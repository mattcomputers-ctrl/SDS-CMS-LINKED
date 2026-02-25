<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * PictogramHelper — Generates reliable PNG pictogram images using PHP GD.
 *
 * Traces the exact SVG coordinate data from public/assets/pictograms/ at 4×
 * scale (800×800 px) for crisp PDF rendering.  PNGs are cached on disk.
 *
 * Pictogram types:
 *   - GHS01–GHS09:  Red diamond border, black symbol (GHS/OSHA standard)
 *   - PPE-*:        Blue circle, white symbol (ISO 7010 mandatory signs)
 *   - PROP65:       Yellow/black warning triangle with exclamation mark
 */
class PictogramHelper
{
    /** PNG canvas size in pixels (4× the 200×200 SVG source). */
    private const SIZE = 800;

    /** Scale factor from SVG coordinate space (200) to PNG (800). */
    private const K = 4;

    /** Cache directory relative to base path. */
    private const CACHE_DIR = '/public/assets/pictograms/png';

    /**
     * Get the absolute path to a PNG pictogram, generating it if needed.
     */
    public static function getPngPath(string $code): string
    {
        $dir = App::basePath() . self::CACHE_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $code . '.png';
        if (file_exists($file)) {
            return $file;
        }

        $img = self::generate($code);
        if ($img === null) {
            return '';
        }

        imagepng($img, $file, 6);
        imagedestroy($img);

        return file_exists($file) ? $file : '';
    }

    /**
     * Force-regenerate all pictograms (e.g. after an update).
     */
    public static function generateAll(): void
    {
        $dir = App::basePath() . self::CACHE_DIR;
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*.png'));
        }
        $codes = [
            'GHS01', 'GHS02', 'GHS03', 'GHS04', 'GHS05',
            'GHS06', 'GHS07', 'GHS08', 'GHS09',
            'PPE-respiratory', 'PPE-hand', 'PPE-eye', 'PPE-skin',
            'PROP65',
        ];
        foreach ($codes as $code) {
            self::getPngPath($code);
        }
    }

    /* ==================================================================
     *  Dispatcher
     * ================================================================*/

    private static function generate(string $code): ?\GdImage
    {
        return match (true) {
            str_starts_with($code, 'GHS') => self::generateGHS($code),
            str_starts_with($code, 'PPE-') => self::generatePPE($code),
            $code === 'PROP65' => self::generateProp65(),
            default => null,
        };
    }

    /* ==================================================================
     *  GHS Pictograms  (red diamond, black symbol on white)
     * ================================================================*/

    private static function generateGHS(string $code): ?\GdImage
    {
        $s   = self::SIZE;
        $img = self::createCanvas($s);
        self::drawGHSDiamond($img);

        $black = imagecolorallocate($img, 0, 0, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        $drawn = match ($code) {
            'GHS01' => self::drawGHS01($img, $black, $white),
            'GHS02' => self::drawGHS02($img, $black),
            'GHS03' => self::drawGHS03($img, $black),
            'GHS04' => self::drawGHS04($img, $black, $white),
            'GHS05' => self::drawGHS05($img, $black, $white),
            'GHS06' => self::drawGHS06($img, $black, $white),
            'GHS07' => self::drawGHS07($img, $black),
            'GHS08' => self::drawGHS08($img, $black, $white),
            'GHS09' => self::drawGHS09($img, $black, $white),
            default  => false,
        };

        if (!$drawn) {
            imagedestroy($img);
            return null;
        }
        return $img;
    }

    /**
     * Red diamond border — matches SVG:
     *   polygon points="100,5 195,100 100,195 5,100" stroke="#FF1100" stroke-width="7"
     */
    private static function drawGHSDiamond(\GdImage $img): void
    {
        $K     = self::K;
        $red   = imagecolorallocate($img, 255, 17, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        // Outer red diamond (fill the stroke area)
        $outer = self::s([100, 5, 195, 100, 100, 195, 5, 100]);
        imagefilledpolygon($img, $outer, $red);

        // Inner white diamond  (inset ≈ stroke-width × √2 ≈ 10 SVG units)
        $inner = self::s([100, 15, 185, 100, 100, 185, 15, 100]);
        imagefilledpolygon($img, $inner, $white);
    }

    /* --- GHS01: Exploding Bomb ----------------------------------------
     *  SVG group: translate(100,105), bomb circle + fuse + rays + fragments
     */
    private static function drawGHS01(\GdImage $img, int $black, int $white): bool
    {
        $K = self::K;
        // Bomb body — circle cx=100 cy=115 r=22  (after translate)
        imagefilledellipse($img, 100 * $K, 115 * $K, 44 * $K, 44 * $K, $black);

        // Fuse stem
        imagefilledrectangle($img, (100 - 3) * $K, (105 - 18) * $K, (100 + 3) * $K, (105 - 6) * $K, $black);

        // Explosion rays from bomb (line coords in absolute SVG after translate)
        imagesetthickness($img, 4 * $K);
        $rays = [
            [122, 105, 142, 95],  [120, 93, 138, 80],  [115, 87, 128, 67],
            [78, 105, 58, 95],    [80, 93, 62, 80],     [85, 87, 72, 67],
            [110, 85, 115, 63],   [90, 85, 85, 63],
        ];
        foreach ($rays as $r) {
            imageline($img, $r[0] * $K, $r[1] * $K, $r[2] * $K, $r[3] * $K, $black);
        }
        imagesetthickness($img, 1);

        // Fragment circles at ray tips
        $frags = [
            [144, 94], [140, 78], [130, 65], [58, 94], [60, 78], [70, 65],
            [116, 61], [84, 61],
        ];
        foreach ($frags as $f) {
            imagefilledellipse($img, $f[0] * $K, $f[1] * $K, 6 * $K, 6 * $K, $black);
        }

        // Spark above fuse
        $spark = self::s([100, 67, 96, 75, 104, 75]);
        imagefilledpolygon($img, $spark, $black);
        $spark2 = self::s([108, 69, 104, 77, 110, 78]);
        imagefilledpolygon($img, $spark2, $black);
        $spark3 = self::s([92, 69, 96, 77, 90, 78]);
        imagefilledpolygon($img, $spark3, $black);

        return true;
    }

    /* --- GHS02: Flame -------------------------------------------------
     *  Outer flame path sampled from cubic bezier curves in SVG.
     */
    private static function drawGHS02(\GdImage $img, int $black): bool
    {
        // Outer flame outline — sampled from SVG bezier M100,30 C…Z
        $pts = self::s([
            100, 30,   82, 53,   66, 77,   60, 100,
            60, 118,   66, 135,  73, 143,  88, 155,
            82, 147,   77, 135,  80, 118,
            82, 126,   87, 137,  95, 148,
            92, 139,   89, 127,  92, 115,
            95, 123,   99, 134,  105, 148,
            110, 140,  116, 129, 120, 118,
            123, 130,  121, 143, 112, 155,
            122, 147,  132, 135, 140, 118,
            140, 100,  136, 77,  118, 53,
        ]);
        imagefilledpolygon($img, $pts, $black);
        return true;
    }

    /* --- GHS03: Flame Over Circle -------------------------------------
     *  Circle + flame above.
     */
    private static function drawGHS03(\GdImage $img, int $black): bool
    {
        // Circle at bottom
        imagefilledellipse($img, 100 * self::K, 135 * self::K, 50 * self::K, 50 * self::K, $black);

        // Flame sampled from SVG bezier
        $pts = self::s([
            100, 40,   82, 60,   68, 78,   68, 95,
            68, 108,   75, 118,  85, 123,
            78, 115,   78, 105,  83, 95,
            86, 105,   90, 115,  95, 120,
            92, 108,   90, 98,   95, 88,
            98, 98,    102, 110, 105, 120,
            110, 115,  114, 105, 117, 95,
            122, 105,  122, 115, 115, 123,
            125, 118,  132, 108, 132, 95,
            132, 78,   118, 60,
        ]);
        imagefilledpolygon($img, $pts, $black);
        return true;
    }

    /* --- GHS04: Gas Cylinder ------------------------------------------
     *  Rect body + ellipse caps + valve from SVG.
     */
    private static function drawGHS04(\GdImage $img, int $black, int $white): bool
    {
        $K = self::K;
        // Cylinder body
        imagefilledrectangle($img, 72 * $K, 60 * $K, 128 * $K, 150 * $K, $black);
        // Top dome
        imagefilledellipse($img, 100 * $K, 60 * $K, 56 * $K, 20 * $K, $black);
        // Bottom dome
        imagefilledellipse($img, 100 * $K, 150 * $K, 56 * $K, 20 * $K, $black);
        // Foot
        imagefilledrectangle($img, 74 * $K, 152 * $K, 126 * $K, 158 * $K, $black);
        // Valve neck
        imagefilledrectangle($img, 92 * $K, 42 * $K, 108 * $K, 60 * $K, $black);
        // Valve top
        imagefilledrectangle($img, 88 * $K, 38 * $K, 112 * $K, 44 * $K, $black);
        // Valve handle
        imagefilledrectangle($img, 85 * $K, 33 * $K, 115 * $K, 39 * $K, $black);
        // Highlight stripe
        imagefilledrectangle($img, 82 * $K, 62 * $K, 90 * $K, 148 * $K, $white);
        return true;
    }

    /* --- GHS05: Corrosion ---------------------------------------------
     *  Two tilted test tubes, drips, hand + surface.
     */
    private static function drawGHS05(\GdImage $img, int $black, int $white): bool
    {
        $K = self::K;

        // Simplified: two containers, drops, corroded surface + hand
        // Left container (tilted ~-20°)
        $lTube = self::s([65, 42, 85, 42, 88, 90, 62, 90]);
        imagefilledpolygon($img, $lTube, $black);
        imagefilledrectangle($img, 60 * $K, 38 * $K, 90 * $K, 44 * $K, $black);

        // Right container (tilted ~+20°)
        $rTube = self::s([115, 42, 135, 42, 138, 90, 112, 90]);
        imagefilledpolygon($img, $rTube, $black);
        imagefilledrectangle($img, 110 * $K, 38 * $K, 140 * $K, 44 * $K, $black);

        // Drops from left
        imagefilledellipse($img, 78 * $K, 95 * $K, 6 * $K, 10 * $K, $black);
        imagefilledellipse($img, 82 * $K, 105 * $K, 5 * $K, 8 * $K, $black);
        // Drops from right
        imagefilledellipse($img, 122 * $K, 95 * $K, 6 * $K, 10 * $K, $black);
        imagefilledellipse($img, 118 * $K, 105 * $K, 5 * $K, 8 * $K, $black);

        // Hand (left side)
        $hand = self::s([
            60, 140, 65, 127, 75, 120, 80, 122,
            80, 128, 78, 135, 78, 148,
            95, 148, 95, 155, 55, 155, 55, 148,
        ]);
        imagefilledpolygon($img, $hand, $black);
        // Corrosion marks on hand
        imagefilledrectangle($img, 67 * $K, 133 * $K, 75 * $K, 136 * $K, $white);
        imagefilledrectangle($img, 70 * $K, 139 * $K, 75 * $K, 141 * $K, $white);

        // Surface bar (right side)
        imagefilledrectangle($img, 100 * $K, 130 * $K, 145 * $K, 148 * $K, $black);
        // Corrosion pits
        imagefilledrectangle($img, 108 * $K, 134 * $K, 118 * $K, 138 * $K, $white);
        imagefilledrectangle($img, 122 * $K, 136 * $K, 130 * $K, 139 * $K, $white);
        imagefilledrectangle($img, 112 * $K, 140 * $K, 124 * $K, 143 * $K, $white);

        return true;
    }

    /* --- GHS06: Skull and Crossbones ----------------------------------
     *  From SVG: cranium ellipse, jaw path, eye/nose/teeth, crossbones.
     */
    private static function drawGHS06(\GdImage $img, int $black, int $white): bool
    {
        $K = self::K;

        // Cranium  (ellipse cx=100 cy=72 rx=30 ry=28)
        imagefilledellipse($img, 100 * $K, 72 * $K, 60 * $K, 56 * $K, $black);

        // Jaw  (polygon approximating M78,85 L78,100 C78,108 88,112 100,112 C112,112 122,108 122,100 L122,85 Z)
        $jaw = self::s([78, 85, 78, 100, 82, 108, 90, 112, 100, 112, 110, 112, 118, 108, 122, 100, 122, 85]);
        imagefilledpolygon($img, $jaw, $black);

        // Left eye socket (ellipse cx=88 cy=72 rx=10 ry=9)
        imagefilledellipse($img, 88 * $K, 72 * $K, 20 * $K, 18 * $K, $white);
        // Right eye socket (cx=112)
        imagefilledellipse($img, 112 * $K, 72 * $K, 20 * $K, 18 * $K, $white);

        // Nose (triangle 96,85 100,92 104,85)
        $nose = self::s([96, 85, 100, 92, 104, 85]);
        imagefilledpolygon($img, $nose, $white);

        // Teeth (4 rects with white gaps)
        $teethX = [85, 93, 101, 109];
        foreach ($teethX as $tx) {
            imagefilledrectangle($img, $tx * $K, 99 * $K, ($tx + 6) * $K, 107 * $K, $white);
        }

        // Crossbones (lines stroke-width=10)
        imagesetthickness($img, 10 * $K);
        imageline($img, 55 * $K, 125 * $K, 145 * $K, 160 * $K, $black);
        imageline($img, 145 * $K, 125 * $K, 55 * $K, 160 * $K, $black);
        imagesetthickness($img, 1);

        // Bone knobs (pairs of circles at each end from SVG)
        $knobs = [
            [53, 123, 7], [57, 127, 6],
            [147, 123, 7], [143, 127, 6],
            [53, 162, 7], [57, 158, 6],
            [147, 162, 7], [143, 158, 6],
        ];
        foreach ($knobs as $kb) {
            imagefilledellipse($img, $kb[0] * $K, $kb[1] * $K, $kb[2] * 2 * $K, $kb[2] * 2 * $K, $black);
        }

        return true;
    }

    /* --- GHS07: Exclamation Mark --------------------------------------
     *  SVG: M88,45 L112,45 L108,125 L92,125 Z  +  circle cx=100 cy=148 r=13
     */
    private static function drawGHS07(\GdImage $img, int $black): bool
    {
        // Tapered stem
        $stem = self::s([88, 45, 112, 45, 108, 125, 92, 125]);
        imagefilledpolygon($img, $stem, $black);

        // Dot
        imagefilledellipse($img, 100 * self::K, 148 * self::K, 26 * self::K, 26 * self::K, $black);
        return true;
    }

    /* --- GHS08: Health Hazard -----------------------------------------
     *  Uses the reference image (GHS08_reference.jpg) for accurate rendering.
     *  The reference is the standard GHS health hazard pictogram.
     */
    private static function drawGHS08(\GdImage $img, int $black, int $white): bool
    {
        $refPath = App::basePath() . '/public/assets/pictograms/GHS08_reference.jpg';
        if (!file_exists($refPath)) {
            return false;
        }

        $src = imagecreatefromjpeg($refPath);
        if ($src === false) {
            return false;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);

        // Copy the reference image onto our canvas (resized to 800x800)
        imagecopyresampled($img, $src, 0, 0, 0, 0, self::SIZE, self::SIZE, $sw, $sh);
        imagedestroy($src);

        return true;
    }

    /* --- GHS09: Environment (dead tree + fish) ------------------------
     *  Traced from GHS09.svg.
     */
    private static function drawGHS09(\GdImage $img, int $black, int $white): bool
    {
        $K = self::K;

        // Tree trunk (rect x=68 y=55 w=10 h=70)
        imagefilledrectangle($img, 68 * $K, 55 * $K, 78 * $K, 125 * $K, $black);

        // Branches (lines from trunk)
        imagesetthickness($img, 5 * $K);
        imageline($img, 73 * $K, 65 * $K, 95 * $K, 50 * $K, $black);
        imageline($img, 73 * $K, 72 * $K, 52 * $K, 55 * $K, $black);
        imageline($img, 73 * $K, 85 * $K, 90 * $K, 75 * $K, $black);
        imageline($img, 73 * $K, 95 * $K, 55 * $K, 85 * $K, $black);
        imagesetthickness($img, 3 * $K);
        imageline($img, 90 * $K, 53 * $K, 95 * $K, 42 * $K, $black);
        imageline($img, 55 * $K, 58 * $K, 48 * $K, 48 * $K, $black);
        imagesetthickness($img, 1);

        // Ground/water line (wavy)
        imagesetthickness($img, 3 * $K);
        $waveY = 130;
        $waveXs = [40, 55, 70, 85, 100, 115, 130, 145, 160];
        for ($i = 0; $i < count($waveXs) - 1; $i++) {
            $y1 = ($i % 2 === 0) ? $waveY : $waveY - 5;
            $y2 = (($i + 1) % 2 === 0) ? $waveY : $waveY - 5;
            imageline($img, $waveXs[$i] * $K, $y1 * $K, $waveXs[$i + 1] * $K, $y2 * $K, $black);
        }
        imagesetthickness($img, 1);

        // Dead fish (belly up) — translated to (115, 145) in SVG
        $fx = 115;
        $fy = 145;
        // Body ellipse rx=25 ry=10
        imagefilledellipse($img, $fx * $K, $fy * $K, 50 * $K, 20 * $K, $black);
        // Tail (triangle: 22,0 35,-10 35,10 relative)
        imagefilledpolygon($img, self::s([
            $fx + 22, $fy, $fx + 35, $fy - 10, $fx + 35, $fy + 10,
        ]), $black);
        // Eye (white circle + X)
        imagefilledellipse($img, ($fx - 12) * $K, ($fy - 2) * $K, 8 * $K, 8 * $K, $white);
        imagesetthickness($img, 2 * $K);
        imageline($img, ($fx - 15) * $K, ($fy - 5) * $K, ($fx - 9) * $K, ($fy + 1) * $K, $black);
        imageline($img, ($fx - 9) * $K, ($fy - 5) * $K, ($fx - 15) * $K, ($fy + 1) * $K, $black);
        imagesetthickness($img, 1);
        // Gill arc (white)
        imagesetthickness($img, 2 * $K);
        imagearc($img, ($fx - 3) * $K, $fy * $K, 6 * $K, 12 * $K, 270, 90, $white);
        imagesetthickness($img, 1);

        return true;
    }

    /* ==================================================================
     *  PPE Pictograms (blue circle, white symbol — ISO 7010)
     * ================================================================*/

    private static function generatePPE(string $code): ?\GdImage
    {
        $s   = self::SIZE;
        $img = self::createCanvas($s);

        $blue  = imagecolorallocate($img, 0, 94, 184);
        $white = imagecolorallocate($img, 255, 255, 255);
        $cx    = (int)($s / 2);
        $cy    = (int)($s / 2);

        // Blue filled circle
        imagefilledellipse($img, $cx, $cy, $s - 40, $s - 40, $blue);
        // White ring
        imagefilledellipse($img, $cx, $cy, $s - 80, $s - 80, $white);
        // Blue inner fill
        imagefilledellipse($img, $cx, $cy, $s - 88, $s - 88, $blue);

        $drawn = match ($code) {
            'PPE-eye'         => self::drawPPEEye($img, $cx, $cy, $white, $blue),
            'PPE-hand'        => self::drawPPEGlove($img, $cx, $cy, $white),
            'PPE-respiratory'  => self::drawPPERespirator($img, $cx, $cy, $white, $blue),
            'PPE-skin'         => self::drawPPESuit($img, $cx, $cy, $white),
            default            => false,
        };

        if (!$drawn) {
            imagedestroy($img);
            return null;
        }
        return $img;
    }

    private static function drawPPEEye(\GdImage $img, int $cx, int $cy, int $white, int $blue): bool
    {
        // Goggle frame
        imagefilledrectangle($img, $cx - 160, $cy - 50, $cx + 160, $cy + 50, $white);
        imagefilledellipse($img, $cx - 160, $cy, 60, 100, $white);
        imagefilledellipse($img, $cx + 160, $cy, 60, 100, $white);

        // Lens outlines
        imagefilledellipse($img, $cx - 65, $cy, 100, 80, $blue);
        imagefilledellipse($img, $cx + 65, $cy, 100, 80, $blue);
        // Lens glass
        imagefilledellipse($img, $cx - 65, $cy, 80, 60, $white);
        imagefilledellipse($img, $cx + 65, $cy, 80, 60, $white);

        // Strap
        imagefilledrectangle($img, $cx - 220, $cy - 14, $cx - 188, $cy + 14, $white);
        imagefilledrectangle($img, $cx + 188, $cy - 14, $cx + 220, $cy + 14, $white);
        return true;
    }

    private static function drawPPEGlove(\GdImage $img, int $cx, int $cy, int $white): bool
    {
        // Cuff
        imagefilledrectangle($img, $cx - 70, $cy + 80, $cx + 70, $cy + 190, $white);
        imagefilledellipse($img, $cx, $cy + 190, 140, 30, $white);
        // Palm
        imagefilledrectangle($img, $cx - 88, $cy - 50, $cx + 70, $cy + 85, $white);
        // Fingers
        $fx = [$cx - 80, $cx - 32, $cx + 16, $cx + 58];
        foreach ($fx as $i => $x) {
            $top = $cy - 160 + ($i === 0 ? 30 : ($i === 3 ? 20 : 0));
            imagefilledrectangle($img, $x, $top, $x + 36, $cy - 40, $white);
            imagefilledellipse($img, $x + 18, $top, 36, 24, $white);
        }
        // Thumb
        $thumb = [
            $cx + 70, $cy + 20, $cx + 76, $cy - 20,
            $cx + 150, $cy - 80, $cx + 168, $cy - 60,
            $cx + 110, $cy + 10, $cx + 90, $cy + 30,
        ];
        imagefilledpolygon($img, $thumb, $white);
        return true;
    }

    private static function drawPPERespirator(\GdImage $img, int $cx, int $cy, int $white, int $blue): bool
    {
        // Head
        imagefilledellipse($img, $cx, $cy - 30, 200, 220, $white);
        // Inner face (blue)
        imagefilledellipse($img, $cx, $cy - 30, 176, 196, $blue);
        // Eyes
        imagefilledellipse($img, $cx - 40, $cy - 70, 48, 32, $white);
        imagefilledellipse($img, $cx + 40, $cy - 70, 48, 32, $white);
        // Mask
        imagefilledellipse($img, $cx, $cy + 30, 160, 130, $white);
        // Filter
        imagefilledellipse($img, $cx, $cy + 30, 60, 60, $blue);
        imagefilledellipse($img, $cx, $cy + 30, 40, 40, $white);
        // Straps
        imagesetthickness($img, 8);
        imageline($img, $cx - 76, $cy + 10, $cx - 130, $cy - 40, $white);
        imageline($img, $cx + 76, $cy + 10, $cx + 130, $cy - 40, $white);
        imagesetthickness($img, 1);
        return true;
    }

    private static function drawPPESuit(\GdImage $img, int $cx, int $cy, int $white): bool
    {
        // Head
        imagefilledellipse($img, $cx, $cy - 150, 72, 72, $white);
        // Torso
        imagefilledpolygon($img, [
            $cx - 60, $cy - 110, $cx + 60, $cy - 110,
            $cx + 70, $cy + 60,  $cx - 70, $cy + 60,
        ], $white);
        // Left arm
        imagefilledpolygon($img, [
            $cx - 56, $cy - 100, $cx - 70, $cy - 104,
            $cx - 140, $cy - 10, $cx - 120, $cy + 4,
        ], $white);
        // Right arm
        imagefilledpolygon($img, [
            $cx + 56, $cy - 100, $cx + 70, $cy - 104,
            $cx + 140, $cy - 10, $cx + 120, $cy + 4,
        ], $white);
        // Left leg
        imagefilledpolygon($img, [
            $cx - 64, $cy + 56, $cx - 10, $cy + 56,
            $cx - 4, $cy + 200, $cx - 60, $cy + 200,
        ], $white);
        // Right leg
        imagefilledpolygon($img, [
            $cx + 64, $cy + 56, $cx + 10, $cy + 56,
            $cx + 4, $cy + 200, $cx + 60, $cy + 200,
        ], $white);
        return true;
    }

    /* ==================================================================
     *  Prop 65 Warning Triangle
     * ================================================================*/

    private static function generateProp65(): ?\GdImage
    {
        $s   = self::SIZE;
        $img = self::createCanvas($s);

        $black  = imagecolorallocate($img, 0, 0, 0);
        $yellow = imagecolorallocate($img, 255, 204, 0);

        $cx = (int)($s / 2);

        // Outer triangle (black)
        imagefilledpolygon($img, [$cx, 30, $s - 30, $s - 50, 30, $s - 50], $black);

        // Inner triangle (yellow)
        imagefilledpolygon($img, [$cx, 90, $s - 80, $s - 85, 80, $s - 85], $yellow);

        // Exclamation stem
        imagefilledpolygon($img, [$cx - 24, 220, $cx + 24, 220, $cx + 18, 530, $cx - 18, 530], $black);
        // Exclamation dot
        imagefilledellipse($img, $cx, 600, 56, 56, $black);

        return $img;
    }

    /* ==================================================================
     *  Helpers
     * ================================================================*/

    /**
     * Scale an array of SVG coordinates (x,y pairs) by K.
     *
     * @param  int[] $coords  Flat [x1,y1,x2,y2,…] in SVG 200×200 space
     * @return int[]          Scaled to 800×800 space
     */
    private static function s(array $coords): array
    {
        $K = self::K;
        return array_map(fn(int|float $v) => (int)($v * $K), $coords);
    }

    private static function createCanvas(int $size): \GdImage
    {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $transparent);
        imagealphablending($img, true);
        imageantialias($img, true);
        return $img;
    }
}
