<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p class="text-muted mb-1">Generate and publish a new SDS version for every active finished good (all languages). This creates a new version for each product — existing versions are preserved.</p>

<div class="card" style="max-width: 700px;">
    <h2>Publish Summary</h2>
    <table class="table table-sm" style="max-width: 400px;">
        <tr><td><strong>Active Finished Goods with Formulas:</strong></td><td><?= (int) $fgCount ?></td></tr>
        <tr><td><strong>Languages:</strong></td><td><?= (int) $langCount ?> (<?= e(strtoupper(implode(', ', $languages))) ?>)</td></tr>
        <tr><td><strong>Total PDFs to Generate:</strong></td><td><?= (int) $fgCount * (int) $langCount ?></td></tr>
    </table>

    <?php if ($fgCount === 0): ?>
        <p class="text-muted">No active finished goods with formulas found.</p>
    <?php else: ?>

        <form id="publish-form" style="margin-top: 1rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" id="start-btn">Publish All SDS</button>
        </form>

        <!-- Progress bar area (hidden initially) -->
        <div id="publish-progress" style="display: none; margin-top: 1.5rem;">
            <div style="margin-bottom: 0.5rem;">
                <strong>Publish Progress</strong>
                <span id="progress-pct" style="float: right;">0%</span>
            </div>
            <div style="background: #e9ecef; border-radius: 4px; overflow: hidden; height: 24px; position: relative;">
                <div id="progress-bar" style="background: #003366; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>
            </div>
            <p id="progress-message" class="text-muted" style="margin-top: 0.5rem; font-size: 0.85rem;">Preparing...</p>
        </div>

        <!-- Complete area (hidden until done) -->
        <div id="publish-complete" style="display: none; margin-top: 1.5rem;">
            <div class="alert alert-success">
                <strong>Bulk Publish Complete!</strong>
                <span id="complete-message"></span>
            </div>
            <div id="publish-errors" style="display: none; margin-top: 0.5rem;">
                <div class="alert alert-danger">
                    <strong>Some products failed:</strong>
                    <ul id="error-list" style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;"></ul>
                </div>
            </div>
        </div>

        <!-- Error area (hidden unless error) -->
        <div id="publish-error" style="display: none; margin-top: 1.5rem;">
            <div class="alert alert-danger">
                <strong>Publish Failed:</strong> <span id="error-message"></span>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
(function() {
    'use strict';

    var form       = document.getElementById('publish-form');
    var btn        = document.getElementById('start-btn');
    var progressEl = document.getElementById('publish-progress');
    var barEl      = document.getElementById('progress-bar');
    var pctEl      = document.getElementById('progress-pct');
    var msgEl      = document.getElementById('progress-message');
    var completeEl = document.getElementById('publish-complete');
    var completeMsgEl = document.getElementById('complete-message');
    var errorsEl   = document.getElementById('publish-errors');
    var errorListEl = document.getElementById('error-list');
    var errorEl    = document.getElementById('publish-error');
    var errorMsgEl = document.getElementById('error-message');

    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Starting...';
        progressEl.style.display = 'block';
        completeEl.style.display = 'none';
        errorEl.style.display    = 'none';
        errorsEl.style.display   = 'none';

        var formData = new FormData(form);

        fetch('/admin/bulk-publish/start', {
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
            fetch('/admin/bulk-publish/progress/' + token)
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

                    completeMsgEl.textContent = ' ' + (data.published || 0) + ' products published'
                        + (data.failed > 0 ? ', ' + data.failed + ' failed' : '') + '.';
                    completeEl.style.display = 'block';

                    // Show error details if any
                    if (data.errors && data.errors.length > 0) {
                        errorListEl.innerHTML = '';
                        data.errors.forEach(function(err) {
                            var li = document.createElement('li');
                            li.textContent = err;
                            errorListEl.appendChild(li);
                        });
                        errorsEl.style.display = 'block';
                    }

                    btn.disabled = false;
                    btn.textContent = 'Publish All SDS';
                } else if (data.error) {
                    clearInterval(interval);
                    showError(data.message || 'Bulk publish failed.');
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
        btn.textContent = 'Publish All SDS';
    }
})();
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
