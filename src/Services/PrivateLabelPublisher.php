<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\App;
use SDS\Core\Database;
use SDS\Models\Manufacturer;
use SDS\Models\PrivateLabelItem;

/**
 * PrivateLabelPublisher — the ONE code path that creates private label SDS
 * versions (private_label_sds rows) for private_label_items.
 *
 *  - publishForFinishedGood(): the cascade. Called by the three live
 *    finished-good publish paths with the base per-language SDS data they
 *    already generated, so the hazard engine runs once per FG.
 *  - republishItems(): the manual paths (item create/edit "Publish now",
 *    per-item Republish, "Republish stale", the SDS-update PL-only button).
 *    Recomputes the base once per FG from live data.
 *
 * Both are safe to call from inside a request that has already committed
 * other rows: they NEVER throw. Each item is all-or-nothing — every
 * language is rendered first and a version number is only consumed when
 * all PDFs succeeded; the rows for one version are inserted in a single
 * transaction.
 */
final class PrivateLabelPublisher
{
    /** manufacturer_id => Manufacturer::toCompanyInfo() array, cached for one call. */
    private array $mfgInfoCache = [];

    /* ------------------------------------------------------------------
     *  Identity resolution
     * ----------------------------------------------------------------*/

    /**
     * Resolve the identity the NEXT publish will print, from a joined
     * PrivateLabelItem row.
     *
     * Code (first set value wins):
     *   custom_code (verbatim) -> strip_pack_extension(alias.customer_code) -> fg.product_code
     * Description (independent chain):
     *   custom_description -> alias.description (when alias_id) -> fg.description
     *
     * @return array{code:string,description:string,source:string}  source: custom | shared_alias | base
     */
    public static function resolveIdentity(array $item): array
    {
        $aliasId    = (int) ($item['alias_id'] ?? 0);
        $hasAlias   = $aliasId > 0;
        $customCode = trim((string) ($item['custom_code'] ?? ''));
        $aliasCode  = trim((string) ($item['alias_customer_code'] ?? ''));

        if ($customCode !== '') {
            $code   = $customCode;
            $source = 'custom';
        } elseif ($hasAlias && $aliasCode !== '') {
            $code   = strip_pack_extension($aliasCode);
            $source = 'shared_alias';
        } else {
            $code   = (string) ($item['fg_product_code'] ?? '');
            $source = 'base';
        }

        $customDesc = trim((string) ($item['custom_description'] ?? ''));
        $aliasDesc  = trim((string) ($item['alias_description'] ?? ''));

        if ($customDesc !== '') {
            $description = $customDesc;
        } elseif ($hasAlias && $aliasDesc !== '') {
            $description = $aliasDesc;
        } else {
            $description = (string) ($item['fg_description'] ?? '');
        }

        return [
            'code'        => $code,
            'description' => $description,
            'source'      => $source,
        ];
    }

    /* ------------------------------------------------------------------
     *  Cascade: called by the FG publish paths
     * ----------------------------------------------------------------*/

    /**
     * Version every active, auto_republish item of a finished good from the
     * caller's already-generated base data. Never throws.
     *
     * @param  int      $fgId             Finished good
     * @param  array    $baseLangData     lang => base SDS data (from generateFromBase)
     * @param  int      $sourceFgVersion  The sds_versions.version just published
     * @param  int|null $userId           Publishing user (null = system / cron)
     * @param  string   $changeSummary    Stored on every row
     * @param  string   $trigger          Audit tag (fg_publish, sds_update_republish, ...)
     * @return array{published:int,failed:string[]}
     */
    public function publishForFinishedGood(
        int $fgId,
        array $baseLangData,
        int $sourceFgVersion,
        ?int $userId,
        string $changeSummary,
        string $trigger
    ): array {
        $result = ['published' => 0, 'failed' => []];

        try {
            $items = PrivateLabelItem::forFinishedGood($fgId, true);

            foreach ($items as $item) {
                try {
                    $r = $this->publishOne($item, $baseLangData, $sourceFgVersion, $userId, $changeSummary, $trigger);
                    if ($r['ok']) {
                        $result['published']++;
                    } else {
                        $result['failed'][] = (string) $r['error'];
                    }
                } catch (\Throwable $e) {
                    $result['failed'][] = self::itemLabel($item) . ': ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $result['failed'][] = 'Private label publish failed: ' . $e->getMessage();
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     *  Manual paths
     * ----------------------------------------------------------------*/

    /**
     * Republish specific items from live data. Never throws.
     *
     * Items are grouped by finished good; per FG the base is computed once
     * (SDSGenerator::computeBase + generateFromBase per configured language).
     * A whole FG group is skipped (skipped[] "FG CODE: reason") when:
     *   - the FG is inactive,
     *   - the FG has no published base SDS ("Publish the base SDS for CODE first"),
     *   - computeBase / generateFromBase throws (e.g. no current formula),
     *   - SDSReadinessService::missingHazardDataError() blocks the first language.
     *   - SDSReadinessService::manufacturerEmergencyPhoneError() refuses the item (failed[]).
     * Retired items (is_active = 0) are skipped individually. Frozen items
     * (auto_republish = 0) ARE republished here — the operator asked.
     *
     * @param  int[]    $itemIds
     * @return array{published:int,failed:string[],skipped:string[],versions:array<int,int>}
     */
    public function republishItems(array $itemIds, ?int $userId, string $changeSummary, string $trigger): array
    {
        $result = ['published' => 0, 'failed' => [], 'skipped' => [], 'versions' => []];

        try {
            $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn (int $v): bool => $v > 0)));
            if (empty($ids)) {
                return $result;
            }

            // Load + group by finished good
            $byFg = [];
            foreach ($ids as $id) {
                $item = PrivateLabelItem::findById($id);
                if ($item === null) {
                    $result['skipped'][] = 'Item #' . $id . ': not found';
                    continue;
                }
                if ((int) $item['is_active'] !== 1) {
                    $result['skipped'][] = self::itemLabel($item) . ': item is retired — restore it first';
                    continue;
                }
                $byFg[(int) $item['finished_good_id']][] = $item;
            }

            $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
            $db        = Database::getInstance();

            foreach ($byFg as $fgId => $fgItems) {
                $fgCode = (string) ($fgItems[0]['fg_product_code'] ?? ('#' . $fgId));

                if ((int) ($fgItems[0]['fg_is_active'] ?? 0) !== 1) {
                    $result['skipped'][] = $fgCode . ': finished good is inactive';
                    continue;
                }

                $fgLatest = PrivateLabelItem::fgLatestBaseVersions([$fgId])[$fgId] ?? null;
                if ($fgLatest === null) {
                    $result['skipped'][] = $fgCode . ': Publish the base SDS for ' . $fgCode . ' first';
                    continue;
                }

                // Compute the base once per FG
                $baseLangData = [];
                $skipReason   = null;
                try {
                    $generator = new SDSGenerator();
                    $base      = $generator->computeBase($fgId);

                    foreach ($languages as $lang) {
                        $sdsData = $generator->generateFromBase($base, $lang);

                        if ($lang === $languages[0]) {
                            $blockError = SDSReadinessService::missingHazardDataError($sdsData, $db);
                            if ($blockError !== null) {
                                $skipReason = $blockError;
                                break;
                            }
                        }

                        $baseLangData[$lang] = $sdsData;
                    }
                } catch (\Throwable $e) {
                    $skipReason = $e->getMessage();
                }

                if ($skipReason !== null) {
                    $result['skipped'][] = $fgCode . ': ' . $skipReason;
                    continue;
                }

                foreach ($fgItems as $item) {
                    try {
                        $r = $this->publishOne($item, $baseLangData, $fgLatest, $userId, $changeSummary, $trigger);
                        if ($r['ok']) {
                            $result['published']++;
                            $result['versions'][(int) $item['id']] = (int) $r['version'];
                        } else {
                            $result['failed'][] = (string) $r['error'];
                        }
                    } catch (\Throwable $e) {
                        $result['failed'][] = self::itemLabel($item) . ': ' . $e->getMessage();
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['failed'][] = 'Private label republish failed: ' . $e->getMessage();
        }

        return $result;
    }

    /* ------------------------------------------------------------------
     *  Core: one item, one version, all languages
     * ----------------------------------------------------------------*/

    /**
     * Publish one new version of one item. ALL-OR-NOTHING:
     *   1. re-validate a shared alias still belongs to the FG,
     *   2. version = MAX(version) WHERE item_id + 1 (read before rendering
     *      so it can go into the PDF filename), resolve identity, build the
     *      private label variant per language with meta.sds_version set,
     *   3. render every PDF (PdfBatchRenderer) — any failure unlinks the
     *      successful PDFs and returns failure WITHOUT consuming a version,
     *   4. in one transaction: re-read MAX(version) and throw if another
     *      publish of this item slipped in since step 2 (the rendered files
     *      would carry a wrong version number), insert one private_label_sds
     *      row per language, commit,
     *   5. audit 'private_label_sds' / 'publish'.
     *
     * @return array{ok:bool,version?:int,error?:string}
     */
    private function publishOne(
        array $item,
        array $baseLangData,
        int $sourceFgVersion,
        ?int $userId,
        string $changeSummary,
        string $trigger
    ): array {
        $itemId  = (int) $item['id'];
        $mfgId   = (int) $item['manufacturer_id'];
        $fgId    = (int) $item['finished_good_id'];
        $aliasId = ((int) ($item['alias_id'] ?? 0) > 0) ? (int) $item['alias_id'] : null;
        $fgCode  = (string) ($item['fg_product_code'] ?? '');
        $mfgName = (string) ($item['manufacturer_name'] ?? ('manufacturer #' . $mfgId));

        $identity = self::resolveIdentity($item);
        $code     = $identity['code'];
        $desc     = $identity['description'];
        $label    = $code . ' / ' . $mfgName;

        // R4 — a CMS sync can re-point an alias to a different product.
        if ($aliasId !== null) {
            $aliasBase = $item['alias_internal_code_base'] ?? null;
            $aliasCode = strip_pack_extension((string) ($item['alias_customer_code'] ?? ('#' . $aliasId)));
            if ($aliasBase === null) {
                return ['ok' => false, 'error' => $label . ': Shared alias #' . $aliasId . ' not found'];
            }
            if (strcasecmp((string) $aliasBase, $fgCode) !== 0) {
                return ['ok' => false, 'error' => $label . ': Shared alias ' . $aliasCode . ' no longer belongs to ' . $fgCode];
            }
        }

        // Manufacturer company info (cached per manufacturer for this call)
        if (!isset($this->mfgInfoCache[$mfgId])) {
            $mfgRow = Manufacturer::findById($mfgId);
            if ($mfgRow === null) {
                return ['ok' => false, 'error' => $label . ': Manufacturer not found'];
            }
            $this->mfgInfoCache[$mfgId] = Manufacturer::toCompanyInfo($mfgRow);
        }
        $mfgInfo = $this->mfgInfoCache[$mfgId];

        // Audit #2 — a private label SDS prints the manufacturer's own
        // emergency number; a blank one is refused before a version is consumed.
        $mfgPhoneError = SDSReadinessService::manufacturerEmergencyPhoneError($mfgInfo);
        if ($mfgPhoneError !== null) {
            return ['ok' => false, 'error' => $label . ': ' . $mfgPhoneError];
        }

        // Next version for this item, read BEFORE rendering so the PDFs are
        // named {code}_PL_{Manufacturer}_v{n}[_{lang}].pdf (meta.sds_version).
        // Nothing is written here; the transaction below re-checks the
        // number before inserting.
        $db      = Database::getInstance();
        $last    = $db->fetch("SELECT MAX(version) AS max_ver FROM private_label_sds WHERE item_id = ?", [$itemId]);
        $version = ((int) ($last['max_ver'] ?? 0)) + 1;

        // Build the variant for every configured language — one code path for all three sources
        $languages = App::config('sds.supported_languages', ['en', 'es', 'fr', 'de']);
        $effectiveDate = date('Y-m-d');
        $variant       = [];
        foreach ($languages as $lang) {
            if (!isset($baseLangData[$lang]) || !is_array($baseLangData[$lang])) {
                return ['ok' => false, 'error' => $label . ': Base SDS data missing for ' . strtoupper((string) $lang)];
            }
            $variant[$lang] = SDSGenerator::createPrivateLabelVariant($baseLangData[$lang], $code, $desc, $mfgInfo);
            $variant[$lang] = SDSGenerator::stampPublishedVersion($variant[$lang], $version, $effectiveDate);
        }

        // Render all PDFs first
        $pdfResults = PdfBatchRenderer::render($variant, 'plpdf_');

        $pdfPaths = [];
        $errors   = [];
        foreach ($languages as $lang) {
            $r = $pdfResults[$lang] ?? null;
            if ($r !== null && ($r['ok'] ?? false) && !empty($r['pdf_path'])) {
                $pdfPaths[$lang] = (string) $r['pdf_path'];
            } else {
                $errors[] = 'PDF failed for ' . strtoupper((string) $lang) . ': ' . ($r['error'] ?? 'unknown error');
            }
        }

        if (!empty($errors)) {
            self::unlinkAll($pdfPaths);
            return ['ok' => false, 'error' => $label . ': ' . implode('; ', $errors)];
        }

        // All PDFs rendered — insert the version in one transaction
        $pdo   = $db->getPdo();
        $ownTx = !$pdo->inTransaction();

        $now      = date('Y-m-d H:i:s');
        $basePath = App::basePath() . '/';

        try {
            if ($ownTx) {
                $db->beginTransaction();
            }

            // Consistency check: the version number is already baked into
            // the rendered filenames, so if a concurrent publish of this item
            // consumed it meanwhile we must not insert under a different
            // number. Throwing here rolls back, unlinks the PDFs and leaves
            // this version unconsumed (the caller can retry).
            $recheck = $db->fetch("SELECT MAX(version) AS max_ver FROM private_label_sds WHERE item_id = ?", [$itemId]);
            $expected = ((int) ($recheck['max_ver'] ?? 0)) + 1;
            if ($expected !== $version) {
                throw new \RuntimeException(
                    'Version changed during publish (expected v' . $version . ', now v' . $expected . ') — retry'
                );
            }

            foreach ($languages as $lang) {
                $db->insert('private_label_sds', [
                    'item_id'             => $itemId,
                    'finished_good_id'    => $fgId,
                    'manufacturer_id'     => $mfgId,
                    'alias_id'            => $aliasId,
                    'language'            => $lang,
                    'product_code'        => $code,
                    'product_description' => $desc,
                    'version'             => $version,
                    'source_fg_version'   => $sourceFgVersion,
                    'status'              => 'published',
                    'effective_date'      => $effectiveDate,
                    'published_by'        => $userId,
                    'published_at'        => $now,
                    'snapshot_json'       => json_encode($variant[$lang], JSON_UNESCAPED_UNICODE),
                    'pdf_path'            => str_replace($basePath, '', $pdfPaths[$lang]),
                    'change_summary'      => $changeSummary !== '' ? $changeSummary : null,
                    'created_by'          => $userId,
                ]);
            }

            if ($ownTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                try {
                    $db->rollback();
                } catch (\Throwable $ignored) {
                    // nothing more to do
                }
            }
            self::unlinkAll($pdfPaths);
            return ['ok' => false, 'error' => $label . ': ' . $e->getMessage()];
        }

        // Rows are committed — an audit failure must not turn this into a publish failure
        try {
            AuditService::log('private_label_sds', (string) $itemId, 'publish', [
                'manufacturer_id'   => $mfgId,
                'manufacturer'      => $mfgName,
                'finished_good_id'  => $fgId,
                'alias_id'          => $aliasId,
                'code'              => $code,
                'description'       => $desc,
                'source'            => $identity['source'],
                'version'           => $version,
                'source_fg_version' => $sourceFgVersion,
                'languages'         => array_map('strtoupper', array_values($languages)),
                'trigger'           => $trigger,
                'change_summary'    => $changeSummary,
            ], $userId);
        } catch (\Throwable $e) {
            error_log('PrivateLabelPublisher: audit log failed for item #' . $itemId . ': ' . $e->getMessage());
        }

        return ['ok' => true, 'version' => $version];
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /** "CODE / Manufacturer" for error strings. */
    private static function itemLabel(array $item): string
    {
        $identity = self::resolveIdentity($item);
        $mfgName  = (string) ($item['manufacturer_name'] ?? ('manufacturer #' . (int) ($item['manufacturer_id'] ?? 0)));
        return $identity['code'] . ' / ' . $mfgName;
    }

    /** Remove orphaned PDFs after a failed (or partially failed) publish. */
    private static function unlinkAll(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
