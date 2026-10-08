#!/usr/bin/env php
<?php
/**
 * Audit #2 — emergency phone gate (SDSReadinessService) + private label variant.
 * DB-free: only the pure static helpers are exercised.
 * Run: php tests/Services/EmergencyPhoneGateTest.php   (exit 0 = pass)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use SDS\Services\SDSGenerator;
use SDS\Services\SDSReadinessService;

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) {
        $failures[] = $what;
    }
};

// Company value: blank/null/whitespace blocks, anything else passes.
$check(SDSReadinessService::companyEmergencyPhoneError(null) !== null, 'company null must block');
$check(SDSReadinessService::companyEmergencyPhoneError('') !== null, 'company empty must block');
$check(SDSReadinessService::companyEmergencyPhoneError('   ') !== null, 'company whitespace must block');
$check(SDSReadinessService::companyEmergencyPhoneError('CHEMTREC: (800) 424-9300') === null, 'company number must pass');
$msg = (string) SDSReadinessService::companyEmergencyPhoneError('');
$check(str_contains($msg, 'Publishing blocked') && str_contains($msg, '29 CFR 1910.1200 Appendix D') && str_contains($msg, 'Admin > Settings'), 'company message wording');

// Manufacturer: row or toCompanyInfo() shape, blank blocks and names the manufacturer.
$check(SDSReadinessService::manufacturerEmergencyPhoneError(['name' => 'Acme', 'emergency_phone' => '']) !== null, 'mfg blank must block');
$check(SDSReadinessService::manufacturerEmergencyPhoneError(['name' => 'Acme']) !== null, 'mfg missing key must block');
$check(SDSReadinessService::manufacturerEmergencyPhoneError(['name' => 'Acme', 'emergency_phone' => '(800) 555-0199']) === null, 'mfg number must pass');
$mfgMsg = (string) SDSReadinessService::manufacturerEmergencyPhoneError(['name' => 'Acme', 'emergency_phone' => ' ']);
$check(str_contains($mfgMsg, '"Acme"') && str_contains($mfgMsg, '29 CFR 1910.1200 Appendix D') && str_contains($mfgMsg, 'Manufacturers > Acme'), 'mfg message wording');

// Variant: manufacturer number replaces the company number; blank never falls back.
$base = ['meta' => ['product_code' => 'X'], 'sections' => [1 => ['product_identifier' => 'X', 'emergency_phone' => 'CHEMTREC: (800) 424-9300']], 'warnings' => []];
$v1 = SDSGenerator::createManufacturerVariant($base, ['name' => 'Acme', 'emergency_phone' => '(800) 555-0199']);
$check(($v1['sections'][1]['emergency_phone'] ?? '') === '(800) 555-0199' && empty($v1['warnings']), 'variant prints manufacturer number, no warning');
$v2 = SDSGenerator::createManufacturerVariant($base, ['name' => 'Acme', 'emergency_phone' => '']);
$check(($v2['sections'][1]['emergency_phone'] ?? 'unset') === '' && count($v2['warnings'] ?? []) === 1, 'blank manufacturer number -> empty + one warning (no company fallback)');
$check(($base['sections'][1]['emergency_phone'] ?? '') === 'CHEMTREC: (800) 424-9300', 'base data untouched');

if ($failures) {
    echo "FAIL: emergency phone gate\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "PASS: emergency phone gate (" . 13 . " checks)\n";
exit(0);
