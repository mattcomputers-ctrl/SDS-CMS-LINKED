<?php include dirname(__DIR__) . '/layouts/main.php'; ?>

<?php
$mid      = (int) $item['manufacturer_id'];
$itemId   = (int) $item['id'];
$isActive = (int) $item['is_active'] === 1;
$canEdit  = can_edit('private_label');
$identity = $row['identity'];
$status   = $row['status'];
$statusCode = (string) ($status['code'] ?? 'unknown');

$statusBadge = [
    'current' => 'badge-success',
    'stale'   => 'badge-draft',
    'never'   => 'badge-muted',
    'unknown' => 'badge-muted',
    'retired' => 'badge-muted',
];
$sourceLabels = ['custom' => 'Custom code', 'shared_alias' => 'Shared alias', 'base' => 'Base product'];
$langList     = implode(', ', array_map('strtoupper', $languages));

// Group the per-language rows by version number (sds/index.php pattern).
// The frozen identity and provenance are taken from the first row of the
// version that carries them.
$grouped = [];
foreach ($versions as $v) {
    $ver = (int) $v['version'];
    if (!isset($grouped[$ver])) {
        $grouped[$ver] = [
            'version'             => $ver,
            'product_code'        => null,
            'product_description' => null,
            'source_fg_version'   => null,
            'published_by'        => $v['published_by_name'] ?? null,
            'date'                => $v['published_at'] ?? $v['created_at'] ?? null,
            'change_summary'      => (string) ($v['change_summary'] ?? ''),
            'languages'           => [],
        ];
    }
    if ($grouped[$ver]['product_code'] === null && !empty($v['product_code'])) {
        $grouped[$ver]['product_code'] = (string) $v['product_code'];
    }
    if ($grouped[$ver]['product_description'] === null && !empty($v['product_description'])) {
        $grouped[$ver]['product_description'] = (string) $v['product_description'];
    }
    if ($grouped[$ver]['source_fg_version'] === null && isset($v['source_fg_version']) && $v['source_fg_version'] !== null) {
        $grouped[$ver]['source_fg_version'] = (int) $v['source_fg_version'];
    }
    if ($grouped[$ver]['published_by'] === null && !empty($v['published_by_name'])) {
        $grouped[$ver]['published_by'] = $v['published_by_name'];
    }
    if ($grouped[$ver]['change_summary'] === '' && !empty($v['change_summary'])) {
        $grouped[$ver]['change_summary'] = (string) $v['change_summary'];
    }
    $grouped[$ver]['languages'][(string) $v['language']] = $v;
}
?>

<p><a href="/private-label/manufacturer/<?= $mid ?>">&larr; Back to <?= e((string) ($item['manufacturer_name'] ?? 'manufacturer')) ?></a></p>

<div class="card">
    <div class="card-header">
        <div>
            <h2 style="margin: 0 0 0.25rem 0;">
                <?= e($identity['code']) ?>
                <span class="badge badge-muted" title="Identity source"><?= e($sourceLabels[$identity['source']] ?? $identity['source']) ?></span>
                <?php if (!$isActive): ?><span class="badge badge-muted">Retired</span><?php endif; ?>
                <?php if ((int) $item['auto_republish'] !== 1): ?><span class="badge badge-muted" title="Skipped by the base-SDS cascade and bulk publish; only Republish regenerates it">Frozen</span><?php endif; ?>
            </h2>
            <div><?= e($identity['description']) ?></div>
            <div class="text-muted" style="margin-top: 0.35rem;">
                Manufacturer: <strong><?= e((string) ($item['manufacturer_name'] ?? '')) ?></strong>
                &nbsp;|&nbsp; Base product: <a href="/sds/<?= (int) $item['finished_good_id'] ?>"><?= e((string) ($item['fg_product_code'] ?? '')) ?></a>
                <?php if (!empty($item['fg_description'])): ?><small>(<?= e($item['fg_description']) ?>)</small><?php endif; ?>
                <?php if ($row['fg_latest_version'] !== null): ?>
                    <small>— latest base SDS v<?= (int) $row['fg_latest_version'] ?></small>
                <?php else: ?>
                    <small>— no published base SDS</small>
                <?php endif; ?>
            </div>
            <div style="margin-top: 0.5rem;">
                <span class="badge <?= $statusBadge[$statusCode] ?? 'badge-muted' ?>" title="<?= e((string) ($status['reason'] ?? '')) ?>"><?= e($status['label'] ?? ucfirst($statusCode)) ?></span>
                <?php if (!empty($status['reason'])): ?><small class="text-muted"><?= e($status['reason']) ?></small><?php endif; ?>
            </div>
            <?php if (!empty($item['notes'])): ?>
                <div class="text-muted" style="margin-top: 0.35rem;"><small>Notes: <?= e($item['notes']) ?></small></div>
            <?php endif; ?>
        </div>
        <div style="white-space: nowrap;">
            <a href="/private-label/documents?item_id=<?= $itemId ?>" class="btn btn-sm btn-outline">Flat view</a>
            <?php if ($canEdit): ?>
                <a href="/private-label/items/<?= $itemId ?>/edit" class="btn btn-sm">Edit</a>
                <?php if ($isActive): ?>
                    <form method="POST" action="/private-label/items/<?= $itemId ?>/publish" style="display: inline;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-primary"
                                onclick="return confirm(<?= e(json_encode((empty($grouped) ? 'Publish' : 'Republish') . ' private label SDS for ' . $identity['code'] . ' / ' . ($item['manufacturer_name'] ?? '') . '? A PDF is generated for each language (' . $langList . ') from the latest published base SDS.')) ?>)"><?= empty($grouped) ? 'Publish' : 'Republish' ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<h3>Versions</h3>

<?php if (empty($grouped)): ?>
    <div class="card">
        <p class="text-muted">No private label SDS has been published for this item yet.<?= $isActive ? ' Use Publish to generate the first version.' : '' ?></p>
    </div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table">
        <thead>
            <tr>
                <th>Version</th>
                <th>Printed as</th>
                <th>From base</th>
                <th>Published</th>
                <th>Change summary</th>
                <th>Documents</th>
                <th>Preview</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grouped as $ver => $g): ?>
            <tr>
                <td>v<?= (int) $ver ?></td>
                <td>
                    <strong><?= e($g['product_code'] ?? $identity['code']) ?></strong>
                    <?php if ($g['product_description'] !== null): ?>
                        <br><small class="text-muted"><?= e($g['product_description']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($g['source_fg_version'] !== null): ?>
                        v<?= (int) $g['source_fg_version'] ?>
                    <?php else: ?>
                        <span class="text-muted" title="Legacy version — base SDS version unknown">unknown</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= !empty($g['date']) ? format_date((string) $g['date'], 'm/d/Y H:i') : '&mdash;' ?>
                    <br><small class="text-muted">by <?= e((string) ($g['published_by'] ?? 'system')) ?></small>
                </td>
                <td><?= e($g['change_summary']) ?></td>
                <td>
                    <?php foreach ($languages as $lang): ?>
                        <?php $name = $langNames[$lang] ?? strtoupper($lang); ?>
                        <?php if (isset($g['languages'][$lang])): ?>
                            <?php $plRow = $g['languages'][$lang]; ?>
                            <?php if (!empty($plRow['pdf_path'])): ?>
                                <a href="/private-label/<?= (int) $plRow['id'] ?>/download" class="btn btn-sm pdf-link"><?= e($name) ?> PDF</a>
                            <?php else: ?>
                                <span class="badge badge-muted"><?= e($name) ?> — no PDF</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-muted" title="This language was not generated at v<?= (int) $ver ?>"><?= e($name) ?> — missing</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ($g['languages'] as $lang => $plRow): ?>
                        <?php if (in_array($lang, $languages, true) || empty($plRow['pdf_path'])) continue; ?>
                        <a href="/private-label/<?= (int) $plRow['id'] ?>/download" class="btn btn-sm pdf-link" title="Language no longer configured"><?= e(strtoupper((string) $lang)) ?> PDF</a>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php
                        $first = null;
                        foreach ($languages as $lang) {
                            if (isset($g['languages'][$lang])) {
                                $first = $g['languages'][$lang];
                                break;
                            }
                        }
                        if ($first === null) {
                            $first = reset($g['languages']);
                        }
                    ?>
                    <?php if ($first): ?>
                        <a href="/private-label/<?= (int) $first['id'] ?>/preview" class="btn btn-sm btn-outline" target="_blank" title="Published snapshot">Preview</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
