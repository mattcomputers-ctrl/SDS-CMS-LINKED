#!/usr/bin/env php
<?php
/**
 * SDSGenerator::section1() product-family fallback test (audit #3, DB-free)
 *
 * Exercises the Recommended Use / Restrictions on Use chain through
 * Reflection on the private section1() method:
 *   per-FG text override > finished_goods column > family default for the
 *   SDS language (blank -> en) > translation file (section1.<field>, or
 *   section1.<field>_resale for resale raw-material SDSs).
 * Also checks that the two new resale keys exist in all four language files.
 *
 * Run:
 *   php tests/Services/SDSGeneratorSection1FamilyTest.php
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

$company = ['name' => 'Test Co', 'address' => '1 Main St', 'city' => 'X', 'state' => 'Y', 'zip' => '1', 'phone' => '555', 'emergency_phone' => '911', 'email' => '', 'website' => ''];

$baseFg = [
    'product_code'        => 'P1',
    'description'         => 'Ink',
    'family'              => 'UV Offset',
    'recommended_use'     => null,
    'restrictions_on_use' => null,
    'family_defaults'     => [
        'recommended_use' => ['en' => 'Fam EN', 'es' => 'Fam ES'],
        'restrictions'    => ['en' => 'Res EN'],
    ],
    'family_is_uv'        => true,
];

$section1 = static function (string $lang, array $fg, array $overrides = []) use ($company): array {
    $t   = new \SDS\Services\TranslationService($lang);
    $gen = new \SDS\Services\SDSGenerator($t);
    $m   = new ReflectionMethod($gen, 'section1');
    $m->setAccessible(true);
    return $m->invoke($gen, $fg, $company, $overrides);
};

$tr = static fn(string $lang, string $key): string => (new \SDS\Services\TranslationService($lang))->get('section1.' . $key);

// ---------------------------------------------------------------------
echo "1. en: family defaults used when no override and no column\n";
$s = $section1('en', $baseFg);
check($s['recommended_use'] === 'Fam EN', 'recommended_use = family en text', $s['recommended_use']);
check($s['restrictions'] === 'Res EN', 'restrictions = family en text', $s['restrictions']);
check($s['product_family'] === 'UV Offset', 'product_family prints the family name', $s['product_family']);

// ---------------------------------------------------------------------
echo "2. es: language text, restrictions fall back to en\n";
$s = $section1('es', $baseFg);
check($s['recommended_use'] === 'Fam ES', 'recommended_use = family es text', $s['recommended_use']);
check($s['restrictions'] === 'Res EN', 'restrictions fall back to family en text (no es)', $s['restrictions']);

// ---------------------------------------------------------------------
echo "3. finished_goods column beats family\n";
$fg = $baseFg;
$fg['recommended_use'] = 'Col';
$s = $section1('en', $fg);
check($s['recommended_use'] === 'Col', 'column beats family default', $s['recommended_use']);
check($s['restrictions'] === 'Res EN', 'other field still from family', $s['restrictions']);

// ---------------------------------------------------------------------
echo "4. per-FG text override beats column; blank override falls through\n";
$s = $section1('en', $fg, [1 => ['recommended_use' => 'Ovr']]);
check($s['recommended_use'] === 'Ovr', 'override beats column', $s['recommended_use']);
$s = $section1('en', $fg, [1 => ['recommended_use' => '']]);
check($s['recommended_use'] === 'Col', 'blank override falls through to column', $s['recommended_use']);
$s = $section1('en', $baseFg, [1 => ['recommended_use' => '   ']]);
check($s['recommended_use'] === 'Fam EN', 'whitespace override falls through to family', $s['recommended_use']);

// ---------------------------------------------------------------------
echo "5. no family defaults, no column -> translation file\n";
$plain = $baseFg;
$plain['family_defaults'] = ['recommended_use' => [], 'restrictions' => []];
$s = $section1('en', $plain);
check($s['recommended_use'] === $tr('en', 'recommended_use'), 'recommended_use = translation default (en)', $s['recommended_use']);
check($s['restrictions'] === $tr('en', 'restrictions'), 'restrictions = translation default (en)', $s['restrictions']);
$noKey = $baseFg;
unset($noKey['family_defaults'], $noKey['family_is_uv']);
$s = $section1('fr', $noKey);
check($s['recommended_use'] === $tr('fr', 'recommended_use'), 'missing family_defaults key tolerated (fr)', $s['recommended_use']);

// ---------------------------------------------------------------------
echo "6. resale raw-material SDS\n";
$resale = $plain;
$resale['is_resale'] = true;
foreach (['en', 'de'] as $lang) {
    $s = $section1($lang, $resale);
    check($s['recommended_use'] === $tr($lang, 'recommended_use_resale') && $s['recommended_use'] !== '', "resale recommended_use = section1.recommended_use_resale ({$lang})", $s['recommended_use']);
    check($s['restrictions'] === $tr($lang, 'restrictions_resale') && $s['restrictions'] !== '', "resale restrictions = section1.restrictions_resale ({$lang})", $s['restrictions']);
    check($s['recommended_use'] !== $tr($lang, 'recommended_use'), "resale default differs from the product default ({$lang})", $s['recommended_use']);
}
$resaleFam = $baseFg;
$resaleFam['is_resale'] = true;
$s = $section1('en', $resaleFam);
check($s['recommended_use'] === 'Fam EN', 'resale with family defaults -> family text wins', $s['recommended_use']);
check($s['restrictions'] === 'Res EN', 'resale with family defaults -> family restrictions win', $s['restrictions']);

// ---------------------------------------------------------------------
echo "7. translation completeness (all four files)\n";
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $file = $basePath . '/templates/translations/' . $lang . '.php';
    $arr  = require $file;
    foreach (['recommended_use_resale', 'restrictions_resale'] as $key) {
        $val = $arr['section1'][$key] ?? null;
        check(is_string($val) && trim($val) !== '', "{$lang}: section1.{$key} present and non-empty", $val);
    }
}

// ---------------------------------------------------------------------
echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
