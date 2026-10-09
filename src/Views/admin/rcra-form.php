<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$oldInput = $_SESSION['_flash']['_old_input'] ?? [];
unset($_SESSION['_flash']['_old_input']);

$casVal   = $item['cas_number']  ?? ($oldInput['cas_number']  ?? '');
$codeVal  = $item['waste_code']  ?? ($oldInput['waste_code']  ?? '');
$descVal  = $item['description'] ?? ($oldInput['description'] ?? '');
$kindVal  = $item['kind']        ?? ($oldInput['kind']        ?? '');
$limitRaw = $item['limit_mg_l']  ?? ($oldInput['limit_mg_l']  ?? '');
$limitVal = ($limitRaw === null || $limitRaw === '' || !is_numeric($limitRaw)) ? (string) $limitRaw : rtrim(rtrim(number_format((float) $limitRaw, 3, '.', ''), '0'), '.');

$currentSource = $item['source_ref'] ?? null;
$sourceLabel = 'New';
if ($currentSource === 'manual') {
    $sourceLabel = 'Manual';
} elseif ($mode === 'edit') {
    $sourceLabel = 'Seed';
}
?>

<p><a href="/rcra">&larr; Back to RCRA Waste Codes</a></p>

<div class="card">
    <form method="POST" action="<?= $mode === 'create' ? '/rcra' : '/rcra/' . (int) $item['id'] ?>">
        <?= csrf_field() ?>

        <?php if ($mode === 'edit' && $currentSource !== 'manual'): ?>
            <div style="background:#fef3c7; border:1px solid #f59e0b; color:#78350f; padding:0.5rem 0.75rem; border-radius:4px; margin-bottom:1rem;">
                <strong>Note:</strong> this entry is currently tagged <code><?= e($sourceLabel) ?></code>
                (<?= e((string) ($currentSource ?? '')) ?>). Saving will re-tag it as <strong>Manual</strong>.
            </div>
        <?php endif; ?>

        <div class="form-grid-2col">
            <div class="form-group">
                <label>CAS Number</label>
                <input type="text" name="cas_number" value="<?= e($casVal) ?>" required placeholder="e.g. 108-88-3">
                <small class="text-muted">Format: digits-digits-digit (1–7 / 2 / 1). Together with the waste code this is the unique key.</small>
            </div>
            <div class="form-group">
                <label>Waste Code</label>
                <input type="text" name="waste_code" value="<?= e($codeVal) ?>" required placeholder="e.g. D035, U220, F005" maxlength="10" style="text-transform:uppercase;">
                <small class="text-muted">D004–D043 (toxicity characteristic), F, K, P or U listing. D001–D003 are derived automatically and are refused.</small>
            </div>
            <div class="form-group">
                <label>Kind</label>
                <select name="kind">
                    <option value="">(from code)</option>
                    <?php foreach (['D' => 'D — toxicity characteristic (40 CFR 261.24)', 'F' => 'F — non-specific source (261.31)', 'K' => 'K — specific source (261.32)', 'P' => 'P — acutely hazardous commercial product (261.33(e))', 'U' => 'U — toxic commercial product (261.33(f))'] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $kindVal === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Must match the first letter of the waste code; left blank it is taken from the code.</small>
            </div>
            <div class="form-group">
                <label>TCLP Regulatory Level (mg/L) — D-codes only</label>
                <input type="text" name="limit_mg_l" value="<?= e($limitVal) ?>" placeholder="e.g. 200 or 0.5" inputmode="decimal">
                <small class="text-muted">40 CFR 261.24 Table 1. Leave blank for F / K / P / U codes. Printed as "if the toxicity characteristic regulatory level of N mg/L (TCLP) is exceeded".</small>
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Description (chemical / waste name)</label>
                <input type="text" name="description" value="<?= e($descVal) ?>" required placeholder="e.g. Methyl ethyl ketone">
                <small class="text-muted">Admin label only — Section 13 prints the component's own name from the formula, never this text.</small>
            </div>
            <?php if ($mode === 'edit'): ?>
                <div class="form-group">
                    <label>Current Source</label>
                    <input type="text" value="<?= e($sourceLabel) ?>" readonly>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $mode === 'create' ? 'Add Entry' : 'Update Entry' ?></button>
            <a href="/rcra" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
