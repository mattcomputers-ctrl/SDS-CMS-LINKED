<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<div class="toolbar" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <div style="flex: 1;">
        <span class="text-muted">
            <?= (int) $pendingCount ?> product(s) pending review
            (<?= (int) $totalPendingItems ?> total change(s))
            &mdash;
            <?= (int) $recentCompleted ?> completed in the last 30 days
        </span>
    </div>
    <?php if (can_edit('sds_updates')): ?>
        <form method="POST" action="/sds-updates/scan" style="display: inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Scan all products for stale SDS documents? This may take a moment.')">
                Scan for Changes
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($queue)): ?>
    <div class="card" style="text-align: center; padding: 2rem;">
        <p style="font-size: 1.2rem; margin-bottom: 0.5rem;">All SDS documents are up to date.</p>
        <p class="text-muted">Click "Scan for Changes" to check for raw material or formula updates that may require SDS regeneration.</p>
    </div>
<?php else: ?>
    <form id="updateForm" method="POST">
        <?= csrf_field() ?>

        <div class="card" style="margin-bottom: 1rem; padding: 1rem;">
            <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="selectAll"> <strong>Select All</strong>
                </label>
                <span class="text-muted" id="selectedCount">0 selected</span>
                <div style="margin-left: auto; display: flex; gap: 0.5rem;">
                    <?php if (can_edit('sds_updates')): ?>
                        <button type="button" class="btn btn-primary" onclick="submitAction('/sds-updates/republish')"
                                title="Republish standard SDS (+ aliases) for selected products">
                            Republish Selected
                        </button>
                        <button type="button" class="btn btn-outline" onclick="submitAction('/sds-updates/republish-private-label')"
                                title="Republish private label SDSs for selected products">
                            Republish Private Labels
                        </button>
                        <button type="button" class="btn btn-sm" style="color: #888;" onclick="submitAction('/sds-updates/dismiss')"
                                title="Dismiss selected items without republishing">
                            Dismiss
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Product Code</th>
                    <th>Description</th>
                    <th>Reason(s)</th>
                    <th>Aliases</th>
                    <th>Private Labels</th>
                    <th>Last Published</th>
                    <th>Queued</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($queue as $item): ?>
                <tr>
                    <td>
                        <input type="checkbox" name="fg_ids[]" value="<?= (int) $item['finished_good_id'] ?>" class="fg-checkbox">
                    </td>
                    <td>
                        <a href="/sds/<?= (int) $item['finished_good_id'] ?>">
                            <strong><?= e($item['product_code']) ?></strong>
                        </a>
                    </td>
                    <td><?= e($item['fg_description']) ?></td>
                    <td>
                        <?php foreach ($item['reasons'] as $reason): ?>
                            <div class="text-muted" style="font-size: 0.85rem;"><?= e($reason) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($item['alias_count'] > 0): ?>
                            <span class="badge"><?= (int) $item['alias_count'] ?> alias(es)</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['private_label_count'] > 0): ?>
                            <span class="badge"><?= (int) $item['private_label_count'] ?> PL combo(s)</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item['last_sds_published']): ?>
                            <?= format_date($item['last_sds_published'], 'm/d/Y g:i A') ?>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= format_date($item['earliest_queued'], 'm/d/Y g:i A') ?>
                        <?php if (!empty($item['queued_by_name'])): ?>
                            <br><small class="text-muted">by <?= e($item['queued_by_name']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <script>
    (function() {
        var selectAll = document.getElementById('selectAll');
        var checkboxes = document.querySelectorAll('.fg-checkbox');
        var countEl = document.getElementById('selectedCount');

        function updateCount() {
            var checked = document.querySelectorAll('.fg-checkbox:checked').length;
            countEl.textContent = checked + ' selected';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateCount();
            });
        }

        checkboxes.forEach(function(cb) {
            cb.addEventListener('change', updateCount);
        });
    })();

    function submitAction(action) {
        var checked = document.querySelectorAll('.fg-checkbox:checked');
        if (checked.length === 0) {
            alert('Please select at least one product.');
            return;
        }

        var msg = 'Are you sure? This will process ' + checked.length + ' product(s).';
        if (action.indexOf('dismiss') !== -1) {
            msg = 'Dismiss ' + checked.length + ' product(s) from the update queue?';
        } else if (action.indexOf('private-label') !== -1) {
            msg = 'Republish private label SDSs for ' + checked.length + ' product(s)? This may take a while.';
        } else {
            msg = 'Republish standard SDSs (+ aliases) for ' + checked.length + ' product(s)? This may take a while.';
        }

        if (!confirm(msg)) return;

        var form = document.getElementById('updateForm');
        form.action = action;
        form.submit();
    }
    </script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
