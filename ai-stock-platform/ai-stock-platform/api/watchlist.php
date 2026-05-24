<?php
// api/watchlist.php — add / remove
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
    default  => jsonResponse(['error' => 'Invalid action'], 400),
};
