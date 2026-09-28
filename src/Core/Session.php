<?php

namespace SDS\Core;

/**
 * Session — Thin wrapper around PHP native sessions.
 *
 * Handles configuration, start/destroy, typed get/set, flash
 * messages, and the authenticated-user lifecycle.
 */
class Session
{
    /** Admin setting key for the idle-logout window. */
    public const IDLE_TIMEOUT_SETTING = 'auth.session_timeout_minutes';

    /** Defaults / clamps for the idle timeout (minutes). Min 2 so the
     *  1-minute client-side warning has room to appear. */
    public const IDLE_TIMEOUT_DEFAULT_MIN = 30;
    public const IDLE_TIMEOUT_MIN_MIN     = 2;
    public const IDLE_TIMEOUT_MAX_MIN     = 720;

    /** @var bool Whether session has been started by this class */
    private bool $started = false;

    /**
     * Idle-logout window in seconds, from the admin setting, clamped.
     * Falls back to the default if the DB isn't reachable (e.g. very
     * early bootstrap failures) so auth never hard-errors on a settings
     * lookup. Memoised per request — it's consulted by App (to size the
     * session cookie), AuthMiddleware (to enforce) and the footer (to
     * configure the client-side timer).
     */
    public static function configuredIdleTimeout(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $minutes = self::IDLE_TIMEOUT_DEFAULT_MIN;
        try {
            $row = Database::getInstance()->fetch(
                "SELECT `value` FROM settings WHERE `key` = ?",
                [self::IDLE_TIMEOUT_SETTING]
            );
            if ($row && $row['value'] !== null && $row['value'] !== '') {
                $minutes = (int) $row['value'];
            }
        } catch (\Throwable $e) {
            // keep default
        }

        $minutes = max(self::IDLE_TIMEOUT_MIN_MIN, min(self::IDLE_TIMEOUT_MAX_MIN, $minutes));
        return $cached = $minutes * 60;
    }

    /**
     * Configure and start a PHP session.
     *
     * @param array $config  Optional keys: lifetime, name
     */
    public function start(array $config = []): void
    {
        // CLI processes (e.g. publish workers) have no cookie mechanism;
        // skip session initialisation entirely to avoid errors.
        if (PHP_SAPI === 'cli') {
            return;
        }

        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $lifetime = $config['lifetime'] ?? 3600;
        $name     = $config['name']     ?? 'SDS_SESSION';

        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        session_name($name);
        session_start();

        $this->started = true;

        // Rotate session ID periodically to mitigate fixation
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    /* ------------------------------------------------------------------
     *  Basic key/value helpers
     * ----------------------------------------------------------------*/

    /**
     * Retrieve a value from the session.
     *
     * @param string $key     Session key
     * @param mixed  $default Fallback value
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Store a value in the session.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Remove a key from the session.
     */
    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Check whether a key exists in the session.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    /* ------------------------------------------------------------------
     *  Flash messages (available for exactly one subsequent request)
     * ----------------------------------------------------------------*/

    /**
     * Get or set a flash message.
     *
     * With one argument: read (and consume) the flash value.
     * With two arguments: write the flash value for the next request.
     *
     * @param string     $key
     * @param mixed|null $value
     * @return mixed
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        // Setter mode
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return $value;
        }

        // Getter mode — read and consume
        if (isset($_SESSION['_flash'][$key])) {
            $val = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $val;
        }

        return null;
    }

    /**
     * Return all flash messages (and clear them).
     *
     * @return array
     */
    public function allFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /* ------------------------------------------------------------------
     *  Authenticated user helpers
     * ----------------------------------------------------------------*/

    /**
     * Return the currently authenticated user array, or null.
     */
    public function user(): ?array
    {
        return $_SESSION['_user'] ?? null;
    }

    /**
     * Store the authenticated user in the session.
     *
     * @param array $user  Should contain at least: id, username
     */
    public function setUser(array $user): void
    {
        $_SESSION['_user'] = $user;
        // Regenerate ID on privilege change
        session_regenerate_id(true);
    }

    /**
     * Whether a user is currently authenticated.
     */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['_user']);
    }

    /* ------------------------------------------------------------------
     *  Lifecycle
     * ----------------------------------------------------------------*/

    /**
     * Destroy the session completely (logout).
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
    }
}
