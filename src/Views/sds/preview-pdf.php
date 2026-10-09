<?php
/**
 * Audit #38 — PDF-based SDS preview.
 *
 * Wraps the real PDF (the bytes PDFService renders for publishing, served
 * inline by this same route with ?pdf=1) in the application layout so the
 * page title, back link and the generator Warnings box survive. The legacy
 * HTML rendering (sds/preview.php) stays reachable with ?html=1 for debugging
 * only — it is not what the customer receives.
 */
include dirname(__DIR__) . '/layouts/main.php';

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$pdfUrl     = \SDS\Services\SDSPreviewResponse::pdfUrl($requestUri);
$htmlUrl    = \SDS\Services\SDSPreviewResponse::htmlUrl($requestUri);
$isDraft    = (int) ($sds['meta']['sds_version'] ?? 0) === 0;
?>

<?php if (!empty($backUrl)): ?>
    <p>
        <a href="<?= e($backUrl) ?>">&larr; <?= e($backLabel ?? 'Back') ?></a>
        <?php if (!empty($livePreviewUrl)): ?>
            &nbsp;|&nbsp; <a href="<?= e($livePreviewUrl) ?>" title="Regenerate this document from current data (not the stored snapshot)">Live preview with current data</a>
        <?php endif; ?>
    </p>
<?php elseif (!empty($finishedGood['id'])): ?>
    <p><a href="/sds/<?= (int) $finishedGood['id'] ?>">&larr; Back to SDS Versions</a></p>
<?php elseif (!empty($privateLabelId)): ?>
    <p><a href="/private-label">&larr; Back to Private Label SDS</a></p>
<?php endif; ?>

<?php if (!empty($sds['warnings'])): ?>
<div class="alert alert-warning">
    <strong>Warnings:</strong>
    <ul>
    <?php foreach ($sds['warnings'] as $w): ?>
        <li><?= e($w) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="sds-preview-pdf" style="max-width: 1100px; margin: 0 auto;">
    <p class="text-muted" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 0.5rem;">
        <span>
            <?= e(strtoupper($language)) ?> &mdash;
            <?php if ($isDraft): ?>
                Draft: this is the PDF Publish will produce; publishing only stamps the version number and effective date (Section 16 and the footer).
            <?php else: ?>
                Rendered from the stored snapshot of v<?= (int) $sds['meta']['sds_version'] ?>.
            <?php endif; ?>
        </span>
        <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">Open PDF in new tab</a>
        <?php if (!empty($storedPdfUrl)): ?>
            <a href="<?= e($storedPdfUrl) ?>" class="btn btn-sm btn-outline pdf-link">Stored PDF file</a>
        <?php endif; ?>
        <a href="<?= e($htmlUrl) ?>" style="font-size: 0.8rem;" title="Legacy HTML rendering — debugging only, not the customer document">HTML view (debug)</a>
    </p>
    <iframe id="sdsPreviewFrame" src="<?= e($pdfUrl) ?>" title="SDS preview (PDF)"
            style="width: 100%; height: calc(100vh - 230px); min-height: 600px; border: 1px solid var(--border); background: #fff;"></iframe>
    <p class="text-muted" style="font-size: 0.8rem; margin-top: 0.5rem;">If the document does not appear, your browser has no built-in PDF viewer &mdash; use "Open PDF in new tab".</p>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
