<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

class DashboardController
{
    public function index(): void
    {
        $db = Database::getInstance();

        $row = $db->fetch(
            "SELECT COUNT(DISTINCT finished_good_id) AS fg_count,
                    COUNT(DISTINCT raw_material_id) AS rm_count
             FROM sds_versions
             WHERE status = 'published' AND is_deleted = 0"
        );

        view('dashboard/index', [
            'pageTitle'    => 'Dashboard',
            'publishedFgCount' => (int) ($row['fg_count'] ?? 0),
            'publishedRmCount' => (int) ($row['rm_count'] ?? 0),
        ]);
    }
}
