<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="d-flex justify-between align-center mb-1">
    <h2 style="margin:0;">Bulk SDS Publish</h2>
    <a href="/bulk-publish/queue" class="btn btn-outline">View Queue</a>
</div>

<p class="text-muted mb-1">Generate and publish a new SDS version for every eligible finished good <em>and</em> resale item (all languages). An item is eligible only when:</p>
<ul class="text-muted" style="margin-top: 0; margin-bottom: 0.75rem;">
    <li><strong>Every</strong> raw material in its formula (or for a resale item, the source raw material itself) has been edited by a user at least once (any audit-log entry qualifies — even just adding a note). RMs that have only been imported from CMS and never reviewed block their item.</li>
    <li>The item <strong>doesn't already have an up-to-date SDS</strong>. If the latest published SDS was published after the most recent change to the formula, raws, constituents, or CAS determinations, there's nothing to republish and it's skipped.</li>
</ul>

<?php $totalEligible = (int) $fgCount + (int) ($resaleCount ?? 0); ?>
<?php $totalAliases  = (int) ($aliasCount ?? 0) + (int) ($resaleAliasCount ?? 0); ?>

<div class="card" style="max-width: 700px;">
    <h2>Publish Summary</h2>
    <table class="table table-sm" style="max-width: 500px;">
        <tr><td><strong>Eligible Finished Goods (all raws user-reviewed):</strong></td><td><?= (int) $fgCount ?></td></tr>
        <?php if (($blockedCount ?? 0) > 0): ?>
        <tr><td><strong>Blocked FGs (at least one raw is unreviewed):</strong></td><td><?= (int) $blockedCount ?></td></tr>
        <?php endif; ?>
        <?php if (($aliasCount ?? 0) > 0): ?>
        <tr><td><strong>Aliases (for eligible FGs):</strong></td><td><?= (int) $aliasCount ?></td></tr>
        <?php endif; ?>
        <?php if (($resaleCount ?? 0) > 0 || ($resaleBlockedCount ?? 0) > 0 || ($resaleAliasCount ?? 0) > 0): ?>
        <tr><td colspan="2" style="padding-top: 0.75rem; font-weight: 600;">Resale items (sold as-is, 100% of source RM):</td></tr>
        <tr><td><strong>&nbsp;&nbsp;Eligible resale RMs:</strong></td><td><?= (int) ($resaleCount ?? 0) ?></td></tr>
            <?php if (($resaleBlockedCount ?? 0) > 0): ?>
        <tr><td><strong>&nbsp;&nbsp;Blocked resale RMs (unreviewed):</strong></td><td><?= (int) $resaleBlockedCount ?></td></tr>
            <?php endif; ?>
            <?php if (($resaleAliasCount ?? 0) > 0): ?>
        <tr><td><strong>&nbsp;&nbsp;Resale aliases:</strong></td><td><?= (int) $resaleAliasCount ?></td></tr>
            <?php endif; ?>
        <?php endif; ?>
        <tr><td><strong>Languages:</strong></td><td><?= (int) $langCount ?> (<?= e(strtoupper(implode(', ', $languages))) ?>)</td></tr>
        <tr><td><strong>Total PDFs to Generate:</strong></td><td><?= ($totalEligible + $totalAliases) * (int) $langCount ?></td></tr>
    </table>

    <?php if ($totalEligible === 0): ?>
        <p class="text-muted">No eligible items. Edit the raw materials used in your formulas (any save counts) to make them eligible.</p>
    <?php else: ?>

        <form id="publish-form" style="margin-top: 1rem;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" id="start-btn">Publish All SDS</button>
        </form>

        <!-- Progress bar area (hidden initially) -->
        <div id="publish-progress" style="display: none; margin-top: 1.5rem;">
            <div style="margin-bottom: 0.5rem;">
                <strong>Publish Progress</strong>
                <button type="button" id="stop-btn" class="btn btn-sm btn-danger" style="float: right; margin-left: 0.75rem;">Stop</button>
                <span id="progress-pct" style="float: right;">0%</span>
            </div>
            <div style="background: #e9ecef; border-radius: 4px; overflow: hidden; height: 24px; position: relative;">
                <div id="progress-bar" style="background: #003366; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 4px;"></div>
            </div>
            <p id="progress-message" class="text-muted" style="margin-top: 0.5rem; font-size: 0.85rem;">Preparing...</p>
        </div>

        <!-- Complete area (hidden until done) -->
        <div id="publish-complete" style="display: none; margin-top: 1.5rem;">
            <div id="complete-alert" class="alert alert-success">
                <strong id="complete-heading">Bulk Publish Complete!</strong>
                <span id="complete-message"></span>
            </div>
            <div id="publish-errors" style="display: none; margin-top: 0.5rem;">
                <div class="alert alert-danger">
                    <strong>Some items failed:</strong>
                    <ul id="error-list" style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;"></ul>
                </div>
            </div>
            <div id="publish-logs" style="display: none; margin-top: 0.5rem;">
                <div class="alert alert-danger" style="padding-bottom: 0;">
                    <strong>Worker Logs</strong>
                    <div id="log-entries" style="margin-top: 0.5rem;"></div>
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

    var form           = document.getElementById('publish-form');
    var btn            = document.getElementById('start-btn');
    var stopBtn        = document.getElementById('stop-btn');
    var progressEl     = document.getElementById('publish-progress');
    var barEl          = document.getElementById('progress-bar');
    var pctEl          = document.getElementById('progress-pct');
    var msgEl          = document.getElementById('progress-message');
    var completeEl     = document.getElementById('publish-complete');
    var completeAlert  = document.getElementById('complete-alert');
    var completeHeading = document.getElementById('complete-heading');
    var completeMsgEl  = document.getElementById('complete-message');
    var errorsEl       = document.getElementById('publish-errors');
    var errorListEl    = document.getElementById('error-list');
    var logsEl         = document.getElementById('publish-logs');
    var logEntriesEl   = document.getElementById('log-entries');
    var errorEl        = document.getElementById('publish-error');
    var errorMsgEl     = document.getElementById('error-message');

    if (!form) return;

    // The start button enqueues a job through BulkPublishQueue and
    // returns its id. We then poll /bulk-publish/queue/{id}/status —
    // reading job row fields instead of token-based progress files —
    // so the UI works the same whether we're the runner or queued
    // behind another active run.
    var currentJobId = null;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Starting...';
        progressEl.style.display = 'block';
        completeEl.style.display = 'none';
        errorEl.style.display    = 'none';
        errorsEl.style.display   = 'none';
        logsEl.style.display     = 'none';
        msgEl.textContent = 'Queuing job...';
        barEl.style.width = '0%';
        barEl.style.background = '#003366';
        pctEl.textContent = '0%';

        var formData = new FormData(form);

        fetch('/bulk-publish/start', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.error) {
                showError(data.error);
                return;
            }
            currentJobId = data.job_id;
            pollJobStatus(currentJobId);
        })
        .catch(function(err) {
            showError('Network error: ' + err.message);
        });
    });

    // Stop button = force-fail the running job + SIGTERM any surviving
    // workers. Takes effect via the queue's force-fail endpoint.
    if (stopBtn) {
        stopBtn.addEventListener('click', function() {
            if (!currentJobId) return;
            if (!confirm('Stop this bulk publish?\n\nAlready-published SDSs are kept. Surviving publish-worker processes are sent SIGTERM — each finishes its current item before exiting.')) return;
            stopBtn.disabled   = true;
            stopBtn.textContent = 'Stopping…';

            var fd = new FormData();
            var csrf = document.querySelector('input[name="_csrf_token"]');
            if (csrf) fd.append('_csrf_token', csrf.value);

            fetch('/bulk-publish/queue/' + currentJobId + '/force-fail', {
                method: 'POST',
                body:   fd,
                redirect: 'manual'
            })
            .catch(function () { /* redirect on success, ignore */ })
            .finally(function () {
                // Poll will pick up the 'failed' status on next tick.
            });
        });
    }

    function pollJobStatus(jobId) {
        var interval = setInterval(function () {
            fetch('/bulk-publish/queue/' + jobId + '/status')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    clearInterval(interval);
                    showError(data.error);
                    return;
                }
                var job = data.job || {};
                var status   = job.status || 'pending';
                var total    = parseInt(job.work_items_count || 0, 10);
                var pub      = parseInt(job.published_count  || 0, 10);
                var failed   = parseInt(job.failed_count     || 0, 10);
                var done     = pub + failed;
                var pct      = total > 0 ? Math.round((done / total) * 1000) / 10 : 0;

                barEl.style.width = pct + '%';
                pctEl.textContent = pct + '%';
                msgEl.textContent = buildStatusMessage(status, job, done, total, pub, failed);

                if (status === 'completed' || status === 'failed') {
                    clearInterval(interval);
                    var hasProblem = (status === 'failed') || failed > 0;

                    barEl.style.width = '100%';
                    barEl.style.background = hasProblem ? '#dc3545' : '#28a745';
                    pctEl.textContent = '100%';

                    completeHeading.textContent = (status === 'failed') ? 'Bulk Publish Failed' : 'Bulk Publish Complete!';
                    completeAlert.className = 'alert ' + (hasProblem ? 'alert-danger' : 'alert-success');
                    completeMsgEl.textContent = ' ' + pub + ' PDFs published'
                        + (failed > 0 ? ', ' + failed + ' failed' : '') + '.';
                    completeEl.style.display = 'block';

                    if (job.error_message) {
                        errorListEl.innerHTML = '';
                        var li = document.createElement('li');
                        li.textContent = job.error_message;
                        errorListEl.appendChild(li);
                        errorsEl.style.display = 'block';
                    }

                    btn.disabled = false;
                    btn.textContent = 'Publish All SDS';
                }
            })
            .catch(function () { /* network blip — keep polling */ });
        }, 2000);
    }

    function buildStatusMessage(status, job, done, total, pub, failed) {
        if (status === 'pending') {
            return 'Queued as job #' + job.id + ' — waiting for an available runner.';
        }
        if (status === 'running') {
            if (total > 0) {
                return 'Running job #' + job.id + ': ' + done + ' / ' + total + ' items ('
                    + pub + ' published, ' + failed + ' failed).';
            }
            return 'Running job #' + job.id + ': computing eligibility...';
        }
        if (status === 'completed') return 'Job #' + job.id + ' completed.';
        if (status === 'failed')    return 'Job #' + job.id + ' failed: ' + (job.error_message || 'see logs');
        return 'Job #' + job.id + ' — ' + status;
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
