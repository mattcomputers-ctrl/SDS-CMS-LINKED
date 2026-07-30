#!/usr/bin/env php
<?php
/**
 * cms-check-item.php — diagnose why a CMS item was (or would be)
 * imported as a raw material.
 *
 * Shows the item's GL group, costing recipe, which recipes use it as
 * an ingredient, and how the import classifies it under the current
 * dot-rule versus a tightened "has any costing recipe" rule.
 *
 * Usage:
 *   php scripts/cms-check-item.php 5545-1GT
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Services\CMSDatabase;

$code = trim($argv[1] ?? '');
if ($code === '') {
    fwrite(STDERR, "Usage: php scripts/cms-check-item.php <ItemCode>\n");
    exit(1);
}

new App();
$db = Database::getInstance();

if (!CMSDatabase::isConfigured()) {
    fwrite(STDERR, "CMS database is not configured.\n");
    exit(1);
}
$cms = CMSDatabase::getInstance();

echo "=============================================================\n";
echo "  CMS Item Check: {$code}\n";
echo "=============================================================\n\n";

// ── 1. The item itself ──
$item = $cms->fetch(
    "SELECT i.Item, i.ItemCode, i.Description, i.CostingRecipe,
            g.Description AS gl_group,
            r.RecipeNumber
     FROM CMS.dbo.Item i
     LEFT JOIN CMS.dbo.GLGroup g ON g.GLGroup = i.GLGroup
     LEFT JOIN CMS.dbo.Recipe r ON i.CostingRecipe = r.Recipe
     WHERE i.ItemCode = ?",
    [$code]
);

if ($item === null) {
    echo "Item '{$code}' not found in CMS.\n";
    exit(1);
}

$hasRecipe   = $item['CostingRecipe'] !== null;
$recipeNum   = (string) ($item['RecipeNumber'] ?? '');
$dotRule     = $hasRecipe && str_contains($recipeNum, '.');

echo "CMS item:\n";
echo "  Description:    " . ($item['Description'] ?? '—') . "\n";
echo "  GL group:       " . ($item['gl_group'] ?? '—') . "\n";
echo "  CostingRecipe:  " . ($hasRecipe ? "yes (RecipeNumber: {$recipeNum})" : 'none (NULL)') . "\n\n";

// ── 2. Recipes that use it as an ingredient ──
$parents = $cms->fetchAll(
    "SELECT DISTINCT pi.ItemCode AS parent_code, pi.Description AS parent_desc,
            pr.RecipeNumber AS parent_recipe
     FROM CMS.dbo.RecipeDetail rd
     JOIN CMS.dbo.Recipe pr ON rd.Recipe = pr.Recipe
     JOIN CMS.dbo.Item pi ON pi.CostingRecipe = pr.Recipe
     WHERE rd.Item = ? AND rd.Context = 'UI'
     ORDER BY pi.ItemCode",
    [(int) $item['Item']]
);

echo "Used as an ingredient in " . count($parents) . " recipe(s):\n";
if (empty($parents)) {
    echo "  (none — the formula-ingredient import path never sees this item)\n";
}
foreach ($parents as $p) {
    echo "  - {$p['parent_code']} ({$p['parent_desc']}) recipe {$p['parent_recipe']}\n";
}
echo "\n";

// ── 3. Classification verdicts ──
echo "Import classification when seen as an ingredient:\n";
echo "  Current rule (recipe number must contain a dot):\n";
echo "    → treated as " . ($dotRule ? 'FINISHED GOOD (skipped as RM)' : 'RAW MATERIAL') . "\n";
echo "  Tightened rule (any costing recipe = manufactured):\n";
echo "    → treated as " . ($hasRecipe ? 'FINISHED GOOD (skipped as RM)' : 'RAW MATERIAL') . "\n\n";

if (!$dotRule && $hasRecipe) {
    echo "  ✔ Tightening the rule WOULD prevent this item from being\n";
    echo "    created as a raw material.\n\n";
} elseif (!$hasRecipe) {
    echo "  ✘ This item has NO costing recipe in CMS — tightening the\n";
    echo "    recipe rule would NOT help. The finished-good guard (base\n";
    echo "    code match against finished_goods) is the only protection.\n\n";
}

// ── 4. Local SDS system state ──
$rm = $db->fetch("SELECT id, created_at FROM raw_materials WHERE internal_code = ?", [$code]);
$baseCode = strip_pack_extension($code);
$fg = $db->fetch("SELECT id, product_code FROM finished_goods WHERE product_code IN (?, ?)", [$code, $baseCode]);
$lineCount = 0;
if ($rm) {
    $row = $db->fetch("SELECT COUNT(*) AS cnt FROM formula_lines WHERE raw_material_id = ?", [(int) $rm['id']]);
    $lineCount = (int) ($row['cnt'] ?? 0);
}

echo "Local SDS system:\n";
echo "  raw_materials row:  " . ($rm ? "id {$rm['id']} (created {$rm['created_at']}, referenced by {$lineCount} formula line(s))" : 'none') . "\n";
echo "  finished_goods:     " . ($fg ? "'{$fg['product_code']}' (id {$fg['id']})" : "no match for '{$code}' or '{$baseCode}'") . "\n\n";

if ($rm && $fg) {
    echo "  → This RM matches finished good '{$fg['product_code']}'.\n";
    echo "    Clean it up with: php scripts/fix-fg-as-raw-materials.php\n";
    echo "    (dry run first; add --confirm to apply)\n";
} elseif ($rm) {
    echo "  → RM exists but no finished good match — cleanup script will\n";
    echo "    not remove it. Import the base finished good first, or\n";
    echo "    delete the RM manually if it is bogus.\n";
}
