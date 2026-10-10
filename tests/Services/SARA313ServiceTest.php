<?php
/**
 * DB-free checks for finding #26 — SARA 313 supplier notification:
 *
 *   - PBT chemicals (40 CFR 372.28) are reportable at any concentration above
 *     0 (2023 TRI rule): threshold 0.0, never below_threshold.
 *   - Category members (RegulatoryCategoryService rows) are reported under the
 *     category name; a direct list row keeps its own name but takes the
 *     stricter threshold and the PBT flag of its category.
 *   - EPA footnote markers are stripped from list names.
 *   - TRADE_SECRET and zero-concentration rows are skipped; analyse() needs no
 *     DB when nothing has a CAS.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SARA313ServiceTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, ['paths' => []]);

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
        echo '       actual: ' . var_export($actual, true) . "\n";
    }
}

use SDS\Services\SARA313Service;

$comp = [
    ['cas_number' => '7439-92-1',    'chemical_name' => 'Lead',         'concentration_pct' => 0.025],
    ['cas_number' => '108-88-3',     'chemical_name' => 'Toluene',      'concentration_pct' => 0.5],
    ['cas_number' => '1314-13-2',    'chemical_name' => 'Zinc oxide',   'concentration_pct' => 2.0],
    ['cas_number' => '301-04-2',     'chemical_name' => 'Lead acetate', 'concentration_pct' => 0.05],
    ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 3.0],
    ['cas_number' => '111-76-2',     'chemical_name' => 'EGBE',         'concentration_pct' => 0.0],
];
$direct = [
    '7439-92-1' => ['cas_number' => '7439-92-1', 'chemical_name' => "Lead \u{2020}\u{2020}", 'category_code' => null, 'deminimis_pct' => '0.1000', 'is_pbt' => 1, 'pbt_threshold_pct' => '0.1000'],
    '108-88-3'  => ['cas_number' => '108-88-3',  'chemical_name' => 'Toluene',      'category_code' => null, 'deminimis_pct' => '1.0000', 'is_pbt' => 0, 'pbt_threshold_pct' => null],
    '301-04-2'  => ['cas_number' => '301-04-2',  'chemical_name' => 'Lead acetate', 'category_code' => null, 'deminimis_pct' => '1.0000', 'is_pbt' => 0, 'pbt_threshold_pct' => null],
];
$cats = [
    '1314-13-2' => [['category_code' => 'N982', 'category_name' => 'Zinc compounds',        'deminimis_pct' => '1.0000', 'is_pbt' => 0]],
    '301-04-2'  => [['category_code' => 'N420', 'category_name' => 'Lead compounds',        'deminimis_pct' => '0.1000', 'is_pbt' => 1]],
    '111-76-2'  => [['category_code' => 'N230', 'category_name' => 'Certain glycol ethers', 'deminimis_pct' => '1.0000', 'is_pbt' => 0]],
];

$r = SARA313Service::evaluate($comp, $direct, $cats);
$byCas = static function (array $rows): array {
    $o = [];
    foreach ($rows as $row) {
        $o[$row['cas_number']] = $row;
    }
    return $o;
};
$rep   = $byCas($r['reportable']);
$below = $byCas($r['below_threshold']);

echo "a. PBT (no de minimis)\n";
$lead = $rep['7439-92-1'] ?? null;
check($lead !== null && $lead['threshold_pct'] === 0.0 && $lead['is_pbt'] === true && $lead['sara_name'] === 'Lead', 'lead 0.025 % reportable, threshold 0, footnote stripped', $lead);

echo "b. de minimis\n";
check(isset($below['108-88-3']) && $below['108-88-3']['threshold_pct'] === 1.0 && !isset($rep['108-88-3']), 'toluene 0.5 % below its 1 % de minimis', $below['108-88-3'] ?? null);

echo "c. categories\n";
$zn = $rep['1314-13-2'] ?? null;
check($zn !== null && $zn['sara_name'] === 'Zinc compounds' && $zn['category_code'] === 'N982' && $zn['threshold_pct'] === 1.0, 'zinc oxide reported as Zinc compounds', $zn);
$pa = $rep['301-04-2'] ?? null;
check($pa !== null && $pa['is_pbt'] === true && $pa['threshold_pct'] === 0.0 && $pa['sara_name'] === 'Lead acetate' && $pa['category_code'] === 'N420', 'lead acetate: own name, PBT from the lead compounds category', $pa);

echo "d. skipped rows\n";
check(!isset($rep['TRADE_SECRET']) && !isset($below['TRADE_SECRET']), 'TRADE_SECRET never evaluated');
check(!isset($rep['111-76-2']) && !isset($below['111-76-2']), 'zero concentration skipped');
check(SARA313Service::evaluate([], [], []) === ['reportable' => [], 'below_threshold' => []], 'empty composition');

// TRI PFAS = chemical of special concern (2023 TRI rule, 40 CFR 372.28 / 372.29):
// no de minimis, but NOT a PBT (Section 12 / "PBT chemical" wording stay PBT-only).
$pfasDirect = [
    '27905-45-9' => ['cas_number' => '27905-45-9', 'chemical_name' => '1,1,2,2-Tetrahydroperfluorodecyl acrylate', 'category_code' => null, 'deminimis_pct' => '1.0000', 'is_pbt' => 0, 'is_special_concern' => 1, 'pbt_threshold_pct' => null],
    '108-88-3'   => ['cas_number' => '108-88-3',   'chemical_name' => 'Toluene', 'category_code' => null, 'deminimis_pct' => '1.0000', 'is_pbt' => 0, 'is_special_concern' => 0, 'pbt_threshold_pct' => null],
];
$rp = SARA313Service::evaluate([
    ['cas_number' => '27905-45-9', 'chemical_name' => 'Fluorosurfactant', 'concentration_pct' => 0.05],
    ['cas_number' => '108-88-3',   'chemical_name' => 'Toluene',          'concentration_pct' => 0.5],
], $pfasDirect, []);
$pf = $rp['reportable'][0] ?? null;
check(count($rp['reportable']) === 1 && $pf !== null && $pf['cas_number'] === '27905-45-9' && $pf['threshold_pct'] === 0.0
    && $pf['is_pbt'] === false && $pf['is_special_concern'] === true && $pf['status'] === 'reportable',
    'PFAS at 0.05 % reportable (threshold 0, special concern, not PBT)', $rp);
check(array_column($rp['below_threshold'], 'cas_number') === ['108-88-3'], 'toluene 0.5 % still below its 1 % de minimis', $rp['below_threshold']);
$rl = SARA313Service::evaluate([['cas_number' => '7439-92-1', 'concentration_pct' => 0.025]], $direct, []);
check(($rl['reportable'][0]['is_special_concern'] ?? null) === true && ($rl['reportable'][0]['is_pbt'] ?? null) === true, 'a PBT also carries is_special_concern', $rl);
$m058 = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/058_sds_audit_followup_t3.sql');
check(str_contains($m058, "ADD COLUMN `is_special_concern`") && str_contains($m058, "'27905-45-9'") && str_contains($m058, "'335-67-1'") && str_contains($m058, "'1763-23-1'"),
    '058 adds is_special_concern and flags TRI PFAS by explicit CAS (incl. PFOA, PFOS)');
check(!str_contains($m058, "'1717-00-6'"), '058 PFAS list does not include HCFCs (1,1-dichloro-1-fluoroethane)');
check(SARA313Service::analyse([['cas_number' => 'TRADE_SECRET', 'concentration_pct' => 5]]) === ['reportable' => [], 'below_threshold' => []], 'analyse() without any CAS needs no DB');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
