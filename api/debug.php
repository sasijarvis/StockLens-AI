<?php
// api/debug.php — diagnostic endpoint. REMOVE IN PRODUCTION.
// Usage: /api/debug?action=check_db
//        /api/debug?action=test_ai
//        /api/debug?action=raw_analysis&symbol=WIPRO

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/helpers.php';

spl_autoload_register(function(string $class): void {
    foreach (['/src/Ai/','/src/Data/','/src/Api/','/src/Cache/','/src/Models/','/src/'] as $dir) {
        $f = dirname(__DIR__) . $dir . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

header('Content-Type: application/json');
$action = $_GET['action'] ?? 'check_db';
$symbol = strtoupper(trim($_GET['symbol'] ?? 'WIPRO'));

// ── 1. Check DB ──────────────────────────────────────────────────────
if ($action === 'check_db') {
    $db   = Database::get();
    $stmt = $db->prepare("SELECT * FROM analysis_history ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo json_encode([
        'action' => 'check_db',
        'rows'   => array_map(fn($r) => [
            'id'                => $r['id'],
            'verdict'           => $r['verdict'],
            'confidence_score'  => $r['confidence_score'],
            'model_used'        => $r['model_used'],
            'section_technical' => $r['section_technical'] ? substr($r['section_technical'], 0, 80).'...' : 'NULL',
            'section_valuation' => $r['section_valuation'] ? substr($r['section_valuation'], 0, 80).'...' : 'NULL',
            'section_verdict'   => $r['section_verdict']   ? substr($r['section_verdict'],   0, 80).'...' : 'NULL',
            'created_at'        => $r['created_at'],
        ], $rows),
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── 2. Test AI with realistic stock prompt ───────────────────────────
if ($action === 'test_ai') {
    $client = new OpenRouterClient();
    $system = PromptBuilder::getSystemPrompt();
    // Use a real-looking prompt so the model knows what to do
    $user = <<<PROMPT
Analyse Wipro Ltd (NSE: WIPRO) — Sector: IT

--- PRICE & TECHNICAL ---
Price: Rs 197.70 | Prev Close: Rs 194.91
Change today: +1.43% | VWAP: Rs 197.65
52-week High: Rs 273.10 (22-Dec-2025) | 52-week Low: Rs 186.50 (30-Mar-2026)
50-DMA: Rs 241.75 | 200-DMA: Rs 232.71
RSI-14: 32.3 [Oversold]

--- VALUATION ---
Market Cap: Rs 207356 Cr
P/E: 15.39x | Median P/E (5yr): 17.2x | P/E vs Median: -10.5% [near median]
Sector P/E: 15.39x

--- BUSINESS QUALITY ---
Revenue (latest year): Rs 89000 Cr | 5-yr CAGR: 8.2%
Operating Margin: 18.86% | Net Margin: 15.44%

--- BALANCE SHEET ---
Total Borrowings: Rs 2200 Cr (trend: stable)

--- CASH FLOW ---
Free Cash Flow: Rs 8500 Cr | FCF-to-PAT ratio: 0.85x

--- SECTOR PEERS ---
TCS, Infosys, HCL Tech

--- RECENT NEWS ---
1. Wipro AI services raise targets
2. Wipro Q3 results beat estimates

Now write the 8-section analysis.
PROMPT;

    try {
        $result = $client->analyse($system, $user);
        echo json_encode([
            'model'        => $result['model_used'],
            'parsed_keys'  => array_keys($result['sections']),
            'sections'     => $result['sections'],
            'raw_text'     => $result['raw'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['usage' => 'Use ?action=check_db or ?action=test_ai or ?action=raw_analysis&symbol=WIPRO']);