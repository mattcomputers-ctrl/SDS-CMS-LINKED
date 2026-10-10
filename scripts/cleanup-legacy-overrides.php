#!/usr/bin/env php
<?php
/**
 * cleanup-legacy-overrides.php: second override cleanup pass (audit #1,
 * owner decision Q12). Run AFTER cleanup-default-overrides.php (pass 1).
 *
 * Deletes product-level text_overrides rows that are not operator text
 * (LegacyOverrideMatcher):
 *   blank            trimmed-empty rows (#56)                               any age
 *   retired_field    keys the generator no longer reads, incl.
 *                    15.osha_status / 15.tsca_status (Q12)                  any age
 *   legacy_*         OLD automatic text saved by the pre-#36 editor: old
 *                    translations / hard-coded sentences, old lowest-raw
 *                    flash point, old dot_transport_info values             only rows last written
 *                    before --before (default 2026-10-08 23:31:06, the #36 commit)
 * These deletions CHANGE printed SDSs (today's automatic text prints). With
 * --apply, finished goods with a published SDS are queued on SDS Updates
 * and resale raw materials are listed for republish. Retired-field-only
 * products are not queued: those rows were never read.
 *
 * The rows it KEEPS in Sections 9, 14 and 15 are always listed for owner review.
 *
 * Usage:
 *   sudo -u www-data php scripts/cleanup-legacy-overrides.php            # dry run
 *   sudo -u www-data php scripts/cleanup-legacy-overrides.php --apply    # delete + queue
 *   options: --fg=<id> --rm=<id> --lang=<xx> --before="YYYY-MM-DD HH:MM:SS" --any-date --verbose --quiet
 * Needs migration 059 (text_overrides.raw_material_id) and
 * scripts/data/legacy-override-texts.php (scripts/build-legacy-override-texts.php).
 * Exit codes: 0 ok, 1 usage error, 2 data file missing.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
new \SDS\Core\App();

use SDS\Core\Database;
use SDS\Services\FormulaCalcService;
use SDS\Services\LegacyOverrideMatcher as M;
use SDS\Services\TextOverrideService;

$apply = false; $quiet = false; $verbose = false; $anyDate = false;
$onlyFg = null; $onlyRm = null; $onlyLang = null; $before = '2026-10-08 23:31:06';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply')    { $apply = true;   continue; }
    if ($arg === '--dry-run')  { $apply = false;  continue; }
    if ($arg === '--quiet')    { $quiet = true;   continue; }
    if ($arg === '--verbose')  { $verbose = true; continue; }
    if ($arg === '--any-date') { $anyDate = true; continue; }
    if (preg_match('/^--fg=(\d+)$/', $arg, $m))        { $onlyFg = (int) $m[1]; continue; }
    if (preg_match('/^--rm=(\d+)$/', $arg, $m))        { $onlyRm = (int) $m[1]; continue; }
    if (preg_match('/^--lang=([a-z]{2})$/', $arg, $m)) { $onlyLang = $m[1];     continue; }
    if (preg_match('/^--before=(\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?)$/', $arg, $m)) { $before = $m[1]; continue; }
    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Usage: php scripts/cleanup-legacy-overrides.php [--apply] [--fg=<id>] [--rm=<id>] [--lang=<xx>] [--before=\"YYYY-MM-DD HH:MM:SS\"] [--any-date] [--verbose] [--quiet]\n");
    exit(1);
}
$out = function (string $msg) use ($quiet): void { if (!$quiet) { echo $msg . "\n"; } };
$short = static function (string $t): string {
    $t = TextOverrideService::normalize($t);
    return function_exists('mb_strimwidth') ? mb_strimwidth($t, 0, 110, '…', 'UTF-8') : substr($t, 0, 110);
};

$dataFile = __DIR__ . '/data/legacy-override-texts.php';
if (!is_file($dataFile)) {
    fwrite(STDERR, "Missing {$dataFile}; build it with scripts/build-legacy-override-texts.php\n");
    exit(2);
}
$matcher = new M(require $dataFile);
$db      = Database::getInstance();
$cutoff  = $anyDate ? null : strtotime($before);
if ($cutoff === false) {
    fwrite(STDERR, "Invalid --before value: {$before}\n");
    exit(1);
}

$where = ['sds_version_id IS NULL']; $params = [];
if ($onlyFg !== null)   { $where[] = 'finished_good_id = ?'; $params[] = $onlyFg; }
if ($onlyRm !== null)   { $where[] = 'raw_material_id = ?';  $params[] = $onlyRm; }
if ($onlyLang !== null) { $where[] = 'language = ?';         $params[] = $onlyLang; }
$rows = $db->fetchAll(
    "SELECT id, finished_good_id, raw_material_id, section_number, field_key, language, override_text, updated_at
       FROM text_overrides WHERE " . implode(' AND ', $where) . "
   ORDER BY finished_good_id, raw_material_id, language, section_number, field_key",
    $params
);
$fgCode = []; foreach ($db->fetchAll("SELECT id, product_code FROM finished_goods") as $x) { $fgCode[(int) $x['id']] = (string) $x['product_code']; }
$rmCode = []; foreach ($db->fetchAll("SELECT id, internal_code FROM raw_materials") as $x) { $rmCode[(int) $x['id']] = (string) $x['internal_code']; }

$calc = new FormulaCalcService();
$ctxCache = [];
$contextFor = function (string $kind, int $id) use ($calc, $db): array {
    try {
        $r = $kind === 'rm' ? $calc->calculateForRawMaterial($id) : $calc->calculate($id);
    } catch (\Throwable $e) {
        return [];
    }
    $fps = [];
    foreach ($r['formula_props']['enriched_lines'] ?? [] as $line) {
        $fp = $line['flash_point_c'] ?? null;
        if ($fp !== null && $fp !== '' && is_numeric($fp)) {
            $fps[] = ['c' => (float) $fp, 'gt' => !empty($line['flash_point_greater_than'])];
        }
    }
    $cas = array_values(array_unique(array_filter(array_map(
        static fn ($c): string => trim((string) ($c['cas_number'] ?? '')),
        $r['composition'] ?? []
    ), static fn (string $c): bool => $c !== '' && $c !== 'TRADE_SECRET')));
    $dot = [];
    if ($cas !== []) {
        $ph = implode(',', array_fill(0, count($cas), '?'));
        foreach ($db->fetchAll("SELECT un_number, proper_shipping_name, hazard_class, packing_group FROM dot_transport_info WHERE cas_number IN ({$ph})", $cas) as $d) {
            foreach ($d as $col => $v) {
                if ($v !== null && trim((string) $v) !== '') { $dot[$col][] = (string) $v; }
            }
        }
    }
    return ['raw_flash_points' => $fps, 'dot' => $dot];
};

$out("=== text_overrides cleanup, pass 2: blank / retired / old automatic text (audit #1, Q12) ===");
$out("Mode: " . ($apply ? 'APPLY (deleting)' : 'DRY-RUN (use --apply to delete)'));
$out("Age cutoff for legacy_* reasons: " . ($cutoff === null ? 'none (--any-date)' : $before . ' (rows written later are kept as operator text)'));
$out("Product-level rows scanned: " . count($rows));
$out('');

$delete = []; $byReason = []; $affected = ['fg' => [], 'rm' => []];
$keptNew = 0; $kept = 0; $orphan = 0; $review = [];
foreach ($rows as $r) {
    $afterCutoff = false;
    $kind = $r['finished_good_id'] !== null ? 'fg' : (($r['raw_material_id'] ?? null) !== null ? 'rm' : null);
    if ($kind === null) { $orphan++; continue; }
    $pid  = (int) ($kind === 'fg' ? $r['finished_good_id'] : $r['raw_material_id']);
    $s    = (int) $r['section_number'];
    $k    = (string) $r['field_key'];
    $lang = (string) $r['language'];
    $who  = strtoupper($kind) . ' ' . (($kind === 'fg' ? ($fgCode[$pid] ?? '') : ($rmCode[$pid] ?? '')) ?: ('#' . $pid));
    $ctx  = [];
    if (($s === 9 && $k === 'flash_point') || $s === 14) {
        $ctx = $ctxCache[$kind . ':' . $pid] ??= $contextFor($kind, $pid);
    }
    $reason = $matcher->reason($s, $k, $lang, (string) $r['override_text'], $ctx);
    if ($reason !== null && !in_array($reason, M::AGE_INDEPENDENT, true) && $cutoff !== null) {
        $ts = strtotime((string) $r['updated_at']);
        if ($ts === false || $ts >= $cutoff) {
            if ($verbose) { $out(sprintf("  keep (saved after cutoff, would be %s) %s [%s] %d.%s", $reason, $who, $lang, $s, $k)); }
            $reason      = null;
            $afterCutoff = true;
        }
    }
    if ($reason === null) {
        if ($afterCutoff) { $keptNew++; } else { $kept++; }
        if (in_array($s, [9, 14, 15], true)) {
            $review[] = sprintf("  %s [%s] %d.%s: %s", $who, $lang, $s, $k, $short((string) $r['override_text']));
        }
        continue;
    }
    $delete[] = (int) $r['id'];
    $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
    if ($reason !== M::REASON_RETIRED_FIELD) { $affected[$kind][$pid] = true; }
    if ($verbose) { $out(sprintf("  DELETE (%s) %s [%s] %d.%s: %s", $reason, $who, $lang, $s, $k, $short((string) $r['override_text']))); }
}

$out('');
$out('Delete, by reason:');
foreach ([M::REASON_BLANK, M::REASON_RETIRED_FIELD, M::REASON_LEGACY_FLASH, M::REASON_LEGACY_DOT, M::REASON_LEGACY_TEXT, M::REASON_LEGACY_COMPOSITE] as $rk) {
    $out(sprintf("  %-20s %5d", $rk, $byReason[$rk] ?? 0));
}
$out(sprintf("Total to delete:              %d", count($delete)));
$out(sprintf("Operator text (kept):         %d", $kept));
$out(sprintf("Saved after cutoff (kept):    %d", $keptNew));
$out(sprintf("No product id (kept):         %d", $orphan));
$out(sprintf("Products whose printed SDS changes: %d finished good(s), %d resale raw material(s)", count($affected['fg']), count($affected['rm'])));
$out('');
$out('Kept rows in Sections 9, 14 and 15 (review with the owner):');
foreach ($review as $line) { $out($line); }
if ($review === []) { $out('  (none)'); }

if (!$apply) {
    $out('');
    $out("Dry-run only. Re-run with --apply to delete the " . count($delete) . " row(s) and queue the affected products for republish.");
    exit(0);
}

$deleted = 0;
foreach (array_chunk($delete, 500) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $deleted += $db->delete('text_overrides', "id IN ({$ph}) AND sds_version_id IS NULL", $chunk);
}
$queued = 0;
foreach (array_keys($affected['fg']) as $fgId) {
    $hasPublished = $db->fetch("SELECT 1 FROM sds_versions WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0 LIMIT 1", [$fgId]);
    $pending      = $db->fetch("SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending'", [$fgId]);
    if ($hasPublished && !$pending) {
        $db->insert('sds_update_queue', [
            'finished_good_id' => $fgId,
            'reason'           => 'Text override cleanup (audit #1, Q12): old automatic text removed',
            'source_type'      => 'finished_good',
            'source_id'        => $fgId,
            'queued_by'        => null,
        ]);
        $queued++;
    }
}
$out('');
$out("Deleted {$deleted} row(s). Queued {$queued} finished good(s) on SDS Updates.");
if ($affected['rm'] !== []) {
    $out('Republish these resale SDSs from the SDS Creation Readiness Check: ' . implode(', ', array_map(static fn (int $id): string => ($rmCode[$id] ?? '') . ' (#' . $id . ')', array_keys($affected['rm']))));
}
exit(0);
