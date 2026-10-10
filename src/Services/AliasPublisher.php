<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;

/**
 * AliasPublisher — an alias's OWN published SDS (audit #53 / #60, owner decision Q9).
 *
 * An alias sheet is the base product's (or resale raw material's) sheet with
 * the alias's pack-stripped base code and description in Section 1, the
 * footer and the PDF Title. Pack variants of one alias (BK1080-2G, BK1080-5G)
 * share ONE sheet, published under one alias id of the group (the publishers'
 * deduplicateAliasesByBaseCode()), so every lookup goes by the group.
 *
 * Senders (SDS send queue, auto-send, shipped-SDS ZIP report) call
 * ensurePublished(): the group's newest published row per language, after
 * publishing the alias first (current data, same gates and steps as the
 * interactive alias publish) when a wanted language has no row or its PDF is
 * missing on disk. Senders never fall back to the base sheet, another alias's
 * sheet or a snapshot re-render.
 */
final class AliasPublisher
{
    /** @var array<int,string> alias id => publish error; a failing alias is tried once per process (cron runs). */
    private static array $failed = [];

    /** Configured sheet languages (config sds.supported_languages). */
    public static function languages(): array
    {
        $langs = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        return (is_array($langs) && $langs !== []) ? array_values(array_map(static fn($l): string => strtolower((string) $l), $langs)) : ['en'];
    }

    /** Alias rows that share one sheet with $alias: same internal_code_base and same pack-stripped customer code. */
    public static function group(array $alias, Database $db): array
    {
        $rows = $db->fetchAll(
            "SELECT id, customer_code, description, internal_code_base
               FROM aliases
              WHERE internal_code_base = ? AND SUBSTRING_INDEX(customer_code, '-', 1) = ?
              ORDER BY customer_code ASC, id ASC",
            [(string) $alias['internal_code_base'], AliasResolver::stripPack((string) $alias['customer_code'])]
        );
        return $rows !== [] ? $rows : [$alias];
    }

    /** Published sds_versions rows (PDF path set) of the alias group. */
    public static function publishedRows(array $alias, Database $db): array
    {
        $ids = array_map(static fn(array $r): int => (int) $r['id'], self::group($alias, $db));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        return $db->fetchAll(
            "SELECT * FROM sds_versions
              WHERE alias_id IN ({$ph}) AND status = 'published' AND is_deleted = 0
                AND pdf_path IS NOT NULL AND pdf_path <> ''",
            $ids
        );
    }

    /**
     * Pure: newest row per language (highest sds_versions.id = last inserted;
     * published_at mixed clocks across publishers before audit #59, so it is
     * not used). Restricted to and ordered by $languages when given.
     *
     * @return array<string,array> lang => row
     */
    public static function latestPerLanguage(array $rows, ?array $languages = null): array
    {
        usort($rows, static fn(array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        $out = [];
        foreach ($rows as $r) {
            $lang = strtolower((string) ($r['language'] ?? ''));
            if ($lang !== '' && !isset($out[$lang])) {
                $out[$lang] = $r;
            }
        }
        if ($languages === null) {
            return $out;
        }
        $ordered = [];
        foreach ($languages as $l) {
            $l = strtolower((string) $l);
            if (isset($out[$l])) {
                $ordered[$l] = $out[$l];
            }
        }
        return $ordered;
    }

    /** Pure: the alias id a publish writes under — the member owning the newest published row, else the first by customer code (publishers' dedupe order). */
    public static function representativeId(array $groupRows, array $publishedRows): int
    {
        if ($publishedRows !== []) {
            usort($publishedRows, static fn(array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
            return (int) $publishedRows[0]['alias_id'];
        }
        return (int) $groupRows[0]['id'];
    }

    /**
     * The alias's own published rows for $languages (null = all configured;
     * unconfigured codes are dropped), publishing it first when any wanted
     * language is missing or its PDF is gone from disk.
     *
     * @return array<string,array> lang => sds_versions row (PDF exists)
     * @throws \RuntimeException alias unknown / unresolvable / publish refused (message says why)
     */
    public static function ensurePublished(int $aliasId, ?int $userId, string $reason, Database $db, ?array $languages = null): array
    {
        $alias = $db->fetch('SELECT id, customer_code, description, internal_code_base FROM aliases WHERE id = ?', [$aliasId]);
        if ($alias === null) {
            throw new \RuntimeException('Alias #' . $aliasId . ' not found.');
        }
        $configured = self::languages();
        $wanted = $languages === null
            ? $configured
            : array_values(array_intersect($configured, array_map(static fn($l): string => strtolower((string) $l), $languages)));
        if ($wanted === []) {
            return [];
        }

        $have = self::onDisk(self::latestPerLanguage(self::publishedRows($alias, $db), $wanted));
        if (count($have) === count($wanted)) {
            return $have;
        }

        if (isset(self::$failed[$aliasId])) {
            throw new \RuntimeException(self::$failed[$aliasId]);
        }
        try {
            self::publish($alias, $userId, $reason, $db);
        } catch (\Throwable $e) {
            self::$failed[$aliasId] = $e->getMessage();
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $have = self::onDisk(self::latestPerLanguage(self::publishedRows($alias, $db), $wanted));
        if (count($have) !== count($wanted)) {
            throw new \RuntimeException('Alias ' . AliasResolver::stripPack((string) $alias['customer_code'])
                . ' SDS still missing for: ' . implode(', ', array_map('strtoupper', array_diff($wanted, array_keys($have)))) . '.');
        }
        return $have;
    }

    /**
     * Publish the alias sheet in every configured language from CURRENT data —
     * the steps of SDSController::publishAliases / publishResaleAliases:
     * computeBase | computeBaseForResaleRawMaterial -> generateFromBase ->
     * publish gates -> createAliasVariant(base code) -> stampPublishedVersion
     * -> PDFService::generate -> sds_versions + sds_generation_trace + audit.
     * Every language renders before any row is written.
     *
     * Gates (same as SDSController::publish and scripts/publish-worker.php):
     * inactive finished good (#69), company emergency phone (#2), trade-secret
     * Prop 65 constituent (#13 / Q4), missing federal hazard data (Q11) and
     * Section 14 "Not determined" (#27 / Q2 / Q3).
     *
     * @return int version number written
     */
    public static function publish(array $alias, ?int $userId, string $reason, Database $db): int
    {
        $code = AliasResolver::stripPack((string) $alias['customer_code']);
        $res  = AliasResolver::resolveByAliasId((int) $alias['id']);
        if ($res === null) {
            throw new \RuntimeException("Alias {$code} does not resolve to a finished good with a current formula or to a resale raw material.");
        }
        if ($res['type'] === 'formula') {
            $inactiveError = SDSReadinessService::inactiveFinishedGoodError($res['fg']);
            if ($inactiveError !== null) {
                throw new \RuntimeException($inactiveError);
            }
        }
        $phoneError = SDSReadinessService::companyEmergencyPhoneErrorFromDb($db);
        if ($phoneError !== null) {
            throw new \RuntimeException($phoneError);
        }

        $gen = new SDSGenerator();
        if ($res['type'] === 'formula') {
            $fgId = (int) $res['fg']['id'];
            $rmId = null;
            $base = $gen->computeBase($fgId);
        } else {
            $fgId = null;
            $rmId = (int) $res['rm']['id'];
            $base = $gen->computeBaseForResaleRawMaterial($rmId);
        }

        $langData = [];
        foreach (self::languages() as $i => $lang) {
            $data = $gen->generateFromBase($base, $lang);
            if ($i === 0) {
                // Same gates, same order as scripts/publish-worker.php.
                $block = SDSReadinessService::tradeSecretProp65Error($data['prop65_result'] ?? ($base['prop65Result'] ?? []))
                    ?? SDSReadinessService::missingHazardDataError($data, $db);
                if ($block !== null) {
                    throw new \RuntimeException($block);
                }
            }
            // Finding #6: Section 14 overrides are stored per language → every language.
            $transportBlock = SDSReadinessService::transportNotDeterminedError($data);
            if ($transportBlock !== null) {
                throw new \RuntimeException($transportBlock);
            }
            $langData[$lang] = SDSGenerator::createAliasVariant($data, $code, (string) ($alias['description'] ?? ''));
        }

        $group     = self::group($alias, $db);
        $groupIds  = array_map(static fn(array $r): int => (int) $r['id'], $group);
        $targetId  = self::representativeId($group, self::publishedRows($alias, $db));
        $ph        = implode(',', array_fill(0, count($groupIds), '?'));
        $last      = $db->fetch("SELECT MAX(version) AS max_ver FROM sds_versions WHERE alias_id IN ({$ph})", $groupIds);
        $version   = ((int) ($last['max_ver'] ?? 0)) + 1;
        // Audit #59 clocks: printed effective date = today in the admin time
        // zone; published_at = UTC (same clock as raw_materials.updated_at).
        $effective = PublishClock::todayLocal();
        $now       = PublishClock::nowUtc();

        $pdf   = new PDFService();
        $paths = [];
        try {
            foreach ($langData as $lang => $d) {
                $langData[$lang] = SDSGenerator::stampPublishedVersion($d, $version, $effective);
                $paths[$lang]    = $pdf->generate($langData[$lang]);
            }
        } catch (\Throwable $e) {
            foreach ($paths as $p) {
                @unlink($p);
            }
            throw new \RuntimeException('Alias ' . $code . ' PDF generation failed: ' . $e->getMessage(), 0, $e);
        }

        $basePrefix = App::basePath() . '/';
        foreach ($langData as $lang => $d) {
            $versionId = $db->insert('sds_versions', [
                'finished_good_id' => $fgId,
                'raw_material_id'  => $rmId,
                'alias_id'         => $targetId,
                'language'         => $lang,
                'version'          => $version,
                'status'           => 'published',
                'effective_date'   => $effective,
                'published_by'     => $userId,
                'published_at'     => $now,
                'snapshot_json'    => SDSGenerator::snapshotJson($d),   // finding #70
                'pdf_path'         => str_replace($basePrefix, '', $paths[$lang]),
                'change_summary'   => $reason, // TEXT column
                'created_by'       => $userId,
            ]);
            $db->insert('sds_generation_trace', [
                'sds_version_id' => $versionId,
                'engine_version' => HazardEngine::ENGINE_VERSION,
                'trace_json'     => json_encode(array_merge($d['hazard_result']['trace'] ?? [], $d['voc_result']['trace'] ?? []), JSON_UNESCAPED_UNICODE),
            ]);
            AuditService::log('sds_version', $versionId, $fgId !== null ? 'publish_alias' : 'publish_resale_alias', [
                'finished_good_id' => $fgId,
                'raw_material_id'  => $rmId,
                'alias_id'         => $targetId,
                'alias_code'       => $alias['customer_code'],
                'language'         => $lang,
                'version'          => $version,
                'source'           => $reason,
            ]);
        }
        return $version;
    }

    /** Keep rows whose PDF exists on disk. */
    private static function onDisk(array $rows): array
    {
        $base = App::basePath() . '/';
        return array_filter($rows, static fn(array $r): bool => is_file($base . ltrim((string) $r['pdf_path'], '/')));
    }
}
