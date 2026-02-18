<?php

declare(strict_types=1);

namespace SDS\Controllers;

use SDS\Core\CSRF;
use SDS\Models\User;
use SDS\Models\AuditLog;

/**
 * AuthController -- Login / Logout flows.
 */
class AuthController
{
    /**
     * GET /login -- Show the login form.
     */
    public function loginForm(): void
    {
        // Already logged in? Send to dashboard.
        if (isset($_SESSION['_user'])) {
            redirect('/');
            return; // unreachable, but explicit
        }

        view('auth/login', [
            'pageTitle' => 'Sign In',
        ]);
    }

    /**
     * POST /login -- Authenticate and establish session.
     */
    public function login(): void
    {
        try {
            CSRF::validateRequest();
        } catch (\RuntimeException $e) {
            $_SESSION['_flash']['error'] = 'Invalid or expired form token. Please try again.';
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // ---- basic input checks ----
        $errors = [];
        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if (!empty($errors)) {
            $_SESSION['_flash']['error'] = implode(' ', $errors);
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        // ---- authenticate ----
        $user = User::authenticate($username, $password);

        if ($user === false) {
            // Log failed attempt
            $db = \SDS\Core\Database::getInstance();
            $db->insert('audit_log', [
                'user_id'     => null,
                'entity_type' => 'auth',
                'entity_id'   => $username,
                'action'      => 'login_failed',
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $_SESSION['_flash']['error'] = 'Invalid username or password.';
            $_SESSION['_flash']['_old_input'] = ['username' => $username];
            redirect('/login');
        }

        // ---- success: set session ----
        User::updateLastLogin((int) $user['id']);

        // Store user in session (regenerates session ID internally)
        $_SESSION['_user'] = $user;
        session_regenerate_id(true);

        // Audit log -- successful login
        $db = \SDS\Core\Database::getInstance();
        $db->insert('audit_log', [
            'user_id'     => $user['id'],
            'entity_type' => 'auth',
            'entity_id'   => (string) $user['id'],
            'action'      => 'login',
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Redirect to intended URL or dashboard
        $intended = $_SESSION['_flash']['intended_url'] ?? '/';
        unset($_SESSION['_flash']['intended_url']);

        $_SESSION['_flash']['success'] = 'Welcome back, ' . ($user['display_name'] ?: $user['username']) . '.';
        redirect($intended);
    }

    /**
     * GET|POST /logout -- Destroy session and redirect.
     */
    public function logout(): void
    {
        $userId = current_user_id();

        if ($userId) {
            $db = \SDS\Core\Database::getInstance();
            $db->insert('audit_log', [
                'user_id'     => $userId,
                'entity_type' => 'auth',
                'entity_id'   => (string) $userId,
                'action'      => 'logout',
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        }

        // Destroy session completely
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

        redirect('/login');
    }
}
