<?php

declare(strict_types=1);

namespace SDS\Models;

use SDS\Core\Database;

/**
 * PrivateLabelItem Model — the private label registry.
 *
 * One row = "manufacturer M sells finished good F under identity I".
 * Identity precedence (resolved at publish time by PrivateLabelPublisher):
 *   custom_code  ->  shared alias (alias_id)  ->  base finished good.
 *
 * Every finder returns the same "joined row": all private_label_items
 * columns plus
 *   fg_product_code, fg_description, fg_is_active,
 *   manufacturer_name, manufacturer_updated_at, manufacturer_logo_path,
 *   alias_customer_code, alias_description, alias_internal_code_base.
 *
 * Staleness: private_label_items.updated_at is a staleness input, so
 * update() writes `updated_at = updated_at` whenever none of the identity
 * fields (alias_id / custom_code / custom_description) actually change.
 */
final class PrivateLabelItem
{
    /** Shown by validate() and by callers that catch \PDOException code 23000. */
    public const DUPLICATE_MESSAGE = 'This manufacturer already has an item with that code / alias / base product.';

    /* ------------------------------------------------------------------
     *  Shared select fragment
     * ----------------------------------------------------------------*/

    private static function selectFragment(): string
    {
        return "SELECT i.*,
                       fg.product_code        AS fg_product_code,
                       fg.description         AS fg_description,
                       fg.is_active           AS fg_is_active,
                       m.name                 AS manufacturer_name,
                       m.updated_at           AS manufacturer_updated_at,
                       m.logo_path            AS manufacturer_logo_path,
                       a.customer_code        AS alias_customer_code,
                       a.description          AS alias_description,
                       a.internal_code_base   AS alias_internal_code_base
                FROM private_label_items i
                JOIN finished_goods fg ON fg.id = i.finished_good_id
                JOIN manufacturers  m  ON m.id  = i.manufacturer_id
                LEFT JOIN aliases   a  ON a.id  = i.alias_id";
    }

    /** ORDER BY the code the next publish will print (custom -> alias base -> FG). */
    private static function orderFragment(): string
    {
        return "ORDER BY COALESCE(i.custom_code, SUBSTRING_INDEX(a.customer_code, '-', 1), fg.product_code) ASC, i.id ASC";
    }

    /* ------------------------------------------------------------------
     *  Finders
     * ----------------------------------------------------------------*/

    /**
     * Find one item (joined row) by primary key.
     */
    public static function findById(int $id): ?array
    {
        $db = Database::getInstance();
        return $db->fetch(self::selectFragment() . " WHERE i.id = ?", [$id]);
    }

    /**
     * Items of one manufacturer (joined rows), ordered by resolved code.
     *
     * @param bool   $includeRetired  false = active items only
     * @param string $search          matches custom_code, alias customer_code,
     *                                FG product_code and the custom / alias / FG descriptions
     */
    public static function forManufacturer(int $mfgId, bool $includeRetired = false, string $search = ''): array
    {
        $db = Database::getInstance();

        $where  = ['i.manufacturer_id = ?'];
        $params = [$mfgId];

        if (!$includeRetired) {
            $where[] = 'i.is_active = 1';
        }

        $search = trim($search);
        if ($search !== '') {
            $like    = '%' . $search . '%';
            $where[] = '(i.custom_code LIKE ? OR a.customer_code LIKE ? OR fg.product_code LIKE ?
                         OR i.custom_description LIKE ? OR a.description LIKE ? OR fg.description LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        return $db->fetchAll(
            self::selectFragment() . ' WHERE ' . implode(' AND ', $where) . ' ' . self::orderFragment(),
            $params
        );
    }

    /**
     * Active items of one finished good (joined rows) — the cascade lookup.
     *
     * @param bool $autoOnly  true = only items with auto_republish = 1
     */
    public static function forFinishedGood(int $fgId, bool $autoOnly = true): array
    {
        $db = Database::getInstance();

        $sql = self::selectFragment() . ' WHERE i.finished_good_id = ? AND i.is_active = 1';
        if ($autoOnly) {
            $sql .= ' AND i.auto_republish = 1';
        }
        $sql .= ' ' . self::orderFragment();

        return $db->fetchAll($sql, [$fgId]);
    }

    /* ------------------------------------------------------------------
     *  Create / Update
     * ----------------------------------------------------------------*/

    /**
     * Insert a registry row and return its id.
     *
     * Keys: manufacturer_id, finished_good_id, alias_id, custom_code,
     * custom_description, is_active, auto_republish, notes, created_by.
     * Optional identity_mode ('base' | 'shared_alias' | 'custom') forces the
     * identity fields the same way validate() interprets it.
     * '' is normalised to NULL for alias_id / custom_code / custom_description / notes.
     *
     * @throws \InvalidArgumentException when manufacturer_id / finished_good_id are missing
     * @throws \PDOException             (code 23000) on a uq_pli_mfg_identity collision
     */
    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $mfgId = (int) ($data['manufacturer_id'] ?? 0);
        $fgId  = (int) ($data['finished_good_id'] ?? 0);
        if ($mfgId <= 0) {
            throw new \InvalidArgumentException('Manufacturer is required.');
        }
        if ($fgId <= 0) {
            throw new \InvalidArgumentException('Finished good is required.');
        }

        $identity = array_merge(
            ['alias_id' => null, 'custom_code' => null, 'custom_description' => null],
            self::normaliseIdentity($data)
        );

        $createdBy = (int) ($data['created_by'] ?? 0);

        $row = [
            'manufacturer_id'    => $mfgId,
            'finished_good_id'   => $fgId,
            'alias_id'           => $identity['alias_id'],
            'custom_code'        => $identity['custom_code'],
            'custom_description' => $identity['custom_description'],
            'is_active'          => array_key_exists('is_active', $data) ? self::toFlag($data['is_active']) : 1,
            'auto_republish'     => array_key_exists('auto_republish', $data) ? self::toFlag($data['auto_republish']) : 1,
            'notes'              => self::nullIfBlank($data['notes'] ?? null),
            'created_by'         => $createdBy > 0 ? $createdBy : null,
        ];

        return (int) $db->insert('private_label_items', $row);
    }

    /**
     * Update a registry row.
     *
     * Only alias_id, custom_code, custom_description, is_active,
     * auto_republish and notes may change (manufacturer_id / finished_good_id
     * never do). Keys absent from $data are left untouched. '' -> NULL.
     * Optional identity_mode is applied exactly as in create().
     *
     * When none of alias_id / custom_code / custom_description differ from
     * the stored row the statement carries `updated_at = updated_at`, so a
     * metadata-only edit (notes, retire, freeze) never flags the item stale.
     *
     * @throws \RuntimeException when the item does not exist
     * @throws \PDOException     (code 23000) on a uq_pli_mfg_identity collision
     */
    public static function update(int $id, array $data): void
    {
        $db = Database::getInstance();

        $current = $db->fetch(
            "SELECT id, alias_id, custom_code, custom_description FROM private_label_items WHERE id = ?",
            [$id]
        );
        if ($current === null) {
            throw new \RuntimeException('Private label item #' . $id . ' not found.');
        }

        $set    = [];
        $params = [];

        // Identity fields — compare against the stored row
        $identityChanged = false;
        foreach (self::normaliseIdentity($data) as $col => $newVal) {
            $oldVal = $current[$col];
            if ($col === 'alias_id') {
                $oldVal = $oldVal !== null ? (int) $oldVal : null;
            }
            if ($newVal !== $oldVal) {
                $identityChanged = true;
            }
            $set[]    = "`{$col}` = ?";
            $params[] = $newVal;
        }

        // Metadata fields
        if (array_key_exists('is_active', $data)) {
            $set[]    = '`is_active` = ?';
            $params[] = self::toFlag($data['is_active']);
        }
        if (array_key_exists('auto_republish', $data)) {
            $set[]    = '`auto_republish` = ?';
            $params[] = self::toFlag($data['auto_republish']);
        }
        if (array_key_exists('notes', $data)) {
            $set[]    = '`notes` = ?';
            $params[] = self::nullIfBlank($data['notes']);
        }

        if (empty($set)) {
            return;
        }

        if (!$identityChanged) {
            // Suppress ON UPDATE CURRENT_TIMESTAMP — nothing the SDS prints changed.
            $set[] = '`updated_at` = `updated_at`';
        }

        $params[] = $id;
        $db->query(
            'UPDATE `private_label_items` SET ' . implode(', ', $set) . ' WHERE `id` = ?',
            $params
        );
    }

    /* ------------------------------------------------------------------
     *  Identity key / validation
     * ----------------------------------------------------------------*/

    /**
     * Same CASE as the DB generated column identity_key:
     *   "code:X" | "alias:N" | "fg:N"
     */
    public static function identityKey(?string $customCode, ?int $aliasId, int $fgId): string
    {
        if ($customCode !== null && $customCode !== '') {
            return 'code:' . $customCode;
        }
        if ($aliasId !== null && $aliasId > 0) {
            return 'alias:' . $aliasId;
        }
        return 'fg:' . $fgId;
    }

    /**
     * Validate item data before create()/update().
     *
     * @param array    $data       Form data (manufacturer_id, finished_good_id, identity_mode,
     *                             alias_id, custom_code, custom_description, notes, ...)
     * @param array    $fg         The finished_goods row for $data['finished_good_id']
     *                             (the stored item's FG on edit). Pass [] when the lookup failed.
     * @param int|null $excludeId  The item being edited (excluded from the uniqueness check)
     * @return string|null         User-facing error, or null when valid
     */
    public static function validate(array $data, array $fg, ?int $excludeId = null): ?string
    {
        $db = Database::getInstance();

        // --- Finished good: must exist, be active, have a current formula ---
        $fgId = (int) ($fg['id'] ?? 0);
        if ($fgId <= 0) {
            return 'Please select a valid product.';
        }
        if (isset($data['finished_good_id']) && (int) $data['finished_good_id'] !== $fgId) {
            if ($excludeId !== null) {
                return 'The base product of an existing item cannot be changed. Create a new item instead.';
            }
            return 'Please select a valid product.';
        }

        $fgCode = (string) ($fg['product_code'] ?? ('#' . $fgId));

        $stored = null;
        if ($excludeId !== null) {
            $stored = $db->fetch(
                "SELECT id, manufacturer_id, finished_good_id, alias_id, custom_code, custom_description
                 FROM private_label_items WHERE id = ?",
                [$excludeId]
            );
            if ($stored === null) {
                return 'Private label item not found.';
            }
            if ((int) $stored['finished_good_id'] !== $fgId) {
                return 'The base product of an existing item cannot be changed. Create a new item instead.';
            }
        }

        if ((int) ($fg['is_active'] ?? 0) !== 1) {
            return "Finished good {$fgCode} is inactive and cannot have private label items.";
        }

        $formula = $db->fetch(
            "SELECT id FROM formulas WHERE finished_good_id = ? AND is_current = 1 LIMIT 1",
            [$fgId]
        );
        if ($formula === null) {
            return "Finished good {$fgCode} has no current formula, so an SDS cannot be generated for it.";
        }

        // --- Manufacturer ---
        $mfgId = $stored !== null ? (int) $stored['manufacturer_id'] : (int) ($data['manufacturer_id'] ?? 0);
        if ($mfgId <= 0) {
            return 'Please select a manufacturer.';
        }
        $mfg = $db->fetch("SELECT id FROM manufacturers WHERE id = ?", [$mfgId]);
        if ($mfg === null) {
            return 'Manufacturer not found.';
        }

        // --- Effective identity (stored values + submitted changes + identity_mode) ---
        $effective = [
            'alias_id'           => $stored !== null && $stored['alias_id'] !== null ? (int) $stored['alias_id'] : null,
            'custom_code'        => $stored['custom_code'] ?? null,
            'custom_description' => $stored['custom_description'] ?? null,
        ];
        foreach (self::normaliseIdentity($data) as $col => $val) {
            $effective[$col] = $val;
        }

        $aliasId    = $effective['alias_id'];
        $customCode = $effective['custom_code'];
        $customDesc = $effective['custom_description'];

        $mode = isset($data['identity_mode']) ? trim((string) $data['identity_mode']) : null;
        if ($mode !== null && $mode !== '') {
            switch ($mode) {
                case 'base':
                    // normaliseIdentity() already forced alias_id / custom_code to NULL
                    break;
                case 'shared_alias':
                    if ($aliasId === null) {
                        return 'Please select a shared alias from the main alias list.';
                    }
                    break;
                case 'custom':
                    if ($customCode === null) {
                        return 'Enter the manufacturer-specific product code.';
                    }
                    break;
                default:
                    return 'Invalid identity mode.';
            }
        }

        // --- Lengths ---
        if ($customCode !== null && mb_strlen($customCode) > 100) {
            return 'Product code must be 100 characters or fewer.';
        }
        if ($customDesc !== null && mb_strlen($customDesc) > 500) {
            return 'Description must be 500 characters or fewer.';
        }
        $notes = self::nullIfBlank($data['notes'] ?? null);
        if ($notes !== null && mb_strlen($notes) > 500) {
            return 'Notes must be 500 characters or fewer.';
        }

        // --- Shared alias must belong to this finished good ---
        if ($aliasId !== null) {
            $alias = $db->fetch(
                "SELECT id, customer_code, internal_code_base FROM aliases WHERE id = ?",
                [$aliasId]
            );
            if ($alias === null) {
                return 'Selected alias not found.';
            }
            if (strcasecmp((string) $alias['internal_code_base'], $fgCode) !== 0) {
                return 'Alias ' . strip_pack_extension((string) $alias['customer_code'])
                    . " does not belong to finished good {$fgCode}.";
            }
        }

        // --- Uniqueness pre-check (DB UNIQUE uq_pli_mfg_identity is the real guard) ---
        $key    = self::identityKey($customCode, $aliasId, $fgId);
        $sql    = "SELECT id FROM private_label_items WHERE manufacturer_id = ? AND identity_key = ?";
        $params = [$mfgId, $key];
        if ($excludeId !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        if ($db->fetch($sql . ' LIMIT 1', $params) !== null) {
            return self::DUPLICATE_MESSAGE;
        }

        return null;
    }

    /* ------------------------------------------------------------------
     *  Published versions / staleness
     * ----------------------------------------------------------------*/

    /**
     * Latest private_label_sds row per (item, language).
     *
     * @return array  [item_id][language] => row (id, item_id, language, version, published_at,
     *                created_at, source_fg_version, product_code, product_description, pdf_path, status, published_by)
     */
    public static function latestByItem(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn (int $v): bool => $v > 0)));
        if (empty($ids)) {
            return [];
        }

        $db           = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $db->fetchAll(
            "SELECT pl.id, pl.item_id, pl.language, pl.version, pl.published_at, pl.created_at,
                    pl.source_fg_version, pl.product_code, pl.product_description, pl.pdf_path,
                    pl.status, pl.published_by
             FROM private_label_sds pl
             INNER JOIN (
                 SELECT item_id, language, MAX(version) AS max_ver
                 FROM private_label_sds
                 WHERE item_id IN ({$placeholders})
                 GROUP BY item_id, language
             ) mx ON mx.item_id = pl.item_id AND mx.language = pl.language AND mx.max_ver = pl.version
             ORDER BY pl.item_id ASC, pl.language ASC, pl.id DESC",
            $ids
        );

        $out = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $lang   = (string) $row['language'];
            if (isset($out[$itemId][$lang])) {
                continue; // duplicate version rows (pre-052): keep the most recent insert
            }
            $row['version']           = (int) $row['version'];
            $row['source_fg_version'] = $row['source_fg_version'] !== null ? (int) $row['source_fg_version'] : null;
            $out[$itemId][$lang]      = $row;
        }

        return $out;
    }

    /**
     * Latest published base (alias_id IS NULL) SDS version per finished good.
     *
     * @return array  fg_id => int version (FGs with no published base SDS are absent)
     */
    public static function fgLatestBaseVersions(array $fgIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fgIds), fn (int $v): bool => $v > 0)));
        if (empty($ids)) {
            return [];
        }

        $db           = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $db->fetchAll(
            "SELECT finished_good_id, MAX(version) AS max_ver
             FROM sds_versions
             WHERE finished_good_id IN ({$placeholders})
               AND alias_id IS NULL
               AND status = 'published'
               AND is_deleted = 0
             GROUP BY finished_good_id",
            $ids
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['finished_good_id']] = (int) $row['max_ver'];
        }
        return $out;
    }

    /**
     * Staleness of one item.
     *
     * @param array    $item         Joined row (is_active, updated_at, manufacturer_updated_at)
     * @param array    $latestLangs  latestByItem()[item_id] ?? []  (language => row)
     * @param int|null $fgLatest     fgLatestBaseVersions()[fg_id] ?? null
     * @param array    $languages    App::config('sds.supported_languages')
     * @return array{code:string,label:string,reason:string,version:?int,source_fg_version:?int}
     *         code: current | stale | never | unknown | retired
     */
    public static function status(array $item, array $latestLangs, ?int $fgLatest, array $languages): array
    {
        if ((int) ($item['is_active'] ?? 1) === 0) {
            return self::statusResult('retired', 'Retired', '', null, null);
        }

        if (empty($latestLangs)) {
            return self::statusResult(
                'never',
                'Never published',
                'Not generated by bulk publish until the base SDS is republished — use Publish',
                null,
                null
            );
        }

        // V = max version across languages
        $v = 0;
        foreach ($latestLangs as $row) {
            $v = max($v, (int) ($row['version'] ?? 0));
        }

        // C = max created_at among rows at V; S = source_fg_version of a row at V
        $c = null;
        $s = null;
        foreach ($latestLangs as $row) {
            if ((int) ($row['version'] ?? 0) !== $v) {
                continue;
            }
            $created = (string) ($row['created_at'] ?? '');
            if ($created !== '' && ($c === null || strtotime($created) > strtotime($c))) {
                $c = $created;
            }
            if ($s === null && isset($row['source_fg_version']) && $row['source_fg_version'] !== null) {
                $s = (int) $row['source_fg_version'];
            }
        }

        // (a0) the base version this PL was derived from is not (or no longer)
        //      published. Bulk-path PL rows are stamped with the FG's *planned*
        //      next version, which may never have landed (base PDF failure, stop
        //      flag, crashed worker in another batch) or may since have been
        //      soft-deleted by an admin. Evaluated from live sds_versions state
        //      so "Republish stale" picks the item up.
        if ($s !== null && ($fgLatest === null || $fgLatest < $s)) {
            return self::statusResult(
                'stale',
                'Stale',
                $fgLatest === null
                    ? "Derived from base v{$s}, but the finished good has no published base SDS"
                    : "Derived from base v{$s}, which is not published (latest is v{$fgLatest})",
                $v,
                $s
            );
        }

        // (a) base SDS moved on
        if ($s !== null && $fgLatest !== null && $fgLatest > $s) {
            return self::statusResult('stale', 'Stale', "Base SDS v{$fgLatest} is newer than v{$s}", $v, $s);
        }

        $cTs = $c !== null ? strtotime($c) : false;

        // (b) identity edited after the last publish
        $itemUpdated = (string) ($item['updated_at'] ?? '');
        if ($cTs !== false && $itemUpdated !== '' && strtotime($itemUpdated) > $cTs) {
            return self::statusResult('stale', 'Stale', 'Identity changed since last publish', $v, $s);
        }

        // (c) manufacturer edited after the last publish
        $mfgUpdated = (string) ($item['manufacturer_updated_at'] ?? '');
        if ($cTs !== false && $mfgUpdated !== '' && strtotime($mfgUpdated) > $cTs) {
            return self::statusResult('stale', 'Stale', 'Manufacturer details changed since last publish', $v, $s);
        }

        // (d) a configured language has no row at V
        foreach ($languages as $lang) {
            $row = $latestLangs[$lang] ?? null;
            if ($row === null || (int) ($row['version'] ?? 0) !== $v) {
                return self::statusResult('stale', 'Stale', 'Missing ' . strtoupper((string) $lang) . " at v{$v}", $v, $s);
            }
        }

        if ($s === null) {
            return self::statusResult('unknown', 'Unknown', 'Legacy version — base SDS version unknown', $v, null);
        }

        return self::statusResult('current', 'Current', '', $v, $s);
    }

    /**
     * Every manufacturer (even with 0 items) with private label counts.
     *
     * @param  string $search  matches manufacturer name
     * @return array  manufacturers rows + item_count, active_count, published_count, last_published
     */
    public static function manufacturerSummaries(string $search = ''): array
    {
        $db = Database::getInstance();

        $where  = '';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $where    = 'WHERE m.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $rows = $db->fetchAll(
            "SELECT m.*,
                    COALESCE(s.item_count, 0)      AS item_count,
                    COALESCE(s.active_count, 0)    AS active_count,
                    COALESCE(s.published_count, 0) AS published_count,
                    s.last_published               AS last_published
             FROM manufacturers m
             LEFT JOIN (
                 SELECT i.manufacturer_id,
                        COUNT(*)                       AS item_count,
                        SUM(CASE WHEN i.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                        COUNT(pls.item_id)             AS published_count,
                        MAX(pls.last_pub)              AS last_published
                 FROM private_label_items i
                 LEFT JOIN (
                     SELECT item_id, MAX(published_at) AS last_pub
                     FROM private_label_sds
                     WHERE item_id IS NOT NULL
                     GROUP BY item_id
                 ) pls ON pls.item_id = i.id
                 GROUP BY i.manufacturer_id
             ) s ON s.manufacturer_id = m.id
             {$where}
             ORDER BY m.name ASC",
            $params
        );

        foreach ($rows as &$row) {
            $row['item_count']      = (int) $row['item_count'];
            $row['active_count']    = (int) $row['active_count'];
            $row['published_count'] = (int) $row['published_count'];
        }
        unset($row);

        return $rows;
    }

    /* ------------------------------------------------------------------
     *  Private helpers
     * ----------------------------------------------------------------*/

    private static function statusResult(string $code, string $label, string $reason, ?int $version, ?int $sourceFgVersion): array
    {
        return [
            'code'              => $code,
            'label'             => $label,
            'reason'            => $reason,
            'version'           => $version,
            'source_fg_version' => $sourceFgVersion,
        ];
    }

    /**
     * Normalise the identity fields present in $data ('' -> NULL, ints) and
     * apply identity_mode when given:
     *   base         -> alias_id NULL, custom_code NULL
     *   shared_alias -> custom_code NULL
     *   custom       -> (nothing forced)
     *
     * Only keys that should be written are returned.
     *
     * @return array{alias_id?:?int,custom_code?:?string,custom_description?:?string}
     */
    private static function normaliseIdentity(array $data): array
    {
        $out = [];

        if (array_key_exists('alias_id', $data)) {
            $aliasId         = (int) ($data['alias_id'] ?? 0);
            $out['alias_id'] = $aliasId > 0 ? $aliasId : null;
        }
        if (array_key_exists('custom_code', $data)) {
            $out['custom_code'] = self::nullIfBlank($data['custom_code']);
        }
        if (array_key_exists('custom_description', $data)) {
            $out['custom_description'] = self::nullIfBlank($data['custom_description']);
        }

        $mode = isset($data['identity_mode']) ? trim((string) $data['identity_mode']) : '';
        if ($mode === 'base') {
            $out['alias_id']    = null;
            $out['custom_code'] = null;
        } elseif ($mode === 'shared_alias') {
            $out['custom_code'] = null;
        }

        return $out;
    }

    /** trim(); '' -> NULL */
    private static function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** Checkbox / "0" / "1" / bool -> 0|1 */
    private static function toFlag(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
            return ($value === '' || $value === '0' || strtolower($value) === 'false' || strtolower($value) === 'off') ? 0 : 1;
        }
        return $value ? 1 : 0;
    }
}
