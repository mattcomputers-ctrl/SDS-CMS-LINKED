#!/usr/bin/env php
<?php
/**
 * Bulk publish language order test (DB-free)
 *
 * Owner request 2026-10-19: a bulk publish (page button, Bump ALL's next run,
 * the hourly CMS-sync run) publishes English first, then Spanish, German and
 * French, so the sheets needed most often land early. Checks:
 *   a. the publish order of the configured languages;
 *   b. planWorkerBatches(): each source on one worker, at most one batch per
 *      worker, every batch sorted by language (stable), private-label items
 *      with English, nothing lost or duplicated, batches balanced;
 *   c. missingLanguages(): a current version missing languages (a stopped or
 *      killed run) is completed at the same version; retired sheets stay retired;
 *   d. the runner, eligibility, work-item builder and worker are wired to it.
 *
 * Run:
 *   php tests/Services/BulkPublishLanguageOrderTest.php
 *
 * Exit code: 0 = passed, 1 = failed.
 */

declare(strict_types=1);

$basePath = dirname(__DIR__, 2);

require_once $basePath . '/vendor/autoload.php';

use SDS\Controllers\BulkPublishController as BP;

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
        echo '       actual: ' . var_export($actual, true) . "\n";
    }
}

/** Source key used by the planner. */
function src_key(array $it): string
{
    return (($it['type'] ?? '') === 'resale') ? 'rm' . $it['rm_id'] : 'fg' . $it['id'];
}

/** A unique id for an item, to compare item sets. */
function item_id(array $it): string
{
    return src_key($it) . '|' . ($it['type'] ?? 'fg') . '|' . ($it['alias_id'] ?? '-') . '|' . ($it['pl_item_id'] ?? '-') . '|' . $it['language'];
}

/**
 * A work list shaped like buildWorkItems(): per FG the base items in config
 * order, then per alias its items, then private-label items; resale after.
 */
function sample_items(array $languages, int $fgCount, int $resaleCount): array
{
    $items = [];
    for ($fg = 1; $fg <= $fgCount; $fg++) {
        foreach ($languages as $lang) {
            $items[] = ['id' => $fg, 'product_code' => "FG{$fg}", 'language' => $lang, 'version' => 2];
        }
        for ($a = 1; $a <= $fg % 4; $a++) {
            foreach ($languages as $lang) {
                $items[] = ['id' => $fg, 'product_code' => "FG{$fg}", 'language' => $lang, 'version' => 5, 'alias_id' => $fg * 100 + $a];
            }
        }
        if ($fg % 5 === 0) {
            $items[] = ['type' => 'private_label', 'id' => $fg, 'product_code' => "FG{$fg}", 'language' => 'all', 'version' => 0, 'pl_item_id' => $fg * 10];
        }
    }
    for ($rm = 1; $rm <= $resaleCount; $rm++) {   // rm ids overlap the FG ids on purpose
        foreach ($languages as $lang) {
            $items[] = ['type' => 'resale', 'rm_id' => $rm, 'product_code' => "RM{$rm}", 'language' => $lang, 'version' => 1];
        }
        if ($rm % 3 === 0) {
            foreach ($languages as $lang) {
                $items[] = ['type' => 'resale', 'rm_id' => $rm, 'product_code' => "RM{$rm}", 'language' => $lang, 'version' => 1, 'alias_id' => 9000 + $rm];
            }
        }
    }
    return $items;
}

// ---------------------------------------------------------------------
echo "a. Publish order\n";
check(BP::PUBLISH_LANGUAGE_ORDER === ['en', 'es', 'de', 'fr'], 'owner order: English, Spanish, German, French');
check(BP::publishLanguageOrder(['en', 'es', 'fr', 'de']) === ['en', 'es', 'de', 'fr'], 'shipped config order en, es, fr, de -> en, es, de, fr');
check(BP::publishLanguageOrder(['de', 'fr', 'es', 'en']) === ['en', 'es', 'de', 'fr'], 'any config order -> en, es, de, fr');
check(BP::publishLanguageOrder(['en', 'pt', 'fr', 'it', 'es']) === ['en', 'es', 'fr', 'pt', 'it'], 'other languages follow, in config order');
check(BP::publishLanguageOrder(['es', 'fr']) === ['es', 'fr'], 'a language that is not configured is not added');
check(BP::publishLanguageOrder(['en', 'en', 'es']) === ['en', 'es'], 'duplicates dropped');
$order = BP::publishLanguageOrder(['en', 'es', 'fr', 'de']);
check(BP::languageRank(['language' => 'all'], $order) === 0, "private label ('all') ranks with English");
check(BP::languageRank(['language' => 'de'], $order) === 2 && BP::languageRank(['language' => 'fr'], $order) === 3, 'German before French');
check(BP::languageRank(['language' => 'pt'], $order) === 4, 'unknown language last');

// ---------------------------------------------------------------------
echo "\nb. planWorkerBatches\n";
$languages = ['en', 'es', 'fr', 'de'];   // config order on the server
$items     = sample_items($languages, 60, 25);
$sources   = count(array_unique(array_map('src_key', $items)));   // 85
$parts     = count(array_unique(array_map(fn ($it) => src_key($it) . '|' . BP::languageRank($it, $order), $items)));
foreach ([1, 3, 8, 16, 80, 500] as $workers) {
    $batches = BP::planWorkerBatches($items, $workers, $order);
    $label   = "{$workers} workers";
    $splitRun = $sources < $workers;   // small run: sources split by language

    $expectBatches = $splitRun ? min($workers, $parts) : min($workers, $sources);
    check(count($batches) === $expectBatches, "{$label}: exactly {$expectBatches} batches (every worker busy, never more than one per worker)", count($batches));

    $flat = array_merge(...$batches);
    $a = array_map('item_id', $items);
    $b = array_map('item_id', $flat);
    sort($a);
    sort($b);
    check($a === $b, "{$label}: every item exactly once");

    $where = [];
    $split = [];
    foreach ($batches as $bi => $batch) {
        foreach ($batch as $it) {
            // A small run may split a source by language, never within one.
            $k = $splitRun ? src_key($it) . '|' . BP::languageRank($it, $order) : src_key($it);
            if (isset($where[$k]) && $where[$k] !== $bi) {
                $split[$k] = true;
            }
            $where[$k] = $bi;
        }
    }
    check($split === [], $splitRun
        ? "{$label}: small run: each source's language (with its aliases; private label with English) on one worker"
        : "{$label}: no source split across workers (one computeBase per source)", array_keys($split));
    check($splitRun || isset($where['fg5'], $where['rm5']), "{$label}: resale rm 5 and FG 5 are different sources");

    $sorted = true;
    $stable = true;
    $plOk   = true;
    foreach ($batches as $batch) {
        $prev = -1;
        foreach ($batch as $it) {
            $r = BP::languageRank($it, $order);
            if ($r < $prev) {
                $sorted = false;
            }
            $prev = $r;
        }
        // Stable: within one language the batch keeps the original order,
        // so a source's base item comes before its alias items.
        foreach (array_merge(['all'], $order) as $lang) {
            $inBatch = array_map('item_id', array_values(array_filter($batch, fn ($it) => $it['language'] === $lang)));
            $ids     = array_flip(array_map('item_id', $batch));
            $orig    = array_map('item_id', array_values(array_filter($items, fn ($it) => $it['language'] === $lang && isset($ids[item_id($it)]))));
            if ($inBatch !== $orig) {
                $stable = false;
            }
        }
        // A private-label item comes right after its FG's English items.
        foreach ($batch as $pos => $it) {
            if (($it['type'] ?? '') !== 'private_label') {
                continue;
            }
            $prevIt = $batch[$pos - 1] ?? null;
            if ($prevIt === null || src_key($prevIt) !== src_key($it) || !in_array($prevIt['language'], ['en', 'all'], true)) {
                $plOk = false;
            }
            foreach ($batch as $p2 => $other) {
                if (src_key($other) === src_key($it) && $other['language'] === 'en' && $p2 > $pos) {
                    $plOk = false;
                }
            }
        }
    }
    check($sorted, "{$label}: each batch is English, then Spanish, German, French");
    check($stable, "{$label}: within a language the original order is kept (base before alias)");
    check($plOk, "{$label}: private-label items right after their FG's English items");

    $sizes   = array_map('count', $batches);
    $biggest = $splitRun ? 1 + 3 + 1 : 4 * (1 + 3) + 1;   // the largest group in the sample
    check(max($sizes) <= (int) ceil(count($items) / count($batches)) + $biggest, "{$label}: batches balanced (largest first, least-loaded)", $sizes);
}
// One huge source (an FG with 40 aliases) among small ones: it gets a worker
// of its own instead of landing on top of an already full batch.
$skewed = sample_items($languages, 40, 0);
foreach ($languages as $lang) {
    for ($a = 1; $a <= 40; $a++) {
        $skewed[] = ['id' => 999, 'product_code' => 'FG999', 'language' => $lang, 'version' => 2, 'alias_id' => 99900 + $a];
    }
}
$sk = BP::planWorkerBatches($skewed, 8, $order);
$withBig = array_values(array_filter($sk, fn ($b) => in_array('fg999', array_map('src_key', $b), true)));
check(count($withBig) === 1 && count(array_unique(array_map('src_key', $withBig[0]))) === 1, 'a huge source gets a batch of its own (LPT)');
$batches = BP::planWorkerBatches($items, 8, $order);
$firstEsAt = [];
foreach ($batches as $bi => $batch) {
    foreach ($batch as $pos => $it) {
        if ($it['language'] === 'es') {
            $firstEsAt[$bi] = $pos;
            break;
        }
    }
    $enCount = count(array_filter($batch, fn ($it) => in_array($it['language'], ['en', 'all'], true)));
    check(($firstEsAt[$bi] ?? $enCount) === $enCount, "batch {$bi}: all its English items before its first Spanish item");
}
check(BP::planWorkerBatches([], 8, $order) === [], 'no items: no batches');
$plOnly = [
    ['type' => 'private_label', 'id' => 1, 'language' => 'all', 'pl_item_id' => 1],
    ['type' => 'private_label', 'id' => 2, 'language' => 'all', 'pl_item_id' => 2],
];
check(array_merge(...BP::planWorkerBatches($plOnly, 4, $order)) === $plOnly, 'private-label-only run (blank company phone): unchanged');
$one = BP::planWorkerBatches(sample_items($languages, 3, 0), 1, $order);
check(count($one) === 1 && $one[0][0]['language'] === 'en' && end($one[0])['language'] === 'fr', 'one worker: one batch, English first, French last');
// An hourly run with 2 products and 80 workers: 8 batches (2 x 4 languages),
// so the languages publish side by side instead of one worker per product.
$small = BP::planWorkerBatches(sample_items($languages, 2, 0), 80, $order);
check(count($small) === 8, 'small run (2 products, 80 workers): split by language into 8 batches', count($small));
check(count(array_filter($small, fn ($b) => count(array_unique(array_column($b, 'language'))) === 1)) === 8, 'small run: each batch is one language of one product');

// ---------------------------------------------------------------------
echo "\nc. missingLanguages (stopped or killed runs)\n";
$v = fn (?int $live, ?int $any = null) => ['live' => $live, 'any' => $any ?? $live];
check(BP::missingLanguages(['en' => $v(3), 'es' => $v(3), 'fr' => $v(3), 'de' => $v(3)], $languages) === null, 'all languages at the current version: complete');
check(BP::missingLanguages([], $languages) === null, 'never published: not a completion (a normal first publish)');
check(BP::missingLanguages(['en' => $v(4), 'es' => $v(4), 'fr' => $v(3), 'de' => $v(3)], $languages) === ['version' => 4, 'missing' => ['fr', 'de']],
    'run stopped after Spanish: French and German completed at version 4', BP::missingLanguages(['en' => $v(4), 'es' => $v(4), 'fr' => $v(3), 'de' => $v(3)], $languages));
check(BP::missingLanguages(['en' => $v(4)], $languages) === ['version' => 4, 'missing' => ['es', 'fr', 'de']], 'languages never published at all are missing too');
check(BP::missingLanguages(['en' => $v(4), 'es' => $v(4), 'fr' => $v(4), 'de' => $v(3, 4)], $languages) === null, 'German v4 retired on purpose (deleted row): stays retired');
check(BP::missingLanguages(['en' => $v(4), 'es' => $v(4), 'fr' => $v(4), 'de' => $v(4), 'it' => $v(9)], $languages) === null, 'a language no longer configured is ignored');
check(BP::missingLanguages(['en' => $v(null, 5), 'es' => $v(4), 'fr' => $v(4), 'de' => $v(4)], $languages) === null, 'current version = highest live version (a fully retired v5 does not count)');

echo "\nc2. Freshness from the current version's FIRST sheet (mid-run changes)\n";
$t = fn (?int $live, ?string $last, ?int $any = null) => ['live' => $live, 'any' => $any ?? $live, 'last' => $last];
// English written 08:00 from data computed then; the rest 10:00-12:00 from the
// same cached data. A raw material changed at 09:00 must keep it stale.
$run = ['en' => $t(5, '2026-10-19 08:00:00'), 'es' => $t(5, '2026-10-19 10:00:00'), 'de' => $t(5, '2026-10-19 11:00:00'), 'fr' => $t(5, '2026-10-19 12:00:00')];
check(BP::currentVersionPublishedAt($run, $languages) === '2026-10-19 08:00:00', 'freshness = the first sheet of the current version (08:00), not the last (12:00)');
check(BP::currentVersionPublishedAt($run, $languages) < '2026-10-19 09:00:00', 'a change at 09:00 is newer: the product stays stale and gets a full new version');
$part = ['en' => $t(5, '2026-10-19 08:00:00'), 'es' => $t(5, '2026-10-19 10:00:00'), 'de' => $t(4, '2026-09-01 00:00:00'), 'fr' => $t(4, '2026-09-01 00:00:00')];
check(BP::currentVersionPublishedAt($part, $languages) === '2026-10-19 08:00:00', 'languages still at the older version do not count');
check(BP::currentVersionPublishedAt(['en' => $t(null, null, 5)], $languages) === null, 'nothing live: never published');
check(BP::currentVersion($part, $languages) === 5, 'current version 5');

// The first-language sheet retired on purpose: the version still began at 08:00.
$retEn = $run;
$retEn['en'] = ['live' => 4, 'any' => 5, 'last' => '2026-09-01 00:00:00', 'last_any' => '2026-10-19 08:00:00'];
check(BP::currentVersionPublishedAt($retEn, $languages) === '2026-10-19 08:00:00', 'retiring the first sheet does not move the version\'s start (retired rows count)');

$since = '2026-10-19 08:00:00';
$a = fn (int $v, string $last) => ['v' => $v, 'last' => $last];
$aliasDone = [1 => ['en' => $a(7, '2026-10-19 08:01:00'), 'es' => $a(7, '2026-10-19 10:01:00'), 'fr' => $a(7, '2026-10-19 12:01:00'), 'de' => $a(7, '2026-10-19 11:01:00')]];
check(!BP::aliasesIncomplete($aliasDone, $since, $languages), 'alias with all four sheets from the run: complete');
$aliasCut = [1 => $aliasDone[1], 2 => ['en' => $a(3, '2026-10-19 08:02:00'), 'es' => $a(3, '2026-10-19 10:02:00'), 'de' => $a(3, '2026-10-19 11:02:00'), 'fr' => $a(2, '2026-09-01 00:00:00')]];
check(BP::aliasesIncomplete($aliasCut, $since, $languages), 'run stopped in the French pass after the base sheet: alias French is missing');
$runV = 0;
check(BP::aliasMissingLanguages($aliasCut[2], $since, $languages, $runV) === ['fr'] && $runV === 3, 'the alias needs French at the version the run gave it (v3)', [$runV]);
$aliasNew = [3 => ['en' => $a(2, '2026-09-01 00:00:00'), 'es' => $a(2, '2026-09-01 00:00:00'), 'fr' => $a(2, '2026-09-01 00:00:00'), 'de' => $a(2, '2026-09-01 00:00:00')]];
check(!BP::aliasesIncomplete($aliasNew, $since, $languages), 'alias with no sheet from the run (added later): left to on-demand alias publishing');
// Older parallel runs wrote an alias's English a second before the base's first
// sheet, all at the same version: complete (judged by version, not time).
$aliasParallel = [4 => ['en' => $a(2, '2026-10-19 07:59:59'), 'es' => $a(2, '2026-10-19 08:00:01'), 'fr' => $a(2, '2026-10-19 08:00:00'), 'de' => $a(2, '2026-10-19 08:00:02')]];
check(!BP::aliasesIncomplete($aliasParallel, $since, $languages), 'alias sheets written in parallel at the same version: complete (no endless completion)');
$aliasRetired = [5 => ['en' => $a(7, '2026-10-19 08:01:00'), 'es' => $a(7, '2026-10-19 10:01:00'), 'fr' => $a(7, '2026-10-19 12:01:00'), 'de' => $a(7, '2026-10-19 11:01:00')]];
check(!BP::aliasesIncomplete($aliasRetired, $since, $languages), 'a retired alias sheet counts as done (highest version includes retired rows)');

echo "\nc3. Customer auto-send waits for the customer's languages\n";
use SDS\Services\SDSAutoSendService as AS_;
$holdSince = '2026-10-18 12:00:00';   // now - 24 h
check(AS_::languagesBehind(['en' => 5, 'es' => 4], 5, '2026-10-19 08:00:00', ['es'], $holdSince), 'Spanish customer, English v5 just published, Spanish still v4: wait');
check(!AS_::languagesBehind(['en' => 5, 'es' => 5], 5, '2026-10-19 08:00:00', ['es'], $holdSince), 'Spanish completed at v5: send');
check(!AS_::languagesBehind(['en' => 5, 'es' => 4], 5, '2026-10-19 08:00:00', ['en'], $holdSince), 'English-only customer: send');
check(AS_::languagesBehind(['en' => 5], 5, '2026-10-19 08:00:00', ['en', 'de'], $holdSince), 'German not published yet: wait');
check(!AS_::languagesBehind(['en' => 5, 'es' => 4], 5, '2026-10-17 08:00:00', ['es'], $holdSince), 'after a day: send what there is (never held for good)');
check(AS_::LANGUAGE_CATCH_UP_HOURS === 24, 'the wait is at most 24 hours');

// ---------------------------------------------------------------------
echo "\nd. Wiring\n";
$src = static fn (string $rel): string => (string) file_get_contents($basePath . '/' . $rel);
$runner = $src('cron/bulk-publish.php');
check(str_contains($runner, 'BulkPublishController::planWorkerBatches($workItems, $workerCount, $publishOrder)') && !str_contains($runner, 'array_chunk($workItems'), 'runner splits with planWorkerBatches, not array_chunk');
check(substr_count($runner, 'BulkPublishController::buildWorkItems(') === 1, 'runner builds the work list once per job');
check(str_contains($runner, 'buildWorkItems($db, $fgs, $resale, $languages, $incompleteFgs, $incompleteResale)'), 'runner passes the missing-language entries');
check(str_contains($runner, "\$eligibility['incomplete'] ?? []") && str_contains($runner, "\$resaleEligibility['incomplete'] ?? []"), 'runner reads incomplete FGs and resale items');
check(str_contains($runner, '" pass complete after "'), 'runner logs each language pass');
check(str_contains($runner, '$incompleteFgs === [] && $incompleteResale === []'), 'a run with only missing languages to complete still runs');
$cms = $src('cron/cms-sync.php');
check(str_contains($cms, "require __DIR__ . '/bulk-publish.php';"), 'hourly CMS sync runs the same runner (same order)');
$bp = $src('src/Controllers/BulkPublishController.php');
check(substr_count($bp, "'incomplete' => \$incomplete") === 2, 'FG and resale eligibility both return incomplete entries');
check(substr_count($bp, '$gap = self::missingLanguages(') === 2, 'missing languages checked only for fresh sources (FG and resale)');
check(str_contains($bp, 'array_filter($incomplete, function (array $entry) use ($fgToFormulaId, $walk, $lastPublishedByFg, $eligibleIds)'), 'missing-language completion keeps Rule 3 (upstream FG components)');
check(str_contains($bp, 'array_push($workItems, ...self::completionItems($db, $fg, false, $languages));') && str_contains($bp, 'if ($companyPhoneError === null) {'), 'completion items built, not with a blank company phone');
check(str_contains($bp, "'publishOrder'        => self::publishLanguageOrder(\$languages),"), 'bulk page shows the publish order');
$wk = $src('scripts/publish-worker.php');
$supAt = strpos($wk, 'if ((int) ($supRow[\'v\'] ?? 0) >= $version) {');
$nrAt  = strpos($wk, '$nrKey = \'nr\' . $rmId;');
$fgBaseAt     = strrpos($wk, '$baseDataCache[$fgId] = $generator->computeBase($fgId);');   // FG / alias path (the first one is private label)
$resaleBaseAt = strpos($wk, '$generator->computeBaseForResaleRawMaterial($rmId)');
check($supAt !== false && $nrAt !== false && $nrAt < $supAt && $supAt < $fgBaseAt && $supAt < $resaleBaseAt,
    'worker skips a sheet a manual publish already superseded, before computing anything (after the No SDS required skip)');
$supBlock = $supAt === false ? '' : substr($wk, $supAt, 200);
check(str_contains($supBlock, 'writeWorkerProgress(') && str_contains($supBlock, 'continue;') && !str_contains($supBlock, '$failed++'), 'a superseded sheet is skipped quietly (not failed)');
check(str_contains($wk, '$itemTxError = \SDS\Services\SDSReadinessService::transportNotDeterminedError($sdsData);'), "worker re-checks each item's own Section 14");
check(substr_count($bp, '$lastPub = self::currentVersionPublishedAt($byLang, $languages);') === 2, 'Rule 2 (FG and resale) judges freshness by the current version\'s first sheet');
check(substr_count($bp, 'self::aliasesIncomplete($aliasTimes[') === 2, 'an alias stopped part-way through its languages is completed (FG and resale)');
$plBranch = substr($wk, (int) strpos($wk, 'if ($isPrivateLabel) {'), 6000);
check(str_contains($plBranch, 'PrivateLabelItem::fgLatestBaseVersions([$fgId])') && strpos($plBranch, 'fgLatestBaseVersions') < strpos($plBranch, 'publishItemFromBase'), 'private-label item skipped when a manual publish made a newer base version');
check(str_contains($plBranch, '$plCurrent = $plFresh === count($languages);') && str_contains($plBranch, '>= $workerStartedAt'), 'private-label item skipped when a manual publish of the same version ran during this run');
check(str_contains($bp, "\$incomplete[] = \$entry + \$gap + ['since' => \$lastPub];") && str_contains($bp, "\$since = \$entry['since'] ??"), 'eligibility and completion use the same version start');
$as = $src('src/Services/SDSAutoSendService.php');
$holdAt   = strpos($as, 'if ($this->languagesCatchingUp($customer, (int) $fg[\'id\'], $sdsVersion)) {');
$shouldAt = strpos($as, 'if (!$this->shouldSend($customer, (int) $fg[\'id\'], $itemName, $row[\'date_shipped\'] ?? null, (int) $sdsVersion[\'id\'])) {');
check($holdAt !== false && $shouldAt !== false && $holdAt < $shouldAt, 'auto-send checks the customer\'s languages before deciding to send');
check(str_contains($as, "\$examined = array_values(array_filter(array_column(\$shipments, 'id'), static fn (\$id): bool => !isset(\$heldIds[(int) \$id])));")
    && str_contains($as, 'foreach (array_chunk($examined, 500) as $chunk) {'), 'a waiting line is not marked processed, so a later run sends it');
check(!str_contains($runner, '$allProcessed') && str_contains($runner, 'bp_runPostJobSteps($bp_db);'), 'the runner still sends after every job (the hold is per line, in the database)');
$view = $src('src/Views/admin/bulk-publish.php');
check(str_contains($view, '<?php if ($totalEligible === 0 && (int) ($incompleteCount ?? 0) === 0): ?>'), 'Publish All is offered when only missing languages are due');
$checklist = $src('docs/post-update-checklist.md');
check(str_contains($checklist, 'BulkPublishCompletionLiveTest'), 'the live-DB completion test is in the server checklist');

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
