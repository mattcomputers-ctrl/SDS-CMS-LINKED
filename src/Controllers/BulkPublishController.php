<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\App;
use SDS\Core\CSRF;
use SDS\Core\Database;
use SDS\Services\AuditService;
use SDS\Services\BulkPublishQueue;

/**
 * BulkPublishController — Publish new SDS versions for all finished goods (admin only).
 *
 * Spawns parallel PHP CLI worker processes (getWorkerCount()) to generate
 * SDS data and PDFs across all supported languages with progress tracking.
 * Each worker publishes its English sheets first, then Spanish, German and
 * French (planWorkerBatches(), owner request 2026-10-19).
 */
class BulkPublishController
{
    /** Directory for progress/batch files, relative to project root */
    private const PROGRESS_DIR = '/storage/exports';

    /**
     * Detect the number of available CPU cores.
     */
    public static function getCpuCount(): int
    {
        // Linux: /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = substr_count($cpuinfo, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // macOS / BSD: sysctl
        $result = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
        if ($result !== null && ($cores = (int) trim($result)) > 0) {
            return $cores;
        }

        // nproc (Linux fallback)
        $result = @shell_exec('nproc 2>/dev/null');
        if ($result !== null && ($cores = (int) trim($result)) > 0) {
            return $cores;
        }

        return 1;
    }

    /**
     * Determine worker count for bulk publishing.
     *
     * Workers are I/O-bound (DB + TCPDF + disk), so we use a minimum of 8
     * workers regardless of detected CPU cores, scaling up to 4× cores on
     * larger machines.  Override via admin settings or config sds.publish_workers.
     */
    public static function getWorkerCount(int $totalItems): int
    {
        // Check admin settings (DB) first, fall back to config file
        $db = Database::getInstance();
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.publish_workers'");
        $configured = $row ? (int) $row['value'] : (int) App::config('sds.publish_workers', 0);

        if ($configured > 0) {
            return min($configured, $totalItems);
        }

        // Auto: 4× CPU cores with a floor of 8 (I/O-bound workload benefits
        // from many concurrent workers even on single-core machines)
        $workers = max(8, self::getCpuCount() * 4);

        return min($workers, $totalItems);
    }

    /**
     * Bulk publish language order (owner, 2026-10-19): English first, the
     * sheets needed most often, then Spanish, German and French. Any other
     * configured language follows in config order.
     */
    public const PUBLISH_LANGUAGE_ORDER = ['en', 'es', 'de', 'fr'];

    /**
     * The configured languages in bulk publish order (PUBLISH_LANGUAGE_ORDER
     * first, then the rest in config order). Only bulk publish uses this
     * order; sds.supported_languages keeps its own order everywhere else.
     */
    public static function publishLanguageOrder(array $configured): array
    {
        $configured = array_values(array_unique(array_map('strval', $configured)));
        $first      = array_values(array_intersect(self::PUBLISH_LANGUAGE_ORDER, $configured));
        return array_merge($first, array_values(array_diff($configured, $first)));
    }

    /**
     * Split the work items into at most $workers batches, one per worker, so
     * that English lands first. DB-free.
     *
     *  - All items of one source (FG: base, alias and private-label items;
     *    resale RM: base and alias items) stay in one batch, so the worker
     *    computes the source's data and gates once and reuses them for every
     *    language. Only a run with fewer sources than workers splits sources
     *    by language, so no worker sits idle.
     *  - Exactly min($workers, sources) batches, balanced by item count
     *    (largest source first onto the least-loaded batch).
     *  - Each batch is then sorted by language in $languageOrder, a stable
     *    sort: every worker does all its English items, then Spanish, and so
     *    on. Within a language a source's base item is still followed by its
     *    alias items, and a private-label item ('all' languages) sorts with
     *    the first language, right after its FG's English items.
     *
     * @return array<int, array<int, array>>
     */
    public static function planWorkerBatches(array $items, int $workers, array $languageOrder): array
    {
        if ($items === []) {
            return [];
        }

        $groups = [];
        foreach ($items as $item) {
            $key = (($item['type'] ?? '') === 'resale') ? 'rm' . (int) $item['rm_id'] : 'fg' . (int) $item['id'];
            $groups[$key][] = $item;
        }

        $workers = max(1, $workers);

        // A small run (fewer sources than workers, e.g. an hourly CMS-sync
        // run) would leave workers idle: split each source by language
        // instead (private-label items with English), as the old split did.
        // The parts then compute the source's data once each.
        if (count($groups) < $workers) {
            $parts = [];
            foreach ($groups as $key => $group) {
                foreach ($group as $item) {
                    $parts[$key . '|' . self::languageRank($item, $languageOrder)][] = $item;
                }
            }
            $groups = $parts;
        }

        // Longest group first onto the least-loaded batch (LPT), so every
        // worker gets work and the batches stay even; then each batch keeps
        // its groups in the original (product code) order.
        $batchCount = min($workers, count($groups));
        $keys       = array_keys($groups);
        $firstSeen  = array_flip($keys);
        usort($keys, static fn (string $a, string $b): int =>
            (count($groups[$b]) <=> count($groups[$a])) ?: ($firstSeen[$a] <=> $firstSeen[$b]));
        $loads  = array_fill(0, $batchCount, 0);
        $assign = array_fill(0, $batchCount, []);
        foreach ($keys as $key) {
            $least = 0;
            for ($b = 1; $b < $batchCount; $b++) {
                if ($loads[$b] < $loads[$least]) {
                    $least = $b;
                }
            }
            $assign[$least][] = $key;
            $loads[$least]   += count($groups[$key]);
        }

        $batches = [];
        foreach ($assign as $keysInBatch) {
            usort($keysInBatch, static fn (string $a, string $b): int => $firstSeen[$a] <=> $firstSeen[$b]);
            $batch = [];
            foreach ($keysInBatch as $key) {
                foreach ($groups[$key] as $item) {
                    $batch[] = $item;
                }
            }
            usort($batch, static fn (array $x, array $y): int =>
                self::languageRank($x, $languageOrder) <=> self::languageRank($y, $languageOrder));
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * Per batch, how many of its items belong to each language pass and the
     * passes before it (batches are sorted by language), i.e. how far a
     * worker must get to have finished, say, its English items. DB-free.
     *
     * @return array<int, array<string, int>> batch index => [language => prefix length]
     */
    public static function passEnds(array $batches, array $languageOrder): array
    {
        $ends = [];
        foreach ($batches as $i => $batch) {
            foreach ($languageOrder as $lang) {
                $ends[$i][$lang] = 0;
            }
            foreach ($batch as $pos => $item) {
                $rank = self::languageRank($item, $languageOrder);
                foreach ($languageOrder as $r => $lang) {
                    if ($rank <= $r) {
                        $ends[$i][$lang] = $pos + 1;
                    }
                }
            }
        }
        return $ends;
    }

    /**
     * Items and processed items per language pass, from passEnds() and each
     * worker's processed count (workers process their batch in order).
     * Private-label items count in the first language's pass. DB-free.
     *
     * @param array<int, int> $processedBy batch index => items processed
     * @return array<string, array{items: int, processed: int}>
     */
    public static function passProgress(array $passEnds, array $processedBy, array $languageOrder): array
    {
        $out = [];
        foreach ($languageOrder as $lang) {
            $out[$lang] = ['items' => 0, 'processed' => 0];
        }
        foreach ($passEnds as $i => $ends) {
            $start = 0;
            $done  = (int) ($processedBy[$i] ?? 0);
            foreach ($languageOrder as $lang) {
                $end = (int) ($ends[$lang] ?? $start);
                $out[$lang]['items']     += $end - $start;
                $out[$lang]['processed'] += max(0, min($done, $end) - $start);
                $start = $end;
            }
        }
        return $out;
    }

    /**
     * The language passes of a job for display (queue and publish pages),
     * from bulk_publish_jobs.language_progress (migration 065): in publish
     * order, passes with items only. [] for older jobs or bad JSON. DB-free.
     *
     * @return array<int, array{lang: string, name: string, items: int, processed: int, done_at: ?string}>
     */
    public static function languageProgressRows(?string $json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data) || !is_array($data['order'] ?? null) || !is_array($data['passes'] ?? null)) {
            return [];
        }
        $names = ['en' => 'English', 'es' => 'Spanish', 'de' => 'German', 'fr' => 'French'];
        $rows  = [];
        foreach ($data['order'] as $lang) {
            $p = $data['passes'][$lang] ?? null;
            if (!is_array($p) || (int) ($p['items'] ?? 0) <= 0) {
                continue;
            }
            $rows[] = [
                'lang'      => (string) $lang,
                'name'      => $names[$lang] ?? strtoupper((string) $lang),
                'items'     => (int) $p['items'],
                'processed' => (int) ($p['processed'] ?? 0),
                'done_at'   => isset($p['done_at']) && $p['done_at'] !== '' ? (string) $p['done_at'] : null,
            ];
        }
        return $rows;
    }

    /**
     * Position of an item's language in the publish order. Private-label items
     * ('all') rank with the first language; unknown languages go last.
     */
    public static function languageRank(array $item, array $languageOrder): int
    {
        $lang = (string) ($item['language'] ?? '');
        if ($lang === 'all') {
            return 0;
        }
        $pos = array_search($lang, $languageOrder, true);
        return $pos === false ? count($languageOrder) : (int) $pos;
    }

    /**
     * Languages missing from a source's current version. DB-free.
     *
     * A run that is stopped or killed part-way leaves sources whose newest
     * version exists in some languages only; the all-language staleness rule
     * then sees them as current, so nothing would ever publish the rest.
     * Those languages are published at the SAME version by the next run
     * (buildWorkItems), never as a new version, so a language that keeps
     * failing is retried without new versions of the others.
     *
     * @param array<string, array{live: ?int, any: ?int}> $byLanguage per language: the highest
     *        published version that is not deleted (live) and including retired rows (any).
     *        A language retired on purpose (Admin → SDS Versions) is not "missing".
     * @return array{version: int, missing: string[]}|null null when complete or never published
     */
    public static function missingLanguages(array $byLanguage, array $languages): ?array
    {
        $current = self::currentVersion($byLanguage, $languages);
        if ($current === 0) {
            return null;
        }

        $missing = [];
        foreach ($languages as $lang) {
            if ((int) ($byLanguage[$lang]['any'] ?? 0) < $current) {
                $missing[] = $lang;
            }
        }

        return $missing === [] ? null : ['version' => $current, 'missing' => $missing];
    }

    /** A source's current version: its highest live version in the configured languages (0 = none). DB-free. */
    public static function currentVersion(array $byLanguage, array $languages): int
    {
        $current = 0;
        foreach ($languages as $lang) {
            $current = max($current, (int) ($byLanguage[$lang]['live'] ?? 0));
        }
        return $current;
    }

    /**
     * When the source's current version was FIRST published: the earliest
     * sheet time among the configured languages at that version, retired
     * sheets included. Rule 2 compares this, not the latest sheet, with the
     * newest upstream change (2026-10-19): a worker computes a source's data
     * once, at its first (English) sheet, and writes the other languages later
     * in the run, so a change made after the first sheet must still make the
     * source stale (otherwise the later sheets' times would hide it). The same
     * time is where the alias and completion checks start ('since'), so
     * retiring the first sheet moves none of them. Languages at an older
     * version (still to be completed) do not count. DB-free.
     *
     * @param array<string, array{live: ?int, any?: ?int, last?: ?string, last_any?: ?string}> $byLanguage
     *        last: newest live sheet; last_any: newest sheet including retired ones
     */
    public static function currentVersionPublishedAt(array $byLanguage, array $languages): ?string
    {
        $current = self::currentVersion($byLanguage, $languages);
        if ($current === 0) {
            return null;
        }
        $first = null;
        foreach ($languages as $lang) {
            $row = $byLanguage[$lang] ?? null;
            if ($row === null) {
                continue;
            }
            // The newest sheet of a language whose highest version (retired
            // included) is the current one IS its current-version sheet.
            $at = (int) ($row['any'] ?? 0) === $current ? ($row['last_any'] ?? $row['last'] ?? null)
                : ((int) ($row['live'] ?? 0) === $current ? ($row['last'] ?? null) : null);
            if ($at !== null && ($first === null || $at < $first)) {
                $first = (string) $at;
            }
        }
        return $first;
    }

    /**
     * True when one of a source's aliases got a sheet from the source's
     * current-version run (published at or after $since) but is not at that
     * alias version in every configured language: a run stopped part-way
     * through an alias's languages, e.g. during the last (French) pass.
     * Judged by version, as the worker's superseded check is: a language whose
     * newest sheet (retired included) is at or above the alias's run version
     * is done, whenever it was written, so nothing is flagged that the worker
     * would then skip. An alias with no sheet from that run (added later) is
     * left to on-demand alias publishing, as before. DB-free.
     *
     * @param array<int, array<string, array{v: int, last: string}>> $aliasSheets
     *        alias id => [language => highest version, newest sheet time]
     */
    public static function aliasesIncomplete(array $aliasSheets, string $since, array $languages): bool
    {
        foreach ($aliasSheets as $langs) {
            if (self::aliasMissingLanguages($langs, $since, $languages) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * The languages one alias still needs at its run version (aliasesIncomplete),
     * with that version; [] when it is complete or has no sheet from the run.
     * DB-free.
     *
     * @param array<string, array{v: int, last: string}> $langs
     * @return string[]
     */
    public static function aliasMissingLanguages(array $langs, string $since, array $languages, ?int &$runVersion = null): array
    {
        $runVersion = 0;
        foreach ($langs as $p) {
            if ((string) $p['last'] >= $since) {
                $runVersion = max($runVersion, (int) $p['v']);
            }
        }
        if ($runVersion === 0) {
            return [];
        }
        $missing = [];
        foreach ($languages as $lang) {
            if ((int) ($langs[$lang]['v'] ?? 0) < $runVersion) {
                $missing[] = $lang;
            }
        }
        return $missing;
    }

    /**
     * Per alias base code (aliases.internal_code_base): the aliases bulk
     * publish uses (deduplicateAliasesByBaseCode, in buildWorkItems' order),
     * each with, per language, its highest version and newest sheet time
     * (retired sheets included, so a sheet retired on purpose counts as done).
     *
     * @return array<string, array<int, array<string, array{v: int, last: string}>>>
     */
    private static function aliasSheetTimes(Database $db): array
    {
        $byAlias = [];
        foreach ($db->fetchAll(
            "SELECT alias_id, language, MAX(version) AS v, MAX(published_at) AS last
             FROM sds_versions
             WHERE status = 'published' AND alias_id IS NOT NULL
             GROUP BY alias_id, language"
        ) as $r) {
            if ($r['last'] !== null) {
                $byAlias[(int) $r['alias_id']][(string) $r['language']] = ['v' => (int) $r['v'], 'last' => (string) $r['last']];
            }
        }

        $groups = [];
        foreach ($db->fetchAll("SELECT id, customer_code, description, internal_code_base FROM aliases ORDER BY customer_code") as $a) {
            $groups[(string) $a['internal_code_base']][] = $a;
        }
        $out = [];
        foreach ($groups as $base => $rows) {
            foreach (self::deduplicateAliasesByBaseCode($rows) as $rep) {
                $out[$base][(int) $rep['id']] = $byAlias[(int) $rep['id']] ?? [];
            }
        }
        return $out;
    }

    /**
     * GET /admin/bulk-publish — Show the bulk publish page.
     */
    public function page(): void
    {
        if (!can_read('bulk_publish')) {
            http_response_code(403);
            include dirname(__DIR__) . '/Views/errors/403.php';
            exit;
        }

        $db = Database::getInstance();

        // Compute eligible FGs with full recursion through FG sub-components.
        // Rule 1: every upstream raw material (through any depth of FG nesting)
        //         must have been reviewed by a user OR covered by an active CAS
        //         determination on one of its constituents.
        // Rule 2: the FG's most recent non-alias published SDS must be older
        //         than the newest upstream change (formula, raw material,
        //         constituent, or CAS determination) — otherwise skipped.
        $eligibility = self::computeEligibleFinishedGoods($db);
        $eligibleFgs = $eligibility['eligible'];
        $blockedFgs  = $eligibility['blocked'];

        // Count unique aliases (by base customer code) across eligible FGs only.
        $aliasCount = 0;
        if (!empty($eligibleFgs)) {
            $eligibleIds = array_column($eligibleFgs, 'id');
            $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $aliasStats = $db->fetch(
                "SELECT COALESCE(SUM(sub.alias_cnt), 0) AS alias_count
                 FROM (
                     SELECT fg.id,
                            COUNT(DISTINCT SUBSTRING_INDEX(a.customer_code, '-', 1)) AS alias_cnt
                     FROM aliases a
                     INNER JOIN finished_goods fg ON fg.product_code = a.internal_code_base
                     WHERE fg.id IN ({$placeholders})
                     GROUP BY fg.id
                 ) sub",
                $eligibleIds
            );
            $aliasCount = (int) ($aliasStats['alias_count'] ?? 0);
        }

        // Resale items — purchased raw materials sold as-is under an
        // alias (the alias's internal_code_base points at an RM, not an
        // FG with a formula). Same review-ready rule as FGs: the RM
        // must be reviewed.
        $resaleEligibility = self::computeEligibleResaleItems($db);
        $eligibleResale    = $resaleEligibility['eligible'];
        $blockedResale     = $resaleEligibility['blocked'];

        $resaleAliasCount = 0;
        $eligibleResaleBases = array_values(array_unique(array_column(array_filter(
            $eligibleResale,
            static fn(array $r): bool => !empty($r['alias_representative'])
        ), 'base_code')));
        if (!empty($eligibleResaleBases)) {
            $placeholders = implode(',', array_fill(0, count($eligibleResaleBases), '?'));
            $resaleAliasStats = $db->fetch(
                "SELECT COUNT(DISTINCT SUBSTRING_INDEX(customer_code, '-', 1)) AS cnt
                 FROM aliases
                 WHERE internal_code_base IN ({$placeholders})",
                $eligibleResaleBases
            );
            $resaleAliasCount = (int) ($resaleAliasStats['cnt'] ?? 0);
        }

        // Private label items (active + auto_republish) the worker will
        // regenerate for the eligible FGs — one PDF per item per language.
        $plItemCount = 0;
        if (!empty($eligibleFgs)) {
            $eligibleIds  = array_column($eligibleFgs, 'id');
            $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $plStats = $db->fetch(
                "SELECT COUNT(*) AS cnt
                 FROM private_label_items
                 WHERE finished_good_id IN ({$placeholders}) AND is_active = 1 AND auto_republish = 1",
                $eligibleIds
            );
            $plItemCount = (int) ($plStats['cnt'] ?? 0);
        }

        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        // A blank company phone blocks completing missing languages too (buildWorkItems).
        $companyPhoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
        $incompleteCount   = $companyPhoneError !== null ? 0
            : count($eligibility['incomplete'] ?? []) + count($resaleEligibility['incomplete'] ?? []);

        view('admin/bulk-publish', [
            'pageTitle'           => 'Bulk SDS Publish',
            'companyPhoneError'   => $companyPhoneError,
            'fgCount'             => count($eligibleFgs),
            'blockedCount'        => count($blockedFgs),
            'aliasCount'          => $aliasCount,
            'plItemCount'         => $plItemCount,
            'resaleCount'         => count($eligibleResale),
            'resaleBlockedCount'  => count($blockedResale),
            'resaleBlocked'       => array_slice($blockedResale, 0, 200),
            'resaleAliasCount'    => $resaleAliasCount,
            'langCount'           => count($languages),
            'languages'           => $languages,
            'publishOrder'        => self::publishLanguageOrder($languages),
            'incompleteCount'     => $incompleteCount,
        ]);
    }

    /**
     * Compute the set of finished goods that bulk-publish should process.
     *
     * Walks the formula tree recursively through FG sub-components so that
     * nested intermediates are checked, not just top-level raw materials.
     * A finished good is eligible only when:
     *
     *   1. EVERY raw material in the transitive closure of its formula tree
     *      is user-reviewed. An RM counts as reviewed if either:
     *        (a) it has an audit_log entry with a real user_id, OR
     *        (b) an active competent_person_determinations row exists for
     *            any of its constituent CAS numbers.
     *   2. The FG's latest non-alias published SDS is older than the most
     *      recent upstream change (formula created_at, raw material
     *      updated_at, constituent updated_at, or active CPD updated_at)
     *      —  plus finished_goods.updated_at for product-level edits (SDS text,
     *      hazard override, printed columns; audit #58)
     *      — OR the FG has no published SDS yet.
     *
     * All data is batch-loaded up front; recursion runs in memory with
     * cycle detection and per-formula memoization.
     *
     * 'incomplete' lists FGs that pass both rules' gates and are fresh, but
     * whose current version is missing languages (missingLanguages()); the
     * run publishes just those languages at that version.
     *
     * @return array{eligible:   array<int,array{id:int,product_code:string}>,
     *               blocked:    array<int,array{id:int,product_code:string}>,
     *               incomplete: array<int,array{id:int,product_code:string,version:int,missing:string[]}>}
     */
    public static function computeEligibleFinishedGoods(Database $db): array
    {
        // All active FGs with a current formula — the candidate set.
        $fgs = $db->fetchAll(
            "SELECT fg.id, fg.product_code, fg.updated_at AS fg_updated_at, f.id AS formula_id, f.created_at
             FROM finished_goods fg
             INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
             WHERE fg.is_active = 1
             ORDER BY fg.product_code ASC"
        );

        if (empty($fgs)) {
            return ['eligible' => [], 'blocked' => []];
        }

        // ── Batch-load every table needed for the recursion & rule checks ──

        // formula_id → list of lines (raw_material_id OR finished_good_component_id)
        $linesByFormula = [];
        foreach ($db->fetchAll(
            "SELECT formula_id, raw_material_id, finished_good_component_id FROM formula_lines"
        ) as $r) {
            $linesByFormula[(int) $r['formula_id']][] = [
                'rm' => $r['raw_material_id'] !== null ? (int) $r['raw_material_id'] : null,
                'fg' => $r['finished_good_component_id'] !== null ? (int) $r['finished_good_component_id'] : null,
            ];
        }

        // finished_good_id → current formula_id (for recursion into sub-components)
        $fgToFormulaId       = [];
        $formulaCreatedAt    = [];
        foreach ($db->fetchAll("SELECT id, finished_good_id, created_at FROM formulas WHERE is_current = 1") as $r) {
            $fgToFormulaId[(int) $r['finished_good_id']] = (int) $r['id'];
            $formulaCreatedAt[(int) $r['id']]            = $r['created_at'];
        }

        // Reviewed RM ids (Rule 1) — shared with the per-FG SDS Review page
        // so both views agree on what "reviewed" means.
        $reviewedRms = \SDS\Services\SDSReadinessService::loadReviewedRmIds($db);

        // Per-RM max upstream timestamp = MAX(rm.updated_at, constituents.updated_at, CPDs.updated_at)
        $rmMaxUpstream = [];
        foreach ($db->fetchAll("SELECT id, updated_at FROM raw_materials") as $r) {
            $rmMaxUpstream[(int) $r['id']] = $r['updated_at'];
        }
        foreach ($db->fetchAll(
            "SELECT raw_material_id, MAX(updated_at) AS max_upd
             FROM raw_material_constituents GROUP BY raw_material_id"
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }
        $cpdByCas = [];
        foreach ($db->fetchAll(
            "SELECT cas_number, MAX(updated_at) AS max_upd
             FROM competent_person_determinations WHERE is_active = 1
             GROUP BY cas_number"
        ) as $r) {
            $cpdByCas[$r['cas_number']] = $r['max_upd'];
        }
        if (!empty($cpdByCas)) {
            foreach ($db->fetchAll("SELECT raw_material_id, cas_number FROM raw_material_constituents") as $r) {
                $cpdTs = $cpdByCas[$r['cas_number']] ?? null;
                if ($cpdTs === null) {
                    continue;
                }
                $rmId = (int) $r['raw_material_id'];
                if (!isset($rmMaxUpstream[$rmId]) || $cpdTs > $rmMaxUpstream[$rmId]) {
                    $rmMaxUpstream[$rmId] = $cpdTs;
                }
            }
        }

        // Last published SDS timestamp per FG (non-alias, published, not
        // deleted), and per language the highest version (not deleted /
        // including retired rows) for the missing-language check (2026-10-19).
        $lastPublishedByFg = [];
        $versionsByFg      = [];
        foreach ($db->fetchAll(
            "SELECT finished_good_id, language,
                    MAX(CASE WHEN is_deleted = 0 THEN published_at END) AS last,
                    MAX(published_at)                                   AS last_any,
                    MAX(CASE WHEN is_deleted = 0 THEN version END)      AS live_ver,
                    MAX(version)                                        AS any_ver
             FROM sds_versions
             WHERE status = 'published' AND alias_id IS NULL AND finished_good_id IS NOT NULL
             GROUP BY finished_good_id, language"
        ) as $r) {
            $fgId = (int) $r['finished_good_id'];
            if ($r['last'] !== null && (!isset($lastPublishedByFg[$fgId]) || $r['last'] > $lastPublishedByFg[$fgId])) {
                $lastPublishedByFg[$fgId] = $r['last'];
            }
            $versionsByFg[$fgId][(string) $r['language']] = [
                'live' => $r['live_ver'] !== null ? (int) $r['live_ver'] : null,
                'any'  => $r['any_ver'] !== null ? (int) $r['any_ver'] : null,
                'last' => $r['last'],
                'last_any' => $r['last_any'],
            ];
        }
        $languages  = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $aliasTimes = self::aliasSheetTimes($db);

        // RM code lookup — re-check ingredients that were stored as raw
        // materials but actually match a finished good (with pack extension).
        $rmCodeById = [];
        foreach ($db->fetchAll("SELECT id, internal_code FROM raw_materials") as $r) {
            $rmCodeById[(int) $r['id']] = $r['internal_code'];
        }
        $fgIdByCode = [];
        foreach ($db->fetchAll("SELECT id, product_code FROM finished_goods WHERE is_active = 1") as $r) {
            $fgIdByCode[$r['product_code']] = (int) $r['id'];
        }

        // ── Recursive walker (memoized) ──
        // Returns upstream RM set, FG component dependencies, and max
        // formula timestamp. RM lines whose code matches a finished good
        // (with pack extension stripping) are re-routed as FG deps.
        $cache = [];
        $walk = function (int $formulaId, array $visited) use (
            &$walk, $linesByFormula, $fgToFormulaId, $formulaCreatedAt,
            $rmCodeById, $fgIdByCode, &$cache
        ) {
            if (isset($cache[$formulaId])) {
                return $cache[$formulaId];
            }
            if (isset($visited[$formulaId])) {
                return ['rms' => [], 'fg_deps' => [], 'max_ts' => null];
            }
            $visited[$formulaId] = true;

            $rms    = [];
            $fgDeps = [];
            $maxTs  = $formulaCreatedAt[$formulaId] ?? null;

            foreach ($linesByFormula[$formulaId] ?? [] as $line) {
                $targetFgId = null;

                if ($line['rm'] !== null) {
                    $rmCode  = $rmCodeById[$line['rm']] ?? '';
                    $baseCode = strip_pack_extension($rmCode);
                    $targetFgId = $fgIdByCode[$rmCode] ?? $fgIdByCode[$baseCode] ?? null;

                    if ($targetFgId === null) {
                        $rms[$line['rm']] = true;
                        continue;
                    }
                } elseif ($line['fg'] !== null) {
                    $targetFgId = $line['fg'];
                }

                if ($targetFgId !== null) {
                    $fgDeps[$targetFgId] = true;
                    $subFormulaId = $fgToFormulaId[$targetFgId] ?? null;
                    if ($subFormulaId !== null) {
                        $sub = $walk($subFormulaId, $visited);
                        foreach ($sub['rms'] as $rmId => $_) {
                            $rms[$rmId] = true;
                        }
                        foreach ($sub['fg_deps'] as $depId => $_) {
                            $fgDeps[$depId] = true;
                        }
                        if ($sub['max_ts'] !== null && ($maxTs === null || $sub['max_ts'] > $maxTs)) {
                            $maxTs = $sub['max_ts'];
                        }
                    }
                }
            }

            $cache[$formulaId] = ['rms' => $rms, 'fg_deps' => $fgDeps, 'max_ts' => $maxTs];
            return $cache[$formulaId];
        };

        // ── Evaluate each FG against the two rules ──
        $eligible   = [];
        $blocked    = [];
        $incomplete = [];

        foreach ($fgs as $fg) {
            $result      = $walk((int) $fg['formula_id'], []);
            $upstreamRms = $result['rms'];
            $maxTs       = $result['max_ts'];

            // Rule 1: every upstream RM must be reviewed
            $allReviewed = true;
            foreach ($upstreamRms as $rmId => $_) {
                if (!isset($reviewedRms[$rmId])) {
                    $allReviewed = false;
                    break;
                }
                if (isset($rmMaxUpstream[$rmId]) && ($maxTs === null || $rmMaxUpstream[$rmId] > $maxTs)) {
                    $maxTs = $rmMaxUpstream[$rmId];
                }
            }

            // Audit #58 — product-level content edits move finished_goods.updated_at.
            if (!empty($fg['fg_updated_at']) && ($maxTs === null || $fg['fg_updated_at'] > $maxTs)) {
                $maxTs = $fg['fg_updated_at'];
            }

            $entry = ['id' => (int) $fg['id'], 'product_code' => $fg['product_code']];

            if (!$allReviewed) {
                $blocked[] = $entry;
                continue;
            }

            // Rule 2: skip FGs whose SDS is still fresh — judged by when the
            // current version was FIRST published (currentVersionPublishedAt)
            // — unless the current version is missing languages or alias
            // sheets (a stopped or killed run), which the next run completes
            // at the same version.
            $byLang  = $versionsByFg[(int) $fg['id']] ?? [];
            $lastPub = self::currentVersionPublishedAt($byLang, $languages);
            if ($lastPub !== null && $maxTs !== null && $lastPub >= $maxTs) {
                $gap = self::missingLanguages($byLang, $languages);
                if ($gap === null && self::aliasesIncomplete($aliasTimes[(string) $fg['product_code']] ?? [], $lastPub, $languages)) {
                    $gap = ['version' => self::currentVersion($byLang, $languages), 'missing' => []];
                }
                if ($gap !== null) {
                    $incomplete[] = $entry + $gap + ['since' => $lastPub];
                }
                continue;
            }

            $eligible[] = $entry;
        }

        // ── Rule 3: upstream FG components must be published or eligible ──
        // A downstream FG is blocked if any FG it depends on (directly or
        // transitively) is neither already published nor in the eligible set.
        // Iterate until stable — removing one FG may cascade to others.
        $maxPropPasses = 10;
        for ($propPass = 0; $propPass < $maxPropPasses; $propPass++) {
            $eligibleIds = array_flip(array_column($eligible, 'id'));
            $changed = false;
            $stillEligible = [];

            foreach ($eligible as $entry) {
                $formulaId = $fgToFormulaId[$entry['id']] ?? null;
                if ($formulaId === null) {
                    $blocked[] = $entry;
                    $changed = true;
                    continue;
                }

                $result = $walk($formulaId, []);
                $allDepsReady = true;
                foreach ($result['fg_deps'] as $depFgId => $_) {
                    $isPublished = isset($lastPublishedByFg[$depFgId]);
                    $isEligible  = isset($eligibleIds[$depFgId]);
                    if (!$isPublished && !$isEligible) {
                        $allDepsReady = false;
                        break;
                    }
                }

                if ($allDepsReady) {
                    $stillEligible[] = $entry;
                } else {
                    $blocked[] = $entry;
                    $changed = true;
                }
            }

            $eligible = $stillEligible;
            if (!$changed) {
                break;
            }
        }

        // Missing languages are completed under the same Rule 3: every
        // upstream FG component must be published or eligible. One that is
        // not waits for a later run (it is already published, so not blocked).
        $eligibleIds = array_flip(array_column($eligible, 'id'));
        $incomplete  = array_values(array_filter($incomplete, function (array $entry) use ($fgToFormulaId, $walk, $lastPublishedByFg, $eligibleIds): bool {
            $formulaId = $fgToFormulaId[$entry['id']] ?? null;
            if ($formulaId === null) {
                return false;
            }
            foreach ($walk($formulaId, [])['fg_deps'] as $depFgId => $_) {
                if (!isset($lastPublishedByFg[$depFgId]) && !isset($eligibleIds[$depFgId])) {
                    return false;
                }
            }
            return true;
        }));

        return ['eligible' => $eligible, 'blocked' => $blocked, 'incomplete' => $incomplete];
    }

    /**
     * Compute the set of resale raw materials bulk-publish should process.
     *
     * A resale RM is a raw material whose base internal_code has at least
     * one alias pointing at it AND no matching finished_goods.product_code
     * with a current formula (otherwise the formula path wins). Eligibility
     * rules mirror computeEligibleFinishedGoods:
     *
     *   1. The RM itself must be reviewed (audit_log entry with a real
     *      user_id OR active CPD on one of its constituents).
     *   2. The RM's latest non-alias published SDS (sds_versions.raw_material_id
     *      = rmId, alias_id IS NULL) must be older than the most recent
     *      upstream change (RM updated_at, constituent updated_at, or CPD
     *      updated_at) — or no SDS exists yet.
     *
     * A raw material marked "No SDS required" (migration 064, a non-chemical)
     * is not a candidate at all, so it is neither eligible nor blocked. The
     * filter sits in the outer WHERE, not in the alias-representative
     * subquery: a flagged representative keeps the whole base's alias sheets
     * suppressed, and no unflagged sibling inherits them (AliasResolver still
     * resolves the base to the lowest id). RawMaterial::sqlNeedsSds() keeps
     * this cron-path query working before migration 064 runs.
     *
     * 'incomplete': fresh RMs whose current version is missing languages, as
     * in computeEligibleFinishedGoods().
     *
     * @return array{eligible:   array<int,array{rm_id:int,base_code:string,description:string}>,
     *               blocked:    array<int,array{rm_id:int,base_code:string,description:string}>,
     *               incomplete: array<int,array{rm_id:int,base_code:string,description:string,version:int,missing:string[]}>}
     */
    public static function computeEligibleResaleItems(Database $db): array
    {
        // Candidate RMs: those whose base internal_code matches at least
        // one alias's internal_code_base AND whose base does NOT match
        // any finished_goods.product_code with a current formula. One
        // row per RM (we want to publish per RM, not per alias).
        //
        // Resale import (2026-10-13): a raw material that already has a
        // resale SDS of its own is a candidate too, alias or not, so a sheet
        // first published by hand is kept current. Only the alias
        // representative (lowest id for the base, the row AliasResolver
        // resolves aliases to) carries the alias sheets.
        $candidates = $db->fetchAll(
            "SELECT rm.id AS rm_id,
                    SUBSTRING_INDEX(rm.internal_code, '-', 1) AS base_code,
                    rm.updated_at,
                    rm.supplier_product_name,
                    (rep.rep_id IS NOT NULL) AS alias_representative
             FROM raw_materials rm
             LEFT JOIN (
                 SELECT MIN(r2.id) AS rep_id
                 FROM raw_materials r2
                 INNER JOIN (
                     SELECT DISTINCT internal_code_base
                     FROM aliases
                 ) al ON al.internal_code_base = SUBSTRING_INDEX(r2.internal_code, '-', 1)
                 GROUP BY SUBSTRING_INDEX(r2.internal_code, '-', 1)
             ) rep ON rep.rep_id = rm.id
             WHERE " . \SDS\Models\RawMaterial::sqlNeedsSds('rm') . "
               AND (rep.rep_id IS NOT NULL
                    OR EXISTS (
                        SELECT 1 FROM sds_versions sv
                        WHERE sv.raw_material_id = rm.id
                          AND sv.alias_id IS NULL
                          AND sv.finished_good_id IS NULL
                          AND sv.status = 'published'
                          AND sv.is_deleted = 0
                    ))
               AND NOT EXISTS (
                 SELECT 1 FROM finished_goods fg
                 INNER JOIN formulas f ON f.finished_good_id = fg.id AND f.is_current = 1
                 WHERE fg.product_code = SUBSTRING_INDEX(rm.internal_code, '-', 1)
             )
             ORDER BY rm.id"
        );

        if (empty($candidates)) {
            return ['eligible' => [], 'blocked' => []];
        }

        // Per-RM upstream timestamps for Rule 2.
        $rmIds = array_map(fn($c) => (int) $c['rm_id'], $candidates);

        // Rule 1: the reviewed-RM rule shared with FG eligibility, plus the
        // resale composition rule (no constituents and never published by
        // hand = blocked; resale import 2026-10-13).
        $resaleBlocks = \SDS\Services\SDSReadinessService::resaleAutoPublishBlocks($rmIds, $db);
        $placeholders = implode(',', array_fill(0, count($rmIds), '?'));

        $rmMaxUpstream = [];
        foreach ($db->fetchAll("SELECT id, updated_at FROM raw_materials WHERE id IN ({$placeholders})", $rmIds) as $r) {
            $rmMaxUpstream[(int) $r['id']] = $r['updated_at'];
        }
        foreach ($db->fetchAll(
            "SELECT raw_material_id, MAX(updated_at) AS max_upd
             FROM raw_material_constituents
             WHERE raw_material_id IN ({$placeholders})
             GROUP BY raw_material_id",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }
        // Pull CPDs active on constituents of these RMs and compare.
        foreach ($db->fetchAll(
            "SELECT rmc.raw_material_id, MAX(cpd.updated_at) AS max_upd
             FROM raw_material_constituents rmc
             INNER JOIN competent_person_determinations cpd
                 ON cpd.cas_number = rmc.cas_number AND cpd.is_active = 1
             WHERE rmc.raw_material_id IN ({$placeholders})
             GROUP BY rmc.raw_material_id",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }

        // #45 / #58: resale SDS text edits (migration 059; UTC) make the resale
        // sheet stale without touching raw_materials.updated_at.
        foreach ($db->fetchAll(
            "SELECT raw_material_id, edited_at AS max_upd
             FROM resale_sds_text_edits
             WHERE raw_material_id IN ({$placeholders})",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            if (!isset($rmMaxUpstream[$rmId]) || $r['max_upd'] > $rmMaxUpstream[$rmId]) {
                $rmMaxUpstream[$rmId] = $r['max_upd'];
            }
        }

        // Per RM and language (raw_material_id set, alias_id NULL): the last
        // published time and the highest version, for Rule 2 and the
        // missing-language check (2026-10-19), as for finished goods.
        $versionsByRm = [];
        foreach ($db->fetchAll(
            "SELECT raw_material_id, language,
                    MAX(CASE WHEN is_deleted = 0 THEN published_at END) AS last,
                    MAX(published_at)                                   AS last_any,
                    MAX(CASE WHEN is_deleted = 0 THEN version END)      AS live_ver,
                    MAX(version)                                        AS any_ver
             FROM sds_versions
             WHERE status = 'published'
               AND alias_id IS NULL
               AND raw_material_id IN ({$placeholders})
             GROUP BY raw_material_id, language",
            $rmIds
        ) as $r) {
            $rmId = (int) $r['raw_material_id'];
            $versionsByRm[$rmId][(string) $r['language']] = [
                'live' => $r['live_ver'] !== null ? (int) $r['live_ver'] : null,
                'any'  => $r['any_ver'] !== null ? (int) $r['any_ver'] : null,
                'last' => $r['last'],
                'last_any' => $r['last_any'],
            ];
        }
        $languages  = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $aliasTimes = self::aliasSheetTimes($db);

        $eligible   = [];
        $blocked    = [];
        $incomplete = [];

        foreach ($candidates as $c) {
            $rmId = (int) $c['rm_id'];
            $entry = [
                'rm_id'                => $rmId,
                'base_code'            => $c['base_code'],
                'description'          => (string) ($c['supplier_product_name'] ?? $c['base_code']),
                'alias_representative' => (bool) $c['alias_representative'],
            ];

            if (isset($resaleBlocks[$rmId])) {
                $blocked[] = $entry + ['reason' => $resaleBlocks[$rmId]];
                continue;
            }

            // Rule 2 freshness check, by when the current version was first
            // published (as for finished goods).
            $maxTs   = $rmMaxUpstream[$rmId] ?? null;
            $byLang  = $versionsByRm[$rmId] ?? [];
            $lastPub = self::currentVersionPublishedAt($byLang, $languages);
            if ($lastPub !== null && $maxTs !== null && $lastPub >= $maxTs) {
                // Up to date — skip, not blocked — unless its current version
                // is missing languages or alias sheets (completed at that version).
                $gap = self::missingLanguages($byLang, $languages);
                if ($gap === null && $entry['alias_representative']
                    && self::aliasesIncomplete($aliasTimes[(string) $c['base_code']] ?? [], $lastPub, $languages)) {
                    $gap = ['version' => self::currentVersion($byLang, $languages), 'missing' => []];
                }
                if ($gap !== null) {
                    $incomplete[] = $entry + $gap + ['since' => $lastPub];
                }
                continue;
            }

            $eligible[] = $entry;
        }

        return ['eligible' => $eligible, 'blocked' => $blocked, 'incomplete' => $incomplete];
    }

    /**
     * Build the flat work-items list the parallel workers consume.
     *
     * For each eligible FG: one base work item per language plus one
     * alias-branded work item per deduplicated alias per language, and
     * one work item per private label item (all languages; audit #54);
     * with a blank company emergency phone only private-label items
     * (audit #68).
     * For each eligible resale RM: one base work item under the RM's
     * own code plus one alias-branded work item per alias that points
     * at it via the resale path.
     *
     * Version numbers are pre-computed per item so concurrent workers
     * don't race on MAX(version) lookups.
     *
     * Kept public + static so the CMS sync cron can reuse the same
     * item shape without duplicating the build logic — both callers
     * feed the output straight into publish-worker.php.
     *
     * $incompleteFgs / $incompleteResale (computeEligible*()['incomplete'])
     * add the items that complete a current version in its missing
     * languages (completionItems()). The list is built once per job; the
     * order of items here does not matter, planWorkerBatches() sets it.
     */
    public static function buildWorkItems(
        Database $db,
        array $eligibleFgs,
        array $eligibleResaleItems,
        array $languages,
        array $incompleteFgs = [],
        array $incompleteResale = []
    ): array {
        $workItems = [];
        // Audit #68 — a blank company emergency phone blocks every standard,
        // alias and resale sheet (they print it) but not private-label sheets,
        // which print the manufacturer's own number. With it blank only the
        // private-label items are emitted, against the latest PUBLISHED base
        // version (this run publishes no new base version).
        $companyPhoneError = \SDS\Services\SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
        $baseLanguages     = $companyPhoneError === null ? $languages : [];

        foreach ($eligibleFgs as $fg) {
            $aliases = $companyPhoneError !== null ? [] : self::deduplicateAliasesByBaseCode($db->fetchAll(
                "SELECT id, customer_code, description FROM aliases WHERE internal_code_base = ? ORDER BY customer_code",
                [$fg['product_code']]
            ));

            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions WHERE finished_good_id = ? AND alias_id IS NULL",
                [$fg['id']]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($baseLanguages as $lang) {
                $workItems[] = [
                    'id'           => $fg['id'],
                    'product_code' => $fg['product_code'],
                    'language'     => $lang,
                    'version'      => $nextVersion,
                ];
            }

            foreach ($aliases as $alias) {
                $aliasLastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                    [(int) $alias['id']]
                );
                $aliasNextVersion = ((int) ($aliasLastVersion['max_ver'] ?? 0)) + 1;

                foreach ($languages as $lang) {
                    $workItems[] = [
                        'id'                => $fg['id'],
                        'product_code'      => $fg['product_code'],
                        'language'          => $lang,
                        'version'           => $aliasNextVersion,
                        'alias_id'          => (int) $alias['id'],
                        'alias_code'        => $alias['customer_code'],
                        'alias_description' => $alias['description'],
                    ];
                }
            }

            // Private label items (active + auto_republish) of this FG: ONE work
            // item per item, covering every language (audit #54). The worker
            // publishes it through PrivateLabelPublisher::publishItemFromBase(),
            // which re-checks the shared-alias owner and the manufacturer gates
            // (emergency phone, name), assigns the version inside a transaction
            // and inserts all languages or none, so no version is pre-computed here.
            $plSourceVersion = $companyPhoneError === null
                ? $nextVersion
                : (int) (\SDS\Models\PrivateLabelItem::fgLatestBaseVersions([(int) $fg['id']])[(int) $fg['id']] ?? 0);
            $plError = $plSourceVersion > 0 ? null
                : 'Publish the base SDS for ' . $fg['product_code'] . ' first: it has no published version and the blank company emergency phone stops this run from publishing one';
            foreach (\SDS\Models\PrivateLabelItem::forFinishedGood((int) $fg['id'], true) as $pli) {
                $workItems[] = self::privateLabelWorkItem($fg, $pli, $plSourceVersion, $plError);
            }
        }

        foreach ($companyPhoneError === null ? $eligibleResaleItems : [] as $resale) {   // audit #68
            $rmId     = (int) $resale['rm_id'];
            $baseCode = (string) $resale['base_code'];

            $lastVersion = $db->fetch(
                "SELECT MAX(version) AS max_ver FROM sds_versions
                 WHERE raw_material_id = ? AND alias_id IS NULL",
                [$rmId]
            );
            $nextVersion = ((int) ($lastVersion['max_ver'] ?? 0)) + 1;

            foreach ($languages as $lang) {
                $workItems[] = [
                    'type'         => 'resale',
                    'rm_id'        => $rmId,
                    'product_code' => $baseCode,
                    'language'     => $lang,
                    'version'      => $nextVersion,
                ];
            }

            // Aliases resolve to the base's lowest-id raw material only
            // (AliasResolver::findRawMaterialByBase); a sibling that is a
            // candidate for its own published sheet carries no alias items.
            $resaleAliases = ($resale['alias_representative'] ?? true) ? self::deduplicateAliasesByBaseCode($db->fetchAll(
                "SELECT id, customer_code, description FROM aliases
                 WHERE internal_code_base = ?
                 ORDER BY customer_code",
                [$baseCode]
            )) : [];

            foreach ($resaleAliases as $alias) {
                $aliasLastVersion = $db->fetch(
                    "SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id = ?",
                    [(int) $alias['id']]
                );
                $aliasNextVersion = ((int) ($aliasLastVersion['max_ver'] ?? 0)) + 1;

                foreach ($languages as $lang) {
                    $workItems[] = [
                        'type'              => 'resale',
                        'rm_id'             => $rmId,
                        'product_code'      => $baseCode,
                        'language'          => $lang,
                        'version'           => $aliasNextVersion,
                        'alias_id'          => (int) $alias['id'],
                        'alias_code'        => $alias['customer_code'],
                        'alias_description' => $alias['description'],
                    ];
                }
            }
        }

        // Missing languages of current versions (a stopped or killed run).
        // A blank company phone blocks these sheets like every other base sheet.
        if ($companyPhoneError === null) {
            foreach ($incompleteFgs as $fg) {
                array_push($workItems, ...self::completionItems($db, $fg, false, $languages));
            }
            foreach ($incompleteResale as $resale) {
                array_push($workItems, ...self::completionItems($db, $resale, true, $languages));
            }
        }

        return $workItems;
    }

    /**
     * Work items that complete one source's current version (an 'incomplete'
     * entry: version + missing languages):
     *  - base items for the missing languages, at that version;
     *  - alias items for every language in which the alias has no sheet from
     *    that version's run (published since the version's first sheet), at
     *    the alias version that run gave it, or the next one if it gave none;
     *  - for an FG, its active auto-republish private-label items that are
     *    not yet derived from this version in every configured language
     *    (including items never published, as a normal run would emit them).
     * Items carry 'completion' => true (counted in the runner's log).
     */
    private static function completionItems(Database $db, array $entry, bool $isResale, array $languages): array
    {
        $version = (int) $entry['version'];
        if ($isResale) {
            $srcId  = (int) $entry['rm_id'];
            $code   = (string) $entry['base_code'];
            $srcSql = 'raw_material_id = ? AND alias_id IS NULL';
            $base   = ['type' => 'resale', 'rm_id' => $srcId, 'product_code' => $code];
        } else {
            $srcId  = (int) $entry['id'];
            $code   = (string) $entry['product_code'];
            $srcSql = 'finished_good_id = ? AND alias_id IS NULL';
            $base   = ['id' => $srcId, 'product_code' => $code];
        }

        $items = [];
        foreach ($entry['missing'] as $lang) {
            $items[] = $base + ['language' => $lang, 'version' => $version, 'completion' => true];
        }

        // When this version's run began (currentVersionPublishedAt), the same
        // time the eligibility checks used.
        $since = $entry['since'] ?? $db->fetch(
            "SELECT MIN(published_at) AS t FROM sds_versions WHERE {$srcSql} AND version = ? AND status = 'published'",
            [$srcId, $version]
        )['t'] ?? null;

        $aliases = ($isResale && !($entry['alias_representative'] ?? true)) ? [] : self::deduplicateAliasesByBaseCode($db->fetchAll(
            "SELECT id, customer_code, description FROM aliases WHERE internal_code_base = ? ORDER BY customer_code",
            [$code]
        ));
        foreach ($aliases as $alias) {
            $aliasId = (int) $alias['id'];
            // Per language: highest version and newest sheet (retired included,
            // so a sheet retired on purpose counts as done).
            $langs = [];
            foreach ($db->fetchAll(
                "SELECT language, MAX(version) AS v, MAX(published_at) AS last FROM sds_versions
                 WHERE alias_id = ? AND status = 'published'
                 GROUP BY language",
                [$aliasId]
            ) as $r) {
                if ($r['last'] !== null) {
                    $langs[(string) $r['language']] = ['v' => (int) $r['v'], 'last' => (string) $r['last']];
                }
            }
            // By version, as the worker's superseded check: languages below the
            // version the run gave this alias; if it gave none, the next one.
            $runVersion = 0;
            $todo = $since !== null ? self::aliasMissingLanguages($langs, (string) $since, $languages, $runVersion) : [];
            if ($runVersion === 0) {
                $runVersion = ((int) ($db->fetch("SELECT MAX(version) AS v FROM sds_versions WHERE alias_id = ?", [$aliasId])['v'] ?? 0)) + 1;
                $todo       = $languages;
            }
            foreach ($todo as $lang) {
                $items[] = $base + [
                    'language'          => $lang,
                    'version'           => $runVersion,
                    'alias_id'          => $aliasId,
                    'alias_code'        => $alias['customer_code'],
                    'alias_description' => $alias['description'],
                    'completion'        => true,
                ];
            }
        }

        if (!$isResale) {
            $plItems = \SDS\Models\PrivateLabelItem::forFinishedGood($srcId, true);
            $latest  = $plItems === [] ? [] : \SDS\Models\PrivateLabelItem::latestByItem(array_column($plItems, 'id'));
            foreach ($plItems as $pli) {
                // Only configured languages count; an item with none yet (the
                // stopped run never reached it) is published too, as a normal
                // run would have.
                $sources = [];
                foreach (array_intersect_key($latest[(int) $pli['id']] ?? [], array_flip($languages)) as $row) {
                    $sources[] = $row['source_fg_version'] ?? 0;
                }
                if ($sources === [] || min($sources) < $version) {
                    $items[] = self::privateLabelWorkItem(['id' => $srcId, 'product_code' => $code], $pli, $version, null)
                        + ['completion' => true];
                }
            }
        }

        return $items;
    }

    /**
     * One bulk work item for a private label item (audit #54), covering every
     * language. DB-free. The identity is resolved only for the worker's error
     * strings: PrivateLabelPublisher re-resolves it at publish time.
     */
    public static function privateLabelWorkItem(array $fg, array $pli, int $sourceFgVersion, ?string $plError): array
    {
        $identity = \SDS\Services\PrivateLabelPublisher::resolveIdentity($pli);
        return [
            'type'              => 'private_label',
            'id'                => (int) $fg['id'],
            'product_code'      => (string) $fg['product_code'],
            'language'          => 'all',
            'version'           => 0,   // assigned by PrivateLabelPublisher inside its transaction
            'source_fg_version' => $sourceFgVersion,
            'pl_item_id'        => (int) $pli['id'],
            'manufacturer_id'   => (int) $pli['manufacturer_id'],
            'pl_code'           => $identity['code'],
            'pl_source'         => $identity['source'],
            'pl_error'          => $plError,
        ];
    }

    /**
     * POST /admin/bulk-publish/start — Begin bulk publish with parallel workers.
     *
     * The work list has one item per (source, language) PDF; planWorkerBatches()
     * keeps each source on one worker and orders every worker's batch by
     * language, English first.
     */
    public function start(): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $userId  = current_user_id();
        $jobId   = BulkPublishQueue::enqueue('manual', $userId);

        // Flush JSON response before spawning the background runner so
        // the admin UI doesn't block on FPM until the orchestrator exits.
        $this->jsonResponse(['job_id' => $jobId]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Spawn cron/bulk-publish.php as a detached background process.
        // The script enqueue+drain logic handles everything from here:
        //   - If no runner is active, it acquires the lock and runs our job.
        //   - If a runner is active (cron mid-flight, or another manual
        //     click), it exits cleanly and the active runner picks up our
        //     pending row when it finishes the current job.
        //
        // setsid + </dev/null detaches from the FPM child so the process
        // survives request teardown, same pattern the worker spawn uses.
        $basePath = App::basePath();
        $logFile  = $basePath . '/storage/logs/cms-sync.log';
        $cmd = sprintf(
            'setsid %s %s --triggered-by=manual --user-id=%s < /dev/null >> %s 2>&1 &',
            escapeshellarg(php_cli_binary()),
            escapeshellarg($basePath . '/cron/bulk-publish.php'),
            escapeshellarg((string) (int) $userId),
            escapeshellarg($logFile)
        );
        exec($cmd);
    }

    /**
     * GET /bulk-publish/queue — admin view of the publish-job queue.
     */
    public function queue(): void
    {
        if (!can_read('bulk_publish')) {
            redirect('/');
            return;
        }

        $db = Database::getInstance();
        $jobs = $db->fetchAll(
            "SELECT bpj.*, u.display_name AS user_name
             FROM bulk_publish_jobs bpj
             LEFT JOIN users u ON u.id = bpj.triggered_by_user_id
             ORDER BY bpj.queued_at DESC
             LIMIT 50"
        );

        // Active worker count — helps the admin confirm a dead runner
        // if a 'running' job has been sitting idle with no workers.
        $activeWorkers = 0;
        $psOutput = @shell_exec("pgrep -af 'scripts/publish-worker\\.php' 2>/dev/null");
        if (is_string($psOutput) && $psOutput !== '') {
            $activeWorkers = count(array_filter(explode("\n", trim($psOutput))));
        }

        view('admin/bulk-publish-queue', [
            'pageTitle'     => 'Bulk Publish Queue',
            'jobs'          => $jobs,
            'activeWorkers' => $activeWorkers,
        ]);
    }

    /**
     * GET /bulk-publish/queue/{id}/status — JSON status for live polling.
     */
    public function queueStatus(string $id): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $db  = Database::getInstance();
        $job = $db->fetch(
            "SELECT * FROM bulk_publish_jobs WHERE id = ?",
            [(int) $id]
        );
        if (!$job) {
            $this->jsonResponse(['error' => 'Job not found'], 404);
            return;
        }
        $this->jsonResponse(['job' => $job]);
    }

    /**
     * POST /bulk-publish/queue/{id}/dismiss — cancel a pending job.
     *
     * Safe: just marks the row failed so the drainer skips it. No
     * processes to kill because pending jobs aren't running yet.
     */
    public function dismissQueued(string $id): void
    {
        if (!can_edit('bulk_publish')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/bulk-publish/queue');
            return;
        }
        CSRF::validateRequest();

        $db  = Database::getInstance();
        $job = $db->fetch("SELECT id, status FROM bulk_publish_jobs WHERE id = ?", [(int) $id]);
        if (!$job) {
            $_SESSION['_flash']['error'] = 'Job not found.';
            redirect('/bulk-publish/queue');
            return;
        }
        if ($job['status'] !== 'pending') {
            $_SESSION['_flash']['error'] = 'Only pending jobs can be dismissed. Use "Force fail" for running jobs.';
            redirect('/bulk-publish/queue');
            return;
        }

        $db->update('bulk_publish_jobs', [
            'status'        => 'failed',
            'completed_at'  => \SDS\Services\PublishClock::nowUtc(),
            'error_message' => 'Dismissed from queue by admin (' . (current_user_id() ?? '?') . ')',
        ], 'id = ?', [(int) $id]);

        AuditService::log('bulk_publish_job', $id, 'dismiss');
        $_SESSION['_flash']['success'] = "Job #{$id} dismissed from the queue.";
        redirect('/bulk-publish/queue');
    }

    /**
     * POST /bulk-publish/queue/{id}/force-fail — force-fail a stuck
     * running job and kill any orphaned worker processes.
     *
     * Use when a 'running' row has been sitting with no active workers
     * (runner crashed) or when workers are hung and should be stopped.
     */
    public function forceFailQueued(string $id): void
    {
        if (!can_edit('bulk_publish')) {
            $_SESSION['_flash']['error'] = 'Permission denied.';
            redirect('/bulk-publish/queue');
            return;
        }
        CSRF::validateRequest();

        $db  = Database::getInstance();
        $job = $db->fetch("SELECT id, status FROM bulk_publish_jobs WHERE id = ?", [(int) $id]);
        if (!$job) {
            $_SESSION['_flash']['error'] = 'Job not found.';
            redirect('/bulk-publish/queue');
            return;
        }
        if ($job['status'] !== 'running') {
            $_SESSION['_flash']['error'] = 'Only running jobs can be force-failed.';
            redirect('/bulk-publish/queue');
            return;
        }

        // Kill any surviving worker processes. SIGTERM gives each worker
        // a chance to finish its current item cleanly before exiting.
        @shell_exec("pkill -SIGTERM -f 'scripts/publish-worker\\.php' 2>/dev/null");

        $db->update('bulk_publish_jobs', [
            'status'        => 'failed',
            'completed_at'  => \SDS\Services\PublishClock::nowUtc(),
            'error_message' => 'Force-failed from queue by admin (' . (current_user_id() ?? '?') . '); workers sent SIGTERM',
        ], 'id = ?', [(int) $id]);

        AuditService::log('bulk_publish_job', $id, 'force_fail');
        $_SESSION['_flash']['warning'] = "Job #{$id} force-failed. Any orphaned worker processes were sent SIGTERM — verify with `pgrep -af publish-worker`.";
        redirect('/bulk-publish/queue');
    }

    /**
     * POST /admin/bulk-publish/stop/{token} — signal all in-flight workers
     * to stop after their current item. Writes a stop-flag file that every
     * worker polls at the top of each iteration; on observing it the
     * worker writes its final progress and exits cleanly.
     *
     * Already-completed items stay — this is a soft cancel, not a rollback.
     */
    public function stop(string $token): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }
        CSRF::validateRequest();

        $token = preg_replace('/[^a-f0-9]/', '', $token);
        if ($token === '') {
            $this->jsonResponse(['error' => 'Invalid token.'], 400);
            return;
        }

        $flagPath = App::basePath() . self::PROGRESS_DIR . '/publish_stop_' . $token . '.flag';
        if (!is_dir(dirname($flagPath))) {
            @mkdir(dirname($flagPath), 0755, true);
        }
        @file_put_contents($flagPath, date('c') . ' stop requested by user ' . (int) current_user_id() . "\n");

        AuditService::log('sds_bulk_publish', $token, 'stop_requested', [
            'user_id' => current_user_id(),
        ]);

        $this->jsonResponse(['stopped' => true]);
    }

    /** Seconds without progress before we declare workers crashed. */
    private const STALL_TIMEOUT = 60;

    /**
     * GET /admin/bulk-publish/progress/{token} — Aggregate worker progress.
     */
    public function progress(string $token): void
    {
        if (!can_edit('bulk_publish')) {
            $this->jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $token       = preg_replace('/[^a-f0-9]/', '', $token);
        $progressDir = App::basePath() . self::PROGRESS_DIR;
        $manifestFile = $progressDir . '/publish_manifest_' . $token . '.json';

        if (!file_exists($manifestFile)) {
            // Fall back to master file for initial poll before manifest is written
            $masterFile = $progressDir . '/publish_progress_' . $token . '.json';
            if (file_exists($masterFile)) {
                $data = json_decode(file_get_contents($masterFile), true);
                $this->jsonResponse($data ?: ['error' => 'Invalid progress data.']);
                return;
            }
            $this->jsonResponse(['error' => 'Progress not found.'], 404);
            return;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) {
            $this->jsonResponse(['error' => 'Invalid manifest.']);
            return;
        }

        $totalItems     = (int) $manifest['total'];
        $totalProcessed = 0;
        $totalPublished = 0;
        $totalFailed    = 0;
        $allErrors      = [];
        $allComplete    = true;
        $workerFiles    = $manifest['worker_files'] ?? [];
        $logFiles       = $manifest['log_files'] ?? [];
        $stalledWorkers = 0;
        $now            = time();

        foreach ($workerFiles as $idx => $workerFile) {
            if (!file_exists($workerFile)) {
                $allComplete = false;
                // Worker never wrote progress — check if its log exists (crashed on startup)
                if (isset($logFiles[$idx]) && file_exists($logFiles[$idx])
                    && filesize($logFiles[$idx]) > 0
                    && $now - filemtime($logFiles[$idx]) > self::STALL_TIMEOUT) {
                    $stalledWorkers++;
                }
                continue;
            }
            $wData = json_decode(file_get_contents($workerFile), true);
            if (!$wData) {
                $allComplete = false;
                continue;
            }

            $totalProcessed += (int) ($wData['processed'] ?? 0);
            $totalPublished += (int) ($wData['published'] ?? 0);
            $totalFailed    += (int) ($wData['failed'] ?? 0);
            $allErrors       = array_merge($allErrors, $wData['errors'] ?? []);

            if (empty($wData['complete'])) {
                $allComplete = false;
                // Detect stalled worker: progress file not updated recently
                if ($now - filemtime($workerFile) > self::STALL_TIMEOUT) {
                    $stalledWorkers++;
                }
            }
        }

        $percent = $totalItems > 0 ? round(($totalProcessed / $totalItems) * 100, 1) : 0;

        // Workers crashed — treat as finished with errors
        $crashed = !$allComplete && $stalledWorkers > 0
            && $stalledWorkers >= count(array_filter($workerFiles, function ($f) {
                if (!file_exists($f)) return true;
                $d = json_decode(file_get_contents($f), true);
                return !$d || empty($d['complete']);
            }));

        if ($allComplete || $crashed) {
            // Collect worker log output for display
            $workerLogs = self::collectWorkerLogs($logFiles);

            $summary = $totalPublished . ' PDFs published';
            if ($totalFailed > 0) {
                $summary .= ', ' . $totalFailed . ' failed';
            }
            if ($crashed) {
                $summary .= ' (' . $stalledWorkers . ' worker(s) crashed)';
            }

            $response = [
                'current'     => $allComplete ? $totalItems : $totalProcessed,
                'total'       => $totalItems,
                'percent'     => $allComplete ? 100 : $percent,
                'message'     => ($crashed ? 'Bulk publish stopped — ' : 'Bulk publish complete! ') . $summary . '.',
                'complete'    => true,
                'error'       => $crashed,
                'published'   => $totalPublished,
                'failed'      => $totalFailed,
                'errors'      => $allErrors,
                'worker_logs' => $workerLogs,
            ];

            // Clean up progress/manifest/stop-flag files (keep logs when
            // there were problems so operators can diagnose).
            foreach ($workerFiles as $wf) {
                @unlink($wf);
            }
            @unlink($manifestFile);
            @unlink($manifest['master_file'] ?? '');
            @unlink(App::basePath() . self::PROGRESS_DIR . '/publish_stop_' . $token . '.flag');

            // Clean up log files only if everything succeeded
            if (!$crashed && $totalFailed === 0) {
                foreach ($logFiles as $lf) {
                    @unlink($lf);
                }
            }

            AuditService::log('sds_bulk_publish', '0', $crashed ? 'crashed' : 'complete', [
                'published' => $totalPublished,
                'failed'    => $totalFailed,
                'crashed'   => $stalledWorkers,
            ]);

            $this->jsonResponse($response);
            return;
        }

        $this->jsonResponse([
            'current'  => $totalProcessed,
            'total'    => $totalItems,
            'percent'  => $percent,
            'message'  => 'Publishing... ' . $totalProcessed . '/' . $totalItems
                . ' (' . count($workerFiles) . ' workers)',
            'complete' => false,
            'error'    => false,
        ]);
    }

    /**
     * Read non-empty worker log files and return their contents keyed by worker index.
     */
    private static function collectWorkerLogs(array $logFiles): array
    {
        $logs = [];
        foreach ($logFiles as $idx => $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }
            $content = file_get_contents($logFile);
            if ($content !== false && trim($content) !== '') {
                // Cap per-worker log at 4 KB to keep the JSON response reasonable
                if (strlen($content) > 4096) {
                    $content = '...(truncated)...' . "\n" . substr($content, -4096);
                }
                $logs['Worker ' . $idx] = $content;
            }
        }
        return $logs;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Strip the pack extension from a code (everything after the first "-").
     */
    public static function stripPackExtension(string $code): string
    {
        $pos = strpos($code, '-');
        return $pos !== false ? substr($code, 0, $pos) : $code;
    }

    /**
     * Deduplicate alias rows by base customer code (pack extension stripped).
     *
     * Returns one row per unique base code, using the first occurrence's id
     * and description, with customer_code set to the base (no pack extension).
     */
    public static function deduplicateAliasesByBaseCode(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $baseCode = self::stripPackExtension($row['customer_code']);
            if (isset($seen[$baseCode])) {
                continue;
            }
            $seen[$baseCode] = true;
            $row['customer_code'] = $baseCode;
            $result[] = $row;
        }

        return $result;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
