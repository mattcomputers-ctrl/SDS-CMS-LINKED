#!/usr/bin/env php
<?php
/**
 * Dashboard review counts — live-DB test (needs config/config.php)
 *
 * Runs inside ONE transaction that is always rolled back, so it is safe on
 * the production database: its rows (codes ZZDASH*, CAS 7777740..7777741)
 * disappear at the end, also on failure. Counts are compared before and
 * after the fixtures, so existing data does not matter:
 *   - a constituent CAS without a determination adds a pending determination;
 *   - the same CAS, not on the TSCA inventory, in a product bulk publish is
 *     cleared to publish adds a TSCA review item;
 *   - a substance whose determination carries H372 adds a STOT review until
 *     its organs are entered.
 * The cache is not touched (the counts are computed directly).
 *
 * Run:
 *   sudo -u www-data php tests/Services/ReviewCountsLiveTest.php
 *
 * Exit code: 0 = passed (or skipped), 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\ReviewCounts;

if (!is_file($basePath . '/config/config.php')) {
    echo "SKIP: no config/config.php (live-DB test)\n";
    exit(0);
}
new \SDS\Core\App();
$db  = \SDS\Core\Database::getInstance();
$pdo = $db->getPdo();
if ((int) ($db->fetch("SELECT COUNT(*) AS n FROM raw_materials WHERE internal_code LIKE 'ZZDASH%'")['n'] ?? 0) > 0) {
    echo "SKIP: raw materials ZZDASH* already exist\n";
    exit(0);
}

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

$counts = static fn (): array => ReviewCounts::fromLists(
    ReviewCounts::needsDeterminationRows($db),
    \SDS\Services\TSCAService::reviewCandidates(\SDS\Controllers\BulkPublishController::rawMaterialsOnClearedSheets($db)),
    \SDS\Services\StotTargetOrgans::adminRows($db)
);

$pdo->beginTransaction();
try {
    $before = $counts();
    $uid = (int) ($db->fetch("SELECT id FROM users ORDER BY id LIMIT 1")['id'] ?? 0);
    if ($uid === 0) {
        $uid = (int) $db->insert('users', ['username' => 'zzdash_reviewer', 'password_hash' => 'x']);
    }
    // Reviewed raw material with a CAS that is neither determined nor on the inventory, in an active product.
    $rmA = (int) $db->insert('raw_materials', ['internal_code' => 'ZZDASHA']);
    $db->insert('audit_log', ['user_id' => $uid, 'entity_type' => 'raw_material', 'entity_id' => (string) $rmA, 'action' => 'update']);
    $db->insert('raw_material_constituents', ['raw_material_id' => $rmA, 'cas_number' => '7777740-77-0', 'chemical_name' => 'ZZ dash A', 'pct_exact' => 100]);
    $fg = (int) $db->insert('finished_goods', ['product_code' => 'ZZDASHFG']);
    $f  = (int) $db->insert('formulas', ['finished_good_id' => $fg, 'is_current' => 1]);
    $db->insert('formula_lines', ['formula_id' => $f, 'raw_material_id' => $rmA, 'pct' => 100]);
    // A substance whose active determination carries H372, in a raw material no formula uses.
    $rmB = (int) $db->insert('raw_materials', ['internal_code' => 'ZZDASHB']);
    $db->insert('raw_material_constituents', ['raw_material_id' => $rmB, 'cas_number' => '7777741-77-1', 'chemical_name' => 'ZZ dash B', 'pct_exact' => 100]);
    $db->insert('competent_person_determinations', ['cas_number' => '7777741-77-1', 'determination_json' => json_encode(['h_statements' => ['H372']]),
        'rationale_text' => 'ZZ test', 'is_active' => 1]);

    $after = $counts();
    echo "Counts\n";
    check($after['needs'] === $before['needs'] + 1, 'a constituent CAS without a determination: one more pending determination', [$before, $after]);
    check($after['tsca'] === $before['tsca'] + 1, 'that CAS, not on the inventory, in a product bulk publish is cleared to publish: one more TSCA review item', [$before, $after]);
    check($after['stot'] === $before['stot'] + 1, 'a substance whose determination carries H372: one more STOT review', [$before, $after]);

    \SDS\Services\StotTargetOrgans::writeCasStot($db, '7777741-77-1', ['stot_re_organs' => ['lungs']]);
    $db->query("UPDATE cas_master SET tsca_status = 'listed', tsca_note = 'ZZ test' WHERE cas_number = '7777740-77-0'");
    if (!$db->fetch("SELECT 1 FROM cas_master WHERE cas_number = '7777740-77-0'")) {
        $db->insert('cas_master', ['cas_number' => '7777740-77-0', 'preferred_name' => 'ZZ', 'tsca_status' => 'listed', 'tsca_note' => 'ZZ test']);
    }
    $done = $counts();
    check($done['stot'] === $before['stot'] && $done['tsca'] === $before['tsca'] && $done['needs'] === $after['needs'],
        'organs entered and a TSCA override set: those counts go back down (the determination is still pending)', [$before, $done]);
} finally {
    $pdo->rollBack();
}

check((int) ($db->fetch("SELECT COUNT(*) AS n FROM raw_materials WHERE internal_code LIKE 'ZZDASH%'")['n'] ?? 0) === 0, 'rolled back: no test rows left');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
