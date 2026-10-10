<?php
/**
 * DB-free checks for trade-secret handling (audit #13, #14, #36(1); owner
 * decision Q4):
 *
 *   1. section3: a CAS-bearing trade-secret row lists its OWN H-codes; only the
 *      TRADE_SECRET bucket carries the declared trade-secret union (#36(1)).
 *   2. section8: trade-secret exposure-limit rows are masked (name + CAS) (#13).
 *   3. section11: carcinogenicity lines and the component block are masked; two
 *      masked findings keep one line each (#13).
 *   4. Section 15 rows (SARA 313 / HAP / SNUR) via maskTradeSecretRow; the
 *      Section 15 Prop 65 copy never carries trade_secret_conflicts (#13, Q4).
 *   5. CarcinogenService::buildComponentTexts keeps duplicate masked CAS lines.
 *   6. Formula::mergeSubEntryExtras: manual_hazard_json and min/max survive the
 *      sub-FG merge (#14).
 *   7. SDSGenerator::tradeSecretDisclosureWarnings (#36(1)).
 *   8. SDSReadinessService::tradeSecretProp65Error (Q4 publish block).
 *   9. Prop65Service::tradeSecretCasSet / tradeSecretConflictEntry (Q4).
 *  10. Translation keys present in en/es/fr/de.
 *
 * Same Reflection bootstrap as SDSGeneratorSections2_11_12Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TradeSecretMaskingTest.php
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

$t   = new \SDS\Services\TranslationService('en');
$gen = new \SDS\Services\SDSGenerator($t);

$call = static function (object|string $o, string $n): ReflectionMethod {
    $m = new ReflectionMethod($o, $n);
    $m->setAccessible(true);
    return $m;
};

// Keeps section3() DB-free.
$inh = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'inhalationOnlyCas');
$inh->setAccessible(true);
$inhSaved = $inh->getValue();
$inh->setValue(null, ['1333-86-4' => 'Carbon Black']);

$hz0 = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'h_statements' => [], 'p_statements' => [], 'exposure_limits' => []];

// ---------------------------------------------------------------------
echo "1. section3 H-code cell of CAS-bearing trade-secret rows (#36(1))\n";
$s3m = $call($gen, 'section3');
$hz = ['hazardous_cas' => ['25068-38-6', 'TRADE_SECRET'], 'exposure_limits' => [], 'hazard_classes' => [
    ['class' => 'Skin Sensitization', 'category' => 'Category 1', 'canonical' => \SDS\Services\GHSHazardClass::SKIN_SENSITIZATION,
     'category_canonical' => 'Cat 1', 'cas' => '25068-38-6', 'concentration_pct' => 20.0, 'h_codes' => ['H317']],
    ['class' => 'Eye Irritation', 'category' => 'Category 2A', 'canonical' => \SDS\Services\GHSHazardClass::EYE_DAMAGE_IRRITATION,
     'category_canonical' => 'Cat 2A', 'cas' => '25068-38-6', 'concentration_pct' => 20.0, 'h_codes' => ['H319']],
    ['class' => 'Acute Toxicity (Oral)', 'category' => 'Category 4', 'canonical' => \SDS\Services\GHSHazardClass::ACUTE_TOXICITY_ORAL,
     'category_canonical' => 'Cat 4', 'cas' => 'TRADE_SECRET', 'concentration_pct' => 100.0, 'source' => 'manual (trade secret)', 'h_codes' => ['H302']],
]];
$comp = [
    ['cas_number' => '25068-38-6', 'chemical_name' => 'Bisphenol A-epichlorohydrin resin', 'concentration_pct' => 20.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary epoxy resin'],
    ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 5.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Trade Secret'],
];
$rows   = $s3m->invoke($gen, $comp, $hz, [])['components'] ?? [];
$byName = [];
foreach ($rows as $r) {
    $byName[$r['chemical_name']] = $r;
}
check(($byName['Proprietary epoxy resin']['h_codes'] ?? null) === ['H317', 'H319'], 'epoxy TS row lists its own H317/H319 (not H302)', $byName['Proprietary epoxy resin'] ?? $rows);
check(($byName['Trade Secret']['h_codes'] ?? null) === ['H302'], 'TRADE_SECRET bucket lists the declared H302', $byName['Trade Secret'] ?? $rows);
check(($byName['Proprietary epoxy resin']['cas_number'] ?? null) === 'TRADE SECRET' && ($byName['Trade Secret']['cas_number'] ?? null) === 'TRADE SECRET', 'both rows mask the CAS', $rows);

$hzNoTs = $hz;
$hzNoTs['hazardous_cas']  = ['25068-38-6'];
$hzNoTs['hazard_classes'] = array_slice($hz['hazard_classes'], 0, 2);
$rows = $s3m->invoke($gen, [$comp[0]], $hzNoTs, [])['components'] ?? [];
check(count($rows) === 1 && ($rows[0]['h_codes'] ?? null) === ['H317', 'H319'], 'without a TRADE_SECRET bucket the epoxy row still has H317/H319 (was blank)', $rows);

// ---------------------------------------------------------------------
echo "2. section8 exposure limits masked (#13)\n";
$s8m = $call($gen, 'section8');
$comp8 = [
    ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'concentration_pct' => 3.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary glycol ether'],
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 10.0, 'is_trade_secret' => false, 'trade_secret_description' => null],
    ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 5.0, 'is_trade_secret' => true, 'trade_secret_description' => 'Trade Secret'],
];
$hz8 = $hz0;
$hz8['exposure_limits'] = [
    ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'concentration_pct' => 3.0, 'limit_type' => 'PEL', 'value' => '50', 'units' => 'ppm', 'notes' => '', 'source' => 'osha'],
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 10.0, 'limit_type' => 'PEL', 'value' => '400', 'units' => 'ppm', 'notes' => '', 'source' => 'osha'],
    ['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade Secret', 'concentration_pct' => 100.0, 'limit_type' => 'TLV', 'value' => '5', 'units' => 'mg/m3', 'notes' => '', 'source' => 'manual (trade secret)'],
];
$s8 = $s8m->invoke($gen, $hz8, $comp8, []);
$el = $s8['exposure_limits'];
check(($el[0]['cas_number'] ?? null) === 'TRADE SECRET' && ($el[0]['chemical_name'] ?? null) === 'Proprietary glycol ether'
    && ($el[0]['concentration_range'] ?? null) === '1 - 5%' && ($el[0]['is_trade_secret'] ?? null) === true, 'TS limit row masked, band kept', $el[0] ?? null);
check(($el[1]['cas_number'] ?? null) === '67-63-0' && ($el[1]['chemical_name'] ?? null) === 'Isopropanol' && !isset($el[1]['is_trade_secret']), 'disclosed row unchanged', $el[1] ?? null);
check(($el[2]['cas_number'] ?? null) === 'TRADE SECRET' && ($el[2]['chemical_name'] ?? null) === 'Trade Secret' && ($el[2]['concentration_range'] ?? null) === '5 - 10%', 'manual-JSON TRADE_SECRET limit masked, banded from the bucket', $el[2] ?? null);
$json = json_encode($s8['exposure_limits']);
check(!str_contains($json, '111-76-2') && !str_contains($json, 'Butoxyethanol') && !str_contains($json, 'TRADE_SECRET'), 'no real identity or sentinel in Section 8', $json);
$genEs = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('es'));
$s8es  = $call($genEs, 'section8')->invoke($genEs, $hz8, $comp8, []);
check(($s8es['exposure_limits'][0]['cas_number'] ?? null) === 'SECRETO COMERCIAL' && ($s8es['exposure_limits'][2]['chemical_name'] ?? null) === 'Secreto comercial', 'ES placeholders', $s8es['exposure_limits']);

// ---------------------------------------------------------------------
echo "3. section11 carcinogenicity + component block masked (#13)\n";
$s11m = $call($gen, 'section11');
$comp11 = [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary aromatic solvent'],
    ['cas_number' => '98-82-8', 'chemical_name' => 'Cumene', 'concentration_pct' => 0.5, 'is_trade_secret' => true, 'trade_secret_description' => 'Proprietary aromatic solvent'],
    ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol', 'concentration_pct' => 10.0],
];
$hz11 = $hz0;
$hz11['exposure_limits'] = [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5, 'limit_type' => 'PEL', 'value' => '100', 'units' => 'ppm', 'notes' => '', 'source' => 'osha'],
];
$carc = ['has_carcinogens' => true, 'findings' => [
    ['cas_number' => '100-41-4', 'chemical_name' => 'Ethylbenzene', 'concentration_pct' => 1.5,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => 'Ethylbenzene: possibly carcinogenic to humans']]],
    ['cas_number' => '98-82-8', 'chemical_name' => 'Cumene', 'concentration_pct' => 0.5,
     'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B', 'description' => 'Cumene: possibly carcinogenic to humans']]],
]];
$s11 = $s11m->invoke($gen, $hz11, $comp11, $carc, []);
check(substr_count($s11['carcinogenicity'], 'Proprietary aromatic solvent (CAS TRADE SECRET') === 2, 'two masked carcinogen lines (no key collision)', $s11['carcinogenicity']);
$json = json_encode([$s11['carcinogenicity'], $s11['component_toxicology'] ?? null]);
check(!str_contains($json, 'Ethylbenzene') && !str_contains($json, 'Cumene') && !str_contains($json, '100-41-4') && !str_contains($json, '98-82-8'), 'no real identity in Section 11', $json);
$allMasked = ($s11['component_toxicology'] ?? []) !== [];
foreach ($s11['component_toxicology'] ?? [] as $ct) {
    if (($ct['cas_number'] ?? null) !== 'TRADE SECRET') {
        $allMasked = false;
    }
    foreach ($ct['carcinogen_listings'] ?? [] as $l) {
        if (($l['description'] ?? null) !== '') {
            $allMasked = false;
        }
    }
}
check($allMasked, 'component block: CAS masked and registry descriptions blanked', $s11['component_toxicology'] ?? null);

// ---------------------------------------------------------------------
echo "4. Section 15 rows (#13, Q4)\n";
$mask = $call($gen, 'maskTradeSecretRow');
$compByCas = [
    '111-76-2' => $comp8[0],
    '67-63-0'  => $comp8[1],
];
$sara = ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'threshold_pct' => 1.0, 'is_pbt' => false,
    'category_code' => 'N230', 'sara_name' => 'Certain glycol ethers', 'concentration_range' => '1 - 5%'];
$m = $mask->invoke($gen, $sara, $compByCas, ['chemical_name', 'sara_name'], ['category_code']);
check($m['sara_name'] === 'Proprietary glycol ether' && $m['chemical_name'] === 'Proprietary glycol ether' && $m['cas_number'] === 'TRADE SECRET'
    && $m['category_code'] === '' && $m['concentration_range'] === '1 - 5%' && $m['threshold_pct'] === 1.0, 'SARA row masked; band + threshold kept', $m);
$hap = ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'hap_name' => 'Glycol ethers', 'concentration_range' => '1 - 5%'];
$m = $mask->invoke($gen, $hap, $compByCas, ['chemical_name', 'hap_name']);
check($m['hap_name'] === 'Proprietary glycol ether' && $m['cas_number'] === 'TRADE SECRET', 'HAP row masked', $m);
$snur = ['cas_number' => '111-76-2', 'chemical_name' => '2-Butoxyethanol', 'rule_citation' => '40 CFR 721.9999', 'description' => 'x'];
$m = $mask->invoke($gen, $snur, $compByCas, ['chemical_name'], ['rule_citation']);
check($m['rule_citation'] === '' && $m['chemical_name'] === 'Proprietary glycol ether', 'SNUR rule citation blanked', $m);
$open = ['cas_number' => '67-63-0', 'chemical_name' => 'Isopropanol'];
check($mask->invoke($gen, $open, $compByCas) === $open, 'disclosed row returned identical');

$gsn = new ReflectionProperty(\SDS\Services\SDSGenerator::class, 'showGhsSectionNote');
$gsn->setAccessible(true);
$gsn->setValue(null, false);
$s15 = $call($gen, 'section15')->invoke($gen, $hz0, [],
    ['requires_warning' => false, 'warning_text' => '', 'listed_chemicals' => [],
     'trade_secret_conflicts' => [['cas_number' => '50-00-0', 'chemical_name' => 'Formaldehyde (gas)', 'raw_materials' => ['PI-22']]]],
    [], ['composition' => []], []);
check(is_array($s15['prop65'] ?? null) && !array_key_exists('trade_secret_conflicts', $s15['prop65']), 'Section 15 Prop 65 copy drops trade_secret_conflicts', $s15['prop65'] ?? null);
$gsn->setValue(null, null);

// ---------------------------------------------------------------------
echo "5. CarcinogenService::buildComponentTexts keeps duplicate masked CAS\n";
$texts = \SDS\Services\CarcinogenService::buildComponentTexts([
    ['cas_number' => 'TRADE SECRET', 'chemical_name' => 'Proprietary A', 'concentration_range' => '1 - 5%', 'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B']]],
    ['cas_number' => 'TRADE SECRET', 'chemical_name' => 'Proprietary B', 'concentration_range' => '0.1 - 1%', 'agencies' => [['agency' => 'IARC', 'classification' => 'Group 2B']]],
], $t);
check(count($texts) === 2, 'two lines kept', $texts);

// ---------------------------------------------------------------------
echo "6. Formula::mergeSubEntryExtras (#14)\n";
$merge = $call(\SDS\Models\Formula::class, 'mergeSubEntryExtras');
$h317 = ['selected_hazards' => '["Skin Sensitization - Category 1"]', 'hazard_classes' => 'Skin Sensitization Category 1 (1A/1B)', 'signal_word' => 'Warning',
    'h_statements' => 'H317', 'p_statements' => 'P261, P272, P280, P302+P352, P333+P313, P321, P363, P501', 'pictograms' => 'GHS07', 'exposure_limits' => '[]', 'basis' => ''];
$h302 = ['selected_hazards' => '["Acute Toxicity (Oral) - Category 4"]', 'hazard_classes' => 'Acute Toxicity (Oral) Category 4', 'signal_word' => 'Warning',
    'h_statements' => 'H302', 'p_statements' => 'P264, P270, P301+P312, P330, P501', 'pictograms' => 'GHS07', 'exposure_limits' => '[]', 'basis' => ''];
$feq = static fn ($a, $b): bool => $a !== null && abs((float) $a - (float) $b) < 1e-9;

$r = $merge->invoke(null, ['cas_number' => 'TRADE_SECRET', 'concentration_pct' => 10.0], ['cas_number' => 'TRADE_SECRET', 'concentration_pct' => 10.0, 'manual_hazard_json' => [$h317]], 0.0);
check(($r['manual_hazard_json'] ?? null) === [$h317] && !array_key_exists('concentration_min', $r), 'a. fresh bucket gets the sub manual_hazard_json; no range', $r);
$r = $merge->invoke(null, ['cas_number' => 'TRADE_SECRET', 'concentration_pct' => 15.0, 'manual_hazard_json' => [$h302]], ['concentration_pct' => 10.0, 'manual_hazard_json' => [$h317]], 5.0);
check(($r['manual_hazard_json'] ?? null) === [$h302, $h317], 'b. appended after existing JSON', $r['manual_hazard_json'] ?? null);
$r = $merge->invoke(null, ['concentration_pct' => 7.5], ['concentration_pct' => 7.5, 'concentration_min' => 5.0, 'concentration_max' => 10.0], 0.0);
check($feq($r['concentration_min'] ?? null, 5.0) && $feq($r['concentration_max'] ?? null, 10.0), 'c. fresh bucket takes the sub range', $r);
$r = $merge->invoke(null, ['concentration_pct' => 9.5], ['concentration_pct' => 7.5, 'concentration_min' => 5.0, 'concentration_max' => 10.0], 2.0);
check($feq($r['concentration_min'] ?? null, 7.0) && $feq($r['concentration_max'] ?? null, 12.0), 'd. exact bucket + ranged sub -> [pct+min, pct+max]', $r);
$r = $merge->invoke(null, ['concentration_pct' => 6.0, 'concentration_min' => 1.0, 'concentration_max' => 3.0], ['concentration_pct' => 4.0], 2.0);
check($feq($r['concentration_min'] ?? null, 5.0) && $feq($r['concentration_max'] ?? null, 7.0), 'e. ranged bucket + exact sub -> [min+pct, max+pct]', $r);
$r = $merge->invoke(null, ['concentration_pct' => 6.0], ['concentration_pct' => 4.0], 2.0);
check(!array_key_exists('concentration_min', $r) && !array_key_exists('concentration_max', $r), 'f. both exact -> no range keys', $r);

// ---------------------------------------------------------------------
echo "7. SDSGenerator::tradeSecretDisclosureWarnings (#36(1))\n";
$warn = $call(\SDS\Services\SDSGenerator::class, 'tradeSecretDisclosureWarnings');
$row = ['cas_number' => '111-76-2', 'is_trade_secret' => true, 'contributing_materials' => [
    ['raw_material_id' => 1, 'internal_code' => 'ADD-7', 'pct_in_rm' => 40.0, 'pct_in_formula' => 3.2, 'is_trade_secret' => true],
    ['raw_material_id' => 2, 'internal_code' => 'SOLV-2', 'pct_in_rm' => 10.0, 'pct_in_formula' => 0.5, 'is_trade_secret' => false],
]];
$w = $warn->invoke(null, [$row]);
check(count($w) === 1 && str_contains($w[0], '111-76-2') && str_contains($w[0], 'ADD-7') && str_contains($w[0], 'SOLV-2')
    && !preg_match('/Trade Secret|TRADE SECRET/', $w[0]), 'one warning naming the CAS and both raw materials', $w);
$allTs = $row;
$allTs['contributing_materials'][1]['is_trade_secret'] = true;
check($warn->invoke(null, [$allTs]) === [], 'all flagged -> no warning');
$legacy = $row;
foreach ($legacy['contributing_materials'] as &$cm) {
    unset($cm['is_trade_secret']);
}
unset($cm);
check($warn->invoke(null, [$legacy]) === [], 'no per-material flags (legacy) -> no warning');
$sentinel = $row;
$sentinel['cas_number'] = 'TRADE_SECRET';
check($warn->invoke(null, [$sentinel]) === [], 'TRADE_SECRET row ignored');
$nonTs = $row;
$nonTs['is_trade_secret'] = false;
check($warn->invoke(null, [$nonTs]) === [], 'non-trade-secret row ignored');

// ---------------------------------------------------------------------
echo "8. SDSReadinessService::tradeSecretProp65Error (Q4)\n";
check(\SDS\Services\SDSReadinessService::tradeSecretProp65Error([]) === null, 'empty -> null');
check(\SDS\Services\SDSReadinessService::tradeSecretProp65Error(['trade_secret_conflicts' => []]) === null, 'no conflicts -> null');
$err = \SDS\Services\SDSReadinessService::tradeSecretProp65Error(['trade_secret_conflicts' => [
    ['cas_number' => '50-00-0', 'chemical_name' => 'Formaldehyde (gas)', 'raw_materials' => ['PI-22']],
]]);
check(is_string($err) && str_starts_with($err, 'Publishing blocked:') && str_contains($err, 'CAS 50-00-0') && str_contains($err, 'PI-22') && str_contains($err, 'Prop 65'), 'conflict -> blocking message', $err);

// ---------------------------------------------------------------------
echo "9. Prop65Service trade-secret helpers (Q4)\n";
$set = \SDS\Services\Prop65Service::tradeSecretCasSet([
    ['cas_number' => '50-00-0', 'is_trade_secret' => true],
    ['cas_number' => '67-63-0', 'is_trade_secret' => false],
    ['cas_number' => 'TRADE_SECRET', 'is_trade_secret' => true],
    ['cas_number' => '', 'is_trade_secret' => true],
]);
check(array_keys($set) === ['50-00-0'], 'only CAS-bearing trade-secret rows', array_keys($set));
$component = ['cas_number' => '50-00-0', 'chemical_name' => 'Formaldehyde', 'is_trade_secret' => true, 'contributing_materials' => [
    ['internal_code' => 'PI-22', 'is_trade_secret' => true],
    ['internal_code' => 'RES-1', 'is_trade_secret' => false],
]];
$e = \SDS\Services\Prop65Service::tradeSecretConflictEntry('50-00-0', 'Formaldehyde (gas)', $component);
check($e === ['cas_number' => '50-00-0', 'chemical_name' => 'Formaldehyde (gas)', 'raw_materials' => ['PI-22']], 'flagged raw materials only', $e);
$noFlags = $component;
$noFlags['contributing_materials'] = [['internal_code' => 'PI-22'], ['internal_code' => 'RES-1']];
$e = \SDS\Services\Prop65Service::tradeSecretConflictEntry('50-00-0', 'Formaldehyde (gas)', $noFlags);
check($e['raw_materials'] === ['PI-22', 'RES-1'], 'no flags -> all contributing raw materials', $e);
$e = \SDS\Services\Prop65Service::tradeSecretConflictEntry('50-00-0', '', $component);
check($e['chemical_name'] === 'Formaldehyde', 'empty list name -> composition name', $e);

// ---------------------------------------------------------------------
echo "10. Translations\n";
$tr = [];
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr[$lang] = require $basePath . '/templates/translations/' . $lang . '.php';
}
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tc     = (string) ($tr[$lang]['section13']['rcra_component_ts_tc'] ?? '');
    $listed = (string) ($tr[$lang]['section13']['rcra_component_ts_listed'] ?? '');
    $snurTs = (string) ($tr[$lang]['section15']['snur_trade_secret'] ?? '');
    check($tc !== '' && $listed !== '' && $snurTs !== '', "{$lang}: three keys defined");
    check(str_contains($tc, ':name') && str_contains($listed, ':name'), "{$lang}: rcra keys carry :name");
    if ($lang !== 'en') {
        check($tc !== $tr['en']['section13']['rcra_component_ts_tc']
            && $listed !== $tr['en']['section13']['rcra_component_ts_listed']
            && $snurTs !== $tr['en']['section15']['snur_trade_secret'], "{$lang}: translated (differs from EN)");
    }
}

$inh->setValue(null, $inhSaved);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
