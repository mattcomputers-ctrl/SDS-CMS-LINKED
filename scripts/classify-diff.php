#!/usr/bin/env php
<?php
/**
 * SDS System — Classification Diff Harness
 *
 * Re-runs HazardEngine::classify() against every published SDS and compares
 * the result to the classification stored in snapshot_json. Emits a CSV of
 * diffs and a summary report.
 *
 * This is the baseline safety tool for the Hazard Engine refactor
 * (see docs/hazard-engine-refactor-plan.md). Run it:
 *
 *   1. Once today — capture the Phase 0 baseline. Diffs here represent
 *      data drift (hazard_classifications or CPDs updated since publish)
 *      since the engine itself hasn't changed.
 *
 *   2. After each refactor phase — compare against the Phase 0 baseline.
 *      New diffs represent engine behavioral changes.
 *
 * Usage:
 *   php scripts/classify-diff.php [options]
 *
 * Options:
 *   --mode=<snapshot|live>    snapshot: reconstruct composition from snapshot Section 3
 *                             live:     re-run Formula::getExpandedComposition() on current data
 *                             (default: snapshot)
 *   --language=<code>         only diff SDSs in this language (e.g. 'en'). Non-English
 *                             snapshots carry translated signal words and hazard class
 *                             names ("Attention" vs "Warning", "Cancérogénicité" vs
 *                             "Carcinogenicity") which the engine always emits in English
 *                             canonical form — set this to 'en' to avoid spurious
 *                             translation diffs on non-English SDSs.
 *   --limit=<n>               stop after N SDSs (default: all published, non-deleted)
 *   --fg-id=<id>              only diff SDSs for this finished_good_id
 *   --output=<path>           CSV output path
 *                             (default: storage/temp/classify-diff-YYYYMMDD-HHMMSS.csv)
 *   --quiet                   suppress per-SDS progress output
 *   --progress-every=<n>      print a progress line every N SDSs (default: 100)
 *
 * Exit codes:
 *   0 = ran to completion
 *   1 = configuration / IO error before processing began
 *   2 = ran but one or more SDSs errored during replay (see CSV 'error' rows)
 */

declare(strict_types=1);

$basePath = dirname(__DIR__);
require_once $basePath . '/vendor/autoload.php';

new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\HazardEngine;
use SDS\Services\HazardClassAliases;
use SDS\Models\Formula;

// --- Parse args ------------------------------------------------------------

$args = [
    'mode'           => 'snapshot',
    'language'       => null,
    'limit'          => null,
    'fg-id'          => null,
    'output'         => null,
    'quiet'          => false,
    'progress-every' => 100,
];

foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--quiet') {
        $args['quiet'] = true;
        continue;
    }
    if (!preg_match('/^--([a-z\-]+)=(.*)$/', $arg, $m)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    $key = $m[1];
    if (!array_key_exists($key, $args)) {
        fwrite(STDERR, "Unknown option: --{$key}\n");
        exit(1);
    }
    $args[$key] = $m[2];
}

if (!in_array($args['mode'], ['snapshot', 'live'], true)) {
    fwrite(STDERR, "Invalid --mode: must be 'snapshot' or 'live'.\n");
    exit(1);
}

$limit    = $args['limit'] !== null ? (int) $args['limit'] : null;
$fgId     = $args['fg-id'] !== null ? (int) $args['fg-id'] : null;
$language = $args['language'] !== null ? strtolower(trim((string) $args['language'])) : null;
$quiet    = (bool) $args['quiet'];
$progressEvery = max(1, (int) $args['progress-every']);

if ($args['output'] === null) {
    $outDir = $basePath . '/storage/temp';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }
    $args['output'] = $outDir . '/classify-diff-' . date('Ymd-His') . '.csv';
}

$outputPath = $args['output'];

// --- Helpers ---------------------------------------------------------------

function out(string $msg, bool $quiet): void
{
    if (!$quiet) {
        echo $msg . "\n";
    }
}

/**
 * Rebuild a HazardEngine-compatible composition array from snapshot
 * Section 3 components. Returns null if the snapshot contains a trade-secret
 * synthetic row — we can't replay those from snapshot alone because the
 * manual_hazard_json is held on raw_materials, not copied into the snapshot.
 */
function compositionFromSnapshot(array $snapshot): ?array
{
    $components = $snapshot['sections'][3]['components'] ?? [];
    if (!is_array($components) || empty($components)) {
        return [];
    }
    $composition = [];
    foreach ($components as $c) {
        $cas = trim((string) ($c['cas_number'] ?? ''));
        // Trade-secret synthetic rows can be marked with an empty CAS or
        // a string like 'TRADE_SECRET'. Bail — caller logs and skips.
        if ($cas === '' || stripos($cas, 'trade') !== false) {
            return null;
        }
        $composition[] = [
            'cas_number'        => $cas,
            'chemical_name'     => (string) ($c['chemical_name'] ?? ''),
            'concentration_pct' => (float) ($c['concentration_pct'] ?? 0.0),
        ];
    }
    return $composition;
}

/**
 * Re-run via the current formula for a given finished-good id.
 * Captures engine + data drift (raw materials, constituents, CPDs all live).
 */
function compositionFromLiveFormula(int $finishedGoodId): ?array
{
    $db = Database::getInstance();
    $formula = $db->fetch(
        "SELECT id FROM formulas
         WHERE finished_good_id = ? AND is_current = 1
         LIMIT 1",
        [$finishedGoodId]
    );
    if (!$formula) {
        return null;
    }
    return Formula::getExpandedComposition((int) $formula['id']);
}

/**
 * Normalize a list of H-statements / P-statements / pictograms / hazard
 * classes into a comparable canonical form. Keeps only the comparison keys.
 */
function normalizeStatements(array $stmts): array
{
    $codes = [];
    foreach ($stmts as $s) {
        if (is_array($s) && !empty($s['code'])) {
            $codes[] = (string) $s['code'];
        } elseif (is_string($s)) {
            $codes[] = $s;
        }
    }
    sort($codes);
    return array_values(array_unique($codes));
}

function normalizePictograms(array $picts): array
{
    $out = [];
    foreach ($picts as $p) {
        if (is_string($p)) {
            $out[] = $p;
        } elseif (is_array($p) && !empty($p['code'])) {
            $out[] = (string) $p['code'];
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

function normalizeHazardClasses(array $classes): array
{
    $out = [];
    foreach ($classes as $hc) {
        if (!is_array($hc)) continue;
        $class = trim((string) ($hc['class'] ?? ''));
        $cat   = trim((string) ($hc['category'] ?? ''));
        if ($class === '') continue;
        // Compare by canonical code so translated / abbreviated forms collapse
        // to the same identity across snapshot and engine-replay sides.
        // Falls back to the raw display string if unmappable — keeps the diff
        // visible without silently masking data issues.
        $classCanonical = HazardClassAliases::normalize($class) ?? $class;
        $catCanonical   = HazardClassAliases::normalizeCategory($cat);
        $out[] = $classCanonical . '|' . $catCanonical;
    }
    sort($out);
    return array_values(array_unique($out));
}

/** Translation-safe signal word comparison. */
function normalizeSignalForCompare(string $raw): string
{
    return HazardClassAliases::normalizeSignalWord($raw) ?? $raw;
}

/**
 * Compute the set difference between old and new arrays.
 * Returns [added, removed] — items present in new but not old, and vice versa.
 */
function setDiff(array $old, array $new): array
{
    $added   = array_values(array_diff($new, $old));
    $removed = array_values(array_diff($old, $new));
    return [$added, $removed];
}

/**
 * Extract the classification snapshot from the stored snapshot_json.
 * Reads from Section 2, which is what renders to the SDS PDF.
 */
function snapshottedClassification(array $snapshot): array
{
    $sec2 = $snapshot['sections'][2] ?? [];
    return [
        'pictograms'     => normalizePictograms($sec2['pictograms'] ?? []),
        'h_statements'   => normalizeStatements($sec2['h_statements'] ?? []),
        'p_statements'   => normalizeStatements($sec2['p_statements'] ?? []),
        'hazard_classes' => normalizeHazardClasses($sec2['hazard_classes'] ?? []),
        'signal_word'    => normalizeSignalForCompare((string) ($sec2['signal_word'] ?? '')),
    ];
}

function recomputedClassification(array $engineResult): array
{
    return [
        'pictograms'     => normalizePictograms($engineResult['pictograms'] ?? []),
        'h_statements'   => normalizeStatements($engineResult['h_statements'] ?? []),
        'p_statements'   => normalizeStatements($engineResult['p_statements'] ?? []),
        'hazard_classes' => normalizeHazardClasses($engineResult['hazard_classes'] ?? []),
        'signal_word'    => normalizeSignalForCompare((string) ($engineResult['signal_word'] ?? '')),
    ];
}

// --- Main loop -------------------------------------------------------------

$db = Database::getInstance();
$pdo = $db->getPdo();

// Memory strategy: we select only the ID list upfront (tiny), then fetch
// each SDS's heavy snapshot_json one at a time inside the loop. This avoids
// both fetchAll() materialising 14k rows into PHP memory and PDO buffered-
// query mode loading every snapshot into mysqlnd's internal buffer — either
// one easily blows past a 2 GB heap at this data scale. Per-iteration memory
// stays bounded to one snapshot at a time.
$whereClause = "WHERE sv.status = 'published' AND sv.is_deleted = 0";
$params = [];
if ($fgId !== null) {
    $whereClause .= " AND sv.finished_good_id = ?";
    $params[] = $fgId;
}
if ($language !== null) {
    $whereClause .= " AND sv.language = ?";
    $params[] = $language;
}

$idSql = "SELECT id FROM sds_versions sv {$whereClause} ORDER BY id";
if ($limit !== null) {
    $idSql .= " LIMIT " . $limit;
}

out("=== SDS Classification Diff Harness ===", $quiet);
out("Mode:          {$args['mode']}", $quiet);
out("Engine:        " . HazardEngine::ENGINE_VERSION, $quiet);
out("Output CSV:    {$outputPath}", $quiet);
if ($language !== null) out("Filter lang:   {$language}", $quiet);
if ($fgId !== null)     out("Filter FG:     {$fgId}", $quiet);
if ($limit !== null)    out("Limit:         {$limit}", $quiet);
out("", $quiet);

$idStmt = $pdo->prepare($idSql);
$idStmt->execute($params);
$idList = $idStmt->fetchAll(\PDO::FETCH_COLUMN);
$totalSds = count($idList);
out("Processing {$totalSds} published SDS version(s).", $quiet);

// Reused per-row statement — one indexed PK lookup per SDS.
$rowStmt = $pdo->prepare(
    "SELECT sv.id, sv.finished_good_id, sv.version, sv.language, sv.published_at,
            sv.snapshot_json, fg.product_code
       FROM sds_versions sv
       JOIN finished_goods fg ON fg.id = sv.finished_good_id
      WHERE sv.id = ?"
);

$csv = fopen($outputPath, 'w');
if ($csv === false) {
    fwrite(STDERR, "Failed to open output file: {$outputPath}\n");
    exit(1);
}
fputcsv($csv, [
    'sds_version_id', 'product_code', 'version', 'language', 'published_at',
    'diff_type', 'field', 'old_value', 'new_value', 'notes',
]);

$counters = [
    'processed'  => 0,
    'clean'      => 0,
    'with_diff'  => 0,
    'ts_skipped' => 0,
    'errors'     => 0,
    'diff_by_type' => [],
];

foreach ($idList as $sdsIdRaw) {
    $rowStmt->execute([(int) $sdsIdRaw]);
    $row = $rowStmt->fetch(\PDO::FETCH_ASSOC);
    $rowStmt->closeCursor();
    if ($row === false) {
        continue;
    }

    $counters['processed']++;

    $sdsId        = (int) $row['id'];
    $productCode  = (string) $row['product_code'];
    $version      = (int) $row['version'];
    $language     = (string) $row['language'];
    $publishedAt  = (string) $row['published_at'];

    if (!$quiet && $counters['processed'] % $progressEvery === 0) {
        out(sprintf(
            "  [%d/%d] processed=%d clean=%d diffs=%d skipped=%d errors=%d",
            $counters['processed'], $totalSds,
            $counters['processed'], $counters['clean'], $counters['with_diff'],
            $counters['ts_skipped'], $counters['errors']
        ), $quiet);
    }

    try {
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        if (!is_array($snapshot)) {
            throw new RuntimeException('snapshot_json is not decodable JSON');
        }

        // Build composition input
        if ($args['mode'] === 'snapshot') {
            $composition = compositionFromSnapshot($snapshot);
            if ($composition === null) {
                $counters['ts_skipped']++;
                fputcsv($csv, [
                    $sdsId, $productCode, $version, $language, $publishedAt,
                    'trade_secret_skipped', '', '', '',
                    'snapshot mode cannot replay trade-secret composition; re-run with --mode=live for this SDS',
                ]);
                continue;
            }
        } else { // live
            $composition = compositionFromLiveFormula((int) $row['finished_good_id']);
            if ($composition === null) {
                $counters['errors']++;
                fputcsv($csv, [
                    $sdsId, $productCode, $version, $language, $publishedAt,
                    'error', 'formula', '', '',
                    'no current formula for finished_good_id=' . $row['finished_good_id'],
                ]);
                continue;
            }
        }

        // Classify
        $engine = new HazardEngine();
        $result = $engine->classify($composition);

        $old = snapshottedClassification($snapshot);
        $new = recomputedClassification($result);

        $sdsHadDiff = false;

        // Compare each field. Singular diff-type labels are hand-mapped
        // because plural suffixes here aren't uniform (h_statements vs
        // hazard_classes) and rtrim-by-char would mangle them.
        $singular = [
            'pictograms'     => 'pictogram',
            'h_statements'   => 'h_statement',
            'p_statements'   => 'p_statement',
            'hazard_classes' => 'hazard_class',
        ];
        foreach ($singular as $field => $singularLabel) {
            [$added, $removed] = setDiff($old[$field], $new[$field]);
            foreach ($added as $v) {
                $sdsHadDiff = true;
                $diffType = $singularLabel . '_added';
                $counters['diff_by_type'][$diffType] = ($counters['diff_by_type'][$diffType] ?? 0) + 1;
                fputcsv($csv, [
                    $sdsId, $productCode, $version, $language, $publishedAt,
                    $diffType, $field, '', $v, '',
                ]);
            }
            foreach ($removed as $v) {
                $sdsHadDiff = true;
                $diffType = $singularLabel . '_removed';
                $counters['diff_by_type'][$diffType] = ($counters['diff_by_type'][$diffType] ?? 0) + 1;
                fputcsv($csv, [
                    $sdsId, $productCode, $version, $language, $publishedAt,
                    $diffType, $field, $v, '', '',
                ]);
            }
        }

        if ($old['signal_word'] !== $new['signal_word']) {
            $sdsHadDiff = true;
            $counters['diff_by_type']['signal_word_changed'] = ($counters['diff_by_type']['signal_word_changed'] ?? 0) + 1;
            fputcsv($csv, [
                $sdsId, $productCode, $version, $language, $publishedAt,
                'signal_word_changed', 'signal_word', $old['signal_word'], $new['signal_word'], '',
            ]);
        }

        if ($sdsHadDiff) {
            $counters['with_diff']++;
        } else {
            $counters['clean']++;
        }
    } catch (\Throwable $e) {
        $counters['errors']++;
        fputcsv($csv, [
            $sdsId, $productCode, $version, $language, $publishedAt,
            'error', '', '', '',
            get_class($e) . ': ' . $e->getMessage(),
        ]);
    }
}

fclose($csv);

// --- Summary ---------------------------------------------------------------

out("", $quiet);
out("=== Summary ===", $quiet);
out("Total processed:  " . $counters['processed'], $quiet);
out("  Clean:          " . $counters['clean'], $quiet);
out("  With diffs:     " . $counters['with_diff'], $quiet);
out("  TS skipped:     " . $counters['ts_skipped'], $quiet);
out("  Errors:         " . $counters['errors'], $quiet);

if (!empty($counters['diff_by_type'])) {
    out("", $quiet);
    out("Diff types:", $quiet);
    ksort($counters['diff_by_type']);
    foreach ($counters['diff_by_type'] as $t => $n) {
        out("  {$t}: {$n}", $quiet);
    }
}

out("", $quiet);
out("CSV written to: {$outputPath}", $quiet);

exit($counters['errors'] > 0 ? 2 : 0);
