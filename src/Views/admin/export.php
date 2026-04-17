<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted mb-1">Export the most recent published SDS (all languages) for every finished good as downloadable ZIP files — one per language. Large languages are automatically split into numbered parts (<?= (int) \SDS\Controllers\ExportController::MAX_FILES_PER_ZIP ?> files per ZIP max). Exports are automatically deleted after 2 hours to save disk space.</p>

<div class="card" style="max-width: 900px;">
    <h2>Export Summary</h2>
    <table class="table table-sm" style="max-width: 400px;">
        <tr><td><strong>Finished Goods with SDSs:</strong></td><td><?= (int) $fgCount ?></td></tr>
        <tr><td><strong>Total PDF files:</strong></td><td><?= (int) $pdfCount ?></td></tr>
    </table>

    <?php if ($pdfCount === 0): ?>
        <p class="text-muted">No published SDS PDFs are available to export.</p>
    <?php else: ?>

        <form id="export-form" style="margin-top: 1rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" id="start-export-btn">Generate New Export</button>
        </form>

        <!-- Overall progress -->
        <div id="export-progress" style="display: none; margin-top: 1.5rem;">
            <div style="margin-bottom: 0.5rem;">
                <strong>Overall Progress</strong>
                <span id="progress-pct" style="float: right;">0%</span>
            </div>
            <div style="background: #e9ecef; border-radius: 4px; overflow: hidden; height: 24px;">
                <div id="progress-bar" style="background: #003366; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>
            </div>
            <p id="progress-message" class="text-muted" style="margin-top: 0.5rem; font-size: 0.85rem;">Preparing...</p>

            <!-- Per-language progress panels, injected by JS -->
            <div id="lang-panels" style="margin-top: 1rem;"></div>
        </div>

        <!-- All Complete banner -->
        <div id="export-complete" style="display: none; margin-top: 1.5rem;">
            <div class="alert alert-success">
                <strong>All exports ready!</strong> See the download links per language above.
            </div>
        </div>

        <!-- Error area -->
        <div id="export-error" style="display: none; margin-top: 1.5rem;">
            <div class="alert alert-danger">
                <strong>Export Failed:</strong> <span id="error-message"></span>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
(function() {
    'use strict';

    var form       = document.getElementById('export-form');
    var btn        = document.getElementById('start-export-btn');
    var progressEl = document.getElementById('export-progress');
    var barEl      = document.getElementById('progress-bar');
    var pctEl      = document.getElementById('progress-pct');
    var msgEl      = document.getElementById('progress-message');
    var langPanels = document.getElementById('lang-panels');
    var completeEl = document.getElementById('export-complete');
    var errorEl    = document.getElementById('export-error');
    var errorMsgEl = document.getElementById('error-message');

    if (!form) return;

    var langPanelRefs = {}; // { en: { container, bar, status, links } }

    function ensurePanel(lang, total) {
        if (langPanelRefs[lang]) return langPanelRefs[lang];

        var container = document.createElement('div');
        container.style.cssText = 'margin-top: 0.75rem; padding: 0.6rem 0.75rem; background: #f6f8fa; border-radius: 4px;';

        var header = document.createElement('div');
        header.style.cssText = 'display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 4px;';
        header.innerHTML = '<strong>' + lang.toUpperCase() + '</strong><span class="lang-status">0 / ' + total + '</span>';

        var barWrap = document.createElement('div');
        barWrap.style.cssText = 'background: #e9ecef; border-radius: 3px; overflow: hidden; height: 14px;';
        var bar = document.createElement('div');
        bar.style.cssText = 'background: #0069d9; height: 100%; width: 0%; transition: width 0.3s ease;';
        barWrap.appendChild(bar);

        var links = document.createElement('div');
        links.style.cssText = 'margin-top: 6px; font-size: 0.85rem;';

        container.appendChild(header);
        container.appendChild(barWrap);
        container.appendChild(links);
        langPanels.appendChild(container);

        var ref = {
            container: container,
            bar:       bar,
            status:    header.querySelector('.lang-status'),
            links:     links,
            seenParts: {},
        };
        langPanelRefs[lang] = ref;
        return ref;
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Starting...';
        progressEl.style.display = 'block';
        completeEl.style.display = 'none';
        errorEl.style.display    = 'none';
        langPanels.innerHTML     = '';
        langPanelRefs            = {};

        var formData = new FormData(form);

        fetch('/bulk-export/start', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.error) {
                showError(data.error);
                return;
            }
            // Pre-create panels in the order returned by the server
            (data.languages || []).forEach(function(lang) {
                ensurePanel(lang, (data.totals && data.totals[lang]) || 0);
            });
            pollProgress(data.token);
        })
        .catch(function(err) {
            showError('Network error: ' + err.message);
        });
    });

    function pollProgress(token) {
        var interval = setInterval(function() {
            fetch('/bulk-export/progress/' + token)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error && !data.grandTotal) {
                    clearInterval(interval);
                    showError(data.error || 'Export not found.');
                    return;
                }

                var pct = data.percent || 0;
                barEl.style.width = pct + '%';
                pctEl.textContent = pct + '%';
                msgEl.textContent = 'Processed ' + (data.totalProcessed || 0) +
                                   ' / ' + (data.grandTotal || 0) + ' PDFs.';

                // Update per-language panels
                Object.keys(data.languages || {}).forEach(function(lang) {
                    var state = data.languages[lang];
                    var ref   = ensurePanel(lang, state.total || 0);

                    var langPct = state.total > 0 ? Math.round((state.progress / state.total) * 1000) / 10 : 0;
                    ref.bar.style.width = langPct + '%';
                    if (state.done) {
                        ref.bar.style.background = state.error ? '#dc3545' : '#28a745';
                    }

                    var statusText = (state.progress || 0) + ' / ' + (state.total || 0);
                    if (state.message) statusText += ' — ' + state.message;
                    ref.status.textContent = statusText;

                    // Append any new parts as download links
                    (state.parts || []).forEach(function(part) {
                        if (ref.seenParts[part.filename]) return;
                        ref.seenParts[part.filename] = true;
                        var a = document.createElement('a');
                        a.href = '/bulk-export/download/' + encodeURIComponent(part.filename);
                        a.textContent = part.filename;
                        a.className = 'btn btn-sm btn-primary';
                        a.style.marginRight = '6px';
                        a.style.marginTop = '4px';
                        var sizeTxt = part.size >= 1048576
                            ? (part.size / 1048576).toFixed(1) + ' MB'
                            : (part.size / 1024).toFixed(0) + ' KB';
                        var sz = document.createElement('span');
                        sz.className = 'text-muted';
                        sz.style.fontSize = '0.8rem';
                        sz.textContent = ' (' + sizeTxt + ')';
                        ref.links.appendChild(a);
                        ref.links.appendChild(sz);
                        ref.links.appendChild(document.createElement('br'));
                    });
                });

                if (data.complete) {
                    clearInterval(interval);
                    barEl.style.width = '100%';
                    barEl.style.background = data.error ? '#dc3545' : '#28a745';
                    pctEl.textContent = '100%';
                    if (!data.error) {
                        completeEl.style.display = 'block';
                    }
                    btn.disabled = false;
                    btn.textContent = 'Generate New Export';
                }
            })
            .catch(function() {
                // transient network blip; keep polling
            });
        }, 800);
    }

    function showError(msg) {
        errorMsgEl.textContent = msg;
        errorEl.style.display = 'block';
        progressEl.style.display = 'none';
        btn.disabled = false;
        btn.textContent = 'Generate New Export';
    }
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
