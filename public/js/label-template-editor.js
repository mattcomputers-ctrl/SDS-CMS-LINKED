/**
 * LabelTemplateEditor — Drag-and-drop field placement on a label canvas.
 *
 * Fields are stored as percentage positions (x, y, width, height: 0-100)
 * relative to the label dimensions. This makes layouts resolution-independent.
 */
function LabelTemplateEditor(canvasId, hiddenInputId, paletteId, initialLayout, fieldTypes) {
    var self = this;
    this.canvas = document.getElementById(canvasId);
    this.hiddenInput = document.getElementById(hiddenInputId);
    this.palette = document.getElementById(paletteId);
    this.fieldTypes = fieldTypes;
    this.fields = {}; // fieldType -> { x, y, width, height, font_size? } in %
    this.selectedField = null;

    // Canvas scale: px per 1% of label
    this.canvasWidth = 400;
    this.canvasHeight = 260;

    // Load initial layout
    if (initialLayout && typeof initialLayout === 'object') {
        for (var key in initialLayout) {
            if (initialLayout.hasOwnProperty(key) && fieldTypes[key]) {
                this.fields[key] = initialLayout[key];
            }
        }
    }

    this.init();
}

LabelTemplateEditor.prototype.init = function() {
    var self = this;

    // Update canvas aspect ratio based on dimensions
    this.updateCanvasSize();

    // Listen for dimension changes
    var widthInput = document.getElementById('label_width_inches');
    var heightInput = document.getElementById('label_height_inches');
    if (widthInput) widthInput.addEventListener('input', function() { self.updateCanvasSize(); });
    if (heightInput) heightInput.addEventListener('input', function() { self.updateCanvasSize(); });

    // Update sheet info display
    var colsInput = document.getElementById('cols');
    var rowsInput = document.getElementById('rows');
    if (colsInput) colsInput.addEventListener('input', function() { self.updateSheetInfo(); });
    if (rowsInput) rowsInput.addEventListener('input', function() { self.updateSheetInfo(); });

    // Set up form submit handler to convert inches to mm
    var form = document.getElementById('templateForm');
    if (form) {
        form.addEventListener('submit', function() { self.onFormSubmit(); });
    }

    // Set up palette drag
    this.setupPaletteDrag();

    // Set up canvas as drop target
    this.canvas.addEventListener('dragover', function(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
    this.canvas.addEventListener('drop', function(e) { self.onCanvasDrop(e); });

    // Click on canvas background deselects
    this.canvas.addEventListener('mousedown', function(e) {
        if (e.target === self.canvas) {
            self.selectField(null);
        }
    });

    // Clear all button
    var clearBtn = document.getElementById('clearAllFields');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            self.fields = {};
            self.renderFields();
            self.syncHiddenInput();
            self.updatePaletteState();
        });
    }

    // Set up field properties panel
    var fontSizeInput = document.getElementById('fieldPropFontSize');
    if (fontSizeInput) {
        fontSizeInput.addEventListener('input', function() {
            self.onFieldFontSizeChange(this.value);
        });
    }

    // Render initial state
    this.renderFields();
    this.updatePaletteState();
    this.updateSheetInfo();
};

LabelTemplateEditor.prototype.updateCanvasSize = function() {
    var wIn = parseFloat(document.getElementById('label_width_inches').value) || 3.75;
    var hIn = parseFloat(document.getElementById('label_height_inches').value) || 2.4375;

    // Scale to fit max 450px wide
    var maxW = 450;
    var scale = maxW / wIn;
    this.canvasWidth = Math.round(wIn * scale);
    this.canvasHeight = Math.round(hIn * scale);

    this.canvas.style.width = this.canvasWidth + 'px';
    this.canvas.style.height = this.canvasHeight + 'px';

    // Update dimension display
    var dimEl = document.getElementById('canvasDimensions');
    if (dimEl) dimEl.textContent = wIn.toFixed(4) + '" x ' + hIn.toFixed(4) + '"';

    // Re-render fields at new size
    this.renderFields();
    this.updateSheetInfo();
};

LabelTemplateEditor.prototype.updateSheetInfo = function() {
    var cols = parseInt(document.getElementById('cols').value) || 1;
    var rows = parseInt(document.getElementById('rows').value) || 1;
    var el = document.getElementById('labelsPerSheet');
    if (el) el.textContent = (cols * rows) + ' labels per sheet (' + cols + ' cols x ' + rows + ' rows)';
};

LabelTemplateEditor.prototype.setupPaletteDrag = function() {
    var self = this;
    var items = this.palette.querySelectorAll('.template-field-palette-item');

    items.forEach(function(item) {
        item.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('text/plain', item.dataset.fieldType);
            e.dataTransfer.effectAllowed = 'move';
        });

        // Click to add (centered, default size)
        item.addEventListener('click', function() {
            var type = item.dataset.fieldType;
            if (self.fields[type]) return; // Already placed
            self.fields[type] = { x: 10, y: 10, width: 80, height: 15 };
            self.renderFields();
            self.syncHiddenInput();
            self.updatePaletteState();
            self.selectField(type);
        });
    });
};

LabelTemplateEditor.prototype.onCanvasDrop = function(e) {
    e.preventDefault();
    var type = e.dataTransfer.getData('text/plain');
    if (!type || !this.fieldTypes[type]) return;

    var rect = this.canvas.getBoundingClientRect();
    var dropX = ((e.clientX - rect.left) / this.canvasWidth) * 100;
    var dropY = ((e.clientY - rect.top) / this.canvasHeight) * 100;

    // Default size
    var w = 40, h = 15;
    // Center on drop point
    var x = Math.max(0, Math.min(100 - w, dropX - w / 2));
    var y = Math.max(0, Math.min(100 - h, dropY - h / 2));

    this.fields[type] = { x: Math.round(x), y: Math.round(y), width: w, height: h };
    this.renderFields();
    this.syncHiddenInput();
    this.updatePaletteState();
    this.selectField(type);
};

LabelTemplateEditor.prototype.renderFields = function() {
    // Clear existing
    this.canvas.innerHTML = '';

    for (var type in this.fields) {
        if (!this.fields.hasOwnProperty(type)) continue;
        this.createFieldElement(type, this.fields[type]);
    }
};

LabelTemplateEditor.prototype.createFieldElement = function(type, pos) {
    var self = this;
    var el = document.createElement('div');
    el.className = 'label-field';
    el.dataset.fieldType = type;

    // Position from percentages
    el.style.left = (pos.x / 100 * this.canvasWidth) + 'px';
    el.style.top = (pos.y / 100 * this.canvasHeight) + 'px';
    el.style.width = (pos.width / 100 * this.canvasWidth) + 'px';
    el.style.height = (pos.height / 100 * this.canvasHeight) + 'px';

    // Label
    var label = document.createElement('span');
    label.className = 'field-label';
    var displayText = this.fieldTypes[type] || type;
    if (pos.font_size != null) {
        displayText += ' (' + pos.font_size + 'pt)';
    }
    label.textContent = displayText;
    el.appendChild(label);

    // Remove button
    var removeBtn = document.createElement('button');
    removeBtn.className = 'field-remove';
    removeBtn.type = 'button';
    removeBtn.textContent = 'x';
    removeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        delete self.fields[type];
        self.renderFields();
        self.syncHiddenInput();
        self.updatePaletteState();
        self.selectField(null);
    });
    el.appendChild(removeBtn);

    // Resize handles
    var handles = ['e', 's', 'se'];
    handles.forEach(function(dir) {
        var handle = document.createElement('div');
        handle.className = 'resize-handle resize-handle-' + dir;
        handle.addEventListener('mousedown', function(e) {
            e.stopPropagation();
            e.preventDefault();
            self.startResize(type, dir, e);
        });
        el.appendChild(handle);
    });

    // Drag to move
    el.addEventListener('mousedown', function(e) {
        if (e.target.classList.contains('resize-handle') || e.target.classList.contains('field-remove')) return;
        e.preventDefault();
        self.selectField(type);
        self.startDrag(type, e);
    });

    if (type === this.selectedField) {
        el.classList.add('selected');
    }

    this.canvas.appendChild(el);
};

LabelTemplateEditor.prototype.startDrag = function(type, startEvent) {
    var self = this;
    var field = this.fields[type];
    var startX = startEvent.clientX;
    var startY = startEvent.clientY;
    var origX = field.x;
    var origY = field.y;
    var el = this.canvas.querySelector('[data-field-type="' + type + '"]');

    el.classList.add('dragging');

    function onMove(e) {
        var dx = (e.clientX - startX) / self.canvasWidth * 100;
        var dy = (e.clientY - startY) / self.canvasHeight * 100;

        field.x = Math.max(0, Math.min(100 - field.width, origX + dx));
        field.y = Math.max(0, Math.min(100 - field.height, origY + dy));

        el.style.left = (field.x / 100 * self.canvasWidth) + 'px';
        el.style.top = (field.y / 100 * self.canvasHeight) + 'px';
    }

    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        el.classList.remove('dragging');

        // Snap to integers
        field.x = Math.round(field.x);
        field.y = Math.round(field.y);

        self.syncHiddenInput();
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
};

LabelTemplateEditor.prototype.startResize = function(type, dir, startEvent) {
    var self = this;
    var field = this.fields[type];
    var startX = startEvent.clientX;
    var startY = startEvent.clientY;
    var origW = field.width;
    var origH = field.height;
    var el = this.canvas.querySelector('[data-field-type="' + type + '"]');

    function onMove(e) {
        if (dir === 'e' || dir === 'se') {
            var dw = (e.clientX - startX) / self.canvasWidth * 100;
            field.width = Math.max(5, Math.min(100 - field.x, origW + dw));
            el.style.width = (field.width / 100 * self.canvasWidth) + 'px';
        }
        if (dir === 's' || dir === 'se') {
            var dh = (e.clientY - startY) / self.canvasHeight * 100;
            field.height = Math.max(3, Math.min(100 - field.y, origH + dh));
            el.style.height = (field.height / 100 * self.canvasHeight) + 'px';
        }
    }

    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);

        field.width = Math.round(field.width);
        field.height = Math.round(field.height);

        self.syncHiddenInput();
    }

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
};

LabelTemplateEditor.prototype.selectField = function(type) {
    this.selectedField = type;
    var allFields = this.canvas.querySelectorAll('.label-field');
    allFields.forEach(function(el) {
        el.classList.toggle('selected', el.dataset.fieldType === type);
    });
    this.updateFieldPropertiesPanel();
};

LabelTemplateEditor.prototype.updateFieldPropertiesPanel = function() {
    var panel = document.getElementById('fieldPropertiesPanel');
    if (!panel) return;

    var type = this.selectedField;
    if (!type || !this.fields[type]) {
        panel.style.display = 'none';
        return;
    }

    panel.style.display = 'block';
    var field = this.fields[type];

    var nameEl = document.getElementById('fieldPropName');
    if (nameEl) nameEl.textContent = this.fieldTypes[type] || type;

    var fontSizeInput = document.getElementById('fieldPropFontSize');
    if (fontSizeInput) {
        // Show the field-level override, or empty to indicate "use default"
        fontSizeInput.value = field.font_size != null ? field.font_size : '';
    }
};

LabelTemplateEditor.prototype.onFieldFontSizeChange = function(value) {
    var type = this.selectedField;
    if (!type || !this.fields[type]) return;

    var parsed = parseFloat(value);
    if (value === '' || isNaN(parsed) || parsed <= 0) {
        // Clear override — use template default
        delete this.fields[type].font_size;
    } else {
        this.fields[type].font_size = Math.round(parsed * 10) / 10; // round to 1 decimal
    }
    this.syncHiddenInput();
    this.renderFields();
};

LabelTemplateEditor.prototype.updatePaletteState = function() {
    var self = this;
    var items = this.palette.querySelectorAll('.template-field-palette-item');
    items.forEach(function(item) {
        var type = item.dataset.fieldType;
        item.classList.toggle('placed', !!self.fields[type]);
    });
};

LabelTemplateEditor.prototype.syncHiddenInput = function() {
    this.hiddenInput.value = JSON.stringify(this.fields);
};

LabelTemplateEditor.prototype.onFormSubmit = function() {
    // Convert all inch inputs to mm for hidden fields
    var conversions = [
        ['label_width_inches', 'label_width'],
        ['label_height_inches', 'label_height'],
        ['margin_left_inches', 'margin_left'],
        ['margin_top_inches', 'margin_top'],
        ['h_spacing_inches', 'h_spacing'],
        ['v_spacing_inches', 'v_spacing'],
    ];

    conversions.forEach(function(pair) {
        var inchEl = document.getElementById(pair[0]);
        var mmEl = document.getElementById(pair[1]);
        if (inchEl && mmEl) {
            mmEl.value = (parseFloat(inchEl.value) || 0) * 25.4;
        }
    });

    // Ensure layout is synced
    this.syncHiddenInput();
};
