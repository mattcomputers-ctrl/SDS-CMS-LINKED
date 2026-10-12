<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * ProductFamily — product_families + product_family_rules (SDS content audit #3).
 *
 * A family carries the UV/LED flag, the per-language Section 1 defaults
 * (Recommended Use / Restrictions on Use) and the A11 SDS profile (storage
 * temperatures, water-based override, default physical state, typical pH). Membership rules live in
 * product_family_rules. Resolving items to families is Services\FamilyResolver.
 */
class ProductFamily
{
    /** rule_type => label */
    public const RULE_TYPES = [
        'code_prefix'          => 'Code starts with',
        'description_contains' => 'Description contains',
        'exact_code'           => 'Exact code',
    ];

    /** applies_to => label */
    public const APPLIES_TO = [
        'both'         => 'Raw materials and products',
        'raw_material' => 'Raw materials only',
        'product'      => 'Products only (finished goods and intermediates)',
    ];

    /** A11 SDS profile: default physical states a family may set (stored English, like finished_goods.physical_state). */
    public const PHYSICAL_STATES = ['Liquid', 'Paste', 'Gel', 'Solid', 'Powder'];

    /** A11 water_based => form label. */
    public const WATER_BASED = [
        'auto' => 'Auto (from the water content of the formula)',
        'yes'  => 'Yes: water-based',
        'no'   => 'No: not water-based',
    ];

    /** A11 profile of no / an inactive family: prints exactly what today's sheets print. */
    public const PROFILE_EMPTY = [
        'storage_temp_min_c'     => null,
        'storage_temp_max_c'     => null,
        'water_based'            => 'auto',
        'default_physical_state' => null,
        'ph_min'                 => null,
        'ph_max'                 => null,
    ];

    /** @return array<int,array> ordered by sort_order, name */
    public static function all(bool $activeOnly = false): array
    {
        $db  = Database::getInstance();
        $sql = 'SELECT * FROM product_families' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name';
        return $db->fetchAll($sql);
    }

    public static function findById(int $id): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM product_families WHERE id = ?', [$id]);
    }

    public static function findByName(string $name): ?array
    {
        return Database::getInstance()->fetch('SELECT * FROM product_families WHERE name = ?', [trim($name)]);
    }

    /**
     * @param array $data name, is_uv, is_active, sort_order,
     *                    recommended_use (lang => text), restrictions (lang => text),
     *                    profile (A11 raw form strings, see normaliseProfileInput())
     * @throws \InvalidArgumentException on blank name
     * @throws \RuntimeException on duplicate name
     */
    public static function create(array $data): int
    {
        $db   = Database::getInstance();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Family name is required.');
        }
        if (self::findByName($name) !== null) {
            throw new \RuntimeException("A product family named '{$name}' already exists.");
        }
        return (int) $db->insert('product_families', [
            'name'                 => $name,
            'is_uv'                => !empty($data['is_uv']) ? 1 : 0,
            'is_active'            => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'sort_order'           => (int) ($data['sort_order'] ?? 0),
            'recommended_use_json' => self::encodeLangJson($data['recommended_use'] ?? []),
            'restrictions_json'    => self::encodeLangJson($data['restrictions'] ?? []),
        ] + self::profileColumns(self::normaliseProfileInput($data['profile'] ?? [])));
    }

    /** Same $data shape as create(). Returns affected rows. */
    public static function update(int $id, array $data): int
    {
        $db   = Database::getInstance();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Family name is required.');
        }
        $dup = $db->fetch('SELECT id FROM product_families WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dup) {
            throw new \RuntimeException("A product family named '{$name}' already exists.");
        }
        // A11: the profile is written only when the caller sends it (validated first).
        $profileCols = array_key_exists('profile', $data) ? self::profileColumns(self::normaliseProfileInput((array) $data['profile'])) : [];
        return $db->update('product_families', [
            'name'                 => $name,
            'is_uv'                => !empty($data['is_uv']) ? 1 : 0,
            'is_active'            => !empty($data['is_active']) ? 1 : 0,
            'sort_order'           => (int) ($data['sort_order'] ?? 0),
            'recommended_use_json' => self::encodeLangJson($data['recommended_use'] ?? []),
            'restrictions_json'    => self::encodeLangJson($data['restrictions'] ?? []),
        ] + $profileCols, 'id = ?', [$id]);
    }

    /**
     * Delete a family. Rules cascade; rule/content references on items are
     * released by the FK (SET NULL) and re-resolved by the recompute the
     * controller runs afterwards. Refused while manual overrides point at it.
     */
    public static function delete(int $id): void
    {
        $db = Database::getInstance();
        $c  = self::usageCounts()[$id] ?? null;
        if ($c !== null && ($c['manual_rm'] > 0 || $c['manual_fg'] > 0)) {
            throw new \RuntimeException('This family is a manual override on ' . ($c['manual_rm'] + $c['manual_fg']) . ' item(s). Change those items to Auto or another family first.');
        }
        $db->delete('product_families', 'id = ?', [$id]);
    }

    /** @return array<int,array> rules of one family, newest last */
    public static function rules(int $familyId): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM product_family_rules WHERE family_id = ? ORDER BY rule_type, pattern, id',
            [$familyId]
        );
    }

    /** @return array<int,array> every rule with its family name/active flag */
    public static function allRules(): array
    {
        return Database::getInstance()->fetchAll(
            'SELECT r.*, pf.name AS family_name, pf.is_active AS family_is_active
             FROM product_family_rules r
             JOIN product_families pf ON pf.id = r.family_id
             ORDER BY pf.sort_order, pf.name, r.rule_type, r.pattern'
        );
    }

    /**
     * @throws \InvalidArgumentException on bad type / applies_to / blank pattern
     * @throws \RuntimeException on duplicate rule
     */
    public static function addRule(int $familyId, string $type, string $pattern, string $appliesTo, ?int $userId): int
    {
        if (!isset(self::RULE_TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown rule type.');
        }
        if (!isset(self::APPLIES_TO[$appliesTo])) {
            throw new \InvalidArgumentException('Unknown "applies to" value.');
        }
        $pattern = self::normalizePattern($type, $pattern);
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern is required.');
        }
        $db  = Database::getInstance();
        $dup = $db->fetch(
            'SELECT id FROM product_family_rules WHERE family_id = ? AND rule_type = ? AND pattern = ? AND applies_to = ?',
            [$familyId, $type, $pattern, $appliesTo]
        );
        if ($dup) {
            throw new \RuntimeException('That rule already exists on this family.');
        }
        return (int) $db->insert('product_family_rules', [
            'family_id'  => $familyId,
            'rule_type'  => $type,
            'pattern'    => $pattern,
            'applies_to' => $appliesTo,
            'created_by' => $userId,
        ]);
    }

    public static function deleteRule(int $familyId, int $ruleId): int
    {
        return Database::getInstance()->delete('product_family_rules', 'id = ? AND family_id = ?', [$ruleId, $familyId]);
    }

    /** Codes are stored upper-cased and trimmed; description phrases trimmed only. */
    public static function normalizePattern(string $type, string $pattern): string
    {
        $pattern = trim($pattern);
        return $type === 'description_contains' ? $pattern : strtoupper($pattern);
    }

    /**
     * @return array<int,array{raw_materials:int,finished_goods:int,manual_rm:int,manual_fg:int,rules:int}>
     *         keyed by family id (families with no usage are present with zeros)
     */
    public static function usageCounts(): array
    {
        $db  = Database::getInstance();
        $out = [];
        foreach ($db->fetchAll('SELECT id FROM product_families') as $r) {
            $out[(int) $r['id']] = ['raw_materials' => 0, 'finished_goods' => 0, 'manual_rm' => 0, 'manual_fg' => 0, 'rules' => 0];
        }
        foreach ($db->fetchAll("SELECT family_id, COUNT(*) AS n, SUM(family_source = 'manual') AS m FROM raw_materials WHERE family_id IS NOT NULL GROUP BY family_id") as $r) {
            $out[(int) $r['family_id']]['raw_materials'] = (int) $r['n'];
            $out[(int) $r['family_id']]['manual_rm']     = (int) $r['m'];
        }
        foreach ($db->fetchAll("SELECT family_id, COUNT(*) AS n, SUM(family_source = 'manual') AS m FROM finished_goods WHERE family_id IS NOT NULL GROUP BY family_id") as $r) {
            $out[(int) $r['family_id']]['finished_goods'] = (int) $r['n'];
            $out[(int) $r['family_id']]['manual_fg']      = (int) $r['m'];
        }
        foreach ($db->fetchAll('SELECT family_id, COUNT(*) AS n FROM product_family_rules GROUP BY family_id') as $r) {
            $out[(int) $r['family_id']]['rules'] = (int) $r['n'];
        }
        return $out;
    }

    /** {"en":"..","es":".."} (string or null) => [lang => text], blank languages dropped. */
    public static function decodeLangJson($json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $lang => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $out[(string) $lang] = $text;
            }
        }
        return $out;
    }

    /** [lang => text] => JSON (NULL when every language is blank). */
    public static function encodeLangJson(array $texts): ?string
    {
        $clean = [];
        foreach ($texts as $lang => $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $clean[(string) $lang] = $text;
            }
        }
        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * A11: validate + normalise the SDS-profile form strings. Blank = not set.
     * Storage temperature and pH are pairs: both or neither. Storage min < max,
     * -50..100 °C. pH 0..14, min <= max. One decimal (comma accepted).
     * Pure. @throws \InvalidArgumentException listing every problem.
     * @param array $raw storage_temp_min_c, storage_temp_max_c, water_based, default_physical_state, ph_min, ph_max
     * @return array PROFILE_EMPTY shape
     */
    public static function normaliseProfileInput(array $raw): array
    {
        $errors = [];
        $blank  = static fn(string $k): bool => trim((string) ($raw[$k] ?? '')) === '';
        $num    = static function (string $k, string $label, float $lo, float $hi) use ($raw, &$errors): ?float {
            $v = str_replace(',', '.', trim((string) ($raw[$k] ?? '')));
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v)) {
                $errors[] = $label . ' must be a number.';
                return null;
            }
            $f = round((float) $v, 1);
            if ($f < $lo || $f > $hi) {
                $errors[] = $label . ' must be between ' . $lo . ' and ' . $hi . '.';
                return null;
            }
            return $f;
        };
        $tMin = $num('storage_temp_min_c', 'Minimum storage temperature (°C)', -50, 100);
        $tMax = $num('storage_temp_max_c', 'Maximum storage temperature (°C)', -50, 100);
        if ($blank('storage_temp_min_c') !== $blank('storage_temp_max_c')) {
            $errors[] = 'Enter both storage temperatures (minimum and maximum) or leave both blank.';
        } elseif ($tMin !== null && $tMax !== null && $tMin >= $tMax) {
            $errors[] = 'The minimum storage temperature must be lower than the maximum.';
        }
        $pMin = $num('ph_min', 'Minimum pH', 0, 14);
        $pMax = $num('ph_max', 'Maximum pH', 0, 14);
        if ($blank('ph_min') !== $blank('ph_max')) {
            $errors[] = 'Enter both pH values (minimum and maximum) or leave both blank.';
        } elseif ($pMin !== null && $pMax !== null && $pMin > $pMax) {
            $errors[] = 'The minimum pH must not be higher than the maximum.';
        }
        $wb = strtolower(trim((string) ($raw['water_based'] ?? '')));
        if ($wb === '') {
            $wb = 'auto';
        } elseif (!isset(self::WATER_BASED[$wb])) {
            $errors[] = 'Unknown water-based setting.';
            $wb = 'auto';
        }
        $state = self::canonicalPhysicalState((string) ($raw['default_physical_state'] ?? ''));
        if ($state === null && !$blank('default_physical_state')) {
            $errors[] = 'Unknown default physical state (choose ' . implode(', ', self::PHYSICAL_STATES) . ').';
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        return [
            'storage_temp_min_c'     => $tMin,
            'storage_temp_max_c'     => $tMax,
            'water_based'            => $wb,
            'default_physical_state' => $state,
            'ph_min'                 => $pMin,
            'ph_max'                 => $pMax,
        ];
    }

    /** A11: product_families columns for a normalised profile. */
    public static function profileColumns(array $p): array
    {
        return [
            'storage_temp_min_c'     => $p['storage_temp_min_c'],
            'storage_temp_max_c'     => $p['storage_temp_max_c'],
            'water_based'            => $p['water_based'],
            'default_physical_state' => $p['default_physical_state'],
            'ph_min'                 => $p['ph_min'],
            'ph_max'                 => $p['ph_max'],
        ];
    }

    /** 'paste' -> 'Paste'; unknown / blank -> null. */
    public static function canonicalPhysicalState(string $state): ?string
    {
        $state = trim($state);
        foreach (self::PHYSICAL_STATES as $s) {
            if ($state !== '' && strcasecmp($s, $state) === 0) {
                return $s;
            }
        }
        return null;
    }

    /**
     * A11: typed SDS profile of a product_families row (DB DECIMALs arrive as
     * strings). Null row -> PROFILE_EMPTY. Defensive: a half pair or an
     * inverted range is dropped (both null), so consumers can rely on
     * "both or neither, storage min < max, pH min <= max". Activity is NOT
     * checked here (SDSGenerator::familyFields() does that). Pure.
     */
    public static function profile(?array $family): array
    {
        if ($family === null) {
            return self::PROFILE_EMPTY;
        }
        $num = static function ($v): ?float {
            $v = trim((string) ($v ?? ''));
            return ($v === '' || !is_numeric($v)) ? null : round((float) $v, 1);
        };
        $tMin = $num($family['storage_temp_min_c'] ?? null);
        $tMax = $num($family['storage_temp_max_c'] ?? null);
        if ($tMin === null || $tMax === null || $tMin >= $tMax) {
            $tMin = $tMax = null;
        }
        $pMin = $num($family['ph_min'] ?? null);
        $pMax = $num($family['ph_max'] ?? null);
        if ($pMin === null || $pMax === null || $pMin > $pMax) {
            $pMin = $pMax = null;
        }
        $wb = strtolower(trim((string) ($family['water_based'] ?? 'auto')));
        return [
            'storage_temp_min_c'     => $tMin,
            'storage_temp_max_c'     => $tMax,
            'water_based'            => isset(self::WATER_BASED[$wb]) ? $wb : 'auto',
            'default_physical_state' => self::canonicalPhysicalState((string) ($family['default_physical_state'] ?? '')),
            'ph_min'                 => $pMin,
            'ph_max'                 => $pMax,
        ];
    }

    /** A11: one-line admin summary for the families list ('' = nothing set, water auto). */
    public static function profileSummary(?array $family): string
    {
        $p     = self::profile($family);
        $fmt   = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $parts = [];
        if ($p['storage_temp_min_c'] !== null) {
            $parts[] = 'Store ' . $fmt($p['storage_temp_min_c']) . '–' . $fmt($p['storage_temp_max_c']) . ' °C';
        }
        if ($p['water_based'] !== 'auto') {
            $parts[] = $p['water_based'] === 'yes' ? 'Water-based' : 'Not water-based';
        }
        if ($p['default_physical_state'] !== null) {
            $parts[] = $p['default_physical_state'];
        }
        if ($p['ph_min'] !== null) {
            $parts[] = 'pH ' . number_format($p['ph_min'], 1, '.', '') . '–' . number_format($p['ph_max'], 1, '.', '');
        }
        return implode(' · ', $parts);
    }

    /**
     * Default text of one family for one language: requested language, else '' (no EN fallback, finding #62).
     * @param string $field 'recommended_use' | 'restrictions'
     */
    public static function text(?array $family, string $field, string $lang): string
    {
        if ($family === null) {
            return '';
        }
        $col  = $field === 'restrictions' ? 'restrictions_json' : 'recommended_use_json';
        $map  = self::decodeLangJson($family[$col] ?? null);
        return $map[$lang] ?? '';
    }
}
