<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;

/**
 * PictogramHelper — Generates reliable PNG pictogram images using PHP GD.
 *
 * TCPDF's ImageSVG() is unreliable with complex SVGs, so this helper
 * generates PNG versions of all pictograms (GHS, PPE, Prop 65) using
 * GD drawing primitives. PNGs are cached on disk and regenerated only
 * when missing.
 *
 * Pictogram types:
 *   - GHS01–GHS09:  Red diamond border, black symbol (GHS/OSHA standard)
 *   - PPE-*:        Blue circle, white symbol (ISO 7010 mandatory signs)
 *   - PROP65:       Yellow/black warning triangle with exclamation mark
 */
class PictogramHelper
{
    /** PNG canvas size in pixels (renders crisp at any PDF size). */
    private const SIZE = 400;

    /** Cache directory relative to base path. */
    private const CACHE_DIR = '/public/assets/pictograms/png';

    /**
     * Get the absolute path to a PNG pictogram, generating it if needed.
     *
     * @param  string $code  Pictogram code (e.g. 'GHS08', 'PPE-eye', 'PROP65')
     * @return string        Absolute path to PNG file, or '' on failure
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

        // Generate on demand
        $img = self::generate($code);
        if ($img === null) {
            return '';
        }

        imagepng($img, $file, 6);
        imagedestroy($img);

        return file_exists($file) ? $file : '';
    }

    /**
     * Generate all pictograms at once (e.g. during install).
     */
    public static function generateAll(): void
    {
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

    /* ------------------------------------------------------------------
     *  Dispatcher
     * ----------------------------------------------------------------*/

    private static function generate(string $code): ?\GdImage
    {
        return match (true) {
            str_starts_with($code, 'GHS') => self::generateGHS($code),
            str_starts_with($code, 'PPE-') => self::generatePPE($code),
            $code === 'PROP65' => self::generateProp65(),
            default => null,
        };
    }

    /* ------------------------------------------------------------------
     *  GHS Pictograms (red diamond, black symbol)
     * ----------------------------------------------------------------*/

    private static function generateGHS(string $code): ?\GdImage
    {
        $s = self::SIZE;
        $img = self::createCanvas($s);
        self::drawGHSDiamond($img, $s);

        $black = imagecolorallocate($img, 0, 0, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        switch ($code) {
            case 'GHS01':
                self::drawExplodingBomb($img, $s, $black);
                break;
            case 'GHS02':
                self::drawFlame($img, $s, $black);
                break;
            case 'GHS03':
                self::drawFlameOverCircle($img, $s, $black, $white);
                break;
            case 'GHS04':
                self::drawGasCylinder($img, $s, $black);
                break;
            case 'GHS05':
                self::drawCorrosion($img, $s, $black, $white);
                break;
            case 'GHS06':
                self::drawSkullCrossbones($img, $s, $black, $white);
                break;
            case 'GHS07':
                self::drawExclamationMark($img, $s, $black);
                break;
            case 'GHS08':
                self::drawHealthHazard($img, $s, $black, $white);
                break;
            case 'GHS09':
                self::drawEnvironment($img, $s, $black);
                break;
            default:
                imagedestroy($img);
                return null;
        }

        return $img;
    }

    private static function drawGHSDiamond(\GdImage $img, int $s): void
    {
        $red   = imagecolorallocate($img, 255, 17, 0);
        $white = imagecolorallocate($img, 255, 255, 255);

        $cx = $s / 2;
        $cy = $s / 2;
        $r  = ($s / 2) - 5;

        // Outer diamond (red fill)
        $outer = [(int)$cx, (int)($cy - $r), (int)($cx + $r), (int)$cy, (int)$cx, (int)($cy + $r), (int)($cx - $r), (int)$cy];
        imagefilledpolygon($img, $outer, $red);

        // Inner diamond (white fill)
        $ri = $r - 18;
        $inner = [(int)$cx, (int)($cy - $ri), (int)($cx + $ri), (int)$cy, (int)$cx, (int)($cy + $ri), (int)($cx - $ri), (int)$cy];
        imagefilledpolygon($img, $inner, $white);
    }

    /* --- GHS01: Exploding Bomb --- */
    private static function drawExplodingBomb(\GdImage $img, int $s, int $black): void
    {
        $cx = $s / 2;
        $cy = $s / 2 + 20;

        // Central circle (bomb body)
        imagefilledellipse($img, (int)$cx, (int)$cy, 80, 80, $black);

        // Radiating debris fragments
        $fragments = [
            [-60, -80], [-30, -90], [0, -95], [30, -90], [60, -80],
            [-80, -40], [80, -40],
            [-90, 10], [90, 10],
            [-70, 55], [70, 55],
            [-40, 75], [40, 75],
        ];
        foreach ($fragments as $f) {
            $fx = (int)($cx + $f[0]);
            $fy = (int)($cy + $f[1]);
            imagefilledellipse($img, $fx, $fy, 14, 14, $black);
        }

        // Radiating lines from center
        imagesetthickness($img, 3);
        $lines = [
            [-50, -70], [0, -85], [50, -70],
            [-75, -30], [75, -30],
            [-80, 20], [80, 20],
            [-60, 60], [60, 60],
        ];
        foreach ($lines as $l) {
            imageline($img, (int)$cx, (int)$cy, (int)($cx + $l[0]), (int)($cy + $l[1]), $black);
        }
        imagesetthickness($img, 1);
    }

    /* --- GHS02: Flame --- */
    private static function drawFlame(\GdImage $img, int $s, int $black): void
    {
        $cx = $s / 2;
        // Flame body
        $points = [
            (int)($cx), (int)(70),        // top
            (int)($cx + 15), (int)(100),
            (int)($cx + 45), (int)(130),
            (int)($cx + 60), (int)(180),
            (int)($cx + 65), (int)(220),
            (int)($cx + 60), (int)(260),
            (int)($cx + 45), (int)(290),
            (int)($cx + 20), (int)(310),
            (int)($cx), (int)(320),        // bottom center
            (int)($cx - 20), (int)(310),
            (int)($cx - 45), (int)(290),
            (int)($cx - 60), (int)(260),
            (int)($cx - 65), (int)(220),
            (int)($cx - 60), (int)(180),
            (int)($cx - 45), (int)(130),
            (int)($cx - 15), (int)(100),
        ];
        imagefilledpolygon($img, $points, $black);
    }

    /* --- GHS03: Flame Over Circle --- */
    private static function drawFlameOverCircle(\GdImage $img, int $s, int $black, int $white): void
    {
        $cx = $s / 2;

        // Circle
        imagefilledellipse($img, (int)$cx, (int)260, 100, 100, $black);

        // Flame above circle
        $points = [
            (int)($cx), (int)(65),
            (int)($cx + 12), (int)(95),
            (int)($cx + 35), (int)(120),
            (int)($cx + 50), (int)(160),
            (int)($cx + 45), (int)(200),
            (int)($cx + 20), (int)(215),
            (int)($cx), (int)(220),
            (int)($cx - 20), (int)(215),
            (int)($cx - 45), (int)(200),
            (int)($cx - 50), (int)(160),
            (int)($cx - 35), (int)(120),
            (int)($cx - 12), (int)(95),
        ];
        imagefilledpolygon($img, $points, $black);
    }

    /* --- GHS04: Gas Cylinder --- */
    private static function drawGasCylinder(\GdImage $img, int $s, int $black): void
    {
        $cx = (int)($s / 2);

        // Cylinder body
        imagefilledrectangle($img, $cx - 40, 120, $cx + 40, 310, $black);

        // Rounded top (ellipse)
        imagefilledellipse($img, $cx, 120, 80, 40, $black);

        // Rounded bottom (ellipse)
        imagefilledellipse($img, $cx, 310, 80, 40, $black);

        // Valve at top
        imagefilledrectangle($img, $cx - 8, 85, $cx + 8, 105, $black);
        imagefilledrectangle($img, $cx - 20, 80, $cx + 20, 90, $black);
    }

    /* --- GHS05: Corrosion --- */
    private static function drawCorrosion(\GdImage $img, int $s, int $black, int $white): void
    {
        $cx = (int)($s / 2);

        // Left container pouring
        $lx = $cx - 35;
        imagefilledrectangle($img, $lx - 25, 85, $lx + 25, 130, $black);
        // Pour stream
        $pour = [$lx + 20, 130, $lx + 25, 130, $lx + 35, 200, $lx + 30, 200];
        imagefilledpolygon($img, $pour, $black);

        // Right container pouring
        $rx = $cx + 35;
        imagefilledrectangle($img, $rx - 25, 85, $rx + 25, 130, $black);
        $pour2 = [$rx - 20, 130, $rx - 25, 130, $rx - 35, 200, $rx - 30, 200];
        imagefilledpolygon($img, $pour2, $black);

        // Surface being corroded (horizontal bar)
        imagefilledrectangle($img, $cx - 75, 200, $cx + 75, 215, $black);

        // Corroded material below (hand/material shape)
        // Dripping/dissolving downward
        $drips = [-50, -30, -10, 10, 30, 50];
        foreach ($drips as $dx) {
            $dh = 20 + rand(10, 40);
            imagefilledrectangle($img, $cx + $dx - 5, 215, $cx + $dx + 5, 215 + $dh, $black);
        }

        // Corroded surface below
        imagefilledrectangle($img, $cx - 60, 280, $cx + 60, 320, $black);
        // Pitted surface (white holes)
        imagefilledellipse($img, $cx - 30, 295, 18, 14, $white);
        imagefilledellipse($img, $cx + 10, 300, 20, 16, $white);
        imagefilledellipse($img, $cx + 40, 292, 16, 12, $white);
    }

    /* --- GHS06: Skull and Crossbones --- */
    private static function drawSkullCrossbones(\GdImage $img, int $s, int $black, int $white): void
    {
        $cx = (int)($s / 2);

        // Skull (large circle)
        imagefilledellipse($img, $cx, 150, 110, 100, $black);

        // Eye sockets
        imagefilledellipse($img, $cx - 22, 140, 28, 24, $white);
        imagefilledellipse($img, $cx + 22, 140, 28, 24, $white);

        // Nasal cavity
        $nose = [$cx, 155, $cx - 8, 170, $cx + 8, 170];
        imagefilledpolygon($img, $nose, $white);

        // Jaw/teeth area
        imagefilledrectangle($img, $cx - 30, 180, $cx + 30, 200, $black);
        // Teeth gaps
        for ($tx = $cx - 25; $tx < $cx + 25; $tx += 12) {
            imagefilledrectangle($img, $tx + 4, 183, $tx + 8, 197, $white);
        }

        // Crossbones
        imagesetthickness($img, 10);
        imageline($img, $cx - 70, 220, $cx + 70, 310, $black);
        imageline($img, $cx + 70, 220, $cx - 70, 310, $black);
        imagesetthickness($img, 1);

        // Bone ends (small circles)
        $boneEnds = [
            [$cx - 70, 220], [$cx + 70, 220],
            [$cx - 70, 310], [$cx + 70, 310],
        ];
        foreach ($boneEnds as $be) {
            imagefilledellipse($img, $be[0], $be[1], 20, 18, $black);
        }
    }

    /* --- GHS07: Exclamation Mark --- */
    private static function drawExclamationMark(\GdImage $img, int $s, int $black): void
    {
        $cx = (int)($s / 2);

        // Exclamation line (tapered rectangle)
        $bar = [
            $cx - 14, 85,
            $cx + 14, 85,
            $cx + 10, 250,
            $cx - 10, 250,
        ];
        imagefilledpolygon($img, $bar, $black);

        // Exclamation dot
        imagefilledellipse($img, $cx, 290, 30, 30, $black);
    }

    /* --- GHS08: Health Hazard (person silhouette with starburst on chest) --- */
    private static function drawHealthHazard(\GdImage $img, int $s, int $black, int $white): void
    {
        $cx = (int)($s / 2);

        // Head
        imagefilledellipse($img, $cx, 100, 42, 42, $black);

        // Torso
        $torso = [
            $cx - 30, 125,
            $cx + 30, 125,
            $cx + 35, 240,
            $cx - 35, 240,
        ];
        imagefilledpolygon($img, $torso, $black);

        // Left arm
        $lArm = [
            $cx - 28, 128,
            $cx - 32, 125,
            $cx - 75, 180,
            $cx - 68, 188,
        ];
        imagefilledpolygon($img, $lArm, $black);

        // Right arm
        $rArm = [
            $cx + 28, 128,
            $cx + 32, 125,
            $cx + 75, 180,
            $cx + 68, 188,
        ];
        imagefilledpolygon($img, $rArm, $black);

        // Left leg
        $lLeg = [
            $cx - 35, 238,
            $cx - 10, 238,
            $cx - 5, 325,
            $cx - 30, 325,
        ];
        imagefilledpolygon($img, $lLeg, $black);

        // Right leg
        $rLeg = [
            $cx + 35, 238,
            $cx + 10, 238,
            $cx + 5, 325,
            $cx + 30, 325,
        ];
        imagefilledpolygon($img, $rLeg, $black);

        // Six-pointed starburst on chest (white cutout)
        $starCx = $cx;
        $starCy = 175;
        $outerR = 28;
        $innerR = 14;
        $starPoints = [];
        for ($i = 0; $i < 12; $i++) {
            $angle = deg2rad(-90 + $i * 30);
            $r = ($i % 2 === 0) ? $outerR : $innerR;
            $starPoints[] = (int)($starCx + $r * cos($angle));
            $starPoints[] = (int)($starCy + $r * sin($angle));
        }
        imagefilledpolygon($img, $starPoints, $white);
    }

    /* --- GHS09: Environment (dead tree and fish) --- */
    private static function drawEnvironment(\GdImage $img, int $s, int $black): void
    {
        $cx = (int)($s / 2);

        // Dead tree — trunk
        imagefilledrectangle($img, $cx - 35 - 8, 90, $cx - 35 + 8, 250, $black);

        // Bare branches (lines from trunk)
        imagesetthickness($img, 5);
        imageline($img, $cx - 35, 120, $cx - 75, 90, $black);
        imageline($img, $cx - 35, 140, $cx - 80, 120, $black);
        imageline($img, $cx - 35, 120, $cx + 5, 85, $black);
        imageline($img, $cx - 35, 145, $cx + 10, 115, $black);
        imageline($img, $cx - 35, 170, $cx - 78, 155, $black);
        imageline($img, $cx - 35, 180, $cx + 5, 155, $black);
        imagesetthickness($img, 1);

        // Ground line
        imagefilledrectangle($img, $cx - 85, 248, $cx + 85, 258, $black);

        // Dead fish below ground
        $fishCx = $cx + 25;
        $fishCy = 295;
        // Fish body (ellipse)
        imagefilledellipse($img, $fishCx, $fishCy, 90, 40, $black);
        // Tail
        $tail = [
            $fishCx + 45, $fishCy,
            $fishCx + 70, $fishCy - 20,
            $fishCx + 70, $fishCy + 20,
        ];
        imagefilledpolygon($img, $tail, $black);
        // Eye (white)
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, $fishCx - 20, $fishCy - 5, 14, 14, $white);
        // X on eye (dead)
        $darkGrey = imagecolorallocate($img, 0, 0, 0);
        imagesetthickness($img, 2);
        imageline($img, $fishCx - 24, $fishCy - 9, $fishCx - 16, $fishCy - 1, $darkGrey);
        imageline($img, $fishCx - 16, $fishCy - 9, $fishCx - 24, $fishCy - 1, $darkGrey);
        imagesetthickness($img, 1);
    }

    /* ------------------------------------------------------------------
     *  PPE Pictograms (blue circle, white symbol — ISO 7010 style)
     * ----------------------------------------------------------------*/

    private static function generatePPE(string $code): ?\GdImage
    {
        $s = self::SIZE;
        $img = self::createCanvas($s);

        $blue  = imagecolorallocate($img, 0, 94, 184);
        $white = imagecolorallocate($img, 255, 255, 255);

        // Blue circle background
        imagefilledellipse($img, (int)($s / 2), (int)($s / 2), $s - 20, $s - 20, $blue);

        // White circle inset (creates blue ring)
        imagefilledellipse($img, (int)($s / 2), (int)($s / 2), $s - 60, $s - 60, $white);

        // Blue inner fill
        imagefilledellipse($img, (int)($s / 2), (int)($s / 2), $s - 64, $s - 64, $blue);

        $cx = (int)($s / 2);
        $cy = (int)($s / 2);

        switch ($code) {
            case 'PPE-eye':
                self::drawPPEEye($img, $cx, $cy, $white);
                break;
            case 'PPE-hand':
                self::drawPPEGlove($img, $cx, $cy, $white);
                break;
            case 'PPE-respiratory':
                self::drawPPERespirator($img, $cx, $cy, $white);
                break;
            case 'PPE-skin':
                self::drawPPESuit($img, $cx, $cy, $white);
                break;
            default:
                imagedestroy($img);
                return null;
        }

        return $img;
    }

    /* --- PPE-eye: Safety goggles --- */
    private static function drawPPEEye(\GdImage $img, int $cx, int $cy, int $white): void
    {
        // Goggles frame (wide rectangle with rounded ends)
        imagefilledrectangle($img, $cx - 80, $cy - 30, $cx + 80, $cy + 30, $white);
        imagefilledellipse($img, $cx - 80, $cy, 40, 60, $white);
        imagefilledellipse($img, $cx + 80, $cy, 40, 60, $white);

        // Lens outlines (blue circles inside white)
        $blue = imagecolorallocate($img, 0, 94, 184);
        imagefilledellipse($img, $cx - 38, $cy, 52, 42, $blue);
        imagefilledellipse($img, $cx + 38, $cy, 52, 42, $blue);

        // Lens glass (white)
        imagefilledellipse($img, $cx - 38, $cy, 40, 32, $white);
        imagefilledellipse($img, $cx + 38, $cy, 40, 32, $white);

        // Strap (extend from both sides)
        imagefilledrectangle($img, $cx - 110, $cy - 8, $cx - 98, $cy + 8, $white);
        imagefilledrectangle($img, $cx + 98, $cy - 8, $cx + 110, $cy + 8, $white);
    }

    /* --- PPE-hand: Protective glove --- */
    private static function drawPPEGlove(\GdImage $img, int $cx, int $cy, int $white): void
    {
        // Wrist/cuff
        imagefilledrectangle($img, $cx - 35, $cy + 40, $cx + 35, $cy + 100, $white);

        // Palm area
        imagefilledrectangle($img, $cx - 45, $cy - 30, $cx + 35, $cy + 45, $white);

        // Fingers (4 rounded rectangles extending upward)
        $fingerX = [$cx - 40, $cx - 16, $cx + 8, $cx + 30];
        foreach ($fingerX as $i => $fx) {
            $fTop = $cy - 85 + ($i === 0 ? 15 : ($i === 3 ? 10 : 0));
            imagefilledrectangle($img, $fx, $fTop, $fx + 18, $cy - 20, $white);
            imagefilledellipse($img, $fx + 9, $fTop, 18, 14, $white);
        }

        // Thumb (angled to the right)
        $thumb = [
            $cx + 35, $cy + 10,
            $cx + 38, $cy - 10,
            $cx + 75, $cy - 40,
            $cx + 85, $cy - 30,
            $cx + 55, $cy + 5,
            $cx + 45, $cy + 15,
        ];
        imagefilledpolygon($img, $thumb, $white);

        // Rounded cuff bottom
        imagefilledellipse($img, $cx, $cy + 100, 70, 20, $white);
    }

    /* --- PPE-respiratory: Face mask / respirator --- */
    private static function drawPPERespirator(\GdImage $img, int $cx, int $cy, int $white): void
    {
        // Head outline (circle)
        imagefilledellipse($img, $cx, $cy - 15, 100, 110, $white);

        // Mask covering lower face
        $blue = imagecolorallocate($img, 0, 94, 184);
        imagefilledellipse($img, $cx, $cy - 15, 88, 98, $blue);

        // Restore white for mask
        // Eyes area visible (two white ovals)
        imagefilledellipse($img, $cx - 20, $cy - 35, 24, 16, $white);
        imagefilledellipse($img, $cx + 20, $cy - 35, 24, 16, $white);

        // Respirator mask (covers nose and mouth)
        imagefilledellipse($img, $cx, $cy + 15, 80, 65, $white);

        // Filter cartridge circle on mask
        imagefilledellipse($img, $cx, $cy + 15, 30, 30, $blue);
        imagefilledellipse($img, $cx, $cy + 15, 20, 20, $white);

        // Straps (lines going to sides)
        imagesetthickness($img, 4);
        imageline($img, $cx - 38, $cy + 5, $cx - 65, $cy - 20, $white);
        imageline($img, $cx + 38, $cy + 5, $cx + 65, $cy - 20, $white);
        imagesetthickness($img, 1);
    }

    /* --- PPE-skin: Protective suit / coverall --- */
    private static function drawPPESuit(\GdImage $img, int $cx, int $cy, int $white): void
    {
        // Head
        imagefilledellipse($img, $cx, $cy - 75, 36, 36, $white);

        // Torso
        $torso = [
            $cx - 30, $cy - 55,
            $cx + 30, $cy - 55,
            $cx + 35, $cy + 30,
            $cx - 35, $cy + 30,
        ];
        imagefilledpolygon($img, $torso, $white);

        // Left arm
        $lArm = [
            $cx - 28, $cy - 50,
            $cx - 35, $cy - 52,
            $cx - 70, $cy - 5,
            $cx - 60, $cy + 2,
        ];
        imagefilledpolygon($img, $lArm, $white);

        // Right arm
        $rArm = [
            $cx + 28, $cy - 50,
            $cx + 35, $cy - 52,
            $cx + 70, $cy - 5,
            $cx + 60, $cy + 2,
        ];
        imagefilledpolygon($img, $rArm, $white);

        // Left leg
        $lLeg = [
            $cx - 32, $cy + 28,
            $cx - 5, $cy + 28,
            $cx - 2, $cy + 100,
            $cx - 30, $cy + 100,
        ];
        imagefilledpolygon($img, $lLeg, $white);

        // Right leg
        $rLeg = [
            $cx + 32, $cy + 28,
            $cx + 5, $cy + 28,
            $cx + 2, $cy + 100,
            $cx + 30, $cy + 100,
        ];
        imagefilledpolygon($img, $rLeg, $white);
    }

    /* ------------------------------------------------------------------
     *  Prop 65 Warning Triangle
     * ----------------------------------------------------------------*/

    private static function generateProp65(): ?\GdImage
    {
        $s = self::SIZE;
        $img = self::createCanvas($s);

        $black  = imagecolorallocate($img, 0, 0, 0);
        $yellow = imagecolorallocate($img, 255, 204, 0);
        $white  = imagecolorallocate($img, 255, 255, 255);

        $cx = (int)($s / 2);

        // Outer triangle (black border)
        $outerTri = [
            $cx, 20,              // top
            $s - 15, $s - 30,     // bottom right
            15, $s - 30,          // bottom left
        ];
        imagefilledpolygon($img, $outerTri, $black);

        // Inner triangle (yellow fill)
        $inset = 20;
        $innerTri = [
            $cx, 20 + (int)($inset * 2.2),
            $s - 15 - (int)($inset * 1.5), $s - 30 - $inset,
            15 + (int)($inset * 1.5), $s - 30 - $inset,
        ];
        imagefilledpolygon($img, $innerTri, $yellow);

        // Exclamation mark (black)
        // Line part (tapered)
        $bar = [
            $cx - 12, 120,
            $cx + 12, 120,
            $cx + 9, 270,
            $cx - 9, 270,
        ];
        imagefilledpolygon($img, $bar, $black);

        // Dot
        imagefilledellipse($img, $cx, 305, 28, 28, $black);

        return $img;
    }

    /* ------------------------------------------------------------------
     *  Canvas helper
     * ----------------------------------------------------------------*/

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
