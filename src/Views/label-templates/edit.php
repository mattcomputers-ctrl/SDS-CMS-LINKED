<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$isEdit = $template !== null;
$action = $isEdit ? '/label-templates/' . (int) $template['id'] : '/label-templates';
$layout = $isEdit ? json_decode($template['field_layout'], true) : [];

$fieldIcons = [
    'lot_item_code'            => '&#127991;',
    'pictograms'               => '&#9888;',
    'signal_word'              => '&#9888;',
    'hazard_statements'        => '&#128308;',
    'precautionary_statements' => '&#128309;',
    'net_weight'               => '&#9878;',
    'supplier_info'            => '&#127963;',
];
?>

<div class="card">
    <h2><?= $isEdit ? 'Edit' : 'Create' ?> Label Template</h2>
    <p class="text-muted">Configure label sheet dimensions, then drag and position fields on the label preview.</p>

    <form method="POST" action="<?= $action ?>" id="templateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="field_layout" id="fieldLayoutInput" value="<?= e(json_encode($layout ?: new \stdClass())) ?>">

        <!-- Template Properties -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="form-group">
                <label for="name">Template Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="name" class="input" required maxlength="100"
                       value="<?= e($template['name'] ?? '') ?>" placeholder="e.g. OL575WR">
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" name="description" id="description" class="input" maxlength="255"
                       value="<?= e($template['description'] ?? '') ?>" placeholder="e.g. Big label, 8/sheet">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="form-group">
                <label for="label_width">Label Width (inches) <span class="text-danger">*</span></label>
                <input type="number" name="label_width_inches" id="label_width_inches" class="input" step="0.0001" min="0.1" required
                       value="<?= $isEdit ? number_format((float) $template['label_width'] / 25.4, 4) : '' ?>" placeholder="3.75">
                <input type="hidden" name="label_width" id="label_width">
            </div>
            <div class="form-group">
                <label for="label_height">Label Height (inches) <span class="text-danger">*</span></label>
                <input type="number" name="label_height_inches" id="label_height_inches" class="input" step="0.0001" min="0.1" required
                       value="<?= $isEdit ? number_format((float) $template['label_height'] / 25.4, 4) : '' ?>" placeholder="2.4375">
                <input type="hidden" name="label_height" id="label_height">
            </div>
            <div class="form-group">
                <label for="cols">Columns</label>
                <input type="number" name="cols" id="cols" class="input" min="1" max="10"
                       value="<?= (int) ($template['cols'] ?? 2) ?>">
            </div>
            <div class="form-group">
                <label for="rows">Rows</label>
                <input type="number" name="rows" id="rows" class="input" min="1" max="20"
                       value="<?= (int) ($template['rows'] ?? 4) ?>">
            </div>
            <div class="form-group">
                <label for="margin_left">Left Margin (in)</label>
                <input type="number" name="margin_left_inches" id="margin_left_inches" class="input" step="0.001" min="0"
                       value="<?= $isEdit ? number_format((float) $template['margin_left'] / 25.4, 4) : '0.4375' ?>">
                <input type="hidden" name="margin_left" id="margin_left">
            </div>
            <div class="form-group">
                <label for="margin_top">Top Margin (in)</label>
                <input type="number" name="margin_top_inches" id="margin_top_inches" class="input" step="0.001" min="0"
                       value="<?= $isEdit ? number_format((float) $template['margin_top'] / 25.4, 4) : '0.4375' ?>">
                <input type="hidden" name="margin_top" id="margin_top">
            </div>
            <div class="form-group">
                <label for="h_spacing">H-Spacing (in)</label>
                <input type="number" name="h_spacing_inches" id="h_spacing_inches" class="input" step="0.001" min="0"
                       value="<?= $isEdit ? number_format((float) $template['h_spacing'] / 25.4, 4) : '0.125' ?>">
                <input type="hidden" name="h_spacing" id="h_spacing">
            </div>
            <div class="form-group">
                <label for="v_spacing">V-Spacing (in)</label>
                <input type="number" name="v_spacing_inches" id="v_spacing_inches" class="input" step="0.001" min="0"
                       value="<?= $isEdit ? number_format((float) $template['v_spacing'] / 25.4, 4) : '0.125' ?>">
                <input type="hidden" name="v_spacing" id="v_spacing">
            </div>
            <div class="form-group">
                <label for="default_font_size">Default Font Size (pt)</label>
                <input type="number" name="default_font_size" id="default_font_size" class="input" step="0.5" min="6" max="72"
                       value="<?= number_format((float) ($template['default_font_size'] ?? 7.0), 1) ?>">
                <small class="text-muted">OSHA minimum 6pt. System auto-shrinks from this.</small>
            </div>
        </div>

        <!-- Label Preview & Field Placement -->
        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
            <!-- Field Palette -->
            <div style="flex: 0 0 220px;">
                <h3 style="margin-bottom: 0.75rem;">Fields</h3>
                <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 0.75rem;">Drag fields onto the label, or click to add. Resize by dragging edges.</p>
                <div id="fieldPalette">
                    <?php foreach (\SDS\Models\LabelTemplate::fieldTypes() as $key => $label): ?>
                    <div class="template-field-palette-item" data-field-type="<?= $key ?>" draggable="true">
                        <span class="template-field-icon"><?= $fieldIcons[$key] ?? '&#9632;' ?></span>
                        <?= e($label) ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1rem;">
                    <button type="button" class="btn btn-sm" id="clearAllFields" style="width: 100%;">Clear All Fields</button>
                </div>

                <!-- Field Properties Panel -->
                <div id="fieldPropertiesPanel" style="display: none; margin-top: 1rem; padding: 0.75rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius);">
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;">Field Properties</h4>
                    <p style="font-weight: 600; font-size: 0.85rem; margin-bottom: 0.5rem;" id="fieldPropName"></p>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="fieldPropFontSize" style="font-size: 0.8rem;">Font Size (pt)</label>
                        <input type="number" id="fieldPropFontSize" class="input" step="0.5" min="6" max="72" placeholder="Use default"
                               style="font-size: 0.85rem; padding: 0.3rem 0.5rem;">
                        <small class="text-muted">Leave blank to use the template default</small>
                    </div>
                </div>
            </div>

            <!-- Label Canvas -->
            <div style="flex: 1; min-width: 300px;">
                <h3 style="margin-bottom: 0.75rem;">Label Preview <small class="text-muted">(drag fields to position, drag edges to resize)</small></h3>
                <div id="labelCanvasContainer" style="border: 2px dashed var(--border); border-radius: var(--radius); padding: 1rem; background: #fafbfc; display: flex; justify-content: center;">
                    <div id="labelCanvas" style="position: relative; background: #fff; border: 1px solid #ccc; box-shadow: var(--shadow); overflow: hidden;">
                        <!-- Fields will be placed here by JS -->
                    </div>
                </div>
                <div style="margin-top: 0.5rem; display: flex; gap: 1rem; align-items: center;">
                    <small class="text-muted" id="canvasDimensions"></small>
                    <small class="text-muted" id="labelsPerSheet"></small>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?> Template</button>
            <a href="/label-templates" class="btn">Cancel</a>
        </div>
    </form>
</div>

<style>
/* Template editor styles */
.template-field-palette-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: grab;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
    transition: background var(--transition), box-shadow var(--transition);
    user-select: none;
}
.template-field-palette-item:hover {
    background: #eef2f7;
    box-shadow: var(--shadow-sm);
}
.template-field-palette-item.placed {
    opacity: 0.4;
    cursor: default;
    pointer-events: none;
}
.template-field-palette-item:active {
    cursor: grabbing;
}
.template-field-icon {
    font-size: 1rem;
    width: 1.2rem;
    text-align: center;
}

/* Fields on the canvas */
.label-field {
    position: absolute;
    border: 2px solid var(--primary-light);
    background: rgba(41, 128, 185, 0.08);
    border-radius: 3px;
    cursor: move;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: var(--primary);
    font-weight: 600;
    user-select: none;
    z-index: 1;
    overflow: hidden;
    transition: box-shadow 0.1s ease;
}
.label-field:hover {
    box-shadow: 0 0 0 2px rgba(41, 128, 185, 0.3);
}
.label-field.dragging {
    opacity: 0.7;
    z-index: 10;
    box-shadow: var(--shadow-lg);
}
.label-field.selected {
    border-color: var(--accent);
    background: rgba(46, 204, 113, 0.08);
    box-shadow: 0 0 0 2px rgba(46, 204, 113, 0.3);
}
.label-field .field-label {
    pointer-events: none;
    padding: 2px;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}
.label-field .field-remove {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 16px;
    height: 16px;
    background: #e74c3c;
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 10px;
    line-height: 16px;
    text-align: center;
    cursor: pointer;
    display: none;
    z-index: 20;
    padding: 0;
}
.label-field:hover .field-remove {
    display: block;
}

/* Resize handles */
.label-field .resize-handle {
    position: absolute;
    background: var(--primary-light);
    z-index: 15;
}
.label-field .resize-handle-e {
    right: -3px; top: 0; width: 6px; height: 100%; cursor: e-resize;
}
.label-field .resize-handle-s {
    bottom: -3px; left: 0; width: 100%; height: 6px; cursor: s-resize;
}
.label-field .resize-handle-se {
    right: -4px; bottom: -4px; width: 8px; height: 8px; cursor: se-resize; border-radius: 2px;
}
</style>

<script src="/js/label-template-editor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var initialLayout = <?= json_encode($layout ?: new \stdClass()) ?>;
    var fieldTypes = <?= json_encode(\SDS\Models\LabelTemplate::fieldTypes()) ?>;
    window.labelTemplateEditor = new LabelTemplateEditor('labelCanvas', 'fieldLayoutInput', 'fieldPalette', initialLayout, fieldTypes);
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
