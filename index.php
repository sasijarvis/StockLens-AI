<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';

// Set execution limits via PHP (more reliable than .htaccess php_value)
@ini_set('max_execution_time', '300');
@ini_set('default_socket_timeout', '120');
@set_time_limit(300);

// Suppress PHP error display in production — errors go to logs, not browser output
if (env('APP_DEBUG') !== 'true') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/php_errors.log');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/php_errors.log');
}

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/helpers.php';

// Start session for auth (only for non-analyse_start routes to avoid buffering issues)
$currentParam = explode('/', trim($_GET['route'] ?? '', '/'))[1] ?? '';
if ($currentParam !== 'analyse_start') {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
} else {
    // analyse_start needs clean output — no session buffering
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
        session_write_close(); // release session lock immediately
    }
}

// Autoload all classes from src/
spl_autoload_register(function (string $class): void {
    $dirs = [
        __DIR__ . '/src/Api/',
        __DIR__ . '/src/Data/',
        __DIR__ . '/src/Ai/',
        __DIR__ . '/src/Cache/',
        __DIR__ . '/src/Models/',
        __DIR__ . '/src/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

$route  = trim($_GET['route'] ?? '', '/');
$parts  = explode('/', $route);
$page   = $parts[0] ?: 'home';
$param  = $parts[1] ?? '';

// API endpoints must never display errors (corrupts JSON) — override debug mode
if ($page === 'api') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    // analyse_start sets its own Content-Type header
    if ($param !== 'analyse_start') {
        header('Content-Type: application/json');
    }
    try {
        match ($param) {
            'search'         => require __DIR__ . '/api/search.php',
            'analyse'        => require __DIR__ . '/api/analyse.php',
            'analyse_start'  => require __DIR__ . '/api/analyse_start.php',
            'analyse_steps'  => require __DIR__ . '/api/analyse_steps.php',
            'analyse_status' => require __DIR__ . '/api/analyse_status.php',
            'prices'         => require __DIR__ . '/api/prices.php',
            'watchlist'      => require __DIR__ . '/api/watchlist.php',
            'auth'           => require __DIR__ . '/api/auth.php',
            'health'         => require __DIR__ . '/api/health.php',
            default          => jsonResponse(['error' => 'Not found'], 404),
        };
    } catch (Throwable $e) {
        logError('api/' . $param, $e);
        jsonResponse(['error' => 'Internal server error', 'message' => env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Something went wrong'], 500);
    }
    exit;
}

// --- Page routes → HTML ---
$allowed = ['home', 'analysis', 'watchlist', 'history', 'compare', 'login', 'register', 'heatmap'];
if (!in_array($page, $allowed)) {
    http_response_code(404);
    $page = '404';
}

// Never allow browsers or CDNs to cache HTML pages — JS/CSS/images have their own cache headers
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Pass $param to pages that need it (e.g., analysis/{SYMBOL})
$GLOBALS['route_param'] = strtoupper($param);

ob_start();
$pagefile = __DIR__ . "/pages/{$page}.php";
if (file_exists($pagefile)) {
    require $pagefile;
} else {
    echo '<p>Page not found.</p>';
}
$content = ob_get_clean();

require __DIR__ . '/templates/layout.php';
