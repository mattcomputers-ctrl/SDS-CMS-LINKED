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
        // #37 PDF document properties (TCPDF SetTitle / SetSubject); :code = product code
        'pdf_title'       => 'SDS - :code',
        'pdf_subject'     => 'Safety Data Sheet',
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

    /** @var array<string,TranslationService> one translator per sheet language (translate()) */
    private static array $translators = [];

    /**
     * Renderer fallback for a string the snapshot does not carry (audit #37):
     * looks the dot-notation key up in the sheet language's translation file
     * (TranslationService falls back to EN for a key missing there), so a
     * snapshot generated before a label was added to meta.labels still prints
     * in its own language instead of an English PHP literal.
     *
     * @param  string $language  meta.language of the sheet ('en', 'es', ...)
     * @param  string $key       e.g. 'labels.uv_acrylate_note', 'section2.not_classified'
     * @return string|null       null when no translation file defines the key
     */
    public static function translate(string $language, string $key, array $replacements = []): ?string
    {
        $language = $language !== '' ? $language : 'en';
        $t = self::$translators[$language] ??= new TranslationService($language);
        $text = $t->get($key, $replacements);
        return $text === $key ? null : $text;
    }
}
