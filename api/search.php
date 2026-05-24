<?php
// api/search.php — returns autocomplete results
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { jsonResponse([]); }

try {
    $screener = new ScreenerApi();
    $results  = $screener->searchCompany($q);

    if (empty($results)) { jsonResponse([]); }

    $out = [];
    foreach ($results as $r) {
        $id = $r['id'] ?? null;

        // Skip null-id entries (e.g. "Search everywhere" row)
        if (!$id) continue;

        // Extract NSE symbol from Screener URL: "/company/ADANIPOWER/consolidated/" → "ADANIPOWER"
        $symbol = '';
        if (!empty($r['url'])) {
            // Pattern: /company/{SYMBOL}/ optionally followed by consolidated/ or other path
            if (preg_match('#/company/([^/]+)/#', $r['url'], $m)) {
                $symbol = strtoupper($m[1]);
            }
        }

        // Skip if we couldn't extract a symbol
        if (!$symbol) continue;

        $out[] = [
            'name'        => $r['name'] ?? '',
            'symbol'      => $symbol,
            'sector'      => $r['sector'] ?? '',
            'screener_id' => (int)$id,
            'url'         => '/analysis/' . $symbol,
        ];

        if (count($out) >= 8) break;
    }

    jsonResponse($out);
} catch (Throwable $e) {
    logError('api/search', $e);
    jsonResponse([]);
}