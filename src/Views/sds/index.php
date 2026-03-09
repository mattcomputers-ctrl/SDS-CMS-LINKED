<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<p><a href="/finished-goods/<?= (int) $finishedGood['id'] ?>/edit">&larr; Back to <?= e($finishedGood['product_code']) ?></a></p>

<div class="toolbar">
    <div>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview" class="btn btn-outline">Preview (EN)</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=es" class="btn btn-outline">Preview (ES)</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=fr" class="btn btn-outline">Preview (FR)</a>
        <a href="/sds/<?= (int) $finishedGood['id'] ?>/preview?lang=de" class="btn btn-outline">Preview (DE)</a>
        <?php if (can_edit('sds')): ?>
            | <a href="/sds/<?= (int) $finishedGood['id'] ?>/edit" class="btn btn-outline">Edit (EN)</a>
            <a href="/sds/<?= (int) $finishedGood['id'] ?>/edit?lang=es" class="btn btn-outline">Edit (ES)</a>
            <a href="/sds/<?= (int) $finishedGood['id'] ?>/edit?lang=fr" class="btn btn-outline">Edit (FR)</a>
            <a href="/sds/<?= (int) $finishedGood['id'] ?>/edit?lang=de" class="btn btn-outline">Edit (DE)</a>
        <?php endif; ?>
    </div>
    <?php if (can_edit('sds')): ?>
    <form method="POST" action="/sds/<?= (int) $finishedGood['id'] ?>/publish" class="inline-form">
        <?= csrf_field() ?>
        <input type="text" name="change_summary" placeholder="Change summary (optional)" class="input-md">
        <button type="submit" class="btn btn-primary" onclick="return confirm('Publish SDS for all languages (EN, ES, FR, DE)? This will generate a PDF for each language and create a new version.')">Publish All Languages</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($versions)): ?>
    <div class="card">
        <p class="text-muted">No SDS versions published yet. Use "Preview" to review, then "Publish" to create the first version.</p>
    </div>
<?php else: ?>
    <?php
        // Group versions by version number
        $grouped = [];
        foreach ($versions as $v) {
            $ver = (int) $v['version'];
            if (!isset($grouped[$ver])) {
                $grouped[$ver] = [
                    'version'     => $ver,
                    'status'      => $v['status'],
                    'published_by' => $v['published_by_name'] ?? $v['created_by_name'] ?? '—',
                    'date'        => $v['published_at'] ?? $v['created_at'],
                    'change_summary' => $v['change_summary'] ?? '',
                    'languages'   => [],
                ];
            }
            $grouped[$ver]['languages'][$v['language']] = $v;
        }
    ?>
    <table class="table">
        <thead>
            <tr><th>Version</th><th>Status</th><th>Published By</th><th>Date</th><th>Downloads</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($grouped as $ver => $group): ?>
            <tr>
                <td>v<?= $ver ?></td>
                <td><span class="badge badge-<?= $group['status'] ?>"><?= e($group['status']) ?></span></td>
                <td><?= e($group['published_by']) ?></td>
                <td><?= format_date($group['date'], 'm/d/Y H:i') ?></td>
                <td>
                    <?php
                        $langLabels = ['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German'];
                        $links = [];
                        foreach ($langLabels as $langCode => $langName) {
                            if (isset($group['languages'][$langCode]) && $group['languages'][$langCode]['pdf_path']) {
                                $links[] = '<a href="/sds/version/' . (int) $group['languages'][$langCode]['id'] . '/download" class="btn btn-sm">Download ' . $langName . ' PDF</a>';
                            }
                        }
                        echo implode(' ', $links);
                    ?>
                </td>
                <td>
                    <?php
                        // Use the first available language record for trace
                        $firstLangVersion = reset($group['languages']);
                    ?>
                    <a href="/sds/version/<?= (int) $firstLangVersion['id'] ?>/trace" class="btn btn-sm btn-outline">Trace</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
