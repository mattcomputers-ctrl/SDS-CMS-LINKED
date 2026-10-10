<?php
/**
 * DB-free check (finding #51): every string in the translation files prints
 * in the core Helvetica font PDFService uses. TCPDF_FONTS::UTF8ArrToLatin1
 * maps only Latin-1 plus the cp1252 extras ($uni_utf8tolatin); any other
 * character (e.g. '≤', '≥', '→') prints as '?'.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/CoreFontCharsetTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

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

// cp1252 0x80-0x9F code points TCPDF maps to Latin-1 bytes.
$extras = [
    0x20AC, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x017D,
    0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x017E, 0x0178,
];

$bad  = [];
$walk = function ($v, string $path) use (&$walk, &$bad, $extras): void {
    if (is_array($v)) {
        foreach ($v as $k => $x) {
            $walk($x, $path . '.' . $k);
        }
        return;
    }
    if (!is_string($v)) {
        return;
    }
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $o = mb_ord($ch, 'UTF-8');
        if ($o > 0xFF && !in_array($o, $extras, true)) {
            $bad[] = $path . ' U+' . sprintf('%04X', $o);
        }
    }
};

foreach (['en', 'es', 'fr', 'de', 'ghs_es', 'ghs_fr', 'ghs_de'] as $f) {
    $walk(require $basePath . "/templates/translations/{$f}.php", $f);
}
check($bad === [], 'every translation string prints in core Helvetica (Latin-1 + cp1252)', $bad);

// The mapping table the check mirrors: TCPDF's own cp1252 list.
if (class_exists('TCPDF_FONT_DATA')) {
    $tcpdf = array_keys(TCPDF_FONT_DATA::$uni_utf8tolatin);
    sort($tcpdf);
    $mine = $extras;
    sort($mine);
    check($tcpdf === $mine, 'extras list matches TCPDF_FONT_DATA::$uni_utf8tolatin', array_values(array_diff($tcpdf, $mine)));
}

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
