#!/usr/bin/env php
<?php
/**
 * cms-check-item.php — diagnose why a CMS item was (or would be)
 * imported as a raw material.
 *
 * Shows the item's GL group, costing recipe, which recipes use it as
 * an ingredient, and how the import classifies it under the current
 * dot-rule versus a tightened "has any costing recipe" rule. Locally it
 * also shows the "No SDS required" flag (migration 064), whether the
 * item's base is ignored by aliases / labels / sends, and any CMS import
 * exclusion left by a delete (migration 063).
 *
 * Usage:
 *   php scripts/cms-check-item.php 5545-1GT
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\RawMaterial;
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
// SELECT *: also works before migration 064 (no sds_not_required column).
$rm = $db->fetch("SELECT * FROM raw_materials WHERE internal_code = ?", [$code]);
$baseCode = strip_pack_extension($code);
$fg = $db->fetch("SELECT id, product_code FROM finished_goods WHERE product_code IN (?, ?)", [$code, $baseCode]);
$lineCount = 0;
if ($rm) {
    $row = $db->fetch("SELECT COUNT(*) AS cnt FROM formula_lines WHERE raw_material_id = ?", [(int) $rm['id']]);
    $lineCount = (int) ($row['cnt'] ?? 0);
}
$hasNrCol = RawMaterial::hasSdsNotRequiredColumn($db);
$flagged  = $rm ? RawMaterial::isSdsNotRequired($rm) : false;
$currentFormulaProducts = $rm ? array_column(RawMaterial::getCurrentFormulaProducts((int) $rm['id']), 'product_code') : [];

// Base-level decisions (aliases, labels, sends, reports) follow the base's
// first raw material (lowest id); a finished good with a current formula
// is never ignored.
$baseIgnored = isset(RawMaterial::sdsNotRequiredBases($db)[RawMaterial::baseKey($code)]);
$rep = $db->fetch(
    "SELECT * FROM raw_materials WHERE SUBSTRING_INDEX(internal_code, '-', 1) = ? ORDER BY id LIMIT 1",
    [$baseCode]
);

// Migration 063: a delete records the code; for a Resale item every pack
// size of the same product stays out of the GL-group import.
$exclusions = null;
try {
    $exclusions = $db->fetchAll(
        "SELECT item_code, reason, created_by, created_at FROM cms_rm_import_exclusions
          WHERE item_code = ? OR SUBSTRING_INDEX(item_code, '-', 1) = ?
          ORDER BY item_code",
        [$code, $baseCode]
    );
} catch (\Throwable $e) {
    $exclusions = null;   // table missing: migration 063 not applied
}
$exactExcluded = false;
$siblingExcluded = [];
foreach ($exclusions ?? [] as $ex) {
    if ((string) $ex['item_code'] === $code) {
        $exactExcluded = true;
    } else {
        $siblingExcluded[] = (string) $ex['item_code'];
    }
}
$isResale = ($item['gl_group'] ?? '') === 'Resale';

echo "Local SDS system:\n";
echo "  raw_materials row:  " . ($rm ? "id {$rm['id']} (created {$rm['created_at']}, referenced by {$lineCount} formula line(s))" : 'none') . "\n";
echo "  finished_goods:     " . ($fg ? "'{$fg['product_code']}' (id {$fg['id']})" : "no match for '{$code}' or '{$baseCode}'") . "\n";
if (!$hasNrCol) {
    echo "  No SDS required:    (column missing: migration 064 not applied)\n";
} elseif ($rm) {
    echo "  No SDS required:    " . ($flagged ? 'YES (not a chemical: no resale/alias SDS or label, left off worklists and sends)' : 'no') . "\n";
    if ($flagged && $currentFormulaProducts !== []) {
        echo "                      still used in the current formula of: " . implode(', ', $currentFormulaProducts) . " (its data feeds those SDSs)\n";
    }
}
if ($hasNrCol && $rep) {
    $repNote = ($rm && (int) $rep['id'] === (int) $rm['id']) ? 'this row' : "{$rep['internal_code']} (id {$rep['id']})";
    echo "  base '{$baseCode}':" . str_repeat(' ', max(1, 12 - strlen($baseCode))) . "follows {$repNote}; aliases, labels and sends "
        . ($baseIgnored ? 'IGNORE it (No SDS required)' : 'treat it normally') . "\n";
}
if ($exclusions === null) {
    echo "  import exclusion:   (table missing: migration 063 not applied)\n";
} else {
    echo "  import exclusion:   ";
    if ($exactExcluded) {
        $ex = array_values(array_filter($exclusions, static fn(array $e): bool => (string) $e['item_code'] === $code))[0];
        echo "yes, deleted " . $ex['created_at'] . ($ex['reason'] !== '' ? " ({$ex['reason']})" : '') . " — the GL-group sync will not recreate it\n";
    } elseif ($siblingExcluded !== [] && $isResale) {
        echo "yes, via deleted pack size(s) " . implode(', ', $siblingExcluded) . " — the GL-group sync will not recreate this Resale product\n";
    } elseif ($siblingExcluded !== []) {
        echo "no (pack size(s) " . implode(', ', $siblingExcluded) . " deleted; that only excludes Resale items)\n";
    } else {
        echo "no\n";
    }
}
echo "\n";

if ($rm && $fg) {
    echo "  → This RM matches finished good '{$fg['product_code']}'.\n";
    echo "    Clean it up with: php scripts/fix-fg-as-raw-materials.php\n";
    echo "    (dry run first; add --confirm to apply)\n";
} elseif ($rm) {
    echo "  → RM exists but no finished good match — cleanup script will\n";
    echo "    not remove it. Import the base finished good first. If it is\n";
    echo "    not a chemical (pail, lid, colour guide, tool), mark it\n";
    echo "    No SDS required (Raw Materials → Edit → SDS requirement);\n";
    echo "    delete it only if the code should not exist at all.\n";
} elseif ($exactExcluded || ($isResale && $siblingExcluded !== [])) {
    echo "  → Deleted by an admin, so the sync does not recreate it. To keep\n";
    echo "    it as a non-chemical instead, create it by hand with\n";
    echo "    No SDS required ticked; that lifts the exclusion.\n";
}
