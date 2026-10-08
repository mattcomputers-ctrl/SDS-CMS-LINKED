#!/usr/bin/env php
<?php
/**
 * HazardEngine::derivePPE() test (DB-free)
 *
 * Verifies the H-code -> PPE tier mapping, the baseline tiers
 * ('general' / 'none'), combined-code splitting, that P-statements are
 * ignored, and that every section8.ppe.<field>.<tier> translation key
 * exists in all four language files (and the old flat keys are gone).
 *
 * Run:
 *   php tests/Services/DerivePPETest.php
 *
 * Exit code:
 *   0 = passed
 *   1 = failed
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

use SDS\Services\HazardEngine;

$failures = 0;

function h(string ...$codes): array
{
    return array_map(static fn(string $c) => ['code' => $c, 'text' => ''], $codes);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function assertTiers(string $name, array $result, array $expected): void
{
    foreach ($expected as $field => $tier) {
        $got = $result[$field]['tier'] ?? '(missing)';
        check("{$name}: {$field} = {$tier}", $got === $tier, "got {$got}");
        $expectedKey = 'section8.ppe.' . $field . '.' . $tier;
        $gotKey = $result[$field]['key'] ?? '(missing)';
        check("{$name}: {$field} key = {$expectedKey}", $gotKey === $expectedKey, "got {$gotKey}");
    }
}

$allNone    = ['respiratory' => 'none',    'hand_protection' => 'none',    'eye_protection' => 'none',    'skin_protection' => 'none'];
$allGeneral = ['respiratory' => 'general', 'hand_protection' => 'general', 'eye_protection' => 'general', 'skin_protection' => 'general'];

// 1. No H-codes -> every field 'none'
$r1 = HazardEngine::derivePPE([], []);
assertTiers('case 1 (no H-codes)', $r1, $allNone);

// 2. P280 only, no H -> still all 'none' (P-codes ignored)
$r2 = HazardEngine::derivePPE([], [['code' => 'P280', 'text' => 'Wear protective gloves/protective clothing/eye protection/face protection']]);
assertTiers('case 2 (P280 only)', $r2, $allNone);

// 3. H226 (flammable only) -> all 'general'
$r3 = HazardEngine::derivePPE(h('H226'), []);
assertTiers('case 3 (H226)', $r3, $allGeneral);

// 4. Smoke fixture H315/H319/H411
$r4 = HazardEngine::derivePPE(h('H315', 'H319', 'H411'), []);
assertTiers('case 4 (H315/H319/H411)', $r4, [
    'respiratory' => 'general', 'hand_protection' => 'resistant', 'eye_protection' => 'goggles', 'skin_protection' => 'clothing',
]);

// 5. H314 corrosive
$r5 = HazardEngine::derivePPE(h('H314'), []);
assertTiers('case 5 (H314)', $r5, [
    'respiratory' => 'general', 'hand_protection' => 'impervious', 'eye_protection' => 'goggles_faceshield', 'skin_protection' => 'suit',
]);

// 6. Combined code must split
$r6 = HazardEngine::derivePPE(h('H300+H310+H330'), []);
assertTiers('case 6 (H300+H310+H330)', $r6, [
    'respiratory' => 'scba', 'hand_protection' => 'impervious', 'eye_protection' => 'general', 'skin_protection' => 'suit',
]);

// 7. Sensitizers
$r7 = HazardEngine::derivePPE(h('H334', 'H317'), []);
assertTiers('case 7 (H334/H317)', $r7, [
    'respiratory' => 'sensitizer', 'hand_protection' => 'sensitizer', 'eye_protection' => 'general', 'skin_protection' => 'clothing',
]);

// 8. Systemic (carcinogen) code
$r8 = HazardEngine::derivePPE(h('H350'), []);
assertTiers('case 8 (H350)', $r8, [
    'respiratory' => 'cartridge', 'hand_protection' => 'resistant', 'eye_protection' => 'general', 'skin_protection' => 'clothing',
]);

// 9. Respiratory irritant only
$r9 = HazardEngine::derivePPE(h('H335'), []);
assertTiers('case 9 (H335)', $r9, [
    'respiratory' => 'cartridge', 'hand_protection' => 'general', 'eye_protection' => 'general', 'skin_protection' => 'general',
]);

// 10. Baseline flags
foreach (['case 1' => $r1, 'case 2' => $r2, 'case 3' => $r3] as $label => $r) {
    foreach (HazardEngine::PPE_FIELDS as $f) {
        check("case 10 ({$label}): {$f} is baseline", in_array($r[$f]['tier'], HazardEngine::PPE_BASELINE_TIERS, true));
    }
}
foreach (['hand_protection', 'eye_protection', 'skin_protection'] as $f) {
    check("case 10 (case 4): {$f} is hazard-driven", !in_array($r4[$f]['tier'], HazardEngine::PPE_BASELINE_TIERS, true));
}
check('case 10 (case 4): respiratory is baseline', in_array($r4['respiratory']['tier'], HazardEngine::PPE_BASELINE_TIERS, true));

// 11. Translation completeness
$tierTable = [
    'respiratory'     => ['scba', 'sensitizer', 'cartridge', 'general', 'none'],
    'hand_protection' => ['impervious', 'sensitizer', 'resistant', 'general', 'none'],
    'eye_protection'  => ['goggles_faceshield', 'goggles', 'glasses', 'general', 'none'],
    'skin_protection' => ['suit', 'clothing', 'general', 'none'],
];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $t = require $basePath . '/templates/translations/' . $lang . '.php';
    $count = 0;
    $missing = [];
    foreach ($tierTable as $field => $tiers) {
        foreach ($tiers as $tier) {
            $v = $t['section8']['ppe'][$field][$tier] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $count++;
            } else {
                $missing[] = "section8.ppe.{$field}.{$tier}";
            }
        }
    }
    check("case 11 ({$lang}): 19 PPE keys present", $count === 19 && $missing === [], 'missing: ' . implode(', ', $missing));
    foreach (HazardEngine::PPE_FIELDS as $f) {
        check("case 11 ({$lang}): old key section8.{$f} removed", !isset($t['section8'][$f]));
    }
}

if ($failures > 0) {
    echo "\n=== {$failures} FAILURE(S) ===\n";
    exit(1);
}
echo "\n=== ALL TESTS PASSED ===\n";
exit(0);
