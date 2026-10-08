#!/usr/bin/env php
<?php
/**
 * Audit #34 — private label legal disclaimer resolution (no DB).
 *
 * Verifies SDSGenerator::createManufacturerVariant() layers the manufacturer's
 * per-language disclaimer over the base sheet's already-resolved text only when
 * the manufacturer has text for THAT language, and that the Manufacturer model's
 * disclaimer_json encode/decode helpers drop blank languages.
 *
 * Run:
 *   php tests/Services/ManufacturerVariantDisclaimerTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection (same as
// PDFServiceGenerateToFileTest.php).
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, [
    'company' => ['name' => 'Config Placeholder Co'],
    'paths'   => ['generated_pdfs' => $basePath . '/storage/temp'],
]);

use SDS\Models\Manufacturer;
use SDS\Services\SDSGenerator;

$failures = 0;
$assert = function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

$base = [
    'meta'             => ['language' => 'es', 'product_code' => 'X'],
    'sections'         => [1 => []],
    'legal_disclaimer' => 'ADMIN-ES',
];

// 1. Manufacturer text for the sheet's language wins.
$v = SDSGenerator::createManufacturerVariant($base, [
    'name'              => 'M',
    'emergency_phone'   => '(555) 000-0000',
    'legal_disclaimers' => ['es' => 'MFG-ES'],
]);
$assert(($v['legal_disclaimer'] ?? null) === 'MFG-ES', 'manufacturer ES text overrides base on an ES sheet');

// 2. No legal_disclaimers key at all: base text is inherited.
$v = SDSGenerator::createManufacturerVariant($base, [
    'name'            => 'M',
    'emergency_phone' => '(555) 000-0000',
]);
$assert(($v['legal_disclaimer'] ?? null) === 'ADMIN-ES', 'no manufacturer disclaimers: base text inherited');

// 3. Manufacturer text only for a different language: base text is inherited.
$v = SDSGenerator::createManufacturerVariant($base, [
    'name'              => 'M',
    'emergency_phone'   => '(555) 000-0000',
    'legal_disclaimers' => ['en' => 'MFG-EN'],
]);
$assert(($v['legal_disclaimer'] ?? null) === 'ADMIN-ES', 'manufacturer EN-only text does not touch an ES sheet');

// 4. Whitespace-only manufacturer text counts as blank.
$v = SDSGenerator::createManufacturerVariant($base, [
    'name'              => 'M',
    'emergency_phone'   => '(555) 000-0000',
    'legal_disclaimers' => ['es' => '   '],
]);
$assert(($v['legal_disclaimer'] ?? null) === 'ADMIN-ES', 'whitespace-only manufacturer text is treated as blank');

// 5. Model helpers.
$assert(Manufacturer::encodeDisclaimers(['en' => ' ', 'es' => '']) === null, 'encodeDisclaimers: all blank => null');
$assert(Manufacturer::encodeDisclaimers(['en' => ' a ', 'es' => '']) === '{"en":"a"}', 'encodeDisclaimers: trims and drops blanks');
$assert(Manufacturer::decodeDisclaimers('{"en":"a","es":" "}') === ['en' => 'a'], 'decodeDisclaimers: drops blank language');
$assert(Manufacturer::decodeDisclaimers(null) === [], 'decodeDisclaimers: null => []');
$assert(Manufacturer::decodeDisclaimers('not json') === [], 'decodeDisclaimers: invalid JSON => []');
$assert(
    Manufacturer::decodeDisclaimers(Manufacturer::encodeDisclaimers(['fr' => 'Été'])) === ['fr' => 'Été'],
    'encode/decode round-trip keeps unescaped unicode'
);

if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)" . PHP_EOL;
    exit(1);
}
echo 'ManufacturerVariantDisclaimerTest: PASS' . PHP_EOL;
exit(0);
