<?php
// api/analyse_steps.php
// Pipeline split into 5 independent steps.
// Each step is fast, cacheable, and called separately by the browser.
// ?step=1..5 &symbol=RELIANCE [&force=1] [&model=fast]

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
    foreach (['/src/Api/','/src/Data/','/src/Ai/','/src/Cache/','/src/Models/','/src/'] as $dir) {
        $f = dirname(__DIR__) . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

header('Content-Type: application/json');
header('Cache-Control: no-store');

$step         = (int)($_GET['step'] ?? 0);
$symbol       = strtoupper(trim($_GET['symbol'] ?? ''));
$forceRefresh = ($_GET['force'] ?? '0') === '1';
$model        = trim($_GET['model'] ?? '');

if (!$symbol) { echo json_encode(['error' => 'Symbol required']); exit; }
try { $symbol = validateSymbol($symbol); }
catch (Throwable) { echo json_encode(['error' => 'Invalid symbol']); exit; }

try {
    switch ($step) {

        // ── Step 1: Resolve company + fetch NSE price ─────────────────────────
        case 1: {
            // Check if fresh AI analysis already exists — skip the full pipeline
            $company = Company::findBySymbol($symbol);
            if ($company && !$forceRefresh) {
                $cached = Analysis::getLatest((int)$company['id'], (int)env('CACHE_TTL_ANALYSIS', 21600));
                if ($cached) {
                    echo json_encode(['status' => 'cached', 'company_id' => $company['id']]);
                    exit;
                }
            }

            // Search Screener for company metadata
            $screenerApi = new ScreenerApi();
            $search      = $screenerApi->searchCompany($symbol);
            if (empty($search)) {
                echo json_encode(['error' => "Stock '$symbol' not found."]); exit;
            }

            $info        = $search[0];
            $screenerId  = (int)($info['id'] ?? 0);
            $companyName = $info['name'] ?? $symbol;
            $sector      = $info['sector'] ?? '';

            if (!$screenerId) {
                echo json_encode(['error' => "Could not resolve Screener ID for $symbol."]); exit;
            }

            // Upsert company in DB
            $company   = Company::findOrCreate($symbol, $screenerId, $companyName, $sector);
            $companyId = (int)$company['id'];

            // Fetch NSE price (uses cache TTL 5 min)
            $nseApi   = new NseApi();
            $quote    = null;
            $priceRow = $forceRefresh ? null : MySqlCache::get('price_cache', ['company_id' => $companyId], (int)env('CACHE_TTL_PRICE', 300));

            if ($priceRow) {
                $quote = ['_cached' => true, '_row' => $priceRow];
            } else {
                try {
                    $raw = $nseApi->getQuote($symbol);
                    if ($raw) {
                        $quote = $raw;
                        savePriceCache($companyId, $raw);
                    }
                } catch (Throwable $e) {
                    logError('step1:nse', $e);
                }
            }

            echo json_encode([
                'status'      => 'ok',
                'company_id'  => $companyId,
                'screener_id' => $screenerId,
                'company_name'=> $companyName,
                'sector'      => $sector,
                'nse_quote'   => $quote,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        // ── Step 2: Fetch Screener charts + financial schedules ───────────────
        case 2: {
            $screenerId = (int)($_GET['screener_id'] ?? 0);
            $companyId  = (int)($_GET['company_id']  ?? 0);
            if (!$screenerId || !$companyId) {
                echo json_encode(['error' => 'screener_id and company_id required']); exit;
            }

            $screenerApi = new ScreenerApi();

            // Charts (TTL 6hr)
            $chartKeys  = ['price','pe','margins','ev','mcap','pbv','dividend'];
            $charts     = [];
            $needCharts = [];
            foreach ($chartKeys as $key) {
                $cached = MySqlCache::get('chart_cache', ['company_id' => $companyId, 'metric_group' => $key, 'days' => 1825], (int)env('CACHE_TTL_CHART', 21600));
                if ($cached) {
                    $charts[$key] = json_decode($cached['raw_json'], true);
                } else {
                    $needCharts[] = $key;
                }
            }
            if (!empty($needCharts)) {
                try {
                    $fetched = $screenerApi->getAllCharts($screenerId);
                    foreach ($fetched as $key => $data) {
                        if ($data) {
                            $charts[$key] = $data;
                            MySqlCache::set('chart_cache', ['company_id' => $companyId, 'metric_group' => $key, 'days' => 1825, 'raw_json' => json_encode($data)]);
                        }
                    }
                } catch (Throwable $e) { logError('step2:charts', $e); }
            }

            // Schedules (TTL 24hr)
            $scheduleMap = [
                'pl_sales'    => ['profit-loss',   'Sales'],
                'pl_profit'   => ['profit-loss',   'Net Profit'],
                'pl_material' => ['profit-loss',   'Material Cost %'],
                'cf_ops'      => ['cash-flow',     'Cash from Operating Activity'],
                'cf_inv'      => ['cash-flow',     'Cash from Investing Activity'],
                'bs_borrow'   => ['balance-sheet', 'Borrowings'],
            ];
            $schedules     = [];
            $needSchedules = [];
            foreach ($scheduleMap as $key => [$section, $parent]) {
                try {
                    $stmt = Database::get()->prepare(
                        "SELECT years_json, values_json, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
                         FROM schedule_cache WHERE company_id=? AND section=? AND parent=? LIMIT 1"
                    );
                    $stmt->execute([$companyId, $section, $parent]);
                    $row = $stmt->fetch();
                    if ($row && $row['age'] < (int)env('CACHE_TTL_SCHEDULE', 86400)) {
                        $schedules[$key] = ['years' => json_decode($row['years_json'], true), 'values' => json_decode($row['values_json'], true)];
                    } else {
                        $needSchedules[$key] = [$section, $parent];
                    }
                } catch (Throwable) {
                    $needSchedules[$key] = [$section, $parent];
                }
            }
            if (!empty($needSchedules)) {
                try {
                    $fetched = $screenerApi->getAllSchedules($screenerId);
                    foreach ($needSchedules as $key => [$section, $parent]) {
                        if (!empty($fetched[$key])) {
                            $data = $fetched[$key];
                            $schedules[$key] = $data;
                            MySqlCache::set('schedule_cache', [
                                'company_id'  => $companyId, 'section' => $section, 'parent' => $parent,
                                'years_json'  => json_encode($data['years']  ?? []),
                                'values_json' => json_encode($data['values'] ?? []),
                                'breakdown_json' => null,
                            ]);
                        }
                    }
                } catch (Throwable $e) { logError('step2:schedules', $e); }
            }

            // Peers (TTL 1hr)
            $peers = null;
            $cachedPeers = MySqlCache::get('chart_cache', ['company_id' => $companyId, 'metric_group' => 'peers', 'days' => 0], (int)env('CACHE_TTL_PEERS', 3600));
            if ($cachedPeers) {
                $peers = json_decode($cachedPeers['raw_json'], true);
            } else {
                try {
                    $peers = $screenerApi->getPeers($screenerId);
                    if ($peers) MySqlCache::set('chart_cache', ['company_id' => $companyId, 'metric_group' => 'peers', 'days' => 0, 'raw_json' => json_encode($peers)]);
                } catch (Throwable $e) { logError('step2:peers', $e); }
            }

            echo json_encode([
                'status'    => 'ok',
                'charts'    => $charts,
                'schedules' => $schedules,
                'peers'     => $peers,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        // ── Step 3: Fetch news ────────────────────────────────────────────────
        case 3: {
            $companyId   = (int)($_GET['company_id'] ?? 0);
            $companyName = trim($_GET['company_name'] ?? $symbol);
            if (!$companyId) { echo json_encode(['error' => 'company_id required']); exit; }

            $news = [];
            // Check cache first (TTL 30min)
            try {
                $db   = Database::get();
                $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age FROM news_cache WHERE company_id=? ORDER BY published_at DESC LIMIT 1");
                $stmt->execute([$companyId]);
                $check = $stmt->fetch();
                if ($check && $check['age'] < (int)env('CACHE_TTL_NEWS', 1800)) {
                    $stmt2 = $db->prepare("SELECT * FROM news_cache WHERE company_id=? ORDER BY published_at DESC LIMIT ?");
                    $stmt2->execute([$companyId, NEWS_MAX]);
                    $news = $stmt2->fetchAll();
                }
            } catch (Throwable) {}

            if (empty($news) || $forceRefresh) {
                try {
                    $newsApi = new NewsApi();
                    $items   = $newsApi->getNews($companyName, $symbol);
                    $news    = $items;
                    // Save to cache
                    $db = Database::get();
                    $db->prepare("DELETE FROM news_cache WHERE company_id=?")->execute([$companyId]);
                    $stmt = $db->prepare("INSERT INTO news_cache (company_id,title,source,link,published_at) VALUES (?,?,?,?,?)");
                    foreach ($items as $item) {
                        $stmt->execute([$companyId, $item['title'], $item['source'] ?? '', $item['link'] ?? '', $item['published_at'] ?? null]);
                    }
                } catch (Throwable $e) { logError('step3:news', $e); }
            }

            echo json_encode(['status' => 'ok', 'news' => $news], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        // ── Step 4: Run AI analysis ───────────────────────────────────────────
        case 4: {
            $companyId   = (int)($_GET['company_id'] ?? 0);
            if (!$companyId) { echo json_encode(['error' => 'company_id required']); exit; }

            // Read all data passed as POST JSON body
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body) { echo json_encode(['error' => 'Request body required']); exit; }

            $metrics  = $body['metrics']  ?? [];
            $priceRow = $body['price_row'] ?? [];
            $peersText = $body['peers_text'] ?? 'Not available';
            $newsText  = $body['news_text']  ?? 'Not available';
            $companyName = $body['company_name'] ?? $symbol;
            $sector      = $body['sector'] ?? '';

            $na = fn($v) => ($v !== null && $v !== '') ? $v : 'N/A';
            $promptData = [
                'company_name'      => $companyName,
                'symbol'            => $symbol,
                'sector'            => $sector,
                'indices'           => $na($body['indices'] ?? null),
                'price'             => $na($priceRow['last_price'] ?? null),
                'prev_close'        => $na($priceRow['prev_close'] ?? null),
                'change_pct'        => $na($priceRow['change_pct'] ?? null),
                'vwap'              => $na($priceRow['vwap'] ?? null),
                'week_high'         => $na($priceRow['week_high'] ?? null),
                'week_high_date'    => $na($priceRow['week_high_date'] ?? null),
                'week_low'          => $na($priceRow['week_low'] ?? null),
                'week_low_date'     => $na($priceRow['week_low_date'] ?? null),
                'upper_circuit'     => $na($priceRow['upper_circuit'] ?? null),
                'lower_circuit'     => $na($priceRow['lower_circuit'] ?? null),
                'dma50'             => $na($metrics['dma_50'] ?? null),
                'dma200'            => $na($metrics['dma_200'] ?? null),
                'vs_dma50'          => $na($metrics['vs_dma50'] ?? null),
                'vs_dma200'         => $na($metrics['vs_dma200'] ?? null),
                'rsi_14'            => $na($metrics['rsi_14'] ?? null),
                'market_cap_cr'     => $na($metrics['market_cap_cr'] ?? $priceRow['market_cap_cr'] ?? null),
                'pe'                => $na($priceRow['pe_ratio'] ?? null),
                'median_pe'         => $na($metrics['median_pe'] ?? null),
                'pe_vs_median'      => $na($metrics['pe_vs_median'] ?? null),
                'sector_pe'         => $na($priceRow['sector_pe'] ?? null),
                'ev_ebitda'         => $na($metrics['ev_ebitda'] ?? null),
                'median_ev'         => $na($metrics['median_ev'] ?? null),
                'pbv'               => $na($metrics['pbv'] ?? null),
                'median_pbv'        => $na($metrics['median_pbv'] ?? null),
                'mcap_sales'        => $na($metrics['mcap_sales'] ?? null),
                'median_mcap_sales' => $na($metrics['median_mcap_sales'] ?? null),
                'div_yield'         => $na($metrics['div_yield'] ?? null),
                'revenue_latest'    => $na($metrics['revenue_latest'] ?? null),
                'revenue_cagr'      => $na($metrics['revenue_cagr'] ?? null),
                'profit_latest'     => $na($metrics['profit_latest'] ?? null),
                'profit_cagr'       => $na($metrics['profit_cagr'] ?? null),
                'sales_yoy'         => $na($metrics['sales_yoy'] ?? null),
                'gpm'               => $na($metrics['gpm'] ?? null),
                'opm'               => $na($metrics['opm'] ?? null),
                'npm'               => $na($metrics['npm'] ?? null),
                'margin_trend'      => $na($metrics['margin_trend'] ?? null),
                'material_cost'     => $na($metrics['material_cost'] ?? null),
                'borrowings'        => $na($metrics['debt_latest'] ?? null),
                'debt_trend'        => $na($metrics['debt_trend'] ?? null),
                'face_value'        => $na($priceRow['face_value'] ?? null),
                'book_value'        => 'N/A',
                'op_cf'             => $na($metrics['op_cf_latest'] ?? null),
                'inv_cf'            => $na($metrics['inv_cf_latest'] ?? null),
                'fcf'               => $na($metrics['free_cash_flow'] ?? null),
                'fcf_to_pat'        => $na($metrics['fcf_to_pat'] ?? null),
                'peers_text'        => $peersText,
                'news_text'         => $newsText,
                'support_resistance'=> $metrics['support_resistance'] ?? [],
            ];

            Database::reset();
            $ai       = new OpenRouterClient();
            $aiResult = $ai->analyse(PromptBuilder::getSystemPrompt(), PromptBuilder::build($promptData), $model ?: null);
            error_log("[steps] AI done for $symbol model=" . $aiResult['model_used']);

            echo json_encode([
                'status'   => 'ok',
                'sections' => $aiResult['sections'],
                'model'    => $aiResult['model_used'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        // ── Step 5: Save to DB + return final result ──────────────────────────
        case 5: {
            $companyId = (int)($_GET['company_id'] ?? 0);
            if (!$companyId) { echo json_encode(['error' => 'company_id required']); exit; }

            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body) { echo json_encode(['error' => 'Request body required']); exit; }

            $aiResult    = $body['ai_result']   ?? [];
            $metrics     = $body['metrics']     ?? [];
            $priceRow    = $body['price_row']   ?? [];
            $charts      = $body['charts']      ?? null;
            $news        = $body['news']        ?? null;
            $peers       = $body['peers']       ?? null;
            $companyName = $body['company_name'] ?? '';
            $sector      = $body['sector']      ?? '';

            Database::reset();
            $company = Database::get()->prepare('SELECT * FROM companies WHERE id=?');
            $company->execute([$companyId]);
            $company = $company->fetch();

            // Build fake aiResult structure for Analysis::save()
            $saveResult = [
                'sections'      => $aiResult['sections'] ?? [],
                'model_used'    => $aiResult['model']    ?? '',
                'prompt_tokens' => 0,
                'output_tokens' => 0,
            ];

            $analysisId     = Analysis::save($companyId, $saveResult, $metrics, (float)($priceRow['last_price'] ?? 0), null);
            $savedRow       = Analysis::getLatest($companyId, 999999);
            $verdictHistory = Analysis::getVerdictHistory($companyId, 6);

            $sections     = $aiResult['sections'] ?? [];
            $liveSections = [
                'price_and_technical_setup' => $sections['price_and_technical_setup'] ?? $sections['price_technical_setup'] ?? $savedRow['section_technical'] ?? null,
                'support_and_resistance'    => $sections['support_and_resistance']    ?? null,
                'valuation'                 => $sections['valuation']                 ?? $savedRow['section_valuation']   ?? null,
                'business_quality'          => $sections['business_quality']          ?? $savedRow['section_quality']     ?? null,
                'balance_sheet_health'      => $sections['balance_sheet_health']      ?? $sections['balance_sheet']       ?? $savedRow['section_balance']   ?? null,
                'cash_flow_quality'         => $sections['cash_flow_quality']         ?? $sections['cash_flow']           ?? $savedRow['section_cashflow']  ?? null,
                'catalysts_and_risks'       => $sections['catalysts_and_risks']       ?? $sections['key_catalysts_and_risks'] ?? $savedRow['section_catalysts'] ?? null,
                'trade_setup'               => $sections['trade_setup']               ?? $savedRow['section_trade_setup'] ?? null,
                'scenario_analysis'         => $sections['scenario_analysis']         ?? $sections['scenarios']           ?? $savedRow['section_scenarios'] ?? null,
                'conviction_matrix'         => $sections['conviction_matrix']         ?? null,
                'verdict'                   => $sections['verdict']                   ?? $savedRow['section_verdict']     ?? null,
            ];

            echo json_encode([
                'status' => 'done',
                'data'   => [
                    'id'                => $analysisId,
                    'symbol'            => $company['nse_symbol'],
                    'company_name'      => $company['company_name'],
                    'sector'            => $company['sector'] ?? '',
                    'verdict'           => $sections['verdict_label']    ?? $savedRow['verdict']          ?? 'Hold',
                    'confidence'        => $sections['confidence_value'] ?? $savedRow['confidence_score'] ?? 50,
                    'model'             => $aiResult['model'] ?? '',
                    'created_at'        => $savedRow['created_at'] ?? date('Y-m-d H:i:s'),
                    'price'             => $priceRow['last_price'] ?? null,
                    'sections'          => $liveSections,
                    'metrics_json'      => json_encode($metrics),
                    'price_data'        => $priceRow,
                    'chart_data'        => $charts,
                    'news'              => $news,
                    'peers'             => $peers,
                    'trade_setup'       => $sections['trade_setup_parsed']  ?? null,
                    'scenarios'         => $sections['scenarios_parsed']    ?? null,
                    'conviction'        => $sections['conviction_parsed']   ?? null,
                    'news_sentiment'    => null,
                    'verdict_history'   => $verdictHistory,
                    'support_resistance'=> $metrics['support_resistance'] ?? [],
                ],
                'models' => OpenRouterClient::SELECTABLE_MODELS,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            break;
        }

        default:
            echo json_encode(['error' => 'Invalid step. Use step=1..5']);
    }

} catch (Throwable $e) {
    logError("analyse_steps step=$step", $e);
    $msg = env('APP_DEBUG') === 'true' ? $e->getMessage() : 'Step failed. Please try again.';
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── Helper: save NSE price to cache ──────────────────────────────────────────
function savePriceCache(int $companyId, array $quote): void {
    $p = $quote['priceInfo']    ?? [];
    $s = $quote['securityInfo'] ?? [];
    $m = $quote['metadata']     ?? [];
    $issued = (float)($s['issuedSize'] ?? 0);
    $price  = (float)($p['lastPrice']  ?? 0);
    MySqlCache::set('price_cache', [
        'company_id'    => $companyId,
        'last_price'    => $p['lastPrice']              ?? null,
        'prev_close'    => $p['previousClose']          ?? null,
        'open_price'    => $p['open']                   ?? null,
        'day_high'      => $p['intraDayHighLow']['max'] ?? null,
        'day_low'       => $p['intraDayHighLow']['min'] ?? null,
        'vwap'          => $p['vwap']                   ?? null,
        'change_abs'    => $p['change']                 ?? null,
        'change_pct'    => $p['pChange']                ?? null,
        'week_high'     => $p['weekHighLow']['max']     ?? null,
        'week_high_date'=> $p['weekHighLow']['maxDate'] ?? null,
        'week_low'      => $p['weekHighLow']['min']     ?? null,
        'week_low_date' => $p['weekHighLow']['minDate'] ?? null,
        'upper_circuit' => $p['upperCP']                ?? null,
        'lower_circuit' => $p['lowerCP']                ?? null,
        'pe_ratio'      => $m['pdSymbolPe']             ?? null,
        'sector_pe'     => $m['pdSectorPe']             ?? null,
        'market_cap_cr' => ($issued > 0 && $price > 0) ? round($issued * $price / 1e7, 0) : null,
        'indices'       => json_encode($m['activeSeries'] ?? []),
    ]);
}
