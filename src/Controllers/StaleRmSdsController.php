<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

/**
 * StaleRmSdsController — list supplier SDSs that have aged beyond the
 * configured threshold. One row per (raw material, supplier): each
 * supplier's current SDS is its newest upload, and a material sourced
 * from two vendors shows under each. Grouped by supplier so the
 * regulatory team can batch requests to each vendor.
 *
 * Staleness is measured against `raw_material_sds.sds_last_confirmed_at`
 * on that supplier's current row. It is advanced by either a fresh
 * upload for that supplier OR "Supplier Confirmed Current" on the RM
 * page / "Mark Current" here — both count as a confirmation the SDS is
 * still the latest.
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

        // One row per (raw material, supplier): the newest upload for each
        // supplier is that supplier's current SDS, and staleness is
        // measured against THAT row's sds_last_confirmed_at. A material
        // sourced from two vendors therefore shows under each vendor with
        // its own age. RMs with no SDS on file at all are appended (never
        // recorded — the most stale case) under the RM's CMS supplier.
        $rows = $db->fetchAll(
            "SELECT rm.id,
                    rm.internal_code,
                    rm.supplier_product_name,
                    rm.supplier_product_code,
                    cur.id                                          AS sds_id,
                    COALESCE(NULLIF(cur.supplier, ''), rm.supplier) AS supplier,
                    cur.sds_last_confirmed_at,
                    cur.uploaded_at                                 AS file_uploaded_at,
                    cur.sds_date_received                           AS file_date_received,
                    cur.original_filename                           AS file_name
               FROM raw_material_sds cur
               JOIN (
                    SELECT raw_material_id, supplier, MAX(id) AS max_id
                      FROM raw_material_sds
                  GROUP BY raw_material_id, supplier
                    ) latest ON latest.max_id = cur.id
               JOIN raw_materials rm ON rm.id = cur.raw_material_id
              WHERE cur.sds_last_confirmed_at IS NULL
                 OR DATEDIFF(?, cur.sds_last_confirmed_at) >= ?
          UNION ALL
             SELECT rm.id,
                    rm.internal_code,
                    rm.supplier_product_name,
                    rm.supplier_product_code,
                    NULL, rm.supplier, NULL, NULL, NULL, NULL
               FROM raw_materials rm
              WHERE NOT EXISTS (SELECT 1 FROM raw_material_sds s WHERE s.raw_material_id = rm.id)
           ORDER BY supplier ASC,
                    sds_last_confirmed_at ASC,
                    internal_code ASC",
            // sds_last_confirmed_at is a local DATE; CURDATE() is UTC since audit #59.
            [date('Y-m-d'), $staleDays]
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
