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
$prof    = \SDS\Models\ProductFamily::profile($item);               // A11 SDS profile
$pf      = static fn(?float $v): string => $v === null ? '' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
$wbSel   = old('water_based', $prof['water_based']);
$stSel   = old('default_physical_state', (string) ($prof['default_physical_state'] ?? ''));
$wbThr   = rtrim(rtrim(number_format((float) ($waterThreshold ?? \SDS\Services\WaterBasedRule::DEFAULT_THRESHOLD_PCT), 2, '.', ''), '0'), '.');
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
                <label style="font-weight: normal;"><input type="checkbox" name="is_active" value="1" <?= $isAct ? 'checked' : '' ?>> Active (inactive families are hidden from pickers; their rules, weight shares and manual picks are ignored, and they never print Section 1 text or set the UV flag)</label>
            </div>
        </div>

        <h3>Section 1 defaults</h3>
        <p class="text-muted">Printed as Recommended Use / Restrictions on Use for every item resolved to this family unless the product carries its own text. A blank language prints that language's translation-file default (shown as placeholder), never the English text.</p>
        <?php if ($isEdit && !empty($usage)): ?>
            <div class="alert alert-info">
                <strong><?= (int) $usage['raw_materials'] ?></strong> raw material(s) and <strong><?= (int) $usage['finished_goods'] ?></strong> product(s) currently resolve to this family.
                Saving a changed name, UV/LED flag, default text or SDS profile flags all of them (and the SDSs that contain them) for republish.
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

        <h3>SDS profile</h3>
        <p class="text-muted">Optional. Applies to every item resolved to this family (active families only). Blank fields print what the sheet prints today. Enter storage temperatures and pH only from verified QC ranges.</p>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="storage_temp_min_c">Storage temperature, minimum (°C)</label>
                <input type="number" id="storage_temp_min_c" name="storage_temp_min_c" step="0.1" min="-50" max="100" value="<?= e(old('storage_temp_min_c', $pf($prof['storage_temp_min_c']))) ?>">
            </div>
            <div class="form-group">
                <label for="storage_temp_max_c">Storage temperature, maximum (°C)</label>
                <input type="number" id="storage_temp_max_c" name="storage_temp_max_c" step="0.1" min="-50" max="100" value="<?= e(old('storage_temp_max_c', $pf($prof['storage_temp_max_c']))) ?>">
                <small class="text-muted">Both or neither. Section 7 prints "Store between X and Y °C"; Section 10 lists temperatures above the maximum.</small>
            </div>
            <div class="form-group">
                <label for="water_based">Water-based</label>
                <select id="water_based" name="water_based">
                    <?php foreach (\SDS\Models\ProductFamily::WATER_BASED as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $wbSel === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Auto: water (CAS 7732-18-5, including inside intermediates) at or above <?= e($wbThr) ?>% of the formula (<a href="/admin/settings">Settings &rarr; SDS Configuration</a>). Water-based products print "Protect from freezing" (Sections 7 and 10).</small>
            </div>
            <div class="form-group">
                <label for="default_physical_state">Default physical state</label>
                <select id="default_physical_state" name="default_physical_state">
                    <option value="" <?= $stSel === '' ? 'selected' : '' ?>>(none: largest raw material decides)</option>
                    <?php foreach (\SDS\Models\ProductFamily::PHYSICAL_STATES as $s): ?>
                        <option value="<?= e($s) ?>" <?= strcasecmp($stSel, $s) === 0 ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Used when the product has no Physical State of its own (Sections 6, 8, 9 and 14). Paste, Solid and Powder are not liquids, so no flammable-liquid category applies to them.</small>
            </div>
            <div class="form-group">
                <label for="ph_min">Typical pH, minimum</label>
                <input type="number" id="ph_min" name="ph_min" step="0.1" min="0" max="14" value="<?= e(old('ph_min', $prof['ph_min'] === null ? '' : number_format($prof['ph_min'], 1, '.', ''))) ?>">
            </div>
            <div class="form-group">
                <label for="ph_max">Typical pH, maximum</label>
                <input type="number" id="ph_max" name="ph_max" step="0.1" min="0" max="14" value="<?= e(old('ph_max', $prof['ph_max'] === null ? '' : number_format($prof['ph_max'], 1, '.', ''))) ?>">
                <small class="text-muted">Both or neither. Section 9 prints "8.0 – 9.5 (typical)"; blank prints "Not determined".</small>
            </div>
        </div>

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
