/**
 * CAS Number Determination Form — Interactive GHS hazard selection.
 *
 * Handles:
 * - Auto-populating H/P codes, pictograms, and signal word from hazard selections
 * - Marking auto-triggered H/P codes in the manual lists
 * - Dynamic exposure limit rows
 * - Building JSON for form submission
 */
(function() {
    'use strict';

    var DATA = window.GHS_DATA || {};
    var PICTO_PATHS = window.PICTOGRAM_PATHS || {};

    // ── DOM references ─────────────────────────────────────────────
    var hazardCheckboxes     = document.querySelectorAll('.hazard-checkbox');
    var hCodeCheckboxes      = document.querySelectorAll('.h-code-checkbox');
    var pCodeCheckboxes      = document.querySelectorAll('.p-code-checkbox');
    var autoSummary          = document.getElementById('auto-summary');
    var autoSignalWord       = document.getElementById('auto-signal-word');
    var autoPictograms       = document.getElementById('auto-pictograms');
    var autoHCodes           = document.getElementById('auto-h-codes');
    var autoPCodes           = document.getElementById('auto-p-codes');
    var selectedHazardsField = document.getElementById('selected-hazards-json');
    var exposureLimitsField  = document.getElementById('exposure-limits-json');
    var expLimitsBody        = document.getElementById('exposure-limits-body');
    var addExpLimitBtn       = document.getElementById('add-exposure-limit');
    var form                 = document.getElementById('determination-form');

    // Signal word priority
    var SIGNAL_PRIORITY = { 'Danger': 2, 'Warning': 1 };

    // Pictogram priority (for sorting display)
    var PICTO_PRIORITY = {
        'GHS01': 9, 'GHS05': 8, 'GHS06': 7, 'GHS02': 6,
        'GHS04': 5, 'GHS03': 4, 'GHS08': 3, 'GHS07': 2, 'GHS09': 1
    };

    // ── Hazard checkbox change handler ─────────────────────────────
    function updateFromHazardSelections() {
        var selectedKeys = [];
        var allHCodes = {};
        var allPCodes = {};
        var allPictograms = {};
        var signalWord = null;

        hazardCheckboxes.forEach(function(cb) {
            if (!cb.checked) return;
            var key = cb.dataset.key;
            selectedKeys.push(key);

            var entry = DATA[key];
            if (!entry) return;

            // Collect H-codes
            (entry.h_codes || []).forEach(function(code) {
                allHCodes[code] = entry.h_descriptions[code] || '';
            });

            // Collect P-codes
            (entry.p_codes || []).forEach(function(code) {
                allPCodes[code] = entry.p_descriptions[code] || '';
            });

            // Collect pictograms
            (entry.pictograms || []).forEach(function(pic) {
                allPictograms[pic] = true;
            });

            // Signal word (highest priority wins)
            if (entry.signal_word) {
                var newPri = SIGNAL_PRIORITY[entry.signal_word] || 0;
                var curPri = signalWord ? (SIGNAL_PRIORITY[signalWord] || 0) : 0;
                if (newPri > curPri) {
                    signalWord = entry.signal_word;
                }
            }
        });

        // Update hidden field
        selectedHazardsField.value = JSON.stringify(selectedKeys);

        // Apply pictogram precedence (GHS06 supersedes GHS07, GHS05 supersedes GHS07)
        var pictoKeys = Object.keys(allPictograms);
        if (pictoKeys.indexOf('GHS06') >= 0 || pictoKeys.indexOf('GHS05') >= 0) {
            delete allPictograms['GHS07'];
        }

        // Sort pictograms by priority
        var sortedPictos = Object.keys(allPictograms).sort(function(a, b) {
            return (PICTO_PRIORITY[b] || 0) - (PICTO_PRIORITY[a] || 0);
        });

        // Sort H-codes and P-codes
        var sortedH = Object.keys(allHCodes).sort();
        var sortedP = Object.keys(allPCodes).sort();

        // Show/hide summary
        if (selectedKeys.length > 0) {
            autoSummary.style.display = 'block';

            // Signal word
            if (signalWord) {
                autoSignalWord.innerHTML = '<span class="signal-word signal-' + signalWord.toLowerCase() + '">' + signalWord + '</span>';
            } else {
                autoSignalWord.textContent = 'None';
            }

            // Pictograms
            if (sortedPictos.length > 0) {
                autoPictograms.innerHTML = sortedPictos.map(function(p) {
                    var src = PICTO_PATHS[p] || ('/assets/pictograms/' + p + '.svg');
                    return '<img src="' + src + '" alt="' + p + '" class="hazard-picto-icon" style="width:32px;height:32px;margin-right:4px;" title="' + p + '">';
                }).join('');
            } else {
                autoPictograms.textContent = 'None';
            }

            // H-codes
            if (sortedH.length > 0) {
                autoHCodes.innerHTML = sortedH.map(function(code) {
                    return '<span class="code-tag" title="' + escapeHtml(allHCodes[code]) + '">' + code + '</span>';
                }).join(' ');
            } else {
                autoHCodes.textContent = 'None';
            }

            // P-codes
            if (sortedP.length > 0) {
                autoPCodes.innerHTML = sortedP.map(function(code) {
                    return '<span class="code-tag code-tag-p" title="' + escapeHtml(allPCodes[code]) + '">' + code + '</span>';
                }).join(' ');
            } else {
                autoPCodes.textContent = 'None';
            }
        } else {
            autoSummary.style.display = 'none';
        }

        // Mark auto-triggered H-codes in the manual checkbox list
        hCodeCheckboxes.forEach(function(cb) {
            var code = cb.dataset.code;
            var row = cb.closest('.code-checkbox-row');
            if (allHCodes.hasOwnProperty(code)) {
                row.classList.add('auto-triggered');
            } else {
                row.classList.remove('auto-triggered');
            }
        });

        // Mark auto-triggered P-codes in the manual checkbox list
        pCodeCheckboxes.forEach(function(cb) {
            var code = cb.dataset.code;
            var row = cb.closest('.code-checkbox-row');
            if (allPCodes.hasOwnProperty(code)) {
                row.classList.add('auto-triggered');
            } else {
                row.classList.remove('auto-triggered');
            }
        });
    }

    // Attach change handlers
    hazardCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', updateFromHazardSelections);
    });

    // Run on page load to restore state
    updateFromHazardSelections();

    // ── Exposure Limit Rows ────────────────────────────────────────
    function createExpLimitRow(data) {
        data = data || {};
        var tr = document.createElement('tr');
        tr.className = 'exposure-limit-row';
        tr.innerHTML =
            '<td>' +
                '<select name="exp_limit_type[]">' +
                    '<option value="PEL-TWA"' + (data.limit_type === 'PEL-TWA' ? ' selected' : '') + '>OSHA PEL-TWA</option>' +
                    '<option value="TLV-TWA"' + (data.limit_type === 'TLV-TWA' ? ' selected' : '') + '>ACGIH TLV-TWA</option>' +
                    '<option value="REL-TWA"' + (data.limit_type === 'REL-TWA' ? ' selected' : '') + '>NIOSH REL-TWA</option>' +
                    '<option value="STEL"' + (data.limit_type === 'STEL' ? ' selected' : '') + '>STEL</option>' +
                    '<option value="Ceiling"' + (data.limit_type === 'Ceiling' ? ' selected' : '') + '>Ceiling</option>' +
                    '<option value="IDLH"' + (data.limit_type === 'IDLH' ? ' selected' : '') + '>IDLH</option>' +
                    '<option value="Other"' + (data.limit_type === 'Other' ? ' selected' : '') + '>Other</option>' +
                '</select>' +
            '</td>' +
            '<td><input type="text" name="exp_limit_value[]" value="' + escapeHtml(data.value || '') + '" placeholder="e.g. 50"></td>' +
            '<td>' +
                '<select name="exp_limit_units[]">' +
                    '<option value="mg/m3"' + (data.units === 'mg/m3' ? ' selected' : '') + '>mg/m\u00B3</option>' +
                    '<option value="ppm"' + (data.units === 'ppm' ? ' selected' : '') + '>ppm</option>' +
                    '<option value="f/cc"' + (data.units === 'f/cc' ? ' selected' : '') + '>f/cc</option>' +
                    '<option value="mppcf"' + (data.units === 'mppcf' ? ' selected' : '') + '>mppcf</option>' +
                '</select>' +
            '</td>' +
            '<td><input type="text" name="exp_limit_notes[]" value="' + escapeHtml(data.notes || '') + '" placeholder="Optional notes"></td>' +
            '<td><button type="button" class="btn btn-sm btn-danger remove-exp-limit">X</button></td>';
        return tr;
    }

    addExpLimitBtn.addEventListener('click', function() {
        expLimitsBody.appendChild(createExpLimitRow());
    });

    // Delegate remove button
    document.getElementById('exposure-limits-container').addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-exp-limit')) {
            e.target.closest('tr').remove();
        }
    });

    // ── Form submission: build JSON from exposure limit rows ───────
    form.addEventListener('submit', function() {
        // Build exposure limits JSON
        var limits = [];
        var rows = expLimitsBody.querySelectorAll('.exposure-limit-row');
        rows.forEach(function(row) {
            var type  = row.querySelector('[name="exp_limit_type[]"]').value;
            var value = row.querySelector('[name="exp_limit_value[]"]').value.trim();
            var units = row.querySelector('[name="exp_limit_units[]"]').value;
            var notes = row.querySelector('[name="exp_limit_notes[]"]').value.trim();
            if (value !== '') {
                limits.push({ limit_type: type, value: value, units: units, notes: notes });
            }
        });
        exposureLimitsField.value = JSON.stringify(limits);

        // Ensure selected hazards JSON is up to date
        var keys = [];
        hazardCheckboxes.forEach(function(cb) {
            if (cb.checked) keys.push(cb.dataset.key);
        });
        selectedHazardsField.value = JSON.stringify(keys);
    });

    // ── Utility ────────────────────────────────────────────────────
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
})();
