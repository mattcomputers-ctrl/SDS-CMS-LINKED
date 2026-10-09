#!/usr/bin/env php
<?php
/**
 * Audit #36 — TextOverrideService (no DB) and the SDSGenerator::ignoreOverrides()
 * switch: normalisation, equality with the automatic value, the editable-key
 * whitelist and the save plan (upsert / delete / counts).
 *
 * Run:
 *   docker run --rm -v "<repo>:/app" -w /app sds-php:8.1-gd php tests/Services/TextOverrideServiceTest.php
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Bootstrap App static properties without a DB connection (same as SDSPreviewResponseTest.php).
$ref = new ReflectionClass(\SDS\Core\App::class);
$bp = $ref->getProperty('basePath');
$bp->setAccessible(true);
$bp->setValue(null, $basePath);
$cfg = $ref->getProperty('config');
$cfg->setAccessible(true);
$cfg->setValue(null, [
    'company' => ['name' => 'Config Placeholder Co'],
    'paths'   => ['generated_pdfs' => $basePath . '/storage/temp'],
]);

use SDS\Services\TextOverrideService as T;

$failures = 0;
$assert = function (bool $ok, string $label, $actual = null) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
        if ($actual !== null) {
            echo '      actual: ' . var_export($actual, true) . PHP_EOL;
        }
    }
};

// --- clean() / normalize() ---------------------------------------------------
$assert(T::clean("  a\r\nb \r\n") === "a\nb", 'clean: trims and CRLF -> LF');
$assert(T::normalize("Keep  away\r\n from   heat. ") === 'Keep away from heat.', 'normalize: collapses whitespace runs');

// --- equalsDefault() ---------------------------------------------------------
$assert(T::equalsDefault('Not determined', 'Not determined'), 'equalsDefault: identical');
$assert(T::equalsDefault("Not\r\ndetermined ", 'Not determined'), 'equalsDefault: whitespace-insensitive');
$assert(!T::equalsDefault('not determined', 'Not determined'), 'equalsDefault: case-sensitive');
$assert(!T::equalsDefault('x', null), 'equalsDefault: null default is never equal');

// --- defaultFor() -------------------------------------------------------------
$sections = [
    2  => ['other_hazards' => 'None known.', 'has_other_hazards' => false, 'pictograms' => ['GHS07']],
    4  => ['title' => 'First-Aid Measures', 'inhalation' => 'Move to fresh air.', 'skin' => 'Wash with soap and water.', 'notes' => ''],
    9  => ['flash_point' => '> 93 °C (> 200 °F)', 'specific_gravity' => 1.05, 'odor' => 'Not determined'],
];
$assert(T::defaultFor($sections, 4, 'inhalation') === 'Move to fresh air.', 'defaultFor: string');
$assert(T::defaultFor($sections, 9, 'specific_gravity') === '1.05', 'defaultFor: numeric cast to string');
$assert(T::defaultFor($sections, 2, 'pictograms') === null, 'defaultFor: array -> null');
$assert(T::defaultFor($sections, 2, 'has_other_hazards') === null, 'defaultFor: bool -> null');
$assert(T::defaultFor($sections, 16, 'revision_note') === null, 'defaultFor: missing key -> null');
$assert(T::defaultFor($sections, 4, 'notes') === '', 'defaultFor: empty string stays empty');

// --- isEditable() --------------------------------------------------------------
$assert(T::isEditable(9, 'solubility'), 'isEditable: 9.solubility allowed');
$assert(T::isEditable(14, 'environmental_hazards'), 'isEditable: 14.environmental_hazards allowed (#27)');
$assert(T::isEditable(15, 'state_regs'), 'isEditable: 15.state_regs allowed');
$assert(!T::isEditable(3, 'components'), 'isEditable: 3.components rejected');
$assert(!T::isEditable(16, 'revision_note'), 'isEditable: 16.revision_note rejected');
$assert(!T::isEditable(2, 'pictograms'), 'isEditable: 2.pictograms rejected');

// --- plan() --------------------------------------------------------------------
$stored = [
    4 => ['inhalation' => 'Custom inhalation text.', 'notes' => 'Old note'],
    9 => ['odor' => 'Not determined'],
];
$posted = [
    '4'  => [
        'inhalation' => 'Custom inhalation text.',     // same as stored -> unchanged
        'skin'       => 'Wash with soap and water.',   // equals automatic, nothing stored -> auto
        'eyes'       => '',                             // blank, nothing stored -> auto
        'notes'      => '',                             // blank, stored -> delete (reset)
        'symptoms'   => " New symptom text \r\n",      // custom -> upsert (cleaned)
    ],
    '9'  => [
        'odor'        => 'Not determined',              // equals automatic, stored -> delete
        'flash_point' => " > 93 °C (> 200 °F)\r\n",    // equals automatic after normalisation -> auto
    ],
    '2'  => ['other_hazards' => 'None known.', 'pictograms' => 'x'], // auto ; ignored (not editable)
    '3'  => ['components' => 'junk'],                                // ignored
    '99' => ['foo' => 'bar'],                                        // ignored
];
$p   = T::plan($posted, $sections, $stored);
$ids = static fn (array $l): array => array_map(static fn (array $x): string => $x['section'] . '.' . $x['key'], $l);

$assert($ids($p['upsert']) === ['4.symptoms'], 'plan: only new custom text is upserted', $ids($p['upsert']));
$assert(($p['upsert'][0]['text'] ?? null) === 'New symptom text', 'plan: upsert carries cleaned text', $p['upsert'][0]['text'] ?? null);
$del = $ids($p['delete']);
sort($del);
$assert($del === ['4.notes', '9.odor'], 'plan: blank (reset) and equal-to-automatic stored rows are deleted', $del);
$assert($p['counts'] === ['stored' => 1, 'unchanged' => 1, 'removed' => 2, 'auto' => 4, 'ignored' => 3], 'plan: counts', $p['counts']);

$p2 = T::plan(['4' => ['inhalation' => 'Changed text']], $sections, $stored);
$assert($ids($p2['upsert']) === ['4.inhalation'] && $p2['counts']['stored'] === 1, 'plan: changed stored text is upserted', $p2);

$p3 = T::plan([], $sections, $stored);
$assert($p3['upsert'] === [] && $p3['delete'] === [], 'plan: fields not posted are left untouched', $p3);

// --- SDSGenerator::ignoreOverrides() ---------------------------------------------
$gen = new \SDS\Services\SDSGenerator(new \SDS\Services\TranslationService('en'));
$assert($gen->ignoreOverrides() === $gen, 'ignoreOverrides(): fluent');
$m = new ReflectionMethod($gen, 'getOverrides');
$m->setAccessible(true);
$assert($m->invoke($gen, 1, 'en') === [], 'getOverrides(): returns [] with overrides ignored (no DB touched)');

echo PHP_EOL . ($failures === 0 ? 'ALL PASSED' : "{$failures} FAILED") . PHP_EOL;
exit($failures > 0 ? 1 : 0);
