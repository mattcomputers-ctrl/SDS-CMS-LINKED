<?php

namespace SDS\Core;

/**
 * CSRF — Cross-Site Request Forgery protection.
 *
 * Generates a per-session token and validates it on POST requests.
 */
class CSRF
{
    /** Session key used to store the token */
    private const SESSION_KEY = '_csrf_token';

    /** POST field name expected in forms */
    private const FIELD_NAME = '_csrf_token';

    /**
     * Generate (or retrieve the existing) CSRF token and store it in
     * the session.  A new token is created only if one does not already
     * exist, so a single token lives for the entire session.
     *
     * @return string  The hex-encoded token
     */
    public static function generate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Session must be active before generating a CSRF token.');
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Return an HTML hidden input element containing the current token.
     *
     * Usage in views:  <?= \SDS\Core\CSRF::field() ?>
     *
     * @return string  HTML <input> tag
     */
    public static function field(): string
    {
        $token = self::generate();
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::FIELD_NAME, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Return the current token value (for AJAX headers, etc.).
     *
     * @return string
     */
    public static function token(): string
    {
        return self::generate();
    }

    /**
     * Validate a given token against the one stored in the session.
     *
     * Uses hash_equals for timing-safe comparison.
     *
     * @param string $token  The token to validate
     * @return bool
     */
    public static function validate(string $token): bool
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * Validate the token submitted in $_POST['_csrf_token'].
     *
     * Typically called at the start of every POST-handling route.
     *
     * @return bool  true if valid
     * @throws \RuntimeException  if token is missing or invalid
     */
    public static function validateRequest(): bool
    {
        $token = $_POST[self::FIELD_NAME] ?? '';

        if ($token === '' || !self::validate($token)) {
            throw new \RuntimeException('CSRF token validation failed.');
        }

        return true;
    }

    /**
     * Regenerate the CSRF token.  Useful after a successful form
     * submission to prevent replay.
     *
     * @return string  The new token
     */
    public static function regenerate(): string
    {
        unset($_SESSION[self::SESSION_KEY]);
        return self::generate();
    }
}
