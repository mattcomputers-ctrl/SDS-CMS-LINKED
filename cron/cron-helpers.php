<?php
/**
 * Shared helpers for cron scripts.
 */

/**
 * Check if the current time falls within any configured blackout window.
 * Returns true if currently in a blackout period (cron should exit).
 */
function cron_in_blackout(\SDS\Core\Database $db): bool
{
    $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'cron.blackout_windows'");
    if (!$row || empty($row['value'])) {
        return false;
    }

    $windows = json_decode($row['value'], true);
    if (!is_array($windows) || empty($windows)) {
        return false;
    }

    $now = (int) date('H') * 60 + (int) date('i');

    foreach ($windows as $win) {
        $start = cron_parse_time($win['start'] ?? '');
        $end   = cron_parse_time($win['end'] ?? '');
        if ($start === null || $end === null) {
            continue;
        }

        if ($start <= $end) {
            if ($now >= $start && $now < $end) {
                return true;
            }
        } else {
            // Wraps midnight: e.g. 23:00–01:00
            if ($now >= $start || $now < $end) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Parse "HH:MM" to minutes since midnight, or null on failure.
 */
function cron_parse_time(string $t): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
        return null;
    }
    return ((int) $m[1]) * 60 + (int) $m[2];
}
