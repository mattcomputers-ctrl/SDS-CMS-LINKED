<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/finished-goods/<?= (int) $finishedGood['id'] ?>/edit">&larr; Back to <?= e($finishedGood['product_code']) ?></a></p>

<div class="toolbar">
    <div>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview" class="btn btn-outline">Preview (EN)</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=es" class="btn btn-outline">Preview (ES)</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=fr" class="btn btn-outline">Preview (FR)</a>
    </div>
    <?php if (is_editor()): ?>
    <form method="POST" action="/sds/<?= (int) $finishedGood['id'] ?>/publish" class="inline-form">
        <?= csrf_field() ?>
        <select name="language">
            <option value="en">English</option>
            <option value="es">Spanish</option>
            <option value="fr">French</option>
        </select>
        <input type="text" name="change_summary" placeholder="Change summary (optional)" class="input-md">
        <button type="submit" class="btn btn-primary" onclick="return confirm('Publish this SDS? This will generate a PDF and create a new version.')">Publish</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($versions)): ?>
    <div class="card">
        <p class="text-muted">No SDS versions published yet. Use "Preview" to review, then "Publish" to create the first version.</p>
    </div>
<?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Version</th><th>Language</th><th>Status</th><th>Published By</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
            <tr>
                <td>v<?= (int) $v['version'] ?></td>
                <td><?= e(strtoupper($v['language'])) ?></td>
                <td><span class="badge badge-<?= $v['status'] ?>"><?= e($v['status']) ?></span></td>
                <td><?= e($v['published_by_name'] ?? $v['created_by_name'] ?? '—') ?></td>
                <td><?= format_date($v['published_at'] ?? $v['created_at'], 'm/d/Y H:i') ?></td>
                <td>
                    <?php if ($v['pdf_path']): ?>
                        <a href="/sds/download/<?= (int) $v['id'] ?>" class="btn btn-sm">Download PDF</a>
                    <?php endif; ?>
                    <a href="/sds/trace/<?= (int) $v['id'] ?>" class="btn btn-sm btn-outline">Trace</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
