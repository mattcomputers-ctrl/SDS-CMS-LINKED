#!/usr/bin/env php
<?php
/**
 * CAS determinations: a signal word left on Auto stays Auto (2026-10-28) — DB-free.
 *
 * Saving used to store the signal word the selections gave in the same field
 * the form reads as the manual override, so a determination saved on Auto
 * reopened with Danger / Warning picked as an override and stopped
 * following its selections. Now the save keeps the manual choice apart
 * (signal_word_override, '' = Auto) and the form reads that. Older
 * determinations count as an override only where their saved word differs
 * from the one their selected hazards give.
 *
 * Run: php tests/Services/DeterminationSignalWordTest.php
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';
session_save_path(sys_get_temp_dir());
@session_start();   // the form prints a CSRF field
$_SESSION = [];
$bp = new ReflectionProperty(\SDS\Core\App::class, 'basePath');   // pictogram paths
$bp->setAccessible(true);
$bp->setValue(null, $basePath);

use SDS\Services\GHSHelper;

$failures = 0;
$checks   = 0;
function check(bool $ok, string $label, $actual = null): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$label}\n";
    if ($actual !== null) {
        echo '       actual: ' . json_encode($actual) . "\n";
    }
}

$danger  = 'Flammable Liquids - Category 2';   // Danger
$warning = 'Flammable Liquids - Category 3';   // Warning
check(GHSHelper::autoSignalWord([$danger]) === 'Danger' && GHSHelper::autoSignalWord([$warning]) === 'Warning'
    && GHSHelper::autoSignalWord([$warning, $danger]) === 'Danger' && GHSHelper::autoSignalWord([]) === null, 'auto signal word: the highest the selections give, none without');

echo "\na. Saving\n";
$post = static fn (array $hazards, string $sw): array => ['selected_hazards_json' => json_encode($hazards), 'signal_word' => $sw];
$det  = GHSHelper::buildDeterminationJson($post([$warning], ''));
check($det['signal_word'] === 'Warning' && $det['signal_word_override'] === '', 'Auto: the word the selections give is in effect, no override recorded', $det);
$det2 = GHSHelper::buildDeterminationJson($post([$warning], 'Danger'));
check($det2['signal_word'] === 'Danger' && $det2['signal_word_override'] === 'Danger', 'an override is in effect and recorded');
check(GHSHelper::buildDeterminationJson($post([$warning], 'Caution'))['signal_word_override'] === '', 'anything but Danger / Warning counts as Auto');

echo "\nb. Reopening and saving again (the reported problem)\n";
check(GHSHelper::signalWordOverride($det) === '', 'a determination saved on Auto reopens on Auto');
$det3 = GHSHelper::buildDeterminationJson($post([$warning, $danger], GHSHelper::signalWordOverride($det)));
check($det3['signal_word'] === 'Danger' && $det3['signal_word_override'] === '', '... and saved again with a Danger hazard added it follows the selections', $det3);
check(GHSHelper::signalWordOverride($det2) === 'Danger', 'an override reopens as that override');
check(GHSHelper::signalWordOverride(['signal_word' => 'Danger', 'signal_word_override' => '', 'selected_hazards' => json_encode([$warning])]) === '',
    'the recorded choice wins over the word in effect');

echo "\nc. Determinations saved before 2026-10-28 (no recorded choice)\n";
check(GHSHelper::signalWordOverride(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$warning])]) === '', 'saved word = what the selections give: Auto');
check(GHSHelper::signalWordOverride(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$danger])]) === 'Warning', 'saved word differs: kept as an override (nothing printed changes)');
check(GHSHelper::signalWordOverride(['signal_word' => '', 'selected_hazards' => json_encode([$danger])]) === '', 'no saved word: Auto');
check(GHSHelper::signalWordOverride(['signal_word' => 'Danger', 'selected_hazards' => '[]']) === 'Danger', 'a word with no hazards selected (federal prefill, unmapped classes): shown as an override');

echo "\nd. A save that changes nothing changes nothing (bulk-publish staleness)\n";
$rm = GHSHelper::buildDeterminationJson(['hazard_selections' => [$warning]]);   // the raw-material trade-secret form: no signal word choice
check(!array_key_exists('signal_word_override', $rm) && $rm['signal_word'] === 'Warning'
    && array_slice(array_keys($rm), 0, 4) === ['selected_hazards', 'hazard_classes', 'signal_word', 'h_statements'], 'raw-material path: JSON as before (no new key)', array_keys($rm));
$legacyAuto = GHSHelper::buildDeterminationJson($post([$warning], ''));
unset($legacyAuto['signal_word_override']);
$storedAuto = (string) json_encode($legacyAuto);                                  // as saved before 2026-10-28
check(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$warning], '')), $storedAuto) === $storedAuto,
    'an older determination saved again unchanged (on Auto) keeps its stored JSON');
$legacyOver = GHSHelper::buildDeterminationJson($post([$danger], 'Warning'));
unset($legacyOver['signal_word_override']);
$storedOver = (string) json_encode($legacyOver);
check(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$danger], 'Warning')), $storedOver) === $storedOver,
    '... and so does one saved again unchanged with its override');
$toAuto = json_decode(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$danger], '')), $storedOver), true);
check(($toAuto['signal_word'] ?? '') === 'Danger' && ($toAuto['signal_word_override'] ?? null) === '', 'switched to Auto: stored with the recorded choice', $toAuto);
$changed = json_decode(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$warning], '') + ['basis' => 'Supplier SDS']), $storedAuto), true);
check(($changed['basis'] ?? '') === 'Supplier SDS' && array_key_exists('signal_word_override', $changed), 'any other change: stored in full');
$newFmt = GHSHelper::buildDeterminationJson($post([$warning], ''));
check(GHSHelper::determinationJsonForUpdate($newFmt, (string) json_encode($newFmt)) === (string) json_encode($newFmt), 'a determination saved since: an unchanged save stores the same text');
$adm = (string) file_get_contents($basePath . '/src/Controllers/AdminController.php');
check(str_contains($adm, "\\SDS\\Services\\GHSHelper::determinationJsonForUpdate(\$determination, \$stored['determination_json'] ?? null)"), 'the edit save uses it');

echo "\ne. The form\n";
$src = str_replace("\r\n", "\n", (string) file_get_contents($basePath . '/src/Views/admin/determination-form.php'));
$src = (string) preg_replace("/include dirname\\(__DIR__\\) \\. '\\/layouts\\/(main|footer)\\.php';/", '', $src);
$render = static function (array $det, array $old = []) use ($src): array {
    $_SESSION['_flash']['_old_input'] = $old;
    $item = ['id' => 7, 'cas_number' => '64-17-5', 'determination' => $det, 'rationale_text' => 'x', 'is_active' => 1];
    $mode = $det === [] ? 'create' : 'edit';
    $rawMaterials = [];
    ob_start();
    eval('?>' . $src);
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp  = new DOMXPath($dom);
    $sel = $xp->query("//select[@name='signal_word']/option[@selected]");
    $hint = str_contains($html, 'Saved as an override.');
    return [$sel->length ? $sel->item(0)->getAttribute('value') : '', $hint];
};
[$picked, $hint] = $render($det);
check($picked === '' && !$hint, 'saved on Auto: the list shows Auto', [$picked, $hint]);
[$picked, $hint] = $render(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$warning]), 'h_statements' => 'H226']);
check($picked === '' && !$hint, 'older determination saved on Auto: Auto', [$picked, $hint]);
[$picked, $hint] = $render($det2);
check($picked === 'Danger' && $hint, 'an override: picked, with a note naming the word the selections give', [$picked, $hint]);
[$picked, $hint] = $render([], ['signal_word' => 'Warning']);
check($picked === 'Warning', 'a new determination sent back with an error keeps the choice made', $picked);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
