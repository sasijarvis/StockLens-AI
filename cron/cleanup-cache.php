<?php
// cron/cleanup-cache.php
// Schedule: 0 0 * * * php /path/to/cron/cleanup-cache.php >> /path/to/logs/cron.log 2>&1

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/Cache/MySqlCache.php';

echo "[" . date('Y-m-d H:i:s') . "] Cache cleanup starting...\n";

$tables = ['price_cache', 'chart_cache', 'schedule_cache', 'news_cache'];

foreach ($tables as $table) {
    $deleted = MySqlCache::cleanup($table, 7);
    echo "  $table: $deleted rows deleted\n";
}

// Also clean up analysis_history older than 90 days (keep recent ones)
try {
    $db   = Database::get();
    $stmt = $db->prepare("DELETE FROM analysis_history WHERE created_at < NOW() - INTERVAL 90 DAY");
    $stmt->execute();
    echo "  analysis_history: {$stmt->rowCount()} old rows deleted\n";
} catch (Throwable $e) {
    echo "  analysis_history cleanup failed: {$e->getMessage()}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
