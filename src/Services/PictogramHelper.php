<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * PictogramHelper — Resolves pictogram images for PDF and preview rendering.
 *
 * Lookup order:
 *   1. Admin-uploaded file:  /public/uploads/pictograms/{CODE}.png (or .jpg/.gif)
 *   2. Default shipped file: /public/assets/pictograms/png/{CODE}.png
 *
 * Pictogram codes:
 *   - GHS01–GHS09:  GHS hazard pictograms (red diamond)
 *   - PPE-eye, PPE-hand, PPE-respiratory, PPE-skin:  PPE pictograms (blue circle)
 *   - PROP65:       California Proposition 65 warning triangle
 */
class PictogramHelper
{
    /** Admin upload directory relative to base path. */
    private const UPLOAD_DIR = '/public/uploads/pictograms';

    /** Default shipped pictograms directory relative to base path. */
    private const DEFAULT_DIR = '/public/assets/pictograms/png';

    /** All recognized pictogram codes. */
    public const ALL_CODES = [
        'GHS01', 'GHS02', 'GHS03', 'GHS04', 'GHS05',
        'GHS06', 'GHS07', 'GHS08', 'GHS09',
        'PPE-eye', 'PPE-hand', 'PPE-respiratory', 'PPE-skin',
        'PROP65',
    ];

    /** Human-readable names for each code. */
    public const NAMES = [
        'GHS01' => 'Exploding Bomb',
        'GHS02' => 'Flame',
        'GHS03' => 'Flame Over Circle',
        'GHS04' => 'Gas Cylinder',
        'GHS05' => 'Corrosion',
        'GHS06' => 'Skull and Crossbones',
        'GHS07' => 'Exclamation Mark',
        'GHS08' => 'Health Hazard',
        'GHS09' => 'Environment',
        'PPE-eye'         => 'Wear Eye Protection',
        'PPE-hand'        => 'Wear Gloves',
        'PPE-respiratory' => 'Wear Respiratory Protection',
        'PPE-skin'        => 'Wear Protective Clothing',
        'PROP65'          => 'Proposition 65 Warning',
    ];

    /**
     * Get the absolute filesystem path to a pictogram PNG.
     *
     * Checks for an admin-uploaded file first, then falls back to the default.
     * Returns empty string if no file exists for the given code.
     */
    public static function getPngPath(string $code): string
    {
        // 1. Check admin uploads (supports png, jpg, gif)
        $uploadDir = App::basePath() . self::UPLOAD_DIR;
        foreach (['png', 'jpg', 'gif'] as $ext) {
            $path = $uploadDir . '/' . $code . '.' . $ext;
            if (file_exists($path)) {
                return $path;
            }
        }

        // 2. Fall back to default shipped PNGs
        $defaultPath = App::basePath() . self::DEFAULT_DIR . '/' . $code . '.png';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        return '';
    }

    /**
     * Get the web-accessible URL path for a pictogram (for preview HTML).
     *
     * Returns empty string if no file exists.
     */
    public static function getWebPath(string $code): string
    {
        // 1. Check admin uploads
        $uploadDir = App::basePath() . self::UPLOAD_DIR;
        foreach (['png', 'jpg', 'gif'] as $ext) {
            $path = $uploadDir . '/' . $code . '.' . $ext;
            if (file_exists($path)) {
                return '/uploads/pictograms/' . $code . '.' . $ext;
            }
        }

        // 2. Fall back to default
        $defaultPath = App::basePath() . self::DEFAULT_DIR . '/' . $code . '.png';
        if (file_exists($defaultPath)) {
            return '/assets/pictograms/png/' . $code . '.png';
        }

        return '';
    }

    /**
     * Check whether a given code has an admin-uploaded custom file.
     */
    public static function hasCustomUpload(string $code): bool
    {
        $uploadDir = App::basePath() . self::UPLOAD_DIR;
        foreach (['png', 'jpg', 'gif'] as $ext) {
            if (file_exists($uploadDir . '/' . $code . '.' . $ext)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Delete the admin-uploaded file for a given code (reverts to default).
     */
    public static function deleteCustomUpload(string $code): void
    {
        $uploadDir = App::basePath() . self::UPLOAD_DIR;
        foreach (['png', 'jpg', 'gif'] as $ext) {
            $path = $uploadDir . '/' . $code . '.' . $ext;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Get the upload directory absolute path (creates if needed).
     */
    public static function getUploadDir(): string
    {
        $dir = App::basePath() . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
