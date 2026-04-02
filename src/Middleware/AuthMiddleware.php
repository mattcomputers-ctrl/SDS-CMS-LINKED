<?php

namespace SDS\Middleware;

use SDS\Services\PermissionService;

/**
 * AuthMiddleware — Redirects unauthenticated users to /login.
 *
 * Public paths (login page, logout, static assets) are excluded from
 * the check so they remain accessible without a session.
 *
 * Users whose permission group only grants SDS Book access are
 * restricted to the SDS Book and their own logout route.
 *
 * Group-based permissions are enforced for authenticated users:
 * pages the user has 'none' access to will return 403.
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
     * Map URI prefixes to page keys for permission checks.
     * Order matters — more specific prefixes should come first.
     *
     * @var array<string, string>
     */
    private const URI_TO_PAGE_KEY = [
        '/formulas/mass-replace' => 'rm_mass_replace',
        '/determinations'        => 'cas_determinations',
        '/exempt-vocs'           => 'exempt_vocs',
        '/bulk-publish'          => 'bulk_publish',
        '/bulk-export'           => 'bulk_export',
        '/cms-import'            => 'finished_goods',
        '/raw-materials'         => 'raw_materials',
        '/finished-goods'        => 'finished_goods',
        '/lookup'                => 'fg_sds_lookup',
        '/sds-book'              => 'rm_sds_book',
        '/reports'               => 'reports',
        '/formulas'              => 'formulas',
        '/sds'                   => 'sds',
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

        // Admin routes — require admin permission group
        if (str_starts_with($uri, '/admin')) {
            if (!can_manage_users()) {
                $this->sendForbidden();
                return;
            }
        }

        // Group-based permission check for mapped pages
        $pageKey = $this->resolvePageKey($uri);
        if ($pageKey !== null) {
            $userId = current_user_id();
            if (!PermissionService::canRead($userId, $pageKey)) {
                $this->sendForbidden();
                return;
            }
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

    /**
     * Resolve a URI to a page key for permission checking.
     */
    private function resolvePageKey(string $uri): ?string
    {
        foreach (self::URI_TO_PAGE_KEY as $prefix => $pageKey) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/') || str_starts_with($uri, $prefix . '?')) {
                return $pageKey;
            }
        }

        // Dashboard is the root
        if ($uri === '/') {
            return 'dashboard';
        }

        return null;
    }

    /**
     * Render a 403 Forbidden page and halt.
     */
    private function sendForbidden(): void
    {
        http_response_code(403);

        $viewFile = dirname(__DIR__) . '/Views/errors/403.php';
        if (file_exists($viewFile)) {
            include $viewFile;
        } else {
            echo '<h1>403 — Forbidden</h1>';
            echo '<p>You do not have permission to access this resource.</p>';
        }

        exit;
    }
}
