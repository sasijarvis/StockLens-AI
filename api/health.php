<?php
// api/health.php — system health check endpoint
// Access: GET /api/health
// Optionally protect with: ?secret=HEALTH_SECRET (set in .env)

$secret = env('HEALTH_SECRET', '');
if ($secret && ($_GET['secret'] ?? '') !== $secret) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$start = microtime(true);
$health = [
    'status'    => 'ok',
    'version'   => APP_VERSION,
    'timestamp' => date('c'),
    'checks'    => [],
];

// ── DB connectivity ───────────────────────────────────────────────────────────
try {
    $db = Database::get();
    $db->query('SELECT 1');
    $health['checks']['database'] = ['status' => 'ok'];
} catch (Throwable $e) {
    $health['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    $health['status'] = 'degraded';
}

// ── Cache stats ───────────────────────────────────────────────────────────────
try {
    $db = Database::get();

    $priceCount = (int)$db->query("SELECT COUNT(*) FROM price_cache WHERE fetched_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
    $chartCount = (int)$db->query("SELECT COUNT(*) FROM chart_cache WHERE fetched_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)")->fetchColumn();
    $newsCount  = (int)$db->query("SELECT COUNT(*) FROM news_cache WHERE fetched_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
    $totalCompanies = (int)$db->query("SELECT COUNT(*) FROM companies")->fetchColumn();
    $totalAnalyses  = (int)$db->query("SELECT COUNT(*) FROM analysis_history")->fetchColumn();
    $watchlistCount = (int)$db->query("SELECT COUNT(*) FROM watchlist")->fetchColumn();

    $health['checks']['cache'] = [
        'status'            => 'ok',
        'fresh_prices'      => $priceCount,
        'fresh_charts'      => $chartCount,
        'fresh_news'        => $newsCount,
        'total_companies'   => $totalCompanies,
        'total_analyses'    => $totalAnalyses,
        'watchlist_entries' => $watchlistCount,
    ];
} catch (Throwable $e) {
    $health['checks']['cache'] = ['status' => 'error', 'message' => $e->getMessage()];
}

// ── Recent errors ─────────────────────────────────────────────────────────────
$logFile = dirname(__DIR__) . '/logs/app.log';
if (file_exists($logFile)) {
    $logSize = filesize($logFile);
    // Count lines in last 100 KB
    $tail = '';
    $fp   = fopen($logFile, 'r');
    if ($fp) {
        fseek($fp, max(0, $logSize - 102400));
        $tail = fread($fp, 102400);
        fclose($fp);
    }
    $recentErrors = substr_count($tail, '[ERROR]') + substr_count($tail, '] ERROR');
    $health['checks']['logs'] = [
        'status'        => 'ok',
        'log_size_kb'   => round($logSize / 1024, 1),
        'recent_errors' => $recentErrors,
    ];
} else {
    $health['checks']['logs'] = ['status' => 'ok', 'log_size_kb' => 0, 'recent_errors' => 0];
}

// ── Rate limit table stats ────────────────────────────────────────────────────
try {
    $rlCount = (int)$db->query("SELECT COUNT(*) FROM rate_limits WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
    $health['checks']['rate_limits'] = ['requests_last_hour' => $rlCount];
} catch (Throwable) {
    $health['checks']['rate_limits'] = ['status' => 'table_missing'];
}

$health['response_ms'] = round((microtime(true) - $start) * 1000, 1);

$httpCode = $health['status'] === 'ok' ? 200 : 503;
jsonResponse($health, $httpCode);
