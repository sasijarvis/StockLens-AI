<?php
// api/prices.php — bulk price fetch for watchlist
$symbolsParam = trim($_GET['symbols'] ?? '');
if (!$symbolsParam) { jsonResponse([]); }

$symbols = array_filter(array_map('trim', explode(',', strtoupper($symbolsParam))));
$symbols = array_slice($symbols, 0, 20);

$nse     = new NseApi();
$results = [];

foreach ($symbols as $sym) {
    try {
        $quote = $nse->getQuote($sym);
        if ($quote) {
            $p = $quote['priceInfo'] ?? [];
            $results[$sym] = [
                'price'      => $p['lastPrice']       ?? null,
                'change_pct' => $p['pChange']         ?? null,
                'change_abs' => $p['change']          ?? null,
                'week_high'  => $p['weekHighLow']['max'] ?? null,
                'week_low'   => $p['weekHighLow']['min'] ?? null,
                'pe'         => $quote['metadata']['pdSymbolPe'] ?? null,
            ];
        }
    } catch (Throwable $e) {
        logError('api/prices:' . $sym, $e);
        $results[$sym] = null;
    }
}

jsonResponse($results);
