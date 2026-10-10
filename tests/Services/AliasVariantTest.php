#!/usr/bin/env php
<?php
/**
 * Alias identity + alias sheet selection (audit #53 / #60, owner decision Q9; DB-free)
 *
 *   a-c. SDSGenerator::createAliasVariant() prints the code verbatim and
 *        rebuilds the Section 16 abbreviations against the alias sheet
 *        (the alias description can carry terms the base does not print).
 *   d.   A private-label custom code is never cut at a hyphen (identifier,
 *        meta code, preview inline file name).
 *   e-f. AliasPublisher pure helpers: newest published row per language and
 *        the alias id a publish writes under (pack variants share one sheet).
 *
 * Run:
 *   php tests/Services/AliasVariantTest.php
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
$cfg->setValue(null, ['paths' => [], 'sds' => ['supported_languages' => ['en', 'es', 'fr', 'de'], 'default_language' => 'en']]);

use SDS\Services\AliasPublisher;
use SDS\Services\SDSGenerator;
use SDS\Services\SDSPreviewResponse;

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

$sds = [
    'meta' => ['product_code' => 'BK1080', 'description' => 'Black', 'language' => 'en', 'labels' => []],
    'sections' => [
        1  => ['product_identifier' => 'BK1080 — Black'],
        2  => [
            'signal_word'         => 'Warning',
            'is_classified'       => true,
            'hazard_classes'      => [],
            'h_statements'        => [['code' => 'H317', 'text' => 'May cause an allergic skin reaction.']],
            'p_statements'        => [],
            'ppe_recommendations' => [],
            'other_hazards'       => '',
        ],
        16 => ['abbreviations' => ''],
    ],
    'legal_disclaimer' => '',
];

// ---------------------------------------------------------------------
echo "a. alias variant (en): code verbatim, abbreviations rebuilt\n";
$a = SDSGenerator::createAliasVariant($sds, 'BK1080', 'LED Cure Black');
check($a['sections'][1]['product_identifier'] === 'BK1080 — LED Cure Black', 'identifier = base code — alias description', $a['sections'][1]['product_identifier']);
check($a['meta']['product_code'] === 'BK1080', 'meta.product_code = code passed (footer / PDF Title / file name)', $a['meta']['product_code']);
$abbr = (string) ($a['sections'][16]['abbreviations'] ?? '');
check(str_contains($abbr, 'LED = Light-emitting diode (curing lamp)'), 'alias description term LED is defined in Section 16', $abbr);
check(str_contains($abbr, 'Hxxx = '), 'base-sheet terms (H-codes) still defined', $abbr);
check($sds['sections'][16]['abbreviations'] === '', 'input sheet untouched');

// ---------------------------------------------------------------------
echo "b. alias variant (es): abbreviations in the sheet language\n";
$es = $sds;
$es['meta']['language'] = 'es';
$a = SDSGenerator::createAliasVariant($es, 'BK1080', 'LED Cure Black');
$abbr = (string) ($a['sections'][16]['abbreviations'] ?? '');
check(str_contains($abbr, 'LED = Diodo emisor de luz (lámpara de curado)'), 'LED defined in Spanish', $abbr);

// ---------------------------------------------------------------------
echo "c. fixture without Section 16 stays without one\n";
$no16 = $sds;
unset($no16['sections'][16]);
$a = SDSGenerator::createAliasVariant($no16, 'BK1080', 'LED Cure Black');
check(!isset($a['sections'][16]), 'no Section 16 added');

// ---------------------------------------------------------------------
echo "d. private label custom code keeps its hyphen\n";
$pl = SDSGenerator::createPrivateLabelVariant($sds, 'ABC-123', 'PL Ink', ['name' => 'Acme']);
check($pl['meta']['product_code'] === 'ABC-123', 'meta.product_code = ABC-123', $pl['meta']['product_code']);
check($pl['sections'][1]['product_identifier'] === 'ABC-123 — PL Ink', 'identifier = ABC-123 — PL Ink', $pl['sections'][1]['product_identifier']);
check(SDSPreviewResponse::filename($pl) === 'SDS_ABC-123_draft_en.pdf', 'preview inline name keeps the hyphen', SDSPreviewResponse::filename($pl));

// ---------------------------------------------------------------------
echo "e. AliasPublisher::latestPerLanguage()\n";
$rows = [
    ['id' => 10, 'alias_id' => 3, 'language' => 'en'],
    ['id' => 12, 'alias_id' => 3, 'language' => 'en'],
    ['id' => 11, 'alias_id' => 3, 'language' => 'ES'],
];
$l = AliasPublisher::latestPerLanguage($rows, ['en', 'es', 'fr']);
check(array_keys($l) === ['en', 'es'], 'only languages with a row, in the wanted order', array_keys($l));
check((int) ($l['en']['id'] ?? 0) === 12, 'en -> newest row (highest id)', $l['en']['id'] ?? null);
check((int) ($l['es']['id'] ?? 0) === 11, 'es (stored upper-case) -> row 11', $l['es']['id'] ?? null);
$all = AliasPublisher::latestPerLanguage($rows);
check(isset($all['en'], $all['es']) && count($all) === 2, 'null languages -> every language present', array_keys($all));
check(AliasPublisher::latestPerLanguage([], ['en']) === [], 'no rows -> empty');

// ---------------------------------------------------------------------
echo "f. AliasPublisher::representativeId()\n";
$group = [['id' => 3, 'customer_code' => 'BK1080-2G'], ['id' => 7, 'customer_code' => 'BK1080-5G']];
check(AliasPublisher::representativeId($group, []) === 3, 'nothing published -> first by customer code');
check(AliasPublisher::representativeId($group, [['id' => 20, 'alias_id' => 7], ['id' => 9, 'alias_id' => 3]]) === 7, 'member owning the newest published row wins');

// ---------------------------------------------------------------------
echo "g. AliasPublisher::languages() from config\n";
check(AliasPublisher::languages() === ['en', 'es', 'fr', 'de'], 'configured languages', AliasPublisher::languages());

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
