<?php
/**
 * DB-free checks for SDS data-source audit #36(2): a finished-good component
 * with no current formula silently contributed nothing to Section 3 and the
 * hazard classification. Now:
 *
 *   1. SDSReadinessService::fgComponentsWithoutFormulaWarning() builds the
 *      readiness WARNING (never a block).
 *   2. SDSReadinessService::walkFormula() (private, fake Database) reports the
 *      components it could not expand.
 *   3. Source guards: the SDS preview warning names the component; the
 *      readiness page renders the warning.
 *
 * Same Reflection bootstrap as tests/smoke_pdf.php. Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/ReadinessFgComponentFormulaTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

use SDS\Services\SDSReadinessService;

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
echo "1. fgComponentsWithoutFormulaWarning\n";
check(SDSReadinessService::fgComponentsWithoutFormulaWarning([]) === null, 'no codes -> null');
$w = (string) SDSReadinessService::fgComponentsWithoutFormulaWarning(['RED BASE']);
check(str_contains($w, 'RED BASE') && str_contains($w, 'component with no current formula') && str_contains($w, 'Publishing is not blocked'), 'one code: named, warning only', $w);
$w2 = (string) SDSReadinessService::fgComponentsWithoutFormulaWarning([' B ', 'A', 'A', '']);
check(str_contains($w2, 'A, B') && str_contains($w2, 'components with no current formula'), 'trimmed, de-duplicated, sorted, plural', $w2);

// ---------------------------------------------------------------------
echo "2. walkFormula reports components with no current formula\n";
$fake = new class extends \SDS\Core\Database {
    public function __construct()
    {
    }
    public function fetch(string $sql, array $params = []): ?array
    {
        return null;
    }
    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM formula_lines')) {
            return [
                ['formula_id' => 1, 'raw_material_id' => 10, 'finished_good_component_id' => null],
                ['formula_id' => 1, 'raw_material_id' => null, 'finished_good_component_id' => 102],
                ['formula_id' => 1, 'raw_material_id' => null, 'finished_good_component_id' => 103],
                ['formula_id' => 2, 'raw_material_id' => 12, 'finished_good_component_id' => null],
            ];
        }
        if (str_contains($sql, 'WHERE is_current = 1')) {
            return [['id' => 1, 'finished_good_id' => 101], ['id' => 2, 'finished_good_id' => 102]];
        }
        if (str_contains($sql, 'FROM finished_goods')) {
            return [
                ['id' => 101, 'product_code' => 'FG-TOP'],
                ['id' => 102, 'product_code' => 'SUB-FG'],
                ['id' => 103, 'product_code' => 'RED BASE'],
            ];
        }
        return [];
    }
};
$walk = new ReflectionMethod(SDSReadinessService::class, 'walkFormula');
$walk->setAccessible(true);
$missing = [];
$ctx = $walk->invokeArgs(null, [1, $fake, &$missing]);
$keys = array_keys($ctx);
sort($keys);
check($keys === [10, 12], 'RM context keys 10, 12', $keys);
check(($ctx[12]['via_fg_codes'] ?? null) === ['SUB-FG'], 'RM 12 via SUB-FG', $ctx[12] ?? null);
check($missing === [103 => 'RED BASE'], 'missing component reported (103 => RED BASE)', $missing);

// ---------------------------------------------------------------------
echo "3. Source guards\n";
$calcSrc = (string) file_get_contents($basePath . '/src/Services/FormulaCalcService.php');
check(str_contains($calcSrc, 'has no current formula; its ingredients are left out of Section 3'), 'SDS preview warning names the component and the consequence');
$viewSrc = (string) file_get_contents($basePath . '/src/Views/sds-review/index.php');
check(str_contains($viewSrc, 'fg_component_formula_warning'), 'readiness page renders the warning');
$svcSrc = (string) file_get_contents($basePath . '/src/Services/SDSReadinessService.php');
check(str_contains($svcSrc, "'fg_component_formula_warning' => self::fgComponentsWithoutFormulaWarning("), 'review() returns the warning');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
