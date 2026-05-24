<?php
// api/analyse_status.php
// Phase 2 (polling): returns current job status + full result when done.
// Called by the browser every 2 seconds until status === 'done' or 'failed'.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/helpers.php';

spl_autoload_register(function(string $class): void {
    foreach (['/src/Models/', '/src/Cache/', '/src/Api/', '/src/Data/', '/src/Ai/', '/src/'] as $dir) {
        $f = dirname(__DIR__) . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

header('Content-Type: application/json');
// No caching on poll responses
header('Cache-Control: no-store, no-cache, must-revalidate');

$jobId  = trim($_GET['job_id'] ?? '');
$symbol = strtoupper(trim($_GET['symbol'] ?? ''));  // needed for cached-path response

if (!$jobId && !$symbol) {
    echo json_encode(['error' => 'job_id or symbol required']);
    exit;
}

// ── Human-readable step labels ─────────────────────────────────────────────
$stepLabels = [
    'queued'          => 'Queued — waiting to start…',
    'screener_search' => 'Looking up stock on Screener…',
    'company_resolve' => 'Resolving company data…',
    'cache_check'     => 'Checking analysis cache…',
    'data_fetch'      => 'Fetching live price & financials…',
    'metrics'         => 'Computing RSI, CAGR, margins…',
    'ai_call'         => 'Running AI analysis (this takes ~60s)…',
    'db_save'         => 'Saving results…',
    'done'            => 'Analysis complete ✓',
    'failed'          => 'Analysis failed',
];

try {
    $db = Database::get();

    // ── Handle cached-path poll (job_id is null, symbol provided) ─────────────
    // analyse_start returns status=cached with no job_id.
    // The frontend calls status with ?symbol= to fetch the cached result directly.
    if (!$jobId && $symbol) {
        $company = Company::findBySymbol($symbol);
        if (!$company) {
            echo json_encode(['status' => 'failed', 'error' => 'Company not found']);
            exit;
        }
        $cached = Analysis::getLatest((int)$company['id'], (int)env('CACHE_TTL_ANALYSIS', 21600));
        if (!$cached) {
            echo json_encode(['status' => 'failed', 'error' => 'Cache expired, please re-analyse']);
            exit;
        }

        // Build full response identical to what the worker would produce
        $aggregator = new DataAggregator();
        $raw        = $aggregator->fetch($symbol, (int)$company['screener_id'], false);
        $priceRow   = buildPriceRow($raw);

        echo json_encode([
            'status'    => 'done',
            'step'      => 'done',
            'step_label'=> 'Loaded from cache ✓',
            'progress'  => 100,
            'data'      => buildCachedResponse($cached, $company, $priceRow, $raw),
            'models'    => OpenRouterClient::SELECTABLE_MODELS,
        ]);
        exit;
    }

    // ── Normal job poll ────────────────────────────────────────────────────────
    $stmt = $db->prepare('SELECT * FROM analysis_jobs WHERE job_id = ?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        echo json_encode(['status' => 'failed', 'error' => 'Job not found. Please start a new analysis.']);
        exit;
    }

    $step      = $job['step'];
    $status    = $job['status'];
    $stepLabel = $stepLabels[$step] ?? $step;

    // ── Still running / queued ─────────────────────────────────────────────────
    if ($status === 'queued' || $status === 'running') {
        echo json_encode([
            'status'     => $status,
            'step'       => $step,
            'step_label' => $stepLabel,
            'progress'   => (int)$job['progress'],
        ]);
        exit;
    }

    // ── Failed ────────────────────────────────────────────────────────────────
    if ($status === 'failed') {
        echo json_encode([
            'status'     => 'failed',
            'step'       => $step,
            'step_label' => $stepLabel,
            'error'      => $job['error'] ?? 'Analysis failed. Please try again.',
        ]);
        exit;
    }

    // ── Done — return full result ──────────────────────────────────────────────
    if ($status === 'done') {
        $result = json_decode($job['result_json'] ?? 'null', true);

        // Defensive check: if result_json is null or corrupt, fall back to DB
        if (!is_array($result) || empty($result['company_name'])) {
            error_log("[Status] result_json null/corrupt for job $jobId, falling back to analysis_history");
            // Try to serve from analysis_history directly
            $symbol  = $job['symbol'] ?? '';
            $company = Company::findBySymbol($symbol);
            if ($company) {
                $saved = Analysis::getLatest((int)$company['id'], 999999);
                if ($saved) {
                    $aggregator = new DataAggregator();
                    $raw        = $aggregator->fetch($symbol, (int)$company['screener_id'], false);
                    $priceRow   = buildPriceRow($raw);
                    $result     = buildCachedResponse($saved, $company, $priceRow, $raw);
                    echo json_encode([
                        'status'     => 'done',
                        'step'       => 'done',
                        'step_label' => 'Analysis complete ✓',
                        'progress'   => 100,
                        'data'       => $result,
                        'models'     => OpenRouterClient::SELECTABLE_MODELS,
                    ]);
                    exit;
                }
            }
            // Nothing to show
            echo json_encode(['status' => 'failed', 'error' => 'Result unavailable. Please re-analyse.']);
            exit;
        }

        // Backfill chart_data / news / peers from cache (not stored in job row to avoid size limits)
        if (empty($result['chart_data']) || empty($result['news'])) {
            try {
                $symbol     = $result['symbol'] ?? $job['symbol'];
                $company    = Company::findBySymbol($symbol);
                if ($company) {
                    $aggregator = new DataAggregator();
                    $raw        = $aggregator->fetch($symbol, (int)$company['screener_id'], false);
                    $result['chart_data'] = $result['chart_data'] ?? ($raw['charts']        ?? null);
                    $result['news']       = $result['news']       ?? ($raw['news']           ?? null);
                    $result['peers']      = $result['peers']      ?? ($raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null);
                }
            } catch (Throwable $e) {
                error_log('[Status] backfill failed: ' . $e->getMessage());
                // Non-fatal — charts/news are optional
            }
        }

        // If trade_setup/scenarios came as raw text (from fallback path), re-parse them
        if (empty($result['trade_setup']) && !empty($result['sections']['trade_setup'])) {
            $result['trade_setup'] = OpenRouterClient::parseTradeSetupPublic($result['sections']['trade_setup']);
        }
        if (empty($result['scenarios']) && !empty($result['sections']['scenario_analysis'])) {
            $result['scenarios'] = OpenRouterClient::parseScenariosPublic($result['sections']['scenario_analysis']);
        }

        echo json_encode([
            'status'     => 'done',
            'step'       => 'done',
            'step_label' => 'Analysis complete ✓',
            'progress'   => 100,
            'data'       => $result,
            'models'     => OpenRouterClient::SELECTABLE_MODELS,
        ]);
        exit;
    }

} catch (Throwable $e) {
    logError('analyse_status', $e);
    echo json_encode(['status' => 'failed', 'error' => 'Status check failed. Please refresh.']);
}

// ── Helpers (duplicated from analyse.php for standalone use) ─────────────────

function buildPriceRow(array $raw): ?array {
    if (isset($raw['nse_quote']['_cached'])) {
        return $raw['nse_quote']['_row'];
    }
    if (!empty($raw['nse_quote']['priceInfo'])) {
        $p = $raw['nse_quote']['priceInfo'] ?? [];
        $m = $raw['nse_quote']['metadata']  ?? [];
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
            'market_cap_cr'  => null,
            'face_value'     => $s['faceValue']              ?? null,
            'indices'        => json_encode($m['activeSeries'] ?? []),
        ];
    }
    return null;
}

function buildCachedResponse(array $row, array $company, ?array $price, ?array $raw): array {
    $conviction    = !empty($row['conviction_json'])         ? json_decode($row['conviction_json'], true)         : null;
    $newsSentiment = !empty($row['news_sentiment_json'])     ? json_decode($row['news_sentiment_json'], true)     : null;
    $srLevels      = !empty($row['support_resistance_json']) ? json_decode($row['support_resistance_json'], true) : [];
    $verdictHistory = Analysis::getVerdictHistory((int)$row['company_id'], 6);

    // Parse structured trade setup and scenarios from stored raw text
    $tradeSetupParsed = !empty($row['section_trade_setup'])
        ? OpenRouterClient::parseTradeSetupPublic($row['section_trade_setup'])
        : null;
    $scenariosParsed  = !empty($row['section_scenarios'])
        ? OpenRouterClient::parseScenariosPublic($row['section_scenarios'])
        : null;

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
        'trade_setup'       => $tradeSetupParsed,
        'scenarios'         => $scenariosParsed,
        'conviction'        => $conviction,
        'news_sentiment'    => $newsSentiment,
        'verdict_history'   => $verdictHistory,
        'support_resistance'=> $srLevels,
    ];
}
