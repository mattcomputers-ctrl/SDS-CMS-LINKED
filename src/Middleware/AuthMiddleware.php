<?php

namespace SDS\Middleware;

/**
 * AuthMiddleware — Redirects unauthenticated users to /login.
 *
 * Public paths (login page, logout, static assets) are excluded from
 * the check so they remain accessible without a session.
 */
class AuthMiddleware
{
    /**
     * URI prefixes that do NOT require authentication.
     *
     * @var list<string>
     */
    private const PUBLIC_PATHS = [
        '/login',
        '/logout',
        '/assets',
        '/css',
        '/js',
        '/images',
        '/fonts',
        '/favicon.ico',
    ];

    /**
     * Run the authentication guard.
     *
     * @param callable $next  The next handler/middleware in the pipeline
     */
    public function handle(callable $next): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Allow public paths through without authentication
        if ($this->isPublicPath($uri)) {
            $next();
            return;
        }

        // Check session for authenticated user
        if (!isset($_SESSION['_user'])) {
            // Store intended URL so we can redirect after login
            $_SESSION['_flash']['intended_url'] = $uri;

            redirect('/login');
        }

        // User is authenticated — proceed
        $next();
    }

    /**
     * Determine whether the given URI is in the public (no-auth) list.
     *
     * @param string $uri
     * @return bool
     */
    private function isPublicPath(string $uri): bool
    {
        foreach (self::PUBLIC_PATHS as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/') || str_starts_with($uri, $prefix . '?')) {
                return true;
            }
        }

        return false;
    }
}
