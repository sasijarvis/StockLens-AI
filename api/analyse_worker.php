<?php
// api/analyse_worker.php
// Background worker — spawned by analyse_start.php via exec/popen.
// Runs the full pipeline: data fetch → metrics → AI → DB save.
// Updates analysis_jobs.step and progress throughout so the UI shows real progress.
// Receives args: $argv[1]=job_id, $argv[2]=symbol, $argv[3]=model (optional)

// ── Bootstrap ────────────────────────────────────────────────────────────────
define('IS_WORKER', true);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Find project root regardless of where this script is called from
$root = dirname(__DIR__);
ini_set('error_log', $root . '/logs/php_errors.log');

@set_time_limit(300);
ignore_user_abort(true);

require_once $root . '/config/env.php';
require_once $root . '/config/constants.php';
require_once $root . '/config/database.php';
require_once $root . '/src/helpers.php';

spl_autoload_register(function(string $class) use ($root): void {
    foreach (['/src/Api/', '/src/Data/', '/src/Ai/', '/src/Cache/', '/src/Models/', '/src/'] as $dir) {
        $f = $root . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// ── Args ─────────────────────────────────────────────────────────────────────
$jobId  = $argv[1] ?? null;
$symbol = strtoupper(trim($argv[2] ?? ''));
$model  = trim($argv[3] ?? '');

if (!$jobId || !$symbol) {
    error_log('[Worker] Missing job_id or symbol — exiting');
    exit(1);
}

// ── Job progress helper ───────────────────────────────────────────────────────
function jobUpdate(string $jobId, string $status, string $step, int $progress, ?string $error = null): void {
    try {
        $db = Database::get();
        $db->prepare(
            "UPDATE analysis_jobs SET status=?, step=?, progress=?, error=?, updated_at=NOW() WHERE job_id=?"
        )->execute([$status, $step, $progress, $error, $jobId]);
    } catch (Throwable $e) {
        error_log('[Worker] jobUpdate failed: ' . $e->getMessage());
    }
}

function jobDone(string $jobId, array $result): void {
    try {
        $db = Database::get();
        $db->prepare(
            "UPDATE analysis_jobs SET status='done', step='done', progress=100,
             result_json=?, updated_at=NOW() WHERE job_id=?"
        )->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $jobId]);
    } catch (Throwable $e) {
        error_log('[Worker] jobDone failed: ' . $e->getMessage());
    }
}

function jobFail(string $jobId, string $error): void {
    try {
        $db = Database::get();
        $db->prepare(
            "UPDATE analysis_jobs SET status='failed', step='failed', error=?, updated_at=NOW() WHERE job_id=?"
        )->execute([substr($error, 0, 490), $jobId]);
    } catch (Throwable $e) {
        error_log('[Worker] jobFail failed: ' . $e->getMessage());
    }
}

// ── Main pipeline ─────────────────────────────────────────────────────────────
try {
    error_log("[Worker] Starting job $jobId for $symbol");
    jobUpdate($jobId, 'running', 'screener_search', 5);

    // ── Step 1: Screener search ───────────────────────────────────────────────
    $screenerApi = new ScreenerApi();
    $search      = $screenerApi->searchCompany($symbol);

    if (empty($search)) {
        jobFail($jobId, "Stock '$symbol' not found on Screener. Please check the symbol.");
        exit;
    }

    $companyInfo = $search[0];
    $screenerId  = (int)($companyInfo['id'] ?? 0);
    $companyName = $companyInfo['name'] ?? $symbol;
    $sector      = $companyInfo['sector'] ?? '';

    if (!$screenerId) {
        jobFail($jobId, "Could not resolve Screener ID for $symbol.");
        exit;
    }

    // ── Step 2: Ensure company exists in DB ───────────────────────────────────
    jobUpdate($jobId, 'running', 'company_resolve', 10);
    $company   = Company::findOrCreate($symbol, $screenerId, $companyName, $sector);
    $companyId = (int)$company['id'];

    // ── Step 3: Check if a fresh analysis already exists (race-condition guard) ─
    $job = Database::get()->prepare('SELECT force_refresh FROM analysis_jobs WHERE job_id=?');
    $job->execute([$jobId]);
    $jobRow       = $job->fetch();
    $forceRefresh = (bool)($jobRow['force_refresh'] ?? false);

    if (!$forceRefresh) {
        jobUpdate($jobId, 'running', 'cache_check', 15);
        $cached = Analysis::getLatest($companyId, (int)env('CACHE_TTL_ANALYSIS', 21600));
        if ($cached) {
            // Another request already saved a fresh analysis while this job was queued.
            // Build and store the result so the status endpoint can return it.
            $aggregator = new DataAggregator();
            $raw        = $aggregator->fetch($symbol, $screenerId, false);
            $priceRow   = buildPriceRow($raw);
            $result     = buildCachedResult($cached, $company, $priceRow, $raw);
            jobDone($jobId, $result);
            error_log("[Worker] Job $jobId served from existing cache");
            exit;
        }
    }

    // ── Step 4: Fetch all external data ───────────────────────────────────────
    jobUpdate($jobId, 'running', 'data_fetch', 20);
    $aggregator = new DataAggregator();
    $raw        = $aggregator->fetch($symbol, $screenerId, $forceRefresh);

    // ── Step 5: Compute metrics ───────────────────────────────────────────────
    jobUpdate($jobId, 'running', 'metrics', 45);
    $metrics = MetricsComputer::computeAll($raw);

    // ── Step 6: Extract price row ─────────────────────────────────────────────
    $priceRow = buildPriceRow($raw, $metrics);
    if (!$priceRow) {
        jobFail($jobId, "Unable to fetch price data for $symbol. NSE may be unavailable or market closed.");
        exit;
    }

    // ── Step 7: Build AI prompt ───────────────────────────────────────────────
    jobUpdate($jobId, 'running', 'ai_call', 50);

    $na        = fn($v) => ($v !== null && $v !== '') ? $v : 'N/A';
    $peersText = buildPeersText($raw['nse_quote'] ?? []);
    $newsText  = buildNewsText($raw['news'] ?? []);

    $promptData = [
        'company_name'      => $companyName,
        'symbol'            => $symbol,
        'sector'            => $sector,
        'indices'           => is_string($priceRow['indices'] ?? null)
                                ? implode(', ', json_decode($priceRow['indices'], true) ?: [])
                                : 'N/A',
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
        'peers_text'        => $peersText,
        'news_text'         => $newsText,
        'support_resistance'=> $metrics['support_resistance'] ?? [],
    ];

    $systemPrompt = PromptBuilder::getSystemPrompt();
    $userPrompt   = PromptBuilder::build($promptData);

    // ── Step 8: Call AI ───────────────────────────────────────────────────────
    // Re-open DB connection in case it dropped during data fetching
    Database::reset();
    jobUpdate($jobId, 'running', 'ai_call', 55);

    $ai     = new OpenRouterClient();
    $aiResult = $ai->analyse($systemPrompt, $userPrompt, $model ?: null);
    error_log("[Worker] AI call done for $jobId, model: " . $aiResult['model_used']);

    // ── Step 9: Save to DB ────────────────────────────────────────────────────
    jobUpdate($jobId, 'running', 'db_save', 90);

    // Reconnect after the long AI call
    Database::reset();
    $analysisId     = Analysis::save($companyId, $aiResult, $metrics, (float)($priceRow['last_price'] ?? 0), null);
    $savedRow       = Analysis::getLatest($companyId, 999999);
    $verdictHistory = Analysis::getVerdictHistory($companyId, 6);

    // ── Step 10: Build final result JSON ──────────────────────────────────────
    $sections     = $aiResult['sections'] ?? [];
    $liveSections = [
        'price_and_technical_setup' => $sections['price_and_technical_setup']
                                    ?? $sections['price_technical_setup']
                                    ?? $sections['price_and_technical']
                                    ?? $savedRow['section_technical'] ?? null,
        'support_and_resistance'    => $sections['support_and_resistance']
                                    ?? $sections['support_resistance'] ?? null,
        'valuation'                 => $sections['valuation']
                                    ?? $savedRow['section_valuation'] ?? null,
        'business_quality'          => $sections['business_quality']
                                    ?? $savedRow['section_quality'] ?? null,
        'balance_sheet_health'      => $sections['balance_sheet_health']
                                    ?? $sections['balance_sheet']
                                    ?? $savedRow['section_balance'] ?? null,
        'cash_flow_quality'         => $sections['cash_flow_quality']
                                    ?? $sections['cash_flow']
                                    ?? $savedRow['section_cashflow'] ?? null,
        'catalysts_and_risks'       => $sections['catalysts_and_risks']
                                    ?? $sections['key_catalysts_and_risks']
                                    ?? $savedRow['section_catalysts'] ?? null,
        'trade_setup'               => $sections['trade_setup']
                                    ?? $savedRow['section_trade_setup'] ?? null,
        'scenario_analysis'         => $sections['scenario_analysis']
                                    ?? $sections['scenarios']
                                    ?? $savedRow['section_scenarios'] ?? null,
        'conviction_matrix'         => $sections['conviction_matrix']
                                    ?? $sections['conviction'] ?? null,
        'verdict'                   => $sections['verdict']
                                    ?? $savedRow['section_verdict'] ?? null,
    ];

    $result = [
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
        'chart_data'        => $raw['charts'] ?? null,
        'news'              => $raw['news']   ?? null,
        'peers'             => $raw['screener_peers'] ?? $raw['nse_quote']['industryInfo'] ?? null,
        'trade_setup'       => $sections['trade_setup_parsed']  ?? null,
        'scenarios'         => $sections['scenarios_parsed']    ?? null,
        'conviction'        => $sections['conviction_parsed']   ?? null,
        'news_sentiment'    => null,
        'verdict_history'   => $verdictHistory,
        'support_resistance'=> $metrics['support_resistance']   ?? [],
    ];

    jobDone($jobId, $result);
    error_log("[Worker] Job $jobId completed successfully");

} catch (Throwable $e) {
    logError("Worker job $jobId", $e);
    jobFail($jobId, $e->getMessage());
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function buildPriceRow(array $raw, array $metrics = []): ?array {
    if (isset($raw['nse_quote']['_cached'])) {
        return $raw['nse_quote']['_row'];
    }
    if (!empty($raw['nse_quote']['priceInfo'])) {
        $p = $raw['nse_quote']['priceInfo']  ?? [];
        $m = $raw['nse_quote']['metadata']   ?? [];
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

function buildCachedResult(array $row, array $company, ?array $price, ?array $raw): array {
    $conviction    = !empty($row['conviction_json'])         ? json_decode($row['conviction_json'], true)         : null;
    $newsSentiment = !empty($row['news_sentiment_json'])     ? json_decode($row['news_sentiment_json'], true)     : null;
    $srLevels      = !empty($row['support_resistance_json']) ? json_decode($row['support_resistance_json'], true) : [];
    $verdictHistory = Analysis::getVerdictHistory((int)$row['company_id'], 6);

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
        'trade_setup'       => null,
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
