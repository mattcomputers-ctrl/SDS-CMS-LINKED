<?php

namespace SDS\Middleware;

/**
 * RoleMiddleware — Restricts access to specific user roles.
 *
 * Usage (inside App::run):
 *   $adminGuard = new RoleMiddleware(['admin']);
 *   $router->addMiddleware([$adminGuard, 'handle']);
 *
 * When the current user's role is not in the allowed list the
 * middleware sends a 403 Forbidden response and halts execution.
 */
class RoleMiddleware
{
    /**
     * Roles that are permitted to continue.
     *
     * @var list<string>
     */
    private array $allowedRoles;

    /**
     * @param list<string> $allowedRoles  e.g. ['admin'], ['admin','editor']
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    /**
     * Run the role check.
     *
     * @param callable $next  The next handler/middleware in the pipeline
     */
    public function handle(callable $next): void
    {
        $user = $_SESSION['_user'] ?? null;

        // If the user is not logged in at all, delegate to AuthMiddleware
        // (this middleware should be stacked after AuthMiddleware).
        if ($user === null) {
            $this->sendForbidden();
            return;
        }

        $role = $user['role'] ?? '';

        if (!in_array($role, $this->allowedRoles, true)) {
            $this->sendForbidden();
            return;
        }

        // Role is allowed — continue
        $next();
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
