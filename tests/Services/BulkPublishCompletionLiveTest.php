#!/usr/bin/env php
<?php
/**
 * Bulk publish language order — live-DB test (needs config/config.php)
 *
 * Runs inside ONE transaction that is always rolled back, so it is safe on
 * the production database: every row it inserts (codes ZZBPT*) disappears
 * at the end, also on failure, and other sessions never see them. It checks
 * the eligibility rules with real SQL (2026-10-19):
 *   - a run stopped after the Spanish pass: the product's missing languages
 *     (and alias and private-label sheets) are completed at the SAME version;
 *   - a run stopped during the French pass, after the base sheet: the alias
 *     French sheet is completed;
 *   - a raw material changed while a run was writing a product's later
 *     languages: the product is stale (full new version), not "complete";
 *   - a language retired on purpose is not brought back;
 *   - completion is held back by Rule 3 (an unpublished sub-product).
 *
 * Run:
 *   sudo -u www-data php tests/Services/BulkPublishCompletionLiveTest.php
 *
 * Exit code: 0 = passed (or skipped), 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Controllers\BulkPublishController as BP;

if (!is_file($basePath . '/config/config.php')) {
    echo "SKIP: no config/config.php (live-DB test)\n";
    exit(0);
}
new \SDS\Core\App();
$db  = \SDS\Core\Database::getInstance();
$pdo = $db->getPdo();

$failures = 0;
$checks   = 0;
function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) {
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

if ((int) ($db->fetch("SELECT COUNT(*) AS n FROM finished_goods WHERE product_code LIKE 'ZZBPT%'")['n'] ?? 0) > 0) {
    echo "SKIP: products ZZBPT* already exist\n";
    exit(0);
}

$languages = \SDS\Core\App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
if (count($languages) < 2) {
    echo "SKIP: needs at least two configured languages\n";
    exit(0);
}
$first = BP::publishLanguageOrder($languages)[0];
$rest  = array_values(array_diff($languages, [$first]));
$lastL = end($rest);

$pdo->beginTransaction();
try {
    // The company phone gates every base sheet; set it inside the transaction.
    $db->query("INSERT INTO settings (`key`, `value`) VALUES ('company.emergency_phone', '800-555-0100')
                ON DUPLICATE KEY UPDATE `value` = IF(`value` = '' OR `value` IS NULL, VALUES(`value`), `value`)");

    $uid = (int) $db->insert('users', ['username' => 'zz_bpt_' . bin2hex(random_bytes(3)), 'email' => 'zz_bpt_' . bin2hex(random_bytes(3)) . '@example.test',
        'password_hash' => 'x', 'display_name' => 'BPT reviewer']);
    $old = '2026-01-01 00:00:00';
    $rm = function (string $code) use ($db, $uid, $old): int {
        $id = (int) $db->insert('raw_materials', ['internal_code' => $code]);
        $db->query('UPDATE raw_materials SET updated_at = ? WHERE id = ?', [$old, $id]);
        $db->insert('audit_log', ['user_id' => $uid, 'entity_type' => 'raw_material', 'entity_id' => (string) $id, 'action' => 'update']);
        return $id;
    };
    $rm1 = $rm('ZZBPTRM1');
    $rm2 = $rm('ZZBPTRM2');
    $fg = function (string $code, array $lines) use ($db, $old): int {
        $id = (int) $db->insert('finished_goods', ['product_code' => $code]);
        $db->query('UPDATE finished_goods SET updated_at = ? WHERE id = ?', [$old, $id]);
        $f = (int) $db->insert('formulas', ['finished_good_id' => $id, 'is_current' => 1, 'created_at' => $old]);
        foreach ($lines as $l) {
            $db->insert('formula_lines', $l + ['formula_id' => $f, 'pct' => 50]);
        }
        return $id;
    };
    $sv = function (array $src, string $lang, int $ver, string $at, int $deleted = 0) use ($db): void {
        $db->insert('sds_versions', $src + ['language' => $lang, 'version' => $ver, 'status' => 'published',
            'published_at' => $at, 'is_deleted' => $deleted, 'effective_date' => substr($at, 0, 10)]);
    };

    // STOP: run reached v3 in the first language only; the rest still at v2.
    $stop = $fg('ZZBPTSTOP', [['raw_material_id' => $rm1]]);
    foreach ($languages as $l) { $sv(['finished_good_id' => $stop], $l, 2, '2026-09-01 00:00:00'); }
    $sv(['finished_good_id' => $stop], $first, 3, '2026-10-19 08:00:00');
    $aliasA = (int) $db->insert('aliases', ['customer_code' => 'ZZBPTA', 'internal_code' => 'ZZBPTSTOP', 'internal_code_base' => 'ZZBPTSTOP']);
    $sv(['finished_good_id' => $stop, 'alias_id' => $aliasA], $first, 7, '2026-10-19 08:01:00');
    foreach ($rest as $l) { $sv(['finished_good_id' => $stop, 'alias_id' => $aliasA], $l, 6, '2026-09-01 00:00:00'); }

    // CUT: base complete at v4, alias missing the last language (killed in the last pass).
    $cut = $fg('ZZBPTCUT', [['raw_material_id' => $rm1]]);
    foreach ($languages as $i => $l) { $sv(['finished_good_id' => $cut], $l, 4, sprintf('2026-10-19 %02d:00:00', 8 + $i)); }
    $aliasB = (int) $db->insert('aliases', ['customer_code' => 'ZZBPTB', 'internal_code' => 'ZZBPTCUT', 'internal_code_base' => 'ZZBPTCUT']);
    foreach ($languages as $i => $l) {
        $sv(['finished_good_id' => $cut, 'alias_id' => $aliasB], $l, $l === $lastL ? 2 : 3, $l === $lastL ? '2026-09-01 00:00:00' : sprintf('2026-10-19 %02d:01:00', 8 + $i));
    }

    // MID: first language at 08:00, the rest later from the same cached data;
    // its raw material changed at 09:00 -> stale, a full new version.
    $mid = $fg('ZZBPTMID', [['raw_material_id' => $rm2]]);
    foreach ($languages as $i => $l) { $sv(['finished_good_id' => $mid], $l, 5, $i === 0 ? '2026-10-19 08:00:00' : sprintf('2026-10-19 %02d:00:00', 10 + $i)); }
    $db->query("UPDATE raw_materials SET updated_at = '2026-10-19 09:00:00' WHERE id = ?", [$rm2]);

    // RET: last language of v3 retired on purpose -> nothing.
    $ret = $fg('ZZBPTRET', [['raw_material_id' => $rm1]]);
    foreach ($languages as $l) { $sv(['finished_good_id' => $ret], $l, 3, '2026-10-19 08:00:00', $l === $lastL ? 1 : 0); }
    $sv(['finished_good_id' => $ret], $lastL, 2, '2026-09-01 00:00:00');

    // PAR: an older parallel run wrote base and alias sheets within seconds, the
    // alias's first language a second before the base's first sheet -> complete.
    $par = $fg('ZZBPTPAR', [['raw_material_id' => $rm1]]);
    $aliasP = (int) $db->insert('aliases', ['customer_code' => 'ZZBPTP', 'internal_code' => 'ZZBPTPAR', 'internal_code_base' => 'ZZBPTPAR']);
    foreach ($languages as $i => $l) {
        $sv(['finished_good_id' => $par], $l, 3, sprintf('2026-10-19 10:00:%02d', 5 + $i));
        $sv(['finished_good_id' => $par, 'alias_id' => $aliasP], $l, 2, sprintf('2026-10-19 10:00:%02d', $i === 0 ? 4 : 6 + $i));
    }

    // RFI: the first language's v3 sheet retired on purpose, the rest at v3 -> nothing to do.
    $rfi = $fg('ZZBPTRFI', [['raw_material_id' => $rm1]]);
    $aliasR = (int) $db->insert('aliases', ['customer_code' => 'ZZBPTR', 'internal_code' => 'ZZBPTRFI', 'internal_code_base' => 'ZZBPTRFI']);
    foreach ($languages as $i => $l) {
        $sv(['finished_good_id' => $rfi], $l, 2, '2026-09-01 00:00:00');
        $sv(['finished_good_id' => $rfi], $l, 3, sprintf('2026-10-19 %02d:00:00', 8 + $i), $i === 0 ? 1 : 0);
        $sv(['finished_good_id' => $rfi, 'alias_id' => $aliasR], $l, 4, sprintf('2026-10-19 %02d:00:02', 8 + $i));
    }

    // HOLD: missing languages, but its sub-product has no formula and was never published -> Rule 3.
    $sub  = (int) $db->insert('finished_goods', ['product_code' => 'ZZBPTSUB']);
    $hold = $fg('ZZBPTHOLD', [['raw_material_id' => $rm1], ['finished_good_component_id' => $sub]]);
    $sv(['finished_good_id' => $hold], $first, 2, '2026-10-19 08:00:00');

    $e    = BP::computeEligibleFinishedGoods($db);
    $mine = static fn (array $list): array => array_values(array_filter($list, static fn ($x) => str_starts_with((string) $x['product_code'], 'ZZBPT')));
    $eligible   = array_column($mine($e['eligible']), 'product_code');
    $incomplete = [];
    foreach ($mine($e['incomplete']) as $x) {
        $incomplete[$x['product_code']] = [$x['version'], $x['missing']];
    }

    check(in_array('ZZBPTMID', $eligible, true), 'raw material changed between the first and later languages: stale, full new version', $eligible);
    check(!isset($incomplete['ZZBPTMID']), '... and not treated as a completion');
    check(($incomplete['ZZBPTSTOP'] ?? null) === [3, $rest], 'run stopped after the first language: the others completed at v3', $incomplete['ZZBPTSTOP'] ?? null);
    check(($incomplete['ZZBPTCUT'] ?? null) === [4, []], 'run killed in the last pass after the base sheet: completed for its alias', $incomplete['ZZBPTCUT'] ?? null);
    check(!isset($incomplete['ZZBPTRET']) && !in_array('ZZBPTRET', $eligible, true), 'a language retired on purpose is not brought back');
    check(!isset($incomplete['ZZBPTHOLD']), 'completion waits for an unpublished sub-product (Rule 3)');
    check(!isset($incomplete['ZZBPTPAR']) && !in_array('ZZBPTPAR', $eligible, true), 'alias sheets an older parallel run wrote at the same version: complete (no endless completion)');
    check(!isset($incomplete['ZZBPTRFI']) && !in_array('ZZBPTRFI', $eligible, true), 'first-language sheet retired on purpose: nothing to do, and no phantom completion');

    $items = BP::buildWorkItems($db, [], [], $languages, $mine($e['incomplete']), []);
    $got = [];
    foreach ($items as $it) {
        $got[] = $it['product_code'] . ' ' . (isset($it['alias_id']) ? 'alias' : 'base') . ' ' . $it['language'] . ' v' . $it['version'];
    }
    $want = [];
    foreach ($rest as $l) { $want[] = "ZZBPTSTOP base {$l} v3"; }
    foreach ($rest as $l) { $want[] = "ZZBPTSTOP alias {$l} v7"; }
    $want[] = "ZZBPTCUT alias {$lastL} v3";
    sort($got);
    sort($want);
    check($got === $want, 'completion items: base at the base version, alias at the version the run gave it', $got);

    // Language passes on the job row (migration 065), as the queue page reads them.
    $jobId = (int) $db->insert('bulk_publish_jobs', ['status' => 'running', 'triggered_by' => 'cli']);
    $hasCol = (int) ($db->fetch("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_publish_jobs' AND COLUMN_NAME = 'language_progress'")['n'] ?? 0) > 0;
    \SDS\Services\BulkPublishQueue::updateLanguageProgress($jobId, ['order' => [$first], 'passes' => [$first => ['items' => 5, 'processed' => 5, 'done_at' => '2026-10-20 14:05:00']]]);
    if ($hasCol) {
        $rowsLp = BP::languageProgressRows($db->fetch('SELECT language_progress FROM bulk_publish_jobs WHERE id = ?', [$jobId])['language_progress'] ?? null);
        check(count($rowsLp) === 1 && $rowsLp[0]['done_at'] === '2026-10-20 14:05:00', 'the first language\'s pass is stored on the job and read back for the queue page', $rowsLp);
    } else {
        check(true, 'migration 065 not run yet: progress write is a no-op (no error)');
    }
} finally {
    $pdo->rollBack();
}

check((int) ($db->fetch("SELECT COUNT(*) AS n FROM finished_goods WHERE product_code LIKE 'ZZBPT%'")['n'] ?? 0) === 0, 'rolled back: no test rows left');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
