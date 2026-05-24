<?php
// api/search.php — returns autocomplete results
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { jsonResponse([]); }

try {
    $screener = new ScreenerApi();
    $results  = $screener->searchCompany($q);

    if (empty($results)) { jsonResponse([]); }

    $out = [];
    foreach (array_slice($results, 0, 8) as $r) {
        $out[] = [
            'name'       => $r['name']      ?? $r['company_name'] ?? '',
            'symbol'     => $r['ticker']    ?? $r['symbol']       ?? '',
            'sector'     => $r['sector']    ?? '',
            'screener_id'=> $r['id']        ?? 0,
            'url'        => '/analysis/' . ($r['ticker'] ?? $r['symbol'] ?? ''),
        ];
    }

    jsonResponse($out);
} catch (Throwable $e) {
    logError('api/search', $e);
    jsonResponse([]);
}
