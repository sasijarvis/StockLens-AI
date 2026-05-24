<?php
// api/analyse.php — full analysis pipeline
$symbol       = strtoupper(trim($_GET['symbol'] ?? ''));
$forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';

if (!$symbol) { jsonResponse(['error' => 'Symbol required'], 400); }

try {
    $symbol = validateSymbol($symbol);
} catch (Throwable) {
    jsonResponse(['error' => 'Invalid symbol'], 400);
}

try {
    // 1. Look up company on Screener to get screener_id
    $screenerApi = new ScreenerApi();
    $search      = $screenerApi->searchCompany($symbol);

    if (empty($search)) {
        jsonResponse(['error' => 'Stock not found. Please check the symbol.'], 404);
    }

    $companyInfo = $search[0];
    $screenerId  = (int)($companyInfo['id'] ?? 0);
    $companyName = $companyInfo['name'] ?? $symbol;
    $sector      = $companyInfo['sector'] ?? '';

    if (!$screenerId) {
        jsonResponse(['error' => 'Could not resolve Screener ID for this symbol.'], 404);
    }

    // 2. Ensure company exists in DB
    $company = Company::findOrCreate($symbol, $screenerId, $companyName, $sector);
    $companyId = (int)$company['id'];

    // 3. Check for cached analysis
    if (!$forceRefresh) {
        $cached = Analysis::getLatest($companyId, (int)env('CACHE_TTL_ANALYSIS', 21600));
        if ($cached) {
            return jsonResponse([
                'status'    => 'cached',
                'cached_at' => $cached['created_at'],
                'data'      => buildAnalysisResponse($cached, $company),
            ]);
        }
    }

    // 4. Aggregate all data
    $aggregator = new DataAggregator();
    $raw        = $aggregator->fetch($symbol, $screenerId, $forceRefresh);

    // 5. Compute metrics
    $metrics = MetricsComputer::computeAll($raw);

    // 6. Extract NSE price data
    $priceRow = null;
    if (isset($raw['nse_quote']['_cached'])) {
        $priceRow = $raw['nse_quote']['_row'];
    } elseif (!empty($raw['nse_quote'])) {
        $p        = $raw['nse_quote']['priceInfo'] ?? [];
        $m        = $raw['nse_quote']['metadata'] ?? [];
        $s        = $raw['nse_quote']['securityInfo'] ?? [];
        $priceRow = [
            'last_price'     => $p['lastPrice']   ?? null,
            'prev_close'     => $p['previousClose'] ?? null,
            'change_pct'     => $p['pChange']      ?? null,
            'vwap'           => $p['vwap']          ?? null,
            'week_high'      => $p['weekHighLow']['max'] ?? null,
            'week_high_date' => $p['weekHighLow']['maxDate'] ?? null,
            'week_low'       => $p['weekHighLow']['min'] ?? null,
            'week_low_date'  => $p['weekHighLow']['minDate'] ?? null,
            'upper_circuit'  => $p['upperCP']       ?? null,
            'lower_circuit'  => $p['lowerCP']       ?? null,
            'pe_ratio'       => $m['pdSymbolPe']    ?? null,
            'sector_pe'      => $m['pdSectorPe']    ?? null,
            'market_cap_cr'  => $metrics['market_cap_cr'] ?? null,
            'indices'        => json_encode($raw['nse_quote']['metadata']['activeSeries'] ?? []),
            'face_value'     => $s['faceValue']     ?? null,
        ];
    }

    if (!$priceRow) {
        jsonResponse(['error' => 'Unable to fetch price data for ' . $symbol . '. NSE may be down or market closed.'], 503);
    }

    // 7. Format peers text
    $peersText = buildPeersText($raw['nse_quote'] ?? []);

    // 8. Format news text
    $newsText = buildNewsText($raw['news'] ?? []);

    // 9. Assemble prompt data
    $na = fn($v) => ($v !== null && $v !== '') ? $v : 'N/A';

    $promptData = [
        'company_name'    => $companyName,
        'symbol'          => $symbol,
        'sector'          => $sector,
        'indices'         => is_string($priceRow['indices']) ? implode(', ', json_decode($priceRow['indices'], true) ?: []) : 'N/A',
        'price'           => $na($priceRow['last_price']),
        'prev_close'      => $na($priceRow['prev_close']),
        'change_pct'      => $na($priceRow['change_pct']),
        'vwap'            => $na($priceRow['vwap']),
        'week_high'       => $na($priceRow['week_high']),
        'week_high_date'  => $na($priceRow['week_high_date']),
        'week_low'        => $na($priceRow['week_low']),
        'week_low_date'   => $na($priceRow['week_low_date']),
        'upper_circuit'   => $na($priceRow['upper_circuit']),
        'lower_circuit'   => $na($priceRow['lower_circuit']),
        'dma50'           => $na($metrics['dma_50']),
        'dma200'          => $na($metrics['dma_200']),
        'vs_dma50'        => $na($metrics['vs_dma50']),
        'vs_dma200'       => $na($metrics['vs_dma200']),
        'rsi_14'          => $na($metrics['rsi_14']),
        'market_cap_cr'   => $na($metrics['market_cap_cr'] ?? $priceRow['market_cap_cr']),
        'pe'              => $na($priceRow['pe_ratio']),
        'median_pe'       => $na($metrics['median_pe']),
        'pe_vs_median'    => $na($metrics['pe_vs_median']),
        'sector_pe'       => $na($priceRow['sector_pe']),
        'ev_ebitda'       => $na($metrics['ev_ebitda']),
        'median_ev'       => $na($metrics['median_ev']),
        'pbv'             => $na($metrics['pbv']),
        'median_pbv'      => $na($metrics['median_pbv']),
        'mcap_sales'      => $na($metrics['mcap_sales']),
        'median_mcap_sales'=> $na($metrics['median_mcap_sales']),
        'div_yield'       => $na($metrics['div_yield']),
        'revenue_latest'  => $na($metrics['revenue_latest']),
        'revenue_cagr'    => $na($metrics['revenue_cagr']),
        'profit_latest'   => $na($metrics['profit_latest']),
        'profit_cagr'     => $na($metrics['profit_cagr']),
        'sales_yoy'       => $na($metrics['sales_yoy']),
        'gpm'             => $na($metrics['gpm']),
        'opm'             => $na($metrics['opm']),
        'npm'             => $na($metrics['npm']),
        'margin_trend'    => $na($metrics['margin_trend']),
        'material_cost'   => $na($metrics['material_cost']),
        'borrowings'      => $na($metrics['debt_latest']),
        'debt_trend'      => $na($metrics['debt_trend']),
        'face_value'      => $na($priceRow['face_value'] ?? $company['face_value'] ?? null),
        'book_value'      => 'N/A', // Screener chart needed
        'op_cf'           => $na($metrics['op_cf_latest']),
        'inv_cf'          => $na($metrics['inv_cf_latest']),
        'fcf'             => $na($metrics['free_cash_flow']),
        'fcf_to_pat'      => $na($metrics['fcf_to_pat']),
        'peers_text'      => $peersText,
        'news_text'       => $newsText,
    ];

    // 10. Build prompts & call AI
    $systemPrompt = PromptBuilder::getSystemPrompt();
    $userPrompt   = PromptBuilder::build($promptData);

    $ai     = new OpenRouterClient();
    $result = $ai->analyse($systemPrompt, $userPrompt);

    // 11. Save to DB
    $analysisId = Analysis::save($companyId, $result, $metrics, (float)($priceRow['last_price'] ?? 0));

    // 12. Return structured response
    $saved = Analysis::getLatest($companyId, 999999);
    $response = [
        'status' => 'fresh',
        'data'   => buildAnalysisResponse($saved, $company, $priceRow, $raw),
    ];
    // In debug mode, also return raw AI output and parsed keys to help diagnose section issues
    if (env('APP_DEBUG') === 'true') {
        $response['debug'] = [
            'raw_ai_text'     => $result['raw'] ?? '',
            'parsed_keys'     => array_keys($result['sections'] ?? []),
            'sections_raw'    => $result['sections'] ?? [],
        ];
    }
    jsonResponse($response);

} catch (Throwable $e) {
    logError('api/analyse', $e);
    $msg = env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Analysis failed. Please try again.';
    jsonResponse(['error' => $msg], 500);
}

// ── Helpers ──────────────────────────────────────────

function buildAnalysisResponse(array $row, array $company, ?array $price = null, ?array $raw = null): array {
    return [
        'id'          => $row['id'],
        'symbol'      => $company['nse_symbol'],
        'company_name'=> $company['company_name'],
        'sector'      => $company['sector'] ?? '',
        'verdict'     => $row['verdict'],
        'confidence'  => $row['confidence_score'],
        'model'       => $row['model_used'] ?? '',
        'created_at'  => $row['created_at'],
        'price'       => $row['price_at_time'],
        'sections'    => [
            'price_and_technical_setup' => $row['section_technical'],
            'valuation'                 => $row['section_valuation'],
            'business_quality'          => $row['section_quality'],
            'balance_sheet_health'      => $row['section_balance'],
            'cash_flow_quality'         => $row['section_cashflow'],
            'catalysts_and_risks'       => $row['section_catalysts'],
            'verdict'                   => $row['section_verdict'],
            'confidence_score'          => null, // embedded in card
        ],
        'metrics_json' => $row['key_metrics_json'] ?? '{}',
        'price_data'   => $price,
        'chart_data'   => $raw['charts'] ?? null,
        'news'         => $raw['news']   ?? null,
        'peers'        => $raw['nse_quote']['industryInfo'] ?? null,
    ];
}

function buildPeersText(array $nseQuote): string {
    $peers = $nseQuote['industryInfo'] ?? [];
    if (empty($peers)) return 'Peer data not available.';
    $lines = [];
    foreach (array_slice($peers, 0, PEER_COUNT) as $p) {
        $lines[] = sprintf('%s (NSE: %s) — Price: Rs %s | PE: %s | MCap: Rs %s Cr',
            $p['companyName'] ?? '?',
            $p['symbol'] ?? '?',
            $p['lastPrice'] ?? 'N/A',
            $p['peRatio']   ?? 'N/A',
            isset($p['marketCap']) ? number_format((float)$p['marketCap'], 0) : 'N/A'
        );
    }
    return implode("\n", $lines);
}

function buildNewsText(array $news): string {
    if (empty($news)) return 'No recent news available.';
    $lines = [];
    foreach (array_slice($news, 0, NEWS_MAX) as $i => $n) {
        $date   = isset($n['published_at']) ? date('d M', strtotime($n['published_at'])) : '';
        $source = $n['source'] ?? '';
        $lines[] = ($i + 1) . ". [{$date}] {$n['title']}" . ($source ? " — {$source}" : '');
    }
    return implode("\n", $lines);
}
