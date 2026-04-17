<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

/**
 * StaleRmSdsController — list raw materials whose supplier SDS has
 * aged beyond the configured threshold. Grouped by supplier so the
 * regulatory team can batch requests to each vendor.
 *
 * Staleness is measured against `raw_materials.sds_last_confirmed_at`.
 * That column is advanced by either a fresh SDS upload OR the user
 * clicking "Supplier Confirmed Current" on the RM page — both count
 * as a confirmation the SDS is still the latest.
 */
class StaleRmSdsController
{
    public function index(): void
    {
        if (!can_read('stale_rm_sds')) {
            $_SESSION['_flash']['error'] = 'You do not have permission to view the stale RM SDS list.';
            redirect('/');
        }

        $db = Database::getInstance();

        // Threshold from admin settings, default 3 years (1095 days)
        $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sds.rm_stale_days'");
        $staleDays = (int) ($row['value'] ?? 1095);
        if ($staleDays < 1) {
            $staleDays = 1095;
        }

        // Pull RMs whose last-confirmed date is older than the threshold,
        // OR whose last-confirmed date is NULL (never recorded — most stale).
        // Join the newest SDS file row for the "on file since" display.
        $rows = $db->fetchAll(
            "SELECT rm.id,
                    rm.internal_code,
                    rm.supplier,
                    rm.supplier_product_name,
                    rm.supplier_product_code,
                    rm.sds_last_confirmed_at,
                    newest.uploaded_at       AS file_uploaded_at,
                    newest.sds_date_received AS file_date_received,
                    newest.original_filename AS file_name
               FROM raw_materials rm
          LEFT JOIN (
                    SELECT rms1.raw_material_id,
                           rms1.uploaded_at,
                           rms1.sds_date_received,
                           rms1.original_filename
                      FROM raw_material_sds rms1
                INNER JOIN (
                           SELECT raw_material_id, MAX(id) AS max_id
                             FROM raw_material_sds
                         GROUP BY raw_material_id
                    ) rms2 ON rms1.id = rms2.max_id
                    ) newest ON newest.raw_material_id = rm.id
              WHERE rm.sds_last_confirmed_at IS NULL
                 OR DATEDIFF(CURDATE(), rm.sds_last_confirmed_at) >= ?
           ORDER BY COALESCE(rm.supplier, '') ASC,
                    rm.sds_last_confirmed_at ASC,
                    rm.internal_code ASC",
            [$staleDays]
        );

        // Group by supplier for rendering
        $grouped = [];
        foreach ($rows as $r) {
            $supplier = $r['supplier'] ?: '(no supplier)';
            if (!isset($grouped[$supplier])) {
                $grouped[$supplier] = [];
            }
            $grouped[$supplier][] = $r;
        }
        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        view('stale-rm-sds/index', [
            'pageTitle' => 'Stale RM SDS',
            'grouped'   => $grouped,
            'staleDays' => $staleDays,
            'total'     => count($rows),
        ]);
    }
}
