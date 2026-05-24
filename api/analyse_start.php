<?php
// api/analyse_start.php
// Simple direct pipeline — no job queue.
// 1. Check cache → return instantly if fresh data exists
// 2. Otherwise run the full pipeline and return result when done
// Works on both XAMPP (mod_php) and PHP-FPM servers.

// Force suppress display_errors for this API endpoint — errors must go to log only,
// never to output (which would corrupt the JSON response)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');
@set_time_limit(300);
ignore_user_abort(true);

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/helpers.php';

spl_autoload_register(function(string $class): void {
    foreach (['/src/Api/', '/src/Data/', '/src/Ai/', '/src/Cache/', '/src/Models/', '/src/'] as $dir) {
        $f = dirname(__DIR__) . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

header('Content-Type: application/json');
header('X-Accel-Buffering: no');
header('Cache-Control: no-store');

$symbol       = strtoupper(trim($_GET['symbol'] ?? ''));
$forceRefresh = ($_GET['force'] ?? '0') === '1';
$model        = trim($_GET['model'] ?? '');

if (!$symbol) { echo json_encode(['error' => 'Symbol required']); exit; }
try { $symbol = validateSymbol($symbol); }
catch (Throwable) { echo json_encode(['error' => 'Invalid symbol']); exit; }

try {
    // ── 1. Cache check — return instantly if fresh ────────────────────────────
    if (!$forceRefresh) {
        $company = Company::findBySymbol($symbol);
        if ($company) {
            $cached = Analysis::getLatest((int)$company['id'], (int)env('CACHE_TTL_ANALYSIS', 21600));
            if ($cached) {
                $aggregator = new DataAggregator();
                $raw        = $aggregator->fetch($symbol, (int)$company['screener_id'], false);
                $priceRow   = buildPriceRow($raw);
                echo json_encode([
                    'status' => 'done',
                    'data'   => buildResult($cached, $company, $priceRow, $raw),
                    'models' => OpenRouterClient::SELECTABLE_MODELS,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    // ── 2. Full pipeline ──────────────────────────────────────────────────────
    $screenerApi = new ScreenerApi();
    $search      = $screenerApi->searchCompany($symbol);
    if (empty($search)) {
        echo json_encode(['error' => "Stock '$symbol' not found. Check the symbol."]); exit;
    }

    $companyInfo = $search[0];
    $screenerId  = (int)($companyInfo['id'] ?? 0);
    $companyName = $companyInfo['name'] ?? $symbol;
    $sector      = $companyInfo['sector'] ?? '';
    if (!$screenerId) {
        echo json_encode(['error' => "Could not resolve Screener ID for $symbol."]); exit;
    }

    $company   = Company::findOrCreate($symbol, $screenerId, $companyName, $sector);
    $companyId = (int)$company['id'];

    // Fetch all data
    $aggregator = new DataAggregator();
    $raw        = $aggregator->fetch($symbol, $screenerId, $forceRefresh);

    // Compute metrics
    $metrics  = MetricsComputer::computeAll($raw);
    $priceRow = buildPriceRow($raw, $metrics);
    if (!$priceRow) {
        echo json_encode(['error' => "Unable to fetch price data for $symbol. NSE may be unavailable."]); exit;
    }

    // Build prompt
    $na = fn($v) => ($v !== null && $v !== '') ? $v : 'N/A';
    $promptData = [
        'company_name'      => $companyName,
        'symbol'            => $symbol,
        'sector'            => $sector,
        'indices'           => is_string($priceRow['indices'] ?? null) ? implode(', ', json_decode($priceRow['indices'], true) ?: []) : 'N/A',
        'price'             => $na($priceRow['last_price']),
        'prev_close'        => $na($priceRow['prev_close']),
        'change_pct'        => $na($priceRow['change_pct']),
        'vwap'              => $na($priceRow['vwap']),
        'week_high'         => $na($priceRow['week_high']),
        'week_high_date'    => $na($priceRow['week_high_date']),
        'week_low'          => $na($priceRow['week_low']),
        'week_low_date'     => $na($priceRow['week_low_date']),
        'upper_circuit'     => $na($priceRow['upper_circuit']),
        'lower_circuit'     => $na($priceRow['lower_circuit']),
        'dma50'             => $na($metrics['dma_50']),
        'dma200'            => $na($metrics['dma_200']),
        'vs_dma50'          => $na($metrics['vs_dma50']),
        'vs_dma200'         => $na($metrics['vs_dma200']),
        'rsi_14'            => $na($metrics['rsi_14']),
        'market_cap_cr'     => $na($metrics['market_cap_cr'] ?? $priceRow['market_cap_cr'] ?? null),
        'pe'                => $na($priceRow['pe_ratio']),
        'median_pe'         => $na($metrics['median_pe']),
        'pe_vs_median'      => $na($metrics['pe_vs_median']),
        'sector_pe'         => $na($priceRow['sector_pe']),
        'ev_ebitda'         => $na($metrics['ev_ebitda']),
        'median_ev'         => $na($metrics['median_ev']),
        'pbv'               => $na($metrics['pbv']),
        'median_pbv'        => $na($metrics['median_pbv']),
        'mcap_sales'        => $na($metrics['mcap_sales']),
        'median_mcap_sales' => $na($metrics['median_mcap_sales']),
        'div_yield'         => $na($metrics['div_yield']),
        'revenue_latest'    => $na($metrics['revenue_latest']),
        'revenue_cagr'      => $na($metrics['revenue_cagr']),
        'profit_latest'     => $na($metrics['profit_latest']),
        'profit_cagr'       => $na($metrics['profit_cagr']),
        'sales_yoy'         => $na($metrics['sales_yoy']),
        'gpm'               => $na($metrics['gpm']),
        'opm'               => $na($metrics['opm']),
        'npm'               => $na($metrics['npm']),
        'margin_trend'      => $na($metrics['margin_trend']),
        'material_cost'     => $na($metrics['material_cost']),
        'borrowings'        => $na($metrics['debt_latest']),
        'debt_trend'        => $na($metrics['debt_trend']),
        'face_value'        => $na($priceRow['face_value'] ?? $company['face_value'] ?? null),
        'book_value'        => 'N/A',
        'op_cf'             => $na($metrics['op_cf_latest']),
        'inv_cf'            => $na($metrics['inv_cf_latest']),
        'fcf'               => $na($metrics['free_cash_flow']),
        'fcf_to_pat'        => $na($metrics['fcf_to_pat']),
        'peers_text'        => buildPeersText($raw['nse_quote'] ?? []),
        'news_text'         => buildNewsText($raw['news'] ?? []),
        'support_resistance'=> $metrics['support_resistance'] ?? [],
    ];

    // AI call — reconnect DB first in case connection dropped during data fetch
    Database::reset();
    $ai       = new OpenRouterClient();
    $aiResult = $ai->analyse(PromptBuilder::getSystemPrompt(), PromptBuilder::build($promptData), $model ?: null);
    error_log("[analyse_start] AI done for $symbol, model: " . $aiResult['model_used']);

    // Save to DB — reconnect after AI call
    Database::reset();
    $analysisId     = Analysis::save($companyId, $aiResult, $metrics, (float)($priceRow['last_price'] ?? 0), null);
    $savedRow       = Analysis::getLatest($companyId, 999999);
    $verdictHistory = Analysis::getVerdictHistory($companyId, 6);

    // Build sections
    $sections     = $aiResult['sections'] ?? [];
    $liveSections = [
        'price_and_technical_setup' => $sections['price_and_technical_setup'] ?? $sections['price_technical_setup'] ?? $savedRow['section_technical'] ?? null,
        'support_and_resistance'    => $sections['support_and_resistance']    ?? $sections['support_resistance']    ?? null,
        'valuation'                 => $sections['valuation']                 ?? $savedRow['section_valuation']     ?? null,
        'business_quality'          => $sections['business_quality']          ?? $savedRow['section_quality']       ?? null,
        'balance_sheet_health'      => $sections['balance_sheet_health']      ?? $sections['balance_sheet']         ?? $savedRow['section_balance']   ?? null,
        'cash_flow_quality'         => $sections['cash_flow_quality']         ?? $sections['cash_flow']             ?? $savedRow['section_cashflow']  ?? null,
        'catalysts_and_risks'       => $sections['catalysts_and_risks']       ?? $sections['key_catalysts_and_risks'] ?? $savedRow['section_catalysts'] ?? null,
        'trade_setup'               => $sections['trade_setup']               ?? $savedRow['section_trade_setup']   ?? null,
        'scenario_analysis'         => $sections['scenario_analysis']         ?? $sections['scenarios']             ?? $savedRow['section_scenarios'] ?? null,
        'conviction_matrix'         => $sections['conviction_matrix']         ?? $sections['conviction']            ?? null,
        'verdict'                   => $sections['verdict']                   ?? $savedRow['section_verdict']       ?? null,
    ];

    $encoded = json_encode([
        'status' => 'done',
        'data'   => [
            'id'                => $analysisId,
            'symbol'            => $company['nse_symbol'],
            'company_name'      => $company['company_name'],
            'sector'            => $company['sector'] ?? '',
            'verdict'           => $sections['verdict_label']    ?? $savedRow['verdict']          ?? 'Hold',
            'confidence'        => $sections['confidence_value'] ?? $savedRow['confidence_score'] ?? 50,
            'model'             => $aiResult['model_used'] ?? '',
            'created_at'        => $savedRow['created_at'] ?? date('Y-m-d H:i:s'),
            'price'             => $priceRow['last_price'] ?? null,
            'sections'          => $liveSections,
            'metrics_json'      => json_encode($metrics),
            'price_data'        => $priceRow,
            'chart_data'        => $raw['charts']        ?? null,
            'news'              => $raw['news']           ?? null,
            'peers'             => $raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null,
            'trade_setup'       => $sections['trade_setup_parsed']  ?? null,
            'scenarios'         => $sections['scenarios_parsed']    ?? null,
            'conviction'        => $sections['conviction_parsed']   ?? null,
            'news_sentiment'    => null,
            'verdict_history'   => $verdictHistory,
            'support_resistance'=> $metrics['support_resistance']   ?? [],
        ],
        'models' => OpenRouterClient::SELECTABLE_MODELS,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($encoded === false) {
        // json_encode failed — strip bad data and retry with only essential fields
        error_log('[analyse_start] json_encode failed: ' . json_last_error_msg() . ' — retrying with safe subset');
        $encoded = json_encode([
            'status' => 'done',
            'data'   => buildResult($savedRow, $company, $priceRow, ['charts' => [], 'news' => [], 'screener_peers' => null, 'nse_quote' => []]),
            'models' => OpenRouterClient::SELECTABLE_MODELS,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    error_log('[analyse_start] Sending response for ' . $symbol . ', encoded size: ' . strlen((string)$encoded));
    echo $encoded;

} catch (Throwable $e) {
    logError('analyse_start', $e);
    $msg = env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Analysis failed. Please try again.';
    // Use JSON_INVALID_UTF8_SUBSTITUTE to prevent json_encode from returning false
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function buildPriceRow(array $raw, array $metrics = []): ?array {
    if (isset($raw['nse_quote']['_cached'])) return $raw['nse_quote']['_row'];
    if (!empty($raw['nse_quote']['priceInfo'])) {
        $p = $raw['nse_quote']['priceInfo']    ?? [];
        $m = $raw['nse_quote']['metadata']     ?? [];
        $s = $raw['nse_quote']['securityInfo'] ?? [];
        return [
            'last_price'     => $p['lastPrice']              ?? null,
            'prev_close'     => $p['previousClose']          ?? null,
            'change_pct'     => $p['pChange']                ?? null,
            'vwap'           => $p['vwap']                   ?? null,
            'week_high'      => $p['weekHighLow']['max']     ?? null,
            'week_high_date' => $p['weekHighLow']['maxDate'] ?? null,
            'week_low'       => $p['weekHighLow']['min']     ?? null,
            'week_low_date'  => $p['weekHighLow']['minDate'] ?? null,
            'upper_circuit'  => $p['upperCP']                ?? null,
            'lower_circuit'  => $p['lowerCP']                ?? null,
            'pe_ratio'       => $m['pdSymbolPe']             ?? null,
            'sector_pe'      => $m['pdSectorPe']             ?? null,
            'market_cap_cr'  => $metrics['market_cap_cr']   ?? null,
            'face_value'     => $s['faceValue']              ?? null,
            'indices'        => json_encode($m['activeSeries'] ?? []),
        ];
    }
    return null;
}

function buildResult(array $row, array $company, ?array $price, ?array $raw): array {
    return [
        'id'           => $row['id'],
        'symbol'       => $company['nse_symbol'],
        'company_name' => $company['company_name'],
        'sector'       => $company['sector'] ?? '',
        'verdict'      => $row['verdict'],
        'confidence'   => $row['confidence_score'],
        'model'        => $row['model_used'] ?? '',
        'created_at'   => $row['created_at'],
        'price'        => $row['price_at_time'],
        'sections' => [
            'price_and_technical_setup' => $row['section_technical']   ?? null,
            'support_and_resistance'    => null,
            'valuation'                 => $row['section_valuation']   ?? null,
            'business_quality'          => $row['section_quality']     ?? null,
            'balance_sheet_health'      => $row['section_balance']     ?? null,
            'cash_flow_quality'         => $row['section_cashflow']    ?? null,
            'catalysts_and_risks'       => $row['section_catalysts']   ?? null,
            'trade_setup'               => $row['section_trade_setup'] ?? null,
            'scenario_analysis'         => $row['section_scenarios']   ?? null,
            'conviction_matrix'         => null,
            'verdict'                   => $row['section_verdict']     ?? null,
        ],
        'metrics_json'      => $row['key_metrics_json'] ?? '{}',
        'price_data'        => $price,
        'chart_data'        => $raw['charts'] ?? null,
        'news'              => $raw['news']   ?? null,
        'peers'             => $raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null,
        'trade_setup'       => !empty($row['section_trade_setup']) ? OpenRouterClient::parseTradeSetupPublic($row['section_trade_setup']) : null,
        'scenarios'         => !empty($row['section_scenarios'])   ? OpenRouterClient::parseScenariosPublic($row['section_scenarios'])   : null,
        'conviction'        => !empty($row['conviction_json'])     ? json_decode($row['conviction_json'], true)     : null,
        'news_sentiment'    => !empty($row['news_sentiment_json']) ? json_decode($row['news_sentiment_json'], true) : null,
        'verdict_history'   => Analysis::getVerdictHistory((int)$row['company_id'], 6),
        'support_resistance'=> !empty($row['support_resistance_json']) ? json_decode($row['support_resistance_json'], true) : [],
    ];
}

function buildPeersText(array $nseQuote): string {
    $peers = $nseQuote['industryInfo'] ?? [];
    if (empty($peers)) return 'Peer data not available.';
    $lines = [];
    foreach (array_slice($peers, 0, PEER_COUNT) as $p) {
        $lines[] = sprintf('%s (NSE: %s) — Price: Rs %s | PE: %s | MCap: Rs %s Cr',
            $p['companyName'] ?? '?', $p['symbol'] ?? '?',
            $p['lastPrice'] ?? 'N/A', $p['peRatio'] ?? 'N/A',
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
