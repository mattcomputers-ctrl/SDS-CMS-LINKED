#!/usr/bin/env php
<?php
/**
 * cleanup-default-overrides.php — audit #36 one-off.
 *
 * Before #36 the SDS editor pre-filled every field with the generated text
 * and saved every non-empty field, so most text_overrides rows merely repeat
 * the automatic value and freeze it. This script regenerates each affected
 * (finished good, language) with overrides switched off and deletes the rows
 * whose text equals the automatic value (TextOverrideService::equalsDefault —
 * whitespace-insensitive, case-sensitive).
 *
 * Rows kept: operator text that differs from the automatic value; rows whose
 * field key the generator no longer produces ("unknown key"); rows of
 * products whose generation fails; rows with no finished_good_id. Rows with
 * sds_version_id NOT NULL are never read by the generator and are ignored.
 *
 * Deleting a row that equals the automatic value changes no printed SDS, so
 * nothing is bumped for republish.
 *
 * Usage:
 *   sudo -u www-data php scripts/cleanup-default-overrides.php            # dry-run, prints counts
 *   sudo -u www-data php scripts/cleanup-default-overrides.php --apply    # delete
 *   options: --fg=<id>  --lang=<xx>  --verbose (list every row)  --quiet
 *
 * Exit codes: 0 = ok (dry-run or applied), 1 = usage error.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\SDSGenerator;
use SDS\Services\TextOverrideService;

$apply = false; $quiet = false; $verbose = false; $onlyFg = null; $onlyLang = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply')   { $apply = true;   continue; }
    if ($arg === '--dry-run') { $apply = false;  continue; }
    if ($arg === '--quiet')   { $quiet = true;   continue; }
    if ($arg === '--verbose') { $verbose = true; continue; }
    if (preg_match('/^--fg=(\d+)$/', $arg, $m))        { $onlyFg = (int) $m[1]; continue; }
    if (preg_match('/^--lang=([a-z]{2})$/', $arg, $m)) { $onlyLang = $m[1];     continue; }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/cleanup-default-overrides.php [--apply] [--fg=<id>] [--lang=<xx>] [--verbose] [--quiet]\n");
    exit(1);
}
$out = function (string $msg) use ($quiet): void { if (!$quiet) { echo $msg . "\n"; } };

$db = Database::getInstance();

$where  = ['sds_version_id IS NULL'];
$params = [];
if ($onlyFg !== null)   { $where[] = 'finished_good_id = ?'; $params[] = $onlyFg; }
if ($onlyLang !== null) { $where[] = 'language = ?';         $params[] = $onlyLang; }

$rows = $db->fetchAll(
    "SELECT id, finished_good_id, section_number, field_key, language, override_text
       FROM text_overrides
      WHERE " . implode(' AND ', $where) . "
   ORDER BY finished_good_id, language, section_number, field_key",
    $params
);
$pinned = (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM text_overrides WHERE sds_version_id IS NOT NULL")['cnt'] ?? 0);

$out("=== text_overrides cleanup (audit #36) ===");
$out("Mode: " . ($apply ? 'APPLY (deleting)' : 'DRY-RUN (use --apply to delete)'));
$out("Product-level rows scanned: " . count($rows) . ($pinned > 0 ? "  (version-pinned rows ignored: {$pinned})" : ''));
$out('');

$groups = [];
$orphan = 0;
foreach ($rows as $r) {
    if ($r['finished_good_id'] === null) { $orphan++; continue; }
    $groups[(int) $r['finished_good_id']][(string) $r['language']][] = $r;
}

$equal = [];                 // ids to delete
$kept = 0; $unknown = 0; $failedGroups = 0; $failedRows = 0;
$bySection = [];             // section => ['equal' => n, 'kept' => n, 'unknown' => n]
$tally = function (int $s, string $k) use (&$bySection): void {
    $bySection[$s] ??= ['equal' => 0, 'kept' => 0, 'unknown' => 0];
    $bySection[$s][$k]++;
};

$generator  = (new SDSGenerator())->ignoreOverrides();
$groupCount = 0;
foreach ($groups as $fgId => $langs) {
    foreach ($langs as $lang => $list) {
        $groupCount++;
        try {
            $sections = $generator->generate($fgId, $lang)['sections'];
        } catch (\Throwable $e) {
            $failedGroups++;
            $failedRows += count($list);
            $out(sprintf("  FG #%d [%s]: generation failed, %d row(s) kept - %s", $fgId, $lang, count($list), $e->getMessage()));
            continue;
        }
        foreach ($list as $r) {
            $s   = (int) $r['section_number'];
            $key = (string) $r['field_key'];
            $default = TextOverrideService::defaultFor($sections, $s, $key);
            if ($default === null) {
                $unknown++; $tally($s, 'unknown');
                if ($verbose) { $out(sprintf("  keep (unknown key)   FG #%d [%s] %d.%s", $fgId, $lang, $s, $key)); }
                continue;
            }
            if (TextOverrideService::equalsDefault((string) $r['override_text'], $default)) {
                $equal[] = (int) $r['id']; $tally($s, 'equal');
                if ($verbose) { $out(sprintf("  DELETE (= automatic) FG #%d [%s] %d.%s", $fgId, $lang, $s, $key)); }
            } else {
                $kept++; $tally($s, 'kept');
                if ($verbose) { $out(sprintf("  keep (custom text)   FG #%d [%s] %d.%s", $fgId, $lang, $s, $key)); }
            }
        }
        if (!$verbose && $groupCount % 25 === 0) { $out("  ... {$groupCount} product/language groups processed"); }
    }
}

$out('');
$out("Per section (equal to automatic / custom kept / unknown key):");
ksort($bySection);
foreach ($bySection as $s => $c) {
    $out(sprintf("  Section %-2d  %5d / %5d / %5d", $s, $c['equal'], $c['kept'], $c['unknown']));
}
$out('');
$out(sprintf("Product/language groups:     %d", $groupCount));
$out(sprintf("Equal to automatic (delete): %d", count($equal)));
$out(sprintf("Custom text (kept):          %d", $kept));
$out(sprintf("Unknown field key (kept):    %d", $unknown));
$out(sprintf("No finished_good_id (kept):  %d", $orphan));
$out(sprintf("Generation failed (kept):    %d row(s) in %d group(s)", $failedRows, $failedGroups));

if (!$apply) {
    $out('');
    $out("Dry-run only. Re-run with --apply to delete the " . count($equal) . " row(s) equal to the automatic text.");
    exit(0);
}

$deleted = 0;
foreach (array_chunk($equal, 500) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $deleted += $db->delete('text_overrides', "id IN ({$ph}) AND sds_version_id IS NULL", $chunk);
}
$out('');
$out("Deleted {$deleted} row(s).");
exit(0);
