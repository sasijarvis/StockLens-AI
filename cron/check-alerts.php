<?php
/**
 * cron/check-alerts.php — Price alert checker
 * Schedule: *\/15 * * * * php /path/to/cron/check-alerts.php >> /path/to/logs/cron.log 2>&1
 *
 * Checks current prices against each watchlist row's alert_price_above / alert_price_below.
 * Sends an email to the owning user when a threshold is crossed.
 * Uses a cooldown (1 alert per stock per 4 hours) to avoid flooding.
 */

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
        dirname(__DIR__) . '/src/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

$alertCooldownSeconds = 4 * 3600; // 4 hours between repeated alerts for same stock
$start = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] Alert check starting...\n";

$db     = Database::get();
$stocks = Watchlist::getSymbols(); // all users' watchlist rows

if (empty($stocks)) {
    echo "No watchlist entries. Exiting.\n";
    exit(0);
}

$nse     = new NseApi();
$checked = 0;
$alerted = 0;

foreach ($stocks as $row) {
    $above = $row['alert_price_above'] ? (float)$row['alert_price_above'] : null;
    $below = $row['alert_price_below'] ? (float)$row['alert_price_below'] : null;

    // Skip if no alerts configured
    if ($above === null && $below === null) continue;

    $symbol = $row['nse_symbol'];
    $email  = $row['user_email'] ?? null;

    // Skip rows without an owner email (legacy global watchlist rows with no user)
    if (!$email) continue;

    $checked++;

    // Fetch live price (from cache first to avoid hammering NSE)
    try {
        $priceRow = MySqlCache::get('price_cache', ['company_id' => $row['company_id']], 300);
        $price    = $priceRow ? (float)($priceRow['last_price'] ?? 0) : 0;

        if (!$price) {
            $quote = $nse->getQuote($symbol);
            $price = $quote ? (float)($quote['priceInfo']['lastPrice'] ?? 0) : 0;
        }

        if (!$price) continue;

    } catch (Throwable $e) {
        echo "  ✗ $symbol: " . $e->getMessage() . "\n";
        continue;
    }

    // Check if alert already sent recently (cooldown)
    $cooldownKey = "alert_{$row['company_id']}_{$row['user_id']}";
    $recentAlert = $db->prepare("SELECT 1 FROM rate_limits
                                 WHERE ip_hash = ? AND endpoint = 'alert'
                                 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1");
    $recentAlert->execute([$cooldownKey, $alertCooldownSeconds]);
    if ($recentAlert->fetchColumn()) continue; // still in cooldown

    $triggered = null;
    $message   = '';

    if ($above !== null && $price >= $above) {
        $triggered = 'above';
        $message   = "📈 {$symbol} has crossed ABOVE your alert price of ₹" . number_format($above, 2) . ".\nCurrent price: ₹" . number_format($price, 2);
    } elseif ($below !== null && $price <= $below) {
        $triggered = 'below';
        $message   = "📉 {$symbol} has dropped BELOW your alert price of ₹" . number_format($below, 2) . ".\nCurrent price: ₹" . number_format($price, 2);
    }

    if ($triggered) {
        $sent = sendAlertEmail($email, $symbol, $message);
        if ($sent) {
            // Record in rate_limits to enforce cooldown
            $db->prepare("INSERT INTO rate_limits (ip_hash, endpoint) VALUES (?, 'alert')")
               ->execute([$cooldownKey]);
            echo "  ✉ Alert sent: $symbol → $email ($triggered ₹$price)\n";
            $alerted++;
        }
    }

    sleep(1); // be polite to NSE
}

$elapsed = round(microtime(true) - $start, 2);
echo '[' . date('Y-m-d H:i:s') . "] Done. Checked: $checked | Alerts sent: $alerted | Time: {$elapsed}s\n";

// ── Email sender ──────────────────────────────────────────────────────────────

function sendAlertEmail(string $to, string $symbol, string $body): bool {
    $from    = env('ALERT_FROM_EMAIL', 'noreply@stocklens.local');
    $appUrl  = env('APP_URL', 'http://localhost');
    $subject = "StockLens Alert: $symbol price triggered";

    $fullBody = $body . "\n\n"
        . "View analysis: {$appUrl}/analysis/{$symbol}\n\n"
        . "---\nManage your alerts: {$appUrl}/watchlist\n"
        . "StockLens AI — for informational purposes only.";

    $headers = "From: StockLens AI <{$from}>\r\n"
             . "Reply-To: {$from}\r\n"
             . "X-Mailer: PHP/" . phpversion();

    $sent = mail($to, $subject, $fullBody, $headers);
    if (!$sent) {
        logError('sendAlertEmail', new RuntimeException("mail() failed for $to ($symbol)"));
    }
    return $sent;
}
