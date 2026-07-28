<?php

declare(strict_types=1);

namespace SDS\Services;

use SDS\Core\Database;

/**
 * BulkPublishQueue — serialization layer for bulk SDS publish runs.
 *
 * Backed by the `bulk_publish_jobs` table (migration 044) and a
 * MySQL named lock `bulk_publish_runner`. Every trigger (cron, admin
 * click, CLI) enqueues a pending row here; whichever process holds
 * the runner lock drains pending jobs one at a time. Concurrent
 * triggers that find the lock held simply leave their pending row
 * for the active runner to pick up.
 *
 * Shared by cron/bulk-publish.php and BulkPublishController::start so
 * cron vs manual triggers can't race.
 */
class BulkPublishQueue
{
    public const LOCK_NAME = 'bulk_publish_runner';

    /**
     * Coalescing enqueue: insert a new pending job only if no pending
     * row already exists. Returns the job id (either the new one or
     * the existing pending one we deferred to). Two pending rows would
     * do identical work because bulk publish recomputes eligibility
     * at run time — so we keep at most one queued-up.
     *
     * @param string   $triggeredBy   'cron' | 'manual' | 'cli' | 'api'
     * @param int|null $userId        Auth user id, if known
     */
    public static function enqueue(string $triggeredBy, ?int $userId = null): int
    {
        $db = Database::getInstance();
        $existing = $db->fetch(
            "SELECT id FROM bulk_publish_jobs WHERE status = 'pending'
             ORDER BY queued_at ASC LIMIT 1"
        );
        if ($existing) {
            return (int) $existing['id'];
        }
        return (int) $db->insert('bulk_publish_jobs', [
            'status'               => 'pending',
            'triggered_by'         => $triggeredBy,
            'triggered_by_user_id' => $userId,
        ]);
    }

    /**
     * Try to acquire the runner lock non-blocking. Returns true if we
     * got it (caller should drain and then releaseLock), false if
     * another runner already holds it (caller should exit cleanly —
     * its pending row will be picked up by the active runner).
     *
     * The lock is per-connection and auto-released on disconnect, so
     * a crashed runner can't wedge the queue.
     */
    public static function acquireLock(): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT GET_LOCK(?, 0) AS g",
            [self::LOCK_NAME]
        );
        return $row !== null && (int) ($row['g'] ?? 0) === 1;
    }

    public static function releaseLock(): void
    {
        Database::getInstance()->query(
            "SELECT RELEASE_LOCK(?)",
            [self::LOCK_NAME]
        );
    }

    /**
     * Any rows still in `running` at drain start must be orphaned —
     * we just acquired the exclusive runner lock, which means any
     * prior runner died without marking its in-flight job done.
     * Mark those failed with a descriptive error so they're visible
     * in the job history. Fresh pending rows are untouched.
     */
    public static function reapOrphanedRunning(): void
    {
        Database::getInstance()->query(
            "UPDATE bulk_publish_jobs
                SET status = 'failed',
                    completed_at = NOW(),
                    error_message = CONCAT(
                        'Orphaned: previous runner crashed (auto-failed at ',
                        NOW(), ')'
                    )
              WHERE status = 'running'"
        );
    }

    /**
     * Claim the oldest pending job: mark running + stamp started_at.
     * Returns the job id, or null if no pending jobs remain.
     */
    public static function claimNextPending(): ?int
    {
        $db  = Database::getInstance();
        $row = $db->fetch(
            "SELECT id FROM bulk_publish_jobs WHERE status = 'pending'
             ORDER BY queued_at ASC LIMIT 1"
        );
        if (!$row) {
            return null;
        }
        $jobId = (int) $row['id'];
        $db->update('bulk_publish_jobs', [
            'status'     => 'running',
            'started_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$jobId]);
        return $jobId;
    }

    /**
     * Live progress update while a job is running — called from the
     * worker-polling loop every ~2s so the admin queue view can show
     * real-time counts without needing progress files.
     *
     * @param array $stats  subset of: eligible_fg_count, eligible_resale_count,
     *                      work_items_count, published_count, failed_count
     */
    public static function updateProgress(int $jobId, array $stats): void
    {
        if (empty($stats)) {
            return;
        }
        $allowed = [
            'eligible_fg_count', 'eligible_resale_count',
            'work_items_count', 'published_count', 'failed_count',
        ];
        $payload = array_intersect_key($stats, array_flip($allowed));
        if ($payload !== []) {
            Database::getInstance()->update(
                'bulk_publish_jobs',
                $payload,
                'id = ?',
                [$jobId]
            );
        }
    }

    public static function markCompleted(int $jobId, array $stats): void
    {
        Database::getInstance()->update('bulk_publish_jobs', array_merge($stats, [
            'status'       => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]), 'id = ?', [$jobId]);
    }

    public static function markFailed(int $jobId, string $error): void
    {
        Database::getInstance()->update('bulk_publish_jobs', [
            'status'        => 'failed',
            'completed_at'  => date('Y-m-d H:i:s'),
            'error_message' => mb_strimwidth($error, 0, 4000, '...'),
        ], 'id = ?', [$jobId]);
    }
}
