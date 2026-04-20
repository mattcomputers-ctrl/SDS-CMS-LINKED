<?php

declare(strict_types=1);

namespace SDS\Controllers;

class DashboardController
{
    /**
     * Landing page — two quick-find search cards, one for finished-good SDSs
     * and one for raw-material SDSs. No database queries on load; the
     * former stat-counts / recent-activity dashboard was slow at scale
     * (14k+ SDSs) and the information wasn't actionable from the dashboard
     * anyway. Both forms submit to existing list/search endpoints.
     */
    public function index(): void
    {
        view('dashboard/index', [
            'pageTitle' => 'Dashboard',
        ]);
    }
}
