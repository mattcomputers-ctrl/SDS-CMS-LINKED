#!/usr/bin/env php
<?php
/**
 * check-pl-duplicates.php — read-only verification of private_label_sds
 * after migration 051 (private label item registry).
 *
 * Reports three things:
 *
 *   1. Duplicate (item_id, language, version) groups in private_label_sds.
 *      Migration 052 wants to add UNIQUE (item_id, language, version); it
 *      must not be applied while any of these exist.
 *   2. The number of private_label_sds rows with item_id IS NULL — legacy
 *      rows the 051 backfill could not map to a registry item (expected 0).
 *   3. Items whose latest frozen product_code (private_label_sds.product_code
 *      at the item's highest version) differs from the code the NEXT publish
 *      would print (custom_code -> strip_pack_extension(alias.customer_code)
 *      -> finished_goods.product_code). Informational only. The manufacturer
 *      page shows the frozen code as a muted "printed as X" note but does
 *      NOT flag these items stale: staleness rule (b) is timestamp-based
 *      (private_label_items.updated_at > the latest row's created_at) and
 *      the 051 backfill sets updated_at = MIN(created_at), so legacy
 *      mismatches (alias rows collapsed into the base item by the
 *      fk_plsds_alias SET NULL, or CMS-changed alias / FG codes) show as
 *      Current or Unknown and "Republish stale" skips them. Review each row
 *      and either publish it individually or edit the item to re-point its
 *      identity (custom code / shared alias) so the next publish prints the
 *      intended code.
 *
 * This script never writes to the database.
 *
 * Usage:
 *   php scripts/check-pl-duplicates.php
 *
 * Exit codes:
 *   0 — no duplicate (item_id, language, version) groups
 *   1 — duplicates exist (do NOT apply migration 052 yet)
 *   2 — could not run (bootstrap failed, or migration 051 not applied)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;

echo "=============================================================\n";
echo "  Private Label SDS duplicate / provenance check (read-only)\n";
echo "=============================================================\n\n";

try {
    new App();   // bootstrap: loads config + DB
    $db = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Pre-flight: migration 051 must have been applied ──
try {
    $hasItems = $db->fetch(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_items'"
    );
    $hasItemId = $db->fetch(
        "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'private_label_sds' AND COLUMN_NAME = 'item_id'"
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "Schema check failed: " . $e->getMessage() . "\n");
    exit(2);
}

if ((int) ($hasItems['c'] ?? 0) === 0 || (int) ($hasItemId['c'] ?? 0) === 0) {
    fwrite(STDERR, "Migration 051_private_label_items has not been applied (private_label_items table or private_label_sds.item_id missing).\n");
    fwrite(STDERR, "Run update.sh first, then re-run this script.\n");
    exit(2);
}

$exitCode = 0;

try {
    // ── 1. Duplicate (item_id, language, version) groups ──
    echo "1. Duplicate (item_id, language, version) groups in private_label_sds\n";
    echo "-------------------------------------------------------------\n";

    $duplicates = $db->fetchAll(
        "SELECT pl.item_id, pl.language, pl.version,
                COUNT(*) AS c,
                GROUP_CONCAT(pl.id ORDER BY pl.id SEPARATOR ', ') AS row_ids
           FROM private_label_sds pl
          WHERE pl.item_id IS NOT NULL
          GROUP BY pl.item_id, pl.language, pl.version
         HAVING c > 1
          ORDER BY pl.item_id, pl.language, pl.version"
    );

    if (count($duplicates) === 0) {
        echo "  None. Safe to add UNIQUE (item_id, language, version) in migration 052.\n";
    } else {
        $exitCode = 1;
        printf("  %-10s %-8s %-8s %-6s %s\n", 'item_id', 'lang', 'version', 'rows', 'private_label_sds.id');
        foreach ($duplicates as $d) {
            printf(
                "  %-10d %-8s %-8d %-6d %s\n",
                (int) $d['item_id'],
                (string) $d['language'],
                (int) $d['version'],
                (int) $d['c'],
                (string) $d['row_ids']
            );
        }
        echo "\n  " . count($duplicates) . " duplicate group(s). Resolve these before applying migration 052.\n";
    }
    echo "\n";

    // ── 2. Unlinked legacy rows ──
    echo "2. private_label_sds rows with item_id IS NULL (unlinked legacy rows)\n";
    echo "-------------------------------------------------------------\n";

    $unlinked      = $db->fetch("SELECT COUNT(*) AS c FROM private_label_sds WHERE item_id IS NULL");
    $unlinkedCount = (int) ($unlinked['c'] ?? 0);

    if ($unlinkedCount === 0) {
        echo "  None. Every history row is linked to a registry item.\n";
    } else {
        echo "  {$unlinkedCount} row(s). These are visible on /private-label/documents as \"unlinked (legacy)\"\n";
        echo "  and are never regenerated by any cascade until linked to an item.\n";

        $unlinkedRows = $db->fetchAll(
            "SELECT pl.id, pl.manufacturer_id, m.name AS manufacturer_name,
                    pl.finished_good_id, fg.product_code AS fg_product_code,
                    pl.alias_id, pl.language, pl.version, pl.product_code, pl.published_at
               FROM private_label_sds pl
               LEFT JOIN manufacturers  m  ON m.id  = pl.manufacturer_id
               LEFT JOIN finished_goods fg ON fg.id = pl.finished_good_id
              WHERE pl.item_id IS NULL
              ORDER BY pl.manufacturer_id, pl.finished_good_id, pl.alias_id, pl.version, pl.language
              LIMIT 200"
        );
        printf("  %-8s %-6s %-24s %-14s %-9s %-6s %-8s %-20s %s\n",
            'pl.id', 'mfg', 'manufacturer', 'fg code', 'alias_id', 'lang', 'version', 'frozen code', 'published_at');
        foreach ($unlinkedRows as $r) {
            printf(
                "  %-8d %-6d %-24s %-14s %-9s %-6s %-8d %-20s %s\n",
                (int) $r['id'],
                (int) $r['manufacturer_id'],
                mb_substr((string) ($r['manufacturer_name'] ?? '?'), 0, 24),
                (string) ($r['fg_product_code'] ?? '?'),
                $r['alias_id'] !== null ? (string) $r['alias_id'] : '-',
                (string) $r['language'],
                (int) $r['version'],
                mb_substr((string) ($r['product_code'] ?? ''), 0, 20),
                (string) ($r['published_at'] ?? '')
            );
        }
        if ($unlinkedCount > count($unlinkedRows)) {
            echo "  ... (" . ($unlinkedCount - count($unlinkedRows)) . " more not shown)\n";
        }
    }
    echo "\n";

    // ── 3. Latest frozen code vs. live resolved code ──
    echo "3. Items whose latest frozen product_code differs from the live resolved code\n";
    echo "-------------------------------------------------------------\n";

    // One row per (item, language) at the item's highest version, joined
    // to everything the live resolution needs.
    $latestRows = $db->fetchAll(
        "SELECT pl.item_id, pl.language, pl.version, pl.product_code AS frozen_code,
                i.manufacturer_id, m.name AS manufacturer_name, i.is_active,
                i.custom_code, i.alias_id, a.customer_code AS alias_customer_code,
                fg.product_code AS fg_product_code
           FROM private_label_sds pl
           JOIN (SELECT item_id, MAX(version) AS max_ver
                   FROM private_label_sds
                  WHERE item_id IS NOT NULL
                  GROUP BY item_id) mx
             ON mx.item_id = pl.item_id AND mx.max_ver = pl.version
           JOIN private_label_items i  ON i.id  = pl.item_id
           JOIN finished_goods      fg ON fg.id = i.finished_good_id
           JOIN manufacturers       m  ON m.id  = i.manufacturer_id
           LEFT JOIN aliases        a  ON a.id  = i.alias_id
          ORDER BY pl.item_id, pl.language"
    );

    // Group by item and compare each language's frozen code with the live
    // resolution (same precedence as PrivateLabelPublisher::resolveIdentity).
    $byItem = [];
    foreach ($latestRows as $r) {
        $itemId = (int) $r['item_id'];
        if (!isset($byItem[$itemId])) {
            $customCode = $r['custom_code'] !== null ? trim((string) $r['custom_code']) : '';
            $aliasCode  = $r['alias_customer_code'] !== null ? trim((string) $r['alias_customer_code']) : '';

            if ($customCode !== '') {
                $liveCode   = $customCode;
                $liveSource = 'custom';
            } elseif ($r['alias_id'] !== null && $aliasCode !== '') {
                $liveCode   = strip_pack_extension($aliasCode);
                $liveSource = 'shared_alias';
            } else {
                $liveCode   = (string) $r['fg_product_code'];
                $liveSource = 'base';
            }

            $byItem[$itemId] = [
                'manufacturer_id'   => (int) $r['manufacturer_id'],
                'manufacturer_name' => (string) $r['manufacturer_name'],
                'is_active'         => (int) $r['is_active'],
                'version'           => (int) $r['version'],
                'live_code'         => $liveCode,
                'live_source'       => $liveSource,
                'frozen'            => [],
            ];
        }
        $byItem[$itemId]['frozen'][(string) $r['language']] = $r['frozen_code'];
    }

    $mismatches = [];
    foreach ($byItem as $itemId => $info) {
        $differs = false;
        foreach ($info['frozen'] as $lang => $frozen) {
            if ($frozen === null || (string) $frozen !== $info['live_code']) {
                $differs = true;
                break;
            }
        }
        if ($differs) {
            $mismatches[$itemId] = $info;
        }
    }

    if (count($mismatches) === 0) {
        echo "  None. Every published item's latest frozen code matches what the next publish would print.\n";
    } else {
        printf("  %-8s %-6s %-24s %-8s %-8s %-26s %s\n",
            'item_id', 'mfg', 'manufacturer', 'active', 'latest', 'live code (source)', 'frozen code per language');
        foreach ($mismatches as $itemId => $info) {
            $frozenParts = [];
            foreach ($info['frozen'] as $lang => $frozen) {
                $frozenParts[] = $lang . '=' . ($frozen === null ? '(null)' : (string) $frozen);
            }
            printf(
                "  %-8d %-6d %-24s %-8s %-8s %-26s %s\n",
                $itemId,
                $info['manufacturer_id'],
                mb_substr($info['manufacturer_name'], 0, 24),
                $info['is_active'] === 1 ? 'yes' : 'no',
                'v' . $info['version'],
                mb_substr($info['live_code'] . ' (' . $info['live_source'] . ')', 0, 26),
                implode(', ', $frozenParts)
            );
        }
        echo "\n  " . count($mismatches) . " item(s). The next publish of each will print a different code than its latest version.\n";
        echo "  These are NOT flagged Stale on the manufacturer page (only a muted \"printed as X\" note) and\n";
        echo "  \"Republish stale\" skips them — review each on /private-label/manufacturer/{mfg}, then publish\n";
        echo "  it individually or edit the item to re-point its identity.\n";
    }
    echo "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Check failed: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Summary ──
echo "=============================================================\n";
echo "  Duplicate groups:  " . count($duplicates) . "\n";
echo "  Unlinked rows:     {$unlinkedCount}\n";
echo "  Code mismatches:   " . count($mismatches) . "\n";
echo "=============================================================\n";

if ($exitCode === 1) {
    echo "RESULT: duplicates exist — do NOT apply migration 052 yet.\n";
} else {
    echo "RESULT: no duplicates — migration 052 (UNIQUE item_id, language, version) can be applied.\n";
}

exit($exitCode);
