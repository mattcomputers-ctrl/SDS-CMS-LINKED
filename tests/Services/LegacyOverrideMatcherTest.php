<?php
/**
 * DB-free checks for LegacyOverrideMatcher (audit #1, owner decision Q12):
 * the second override-cleanup pass that recognises blank rows, retired
 * Section 15 OSHA / TSCA rows and OLD automatic text the pre-#36 editor
 * saved (old translations, hard-coded sentences, old lowest-raw flash point,
 * old dot_transport_info values). Also checks the generated data file
 * scripts/data/legacy-override-texts.php.
 *
 * Same bootstrap/check() helper as TransportClassifierTest.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/LegacyOverrideMatcherTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\LegacyOverrideMatcher as M;

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

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

$mt = new M([
    'translations' => [
        'en' => ['This product is classified as hazardous under OSHA HazCom 2012 (29 CFR 1910.1200).',
                 'Based on available data, the classification criteria are not met.',
                 'Stable under recommended storage conditions.', 'Move to fresh air.',
                 'If breathing is difficult, give oxygen.', 'Flash point: :fp.', 'None.', ':list', 'Not determined'],
        'es' => ['Trasladar al aire libre.'],
    ],
    'code' => ['Safety glasses with side shields.', 'Wear protective clothing to prevent skin contact.'],
]);

/** [section, key, lang, text, context, expected, label] */
$cases = [
    [4, 'inhalation', 'en', "  \r\n", [], M::REASON_BLANK, 'blank row'],
    [15, 'osha_status', 'en', 'anything', [], M::REASON_RETIRED_FIELD, '15.osha_status retired (Q12)'],
    [15, 'tsca_status', 'es', 'x', [], M::REASON_RETIRED_FIELD, '15.tsca_status retired (Q12)'],
    [16, 'revision_note', 'en', 'x', [], M::REASON_RETIRED_FIELD, 'never-editable key'],
    [11, 'acute_toxicity', 'en', "Based on available data,\r\nthe classification criteria are not met.", [], M::REASON_LEGACY_TEXT, 'old translation, whitespace-insensitive'],
    [11, 'acute_toxicity', 'en', 'based on available data, the classification criteria are not met.', [], null, 'case-sensitive'],
    [4, 'inhalation', 'en', 'Move to fresh air. If breathing is difficult, give oxygen.', [], M::REASON_LEGACY_COMPOSITE, 'two old sentences joined'],
    [4, 'inhalation', 'en', 'Move to fresh air. Call Dr. Smith at 555-0100.', [], null, 'old sentence + operator text kept'],
    [8, 'eye_protection', 'fr', 'Safety glasses with side shields.', [], M::REASON_LEGACY_TEXT, 'hard-coded English on an FR sheet'],
    [4, 'inhalation', 'es', 'Move to fresh air.', [], M::REASON_LEGACY_TEXT, 'EN fallback on an ES sheet'],
    [4, 'inhalation', 'es', 'Trasladar al aire libre.', [], M::REASON_LEGACY_TEXT, 'ES translation'],
    [5, 'specific_hazards', 'en', 'Flash point: 23 °C (73.4 °F). Stable under recommended storage conditions.', [], M::REASON_LEGACY_COMPOSITE, 'placeholder value containing "." and spaces'],
    [2, 'other_hazards', 'en', 'None.', [], M::REASON_LEGACY_TEXT, 'short exact string'],
    [2, 'other_hazards', 'en', 'None. None.', [], null, 'segments shorter than 8 never compose'],
    [4, 'notes', 'en', 'Anything goes here', [], null, 'a ":list"-only pattern is ignored'],
    [15, 'state_regs', 'en', 'Not determined', [], null, 'operator-only field'],
    [9, 'flash_point', 'en', '12 °C (53.6 °F)', ['raw_flash_points' => [['c' => 12.0, 'gt' => false], ['c' => 100.0, 'gt' => false]]], M::REASON_LEGACY_FLASH, 'old lowest-raw flash point'],
    [9, 'flash_point', 'en', '> 93 °C (199.4 °F)', ['raw_flash_points' => [['c' => 93.0, 'gt' => true]]], M::REASON_LEGACY_FLASH, 'old "greater than" raw flash point'],
    [9, 'flash_point', 'en', '12 °C (53.6 °F)', ['raw_flash_points' => [['c' => 12.0, 'gt' => true]]], null, 'flag mismatch kept'],
    [9, 'flash_point', 'en', '12 °C (53.6 °F)', [], null, 'no raw context kept'],
    [14, 'un_number', 'en', 'UN1170', ['dot' => ['un_number' => ['UN1170']]], M::REASON_LEGACY_DOT, 'old DOT UN number'],
    [14, 'un_number', 'en', 'UN1263', ['dot' => ['un_number' => ['UN1170']]], null, 'different UN number kept'],
    [14, 'hazard_class', 'en', '3', ['dot' => ['un_number' => ['3']]], null, 'DOT match is column-specific'],
    [14, 'hazard_class', 'en', '3', ['dot' => ['hazard_class' => ['3']]], M::REASON_LEGACY_DOT, 'old DOT hazard class'],
];
echo "1. reason()\n";
foreach ($cases as [$s, $k, $lang, $text, $ctx, $want, $label]) {
    $got = $mt->reason($s, $k, $lang, $text, $ctx);
    check($got === $want, "{$s}.{$k} [{$lang}] => " . var_export($want, true) . ": {$label}", $got);
}
check(M::AGE_INDEPENDENT === ['blank', 'retired_field'], 'AGE_INDEPENDENT', M::AGE_INDEPENDENT);

echo "2. Generated data file\n";
$dataFile = $basePath . '/scripts/data/legacy-override-texts.php';
if (!is_file($dataFile)) {
    check(false, 'scripts/data/legacy-override-texts.php exists (build it with scripts/build-legacy-override-texts.php)');
} else {
    $d = require $dataFile;
    check(in_array('This product is classified as hazardous under OSHA HazCom 2012 (29 CFR 1910.1200).', $d['translations']['en'] ?? [], true), 'EN old OSHA 2012 sentence');
    check(in_array('Based on available data, the classification criteria are not met.', $d['translations']['en'] ?? [], true), 'EN old criteria-not-met sentence');
    check(in_array('Este producto está clasificado como peligroso bajo OSHA HazCom 2012.', $d['translations']['es'] ?? [], true), 'ES old OSHA 2012 sentence');
    check(in_array('NIOSH-approved supplied-air respirator or self-contained breathing apparatus (SCBA). Do not use chemical cartridge respirators.', $d['code'] ?? [], true), 'old hard-coded derivePPE sentence');
    check(isset($d['translations']['fr'], $d['translations']['de']), 'FR and DE present');
    check((new M($d))->reason(11, 'acute_toxicity', 'en', 'Based on available data, the classification criteria are not met.') === M::REASON_LEGACY_TEXT, 'real data: old sentence recognised');
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
