-- ============================================================
-- Migration 044: Bulk publish job queue
--
-- Serializes bulk SDS publish runs so two can never be active
-- at the same time. A new row is INSERTed at every bulk-publish
-- trigger (cron, manual, etc.); whichever PHP process acquires
-- the MySQL named lock `bulk_publish_runner` drains pending jobs
-- one at a time. Other triggers that find the lock already held
-- simply leave their pending row in the queue for the active
-- runner to pick up.
--
-- Coalescing rule (applied in cron/bulk-publish.php): if a
-- `pending` row already exists, don't insert another — bulk
-- publish recomputes eligibility at run time so two pending
-- rows would do identical work.
--
-- Stats columns let an admin view show what each run did.
-- ============================================================

CREATE TABLE IF NOT EXISTS `bulk_publish_jobs` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `status`                ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
    `triggered_by`          VARCHAR(50)  NOT NULL DEFAULT 'cron'
                            COMMENT 'cron, manual, cli, api',
    `triggered_by_user_id`  INT UNSIGNED NULL,
    `queued_at`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at`            DATETIME     NULL,
    `completed_at`          DATETIME     NULL,
    `eligible_fg_count`     INT UNSIGNED NULL,
    `eligible_resale_count` INT UNSIGNED NULL,
    `work_items_count`      INT UNSIGNED NULL,
    `published_count`       INT UNSIGNED NULL,
    `failed_count`          INT UNSIGNED NULL,
    `error_message`         TEXT         NULL,
    INDEX `idx_bpj_status_queued` (`status`, `queued_at`),
    INDEX `idx_bpj_triggered_by_user` (`triggered_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('044_add_bulk_publish_jobs');
