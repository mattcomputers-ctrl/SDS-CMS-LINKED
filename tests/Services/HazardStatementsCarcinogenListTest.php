<?php
/**
 * DB-free checks for batch E track T2a, findings #21, #22, #39 and #40:
 *
 *   #21 IARC Group 3 ("not classifiable") is not a carcinogen listing;
 *   #22 the carcinogen seed has one row per (CAS, agency) and carries
 *       acetaldehyde and Disperse Blue 1 as IARC Group 2B;
 *   #39 the withdrawn P281 becomes P280 (GHSStatements helpers, the CPD /
 *       trade-secret editor via GHSHelper, HazardEngine::classify());
 *   #40 Section 2 lists the H-statements no classification line carries,
 *       and Section 11 derives the acute-toxicity route from H-codes.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/HazardStatementsCarcinogenListTest.php
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

use SDS\Services\CarcinogenService;
use SDS\Services\GHSHazardClass;
use SDS\Services\GHSHelper;
use SDS\Services\GHSStatements;
use SDS\Services\SDSGenerator;
use SDS\Services\TranslationService;

// ---------------------------------------------------------------------
echo "a. #21 reportable carcinogen listings\n";
check(CarcinogenService::isReportableListing('IARC', 'Group 2B'), 'IARC Group 2B is reported');
check(CarcinogenService::isReportableListing('iarc', ' group 1 '), 'IARC Group 1 (case / spacing insensitive)');
check(CarcinogenService::isReportableListing('NTP', 'Known') && CarcinogenService::isReportableListing('NTP', 'RAHC')
    && CarcinogenService::isReportableListing('NTP', 'Reasonably Anticipated'), 'NTP Known / RAHC / Reasonably Anticipated');
check(CarcinogenService::isReportableListing('OSHA', 'Listed'), 'OSHA Listed');
check(!CarcinogenService::isReportableListing('IARC', 'Group 3') && !CarcinogenService::isReportableListing('IARC', '3'), 'IARC Group 3 is not a carcinogen listing');
check(!CarcinogenService::isReportableListing('ACGIH', 'A3'), 'a value outside the IARC / NTP / OSHA enums is not reported');
$carcSrc = (string) file_get_contents($basePath . '/src/Services/CarcinogenService.php');
check(str_contains($carcSrc, 'self::isReportableListing('), 'analyse() filters registry rows through isReportableListing()');

// ---------------------------------------------------------------------
echo "b. #22 carcinogen seed: one row per (CAS, agency)\n";
$fh = fopen($basePath . '/storage/data/seed/carcinogens.csv', 'r');
fgetcsv($fh);
$seen = [];
$dups = [];
$iarc = [];
while (($row = fgetcsv($fh)) !== false) {
    $cas = trim((string) ($row[0] ?? ''));
    $ag  = strtoupper(trim((string) ($row[2] ?? '')));
    if ($cas === '' || $ag === '') {
        continue;
    }
    $k = $cas . '|' . $ag;
    if (isset($seen[$k])) {
        $dups[] = $k;
    }
    $seen[$k] = true;
    if ($ag === 'IARC') {
        $iarc[$cas] = trim((string) ($row[3] ?? ''));
    }
}
fclose($fh);
check($dups === [], 'no duplicate (CAS, agency) rows', $dups);
check(($iarc['75-07-0'] ?? null) === 'Group 2B', 'acetaldehyde 75-07-0 is IARC Group 2B', $iarc['75-07-0'] ?? null);
check(($iarc['2475-45-8'] ?? null) === 'Group 2B', 'Disperse Blue 1 2475-45-8 is IARC Group 2B', $iarc['2475-45-8'] ?? null);
check(($iarc['7440-02-0'] ?? null) === 'Group 2B' && ($iarc['110-86-1'] ?? null) === 'Group 2B'
    && ($iarc['60-57-1'] ?? null) === 'Group 2A' && ($iarc['7440-48-4'] ?? null) === 'Group 2A',
    'kept rows are the ones the loader stored before (last row won)');
$loader = (string) file_get_contents($basePath . '/scripts/load-seed-data.php');
check(str_contains($loader, '$seenCarc'), 'loader skips a repeated (CAS, agency) row instead of overwriting');

// ---------------------------------------------------------------------
echo "c. #39 withdrawn P281 -> P280\n";
check(GHSStatements::normalisePCode(' p281 ') === 'P280' && GHSStatements::normalisePCode('P201') === 'P201', 'normalisePCode');
$list = GHSStatements::replaceWithdrawnPCodes([
    ['code' => 'P201', 'text' => ''],
    ['code' => 'P281', 'text' => 'Use personal protective equipment as required'],
    ['code' => 'P280', 'text' => ''],
]);
check(array_column($list, 'code') === ['P201', 'P280'], 'list: P281 becomes P280, no duplicate P280', array_column($list, 'code'));
check(($list[1]['text'] ?? null) === GHSStatements::pText('P280'), 'the replacement carries the P280 wording', $list[1] ?? null);
$keyed = GHSStatements::replaceWithdrawnPCodes(['P281' => ['code' => 'P281', 'text' => ''], 'P405' => ['code' => 'P405', 'text' => '']]);
check(array_keys($keyed) === ['P280', 'P405'], 'code-keyed map (HazardEngine shape)', array_keys($keyed));
check(GHSStatements::normalisePCodeList('P201, p281,P280') === 'P201, P280', 'normalisePCodeList (FG override field)');
check(GHSStatements::pText('P281') === '' && !array_key_exists('P281', GHSStatements::allPStatements()), 'P281 wording removed; not offered by the determination form');
foreach (['es', 'fr', 'de'] as $lang) {
    $g = require $basePath . "/templates/translations/ghs_{$lang}.php";
    check(!isset($g['p_statements']['P281']), "ghs_{$lang}.php has no P281");
}
$det = GHSHelper::buildDeterminationJson(['p_codes_manual' => ['P281', 'P201']]);
check($det['p_statements'] === 'P201, P280', 'CPD / trade-secret editor saves a typed P281 as P280', $det['p_statements']);
$engSrc = (string) file_get_contents($basePath . '/src/Services/HazardEngine.php');
check(str_contains($engSrc, 'GHSStatements::replaceWithdrawnPCodes($allPStmts)'), 'classify() maps P281 from every source (PubChem, CPD, trade secret, FG override)');

// ---------------------------------------------------------------------
echo "d. #40 Section 2 H-statements without a classification line\n";
$classes = [[
    'class' => 'Skin Corrosion/Irritation', 'category' => 'Category 2',
    'canonical' => GHSHazardClass::SKIN_CORROSION_IRRITATION, 'category_canonical' => 'Cat 2', 'h_codes' => ['H315'],
]];
$stmts = [
    ['code' => 'H315', 'text' => 'Causes skin irritation'],
    ['code' => 'H302', 'text' => 'Harmful if swallowed'],
    ['code' => 'H303', 'text' => 'May be harmful if swallowed'],
    ['code' => 'H300+H310', 'text' => 'Fatal if swallowed or in contact with skin'],
];
$unc = array_column(GHSStatements::uncoveredHStatements($classes, $stmts), 'code');
check($unc === ['H302', 'H300+H310'], 'H315 is on its class line; H302 and H300+H310 are listed; H303 (Cat 5, not adopted by HazCom) never', $unc);
check(array_column(GHSStatements::uncoveredHStatements([], [['code' => 'H302', 'text' => '']]), 'code') === ['H302'], 'no class lines: every statement is listed');
$pdfSrc = (string) file_get_contents($basePath . '/src/Services/PDFService.php');
$preSrc = (string) file_get_contents($basePath . '/src/Views/sds/preview.php');
check(str_contains($pdfSrc, 'GHSStatements::uncoveredHStatements(') && str_contains($pdfSrc, "label('hazard_statements')"), 'PDF Section 2 prints the list');
check(str_contains($preSrc, 'GHSStatements::uncoveredHStatements(') && str_contains($preSrc, "\$l('hazard_statements')"), 'HTML preview prints the same list');
foreach (['en' => 'Hazard Statements', 'es' => 'Indicaciones de Peligro', 'fr' => 'Mentions de Danger', 'de' => 'Gefahrenhinweise'] as $lang => $want) {
    $tr = require $basePath . "/templates/translations/{$lang}.php";
    check(($tr['labels']['hazard_statements'] ?? null) === $want, "{$lang} labels.hazard_statements", $tr['labels']['hazard_statements'] ?? null);
    check(str_contains((string) ($tr['section11']['acute_route_line_no_category'] ?? ''), ':code'), "{$lang} section11.acute_route_line_no_category");
}

// ---------------------------------------------------------------------
echo "e. #40 Section 11 acute route from H-codes\n";
$t   = new TranslationService('en');
$gen = new SDSGenerator($t);
$bat = new ReflectionMethod($gen, 'buildAcuteToxicity');
$bat->setAccessible(true);
$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];

$hz = $hz0;
$hz['h_statements'] = [['code' => 'H302', 'text' => 'Harmful if swallowed']];
$out = $bat->invoke($gen, $hz);
check(str_starts_with($out, 'Acute toxicity (oral): Category 4 — Harmful if swallowed (H302).'), 'override H302 with no class line -> oral Category 4 (was "Not classified")', $out);

$hz['h_statements'] = [['code' => 'H300', 'text' => 'Fatal if swallowed']];
$out = $bat->invoke($gen, $hz);
check(str_starts_with($out, "Acute toxicity (oral): Fatal if swallowed (H300).\n"), 'H300 alone (Category 1 or 2) prints no category', $out);

$hz = $hz0;
$hz['hazard_classes'] = [['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => GHSHazardClass::ACUTE_TOXICITY_ORAL,
    'category_canonical' => 'Cat 4', 'cas' => '111-76-2', 'concentration_pct' => 30.0, 'h_codes' => ['H302']]];
$hz['h_statements']   = [['code' => 'H301', 'text' => 'Toxic if swallowed'], ['code' => 'H302', 'text' => 'Harmful if swallowed']];
$out = $bat->invoke($gen, $hz);
check(str_starts_with($out, 'Acute toxicity (oral): Category 3 — Toxic if swallowed (H301).'), 'a more severe H-code wins over a class line', $out);

$hz = $hz0;
$hz['h_statements'] = [['code' => 'H302+H332', 'text' => 'Harmful if swallowed or if inhaled']];
$out = $bat->invoke($gen, $hz);
check(str_contains($out, 'Acute toxicity (oral): Category 4 — ') && str_contains($out, 'Acute toxicity (inhalation): Category 4 — Harmful if inhaled (H332).'),
    'each part of a combined code sets its route', $out);

$hz = $hz0;
$hz['h_statements'] = [['code' => 'H303', 'text' => 'May be harmful if swallowed']];
check(str_starts_with($bat->invoke($gen, $hz), 'Acute toxicity (oral): Not classified based on available data.'), 'H303 (Cat 5) still prints not classified');

$tEs   = new TranslationService('es');
$genEs = new SDSGenerator($tEs);
$batEs = new ReflectionMethod($genEs, 'buildAcuteToxicity');
$batEs->setAccessible(true);
$hz = $hz0;
$hz['h_statements'] = [['code' => 'H302', 'text' => 'Nocivo en caso de ingestión']];
check(str_starts_with($batEs->invoke($genEs, $hz), 'Toxicidad aguda (oral): Categoría 4 — '), 'ES: category from the H-code, translated', $batEs->invoke($genEs, $hz));

echo "\n";
echo $failures === 0 ? "PASSED: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
