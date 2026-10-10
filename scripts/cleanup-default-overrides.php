#!/usr/bin/env php
<?php
/**
 * cleanup-default-overrides.php — audit #36 one-off.
 *
 * Before #36 the SDS editor pre-filled every field with the generated text
 * and saved every non-empty field, so most text_overrides rows merely repeat
 * the automatic value and freeze it. This script (pass 1) regenerates each
 * affected (finished good or resale raw material, language) and deletes the
 * rows whose text equals the automatic value (TextOverrideService::equalsDefault:
 * whitespace-insensitive, case-sensitive). The automatic value is the one the
 * editor shows (audit #57): the field's own text with the product's OTHER
 * stored overrides applied (TextOverrideService::hintDefaults), repeated until
 * nothing more matches.
 *
 * Rows kept: operator text that differs from the automatic value; blank rows,
 * retired keys (15.osha_status / 15.tsca_status, Q12) and keys the generator
 * does not produce (pass 2, scripts/cleanup-legacy-overrides.php, handles those);
 * rows of products whose generation fails; rows with neither finished_good_id
 * nor raw_material_id. Rows with sds_version_id NOT NULL are never read.
 *
 * Deleting a row equal to its automatic value leaves that field unchanged, but
 * a cross-feeding row (9.flash_point, 10.incompatible, 12.persistence) can
 * change another field (e.g. 12.bioaccumulation). Sections are compared before
 * and after per product/language. With --apply, changed finished goods that
 * have a published SDS are queued on SDS Updates, and changed resale raw
 * materials are listed for republish.
 *
 * Usage:
 *   sudo -u www-data php scripts/cleanup-default-overrides.php            # dry-run, prints counts
 *   sudo -u www-data php scripts/cleanup-default-overrides.php --apply    # delete
 *   options: --fg=<id>  --rm=<id>  --lang=<xx>  --verbose (list every row)  --quiet
 *
 * Exit codes: 0 = ok (dry-run or applied), 1 = usage error.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\SDSGenerator;
use SDS\Services\TextOverrideService;

$apply = false; $quiet = false; $verbose = false; $onlyFg = null; $onlyLang = null; $onlyRm = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply')   { $apply = true;   continue; }
    if ($arg === '--dry-run') { $apply = false;  continue; }
    if ($arg === '--quiet')   { $quiet = true;   continue; }
    if ($arg === '--verbose') { $verbose = true; continue; }
    if (preg_match('/^--fg=(\d+)$/', $arg, $m))        { $onlyFg = (int) $m[1]; continue; }
    if (preg_match('/^--rm=(\d+)$/', $arg, $m))        { $onlyRm = (int) $m[1]; continue; }
    if (preg_match('/^--lang=([a-z]{2})$/', $arg, $m)) { $onlyLang = $m[1];     continue; }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/cleanup-default-overrides.php [--apply] [--fg=<id>] [--rm=<id>] [--lang=<xx>] [--verbose] [--quiet]\n");
    exit(1);
}
$out = function (string $msg) use ($quiet): void { if (!$quiet) { echo $msg . "\n"; } };

$db = Database::getInstance();

$where  = ['sds_version_id IS NULL'];
$params = [];
if ($onlyFg !== null)   { $where[] = 'finished_good_id = ?'; $params[] = $onlyFg; }
if ($onlyRm !== null)   { $where[] = 'raw_material_id = ?';  $params[] = $onlyRm; }
if ($onlyLang !== null) { $where[] = 'language = ?';         $params[] = $onlyLang; }

$rows = $db->fetchAll(
    "SELECT id, finished_good_id, raw_material_id, section_number, field_key, language, override_text
       FROM text_overrides
      WHERE " . implode(' AND ', $where) . "
   ORDER BY finished_good_id, raw_material_id, language, section_number, field_key",
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
    if ($r['finished_good_id'] !== null) {
        $groups['fg:' . (int) $r['finished_good_id']][(string) $r['language']][] = $r;
    } elseif (($r['raw_material_id'] ?? null) !== null) {
        $groups['rm:' . (int) $r['raw_material_id']][(string) $r['language']][] = $r;   // audit #45 resale rows
    } else {
        $orphan++;
    }
}

$equal = [];                 // ids to delete
$kept = 0; $unknown = 0; $failedGroups = 0; $failedRows = 0;
$bySection = [];             // section => ['equal' => n, 'kept' => n, 'unknown' => n]
$tally = function (int $s, string $k) use (&$bySection): void {
    $bySection[$s] ??= ['equal' => 0, 'kept' => 0, 'unknown' => 0];
    $bySection[$s][$k]++;
};

$changed = ['fg' => [], 'rm' => []];   // products whose PRINTED sheet changes once the rows go

$generator  = new SDSGenerator();       // overrides are injected per call (withOverrides)
$groupCount = 0;
foreach ($groups as $gk => $langs) {
    [$kind, $pid] = explode(':', (string) $gk);
    $pid   = (int) $pid;
    $label = strtoupper($kind) . ' #' . $pid;
    try {
        $base = $kind === 'rm' ? $generator->computeBaseForResaleRawMaterial($pid) : $generator->computeBase($pid);
    } catch (\Throwable $e) {
        $n = 0;
        foreach ($langs as $list) { $groupCount++; $failedGroups++; $n += count($list); }
        $failedRows += $n;
        $out(sprintf("  %s: generation failed, %d row(s) kept - %s", $label, $n, $e->getMessage()));
        continue;
    }
    foreach ($langs as $lang => $list) {
        $groupCount++;
        $lang = (string) $lang;
        $sectionsWith = static fn (array $ov): array => $generator->withOverrides($ov)->generateFromBase($base, $lang)['sections'];
        $stored = [];
        $idOf   = [];
        foreach ($list as $r) {
            $stored[(int) $r['section_number']][(string) $r['field_key']] = (string) $r['override_text'];
            $idOf[(int) $r['section_number'] . '.' . $r['field_key']] = (int) $r['id'];
        }
        $defaults   = [];
        $groupEqual = [];
        try {
            $printedBefore = $sectionsWith(TextOverrideService::effective($stored));
            $remaining     = $stored;
            for ($round = 0; $round < 5; $round++) {          // fixpoint: a deletion can change another default
                $defaults = TextOverrideService::hintDefaults($remaining, $sectionsWith);
                $found    = false;
                // Finding #7: a full Section 14 override keeps its fields that
                // happen to equal the derived value (e.g. class 3 / PG II next
                // to UN1263 / Paint); deleting them would make it incomplete
                // and block publishing.
                $s14Group = TextOverrideService::section14CoreGroupActive((array) ($remaining[14] ?? []), $defaults);
                foreach ($remaining as $s => $fields) {
                    foreach ($fields as $key => $text) {
                        if (!TextOverrideService::isEditable((int) $s, (string) $key) || trim((string) $text) === '') {
                            continue;                          // blank / retired / unknown: pass 2
                        }
                        if ($s14Group && (int) $s === 14 && in_array((string) $key, TextOverrideService::SECTION14_CORE_FIELDS, true)) {
                            continue;
                        }
                        if (TextOverrideService::equalsDefault((string) $text, TextOverrideService::defaultFor($defaults, (int) $s, (string) $key))) {
                            $groupEqual[$s . '.' . $key] = $idOf[$s . '.' . $key];
                            unset($remaining[$s][$key]);
                            $found = true;
                        }
                    }
                }
                if (!$found) {
                    break;
                }
            }
            $printedAfter = $groupEqual === [] ? $printedBefore : $sectionsWith(TextOverrideService::effective($remaining));
        } catch (\Throwable $e) {
            $failedGroups++;
            $failedRows += count($list);
            $out(sprintf("  %s [%s]: generation failed, %d row(s) kept - %s", $label, $lang, count($list), $e->getMessage()));
            continue;
        } finally {
            $generator->withOverrides(null);
        }
        foreach ($list as $r) {
            $s   = (int) $r['section_number'];
            $key = (string) $r['field_key'];
            if (isset($groupEqual[$s . '.' . $key])) {
                $equal[] = (int) $r['id']; $tally($s, 'equal');
                if ($verbose) { $out(sprintf("  DELETE (= automatic) %s [%s] %d.%s", $label, $lang, $s, $key)); }
            } elseif (!TextOverrideService::isEditable($s, $key) || trim((string) $r['override_text']) === ''
                || TextOverrideService::defaultFor($defaults, $s, $key) === null) {
                $unknown++; $tally($s, 'unknown');
                if ($verbose) { $out(sprintf("  keep (pass 2: blank / retired / unknown key) %s [%s] %d.%s", $label, $lang, $s, $key)); }
            } else {
                $kept++; $tally($s, 'kept');
                if ($verbose) { $out(sprintf("  keep (custom text)   %s [%s] %d.%s", $label, $lang, $s, $key)); }
            }
        }
        if (json_encode($printedBefore) !== json_encode($printedAfter)) {
            $changed[$kind][$pid] = true;
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
$out(sprintf("Blank / retired / unknown key (kept; pass 2): %d", $unknown));
$out(sprintf("No product id (kept):        %d", $orphan));
$out(sprintf("Generation failed (kept):    %d row(s) in %d group(s)", $failedRows, $failedGroups));
$out(sprintf("Printed SDS changes:         %d finished good(s), %d resale raw material(s)", count($changed['fg']), count($changed['rm'])));

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
$queued = 0;
foreach (array_keys($changed['fg']) as $fgId) {
    $hasPublished = $db->fetch("SELECT 1 FROM sds_versions WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0 LIMIT 1", [$fgId]);
    $pending      = $db->fetch("SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'", [$fgId]);
    if ($hasPublished && !$pending) {
        $db->insert('sds_update_queue', [
            'finished_good_id' => $fgId,
            'reason'           => 'Text override cleanup (audit #36/#57): a cross-fed field changed',
            'source_type'      => 'finished_good',
            'source_id'        => $fgId,
            'queued_by'        => null,
        ]);
        $queued++;
    }
}
$out("Queued {$queued} finished good(s) on SDS Updates.");
if ($changed['rm'] !== []) {
    $out('Republish these resale SDSs from the SDS Creation Readiness Check: raw material #' . implode(', #', array_keys($changed['rm'])));
}
exit(0);
