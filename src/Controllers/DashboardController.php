<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\Database;

class DashboardController
{
    public function index(): void
    {
        $db = Database::getInstance();

        $stats = [
            'raw_materials'  => (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM raw_materials")['cnt'] ?? 0),
            'finished_goods' => (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM finished_goods WHERE is_active = 1")['cnt'] ?? 0),
            'published_sds'  => (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM sds_versions WHERE status = 'published' AND is_deleted = 0")['cnt'] ?? 0),
            'users'          => (int) ($db->fetch("SELECT COUNT(*) AS cnt FROM users WHERE is_active = 1")['cnt'] ?? 0),
        ];

        // Storage space information
        $storagePath = '/';
        $totalBytes = @disk_total_space($storagePath);
        $freeBytes  = @disk_free_space($storagePath);
        $usedBytes  = ($totalBytes !== false && $freeBytes !== false) ? $totalBytes - $freeBytes : false;

        $storage = [
            'total_gb'     => $totalBytes !== false ? round($totalBytes / 1073741824, 2) : 0,
            'used_gb'      => $usedBytes  !== false ? round($usedBytes  / 1073741824, 2) : 0,
            'free_gb'      => $freeBytes  !== false ? round($freeBytes  / 1073741824, 2) : 0,
            'used_percent' => ($totalBytes && $usedBytes !== false) ? round(($usedBytes / $totalBytes) * 100, 1) : 0,
            'free_percent' => ($totalBytes && $freeBytes !== false) ? round(($freeBytes / $totalBytes) * 100, 1) : 0,
        ];

        $recentSDS = $db->fetchAll(
            "SELECT sv.*, fg.product_code, fg.description, u.display_name AS published_by_name
             FROM sds_versions sv
             JOIN finished_goods fg ON fg.id = sv.finished_good_id
             LEFT JOIN users u ON u.id = sv.published_by
             WHERE sv.is_deleted = 0
             ORDER BY sv.created_at DESC
             LIMIT 10"
        );

        $recentAudit = $db->fetchAll(
            "SELECT a.*, u.display_name AS user_display_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.timestamp DESC
             LIMIT 10"
        );

        view('dashboard/index', [
            'pageTitle'    => 'Dashboard',
            'stats'        => $stats,
            'storage'      => $storage,
            'recentSDS'    => $recentSDS,
            'recentAudit'  => $recentAudit,
        ]);
    }
}
