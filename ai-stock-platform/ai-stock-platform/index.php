<?php
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/helpers.php';

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

// --- API endpoints → JSON ---
if ($page === 'api') {
    header('Content-Type: application/json');
    try {
        match ($param) {
            'search'   => require __DIR__ . '/api/search.php',
            'analyse'  => require __DIR__ . '/api/analyse.php',
            'prices'   => require __DIR__ . '/api/prices.php',
            'watchlist'=> require __DIR__ . '/api/watchlist.php',
            default    => jsonResponse(['error' => 'Not found'], 404),
        };
    } catch (Throwable $e) {
        logError('api/' . $param, $e);
        jsonResponse(['error' => 'Internal server error', 'message' => env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Something went wrong'], 500);
    }
    exit;
}

// --- Page routes → HTML ---
$allowed = ['home', 'analysis', 'watchlist', 'history', 'compare'];
if (!in_array($page, $allowed)) {
    http_response_code(404);
    $page = '404';
}

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
