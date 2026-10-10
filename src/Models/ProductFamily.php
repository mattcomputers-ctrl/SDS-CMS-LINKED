<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * ProductFamily — product_families + product_family_rules (SDS content audit #3).
 *
 * A family carries the UV/LED flag and the per-language Section 1 defaults
 * (Recommended Use / Restrictions on Use). Membership rules live in
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
     *                    recommended_use (lang => text), restrictions (lang => text)
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
        ]);
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
        return $db->update('product_families', [
            'name'                 => $name,
            'is_uv'                => !empty($data['is_uv']) ? 1 : 0,
            'is_active'            => !empty($data['is_active']) ? 1 : 0,
            'sort_order'           => (int) ($data['sort_order'] ?? 0),
            'recommended_use_json' => self::encodeLangJson($data['recommended_use'] ?? []),
            'restrictions_json'    => self::encodeLangJson($data['restrictions'] ?? []),
        ], 'id = ?', [$id]);
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
