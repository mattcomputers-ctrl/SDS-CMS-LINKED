<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted mb-1">Export the most recent published SDS (all languages) for every finished good as a downloadable ZIP file. Exports are automatically deleted after 2 hours to save disk space.</p>

<div class="card" style="max-width: 700px;">
    <h2>Export Summary</h2>
    <table class="table table-sm" style="max-width: 400px;">
        <tr><td><strong>Finished Goods with SDSs:</strong></td><td><?= (int) $fgCount ?></td></tr>
        <tr><td><strong>Total PDF files:</strong></td><td><?= (int) $pdfCount ?></td></tr>
    </table>

    <?php if ($pdfCount === 0): ?>
        <p class="text-muted">No published SDS PDFs are available to export.</p>
    <?php else: ?>

        <?php if ($existingExport): ?>
            <div class="alert alert-success" style="margin-bottom: 1rem;">
                <strong>Previous export available:</strong>
                <?= e($existingExport['filename']) ?> (<?= e($existingExport['size']) ?>)
                — created <?= e($existingExport['created']) ?>
                — expires in <?= e($existingExport['expires_in']) ?>
                <br>
                <a href="/admin/export/download/<?= e($existingExport['filename']) ?>" class="btn btn-sm btn-primary" style="margin-top: 0.5rem;">Download Previous Export</a>
            </div>
        <?php endif; ?>

        <form id="export-form" style="margin-top: 1rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" id="start-export-btn">Generate New Export</button>
        </form>

        <!-- Progress bar area (hidden initially) -->
        <div id="export-progress" style="display: none; margin-top: 1.5rem;">
            <div style="margin-bottom: 0.5rem;">
                <strong>Export Progress</strong>
                <span id="progress-pct" style="float: right;">0%</span>
            </div>
            <div style="background: #e9ecef; border-radius: 4px; overflow: hidden; height: 24px; position: relative;">
                <div id="progress-bar" style="background: #003366; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>
            </div>
            <p id="progress-message" class="text-muted" style="margin-top: 0.5rem; font-size: 0.85rem;">Preparing...</p>
        </div>

        <!-- Download area (hidden until complete) -->
        <div id="export-complete" style="display: none; margin-top: 1.5rem;">
            <div class="alert alert-success">
                <strong>Export Ready!</strong>
                <span id="complete-message"></span>
                <br>
                <a href="#" id="download-link" class="btn btn-primary" style="margin-top: 0.5rem;">Download ZIP</a>
                <span id="complete-size" class="text-muted" style="margin-left: 0.5rem;"></span>
            </div>
        </div>

        <!-- Error area (hidden unless error) -->
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
    var completeEl = document.getElementById('export-complete');
    var completeMsgEl = document.getElementById('complete-message');
    var downloadLink  = document.getElementById('download-link');
    var completeSizeEl = document.getElementById('complete-size');
    var errorEl    = document.getElementById('export-error');
    var errorMsgEl = document.getElementById('error-message');

    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Starting...';
        progressEl.style.display = 'block';
        completeEl.style.display = 'none';
        errorEl.style.display    = 'none';

        var formData = new FormData(form);

        fetch('/admin/export/start', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.error) {
                showError(data.error);
                return;
            }
            pollProgress(data.token);
        })
        .catch(function(err) {
            showError('Network error: ' + err.message);
        });
    });

    function pollProgress(token) {
        var interval = setInterval(function() {
            fetch('/admin/export/progress/' + token)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.error && !data.total) {
                    clearInterval(interval);
                    showError(data.error);
                    return;
                }

                var pct = data.percent || 0;
                barEl.style.width = pct + '%';
                pctEl.textContent = pct + '%';
                msgEl.textContent = data.message || 'Processing...';

                if (data.complete) {
                    clearInterval(interval);
                    barEl.style.width = '100%';
                    barEl.style.background = '#28a745';
                    pctEl.textContent = '100%';

                    if (data.downloadFile) {
                        completeMsgEl.textContent = data.message;
                        downloadLink.href = '/admin/export/download/' + data.downloadFile;
                        completeSizeEl.textContent = data.fileSize ? '(' + data.fileSize + ')' : '';
                        completeEl.style.display = 'block';
                    }

                    btn.disabled = false;
                    btn.textContent = 'Generate New Export';
                } else if (data.error) {
                    clearInterval(interval);
                    showError(data.message || 'Export failed.');
                }
            })
            .catch(function() {
                // Network blip — keep polling
            });
        }, 500);
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
