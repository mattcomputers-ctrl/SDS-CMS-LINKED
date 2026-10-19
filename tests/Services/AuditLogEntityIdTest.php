<?php
/**
 * DB-free check that no AuditService::log() call passes a null entity id.
 *
 * AuditService::log() declares `string|int $entityId`. Until 2026-10-19 the
 * three SDS Archive actions (generate, purge, delete_zip) passed null, which
 * throws a TypeError in PHP 8 AFTER the action has done its work: the admin
 * got a blank 500, the success flash never showed and no audit row was ever
 * written, including for the purge that deletes superseded SDS PDFs.
 *
 * Method: every file under src/ is tokenised with token_get_all() and each
 * AuditService::log( call has its arguments split at the call's own nesting
 * level. The second argument (entity id) must be present and must not have
 * a null / true / false literal at its top level (that catches `null`,
 * `$x ?? null` and `$c ? $x : null`; under strict_types a bool is a
 * TypeError too).
 *
 * Also checks that SdsArchiveController::purge() writes its audit row
 * (with the sds_versions ids) before it unlinks any file or clears
 * sds_versions.pdf_path.
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/AuditLogEntityIdTest.php
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

/** Tokens minus whitespace and comments, each as [id|null, text, line]. */
function codeTokens(string $src): array
{
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = [$t[0], $t[1], $t[2]];
        } else {
            $out[] = [null, $t, 0];
        }
    }
    return $out;
}

function isAuditLogCall(array $tok, int $i): bool
{
    $name = $tok[$i][1];
    return in_array($tok[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        && ($name === 'AuditService' || str_ends_with($name, '\\AuditService'))
        && ($tok[$i + 1][0] ?? null) === T_DOUBLE_COLON
        && strtolower($tok[$i + 2][1] ?? '') === 'log'
        && ($tok[$i + 3][1] ?? '') === '(';
}

/**
 * Split the arguments of the call whose '(' is at $open. Returns
 * [list of argument token lists, index of the closing ')'].
 */
function callArgs(array $tok, int $open): array
{
    $args  = [[]];
    $depth = 0;
    for ($i = $open + 1, $n = count($tok); $i < $n; $i++) {
        $text = $tok[$i][1];
        if (in_array($text, ['(', '[', '{'], true) || in_array($tok[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            if ($depth === 0) {
                if ($args[count($args) - 1] === []) {
                    array_pop($args); // trailing comma
                }
                return [$args, $i];
            }
            $depth--;
        } elseif ($text === ',' && $depth === 0) {
            $args[] = [];
            continue;
        }
        $args[count($args) - 1][] = [$tok[$i], $depth];
    }
    return [$args, $n];
}

/** Why the entity-id argument is unsafe, or null when it is fine. */
function badEntityId(?array $arg): ?string
{
    if ($arg === null || $arg === []) {
        return 'missing entity id';
    }
    foreach ($arg as [$t, $depth]) {
        if ($depth === 0 && $t[0] === T_STRING && in_array(strtolower($t[1]), ['null', 'true', 'false'], true)) {
            return "literal {$t[1]} in entity id";
        }
    }
    return null;
}

function argText(?array $arg): string
{
    return $arg === null ? '' : implode(' ', array_map(static fn($p) => $p[0][1], $arg));
}

/** All AuditService::log calls in $src: [line, args, openIdx, closeIdx]. */
function auditCalls(array $tok): array
{
    $calls = [];
    for ($i = 0, $n = count($tok); $i < $n; $i++) {
        if (!isAuditLogCall($tok, $i)) {
            continue;
        }
        [$args, $close] = callArgs($tok, $i + 3);
        $calls[] = ['line' => $tok[$i][2], 'args' => $args, 'start' => $i, 'close' => $close];
    }
    return $calls;
}

// ── Scanner self-test ───────────────────────────────────────────────────
echo "Scanner self-test\n";
$sample = codeTokens(<<<'PHP'
<?php
AuditService::log('a', null, 'x', ['k' => null]);
\SDS\Services\AuditService::log('b', $row['id'] ?? NULL, 'x');
AuditService::log('c', $ok ? $id : null, 'x');
AuditService::log('d', foo(null, [1, 2]), 'x', ['z' => 1]);
AuditService::log('e', (string) $id, 'x');
AuditService::log('f', "id-{$id}", 'x');
AuditService::log('g');
PHP);
$got = [];
foreach (auditCalls($sample) as $c) {
    $got[trim(argText($c['args'][0]), "'")] = badEntityId($c['args'][1] ?? null);
}
check(count($got) === 7, 'finds all 7 sample calls (plain and fully qualified)', $got);
check(($got['a'] ?? '') === 'literal null in entity id', 'flags a bare null id', $got['a'] ?? null);
check(($got['b'] ?? '') === 'literal NULL in entity id', 'flags `$x ?? NULL`', $got['b'] ?? null);
check(($got['c'] ?? '') === 'literal null in entity id', 'flags `$c ? $id : null`', $got['c'] ?? null);
check(array_key_exists('d', $got) && $got['d'] === null, 'ignores null nested inside a call in the id', $got['d'] ?? 'missing');
check(array_key_exists('e', $got) && $got['e'] === null, 'accepts a cast id');
check(array_key_exists('f', $got) && $got['f'] === null, 'accepts an interpolated string id');
check(($got['g'] ?? '') === 'missing entity id', 'flags a missing id', $got['g'] ?? null);

// ── Every AuditService::log call in src/ ────────────────────────────────
echo "\nAuditService::log calls in src/\n";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath . '/src', FilesystemIterator::SKIP_DOTS));
$total   = 0;
$bad     = [];
$archive = [];
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($basePath) + 1));
    foreach (auditCalls(codeTokens((string) file_get_contents($file->getPathname()))) as $c) {
        $total++;
        $why = badEntityId($c['args'][1] ?? null);
        if ($why !== null) {
            $bad[] = "{$rel}:{$c['line']} {$why}: " . argText($c['args'][1] ?? null);
        }
        if ($rel === 'src/Controllers/SdsArchiveController.php') {
            $archive[trim(argText($c['args'][2] ?? null), "'")] = argText($c['args'][1] ?? null);
        }
    }
}
check($total >= 50, "scanned {$total} calls (sanity: at least 50)", $total);
check($bad === [], 'no call passes a null / bool literal or omits the entity id', implode("\n       ", $bad));
check(($archive['generate'] ?? null) === '$zipName', "SDS Archive generate logs the ZIP name", $archive['generate'] ?? null);
check(($archive['delete_zip'] ?? null) === '$file', "SDS Archive delete_zip logs the ZIP name", $archive['delete_zip'] ?? null);
check(($archive['purge'] ?? null) === "'purge'", "SDS Archive purge logs entity id 'purge'", $archive['purge'] ?? null);

// ── Purge audits before it deletes anything ─────────────────────────────
echo "\nSdsArchiveController::purge() audits first\n";
$tok = codeTokens((string) file_get_contents($basePath . '/src/Controllers/SdsArchiveController.php'));
$bodyStart = $bodyEnd = null;
for ($i = 0, $n = count($tok); $i < $n; $i++) {
    if ($tok[$i][0] === T_FUNCTION && ($tok[$i + 1][1] ?? '') === 'purge') {
        for ($j = $i; $tok[$j][1] !== '{'; $j++);
        $bodyStart = $j;
        $depth     = 0;
        for (; $j < $n; $j++) {
            if ($tok[$j][1] === '{' || in_array($tok[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($tok[$j][1] === '}' && --$depth === 0) {
                $bodyEnd = $j;
                break;
            }
        }
        break;
    }
}
check($bodyStart !== null && $bodyEnd !== null, 'found purge() body');

$logAt = $unlinkAt = $updateAt = null;
$logArgs = [];
for ($i = (int) $bodyStart; $i < (int) $bodyEnd; $i++) {
    if ($logAt === null && isAuditLogCall($tok, $i)) {
        $logAt   = $i;
        $logArgs = callArgs($tok, $i + 3)[0];
    }
    if ($unlinkAt === null && $tok[$i][0] === T_STRING && $tok[$i][1] === 'unlink' && ($tok[$i + 1][1] ?? '') === '(') {
        $unlinkAt = $i;
    }
    if ($updateAt === null && in_array($tok[$i][0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
        && stripos($tok[$i][1], 'UPDATE sds_versions') !== false) {
        $updateAt = $i;
    }
}
check($logAt !== null, 'purge() calls AuditService::log');
check($unlinkAt !== null, 'purge() unlinks files');
check($updateAt !== null, 'purge() clears sds_versions.pdf_path');
check($logAt !== null && $unlinkAt !== null && $logAt < $unlinkAt, 'audit row is written before the first unlink()');
check($logAt !== null && $updateAt !== null && $logAt < $updateAt, 'audit row is written before the UPDATE sds_versions');
check(str_contains(argText($logArgs[3] ?? null), "'sds_version_ids'"), 'purge audit lists the affected sds_versions ids', argText($logArgs[3] ?? null));

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
