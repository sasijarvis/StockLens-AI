<?php

/**
 * Session-based authentication helper.
 * Requires session_start() to be called before use (done in index.php).
 */
class Auth {

    public static function check(): bool {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }

    public static function id(): ?int {
        return self::check() ? (int)$_SESSION['user_id'] : null;
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        // Cache user in session to avoid repeated DB lookups
        if (!isset($_SESSION['user_data'])) {
            $_SESSION['user_data'] = User::findById((int)$_SESSION['user_id']);
        }
        return $_SESSION['user_data'] ?: null;
    }

    public static function login(array $user): void {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id']   = (int)$user['id'];
        $_SESSION['user_data'] = $user;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Redirect to login page if not authenticated.
     * Call this at the top of protected pages.
     */
    public static function require(): void {
        if (!self::check()) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . APP_BASE . '/login?redirect=' . $redirect);
            exit;
        }
    }
}
