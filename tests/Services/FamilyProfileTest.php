#!/usr/bin/env php
<?php
/**
 * Product-family "SDS profile" unit test (A11, owner 2026-10-12, DB-free)
 *
 *   A. ProductFamily::normaliseProfileInput() validation + normalisation;
 *   B. ProductFamily::profile() typed row (DB strings, half / inverted pairs);
 *   C. ProductFamily::profileSummary();
 *   D. SDSGenerator::familyFields() carries family_profile (inactive = empty);
 *   E. WaterBasedRule (override, auto threshold, settings validation);
 *   F. physical-state precedence (product > family default > largest raw > Liquid)
 *      and the preview-only family physical-state note;
 *   G. SheetContentSettings: the threshold is a sheet-content setting (#58);
 *   H. source assertions (migration 061, three call sites, controller wiring).
 *
 * Run:
 *   php tests/Services/FamilyProfileTest.php
 *
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

use SDS\Models\ProductFamily as PF;
use SDS\Services\SDSGenerator;
use SDS\Services\SheetContentSettings;
use SDS\Services\WaterBasedRule as WB;

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

/** Message of the InvalidArgumentException thrown by normaliseProfileInput, or null when none. */
function profileError(array $raw): ?string
{
    try {
        PF::normaliseProfileInput($raw);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return null;
}

// ---------------------------------------------------------------------
echo "A. normaliseProfileInput\n";
check(PF::normaliseProfileInput([]) === PF::PROFILE_EMPTY, '[] -> PROFILE_EMPTY');
$blankRaw = ['storage_temp_min_c' => '', 'storage_temp_max_c' => ' ', 'water_based' => '', 'default_physical_state' => '', 'ph_min' => '', 'ph_max' => ''];
check(PF::normaliseProfileInput($blankRaw) === PF::PROFILE_EMPTY, 'all blank -> PROFILE_EMPTY', PF::normaliseProfileInput($blankRaw));
$full = PF::normaliseProfileInput(['storage_temp_min_c' => '5', 'storage_temp_max_c' => '30', 'water_based' => 'yes', 'default_physical_state' => 'paste', 'ph_min' => '8', 'ph_max' => '9,5']);
check($full === ['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0, 'water_based' => 'yes', 'default_physical_state' => 'Paste', 'ph_min' => 8.0, 'ph_max' => 9.5], 'full profile normalised (comma decimal, canonical state)', $full);

$errorCases = [
    'min only'           => [['storage_temp_min_c' => '5'], 'both storage temperatures'],
    'min 30 / max 5'     => [['storage_temp_min_c' => '30', 'storage_temp_max_c' => '5'], 'lower than'],
    'min == max'         => [['storage_temp_min_c' => '20', 'storage_temp_max_c' => '20'], 'lower than'],
    'min -60'            => [['storage_temp_min_c' => '-60', 'storage_temp_max_c' => '5'], 'between -50 and 100'],
    'min abc'            => [['storage_temp_min_c' => 'abc', 'storage_temp_max_c' => '5'], 'must be a number'],
    'pH 9 / 8'           => [['ph_min' => '9', 'ph_max' => '8'], 'not be higher'],
    'pH 15'              => [['ph_min' => '7', 'ph_max' => '15'], 'between 0 and 14'],
    'pH min only'        => [['ph_min' => '7'], 'both pH'],
    'water_based maybe'  => [['water_based' => 'maybe'], 'water-based'],
    'state Gas'          => [['default_physical_state' => 'Gas'], 'default physical state'],
];
foreach ($errorCases as $label => [$raw, $needle]) {
    $msg = profileError($raw);
    check($msg !== null && str_contains($msg, $needle), "throws for {$label} ('{$needle}')", $msg);
}
$eq = PF::normaliseProfileInput(['ph_min' => '8.0', 'ph_max' => '8.0']);
check($eq['ph_min'] === 8.0 && $eq['ph_max'] === 8.0, 'pH 8.0 / 8.0 accepted', $eq);
$rounded = PF::normaliseProfileInput(['ph_min' => '7', 'ph_max' => '12.34']);
check($rounded['ph_max'] === 12.3, "'12.34' rounded to 12.3", $rounded);
$two = profileError(['storage_temp_min_c' => '5', 'default_physical_state' => 'Gas']);
check($two !== null && str_contains($two, 'both storage temperatures') && str_contains($two, 'default physical state'), 'two errors reported together', $two);

// ---------------------------------------------------------------------
echo "B. profile\n";
check(PF::profile(null) === PF::PROFILE_EMPTY, 'null -> PROFILE_EMPTY');
$row = ['id' => 3, 'name' => 'Offset', 'is_active' => 1, 'is_uv' => 0, 'storage_temp_min_c' => '5.0', 'storage_temp_max_c' => '30.0', 'water_based' => 'no', 'default_physical_state' => 'Powder', 'ph_min' => '8.0', 'ph_max' => '9.5'];
$p = PF::profile($row);
check($p === ['storage_temp_min_c' => 5.0, 'storage_temp_max_c' => 30.0, 'water_based' => 'no', 'default_physical_state' => 'Powder', 'ph_min' => 8.0, 'ph_max' => 9.5], 'DB-string row -> floats', $p);
$half = PF::profile(['storage_temp_min_c' => '5.0', 'storage_temp_max_c' => null] + $row);
check($half['storage_temp_min_c'] === null && $half['storage_temp_max_c'] === null, 'half storage pair -> both null', $half);
$inv = PF::profile(['storage_temp_min_c' => '30.0', 'storage_temp_max_c' => '5.0'] + $row);
check($inv['storage_temp_min_c'] === null && $inv['storage_temp_max_c'] === null, 'inverted storage pair -> both null', $inv);
$halfPh = PF::profile(['ph_min' => null] + $row);
check($halfPh['ph_min'] === null && $halfPh['ph_max'] === null, 'half pH pair -> both null', $halfPh);
check(PF::profile(['water_based' => 'bogus'] + $row)['water_based'] === 'auto', "water_based 'bogus' -> 'auto'");
check(PF::profile(['default_physical_state' => 'gas'] + $row)['default_physical_state'] === null, "state 'gas' -> null");
check(PF::profile(['default_physical_state' => 'paste'] + $row)['default_physical_state'] === 'Paste', "state 'paste' -> 'Paste'");
check(PF::profile(['id' => 1, 'name' => 'Legacy']) === PF::PROFILE_EMPTY, 'pre-061 row without profile columns -> PROFILE_EMPTY');

// ---------------------------------------------------------------------
echo "C. profileSummary\n";
check(PF::profileSummary(PF::PROFILE_EMPTY) === '', 'empty profile -> \'\'');
check(PF::profileSummary(null) === '', 'null -> \'\'');
$sum = PF::profileSummary($row);
check($sum === 'Store 5–30 °C · Not water-based · Powder · pH 8.0–9.5', 'full summary', $sum);

// ---------------------------------------------------------------------
echo "D. SDSGenerator::familyFields\n";
$ff = SDSGenerator::familyFields($row, 'legacy');
check(($ff['family_profile'] ?? null) === PF::profile($row), 'active family -> family_profile = profile(row)', $ff['family_profile'] ?? null);
check(SDSGenerator::familyFields(['is_active' => 0] + $row, 'legacy')['family_profile'] === PF::PROFILE_EMPTY, 'inactive family -> PROFILE_EMPTY');
check(SDSGenerator::familyFields(null, null)['family_profile'] === PF::PROFILE_EMPTY, 'no family -> PROFILE_EMPTY');
check(array_diff(['family', 'family_is_uv', 'family_defaults', 'family_profile'], array_keys($ff)) === [], 'familyFields keeps its keys and adds family_profile', array_keys($ff));

// ---------------------------------------------------------------------
echo "E. WaterBasedRule\n";
$solvent = ['cas_number' => '64-17-5', 'chemical_name' => 'Ethanol', 'concentration_pct' => 30.0, 'hazard_classes' => []];
$water   = static fn(float $pct): array => ['cas_number' => '7732-18-5', 'chemical_name' => 'Water', 'concentration_pct' => $pct, 'hazard_classes' => []];
$comp20  = [$water(20.0), $solvent];
$comp1999 = [$water(19.99), $solvent];
$comp60  = [$water(60.0), $solvent];
$ts      = ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Water (trade secret)', 'concentration_pct' => 50.0, 'hazard_classes' => []];
check(WB::waterPct($comp20) === 20.0, 'waterPct = 20.0', WB::waterPct($comp20));
check(WB::isWaterBased('auto', $comp20, 20.0) === true, 'auto, 20 % at threshold 20 -> water-based');
check(WB::isWaterBased('auto', $comp1999, 20.0) === false, 'auto, 19.99 % -> not water-based');
check(WB::isWaterBased('yes', [$solvent], 20.0) === true, "'yes' with no water -> water-based");
check(WB::isWaterBased('no', $comp60, 20.0) === false, "'no' with 60 % water -> not water-based");
check(WB::isWaterBased('garbage', $comp60, 20.0) === true && WB::isWaterBased('garbage', $comp1999, 20.0) === false, "'garbage' acts as auto");
check(WB::isWaterBased('auto', [$ts, $solvent], 20.0) === false && WB::waterPct([$ts]) === 0.0, 'TRADE_SECRET row ignored');
check(WB::forProduct([], $comp20, 20.0) === true, 'forProduct without family_profile uses auto');
check(WB::forProduct(['family_profile' => ['water_based' => 'no']], $comp60, 20.0) === false, "forProduct family 'no' wins over 60 % water");
check(WB::forProduct(['family_profile' => PF::PROFILE_EMPTY], $comp60, 20.0) === true, 'forProduct PROFILE_EMPTY = auto');
foreach (['0', 'abc', '101', ''] as $bad) {
    check(WB::thresholdInputError($bad) !== null, "thresholdInputError('{$bad}') non-null");
}
foreach (['20', '1', '100', ' 35.5 '] as $good) {
    check(WB::thresholdInputError($good) === null, "thresholdInputError('{$good}') null");
}
check(WB::normaliseThreshold(null) === 20.0 && WB::normaliseThreshold('') === 20.0 && WB::normaliseThreshold('150') === 20.0, 'normaliseThreshold null / blank / 150 -> 20.0');
check(WB::normaliseThreshold('35') === 35.0, "normaliseThreshold('35') -> 35.0");

// ---------------------------------------------------------------------
echo "F. physical-state precedence + family note\n";
$famPaste = ['family_profile' => ['default_physical_state' => 'Paste']];
$liquidCalc = ['formula_props' => ['physical_state' => 'Liquid']];
check(SDSGenerator::resolvePhysicalState($famPaste, $liquidCalc) === 'Paste', 'family default beats largest raw');
check(SDSGenerator::resolvePhysicalState(['physical_state' => 'Gel'] + $famPaste, $liquidCalc) === 'Gel', 'own state beats family default');
check(SDSGenerator::resolvePhysicalState(['family_profile' => ['default_physical_state' => null]], ['formula_props' => ['physical_state' => 'Solid']]) === 'Solid', 'no family default -> largest raw');
check(SDSGenerator::resolvePhysicalState([], []) === 'Liquid', 'nothing -> Liquid');
check(SDSGenerator::resolvePhysicalState(['family_profile' => PF::PROFILE_EMPTY], []) === 'Liquid', 'PROFILE_EMPTY -> Liquid');
check(SDSGenerator::physicalStateIsDefault($famPaste, []) === false, 'family default -> not the hard-coded default');
check(SDSGenerator::physicalStateIsDefault(['family_profile' => PF::PROFILE_EMPTY], []) === true, 'PROFILE_EMPTY + nothing -> default');
check(SDSGenerator::familyPhysicalStateWarning(['physical_state' => 'Liquid'] + $famPaste, $liquidCalc) === null, 'note: own state set -> null');
check(SDSGenerator::familyPhysicalStateWarning(['family_profile' => PF::PROFILE_EMPTY], $liquidCalc) === null, 'note: family blank -> null');
check(SDSGenerator::familyPhysicalStateWarning($famPaste, ['formula_props' => ['physical_state' => 'paste']]) === null, 'note: same state (case-insensitive) -> null');
check(SDSGenerator::familyPhysicalStateWarning($famPaste, ['formula_props' => ['physical_state' => '']]) === null, 'note: derived blank -> null');
$note = SDSGenerator::familyPhysicalStateWarning($famPaste, $liquidCalc);
check(is_string($note) && str_contains($note, '"Paste"') && str_contains($note, '"Liquid"') && !str_contains($note, 'Not determined'), 'note names family and derived state', $note);
check(!str_contains(SDSGenerator::PHYSICAL_STATE_DEFAULT_WARNING, 'Not determined') && str_contains(SDSGenerator::PHYSICAL_STATE_DEFAULT_WARNING, 'product family'), 'default warning mentions the family default');

// ---------------------------------------------------------------------
echo "G. SheetContentSettings\n";
check(SheetContentSettings::changedKeys([], ['sds.water_based_threshold_pct' => '20']) === [], 'missing row = default 20 -> no change');
check(SheetContentSettings::changedKeys(['sds.water_based_threshold_pct' => '20'], ['sds.water_based_threshold_pct' => '30']) === ['sds.water_based_threshold_pct'], '20 -> 30 is a sheet-content change');

// ---------------------------------------------------------------------
echo "H. source assertions\n";
$m061 = (string) file_get_contents($basePath . '/migrations/061_sds_text_batch_t3.sql');
foreach (['storage_temp_min_c', 'storage_temp_max_c', 'water_based', 'default_physical_state', 'ph_min', 'ph_max'] as $col) {
    check(str_contains($m061, "AND COLUMN_NAME = '{$col}'"), "061 guards column {$col}");
}
check(str_contains($m061, "'sds.water_based_threshold_pct', '20'"), '061 seeds the threshold');
check(str_contains($m061, "('061_sds_text_batch_t3')"), '061 footer version');
$gen = (string) file_get_contents($basePath . '/src/Services/SDSGenerator.php');
check(substr_count($gen, '$this->applyFamilyProfile($fg, $calcResult);') === 3, 'applyFamilyProfile called in all three paths', substr_count($gen, '$this->applyFamilyProfile($fg, $calcResult);'));
// Each call precedes the physical-state write-back.
check(substr_count($gen, "\$this->applyFamilyProfile(\$fg, \$calcResult); // A11: family physical-state note + water-based rule\n        \$fg['physical_state'] = self::resolvePhysicalState(\$fg, \$calcResult);") === 3, 'each call sits directly before the write-back');
$admin = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(str_contains($admin, "'SDS profile' => \$profileChanged"), 'controller bumps on a profile change');
check(str_contains($admin, 'WaterBasedRule::thresholdInputError'), 'controller validates the threshold');
// Verification pin: DB DECIMAL strings and posted ints give the same profile, so re-saving
// an unchanged family never reads as an "SDS profile changed" bump.
check(PF::profile(['storage_temp_min_c' => '5.0', 'storage_temp_max_c' => '30.0', 'ph_min' => '8.00', 'ph_max' => '9.50'])
    === PF::profile(['storage_temp_min_c' => 5, 'storage_temp_max_c' => 30, 'ph_min' => 8, 'ph_max' => 9.5]),
    'profile(): DB decimal strings == ints (no spurious profile-change bump)');

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
