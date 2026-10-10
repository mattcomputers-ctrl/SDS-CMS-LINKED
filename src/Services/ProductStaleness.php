<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * ProductStaleness — per-product "published SDS is out of date" signal (audit #58).
 *
 * Bulk publish (BulkPublishController::computeEligibleFinishedGoods) and the SDS
 * Updates scan compare a product's last published_at with its upstream timestamps:
 * raw materials, constituents, CAS determinations and the current formula, plus
 * finished_goods.updated_at for edits made on the product itself (SDS text edits,
 * the hazard override, printed finished-good columns). Bumping the product row,
 * not its raw materials, republishes this product only and leaves the products
 * that share its raws alone. All timestamps are UTC (audit #59, PublishClock).
 */
final class ProductStaleness
{
    /** finished_goods columns that print on the SDS. */
    public const SDS_COLUMNS = [
        'product_code', 'description', 'recommended_use', 'restrictions_on_use',
        'physical_state', 'color', 'substance_mixture', 'transport_product_type', 'family_id',
    ];

    /**
     * @param  array $diff AuditService::diff() output
     * @return string[]    printed columns that changed (DB-free)
     */
    public static function sdsColumnsChanged(array $diff): array
    {
        return array_values(array_intersect(self::SDS_COLUMNS, array_map('strval', array_keys($diff))));
    }

    /**
     * Mark one finished good's published SDSs stale: bump finished_goods.updated_at
     * (UTC) and, when it has a published base SDS and no pending row, queue an
     * SDS Updates row with $reason.
     */
    public static function markFinishedGood(Database $db, int $fgId, string $reason, ?int $userId): void
    {
        $db->query('UPDATE finished_goods SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$fgId]);

        $hasPublished = $db->fetch(
            "SELECT 1 FROM sds_versions
              WHERE finished_good_id = ? AND alias_id IS NULL AND status = 'published' AND is_deleted = 0
              LIMIT 1",
            [$fgId]
        );
        if (!$hasPublished) {
            return;
        }
        $pending = $db->fetch(
            "SELECT id FROM sds_update_queue WHERE finished_good_id = ? AND status = 'pending' LIMIT 1",
            [$fgId]
        );
        if ($pending) {
            return;
        }
        $db->insert('sds_update_queue', [
            'finished_good_id' => $fgId,
            'reason'           => mb_strimwidth($reason, 0, 500, '...'),
            'source_type'      => 'finished_good',
            'source_id'        => $fgId,
            'queued_by'        => $userId,
        ]);
    }
}
