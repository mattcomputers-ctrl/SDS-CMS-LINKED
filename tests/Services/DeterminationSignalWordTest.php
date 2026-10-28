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
 * determinations never recorded the choice and none was a manual override
 * (owner), so they open on Auto. The engine side (the word follows the
 * hazards on every SDS) is in CpdAutoSignalWordTest.
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
check(GHSHelper::signalWordOverride(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$danger])]) === '', 'a word stuck by the old form (differs from the selections): Auto, so the next save corrects it');
check(GHSHelper::signalWordOverride(['signal_word' => '', 'selected_hazards' => json_encode([$danger])]) === '', 'no saved word: Auto');
check(GHSHelper::signalWordOverride(['signal_word' => 'Danger', 'selected_hazards' => '[]']) === '', 'federal prefill with a word but no mapped hazards: Auto');

echo "\nd. A save that changes nothing changes nothing (bulk-publish staleness)\n";
$rm = GHSHelper::buildDeterminationJson(['hazard_selections' => [$warning]]);   // the raw-material trade-secret form: no signal word choice
check(!array_key_exists('signal_word_override', $rm) && $rm['signal_word'] === 'Warning'
    && array_slice(array_keys($rm), 0, 4) === ['selected_hazards', 'hazard_classes', 'signal_word', 'h_statements'], 'raw-material path: JSON as before (no new key)', array_keys($rm));
$legacyAuto = GHSHelper::buildDeterminationJson($post([$warning], ''));
unset($legacyAuto['signal_word_override']);
$storedAuto = (string) json_encode($legacyAuto);                                  // as saved before 2026-10-28
check(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$warning], '')), $storedAuto) === $storedAuto,
    'an older determination saved again unchanged (on Auto) keeps its stored JSON');
$legacyStuck = GHSHelper::buildDeterminationJson($post([$danger], 'Warning'));
unset($legacyStuck['signal_word_override']);
$storedStuck = (string) json_encode($legacyStuck);                                // Warning stuck on a Danger hazard
$toAuto = json_decode(GHSHelper::determinationJsonForUpdate(GHSHelper::buildDeterminationJson($post([$danger], '')), $storedStuck), true);
check(($toAuto['signal_word'] ?? '') === 'Danger' && ($toAuto['signal_word_override'] ?? null) === '', 'an older one with a stuck word, saved again (it opens on Auto): the hazards\' word, Auto recorded', $toAuto);
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
    $ticked = [];
    foreach ($xp->query("//input[@name='h_codes_manual[]'][@checked]") as $cb) {
        $ticked[] = $cb->getAttribute('value');
    }
    $GLOBALS['tickedP'] = [];
    foreach ($xp->query("//input[@name='p_codes_manual[]'][@checked]") as $cb) {
        $GLOBALS['tickedP'][] = $cb->getAttribute('value');
    }
    $leftover = $xp->query("//*[@id='leftover-h-codes']");
    return [$sel->length ? $sel->item(0)->getAttribute('value') : '', $hint, $ticked, $leftover->length ? trim((string) preg_replace('/\s+/', ' ', $leftover->item(0)->textContent)) : ''];
};
[$picked, $hint] = $render($det);
check($picked === '' && !$hint, 'saved on Auto: the list shows Auto', [$picked, $hint]);
[$picked, $hint] = $render(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$warning]), 'h_statements' => 'H226']);
check($picked === '' && !$hint, 'older determination saved on Auto: Auto', [$picked, $hint]);
[$picked, $hint] = $render(['signal_word' => 'Warning', 'selected_hazards' => json_encode([$danger]), 'h_statements' => 'H225']);
check($picked === '' && !$hint, 'older determination with a stuck word: Auto too', [$picked, $hint]);
[$picked, $hint] = $render($det2);
check($picked === 'Danger' && $hint, 'an override: picked, with a note naming the word the selections give', [$picked, $hint]);
echo "\nf. H-codes: only codes the selections do not give are ticked by hand\n";
$skinIrr = 'Skin Irritation - Category 2';   // H315
[, , $ticked, $left] = $render(['selected_hazards' => json_encode([$skinIrr]), 'h_statements' => 'H315', 'signal_word' => 'Warning']);
check($ticked === [] && $left === '', 'H315 comes from Skin Irritation: not ticked as manual, so deselecting the hazard later drops it', $ticked);
[, , $ticked, $left] = $render(['selected_hazards' => json_encode([$skinIrr]), 'h_statements' => 'H315, H335', 'signal_word' => 'Warning']);
check($ticked === ['H335'] && $left === '', 'a code added by hand (H335, another class) stays ticked, no note', $ticked);
[, , $ticked, $left] = $render(['selected_hazards' => json_encode([$skinIrr]), 'h_statements' => 'H314, H315', 'signal_word' => 'Danger']);
check($ticked === ['H314'] && str_contains($left, 'H314') && str_contains($left, 'Skin Corrosion'), 'H314 left from Skin Corrosion: ticked, and named as probably left from an earlier category', [$ticked, $left]);
$irrP = implode(', ', \SDS\Services\GHSHazardData::all()[$skinIrr]['p_codes']);
$render(['selected_hazards' => json_encode([$skinIrr]), 'h_statements' => 'H315', 'p_statements' => $irrP . ', P271', 'signal_word' => 'Warning']);
check($GLOBALS['tickedP'] === ['P271'], 'P-codes the same way: only one added by hand (P271) is ticked', $GLOBALS['tickedP']);
$render(['selected_hazards' => json_encode(['Acute Toxicity Inhalation - Category 4']), 'h_statements' => 'H332', 'p_statements' => 'P261, P271, P281, P304+P340, P312', 'signal_word' => 'Warning']);
check(in_array('P280', $GLOBALS['tickedP'], true), 'an older hand-added P281 ticks P280 (saved as P280, not dropped)', $GLOBALS['tickedP']);
[, , $ticked, $left] = $render(['selected_hazards' => json_encode(['Flammable Liquids - Category 3', $skinIrr]), 'h_statements' => 'H225, H226, H315', 'signal_word' => 'Warning']);
check($ticked === ['H225'] && $left === '', 'a Flammable Liquids code left over (H225) is ticked but not named: it never prints from a determination', [$ticked, $left]);
[, , $ticked, $left] = $render(['selected_hazards' => json_encode([$skinIrr]), 'h_statements' => 'H314, H315', 'signal_word' => 'Warning', 'signal_word_override' => 'Warning']);
check(str_contains($left, 'override (Warning) is below the Danger they need'), 'with a manual override the note says the override is below what the codes need', $left);
$freeText = static function (string $classes) use ($render, $src): array {
    $_SESSION['_flash']['_old_input'] = [];
    $item = ['id' => 7, 'cas_number' => '64-17-5', 'determination' => ['hazard_classes' => $classes, 'h_statements' => 'H350', 'signal_word' => 'Danger'], 'rationale_text' => 'x', 'is_active' => 1];
    $mode = 'edit';
    $rawMaterials = [];
    ob_start();
    eval('?>' . $src);
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($dom);
    $keys = [];
    foreach ($xp->query("//input[contains(@class,'hazard-checkbox')][@checked]") as $cb) {
        $keys[] = $cb->getAttribute('data-key');
    }
    $warnNode = $xp->query("//*[@id='free-text-classes']");
    return [$keys, $warnNode->length === 1 ? trim((string) preg_replace('/\s+/', ' ', $warnNode->item(0)->textContent)) : ''];
};
[$keys, $warn] = $freeText('Carcinogenicity Category 1B');
check($keys === ['Carcinogenicity - Category 1B'] && $warn === '', 'a free-text class that matches exactly ("Carcinogenicity Category 1B") is ticked, so saving keeps it', [$keys, $warn]);
[$keys, $warn] = $freeText('Carcinogenicity');
check($keys === [] && str_contains($warn, 'this CAS prints no hazards'), 'one that does not match is named: saving without ticking it would clear it', [$keys, $warn]);
[$keys, $warn] = $freeText('Skin Irritation Category 2, Carcinogenicity');
check($keys === ['Skin Irritation - Category 2'] && str_contains($warn, 'Carcinogenicity') && !str_contains($warn, 'prints no hazards'),
    '... with another class matched, the note says only the named classes would stop printing', [$keys, $warn]);
[, , $ticked, $left] = $render(['selected_hazards' => json_encode(['Aquatic Chronic - Category 2', $skinIrr]), 'h_statements' => 'H315, H410, H411', 'signal_word' => 'Warning']);
check($ticked === ['H410'] && $left === '', 'an aquatic code left over (H410) is not named: aquatic codes never print from a determination', [$ticked, $left]);
$_SESSION['_flash']['_old_input'] = ['h_codes_manual' => ['H335'], 'p_codes_manual' => ['P261'], 'signal_word' => ''];
$item = ['id' => 0, 'cas_number' => '', 'determination' => [], 'rationale_text' => '', 'is_active' => 1];
$mode = 'create';
$rawMaterials = [];
ob_start();
try {
    eval('?>' . $src);
    $okRender = true;
} catch (\Throwable $e) {
    $okRender = $e->getMessage();
}
ob_end_clean();
check($okRender === true, 'a new determination sent back with codes ticked (posted as lists) opens without an error', $okRender);
$saved = GHSHelper::buildDeterminationJson(['selected_hazards_json' => json_encode([$warning]), 'h_codes_manual' => [], 'signal_word' => '']);
check($saved['h_statements'] === 'H226', 'saved with the hazard changed and nothing ticked by hand: only the new hazard\'s codes', $saved['h_statements']);

echo "\ng. New determinations\n";
[$picked, $hint] = $render([], ['signal_word' => 'Warning']);
check($picked === 'Warning', 'a new determination sent back with an error keeps the choice made', $picked);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
