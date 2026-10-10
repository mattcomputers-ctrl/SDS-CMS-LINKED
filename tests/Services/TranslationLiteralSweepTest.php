<?php
/**
 * DB-free sweep for English display literals in the SDS renderers,
 * generator and regulatory services (audit item #37).
 *
 * SDSs are issued in EN, ES, FR and DE, so every string that can reach a
 * generated SDS (PDF or HTML preview) must come from the translation files
 * via TranslationService. #37 replaced the English sentinels below with
 * translation lookups (labels.not_determined, labels.none, labels.h_codes,
 * labels.trade_secret, section15.prop65_trace_name, ...). This test makes
 * sure none of them creep back in as a display string.
 *
 * Method: each file is tokenised with token_get_all(), which drops comments
 * and docblocks for free. Every string literal (single-quoted, the literal
 * parts of double-quoted / heredoc strings, and inline HTML in the views)
 * that contains a sentinel is classified by its token context:
 *
 *   - flagged (display context): echo / print / <?= / return / array value
 *     (=>) / assignment / ?? / ternary / concatenation / an argument to any
 *     call that is not on the non-display list below, or plain inline HTML;
 *   - ignored (non-display context): an operand of a comparison
 *     (=== !== == != <=>), an argument to a string-test / measurement
 *     function (strcasecmp, str_ends_with, strlen, in_array, preg_match, ...),
 *     or an argument to a translation lookup (->get(), label(), translate()).
 *
 * A short allowlist covers the documented data-layer sentinels that are
 * mapped to translated text before rendering (each entry says where).
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TranslationLiteralSweepTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

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
        echo '       ' . (is_string($actual) ? $actual : var_export($actual, true)) . "\n";
    }
}

// Renderer / generator / service files whose strings can reach an SDS.
$files = [
    'src/Services/SDSGenerator.php',
    'src/Services/HazardEngine.php',
    'src/Services/CarcinogenService.php',
    'src/Services/Prop65Service.php',
    'src/Services/SARA313Service.php',
    'src/Services/HAPService.php',
    'src/Services/RCRAService.php',
    'src/Services/TransportClassifier.php',
    'src/Services/TSCAService.php',
    'src/Services/UVAcrylateRulePack.php',
    'src/Services/FormulaCalcService.php',
    'src/Services/AbbreviationService.php',
    'src/Services/SDSDocumentStrings.php',
    'src/Services/PDFService.php',
    'src/Services/SDSTcpdf.php',
    'src/Views/sds/preview.php',
    'src/Views/sds/preview-pdf.php',
];

/**
 * Sentinels replaced by #37. Each is a case-sensitive regex matched against
 * the literal's text. "None" must be the whole display value (optionally
 * with a trailing period) so that enum tiers ('none'), SQL and words such
 * as "Nonexistent" are not caught; the multi-word phrases match anywhere in
 * the literal.
 */
$sentinels = [
    'Not determined' => '/Not determined/',
    'Not regulated'  => '/Not regulated/',
    'None known'     => '/None known/',
    'Trade Secret'   => '/Trade Secret|TRADE SECRET/',
    'H-Codes'        => '/H-Codes/',
    ' (trace)'       => '/ \(trace\)/',
    'Not applicable' => '/Not applicable/',
    'None'           => '/^\s*None\.?\s*$/',
];

// Calls whose string arguments are compared / measured / looked up, not shown.
$nonDisplayCalls = [
    // comparisons and tests
    'strcasecmp', 'strcmp', 'strncasecmp', 'strncmp', 'str_starts_with', 'str_ends_with',
    'str_contains', 'stripos', 'strpos', 'strrpos', 'stristr', 'strstr', 'in_array',
    'array_search', 'preg_match', 'preg_match_all', 'similar_text', 'levenshtein',
    // measurement
    'strlen', 'mb_strlen',
    // translation lookups (the literal is a key or a fallback-free lookup)
    'get', 'label', 'translate', 'trans', 't', '__',
];

// Documented data-layer sentinels mapped to translated text before display.
//   [file, regex on the trimmed source line, reason]
$allowlist = [
    ['src/Services/UVAcrylateRulePack.php', "/const TRADE_SECRET_NAME = 'Trade Secret';/",
        'language-free placeholder; section4SkinFragment() maps it to labels.trade_secret'],
    ['src/Services/FormulaCalcService.php', "/'chemical_name'\s*=>\s*'Trade Secret',/",
        'synthetic trade-secret composition row; SDSGenerator::tradeSecretName() prints labels.trade_secret'],
    ['src/Services/FormulaCalcService.php', "/'trade_secret_description'\s*=>\s*'Trade Secret',/",
        'synthetic trade-secret composition row; SDSGenerator::tradeSecretName() maps the sentinel to labels.trade_secret'],
];

/** Significant (non-whitespace, non-comment) token index before / after $i. */
function prevSig(array $toks, int $i): ?int
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $toks[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $j;
    }
    return null;
}

function nextSig(array $toks, int $i): ?int
{
    $n = count($toks);
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $toks[$j];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $j;
    }
    return null;
}

function tokText($t): string
{
    return is_array($t) ? $t[1] : $t;
}

/**
 * Name of the call whose argument list encloses token $i, or null when $i is
 * not inside a call's parentheses (array(...) / grouping parens return null).
 */
function enclosingCall(array $toks, int $i): ?string
{
    $depth = 0;
    for ($j = $i - 1; $j >= 0; $j--) {
        $s = tokText($toks[$j]);
        if ($s === ')' || $s === ']') {
            $depth++;
            continue;
        }
        if ($s === '(' || $s === '[' || (is_array($toks[$j]) && in_array($toks[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            if ($depth > 0) {
                $depth--;
                continue;
            }
            if ($s !== '(') {
                return null; // inside [...] (array literal / index)
            }
            $p = prevSig($toks, $j);
            if ($p !== null && is_array($toks[$p]) && in_array($toks[$p][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = $toks[$p][1];
                $pos  = strrpos($name, '\\');
                return strtolower($pos === false ? $name : substr($name, $pos + 1));
            }
            return null;
        }
        if ($s === ';' || $s === '{' || $s === '}') {
            return null;
        }
    }
    return null;
}

/** Literal text of a string token (quotes removed for constant strings). */
function literalText(array $t): string
{
    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
        $q = $t[1][0];
        $inner = substr($t[1], 1, -1);
        return $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }
    return $t[1];
}

/**
 * Classify the string token at $i. Returns [sentinel name => 'flag'|'skip']
 * for each sentinel the literal contains ('flag' = display context).
 */
function classifySentinels(array $toks, int $i, array $sentinels, array $nonDisplayCalls): array
{
    static $comparisonOps = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_SPACESHIP];

    $t = $toks[$i];
    if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
        return [];
    }
    $kind = $t[0];
    $text = literalText($t);

    $found = [];
    foreach ($sentinels as $name => $re) {
        if ($kind === T_INLINE_HTML) {
            // Text nodes of the view markup; multi-word phrases anywhere.
            $matched = $name !== 'None' && preg_match($re, $text) === 1;
            if (preg_match_all('/>([^<]*)</', $text, $m)) {
                foreach ($m[1] as $node) {
                    if (trim($node) !== '' && preg_match($re, $node)) {
                        $matched = true;
                    }
                }
            }
            if ($matched) {
                $found[$name] = 'flag'; // markup is always displayed
            }
            continue;
        }
        if (!preg_match($re, $text)) {
            continue;
        }
        $found[$name] = 'flag';

        $p = prevSig($toks, $i);
        $n = nextSig($toks, $i);
        if ($kind === T_CONSTANT_ENCAPSED_STRING) {
            // Comparison operand.
            if (($p !== null && is_array($toks[$p]) && in_array($toks[$p][0], $comparisonOps, true))
                || ($n !== null && is_array($toks[$n]) && in_array($toks[$n][0], $comparisonOps, true))) {
                $found[$name] = 'skip';
                continue;
            }
            // Array key (followed by =>): a lookup key, not a value.
            if ($n !== null && is_array($toks[$n]) && $toks[$n][0] === T_DOUBLE_ARROW) {
                $found[$name] = 'skip';
                continue;
            }
            // switch case label.
            if ($p !== null && is_array($toks[$p]) && $toks[$p][0] === T_CASE) {
                $found[$name] = 'skip';
                continue;
            }
        }
        // Argument to a test / measurement / translation-lookup call.
        $call = enclosingCall($toks, $i);
        if ($call !== null && in_array($call, $nonDisplayCalls, true)) {
            $found[$name] = 'skip';
        }
    }
    return $found;
}

// ---------------------------------------------------------------------------
echo "\n[1] sweep for #37 sentinel literals in display contexts\n";
// ---------------------------------------------------------------------------
$allowHits = [];
foreach ($files as $rel) {
    $path = $basePath . '/' . $rel;
    if (!is_file($path)) {
        check(false, "{$rel} exists");
        continue;
    }
    $src   = file_get_contents($path);
    $lines = explode("\n", $src);
    $toks  = token_get_all($src);
    $hits  = [];

    foreach ($toks as $i => $t) {
        foreach (classifySentinels($toks, $i, $sentinels, $nonDisplayCalls) as $name => $verdict) {
            if ($verdict !== 'flag') {
                continue;
            }
            $srcLine = trim($lines[$t[2] - 1] ?? '');
            // Documented data-layer sentinels.
            $allowed = false;
            foreach ($allowlist as $k => [$aFile, $aRe, $why]) {
                if ($aFile === $rel && preg_match($aRe, $srcLine)) {
                    $allowed = true;
                    $allowHits[$k] = true;
                }
            }
            if ($allowed) {
                continue;
            }

            $hits[] = sprintf('%s:%d  [%s]  %s', $rel, $t[2], $name, mb_strimwidth($srcLine, 0, 140, '...'));
        }
    }
    check($hits === [], "{$rel}: no English sentinel display literals", $hits === [] ? null : implode("\n       ", $hits));
}

// ---------------------------------------------------------------------------
echo "\n[2] allowlist entries are still needed (no stale exemptions)\n";
// ---------------------------------------------------------------------------
foreach ($allowlist as $k => [$aFile, $aRe, $why]) {
    check(isset($allowHits[$k]), "allowlist {$aFile} {$aRe} still matches a literal ({$why})");
}

// ---------------------------------------------------------------------------
echo "\n[3] self-test: the classifier flags display contexts and skips the rest\n";
// ---------------------------------------------------------------------------
// Runs the same token classification over a fixture so the sweep cannot
// silently pass because it matches nothing.
$fixture = <<<'PHP'
<?php
// return 'None';  (comment: ignored)
/* echo 'Not determined'; */
function a() { return 'None'; }                       // flag
function b() { echo 'Not regulated'; }                // flag
$x = ['other_hazards' => 'None known.'];              // flag
$y = $cond ? 'Not applicable' : '';                   // flag
$pdf->Cell(0, 4, 'H-Codes', 0);                       // flag
$s = $name . ' (trace)';                              // flag
$z = $v ?? 'Trade Secret';                            // flag
if ($d === 'Trade Secret') {}                         // skip: comparison
if (strcasecmp($d, 'Trade Secret') === 0) {}          // skip: test call
if (str_ends_with($n, ' (trace)')) {}                 // skip: test call
$w = "{$name} (trace)";                               // flag: interpolated
$map = ['None' => 'labels.none'];                     // skip: array key
$k = $this->t->get('labels.none');                    // no sentinel
$tier = 'none';                                       // no sentinel: lowercase enum
?>
<td>None</td>
PHP;
$toks = token_get_all($fixture);
$flagged = 0;
$skipped = 0;
foreach ($toks as $i => $t) {
    foreach (classifySentinels($toks, $i, $sentinels, $nonDisplayCalls) as $verdict) {
        $verdict === 'flag' ? $flagged++ : $skipped++;
    }
}
check($flagged === 9, 'fixture: 9 display-context sentinels flagged (incl. interpolated string and inline HTML)', $flagged);
check($skipped === 4, 'fixture: 4 comparison / test-call / array-key sentinels skipped', $skipped);

// ---------------------------------------------------------------------------
echo "\n";
echo $failures === 0
    ? "PASSED: {$checks} checks\n"
    : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
