<?php
// api/watchlist.php — add / remove / check / update_alert
$action    = $_POST['action']     ?? $_GET['action']     ?? '';
$companyId = (int)($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
$symbol    = strtoupper(trim($_POST['symbol'] ?? $_GET['symbol'] ?? ''));

if (!$companyId && $symbol) {
    $company   = Company::findBySymbol($symbol);
    $companyId = $company ? (int)$company['id'] : 0;
}

if (!$companyId) { jsonResponse(['error' => 'company_id required'], 400); }

match ($action) {
    'add'    => jsonResponse(['ok' => Watchlist::add($companyId),    'in_watchlist' => true]),
    'remove' => jsonResponse(['ok' => Watchlist::remove($companyId), 'in_watchlist' => false]),
    'check'  => jsonResponse(['in_watchlist' => Watchlist::has($companyId)]),
    'update_alert' => handleUpdateAlert($companyId),
    default  => jsonResponse(['error' => 'Invalid action'], 400),
};

function handleUpdateAlert(int $companyId): never {
    $above = isset($_POST['alert_above']) && $_POST['alert_above'] !== ''
        ? (float)$_POST['alert_above'] : null;
    $below = isset($_POST['alert_below']) && $_POST['alert_below'] !== ''
        ? (float)$_POST['alert_below'] : null;

    if ($above !== null && $above <= 0) { jsonResponse(['error' => 'Alert price must be positive'], 400); }
    if ($below !== null && $below <= 0) { jsonResponse(['error' => 'Alert price must be positive'], 400); }
    if ($above !== null && $below !== null && $below >= $above) {
        jsonResponse(['error' => 'Alert below must be less than alert above'], 400);
    }

    jsonResponse(['ok' => Watchlist::updateAlerts($companyId, $above, $below)]);
}
