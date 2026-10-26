#!/usr/bin/env php
<?php
/**
 * CMS Import > Incomplete Raw Materials: supplier columns (2026-10-26) — DB-free.
 *
 * Every tab (Needs Details, Single-RM Unblock, Trade Secret, In Inventory)
 * shows the supplier, sortable from its header, and the supplier's own code
 * for the item (CMS "Their Code", raw_materials.supplier_product_code). The
 * page is rendered with stub rows (layout left out) and each tab's header and
 * cells are checked; the four queries must return both fields. The sort
 * itself is page script (A-Z, Z-A, then the tab's own order); its wiring is
 * checked here.
 *
 * Run: php tests/Services/IncompleteSupplierColumnsTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';
$_SESSION = [];

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
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

echo "a. The queries return the supplier and its code\n";
$svc = (string) file_get_contents($basePath . '/src/Services/CMSImportService.php');
foreach (['getIncompleteRawMaterials', 'getSingleBlockerImpact', 'getTradeSecretRawMaterials', 'getInventoryGaps'] as $fn) {
    $start = (int) strpos($svc, 'public function ' . $fn . '(');
    $body  = substr($svc, $start, preg_match('/\n    (?:public|private|protected) (?:static )?function /', $svc, $m, PREG_OFFSET_CAPTURE, $start + 10) ? $m[0][1] - $start : null);
    check(str_contains($body, 'rm.supplier,') && str_contains($body, 'rm.supplier_product_code'), "{$fn}() selects rm.supplier and rm.supplier_product_code");
}
check(str_contains($svc, "'supplier_product_code' => \$rm['supplier_product_code'],"), 'getInventoryGaps() passes the code on to the page');

echo "\nb. Rendered page\n";
$src = str_replace("\r\n", "\n", (string) file_get_contents($basePath . '/src/Views/cms-import/incomplete.php'));
$src = str_replace(["<?php include dirname(__DIR__) . '/layouts/main.php'; ?>", "<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>"], '', $src);
$row = static fn (int $id, string $supplier, ?string $code, array $extra): array => $extra + [
    'id' => $id, 'internal_code' => 'RM' . $id, 'supplier' => $supplier, 'supplier_product_name' => 'Product ' . $id,
    'supplier_product_code' => $code, 'cms_item_code' => 'C' . $id, 'created_at' => '2026-10-20 10:00:00', 'sds_not_required' => 0,
];
$incomplete    = [$row(1, 'Acme & Co', 'AC-1', ['fg_total_count' => 2, 'fg_direct_count' => 1]), $row(2, '', null, ['fg_total_count' => 1, 'fg_direct_count' => 1])];
$unblockers    = [$row(3, 'Acme & Co', 'AC-3', ['unblock_count' => 2, 'example_fgs' => ['F1'], 'all_fgs' => ['F1']])];
$tradeSecret   = [$row(4, 'Acme & Co', 'AC-4', ['ts_type' => 'whole_rm', 'ts_pct' => 100, 'ts_count' => 0, 'fg_total_count' => 1])];
$inventoryGaps = [$row(5, 'Acme & Co', 'AC-5', ['qty_on_hand' => 4.0, 'unit' => 'LB', 'missing_data' => true, 'missing_sds' => false])];
$inventoryError    = null;
$flaggedInFormulas = [];
ob_start();
eval('?>' . $src);
$html = (string) ob_get_clean();

$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
$xp = new DOMXPath($dom);
$codeByTab = ['incomplete' => 'AC-1', 'unblockers' => 'AC-3', 'trade-secret' => 'AC-4', 'inventory' => 'AC-5'];
foreach ($codeByTab as $tab => $code) {
    $ths   = $xp->query("//div[@id='tab-{$tab}']//table[contains(@class,'table')][1]/thead/tr/th");
    $heads = [];
    foreach ($ths as $th) {
        $heads[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
    }
    $cells = $xp->query("//div[@id='tab-{$tab}']//table[contains(@class,'table')][1]/tbody/tr[1]/td");
    check(($heads[1] ?? '') !== '' && str_starts_with($heads[1], 'Supplier') && ($heads[2] ?? '') === 'Supplier Code' && $cells->length === count($heads),
        "{$tab}: Supplier then Supplier Code, every row cell under its header", $heads);
    check(trim($cells->item(1)->textContent) === 'Acme & Co' && $cells->item(1)->getAttribute('data-sort-value') === 'Acme & Co' && trim($cells->item(2)->textContent) === $code,
        "{$tab}: the supplier (with its sort value) and the supplier's code");
    check($xp->query("//div[@id='tab-{$tab}']//thead//button[contains(@class,'sort-supplier')]")->length === 1, "{$tab}: the Supplier header is a sort button");
}
$blank = $xp->query("//div[@id='tab-incomplete']//table[contains(@class,'table')][1]/tbody/tr[2]/td");
check(trim($blank->item(1)->textContent) === '—' && $blank->item(1)->getAttribute('data-sort-value') === '' && trim($blank->item(2)->textContent) === '—',
    'no supplier or code: a dash (sorted last)');
check($xp->query("//div[@id='tab-trade-secret']//tbody//small")->length === 0, 'Trade Secret: the code is no longer repeated under the product name');

echo "\nc. Sort script\n";
check(str_contains($src, "document.querySelectorAll('.sort-supplier').forEach(") && str_contains($src, "dir = dir === '' ? 'asc' : (dir === 'asc' ? 'desc' : '');")
    && str_contains($src, 'return a.dataset.order - b.dataset.order;'), 'A to Z, Z to A, then the tab\'s own order; ties keep that order');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
