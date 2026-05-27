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
            "SELECT COUNT(*) AS total
             FROM sds_versions
             WHERE status = 'published' AND is_deleted = 0"
        );

        view('dashboard/index', [
            'pageTitle'      => 'Dashboard',
            'publishedCount' => (int) ($row['total'] ?? 0),
        ]);
    }
}
