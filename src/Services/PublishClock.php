<?php

declare(strict_types=1);

namespace SDS\Services;

/**
 * PublishClock — the two clocks every publisher uses (audit #59). DB-free.
 *
 *  - Stored timestamps (sds_versions.published_at, private_label_sds.published_at,
 *    every updated_at / created_at the app or MySQL writes): UTC. App sets the
 *    MySQL session time_zone to DB_TIME_ZONE, so CURRENT_TIMESTAMP, ON UPDATE
 *    CURRENT_TIMESTAMP, NOW(), UTC_TIMESTAMP() and nowUtc() are one clock and the
 *    staleness comparison (published_at vs upstream updated_at) is exact.
 *  - The printed effective date (Section 16 + footer): today in the admin time
 *    zone (app.timezone), taken per item when that item is published.
 */
final class PublishClock
{
    /** MySQL session time zone set by App (one clock = UTC). */
    public const DB_TIME_ZONE = '+00:00';

    /** UTC 'Y-m-d H:i:s' for published_at / updated_at / created_at. */
    public static function nowUtc(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? time());
    }

    /** Printed effective date: today in the admin time zone, Y-m-d. */
    public static function todayLocal(?int $ts = null): string
    {
        return date('Y-m-d', $ts ?? time());
    }

    /** Admin-time-zone calendar date (Y-m-d) of a stored UTC 'Y-m-d H:i:s'. */
    public static function localDateOfUtc(string $utc): string
    {
        $ts = self::parseUtc($utc);
        return $ts === null ? self::todayLocal() : date('Y-m-d', $ts);
    }

    /** Show a stored UTC datetime in the admin time zone; '' when empty, zero or unparseable. */
    public static function display(?string $utc, string $format = 'm/d/Y H:i'): string
    {
        $ts = self::parseUtc((string) $utc);
        return $ts === null ? '' : date($format, $ts);
    }

    /** Unix timestamp of a stored UTC datetime, or null. */
    public static function parseUtc(string $utc): ?int
    {
        $utc = trim($utc);
        if ($utc === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? null : $ts;
    }
}
