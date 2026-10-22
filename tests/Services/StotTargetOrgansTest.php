<?php
/**
 * DB-free checks for A12(b) / T4a (owner decisions 2026-10-12): STOT target
 * organs + route on cas_master (stot_se_* -> H370/H371, stot_re_* ->
 * H372/H373), resolved by SDS\Services\StotTargetOrgans and filled into the
 * Section 2 statement, the Sections 4/11 symptoms line and the Section 11
 * STOT RE line by SDSGenerator.
 *
 *   [1] parseOrgans / normaliseRoute / columnValues
 *   [2] resolve(): union of organs, mixed routes, unflagged CAS, override /
 *       trade-secret sources, MIXTURE contributors, wrong exposure type
 *   [3] statementText / chronicText, exact strings in EN/ES/FR/DE
 *   [4] translation invariants (organ/route keys, official GHS base text)
 *   [5] generator integration via reflection (real engine shapes)
 *   [6] operator warnings
 *   [7] wiring (source checks)
 *
 * Same Reflection bootstrap as SDSGeneratorSections2_11_12Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/StotTargetOrgansTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\GHSHazardClass;
use SDS\Services\GHSStatements;
use SDS\Services\SDSGenerator;
use SDS\Services\StotTargetOrgans as Stot;
use SDS\Services\TranslationService;

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

// ---------------------------------------------------------------------
echo "[1] parseOrgans / normaliseRoute / columnValues\n";
check(Stot::parseOrgans('kidneys, foo,liver;kidneys') === ['liver', 'kidneys'], 'CSV -> canonical order, unknown dropped, deduped', Stot::parseOrgans('kidneys, foo,liver;kidneys'));
check(Stot::parseOrgans(['LUNGS', '']) === ['lungs'], 'array, case-insensitive');
check(Stot::parseOrgans(null) === [] && Stot::parseOrgans('') === [], 'null / empty -> []');
check(Stot::normaliseRoute('Oral') === 'oral', 'route normalised');
check(Stot::normaliseRoute('x') === null && Stot::normaliseRoute(null) === null && Stot::normaliseRoute(['oral']) === null, 'invalid route -> null');
$cv = Stot::columnValues(['stot_se_route' => 'oral']);
check($cv['stot_se_organs'] === null && $cv['stot_se_route'] === null, 'route without organs dropped', $cv);
$cv = Stot::columnValues(['stot_re_organs' => ['lungs', 'liver'], 'stot_re_route' => 'inhalation']);
check($cv['stot_re_organs'] === 'lungs,liver' && $cv['stot_re_route'] === 'inhalation', 'POST array -> CSV + route', $cv);
check($cv['stot_se_organs'] === null && $cv['stot_se_route'] === null, 'other exposure type untouched (null)', $cv);
check(array_keys($cv) === ['stot_se_organs', 'stot_se_route', 'stot_se_not_specified', 'stot_re_organs', 'stot_re_route', 'stot_re_not_specified'], 'six columns in fixed order (067 "not specified" marks)');
$nsv = Stot::columnValues(['stot_re_not_specified' => '1', 'stot_se_not_specified' => true]);
check($nsv['stot_re_not_specified'] === 1 && $nsv['stot_se_not_specified'] === 1 && $nsv['stot_re_organs'] === null, '"not specified" from POST ("1") or JSON (true)', $nsv);
$nsv = Stot::columnValues(['stot_re_organs' => ['lungs'], 'stot_re_not_specified' => 1]);
check($nsv['stot_re_not_specified'] === 0 && $nsv['stot_re_organs'] === 'lungs', '... dropped once organs are ticked (organs win)', $nsv);
check(Stot::columnValues(['stot_se_not_specified' => '0'])['stot_se_not_specified'] === 0 && Stot::columnValues(['stot_se_not_specified' => false])['stot_se_not_specified'] === 0, '... "0" / false are not a mark');
check(Stot::columnValues(['stot_re_organs' => 'liver,lungs', 'stot_re_route' => 'inhalation']) === $cv, 'DB row and POST give the same values (change detection)');

// ---------------------------------------------------------------------
echo "[2] resolve()\n";
$flags = [
    '14808-60-7' => Stot::flagsFromRow(['stot_re_organs' => 'lungs', 'stot_re_route' => 'inhalation']),
    '107-21-1'   => Stot::flagsFromRow(['stot_re_organs' => 'kidneys', 'stot_re_route' => 'oral']),
    '67-56-1'    => Stot::flagsFromRow(['stot_se_organs' => 'central_nervous_system,eyes', 'stot_se_route' => 'all']),
    '50-00-0'    => Stot::flagsFromRow(['stot_re_organs' => 'lungs', 'stot_re_route' => 'inhalation']),
];
$re1 = static fn(string $cas): array => ['class' => 'STOT — Repeated Exposure', 'category' => 'Category 1', 'canonical' => GHSHazardClass::STOT_REPEATED, 'category_canonical' => 'Cat 1', 'cas' => $cas];
$re2 = static fn(string $cas): array => ['class' => 'STOT — Repeated Exposure', 'category' => 'Category 2', 'canonical' => GHSHazardClass::STOT_REPEATED, 'category_canonical' => 'Cat 2', 'cas' => $cas];
$se1 = static fn(string $cas): array => ['class' => 'STOT — Single Exposure', 'category' => 'Category 1', 'canonical' => GHSHazardClass::STOT_SINGLE, 'category_canonical' => 'Cat 1', 'cas' => $cas];

// (a)
$r = Stot::resolve(['H372'], ['14808-60-7' => ['H372']], [$re1('14808-60-7')], $flags);
check(($r['details']['H372'] ?? null) === ['organs' => ['lungs'], 'route' => 'inhalation', 'cas' => ['14808-60-7']] && $r['incomplete'] === [], '(a) single flagged CAS -> organs + route', $r);
// (b)
$r = Stot::resolve(['H373'], ['107-21-1' => ['H373'], '50-00-0' => ['H373']], [$re2('107-21-1'), $re2('50-00-0')], $flags);
check(($r['details']['H373']['organs'] ?? null) === ['lungs', 'kidneys'] && array_key_exists('route', $r['details']['H373']) && $r['details']['H373']['route'] === null, '(b) union of organs in ORGANS order, mixed routes -> no route', $r);
check(($r['details']['H373']['cas'] ?? null) === ['107-21-1', '50-00-0'], '(b) cas sorted', $r);
// (c)
$r = Stot::resolve(['H373'], ['107-21-1' => ['H373'], '1330-20-7' => ['H373']], [$re2('107-21-1'), $re2('1330-20-7')], $flags);
check($r['details'] === [] && ($r['incomplete']['H373'] ?? null) === ['flagged' => ['107-21-1'], 'unflagged' => ['1330-20-7'], 'not_specified' => [], 'other_source' => false], '(c) unflagged second CAS -> incomplete, generic', $r);
// (d)
$r = Stot::resolve(['H372'], ['14808-60-7' => ['H372']], [$re1('14808-60-7'), $re1('FG_OVERRIDE')], $flags);
check($r['details'] === [] && ($r['incomplete']['H372']['other_source'] ?? null) === true && ($r['incomplete']['H372']['unflagged'] ?? null) === [], '(d) FG override also carries H372 -> incomplete (other source)', $r);
$r = Stot::resolve(['H372'], ['14808-60-7' => ['H372']], [$re1('14808-60-7'), $re1('TRADE_SECRET')], $flags);
check($r['details'] === [] && ($r['incomplete']['H372']['other_source'] ?? null) === true, '(d2) trade-secret entry -> incomplete', $r);
// (e)
$mix = $re1('MIXTURE') + ['contributors' => ['14808-60-7']];
$r = Stot::resolve(['H372'], ['14808-60-7' => ['H372']], [$mix], $flags);
check(($r['details']['H372']['organs'] ?? null) === ['lungs'] && $r['incomplete'] === [], '(e) MIXTURE with real contributors -> filled', $r);
$mixTs = $re1('MIXTURE') + ['contributors' => ['14808-60-7', ['cas' => 'TRADE_SECRET']]];
$r = Stot::resolve(['H372'], ['14808-60-7' => ['H372']], [$mixTs], $flags);
check($r['details'] === [] && ($r['incomplete']['H372']['other_source'] ?? null) === true, '(e2) MIXTURE with pseudo contributor -> incomplete', $r);
// (f)
$r = Stot::resolve(['H370'], ['67-56-1' => ['H370']], [$se1('67-56-1')], $flags);
check(($r['details']['H370'] ?? null) === ['organs' => ['central_nervous_system', 'eyes'], 'route' => null, 'cas' => ['67-56-1']], '(f) SE organs, route all -> no route', $r);
// (g)
$r = Stot::resolve(['H370'], ['14808-60-7' => ['H370']], [$se1('14808-60-7')], $flags);
check($r['details'] === [] && $r['incomplete'] === [], '(g) RE organs only, SE code -> generic, no warning', $r);
// (h)
$r = Stot::resolve(['H315'], ['14808-60-7' => ['H372']], [$re1('14808-60-7')], $flags);
check($r === ['details' => [], 'incomplete' => []], '(h) code not in the final H-statements -> nothing', $r);
// (i)
check(Stot::casCarryingStot(['a' => ['H315'], 'b' => ['H373']]) === ['b'], '(i) casCarryingStot');
// nothing flagged at all
$r = Stot::resolve(['H372'], ['1330-20-7' => ['H372']], [$re1('1330-20-7')], []);
check($r === ['details' => [], 'incomplete' => []], 'no flags -> generic, no warning', $r);
// combined code key "H372+H373"-style split
$r = Stot::resolve(['H315+H372'], ['14808-60-7' => ['H372']], [$re1('14808-60-7')], $flags);
check(isset($r['details']['H372']), 'combined code keys are split', $r);

// ---------------------------------------------------------------------
echo "[3] statementText / chronicText\n";
$tEn = new TranslationService('en');
$tEs = new TranslationService('es');
$tFr = new TranslationService('fr');
$tDe = new TranslationService('de');
$lungInh = ['organs' => ['lungs'], 'route' => 'inhalation'];
$exp = [
    'en' => 'Causes damage to organs (lungs) through prolonged or repeated exposure by inhalation',
    'es' => 'Provoca daños en los órganos (pulmones) tras exposiciones prolongadas o repetidas por inhalación',
    'fr' => 'Risque avéré d\'effets graves pour les organes (poumons) à la suite d\'expositions répétées ou d\'une exposition prolongée par inhalation',
    'de' => 'Schädigt die Organe (Lunge) bei längerer oder wiederholter Exposition durch Einatmen',
];
foreach (['en' => $tEn, 'es' => $tEs, 'fr' => $tFr, 'de' => $tDe] as $lang => $tt) {
    $got = Stot::statementText('H372', $lungInh, $tt);
    check($got === $exp[$lang], "{$lang} H372 lungs + inhalation", $got);
}
$got = Stot::statementText('H373', ['organs' => ['kidneys', 'liver'], 'route' => null], $tEn);
check($got === 'May cause damage to organs (liver, kidneys) through prolonged or repeated exposure', 'EN H373 two organs, no route', $got);
$got = Stot::statementText('h370', ['organs' => ['central_nervous_system', 'eyes'], 'route' => 'oral'], $tEn);
check($got === 'Causes damage to organs (central nervous system, eyes) by ingestion', 'EN H370 oral', $got);
$got = Stot::statementText('H371', ['organs' => ['central_nervous_system']], $tDe);
check($got === 'Kann die Organe schädigen (Zentralnervensystem)', 'DE H371', $got);
$got = Stot::statementText('H370', ['organs' => ['skin'], 'route' => 'all'], $tEn);
check($got === 'Causes damage to organs (skin)', "route 'all' prints no route", $got);
check(Stot::statementText('H372', ['organs' => []], $tEn) === '', 'no organs -> empty');
check(Stot::statementText('H315', $lungInh, $tEn) === '', 'non-STOT code -> empty');
$got = Stot::chronicText('H372', $lungInh, $tEn);
check($got === 'Specific target organ toxicity, repeated exposure (Category 1): causes damage to organs (lungs) through prolonged or repeated exposure by inhalation (H372).', 'EN chronic H372', $got);
$got = Stot::chronicText('H373', ['organs' => ['kidneys'], 'route' => 'oral'], $tDe);
check($got === 'Spezifische Zielorgan-Toxizität bei wiederholter Exposition (Kategorie 2): kann die Organe schädigen (Nieren) bei längerer oder wiederholter Exposition durch Verschlucken (H373).', 'DE chronic H373', $got);
check(Stot::chronicText('H370', $lungInh, $tEn) === '', 'chronic H370 -> empty');

// ---------------------------------------------------------------------
echo "[4] translation invariants\n";
$strip = static fn(string $s): string => str_replace([' (:organs)', ' :route'], '', $s);
foreach (['en', 'es', 'fr', 'de'] as $lang) {
    $tr = require $basePath . "/templates/translations/{$lang}.php";
    $stot = $tr['stot'] ?? [];
    $missing = [];
    foreach (array_keys(Stot::ORGANS) as $k) {
        if (trim((string) ($stot['organ'][$k] ?? '')) === '') {
            $missing[] = 'organ.' . $k;
        }
    }
    foreach (Stot::PRINTED_ROUTES as $k) {
        if (trim((string) ($stot['route'][$k] ?? '')) === '') {
            $missing[] = 'route.' . $k;
        }
    }
    check($missing === [], "{$lang}: every organ / printed route key present", $missing);
    $bad = [];
    foreach (array_keys(Stot::CODE_EXPOSURE) as $code) {
        $k = strtolower($code);
        $official = GHSStatements::hText($code, $lang);
        foreach ([$k, $k . '_route'] as $kk) {
            if ($strip((string) ($stot[$kk] ?? '')) !== $official) {
                $bad[] = $kk;
            }
        }
        if (str_contains((string) ($stot[$k] ?? ''), ':route') || !str_contains((string) ($stot[$k . '_route'] ?? ''), ':route')
            || !str_contains((string) ($stot[$k] ?? ''), ':organs')) {
            $bad[] = $k . ' placeholders';
        }
    }
    check($bad === [], "{$lang}: statements are the official GHS text plus placeholders", $bad);
    $bad = [];
    foreach ([1, 2] as $n) {
        $generic = (string) ($tr['section11']['chronic_stot_re_' . $n] ?? '');
        foreach (['chronic_re_' . $n, 'chronic_re_' . $n . '_route'] as $kk) {
            $val = (string) ($stot[$kk] ?? '');
            if ($val === '' || !str_starts_with($generic, $strip($val))) {
                $bad[] = $kk;
            }
        }
    }
    check($bad === [], "{$lang}: chronic lines extend the generic Section 11 sentence", $bad);
}

// ---------------------------------------------------------------------
echo "[5] generator integration\n";
$method = static function (SDSGenerator $gen, string $name): ReflectionMethod {
    $m = new ReflectionMethod($gen, $name);
    $m->setAccessible(true);
    return $m;
};
$hazard = static fn(string $lang, bool $withDetails): array => [
    'signal_word'     => 'Danger',
    'pictograms'      => ['GHS08'],
    'hazard_classes'  => [['class' => 'STOT — Repeated Exposure', 'category' => 'Category 1', 'canonical' => 'stot_repeated',
                           'category_canonical' => 'Cat 1', 'cas' => '14808-60-7', 'h_codes' => ['H372']]],
    'h_statements'    => [['code' => 'H372', 'text' => GHSStatements::hText('H372', $lang)]],
    'p_statements'    => [],
    'exposure_limits' => [],
] + ($withDetails ? ['stot_details' => ['H372' => ['organs' => ['lungs'], 'route' => 'inhalation', 'cas' => ['14808-60-7']]]] : []);

$genFr = new SDSGenerator($tFr);
$filled = $method($genFr, 'applyStotTargetOrgans')->invoke($genFr, $hazard('fr', true));
check($filled['h_statements'][0]['text'] === $exp['fr'], 'FR applyStotTargetOrgans fills H372', $filled['h_statements'][0]['text']);
$s2 = $method($genFr, 'section2')->invoke($genFr, $filled, []);
check(($s2['h_statements'][0]['text'] ?? null) === $exp['fr'], 'FR Section 2 statement carries organs', $s2['h_statements'] ?? null);
$ceFr = $method($genFr, 'buildChronicEffects')->invoke($genFr, $filled, []);
check(str_contains($ceFr, '(poumons)') && !str_contains($ceFr, 'Voir la Section 2'), 'FR Section 11 chronic line names the organs', $ceFr);

$genEn = new SDSGenerator($tEn);
$filledEn = $method($genEn, 'applyStotTargetOrgans')->invoke($genEn, $hazard('en', true));
$sym = $method($genEn, 'deriveSymptoms')->invoke($genEn, $filledEn['h_statements']);
check($sym === 'Delayed: Causes damage to organs (lungs) through prolonged or repeated exposure by inhalation.', 'EN Sections 4/11 symptoms line', $sym);

$plain = $hazard('en', false);
check($method($genEn, 'applyStotTargetOrgans')->invoke($genEn, $plain) === $plain, 'no stot_details -> unchanged');
$cePlain = $method($genEn, 'buildChronicEffects')->invoke($genEn, $plain, []);
check($cePlain === $tEn->get('section11.chronic_stot_re_1'), 'no stot_details -> generic Section 11 line', $cePlain);
$other = $hazard('en', false) + ['stot_details' => ['H373' => ['organs' => ['kidneys'], 'route' => null, 'cas' => ['107-21-1']]]];
check($method($genEn, 'applyStotTargetOrgans')->invoke($genEn, $other)['h_statements'] === $plain['h_statements'], 'details for another code leave H372 alone');

// ---------------------------------------------------------------------
echo "[6] warnings\n";
$w = Stot::warnings(['stot_incomplete' => ['H373' => ['flagged' => ['107-21-1'], 'unflagged' => ['1330-20-7'], 'other_source' => false]]]);
check(count($w) === 1 && str_contains($w[0], 'H373') && str_contains($w[0], '107-21-1') && str_contains($w[0], '1330-20-7') && str_contains($w[0], 'Publishing is not blocked'), 'one warning naming code and CAS', $w);
check(!str_contains($w[0] ?? '', 'Not determined') && !str_contains($w[0] ?? '', 'Trade Secret'), 'warning wording');
$w = Stot::warnings(['stot_incomplete' => ['H372' => ['flagged' => ['14808-60-7'], 'unflagged' => [], 'other_source' => true]]]);
check(count($w) === 1 && str_contains($w[0], 'override'), 'override-source warning', $w);
check(Stot::warnings([]) === [] && Stot::warnings(['stot_incomplete' => []]) === [], 'no incomplete -> no warning');

// ---------------------------------------------------------------------
echo "[7] wiring\n";
$engine = (string) file_get_contents($basePath . '/src/Services/HazardEngine.php');
$pMap   = strpos($engine, 'buildCasHCodeMap($allHClasses)');
$pRes   = strpos($engine, 'StotTargetOrgans::resolve(');
$pCons  = strpos($engine, 'consolidateHazardClasses($allHClasses)');
check($pMap !== false && $pRes !== false && $pCons !== false && $pMap < $pRes && $pRes < $pCons, 'HazardEngine resolves after the per-CAS map, before consolidation');
check(str_contains($engine, "'stot_details'        => \$stot['details']") && str_contains($engine, "'stot_incomplete'     => \$stot['incomplete']"), 'classify() returns stot_details / stot_incomplete');
$gen = (string) file_get_contents($basePath . '/src/Services/SDSGenerator.php');
$n = preg_match_all('/translateHazardResult\(\$hazardResult, \$language\);\s*\n\s*\$hazardResult = \$this->applyStotTargetOrgans\(\$hazardResult\);/', $gen);
check($n === 2, 'applyStotTargetOrgans directly after both translateHazardResult calls', $n);
check(substr_count($gen, 'StotTargetOrgans::warnings(') === 2, 'warnings wired in generate() and generateFromBase()');
$app = (string) file_get_contents($basePath . '/src/Core/App.php');
$pStot = strpos($app, "'/determinations/stot'");
$pId   = strpos($app, "'/determinations/{id}'");
check($pStot !== false && $pId !== false && $pStot < $pId, '/determinations/stot route precedes /determinations/{id}');
$mig = (string) file_get_contents($basePath . '/migrations/062_sds_fixed_text_t4.sql');
foreach (['stot_se_organs', 'stot_se_route', 'stot_re_organs', 'stot_re_route'] as $col) {
    check(str_contains($mig, "COLUMN_NAME = '{$col}'") && str_contains($mig, "ADD COLUMN `{$col}`"), "062 guards and adds {$col}");
}
check(substr_count($mig, "INSERT IGNORE INTO `schema_migrations`") === 1, '062 has exactly one version row');

echo "\nSTOT tab rework (2026-10-21)\n";
$cv = Stot::columnValues(['stot_se_organs' => [], 'stot_se_route' => 'inhalation']);
check($cv['stot_se_organs'] === null && $cv['stot_se_route'] === null, 'unticking every organ clears the exposure type and drops its route', $cv);
$pBatch = strpos($app, "'/determinations/stot-batch'");
check($pBatch !== false && $pBatch < $pId && str_contains($app, "'AdminController@saveCasStotBatch'"), 'route POST /determinations/stot-batch precedes /determinations/{id}');
$adm = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(substr_count($adm, '\SDS\Services\StotTargetOrgans::writeCasStot($db, ') === 2, 'single and batch saves share StotTargetOrgans::writeCasStot()');
check(!str_contains($adm, 'target organs before setting a'), 'clearing organs while a route is set is no longer refused');
check(str_contains($adm, "\$rmIds  = TSCAService::rawMaterialIdsForCas(\$cas);") && str_contains($adm, 'TSCAService::rawMaterialIdsForCasMany(array_keys($content))'), 'STOT saves bump raw materials whatever way the CAS was typed');
$det = (string) file_get_contents($basePath . '/src/Views/admin/determinations.php');
$stotTab = substr($det, (int) strpos($det, '<!-- Tab: STOT Target Organs'));
check(!str_contains($stotTab, '<select name="stot_') || !str_contains($stotTab, ' multiple'), 'no multi-select lists (Ctrl-click to untick)');
check(str_contains($stotTab, '<input type="checkbox" name="stot_') && str_contains($stotTab, "type=\"checkbox\" value=\"' + esc(k) + '\""), 'organs are tick boxes (new-CAS form and the in-place editor)');
check(str_contains($stotTab, 'id="stotBatchForm"') && str_contains($stotTab, 'action="/determinations/stot-batch"') && str_contains($stotTab, 'JSON.stringify({rows: list.map('), 'edits are saved as one JSON field (one substance or all changed)');
check(str_contains($stotTab, "\$canEditStot   = can_edit('cas_determinations');") && str_contains($stotTab, '<?php if ($canEditStot): ?>'), 'edit controls only for users who can save');

echo "\nOne CAS however it was typed (2026-10-21)\n";
$r = Stot::resolve(['H372'], ["14808-60-7\u{00A0}" => ['H372']], [$re1("14808-60-7\u{00A0}")], ['14808-60-7' => $flags['14808-60-7']]);
check(($r['details']['H372']['organs'] ?? null) === ['lungs'] && ($r['details']['H372']['route'] ?? null) === 'inhalation',
    'a driver CAS typed with a no-break space finds the organs stored under the canonical CAS', $r);
$r = Stot::resolve(['H372'], ["14808\u{2013}60\u{2013}7" => ['H372']], [$re1("14808\u{2013}60\u{2013}7")], ['14808-60-7' => $flags['14808-60-7']]);
check(($r['details']['H372']['organs'] ?? null) === ['lungs'], '... and one typed with en dashes', $r);
check(Stot::preferredRow('67-64-1', "67\u{2013}64\u{2013}1", '67-64-1') && !Stot::preferredRow("67\u{2013}64\u{2013}1", '67-64-1', '67-64-1'),
    'the row stored under the canonical CAS wins over another spelling');
$merged = Stot::mergeAdminRows([
    ['cas_number' => "67\u{2013}64\u{2013}1", 'chemical_name' => 'Acetone', 'stot_se_organs' => null, 'stot_se_route' => null, 'stot_re_organs' => 'liver', 'stot_re_route' => null, 'has_se' => 0, 'has_re' => 1, 'rm_count' => 2],
    ['cas_number' => '67-64-1', 'chemical_name' => '', 'stot_se_organs' => 'central_nervous_system', 'stot_se_route' => 'all', 'stot_re_organs' => null, 'stot_re_route' => null, 'has_se' => 1, 'has_re' => 0, 'rm_count' => 3],
    ['cas_number' => '108-88-3', 'chemical_name' => 'Toluene', 'stot_se_organs' => null, 'stot_se_route' => null, 'stot_re_organs' => null, 'stot_re_route' => null, 'has_se' => 1, 'has_re' => 1, 'rm_count' => 1],
]);
check(count($merged) === 2 && $merged[0]['cas_number'] === '108-88-3' && $merged[1]['cas_number'] === '67-64-1', 'STOT tab: one row per canonical CAS, substances without organs first', array_column($merged, 'cas_number'));
check($merged[1]['has_se'] === 1 && $merged[1]['has_re'] === 1 && $merged[1]['rm_count'] === 5 && $merged[1]['chemical_name'] === 'Acetone'
    && $merged[1]['stot_se_organs'] === 'central_nervous_system' && $merged[1]['stot_re_organs'] === null, '... flags OR-ed, counts added, organs from the canonical row (as the sheet reads them)', $merged[1]);
check(str_contains($adm, '$errors[] = "CAS {$canon} was sent twice; only its first row was saved.";'), 'batch save refuses a second row for the same CAS');
$stotSrc = (string) file_get_contents($basePath . '/src/Services/StotTargetOrgans.php');
check(str_contains($stotSrc, '            $rows = $db->fetchAll(') && str_contains($stotSrc, 'return self::mergeAdminRows($rows);'), 'adminRows() merges its rows per canonical CAS');

echo "\n\"Not specified\" target organs (migration 067, 2026-10-22)\n";
$nsRe = Stot::flagsFromRow(['stot_re_not_specified' => 1]);
check($nsRe['re']['not_specified'] === true && $nsRe['re']['organs'] === [] && $nsRe['se']['not_specified'] === false, 'flagsFromRow carries the mark', $nsRe);
$r = Stot::resolve(['H373'], ['1330-20-7' => ['H373']], [$re2('1330-20-7')], ['1330-20-7' => $nsRe]);
check($r['details'] === [] && $r['incomplete'] === [], 'the only source marked not specified: the generic statement, no warning', $r);
$r = Stot::resolve(['H373'], ['107-21-1' => ['H373'], '1330-20-7' => ['H373']], [$re2('107-21-1'), $re2('1330-20-7')], $flags + ['1330-20-7' => $nsRe]);
check($r['details'] === [] && $r['incomplete'] === [], 'one source with organs, one not specified: the generic statement (organs not known for all), no warning', $r);
$r = Stot::resolve(['H373'], ['107-21-1' => ['H373'], '1330-20-7' => ['H373'], '50-00-0' => ['H373']], [$re2('107-21-1'), $re2('1330-20-7'), $re2('50-00-0')],
    ['107-21-1' => $flags['107-21-1'], '1330-20-7' => $nsRe]);
check(($r['incomplete']['H373']['not_specified'] ?? null) === ['1330-20-7'] && ($r['incomplete']['H373']['unflagged'] ?? null) === ['50-00-0'],
    '... with a third source not reviewed yet: the usual warning, naming the not-specified one too', $r);
$w = Stot::warnings(['stot_incomplete' => $r['incomplete']]);
check(isset($w[0]) && str_contains($w[0], 'CAS 1330-20-7 (organs not specified on the vendor SDS)') && str_contains($w[0], 'CAS 50-00-0 (no target organs entered)'), '... in the warning text', $w);
check(!Stot::needsOrgans(['has_re' => 1, 'stot_re_organs' => null, 'stot_re_not_specified' => 1])
    && Stot::needsOrgans(['has_se' => 1, 'has_re' => 1, 'stot_re_not_specified' => 1]), 'not specified counts as reviewed for that exposure type only (tab filter, badge, dashboard)');
$merged = Stot::mergeAdminRows([
    ['cas_number' => '1330-20-7', 'chemical_name' => 'Xylene', 'stot_se_organs' => null, 'stot_se_route' => null, 'stot_se_not_specified' => 0,
     'stot_re_organs' => null, 'stot_re_route' => null, 'stot_re_not_specified' => 1, 'has_se' => 0, 'has_re' => 1, 'rm_count' => 3],
]);
check(($merged[0]['stot_re_not_specified'] ?? null) === 1 && !Stot::needsOrgans($merged[0]), 'the STOT tab row keeps the mark', $merged);
$mig067 = (string) file_get_contents($basePath . '/migrations/067_stot_not_specified.sql');
foreach (['stot_se_not_specified', 'stot_re_not_specified'] as $col) {
    check(str_contains($mig067, "COLUMN_NAME = '{$col}'") && str_contains($mig067, "ADD COLUMN `{$col}` TINYINT(1) NOT NULL DEFAULT 0"), "067 guards and adds {$col}");
}
check(str_contains($mig067, "INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('067_stot_not_specified');"), '067 records its version');
check(str_contains($stotSrc, "if (!self::hasNotSpecifiedColumns()) {") && str_contains($stotSrc, "(self::hasNotSpecifiedColumns() ? \" OR {\$alias}stot_se_not_specified = 1 OR {\$alias}stot_re_not_specified = 1\" : '')"),
    'before 067 the marks are neither written nor read (organs and routes work as before)');
check(str_contains($stotSrc, "AND (\" . self::sqlHasStot() . \")\"") && str_contains($stotSrc, "WHERE \" . self::sqlHasStot('cm2.') . \"")
    && str_contains($stotSrc, "OR \" . self::sqlHasStot('cm.') . \""),
    'the sheet and the STOT tab read the same rows: organs or a mark (one with only the mark is not dropped from the tab)');
check(str_contains($adm, "\$values['stot_' . \$x . '_not_specified'] = !empty(\$row['stot_' . \$x . '_not_specified']) ? 1 : 0;"), 'the batch save carries the marks');
check(str_contains($stotSrc, "'content' => \$printed(\$old) !== \$printed(\$new)") && str_contains($adm, "if (!\$saved['content']) {")
    && str_contains($adm, "if (\$r['content']) {") && str_contains($adm, 'if ($content !== []) {') && str_contains($adm, 'if ($rmIds !== []) {'),
    'a mark alone prints nothing new: saved and audited, but nothing bumped or queued (single and batch saves)');
$detNs = (string) file_get_contents($basePath . '/src/Views/admin/determinations.php');
check(str_contains($detNs, 'class="stot-ns"') && str_contains($detNs, 'stot_se_not_specified: !!st.seNs') && str_contains($detNs, "function syncNs(scope)"), 'the tab has a Not specified box per exposure type, saved with the organs');
$det2 = (string) file_get_contents($basePath . '/src/Views/admin/determinations.php');
check(str_contains($det2, 'window.sdsPage = window.sdsPage || {') && str_contains($det2, "window.sdsPage.pending.stot = function()") && str_contains($det2, "window.sdsPage.pending.tsca = function()")
    && substr_count($det2, "window.sdsPage.leaveOk('tsca')") === 2 && str_contains($det2, "window.sdsPage.leaveOk('stot')"),
    'STOT and TSCA saves ask about each other\'s unsaved work instead of tripping each other\'s leave-page guard');

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
