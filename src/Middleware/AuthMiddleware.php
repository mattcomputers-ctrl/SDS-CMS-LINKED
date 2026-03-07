<?php

namespace SDS\Middleware;

/**
 * AuthMiddleware — Redirects unauthenticated users to /login.
 *
 * Public paths (login page, logout, static assets) are excluded from
 * the check so they remain accessible without a session.
 *
 * Users with the 'sds_book_only' role are restricted to the SDS Book
 * and their own logout route.
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
        '/rm-sds-book',
        '/raw-materials',  // needed for /raw-materials/{id}/sds (supplier SDS view)
    ];

    /**
     * URI prefixes accessible to sds_book_only users.
     *
     * @var list<string>
     */
    private const SDS_BOOK_PATHS = [
        '/sds-book',
        '/raw-materials', // needed for /raw-materials/{id}/sds (supplier SDS view)
        '/sds/version',   // needed for finished good SDS download
        '/logout',
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

        // SDS Book Only restriction
        if (is_sds_book_only() && !$this->isSdsBookPath($uri)) {
            redirect('/sds-book');
        }

        // User is authenticated — proceed
        $next();
    }

    /**
     * Determine whether the given URI is in the public (no-auth) list.
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

    /**
     * Determine whether the given URI is accessible to sds_book_only users.
     */
    private function isSdsBookPath(string $uri): bool
    {
        foreach (self::SDS_BOOK_PATHS as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/') || str_starts_with($uri, $prefix . '?')) {
                return true;
            }
        }

        return false;
    }
}
