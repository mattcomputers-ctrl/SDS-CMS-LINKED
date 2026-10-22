<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;
use SDS\Services\ReviewCounts;

class DashboardController
{
    public function index(): void
    {
        view('dashboard/index', [
            'pageTitle'    => 'Dashboard',
            'reviewCounts' => can_read('cas_determinations') ? self::reviewCounts(isset($_GET['refresh'])) : null,
        ]);
    }

    /**
     * CAS Determinations counts (2026-10-22): the stored ones while fresh
     * (ReviewCounts::TTL; every visit to CAS Determinations stores them),
     * else computed from the same lists the page shows, and stored. A
     * failure never takes the dashboard down: ['unavailable' => true].
     *
     * @return array{needs:int, tsca:int, stot:int, at:string}|array{unavailable:true}
     */
    private static function reviewCounts(bool $refresh): array
    {
        if (!$refresh && ($cached = ReviewCounts::read()) !== null) {
            return $cached;
        }
        try {
            $db = Database::getInstance();
            return ReviewCounts::store(ReviewCounts::fromLists(
                ReviewCounts::needsDeterminationRows($db),
                \SDS\Services\TSCAService::reviewCandidates(BulkPublishController::rawMaterialsOnClearedSheets($db)),
                \SDS\Services\StotTargetOrgans::adminRows($db)
            ));
        } catch (\Throwable $e) {
            error_log('Dashboard review counts failed: ' . $e->getMessage());
            return ['unavailable' => true];
        }
    }
}
