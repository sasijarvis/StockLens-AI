<?php
// cron/refresh-watchlist.php
// Schedule: */30 * * * * php /path/to/cron/refresh-watchlist.php >> /path/to/logs/cron.log 2>&1

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/helpers.php';

spl_autoload_register(function (string $class): void {
    $dirs = [
        dirname(__DIR__) . '/src/Api/',
        dirname(__DIR__) . '/src/Data/',
        dirname(__DIR__) . '/src/Ai/',
        dirname(__DIR__) . '/src/Cache/',
        dirname(__DIR__) . '/src/Models/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

$start = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Watchlist refresh starting...\n";

$stocks = Watchlist::getSymbols();

if (empty($stocks)) {
    echo "No stocks in watchlist. Exiting.\n";
    exit(0);
}

echo "Found " . count($stocks) . " stocks to refresh.\n";

$aggregator = new DataAggregator();
$success    = 0;
$failed     = 0;

foreach ($stocks as $stock) {
    try {
        $aggregator->fetch($stock['nse_symbol'], (int)$stock['screener_id'], forceRefresh: true);
        echo "  ✓ {$stock['nse_symbol']}\n";
        $success++;
        sleep(1); // be polite to NSE / Screener
    } catch (Throwable $e) {
        echo "  ✗ {$stock['nse_symbol']}: {$e->getMessage()}\n";
        logError('cron/refresh-watchlist:' . $stock['nse_symbol'], $e);
        $failed++;
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo "[" . date('Y-m-d H:i:s') . "] Done. Success: $success | Failed: $failed | Time: {$elapsed}s\n";
