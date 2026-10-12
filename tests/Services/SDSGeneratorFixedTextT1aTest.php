<?php
/**
 * DB-free checks for fixed-text batch T1a (owner approval 2026-10-12):
 *
 *   A1  Section 5 names the element-derived combustion products (nitrogen
 *       oxides, sulfur oxides, hydrogen halides) from the same cas_master
 *       has_* flags and section10.decomp_* items Section 10 uses; carbon
 *       oxides always; "gases" (no "fumes"); oxidizer / organic-peroxide lead
 *       sentence followed by the same combustion sentence.
 *   A2  Section 6 aquatic sentences are the Section 2 H-statement text in
 *       the sheet language (French H410-H412 now official "néfastes" text).
 *   A3  Section 4 notes: P308+P313 for H340-H373, P308+P311 (superseding)
 *       for H370/H371, printed before the default closing sentence.
 *
 * Same Reflection bootstrap as SDSGeneratorSection5Test.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/SDSGeneratorFixedTextT1aTest.php
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

$langs = ['en', 'es', 'fr', 'de'];

/** @var array<string, array{0: \SDS\Services\TranslationService, 1: \SDS\Services\SDSGenerator}> $cache */
$cache = [];
$mk = static function (string $lang) use (&$cache): array {
    if (!isset($cache[$lang])) {
        $t = new \SDS\Services\TranslationService($lang);
        $cache[$lang] = [$t, new \SDS\Services\SDSGenerator($t)];
    }
    return $cache[$lang];
};
$tl = static fn (string $lang, string $key, array $r = []): string => $mk($lang)[0]->get($key, $r);

$call = static function (string $lang, string $method, array $args) use ($mk) {
    [, $gen] = $mk($lang);
    $m = new ReflectionMethod($gen, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($gen, $args);
};

$hz = static fn (array $codes, string $lang = 'en'): array => \SDS\Services\GHSStatements::translateHazardResult([
    'signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => array_map(static fn ($c) => ['code' => $c, 'text' => \SDS\Services\GHSStatements::hText($c)], $codes),
], $lang);

$calc = static fn (array $composition, ?float $fp = null): array => [
    'formula'       => ['lines' => []],
    'formula_props' => ['flash_point_c' => $fp, 'flash_point_greater_than' => false, 'enriched_lines' => []],
    'composition'   => $composition,
    'warnings'      => [],
];

$row = static fn (array $flags = []): array => array_merge([
    'cas_number' => '147-14-8', 'chemical_name' => 'Pigment Blue 15:3',
    'has_nitrogen' => false, 'has_sulfur' => false, 'has_halogen' => false,
], $flags);

$s5 = static fn (string $lang, array $codes, array $comp, array $ov = [], ?float $fp = null): string
    => $call($lang, 'section5', [$calc($comp, $fp), $hz($codes, $lang), $ov])['specific_hazards'];
$s10 = static fn (string $lang, array $comp): string
    => $call($lang, 'section10', [$hz([], $lang), [], false, $comp])['decomposition'];
$env = static fn (string $lang, array $codes, ?array $hazard = null): string
    => $call($lang, 'section6', [$hazard ?? $hz($codes, $lang), ['physical_state' => 'Liquid'], []])['environmental'];
$s4 = static fn (array $codes, string $lang = 'en', array $ov = []): array
    => $call($lang, 'section4', [$hz($codes, $lang), $ov]);
$notes = static fn (array $codes, string $lang = 'en', array $ov = []): string => $s4($codes, $lang, $ov)['notes'];

$N = $row(['has_nitrogen' => true]);
$S = $row(['cas_number' => '5281-04-9', 'chemical_name' => 'Pigment Red 57:1', 'has_sulfur' => true]);
$X = $row(['cas_number' => '1328-53-6', 'chemical_name' => 'Pigment Green 7', 'has_halogen' => true]);

// =====================================================================
echo "A1. Section 5 combustion products\n";
$enDefault = 'Combustion may produce carbon monoxide, carbon dioxide, and other toxic gases.';
$enN       = 'Combustion may produce carbon monoxide, carbon dioxide, nitrogen oxides, and other toxic gases.';
$enS       = 'Combustion may produce carbon monoxide, carbon dioxide, sulfur oxides, and other toxic gases.';
$enNSX     = 'Combustion may produce carbon monoxide, carbon dioxide, nitrogen oxides, sulfur oxides, hydrogen halides, and other toxic gases.';

check($s5('en', [], []) === $enDefault, 'EN no flags -> default sentence', $s5('en', [], []));
check($s5('en', [], [$N]) === $enN, 'EN nitrogen -> nitrogen oxides', $s5('en', [], [$N]));
$nsx = $s5('en', [], [$row(['has_nitrogen' => true, 'has_sulfur' => true, 'has_halogen' => true])]);
check($nsx === $enNSX, 'EN N+S+X -> all three products', $nsx);
check($s5('en', [], [$X, $S, $N]) === $enNSX, 'EN product order independent of row order (X,S,N rows)', $s5('en', [], [$X, $S, $N]));
$ns1 = $s5('en', [], [$S, $N]);
$ns2 = $s5('en', [], [$row(['has_nitrogen' => true, 'has_sulfur' => true])]);
check($ns1 === $ns2, 'EN [S row, N row] same as single N+S row', [$ns1, $ns2]);

check($s5('en', [], [$row(['has_nitrogen' => 1])]) === $enN, 'flag value 1 counts');
check($s5('en', [], [$row(['has_nitrogen' => '1'])]) === $enN, "flag value '1' counts");
check($s5('en', [], [$row(['has_nitrogen' => 0, 'has_sulfur' => '0', 'has_halogen' => false])]) === $enDefault, "flag values 0 / '0' / false ignored");
check($s5('en', [], [['cas_number' => 'TRADE_SECRET', 'chemical_name' => 'Trade secret', 'concentration' => 5.0]]) === $enDefault, 'TRADE_SECRET bucket without flags -> default');

$sets = [
    'N'   => [['has_nitrogen' => true]],
    'S'   => [['has_sulfur' => true]],
    'X'   => [['has_halogen' => true]],
    'NS'  => [['has_nitrogen' => true], ['has_sulfur' => true]],
    'NSX' => [['has_nitrogen' => true, 'has_sulfur' => true, 'has_halogen' => true]],
];
$elemOf = ['has_nitrogen' => 'nitrogen', 'has_sulfur' => 'sulfur', 'has_halogen' => 'halogen'];
foreach ($langs as $lang) {
    foreach ($sets as $label => $flagRows) {
        $comp = array_map($row, $flagRows);
        $in = [];
        foreach ($flagRows as $fr) {
            foreach ($fr as $f => $v) {
                $in[$elemOf[$f]] = true;
            }
        }
        $a = $s5($lang, [], $comp);
        $b = $s10($lang, $comp);
        $ok = true;
        foreach ($elemOf as $e) {
            $item = $tl($lang, 'section10.decomp_' . $e);
            $want = isset($in[$e]);
            if ((mb_strpos($a, $item) !== false) !== $want || (mb_strpos($b, $item) !== false) !== $want) {
                $ok = false;
            }
        }
        check($ok, "{$lang} {$label}: Section 5 and Section 10 name exactly the same products", [$a, $b]);
    }
    $d = $s5($lang, [], []);
    $noFumes = true;
    foreach (['fumes', 'fumées', 'humos'] as $w) {
        if (mb_stripos($d, $w) !== false) {
            $noFumes = false;
        }
    }
    check($noFumes, "{$lang}: default combustion sentence says gases, not fumes", $d);
    foreach (['specific_hazards_oxidizer', 'specific_hazards_organic_peroxide'] as $k) {
        $v = $tl($lang, 'section5.' . $k);
        check(mb_stripos($v, 'fum') === false && mb_stripos($v, 'humo') === false, "{$lang}: section5.{$k} is the lead sentence only", $v);
    }
    $tr = require $basePath . '/templates/translations/' . $lang . '.php';
    check(str_contains((string) ($tr['section5']['combustion_products'] ?? ''), ':products'), "{$lang}: section5.combustion_products has :products");
}

$r = $s5('en', ['H272'], [$N]);
check($r === $tl('en', 'section5.specific_hazards_oxidizer') . ' ' . $enN, 'H272 + N: oxidizer lead + N sentence', $r);
check(substr_count($r, 'Combustion') === 1, 'H272 + N: one combustion sentence', $r);
$r = $s5('en', ['H242'], [$S]);
check($r === $tl('en', 'section5.specific_hazards_organic_peroxide') . ' ' . $enS, 'H242 + S: peroxide lead + S sentence', $r);
$r = $s5('en', ['H226'], [$N], [], 38.0);
check(
    str_starts_with($r, $tl('en', 'section5.specific_hazards_flammable_cat3')) && str_contains($r, 'Flash point: ') && str_ends_with($r, $enN),
    'H226 fp 38 + N: cat3, flash point line, then N sentence',
    $r
);
$r = $s5('en', ['H261'], []);
check($r === $tl('en', 'section5.specific_hazards_water_reactive') . ' ' . $tl('en', 'section5.specific_hazards'), 'H261: water-reactive + default combustion', $r);
check($s5('en', [], [$N], [5 => ['specific_hazards' => 'Custom.']]) === 'Custom.', 'override wins over element sentence');

$fr = $s5('fr', [], [$S, $N, $X]);
check(
    $fr === "La combustion peut produire du monoxyde de carbone, du dioxyde de carbone, des oxydes d'azote, oxydes de soufre, halogénures d'hydrogène et d'autres gaz toxiques.",
    'FR N+S+X literal',
    $fr
);

// =====================================================================
echo "A2. Section 6 aquatic sentences from the Section 2 phrase source\n";
$legacy = [
    'H400' => 'environmental_aquatic_acute', 'H401' => 'environmental_aquatic_acute_toxic', 'H402' => 'environmental_aquatic_harmful',
    'H410' => 'environmental_aquatic_chronic_very', 'H411' => 'environmental_aquatic_chronic',
    'H412' => 'environmental_aquatic_chronic_harmful', 'H413' => 'environmental_aquatic_chronic_may_harm',
];
$sent = static function (string $code, string $lang) use ($hz): string {
    $h = $hz([$code], $lang);
    return rtrim((string) $h['h_statements'][0]['text'], '.') . '.';
};
foreach ($langs as $lang) {
    foreach (array_keys($legacy) as $code) {
        $expected = $sent($code, $lang);
        $got = $env($lang, [$code]);
        check(
            $got === $tl($lang, 'section6.environmental') . ' ' . $expected . ' ' . $tl($lang, 'section6.environmental_notify'),
            "{$lang} {$code}: Section 6 = drains + Section 2 text + notify",
            $got
        );
    }
}
foreach ($legacy as $code => $key) {
    check($sent($code, 'en') === $tl('en', 'section6.' . $key), "EN {$code}: unchanged vs legacy section6.{$key}", $sent($code, 'en'));
}
$frLit = [
    'H410' => 'Très toxique pour les organismes aquatiques, entraîne des effets néfastes à long terme.',
    'H411' => 'Toxique pour les organismes aquatiques, entraîne des effets néfastes à long terme.',
    'H412' => 'Nocif pour les organismes aquatiques, entraîne des effets néfastes à long terme.',
    'H413' => 'Peut être nocif à long terme pour les organismes aquatiques.',
];
foreach ($frLit as $code => $lit) {
    check(str_contains($env('fr', [$code]), $lit), "FR {$code} literal in Section 6", $env('fr', [$code]));
}
$s2fr = $call('fr', 'section2', [$hz(['H412'], 'fr'), []]);
$s2text = (string) ($s2fr['h_statements'][0]['text'] ?? '');
check($s2text !== '' && str_contains($env('fr', ['H412']), rtrim($s2text, '.') . '.'), 'FR H412: Section 2 text appears verbatim in Section 6', [$s2text, $env('fr', ['H412'])]);

$e = $env('en', ['H400', 'H410']);
check($e === $tl('en', 'section6.environmental') . ' ' . $sent('H400', 'en') . ' ' . $sent('H410', 'en') . ' ' . $tl('en', 'section6.environmental_notify'), 'EN H400+H410: acute then chronic', $e);
$e = $env('en', ['H410', 'H411']);
check(str_contains($e, $sent('H410', 'en')) && !str_contains($e, $sent('H411', 'en')), 'EN H410+H411: H410 only', $e);
$e = $env('en', ['H411', 'H413']);
check(str_contains($e, $sent('H411', 'en')) && !str_contains($e, $sent('H413', 'en')), 'EN H411+H413: H411 only', $e);
$e = $env('en', ['H402', 'H411']);
check($e === $tl('en', 'section6.environmental') . ' ' . $sent('H402', 'en') . ' ' . $sent('H411', 'en') . ' ' . $tl('en', 'section6.environmental_notify'), 'EN H402+H411: H402 then H411', $e);

$blank = ['signal_word' => null, 'pictograms' => [], 'hazard_classes' => [], 'p_statements' => [], 'exposure_limits' => [],
    'h_statements' => [['code' => 'H412', 'text' => '']]];
$e = $env('fr', ['H412'], $blank);
check(str_contains($e, \SDS\Services\GHSStatements::hText('H412', 'fr') . '.'), 'FR blank text falls back to hText(fr)', $e);
check($env('en', ['H225']) === $tl('en', 'section6.environmental'), 'no aquatic code -> drains sentence only', $env('en', ['H225']));

// =====================================================================
echo "A3. Section 4 P308+P313 / P308+P311\n";
$adv   = $tl('en', 'section4.exposed_medical_advice');
$pc    = $tl('en', 'section4.exposed_poison_center');
$base  = $tl('en', 'section4.notes');
check($adv === 'If exposed or concerned: Get medical advice/attention.', 'EN exposed_medical_advice literal', $adv);
check($pc === 'If exposed or concerned: Call a POISON CENTER/doctor.', 'EN exposed_poison_center literal', $pc);

foreach (['H340', 'H341', 'H350', 'H351', 'H360', 'H360FD', 'H361', 'H361D', 'H362', 'H372', 'H373'] as $c) {
    check($notes([$c]) === $adv . ' ' . $base, "{$c}: P308+P313 then base", $notes([$c]));
}
foreach (['H370', 'H371'] as $c) {
    check($notes([$c]) === $pc . ' ' . $base, "{$c}: P308+P311 then base", $notes([$c]));
}
$cpc = $tl('en', 'section4.call_poison_center');
check($cpc === 'Call a POISON CENTER/doctor.', 'EN call_poison_center literal (P311)', $cpc);
$n = $notes(['H370', 'H351', 'H373']);
check($n === $adv . ' ' . $cpc . ' ' . $base, 'H370+H351+H373: P308+P313 then P311 (additive)', $n);
check(substr_count($n, 'If exposed or concerned') === 1, 'H370+H351+H373: one "If exposed or concerned" sentence', $n);
foreach ([['H350', 'H370'], ['H371', 'H360FD'], ['H372', 'H371']] as $pair) {
    $n = $notes($pair);
    check($n === $adv . ' ' . $cpc . ' ' . $base, implode('+', $pair) . ': CMR/STOT RE advice kept, then P311', $n);
}
$n = $notes(['H370', 'H371']);
check($n === $pc . ' ' . $base, 'H370+H371 only: P308+P311 alone', $n);
foreach (['H304', 'H317', 'H319', 'H334', 'H335', 'H336', 'H226', 'H400'] as $c) {
    $n = $notes([$c]);
    check(!str_contains($n, $adv) && !str_contains($n, $pc), "{$c}: no P308 sentence", $n);
}
$n = $notes(['H304', 'H314', 'H331', 'H372']);
check(
    $n === $tl('en', 'section4.notes_aspiration') . ' ' . $tl('en', 'section4.notes_corrosive') . ' '
        . $tl('en', 'section4.notes_inhalation_delayed') . ' ' . $adv . ' ' . $base,
    'order: aspiration, corrosive, inhalation_delayed, P308+P313, base',
    $n
);
check($notes(['H351'], 'en', [4 => ['notes' => 'Custom']]) === 'Custom', 'override replaces notes field');
$r = $s4(['H351']);
foreach (['inhalation', 'skin', 'eyes', 'ingestion'] as $route) {
    check($r[$route] === $tl('en', 'section4.' . $route), "H351: {$route} paragraph unchanged", $r[$route]);
}
foreach (['es', 'fr', 'de'] as $lang) {
    $advL = $tl($lang, 'section4.exposed_medical_advice');
    $pcL  = $tl($lang, 'section4.exposed_poison_center');
    check($notes(['H351'], $lang) === $advL . ' ' . $tl($lang, 'section4.notes'), "{$lang} H351: P308+P313 then base", $notes(['H351'], $lang));
    check($notes(['H370'], $lang) === $pcL . ' ' . $tl($lang, 'section4.notes'), "{$lang} H370: P308+P311 then base", $notes(['H370'], $lang));
    $cpcL = $tl($lang, 'section4.call_poison_center');
    check($notes(['H350', 'H370'], $lang) === $advL . ' ' . $cpcL . ' ' . $tl($lang, 'section4.notes'), "{$lang} H350+H370: P308+P313 then P311", $notes(['H350', 'H370'], $lang));
    $p308 = rtrim(\SDS\Services\GHSStatements::pText('P308', $lang), ' :');
    $p313 = rtrim(\SDS\Services\GHSStatements::pText('P313', $lang), ' .');
    $p311 = rtrim(\SDS\Services\GHSStatements::pText('P311', $lang), ' .');
    check(mb_stripos($advL, $p313) !== false, "{$lang}: medical advice wording from P313", [$advL, $p313]);
    check(mb_stripos($pcL, $p311) !== false, "{$lang}: poison center wording from P311", [$pcL, $p311]);
    check(mb_stripos($cpcL, $p311) !== false && mb_stripos($cpcL, $p308) === false, "{$lang}: call_poison_center = P311 without the P308 lead", [$cpcL, $p311]);
    check(mb_stripos($advL, $p308) !== false && mb_stripos($pcL, $p308) !== false, "{$lang}: lead wording from P308", [$advL, $pcL, $p308]);
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
