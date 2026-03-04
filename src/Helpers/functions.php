<?php

/**
 * Global helper functions for the SDS System.
 *
 * These are intentionally NOT namespaced so they can be called
 * conveniently from controllers and views without a use statement.
 */

// Guard against double-include
if (defined('SDS_HELPERS_LOADED')) {
    return;
}
define('SDS_HELPERS_LOADED', true);

/* ------------------------------------------------------------------
 *  HTTP helpers
 * ----------------------------------------------------------------*/

/**
 * Send a Location redirect and terminate execution.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect back to the referring page (falls back to /).
 */
function back(): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '/';
    redirect($referer);
}

/* ------------------------------------------------------------------
 *  Output / escaping
 * ----------------------------------------------------------------*/

/**
 * HTML-escape a string for safe output.
 */
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------
 *  View rendering
 * ----------------------------------------------------------------*/

/**
 * Render a view template.
 *
 * @param string $template  Dot or slash-separated path relative to src/Views/
 *                          e.g. 'raw-materials/index' or 'layouts/main'
 * @param array  $data      Variables to extract into the view scope
 */
function view(string $template, array $data = []): void
{
    // Convert dot notation to directory separators
    $template = str_replace('.', '/', $template);

    $file = dirname(__DIR__) . '/Views/' . $template . '.php';

    if (!file_exists($file)) {
        throw new \RuntimeException("View [{$template}] not found at [{$file}].");
    }

    // Extract variables into the view scope
    extract($data, EXTR_SKIP);

    include $file;
}

/* ------------------------------------------------------------------
 *  Form helpers
 * ----------------------------------------------------------------*/

/**
 * Return the old POST value (useful for re-populating forms after
 * validation failure).  Falls back to a flash'd "old" array, then to
 * the $default.
 */
function old(string $key, string $default = ''): string
{
    // First: check current POST (same-request re-render)
    if (isset($_POST[$key])) {
        return (string) $_POST[$key];
    }

    // Second: check flash'd old input (redirect-back pattern)
    if (isset($_SESSION['_flash']['_old_input'][$key])) {
        return (string) $_SESSION['_flash']['_old_input'][$key];
    }

    return $default;
}

/**
 * Build a full URL from an application path.
 *
 * @param string $path  e.g. '/raw-materials' or ''
 * @return string       e.g. 'http://localhost/raw-materials'
 */
function url(string $path = ''): string
{
    // Check for admin-configured server URL override, with one-time cache
    static $cachedBase = null;
    if ($cachedBase === null) {
        try {
            $db = \SDS\Core\Database::getInstance();
            $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'app.server_url'");
            if ($row && !empty(trim($row['value']))) {
                $cachedBase = rtrim(trim($row['value']), '/');
            }
        } catch (\Throwable $e) {
            // DB not available yet (e.g. during install), fall through
        }
        if ($cachedBase === null) {
            $cachedBase = rtrim(\SDS\Core\App::config('app.url', ''), '/');
        }
    }

    if ($path === '' || $path === '/') {
        return $cachedBase . '/';
    }
    return $cachedBase . '/' . ltrim($path, '/');
}

/**
 * Output the CSRF hidden input field.
 */
function csrf_field(): string
{
    return \SDS\Core\CSRF::field();
}

/* ------------------------------------------------------------------
 *  Flash messages
 * ----------------------------------------------------------------*/

/**
 * Render flash messages as Bootstrap-compatible alert HTML.
 *
 * Supported keys in _flash: success, error, warning, info
 *
 * @return string  HTML
 */
function flash_messages(): string
{
    $types = ['success', 'error', 'warning', 'info'];
    $html  = '';

    foreach ($types as $type) {
        if (isset($_SESSION['_flash'][$type])) {
            $message  = $_SESSION['_flash'][$type];
            $bsClass  = ($type === 'error') ? 'danger' : $type;
            $escaped  = e($message);
            $html    .= '<div class="alert alert-' . $bsClass . ' alert-dismissible fade show" role="alert">';
            $html    .= $escaped;
            $html    .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            $html    .= '</div>';
            unset($_SESSION['_flash'][$type]);
        }
    }

    // Clear old form input so it doesn't persist to subsequent pages
    unset($_SESSION['_flash']['_old_input']);

    return $html;
}

/* ------------------------------------------------------------------
 *  Formatting
 * ----------------------------------------------------------------*/

/**
 * Format a date/datetime string for display.
 *
 * @param string|null $date  Any strtotime-compatible value
 * @param string      $format  PHP date format
 * @return string
 */
function format_date(?string $date, string $format = 'm/d/Y'): string
{
    if ($date === null || $date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime($date);
    return $ts !== false ? date($format, $ts) : '';
}

/* ------------------------------------------------------------------
 *  Validation helpers
 * ----------------------------------------------------------------*/

/**
 * Validate a CAS Registry Number (format and checksum).
 *
 * CAS format: XXXXXXX-YY-Z  where the last digit Z is a check digit.
 * The check digit is the weighted sum of all other digits mod 10.
 *
 * @param string $cas  e.g. "7732-18-5"
 * @return bool
 */
function validate_cas(string $cas): bool
{
    $cas = trim($cas);

    // Must match the pattern: 2-7 digits, dash, 2 digits, dash, 1 digit
    if (!preg_match('/^(\d{2,7})-(\d{2})-(\d)$/', $cas, $m)) {
        return false;
    }

    // Extract the digits (excluding the check digit)
    $digits    = $m[1] . $m[2];       // All digits before the check digit
    $checkDigit = (int) $m[3];

    // Compute check digit: rightmost digit weight 1, next 2, etc.
    $sum    = 0;
    $weight = 1;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $sum += (int) $digits[$i] * $weight;
        $weight++;
    }

    return ($sum % 10) === $checkDigit;
}

/**
 * Sanitise a filename for safe storage on the filesystem.
 */
function sanitize_filename(string $name): string
{
    // Remove path components
    $name = basename($name);

    // Replace non-alphanumeric characters (except dot, dash, underscore) with underscores
    $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);

    // Collapse multiple underscores
    $name = preg_replace('/_+/', '_', $name);

    // Trim leading/trailing underscores/dots (prevent hidden files)
    $name = trim($name, '_.');

    // Fallback for empty result
    if ($name === '') {
        $name = 'unnamed_file';
    }

    return $name;
}

/* ------------------------------------------------------------------
 *  Role / auth convenience functions
 * ----------------------------------------------------------------*/

/**
 * Is the current session user an admin?
 */
function is_admin(): bool
{
    $user = $_SESSION['_user'] ?? null;
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

/**
 * Is the current session user an editor (or higher)?
 */
function is_editor(): bool
{
    $user = $_SESSION['_user'] ?? null;
    if ($user === null) {
        return false;
    }
    return in_array($user['role'] ?? '', ['admin', 'editor'], true);
}

/**
 * Is the current session user read-only?
 */
function is_readonly(): bool
{
    $user = $_SESSION['_user'] ?? null;
    return $user !== null && ($user['role'] ?? '') === 'readonly';
}

/**
 * Is the current session user restricted to SDS Book only?
 */
function is_sds_book_only(): bool
{
    $user = $_SESSION['_user'] ?? null;
    return $user !== null && ($user['role'] ?? '') === 'sds_book_only';
}

/**
 * Return the current user's ID, or null if not logged in.
 */
function current_user_id(): ?int
{
    $user = $_SESSION['_user'] ?? null;
    return $user !== null ? (int) ($user['id'] ?? 0) ?: null : null;
}

/**
 * Resolve the PHP CLI binary path.
 *
 * PHP_BINARY points to php-fpm (or may be empty) when running under
 * FPM/CGI, so we probe common CLI paths and fall back to `which php`.
 */
function php_cli_binary(): string
{
    // If already running under CLI, PHP_BINARY is correct
    if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }

    // Common CLI binary locations
    $candidates = [
        '/usr/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        '/usr/local/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    // Last resort: ask the shell
    $which = trim((string) @shell_exec('which php 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }

    // Absolute fallback
    return '/usr/bin/php';
}
