<?php
/**
 * DB-free completeness / parity checks for the SDS translation files
 * (audit item #37). SDSs are issued in EN, ES, FR and DE, and every string
 * that reaches a generated SDS comes from templates/translations/{lang}.php,
 * so the four files must stay in lock-step:
 *
 *   [1] identical key sets, recursively (a key missing from ES/FR/DE would
 *       silently fall back to English on a foreign-language sheet);
 *   [2] no empty / non-string leaf values;
 *   [3] every :placeholder in an EN value is present in ES/FR/DE and no
 *       translation introduces a placeholder EN does not have (an unreplaced
 *       ':name' would be printed literally, a dropped one loses data);
 *   [4] every EN value that is a sentence (after stripping :placeholders it
 *       contains a space, is longer than 12 characters and is not an all-caps
 *       code) is actually translated, i.e. ES/FR/DE differ from EN, except for
 *       a short explicit whitelist of values that are intentionally identical;
 *   [5] section16.abbreviation_table is keyed by the abbreviation as printed
 *       in that language (ETA/SGA/EPP in ES, ETA/SGH/EPI in FR, PSA in DE), so
 *       it is exempt from key parity, but its values must be non-empty and an
 *       abbreviation present in both EN and another language must carry a
 *       translated expansion;
 *   [6] ghs_{es,fr,de}.php have identical key sets, no empty values, and cover
 *       every H-, P- and pictogram code GHSStatements knows in English.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TranslationCompletenessTest.php
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
        echo '       actual: ' . (is_string($actual) ? $actual : var_export($actual, true)) . "\n";
    }
}

/**
 * Flatten a nested translation array into "a.b.c" => leaf. An empty array is
 * kept as a leaf (so it is reported as empty rather than vanishing). Segments
 * are joined with " > " when a key itself contains a dot, to keep key names
 * unambiguous in failure output.
 */
function flattenTranslations(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $seg = (string) $k;
        $key = $prefix === '' ? $seg : $prefix . '.' . $seg;
        if (is_array($v) && $v !== []) {
            $out += flattenTranslations($v, $key);
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

/**
 * @return list<string> sorted unique :placeholder names in a value. Case
 * variants the caller supplies side by side (:color / :lc_color, :state /
 * :lc_state — word order and capitalisation differ per language) count as
 * the same placeholder.
 */
function placeholdersOf(string $v): array
{
    preg_match_all('/:([a-z][a-z0-9_]*)/', $v, $m);
    $p = array_values(array_unique(array_map(
        static fn(string $n): string => preg_replace('/^(lc|uc)_/', '', $n),
        $m[1]
    )));
    sort($p);
    return $p;
}

/**
 * "Sentence" per the audit rule: after removing :placeholders it contains a
 * space, is longer than 12 characters and is not an all-caps code/citation
 * (e.g. "VOC (EPA METHOD 24)", "UN 1210").
 */
function isSentence(string $v): bool
{
    $s = trim(preg_replace('/:[a-z][a-z0-9_]*/', '', $v));
    if (mb_strlen($s) <= 12 || !str_contains($s, ' ')) {
        return false;
    }
    if (!preg_match('/\p{Ll}/u', $s)) {
        return false; // no lowercase letter at all: an all-caps code / citation
    }
    return true;
}

function preview(string $v): string
{
    $v = preg_replace('/\s+/', ' ', $v);
    return mb_strlen($v) > 90 ? mb_substr($v, 0, 87) . '...' : $v;
}

$languages = ['en', 'es', 'fr', 'de'];
$others    = ['es', 'fr', 'de'];
$dir       = $basePath . '/templates/translations';

// Localised maps: keyed by the term as printed in that language, so their
// key sets legitimately differ between languages. Checked separately in [5].
$localisedMaps = ['section16.abbreviation_table'];

// Values intentionally identical to EN in a given language even though they
// look like a sentence. Keep this short and justified.
//   key => [languages, reason]
$sameValueWhitelist = [
    'labels.prop65_title' => [['es', 'fr', 'de'], 'proper name of a California statute ("California Proposition 65")'],
    'labels.voc_lb_gal'   => [['es', 'fr', 'de'], 'US unit + EPA method citation ("VOC (lb/gal) (EPA Method 24)")'],
];
// Values that may be identical in every language wherever they occur
// (codes, units, regulatory citations).
$sameValueAnyKey = ['CAS', 'N/A'];
// Key prefixes whose values may stay in English: DOT proper shipping names
// are the 49 CFR 172.101 names, which 49 CFR requires in English (the files
// currently carry the ADR/IMDG equivalents in ES/FR/DE; either is accepted).
$sameValueKeyPrefixes = ['section14.psn_'];

$raw  = [];
$flat = [];
foreach ($languages as $lang) {
    $data = require $dir . '/' . $lang . '.php';
    check(is_array($data), "[{$lang}] {$lang}.php returns an array");
    $raw[$lang] = is_array($data) ? $data : [];

    // Pull the localised maps out before flattening.
    $copy = $raw[$lang];
    foreach ($localisedMaps as $mapKey) {
        [$sec, $sub] = explode('.', $mapKey, 2);
        unset($copy[$sec][$sub]);
    }
    $flat[$lang] = flattenTranslations($copy);
}

// ---------------------------------------------------------------------------
echo "\n[1] identical key sets (recursive) — ES/FR/DE vs EN\n";
// ---------------------------------------------------------------------------
$enKeys = array_keys($flat['en']);
foreach ($others as $lang) {
    $keys    = array_keys($flat[$lang]);
    $missing = array_values(array_diff($enKeys, $keys));
    $extra   = array_values(array_diff($keys, $enKeys));
    check($missing === [], "[{$lang}] no keys missing vs en.php (" . count($keys) . ' leaves)', $missing === [] ? null : implode(', ', $missing));
    check($extra === [], "[{$lang}] no keys absent from en.php", $extra === [] ? null : implode(', ', $extra));
}
// Leaf-vs-branch shape mismatches (a string in one file, an array in another)
// show up above as a missing/extra pair; nothing further to check here.

// ---------------------------------------------------------------------------
echo "\n[2] no empty or non-string values\n";
// ---------------------------------------------------------------------------
foreach ($languages as $lang) {
    $bad = [];
    foreach ($flat[$lang] as $k => $v) {
        if (!is_string($v) || trim($v) === '') {
            $bad[] = $k . '=' . var_export($v, true);
        }
    }
    check($bad === [], "[{$lang}] every leaf is a non-empty string", $bad === [] ? null : implode(', ', $bad));
}

// ---------------------------------------------------------------------------
echo "\n[3] :placeholder parity with EN\n";
// ---------------------------------------------------------------------------
foreach ($others as $lang) {
    $bad = [];
    foreach ($flat['en'] as $k => $enV) {
        $v = $flat[$lang][$k] ?? null;
        if (!is_string($enV) || !is_string($v)) {
            continue;
        }
        $pe = placeholdersOf($enV);
        $pl = placeholdersOf($v);
        if ($pe !== $pl) {
            $bad[] = "{$k} (en: " . (implode(',', $pe) ?: '-') . "; {$lang}: " . (implode(',', $pl) ?: '-') . ')';
        }
    }
    check($bad === [], "[{$lang}] placeholders match en.php", $bad === [] ? null : "\n         " . implode("\n         ", $bad));
}

// ---------------------------------------------------------------------------
echo "\n[4] sentence-valued keys are translated (ES/FR/DE differ from EN)\n";
// ---------------------------------------------------------------------------
$sentenceCount = 0;
foreach ($flat['en'] as $k => $enV) {
    if (is_string($enV) && isSentence($enV)) {
        $sentenceCount++;
    }
}
check($sentenceCount > 100, "en.php has a meaningful number of sentence values ({$sentenceCount})");
foreach ($others as $lang) {
    $bad = [];
    foreach ($flat['en'] as $k => $enV) {
        if (!is_string($enV) || !isSentence($enV)) {
            continue;
        }
        $v = $flat[$lang][$k] ?? null;
        if (!is_string($v) || $v !== $enV) {
            continue;
        }
        if (in_array($enV, $sameValueAnyKey, true)) {
            continue;
        }
        foreach ($sameValueKeyPrefixes as $prefix) {
            if (str_starts_with($k, $prefix)) {
                continue 2;
            }
        }
        if (isset($sameValueWhitelist[$k]) && in_array($lang, $sameValueWhitelist[$k][0], true)) {
            continue;
        }
        $bad[] = "{$k} = \"" . preview($v) . '"';
    }
    check($bad === [], "[{$lang}] no untranslated English sentences", $bad === [] ? null : "\n         " . implode("\n         ", $bad));
}
// The whitelist must not hide a key that no longer exists.
foreach ($sameValueWhitelist as $k => [$langs, $why]) {
    check(array_key_exists($k, $flat['en']), "whitelisted key {$k} still exists in en.php ({$why})");
}

// ---------------------------------------------------------------------------
echo "\n[5] localised maps (section16.abbreviation_table)\n";
// ---------------------------------------------------------------------------
foreach ($localisedMaps as $mapKey) {
    [$sec, $sub] = explode('.', $mapKey, 2);
    $enMap = $raw['en'][$sec][$sub] ?? null;
    check(is_array($enMap) && $enMap !== [], "[en] {$mapKey} is a non-empty map");
    foreach ($languages as $lang) {
        $map = $raw[$lang][$sec][$sub] ?? null;
        if (!is_array($map) || $map === []) {
            check(false, "[{$lang}] {$mapKey} is a non-empty map", $map);
            continue;
        }
        $bad = [];
        foreach ($map as $term => $v) {
            if (!is_string($v) || trim($v) === '' || trim((string) $term) === '') {
                $bad[] = (string) $term . '=' . var_export($v, true);
            }
        }
        check($bad === [], "[{$lang}] {$mapKey}: " . count($map) . ' entries, none empty', $bad === [] ? null : implode(', ', $bad));
        if ($lang === 'en' || !is_array($enMap)) {
            continue;
        }
        $same = [];
        foreach ($map as $term => $v) {
            $enV = $enMap[$term] ?? null;
            if (is_string($enV) && is_string($v) && $enV === $v && isSentence($enV)) {
                $same[] = "{$term} = \"" . preview($v) . '"';
            }
        }
        check($same === [], "[{$lang}] {$mapKey}: shared abbreviations carry a translated expansion", $same === [] ? null : "\n         " . implode("\n         ", $same));
    }
}

// ---------------------------------------------------------------------------
echo "\n[6] GHS phrase files ghs_{es,fr,de}.php\n";
// ---------------------------------------------------------------------------
$ghs = [];
foreach ($others as $lang) {
    $data = require $dir . '/ghs_' . $lang . '.php';
    check(is_array($data), "[{$lang}] ghs_{$lang}.php returns an array");
    $ghs[$lang] = flattenTranslations(is_array($data) ? $data : []);
}
$ghsKeys = array_keys($ghs['es']);
foreach (['fr', 'de'] as $lang) {
    $keys    = array_keys($ghs[$lang]);
    $missing = array_values(array_diff($ghsKeys, $keys));
    $extra   = array_values(array_diff($keys, $ghsKeys));
    check($missing === [], "[{$lang}] ghs_{$lang}.php has every key ghs_es.php has", $missing === [] ? null : implode(', ', $missing));
    check($extra === [], "[{$lang}] ghs_{$lang}.php has no key ghs_es.php lacks", $extra === [] ? null : implode(', ', $extra));
}
foreach ($others as $lang) {
    $bad = [];
    foreach ($ghs[$lang] as $k => $v) {
        if (!is_string($v) || trim($v) === '') {
            $bad[] = $k . '=' . var_export($v, true);
        }
    }
    check($bad === [], "[{$lang}] ghs_{$lang}.php: every leaf is a non-empty string", $bad === [] ? null : implode(', ', $bad));
}
$gs = new ReflectionClass(\SDS\Services\GHSStatements::class);
foreach (['h_statements' => 'H_STATEMENTS', 'p_statements' => 'P_STATEMENTS', 'pictogram_names' => 'PICTOGRAM_NAMES'] as $section => $const) {
    $en = $gs->getConstant($const);
    check(is_array($en) && $en !== [], "GHSStatements::{$const} readable");
    if (!is_array($en)) {
        continue;
    }
    foreach ($others as $lang) {
        $missing = [];
        foreach (array_keys($en) as $code) {
            if (!array_key_exists($section . '.' . $code, $ghs[$lang])) {
                $missing[] = $code;
            }
        }
        check($missing === [], "[{$lang}] ghs_{$lang}.php {$section} covers all " . count($en) . ' EN codes', $missing === [] ? null : implode(', ', $missing));
    }
}

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
