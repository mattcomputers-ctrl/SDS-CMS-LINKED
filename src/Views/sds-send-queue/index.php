<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php if (empty($items)): ?>
    <div class="alert alert-success">
        No pending SDS sends. All shipments have been handled.
    </div>
<?php else: ?>

    <p class="text-muted"><?= count($items) ?> shipment(s) need SDS sent to customer regulatory contacts.</p>

    <?php
    // Group by customer
    $grouped = [];
    foreach ($items as $item) {
        $key = (int) $item['customer_id'];
        $grouped[$key]['customer_name'] = $item['customer_name'] ?? $item['ship_to'];
        $grouped[$key]['customer_id']   = $key;
        $grouped[$key]['email']         = $item['regulatory_email'] ?? '';
        $grouped[$key]['items'][]       = $item;
    }
    ?>

    <?php foreach ($grouped as $group): ?>
    <div class="card" style="margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <div>
                <h3 style="margin: 0;"><?= e($group['customer_name']) ?></h3>
                <small class="text-muted"><?= e($group['email']) ?></small>
            </div>
            <?php if (can_edit('sds_send_queue')): ?>
            <form method="POST" action="/sds-send-queue/send-customer" onsubmit="return confirm('Send all pending SDSs for this customer?');">
                <?= csrf_field() ?>
                <input type="hidden" name="customer_id" value="<?= $group['customer_id'] ?>">
                <button type="submit" class="btn btn-primary">Send All (<?= count($group['items']) ?>)</button>
            </form>
            <?php endif; ?>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>Shipment Date</th>
                    <th>Item Code</th>
                    <th>Alias</th>
                    <th>Description</th>
                    <th>Reason</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($group['items'] as $item): ?>
                <tr>
                    <td><?= e($item['shipment_date'] ?? '') ?></td>
                    <td><strong><?= e($item['item_code'] ?? '') ?></strong></td>
                    <td><?= e($item['item_name'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($item['item_description'] ?? '') ?></td>
                    <td><small class="text-muted"><?= e($item['reason'] ?? '') ?></small></td>
                    <td style="white-space: nowrap;">
                        <?php if (can_edit('sds_send_queue')): ?>
                        <form method="POST" action="/sds-send-queue/<?= (int) $item['id'] ?>/send" style="display: inline;">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-primary">Send</button>
                        </form>
                        <form method="POST" action="/sds-send-queue/<?= (int) $item['id'] ?>/dismiss" style="display: inline;" onsubmit="return confirm('Dismiss this item?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline">Dismiss</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php if ($sentCount > 0): ?>
<p class="text-muted"><?= $sentCount ?> item(s) previously sent or dismissed.</p>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
