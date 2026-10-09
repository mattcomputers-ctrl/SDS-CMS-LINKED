<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * SDSDocumentStrings — the one place that knows the document-level strings
 * (title banner, "SECTION" prefix, footer "Page x of y", "Rev.") and their
 * English fallbacks (audit #39/#40).
 *
 * SDSGenerator::getDocumentStrings() translates every key listed here into
 * meta.document; the renderers (PDFService, SDSTcpdf, sds/preview.php) read
 * meta.document through resolve(), which falls back to DEFAULTS only for
 * snapshots generated before meta.document existed.
 */
final class SDSDocumentStrings
{
    /** key => English fallback; keys are the templates/translations/*.php 'document.*' keys. */
    public const DEFAULTS = [
        'title'           => 'SAFETY DATA SHEET',
        'section_prefix'  => 'SECTION',
        'page'            => 'Page',
        'page_of'         => 'of',
        'revision_prefix' => 'Rev.',
    ];

    /**
     * @param array  $document  meta.document from the SDS data array (may be empty / missing keys)
     */
    public static function resolve(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return self::DEFAULTS[$key] ?? $key;
    }
}
