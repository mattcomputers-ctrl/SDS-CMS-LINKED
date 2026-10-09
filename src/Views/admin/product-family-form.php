<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit  = $mode === 'edit';
$action  = $isEdit ? '/admin/product-families/' . (int) $item['id'] : '/admin/product-families';
$recMap  = \SDS\Models\ProductFamily::decodeLangJson($item['recommended_use_json'] ?? null);
$resMap  = \SDS\Models\ProductFamily::decodeLangJson($item['restrictions_json'] ?? null);
$oldRec  = $_SESSION['_flash']['_old_input']['recommended_use'] ?? [];
$oldRes  = $_SESSION['_flash']['_old_input']['restrictions'] ?? [];
$isUv    = (bool) old('is_uv', (string) (int) ($item['is_uv'] ?? 0));
$isAct   = (bool) old('is_active', (string) (int) ($item['is_active'] ?? 1));
?>

<p><a href="/admin/product-families">&larr; Back to Product Families</a></p>

<div class="card">
    <form method="POST" action="<?= $action ?>">
        <?= csrf_field() ?>
        <h3>Family</h3>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" required value="<?= e(old('name', $item['name'] ?? '')) ?>" placeholder="e.g. UV Offset">
            </div>
            <div class="form-group">
                <label for="sort_order">Sort order</label>
                <input type="number" id="sort_order" name="sort_order" step="1" value="<?= e(old('sort_order', (string) ($item['sort_order'] ?? 0))) ?>">
            </div>
            <div class="form-group">
                <label style="font-weight: normal;"><input type="checkbox" name="is_uv" value="1" <?= $isUv ? 'checked' : '' ?>> UV / LED curable family (enables the UV acrylate rule pack and UV handling text)</label>
            </div>
            <div class="form-group">
                <label style="font-weight: normal;"><input type="checkbox" name="is_active" value="1" <?= $isAct ? 'checked' : '' ?>> Active (inactive families are hidden from pickers; their rules and weight shares are ignored)</label>
            </div>
        </div>

        <h3>Section 1 defaults</h3>
        <p class="text-muted">Printed as Recommended Use / Restrictions on Use for every item resolved to this family unless the product carries its own text. Blank = the English text below; blank English = the translation-file default (shown as placeholder).</p>
        <?php if ($isEdit && !empty($usage)): ?>
            <div class="alert alert-info">
                <strong><?= (int) $usage['raw_materials'] ?></strong> raw material(s) and <strong><?= (int) $usage['finished_goods'] ?></strong> product(s) currently resolve to this family.
                Saving a changed name, UV/LED flag or default text flags all of them (and the SDSs that contain them) for republish.
            </div>
        <?php endif; ?>
        <?php foreach ($langs as $lang): ?>
            <div class="form-grid-2col">
                <div class="form-group">
                    <label for="rec_<?= e($lang) ?>">Recommended Use (<?= e(strtoupper($lang)) ?>)</label>
                    <textarea id="rec_<?= e($lang) ?>" name="recommended_use[<?= e($lang) ?>]" rows="2" placeholder="<?= e($defaults[$lang]['recommended_use'] ?? '') ?>"><?= e($oldRec[$lang] ?? ($recMap[$lang] ?? '')) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="res_<?= e($lang) ?>">Restrictions on Use (<?= e(strtoupper($lang)) ?>)</label>
                    <textarea id="res_<?= e($lang) ?>" name="restrictions[<?= e($lang) ?>]" rows="2" placeholder="<?= e($defaults[$lang]['restrictions'] ?? '') ?>"><?= e($oldRes[$lang] ?? ($resMap[$lang] ?? '')) ?></textarea>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Family' : 'Create Family' ?></button>
            <a href="/admin/product-families" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card" style="margin-top: 1rem;">
    <h3>Membership rules</h3>
    <p class="text-muted">An item joins this family when any rule matches its own code / description or one of its aliases (alias code for prefix and exact, alias description for contains). Precedence when several families match: exact code, then code prefix, then description phrase; longer patterns win. Rules take effect on <a href="/admin/product-families/recompute">Recompute</a>.</p>
    <table class="table table-sm">
        <thead><tr><th style="width: 180px;">Type</th><th>Pattern</th><th style="width: 260px;">Applies to</th><th style="width: 90px;"></th></tr></thead>
        <tbody>
        <?php if (empty($rules)): ?>
            <tr><td colspan="4" class="text-muted" style="text-align:center;">No rules yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?= e(\SDS\Models\ProductFamily::RULE_TYPES[$r['rule_type']] ?? $r['rule_type']) ?></td>
                <td><code><?= e($r['pattern']) ?></code></td>
                <td><?= e(\SDS\Models\ProductFamily::APPLIES_TO[$r['applies_to']] ?? $r['applies_to']) ?></td>
                <td>
                    <form method="POST" action="/admin/product-families/<?= (int) $item['id'] ?>/rules/<?= (int) $r['id'] ?>/delete" style="display:inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remove this rule?');">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="POST" action="/admin/product-families/<?= (int) $item['id'] ?>/rules" class="d-flex" style="gap: 0.5rem; align-items: flex-end; margin-top: 0.75rem;">
        <?= csrf_field() ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="rule_type">Type</label>
            <select id="rule_type" name="rule_type">
                <?php foreach (\SDS\Models\ProductFamily::RULE_TYPES as $k => $label): ?>
                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex: 1; margin-bottom: 0;">
            <label for="pattern">Pattern</label>
            <input type="text" id="pattern" name="pattern" required placeholder="e.g. VEC47 (prefix), UV FLEXO (phrase), VEC47-55G (exact)">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="applies_to">Applies to</label>
            <select id="applies_to" name="applies_to">
                <?php foreach (\SDS\Models\ProductFamily::APPLIES_TO as $k => $label): ?>
                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Add rule</button>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
