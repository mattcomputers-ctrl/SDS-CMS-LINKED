<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit  = $mode === 'edit';
$mid     = (int) $manufacturer['id'];
$action  = $isEdit ? '/private-label/items/' . (int) $item['id'] : '/private-label/manufacturer/' . $mid . '/items';
$backUrl = '/private-label/manufacturer/' . $mid;

// ── Redirect-back after a validation error ──────────────────────────
// The controller flashes the submitted POST as _old_input. Scalars
// repopulate via old(); radios / selects / checkboxes are rebuilt from
// the same array below. With no old input we fall back to the stored
// item (edit) or the defaults (create).
$old    = $_SESSION['_flash']['_old_input'] ?? null;
$hasOld = is_array($old) && $old !== [];

$currentMode = $hasOld ? (string) ($old['identity_mode'] ?? 'base') : (string) $identityMode;
if (!in_array($currentMode, ['base', 'shared_alias', 'custom'], true)) {
    $currentMode = 'base';
}

$itemAliasId   = ($item !== null && $item['alias_id'] !== null) ? (int) $item['alias_id'] : 0;
$sharedAliasId = $hasOld ? (int) ($old['alias_id'] ?? 0)        : ($identityMode === 'shared_alias' ? $itemAliasId : 0);
$borrowAliasId = $hasOld ? (int) ($old['borrow_alias_id'] ?? 0) : ($identityMode === 'custom' ? $itemAliasId : 0);

$selectedFgId      = $isEdit ? (int) $finishedGood['id'] : (int) old('finished_good_id', '');
$customCode        = old('custom_code', (string) ($item['custom_code'] ?? ''));
$customDescription = old('custom_description', (string) ($item['custom_description'] ?? ''));
$notes             = old('notes', (string) ($item['notes'] ?? ''));

// Checkboxes are absent from POST when unchecked.
$autoRepublish = $hasOld ? !empty($old['auto_republish']) : ($item !== null ? (int) $item['auto_republish'] === 1 : true);

// Operator intent for "Publish SDS now" (default ON on create, OFF on edit;
// redirect-back keeps what was submitted). Kept separate from the rendered
// state so the JS can tick the box as soon as a finished good with a
// published base SDS is chosen — on a fresh create no FG is selected yet,
// so the box starts disabled and the intent would otherwise be lost.
$publishNowWanted = $hasOld ? !empty($old['publish_now']) : !$isEdit;

$fgLatestJson = json_encode((object) $fgLatestVersions) ?: '{}';
$hasBase      = $selectedFgId > 0 && !empty($fgLatestVersions[$selectedFgId]);
$publishNow   = $publishNowWanted && $hasBase;   // rendered state: never checked while disabled

$langList       = implode(', ', array_map('strtoupper', $languages));
$publishHelpOk  = 'Generates a PDF for each language (' . $langList . ') from the latest published base SDS.';
$publishHelpNo  = 'Publish the base SDS for this product first.';
$sourceLabels   = ['custom' => 'custom code', 'shared_alias' => 'shared alias', 'base' => 'base product'];
$initialPrint   = $identity !== null
    ? $identity['code'] . ' — ' . $identity['description'] . ' (' . ($sourceLabels[$identity['source']] ?? $identity['source']) . ')'
    : 'select a finished good';
$radioLabelStyle = 'display: flex; align-items: flex-start; gap: 0.45rem; cursor: pointer; font-weight: normal; margin-bottom: 0.35rem;';
?>

<p><a href="<?= e($backUrl) ?>">&larr; Back to <?= e($manufacturer['name']) ?></a></p>

<div class="card">
    <form method="POST" action="<?= e($action) ?>" id="plItemForm">
        <?= csrf_field() ?>
        <?php if (!$isEdit): ?>
            <input type="hidden" name="manufacturer_id" value="<?= $mid ?>">
        <?php endif; ?>

        <h3>Manufacturer</h3>
        <p>
            <strong><?= e($manufacturer['name']) ?></strong>
            <small class="text-muted">&mdash; this manufacturer's name, address, and contact details appear in Section 1 of the document.</small>
        </p>

        <h3>Base product</h3>
        <div class="form-group">
            <label for="finished_good_id">Finished good <span class="text-danger">*</span></label>
            <?php if ($isEdit): ?>
                <input type="hidden" name="finished_good_id" id="finished_good_id"
                       value="<?= (int) $finishedGood['id'] ?>"
                       data-code="<?= e($finishedGood['product_code']) ?>"
                       data-description="<?= e((string) ($finishedGood['description'] ?? '')) ?>">
                <input type="text" value="<?= e($finishedGood['product_code'] . ' — ' . (string) ($finishedGood['description'] ?? '')) ?>" readonly>
                <small class="text-muted">Changing the base product = create a new item; history stays with this one.</small>
            <?php else: ?>
                <?php /* No `required` here: searchable-select.js hides this <select> (display:none), and
                         browsers silently abort submission on an invalid unfocusable control before
                         the submit event fires. The submit handler below alerts instead, and
                         PrivateLabelItem::validate() enforces the finished good server-side. */ ?>
                <select name="finished_good_id" id="finished_good_id" class="searchable-select">
                    <option value="">— Select a finished good —</option>
                    <?php foreach ($finishedGoods as $fg): ?>
                        <option value="<?= (int) $fg['id'] ?>"
                                data-code="<?= e($fg['product_code']) ?>"
                                data-description="<?= e((string) ($fg['description'] ?? '')) ?>"
                                <?= $selectedFgId === (int) $fg['id'] ? 'selected' : '' ?>><?= e($fg['product_code']) ?> — <?= e((string) ($fg['description'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Active finished goods with a current formula. Type to search by code or description.</small>
            <?php endif; ?>
        </div>

        <h3>Identity on the document</h3>
        <div class="form-group">
            <label style="<?= $radioLabelStyle ?>">
                <input type="radio" name="identity_mode" value="base" <?= $currentMode === 'base' ? 'checked' : '' ?>>
                <span><strong>Base product code and description</strong><br>
                    <small class="text-muted">Prints the finished good's own code and description with this manufacturer's details.</small></span>
            </label>
        </div>

        <div class="form-group">
            <label style="<?= $radioLabelStyle ?>">
                <input type="radio" name="identity_mode" value="shared_alias" <?= $currentMode === 'shared_alias' ? 'checked' : '' ?>>
                <span><strong>Shared alias from the main alias list</strong><br>
                    <small class="text-muted">Prints the alias code (pack-size suffix stripped) and the alias description.</small></span>
            </label>
            <div id="sharedAliasFields" style="margin-left: 1.6rem;">
                <select name="alias_id" id="alias_id">
                    <option value="">— Select a shared alias —</option>
                    <?php foreach ($initialAliases as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"
                                data-code="<?= e($a['code']) ?>"
                                data-description="<?= e($a['description']) ?>"
                                <?= $sharedAliasId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['code']) ?> — <?= e($a['description']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted" id="sharedAliasEmpty" style="display: <?= ($selectedFgId > 0 && $initialAliases === []) ? 'inline' : 'none' ?>;">No aliases exist for this finished good.</small>
            </div>
        </div>

        <div class="form-group">
            <label style="<?= $radioLabelStyle ?>">
                <input type="radio" name="identity_mode" value="custom" <?= $currentMode === 'custom' ? 'checked' : '' ?>>
                <span><strong>Manufacturer-specific code</strong><br>
                    <small class="text-muted">A code that exists only for this manufacturer. It is never added to the main alias list.</small></span>
            </label>
            <div id="customFields" style="margin-left: 1.6rem;">
                <div class="form-grid-2col">
                    <div class="form-group">
                        <label for="custom_code">Product code <span class="text-danger">*</span></label>
                        <input type="text" name="custom_code" id="custom_code" maxlength="100" value="<?= e($customCode) ?>">
                        <small class="text-muted">Printed exactly as typed — enter the base code without a pack-size suffix.</small>
                    </div>
                    <div class="form-group">
                        <label for="borrow_alias_id">Borrow description from shared alias (optional)</label>
                        <select name="borrow_alias_id" id="borrow_alias_id">
                            <option value="">— None (use the base product description) —</option>
                            <?php foreach ($initialAliases as $a): ?>
                                <option value="<?= (int) $a['id'] ?>"
                                        data-code="<?= e($a['code']) ?>"
                                        data-description="<?= e($a['description']) ?>"
                                        <?= $borrowAliasId === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['code']) ?> — <?= e($a['description']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted" id="borrowAliasEmpty" style="display: <?= ($selectedFgId > 0 && $initialAliases === []) ? 'inline' : 'none' ?>;">No aliases exist for this finished good.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="custom_description">Description override</label>
            <input type="text" name="custom_description" id="custom_description" maxlength="500" value="<?= e($customDescription) ?>">
            <small class="text-muted">Leave blank to use the alias / base product description.</small>
        </div>

        <div class="alert alert-info" style="margin: 0.5rem 0 1.25rem 0;">
            <strong>Will print as:</strong> <span id="willPrintText"><?= e($initialPrint) ?></span>
        </div>

        <h3>Publishing</h3>
        <div class="form-group">
            <label style="display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: normal;">
                <input type="checkbox" name="auto_republish" value="1" <?= $autoRepublish ? 'checked' : '' ?>>
                <span>Automatically republish with the base product</span>
            </label>
            <br><small class="text-muted">Create a new version of this document whenever the base product's SDS is republished (manual, SDS Update Required, or bulk publish). Unchecked = frozen: only the Republish button regenerates it.</small>
        </div>
        <div class="form-group">
            <label style="display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer; font-weight: normal;">
                <input type="checkbox" name="publish_now" id="publish_now" value="1" <?= $publishNow ? 'checked' : '' ?> <?= $hasBase ? '' : 'disabled' ?>>
                <span>Publish SDS now after saving</span>
            </label>
            <br><small class="text-muted" id="publishNowHelp"><?= e($hasBase ? $publishHelpOk : $publishHelpNo) ?></small>
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <input type="text" name="notes" id="notes" maxlength="500" value="<?= e($notes) ?>" placeholder="Internal note (not printed on the document)">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Save item' ?></button>
            <span class="inline-form">
                <button type="button" id="livePreviewBtn" class="btn btn-outline" title="Opens a live preview with the current identity in a new tab">Preview</button>
                <select id="preview_lang" style="width: auto;">
                    <?php foreach ($languages as $lang): ?>
                        <option value="<?= e($lang) ?>"><?= e($langNames[$lang] ?? strtoupper($lang)) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <a href="<?= e($backUrl) ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var fgLatest       = <?= $fgLatestJson ?>;
    var manufacturerId = <?= $mid ?>;
    var publishHelpOk  = <?= json_encode($publishHelpOk) ?>;
    var publishHelpNo  = <?= json_encode($publishHelpNo) ?>;
    var sourceLabels   = { custom: 'custom code', shared_alias: 'shared alias', base: 'base product' };

    var form         = document.getElementById('plItemForm');
    var fgSelect     = document.getElementById('finished_good_id');
    var modeRadios   = document.querySelectorAll('input[name="identity_mode"]');
    var sharedBox    = document.getElementById('sharedAliasFields');
    var customBox    = document.getElementById('customFields');
    var aliasSelect  = document.getElementById('alias_id');
    var borrowSelect = document.getElementById('borrow_alias_id');
    var aliasEmpty   = document.getElementById('sharedAliasEmpty');
    var borrowEmpty  = document.getElementById('borrowAliasEmpty');
    var customCode   = document.getElementById('custom_code');
    var customDesc   = document.getElementById('custom_description');
    var willPrint    = document.getElementById('willPrintText');
    var publishNow   = document.getElementById('publish_now');
    var publishHelp  = document.getElementById('publishNowHelp');
    var previewBtn   = document.getElementById('livePreviewBtn');
    var previewLang  = document.getElementById('preview_lang');

    // Seeded from the server-side intent, not the rendered checkbox: on a
    // fresh create the box renders disabled + unchecked (no FG selected yet)
    // and updatePublishNow() restores this value once an FG with a
    // published base SDS is picked.
    var publishNowWanted = <?= json_encode($publishNowWanted) ?>;

    function currentMode() {
        for (var i = 0; i < modeRadios.length; i++) {
            if (modeRadios[i].checked) return modeRadios[i].value;
        }
        return 'base';
    }

    function fgInfo() {
        var none = { id: '', code: '', description: '' };
        if (!fgSelect) return none;
        if (fgSelect.tagName === 'SELECT') {
            var opt = fgSelect.options[fgSelect.selectedIndex];
            if (!opt || !opt.value) return none;
            return { id: opt.value, code: opt.getAttribute('data-code') || '', description: opt.getAttribute('data-description') || '' };
        }
        return { id: fgSelect.value, code: fgSelect.getAttribute('data-code') || '', description: fgSelect.getAttribute('data-description') || '' };
    }

    function selectedAlias(select) {
        if (!select || !select.value) return null;
        var opt = select.options[select.selectedIndex];
        if (!opt) return null;
        return { id: select.value, code: opt.getAttribute('data-code') || '', description: opt.getAttribute('data-description') || '' };
    }

    function setBoxEnabled(box, enabled) {
        if (!box) return;
        var controls = box.querySelectorAll('input, select');
        for (var i = 0; i < controls.length; i++) controls[i].disabled = !enabled;
        box.style.opacity = enabled ? '1' : '0.55';
    }

    // Same precedence as PrivateLabelPublisher::resolveIdentity():
    //   code: custom_code -> alias code -> FG code
    //   description: custom_description -> alias description -> FG description
    function resolveIdentity() {
        var fg = fgInfo();
        if (!fg.id) return null;
        var mode  = currentMode();
        var alias = mode === 'shared_alias' ? selectedAlias(aliasSelect) : (mode === 'custom' ? selectedAlias(borrowSelect) : null);
        var customCodeVal = mode === 'custom' && customCode ? (customCode.value || '').trim() : '';
        var customDescVal = customDesc ? (customDesc.value || '').trim() : '';

        var code, source;
        if (customCodeVal) {
            code = customCodeVal; source = 'custom';
        } else if (alias && alias.code) {
            code = alias.code; source = 'shared_alias';
        } else {
            code = fg.code; source = 'base';
        }
        var description = customDescVal || (alias && alias.description) || fg.description || '';

        return {
            code: code,
            description: description,
            source: source,
            incomplete: (mode === 'custom' && !customCodeVal) ? 'enter a manufacturer-specific code'
                      : (mode === 'shared_alias' && !alias) ? 'select a shared alias'
                      : ''
        };
    }

    function updateWillPrint() {
        if (!willPrint) return;
        var r = resolveIdentity();
        if (!r) { willPrint.textContent = 'select a finished good'; return; }
        if (r.incomplete) { willPrint.textContent = r.incomplete; return; }
        willPrint.textContent = r.code + ' — ' + r.description + ' (' + (sourceLabels[r.source] || r.source) + ')';
    }

    function applyMode() {
        var mode = currentMode();
        setBoxEnabled(sharedBox, mode === 'shared_alias');
        setBoxEnabled(customBox, mode === 'custom');
        if (customCode)  customCode.required  = (mode === 'custom');
        if (aliasSelect) aliasSelect.required = (mode === 'shared_alias');
        updateWillPrint();
    }

    function updatePublishNow() {
        if (!publishNow) return;
        var fg  = fgInfo();
        var has = !!fg.id && fgLatest[String(fg.id)] != null && Number(fgLatest[String(fg.id)]) > 0;
        publishNow.disabled = !has;
        publishNow.checked  = has ? publishNowWanted : false;
        if (publishHelp) publishHelp.textContent = has ? publishHelpOk : publishHelpNo;
    }

    function fillAliasSelects(list, keepShared, keepBorrow) {
        var targets = [
            [aliasSelect,  keepShared, aliasEmpty,  '— Select a shared alias —'],
            [borrowSelect, keepBorrow, borrowEmpty, '— None (use the base product description) —']
        ];
        targets.forEach(function (cfg) {
            var sel = cfg[0];
            if (!sel) return;
            var keep = cfg[1];
            sel.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = cfg[3];
            sel.appendChild(ph);
            list.forEach(function (a) {
                var o = document.createElement('option');
                o.value = String(a.id);
                o.setAttribute('data-code', a.code || '');
                o.setAttribute('data-description', a.description || '');
                o.textContent = (a.code || '') + ' — ' + (a.description || '');
                if (keep && String(keep) === String(a.id)) o.selected = true;
                sel.appendChild(o);
            });
            if (cfg[2]) cfg[2].style.display = list.length ? 'none' : 'inline';
        });
    }

    function loadAliases(fgId, keepShared, keepBorrow) {
        if (!fgId) {
            fillAliasSelects([], null, null);
            if (aliasEmpty)  aliasEmpty.style.display  = 'none';
            if (borrowEmpty) borrowEmpty.style.display = 'none';
            updateWillPrint();
            return;
        }
        fetch('/private-label/aliases-for-fg?fg_id=' + encodeURIComponent(fgId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) {
                fillAliasSelects(Array.isArray(list) ? list : [], keepShared, keepBorrow);
                updateWillPrint();
            })
            .catch(function () {
                fillAliasSelects([], null, null);
                updateWillPrint();
            });
    }

    // ── Wire up ──────────────────────────────────────────────────────
    for (var i = 0; i < modeRadios.length; i++) {
        modeRadios[i].addEventListener('change', applyMode);
    }
    if (customCode)   customCode.addEventListener('input', updateWillPrint);
    if (customDesc)   customDesc.addEventListener('input', updateWillPrint);
    if (aliasSelect)  aliasSelect.addEventListener('change', updateWillPrint);
    if (borrowSelect) borrowSelect.addEventListener('change', updateWillPrint);
    if (publishNow) {
        publishNow.addEventListener('change', function () { publishNowWanted = publishNow.checked; });
    }

    if (fgSelect && fgSelect.tagName === 'SELECT') {
        fgSelect.addEventListener('change', function () {
            loadAliases(fgSelect.value, null, null);
            updatePublishNow();
            updateWillPrint();
        });
        // Redirect-back without server-rendered alias options: fetch them now.
        if (fgSelect.value && aliasSelect && aliasSelect.options.length <= 1) {
            loadAliases(fgSelect.value, <?= json_encode($sharedAliasId > 0 ? (string) $sharedAliasId : null) ?>, <?= json_encode($borrowAliasId > 0 ? (string) $borrowAliasId : null) ?>);
        }
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            var fg = fgInfo();
            if (!fg.id) {
                e.preventDefault();
                alert('Please select a finished good.');
                return;
            }
            var r = resolveIdentity();
            if (r && r.incomplete) {
                e.preventDefault();
                alert('Please ' + r.incomplete + '.');
            }
        });
    }

    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            var fg = fgInfo();
            if (!fg.id) {
                alert('Please select a finished good before previewing.');
                return;
            }
            // Same incomplete-identity guard as the submit handler: without it
            // the server silently falls back to the base FG identity and the
            // preview shows a document this form state can never publish.
            var r = resolveIdentity();
            if (r && r.incomplete) {
                alert('Please ' + r.incomplete + '.');
                return;
            }
            var mode   = currentMode();
            var alias  = mode === 'shared_alias' ? selectedAlias(aliasSelect) : (mode === 'custom' ? selectedAlias(borrowSelect) : null);
            var params = 'finished_good_id=' + encodeURIComponent(fg.id)
                       + '&manufacturer_id=' + encodeURIComponent(manufacturerId)
                       + '&identity_mode=' + encodeURIComponent(mode)
                       + '&lang=' + encodeURIComponent(previewLang ? previewLang.value : '');
            if (alias) params += '&alias_id=' + encodeURIComponent(alias.id);
            if (mode === 'custom' && customCode) params += '&custom_code=' + encodeURIComponent(customCode.value || '');
            if (customDesc) params += '&custom_description=' + encodeURIComponent(customDesc.value || '');
            window.open('/private-label/live-preview?' + params, '_blank');
        });
    }

    applyMode();
    updatePublishNow();
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
