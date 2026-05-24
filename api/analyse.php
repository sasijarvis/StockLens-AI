<?php
// api/analyse.php — full analysis pipeline

// Hard-suppress all PHP warnings/notices so they can never corrupt the JSON stream.
// Errors still go to the log file via error_log().
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Log PHP fatal errors to our own log file
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');

// Allow up to 5 minutes; ignore_user_abort keeps DB save running if browser closes early
@set_time_limit(300);
ignore_user_abort(true);

// Disable output buffering so Apache doesn't buffer and timeout waiting for a flush.
// This is the main reason an empty response is returned when the request takes >30s.
while (ob_get_level() > 0) ob_end_clean();
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('zlib.output_compression', '0');

// Rate limit: 5/min, 20/hr per IP (skipped for force-refresh from watchlist cron)
if (!isset($_SERVER['HTTP_X_CRON_SECRET']) || $_SERVER['HTTP_X_CRON_SECRET'] !== env('CRON_SECRET', '')) {
    RateLimiter::check('analyse');
}

// ── Sector heatmap sub-action ─────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'heatmap') {
    jsonResponse(['heatmap' => Analysis::getSectorHeatmap()]);
}

// ── News sentiment sub-action (called async AFTER the main analysis responds) ──
// Runs as a separate lightweight request so it never blocks the main analysis.
if (($_GET['action'] ?? '') === 'sentiment') {
    @set_time_limit(60);
    $sym = strtoupper(trim($_GET['symbol'] ?? ''));
    if (!$sym) jsonResponse(['error' => 'Symbol required'], 400);

    // Load the cached news for this symbol (already in chart_cache from main analysis)
    $agg  = new DataAggregator();
    $news = $agg->fetchNewsOnly($sym);

    $sentiment = NewsSentimentAnalyser::analyse($news, $sym);

    // Persist into the latest analysis row for this company
    $company = Company::findBySymbol($sym);
    if ($company) {
        Analysis::updateSentiment((int)$company['id'], $sentiment);
    }

    jsonResponse(['sentiment' => $sentiment]);
}

$symbol       = strtoupper(trim($_GET['symbol'] ?? ''));
$forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';
$modelOverride = trim($_GET['model'] ?? '');
$isDebug      = env('APP_DEBUG') === 'true';

// ── Timing tracker (shown in response when APP_DEBUG=true) ───────────────────
$t = ['start' => microtime(true)];
$tick = function(string $label) use (&$t, $isDebug): void {
    $t[$label] = round((microtime(true) - $t['start']) * 1000);
    if ($isDebug) {
        error_log(sprintf('[StockLens timing] %s: %dms', $label, $t[$label]));
    }
};

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
    $tick('screener_search');

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
            // Even for cached AI analysis, we still need live price + chart data
            $aggregator = new DataAggregator();
            $raw        = $aggregator->fetch($symbol, $screenerId, false); // use cache for data too

            $priceRow = null;
            if (isset($raw['nse_quote']['_cached'])) {
                $priceRow = $raw['nse_quote']['_row'];
            } elseif (!empty($raw['nse_quote']['priceInfo'])) {
                $p = $raw['nse_quote']['priceInfo'] ?? [];
                $m = $raw['nse_quote']['metadata']  ?? [];
                $s = $raw['nse_quote']['securityInfo'] ?? [];
                $priceRow = [
                    'last_price'     => $p['lastPrice']           ?? null,
                    'prev_close'     => $p['previousClose']       ?? null,
                    'change_pct'     => $p['pChange']             ?? null,
                    'vwap'           => $p['vwap']                ?? null,
                    'week_high'      => $p['weekHighLow']['max']  ?? null,
                    'week_high_date' => $p['weekHighLow']['maxDate'] ?? null,
                    'week_low'       => $p['weekHighLow']['min']  ?? null,
                    'week_low_date'  => $p['weekHighLow']['minDate'] ?? null,
                    'upper_circuit'  => $p['upperCP']             ?? null,
                    'lower_circuit'  => $p['lowerCP']             ?? null,
                    'pe_ratio'       => $m['pdSymbolPe']          ?? null,
                    'sector_pe'      => $m['pdSectorPe']          ?? null,
                    'market_cap_cr'  => null,
                    'face_value'     => $s['faceValue']           ?? null,
                ];
            }

            $cachedData = buildAnalysisResponse($cached, $company, $priceRow, $raw);

            return jsonResponse([
                'status'    => 'cached',
                'cached_at' => $cached['created_at'],
                'data'      => $cachedData,
                'models'    => OpenRouterClient::SELECTABLE_MODELS,
            ]);
        }
    }

    // 4. Aggregate all data
    $aggregator = new DataAggregator();
    $raw        = $aggregator->fetch($symbol, $screenerId, $forceRefresh);
    $tick('data_fetch');

    // 5. Compute metrics
    $metrics = MetricsComputer::computeAll($raw);
    $tick('metrics');

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
        'book_value'      => 'N/A',
        'op_cf'           => $na($metrics['op_cf_latest']),
        'inv_cf'          => $na($metrics['inv_cf_latest']),
        'fcf'             => $na($metrics['free_cash_flow']),
        'fcf_to_pat'      => $na($metrics['fcf_to_pat']),
        'peers_text'      => $peersText,
        'news_text'       => $newsText,
        'support_resistance' => $metrics['support_resistance'] ?? [],
    ];

    // 10. Build prompts & call AI (main analysis)
    $systemPrompt = PromptBuilder::getSystemPrompt();
    $userPrompt   = PromptBuilder::build($promptData);

    $ai     = new OpenRouterClient();
    $result = $ai->analyse($systemPrompt, $userPrompt, $modelOverride ?: null);
    $tick('ai_call');

    // 11. Save to DB — wrapped so a transient DB failure never kills the JSON response
    $analysisId = 0;
    try {
        $analysisId = Analysis::save($companyId, $result, $metrics, (float)($priceRow['last_price'] ?? 0), null);
    } catch (Throwable $dbEx) {
        logError('api/analyse DB save', $dbEx);
        // Continue — we still return the live AI result to the user
    }

    $tick('db_save');

    // 11b. Fetch verdict history (last 6 verdicts for the tracker)
    $verdictHistory = Analysis::getVerdictHistory($companyId, 6);

    // 12. Return structured response — use $result directly so sections are never null
    $saved = Analysis::getLatest($companyId, 999999);

    // Build sections from live $result first, fall back to DB row
    $sections = $result['sections'] ?? [];
    $liveSections = [
        'price_and_technical_setup' => $sections['price_and_technical_setup']
                                    ?? $sections['price_technical_setup']
                                    ?? $sections['price_and_technical']
                                    ?? $saved['section_technical'] ?? null,
        'support_and_resistance'    => $sections['support_and_resistance']
                                    ?? $sections['support_resistance']
                                    ?? null,
        'valuation'                 => $sections['valuation']
                                    ?? $saved['section_valuation'] ?? null,
        'business_quality'          => $sections['business_quality']
                                    ?? $saved['section_quality'] ?? null,
        'balance_sheet_health'      => $sections['balance_sheet_health']
                                    ?? $sections['balance_sheet']
                                    ?? $saved['section_balance'] ?? null,
        'cash_flow_quality'         => $sections['cash_flow_quality']
                                    ?? $sections['cash_flow']
                                    ?? $saved['section_cashflow'] ?? null,
        'catalysts_and_risks'       => $sections['catalysts_and_risks']
                                    ?? $sections['key_catalysts_and_risks']
                                    ?? $saved['section_catalysts'] ?? null,
        'trade_setup'               => $sections['trade_setup']
                                    ?? $saved['section_trade_setup'] ?? null,
        'scenario_analysis'         => $sections['scenario_analysis']
                                    ?? $sections['scenarios']
                                    ?? $saved['section_scenarios'] ?? null,
        'conviction_matrix'         => $sections['conviction_matrix']
                                    ?? $sections['conviction']
                                    ?? null,
        'verdict'                   => $sections['verdict']
                                    ?? $saved['section_verdict'] ?? null,
    ];

    $response = [
        'status' => 'fresh',
        'data'   => [
            'id'                => $analysisId,
            'symbol'            => $company['nse_symbol'],
            'company_name'      => $company['company_name'],
            'sector'            => $company['sector'] ?? '',
            'verdict'           => $result['sections']['verdict_label'] ?? $saved['verdict'] ?? 'Hold',
            'confidence'        => $result['sections']['confidence_value'] ?? $saved['confidence_score'] ?? 50,
            'model'             => $result['model_used'] ?? '',
            'created_at'        => $saved['created_at'] ?? date('Y-m-d H:i:s'),
            'price'             => $priceRow['last_price'] ?? null,
            'sections'          => $liveSections,
            'metrics_json'      => json_encode($metrics),
            'price_data'        => $priceRow,
            'chart_data'        => $raw['charts'] ?? null,
            'news'              => $raw['news']   ?? null,
            'peers'             => $raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null,
            // New AI feature fields
            'trade_setup'       => $sections['trade_setup_parsed']   ?? null,
            'scenarios'         => $sections['scenarios_parsed']      ?? null,
            'conviction'        => $sections['conviction_parsed']     ?? null,
            'news_sentiment'    => null, // loaded async via ?action=sentiment
            'verdict_history'   => $verdictHistory,
            'support_resistance'=> $metrics['support_resistance']     ?? [],
        ],
    ];
    $tick('total');
    $response['models'] = OpenRouterClient::SELECTABLE_MODELS;
    if ($isDebug) {
        $response['_timing_ms'] = $t;
    }
    jsonResponse($response);

} catch (Throwable $e) {
    logError('api/analyse', $e);
    $msg = env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Analysis failed. Please try again.';
    jsonResponse(['error' => $msg], 500);
}

// ── Helpers ──────────────────────────────────────────

function buildAnalysisResponse(array $row, array $company, ?array $price = null, ?array $raw = null): array {
    // Decode stored JSON blobs
    $conviction     = !empty($row['conviction_json'])          ? json_decode($row['conviction_json'], true)          : null;
    $newsSentiment  = !empty($row['news_sentiment_json'])      ? json_decode($row['news_sentiment_json'], true)      : null;
    $srLevels       = !empty($row['support_resistance_json'])  ? json_decode($row['support_resistance_json'], true)  : [];
    $metrics        = !empty($row['key_metrics_json'])         ? json_decode($row['key_metrics_json'], true)         : [];

    // Verdict history for cached responses
    $companyId      = (int)($row['company_id'] ?? 0);
    $verdictHistory = $companyId ? Analysis::getVerdictHistory($companyId, 6) : [];

    return [
        'id'                => $row['id'],
        'symbol'            => $company['nse_symbol'],
        'company_name'      => $company['company_name'],
        'sector'            => $company['sector'] ?? '',
        'verdict'           => $row['verdict'],
        'confidence'        => $row['confidence_score'],
        'model'             => $row['model_used'] ?? '',
        'created_at'        => $row['created_at'],
        'price'             => $row['price_at_time'],
        'sections'          => [
            'price_and_technical_setup' => $row['section_technical'],
            'support_and_resistance'    => null, // stored in support_resistance_json
            'valuation'                 => $row['section_valuation'],
            'business_quality'          => $row['section_quality'],
            'balance_sheet_health'      => $row['section_balance'],
            'cash_flow_quality'         => $row['section_cashflow'],
            'catalysts_and_risks'       => $row['section_catalysts'],
            'trade_setup'               => $row['section_trade_setup']  ?? null,
            'scenario_analysis'         => $row['section_scenarios']    ?? null,
            'conviction_matrix'         => null, // stored in conviction_json
            'verdict'                   => $row['section_verdict'],
        ],
        'metrics_json'      => $row['key_metrics_json'] ?? '{}',
        'price_data'        => $price,
        'chart_data'        => $raw['charts'] ?? null,
        'news'              => $raw['news']   ?? null,
        'peers'             => $raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null,
        // New AI feature fields (decoded from JSON columns)
        'trade_setup'       => null, // raw text in sections.trade_setup; structured data not re-parsed for cache
        'scenarios'         => null,
        'conviction'        => $conviction,
        'news_sentiment'    => $newsSentiment,
        'verdict_history'   => $verdictHistory,
        'support_resistance'=> $srLevels,
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