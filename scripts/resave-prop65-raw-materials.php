#!/usr/bin/env php
<?php
/**
 * resave-prop65-raw-materials.php
 *
 * For every raw material that has manual Prop 65 data:
 *   1. Remove any manual prop65_data entry whose CAS is already
 *      present in the RM's constituents (the new Auto section on the
 *      RM form will render it from prop65_list instead).
 *   2. For every surviving entry, rewrite chemical_name and
 *      toxicity_types from prop65_list when the CAS is on the public
 *      list, and force is_override = 0 — operator-typed values from
 *      the old form are superseded by the list's authoritative data.
 *   3. Recompute is_prop65 = 1 when any remaining manual entry exists
 *      OR any constituent CAS is on the seeded prop65_list.
 *   4. Bump updated_at so Bulk SDS Publish sees the change and
 *      regenerates the affected FGs' SDSs.
 *
 * The script INTENTIONALLY touches only RMs that already have manual
 * Prop 65 data — RMs that haven't been reviewed by a human stay
 * unreviewed so nothing gets accidentally pulled into the publish
 * eligibility list.
 *
 * Usage:
 *   # Preview
 *   sudo -u www-data php scripts/resave-prop65-raw-materials.php
 *
 *   # Apply
 *   sudo -u www-data php scripts/resave-prop65-raw-materials.php --confirm
 *
 *   # Quiet output
 *   sudo -u www-data php scripts/resave-prop65-raw-materials.php --confirm --quiet
 *
 * Exit codes:
 *   0 = success (dry-run or applied with no failures)
 *   1 = usage / runtime error / per-row failure
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\Prop65Service;

$confirm = false;
$quiet   = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--confirm') { $confirm = true; continue; }
    if ($arg === '--quiet')   { $quiet   = true; continue; }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/resave-prop65-raw-materials.php [--confirm] [--quiet]\n");
    exit(1);
}

$out = function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg . "\n";
    }
};

$db  = Database::getInstance();
$pdo = $db->getPdo();

// "Manual Prop 65 data" = non-empty JSON array OR non-empty legacy
// chemical_name field. An empty JSON ([], "", NULL) doesn't count.
$rows = $db->fetchAll(
    "SELECT id, internal_code, supplier_product_name,
            prop65_data, prop65_chemical_name, prop65_toxicity_types
       FROM raw_materials
      WHERE (prop65_data IS NOT NULL AND prop65_data <> '' AND prop65_data <> '[]')
         OR (prop65_chemical_name IS NOT NULL AND TRIM(prop65_chemical_name) <> '')
   ORDER BY internal_code"
);

$total = count($rows);
$out("=== Prop 65 raw-material re-save ===");
$out("Mode:  " . ($confirm ? 'APPLY' : 'dry-run (no --confirm)'));
$out("Found: {$total} raw material(s) with manual Prop 65 data.");
$out('');

if ($total === 0) {
    $out("Nothing to do.");
    exit(0);
}

$touched   = 0;
$pruned    = 0;
$rewrites  = 0;
$failed    = 0;

foreach ($rows as $r) {
    $id    = (int) $r['id'];
    $code  = (string) $r['internal_code'];
    $name  = (string) ($r['supplier_product_name'] ?? '');
    $label = $code . ($name !== '' ? ' — ' . $name : '');

    // ── Step 1: collect this RM's constituent CAS set ──
    $constituentRows = $db->fetchAll(
        "SELECT cas_number FROM raw_material_constituents
          WHERE raw_material_id = ?
            AND cas_number IS NOT NULL
            AND TRIM(cas_number) <> ''",
        [$id]
    );
    $constituentCas = [];
    foreach ($constituentRows as $c) {
        $cas = trim((string) $c['cas_number']);
        if ($cas !== '') {
            $constituentCas[$cas] = true;
        }
    }

    // ── Step 2: parse existing prop65_data JSON, normalise to list ──
    // Legacy rows can be a single object rather than an array of
    // objects; json_decode('{}', true) would give an assoc array that
    // fails our foreach. Wrap any non-list into a single-element list.
    $existing = [];
    if (!empty($r['prop65_data'])) {
        $decoded = json_decode((string) $r['prop65_data'], true);
        if (is_array($decoded)) {
            if (array_is_list($decoded) || $decoded === []) {
                $existing = $decoded;
            } else {
                $existing = [$decoded];
            }
        }
    }
    // Backfill from legacy single-entry columns when JSON is empty but
    // the old columns still hold a value.
    if (empty($existing)
        && !empty(trim((string) ($r['prop65_chemical_name'] ?? '')))) {
        $existing = [[
            'chemical_name'  => trim((string) $r['prop65_chemical_name']),
            'cas_number'     => '',
            'toxicity_types' => (string) ($r['prop65_toxicity_types'] ?? ''),
            'is_trace'       => 0,
        ]];
    }

    // ── Step 3: prune entries whose CAS matches a constituent ──
    // Shared helper so the mass migration and the on-save flow in
    // RawMaterialController apply identical rules.
    $pruneResult    = Prop65Service::pruneManualEntriesAgainstConstituents(
        array_map(fn($cas) => ['cas_number' => $cas], array_keys($constituentCas)),
        $existing
    );
    $kept           = $pruneResult['pruned'];
    $removedForRow  = $pruneResult['removed_count'];

    // ── Step 3b: rewrite each surviving entry's name + toxicity
    //    from prop65_list where the CAS matches. Also force
    //    is_override = 0 so the runtime service looks them up fresh
    //    on every analyse() — "operator-typed data is stale, the
    //    public list wins" is the whole point of this migration.
    $rewritten = 0;
    if (!empty($kept)) {
        $keptCas = [];
        foreach ($kept as $e) {
            $c = trim((string) ($e['cas_number'] ?? ''));
            if ($c !== '') { $keptCas[$c] = true; }
        }
        $listByCas = [];
        if (!empty($keptCas)) {
            $casArr = array_keys($keptCas);
            $ph     = implode(',', array_fill(0, count($casArr), '?'));
            foreach ($db->fetchAll(
                "SELECT cas_number, chemical_name, toxicity_type
                 FROM prop65_list WHERE cas_number IN ({$ph})",
                $casArr
            ) as $lr) {
                $listByCas[$lr['cas_number']] = $lr;
            }
        }
        foreach ($kept as $k => $e) {
            $c = trim((string) ($e['cas_number'] ?? ''));
            $kept[$k]['is_override'] = 0;
            if ($c !== '' && isset($listByCas[$c])) {
                $row = $listByCas[$c];
                $kept[$k]['chemical_name']  = (string) $row['chemical_name'];
                $kept[$k]['toxicity_types'] = (string) $row['toxicity_type'];
                $rewritten++;
            }
        }
    }

    // ── Step 4: recompute is_prop65 ──
    $hasManual = !empty($kept);
    $hasListedConstituent = false;
    if (!empty($constituentCas)) {
        $casList = array_keys($constituentCas);
        $placeholders = implode(',', array_fill(0, count($casList), '?'));
        $listCheck = $db->fetch(
            "SELECT COUNT(*) AS c FROM prop65_list WHERE cas_number IN ({$placeholders})",
            $casList
        );
        $hasListedConstituent = (int) ($listCheck['c'] ?? 0) > 0;
    }
    $newIsProp65 = ($hasManual || $hasListedConstituent) ? 1 : 0;

    $keptCount = count($kept);
    $note = "prune {$removedForRow}, rewrite {$rewritten}, keep {$keptCount}, is_prop65={$newIsProp65}";

    if (!$confirm) {
        $out("  WOULD UPDATE  {$label}  [{$note}]");
        continue;
    }

    try {
        $db->update(
            'raw_materials',
            [
                'prop65_data'  => json_encode(array_values($kept), JSON_UNESCAPED_UNICODE),
                'is_prop65'    => $newIsProp65,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$id]
        );
        $touched++;
        $pruned   += $removedForRow;
        $rewrites += $rewritten;
        $out("  updated       {$label}  [{$note}]");
    } catch (\Throwable $e) {
        $failed++;
        $out("  FAILED        {$label}: " . $e->getMessage());
    }
}

$out('');
if ($confirm) {
    $out("=== Summary ===");
    $out("  Raw materials updated:   {$touched}");
    $out("  Manual entries pruned:   {$pruned}");
    $out("  Entries rewritten:       {$rewrites}  (name + toxicity replaced from prop65_list)");
    if ($failed > 0) {
        $out("  Failed:                  {$failed}");
    }
    $out('');
    $out("Next Bulk SDS Publish run will regenerate SDSs for every FG whose");
    $out("formula touches one of these raw materials.");
} else {
    $out("=== Dry run complete — re-run with --confirm to apply. ===");
}

exit($failed > 0 ? 1 : 0);
