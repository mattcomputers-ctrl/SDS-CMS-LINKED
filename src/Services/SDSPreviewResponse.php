<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * Audit #38 — PDF-based SDS preview helpers shared by SDSController
 * (preview / previewResale) and PrivateLabelController (preview / livePreview).
 *
 * One preview route serves three things, chosen by the query string:
 *   page  (default)  the application page: chrome, back link, warnings and an
 *                    <iframe> that loads the same URL with pdf=1
 *   pdf   (?pdf=1)   the real PDF (PDFService::generateString), streamed inline
 *   html  (?html=1)  the legacy HTML rendering (src/Views/sds/preview.php), debug only
 * Previews never write a file.
 */
final class SDSPreviewResponse
{
    public const MODE_PAGE = 'page';
    public const MODE_PDF  = 'pdf';
    public const MODE_HTML = 'html';

    /**
     * @param array<string,mixed> $query  Normally $_GET. pdf=1 wins over html=1;
     *                                    "" and "0" count as absent.
     */
    public static function mode(array $query): string
    {
        if (self::flag($query['pdf'] ?? null)) {
            return self::MODE_PDF;
        }
        if (self::flag($query['html'] ?? null)) {
            return self::MODE_HTML;
        }
        return self::MODE_PAGE;
    }

    /** Same path and query as $requestUri with pdf=1 set and html removed. */
    public static function pdfUrl(string $requestUri): string
    {
        return self::withMode($requestUri, self::MODE_PDF);
    }

    /** Same path and query as $requestUri with html=1 set and pdf removed. */
    public static function htmlUrl(string $requestUri): string
    {
        return self::withMode($requestUri, self::MODE_HTML);
    }

    /**
     * Inline filename: SDS_{code}_draft_{lang}.pdf for an unstamped preview,
     * SDS_{code}_v{n}_{lang}.pdf once meta.sds_version is stamped — the same
     * shape and character set as SDSController::download().
     */
    public static function filename(array $sdsData): string
    {
        $meta = is_array($sdsData['meta'] ?? null) ? $sdsData['meta'] : [];
        $code = (string) preg_replace('/[^A-Za-z0-9_\-]/', '_', strip_pack_extension((string) ($meta['product_code'] ?? '')));
        $lang = (string) preg_replace('/[^a-z]/', '', strtolower((string) ($meta['language'] ?? 'en')));
        if ($lang === '') {
            $lang = 'en';
        }
        $version = (int) ($meta['sds_version'] ?? 0);
        $tail    = $version > 0 ? 'v' . $version : 'draft';

        return 'SDS_' . ($code !== '' ? $code . '_' : '') . $tail . '_' . $lang . '.pdf';
    }

    /**
     * Stream PDF bytes inline and end the request. Always inline (the
     * sds_pdf_download cookie is deliberately ignored: the preview page
     * loads this in an <iframe>). no-store: a draft changes with the data.
     */
    public static function send(string $bytes, string $filename): never
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    private static function flag(mixed $value): bool
    {
        return is_scalar($value) && (string) $value !== '' && (string) $value !== '0';
    }

    private static function withMode(string $requestUri, string $mode): string
    {
        $path  = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $query = [];
        parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?: ''), $query);
        unset($query[self::MODE_PDF], $query[self::MODE_HTML]);
        $query[$mode] = '1';

        return $path . '?' . http_build_query($query);
    }
}
