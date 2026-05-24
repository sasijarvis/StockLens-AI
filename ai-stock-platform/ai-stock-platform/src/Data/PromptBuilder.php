<?php

class PromptBuilder {

    public static function getSystemPrompt(): string {
        return <<<SYSTEM
You are a seasoned Indian equity analyst with 20+ years covering NSE and BSE listed companies. You think like a fundamental investor but understand technicals as a risk tool.

Your analysis style:
- Data-driven and opinionated — you reference specific numbers, not vague trends
- You compare every metric against its own 5-year history AND against sector peers
- You identify what the market is pricing in vs what fundamentals suggest
- You call out red flags explicitly (not buried in caveats)
- You are honest when data is conflicting — you explain the tension instead of picking a side blindly

OUTPUT FORMAT — respond in EXACTLY these 8 sections with these EXACT ## headings. No deviation:

## Price and Technical Setup
## Valuation
## Business Quality
## Balance Sheet Health
## Cash Flow Quality
## Catalysts and Risks
## Confidence Score
## Verdict

RULES:
- Every section must reference at least 2 specific numbers from the data
- Compare PE, EV/EBITDA and PBV against both historical median AND current sector peer average
- In "Catalysts and Risks": list exactly 2-3 near-term catalysts and 2-3 key risks
- "Confidence Score": give a score 0-100 with one sentence explaining the main factor limiting your conviction (e.g. data gaps, sector headwinds, valuation uncertainty)
- "Verdict" section: start with exactly ONE word — Buy, Hold, or Avoid — followed by a 2-3 sentence rationale referencing price target or time horizon where possible
- Replace any missing data field with "N/A" — never leave blanks or PHP null
- Use INR (Rs / Cr) for all monetary values
- Keep total response under 1000 words
- Write in clear, direct prose — no bullet points within sections
SYSTEM;
    }

    public static function build(array $data): string {
        $na = fn($v) => ($v !== null && $v !== '' && $v !== 'N/A') ? $v : 'N/A';

        $peSignal = '';
        if ($data['pe_vs_median'] !== null && $data['pe_vs_median'] !== 'N/A') {
            $pct = (float)$data['pe_vs_median'];
            if ($pct > 20)       $peSignal = ' [PREMIUM to median]';
            elseif ($pct < -20)  $peSignal = ' [DISCOUNT to median]';
            else                 $peSignal = ' [near median]';
        }

        $rsiSignal = '';
        if ($data['rsi_14'] !== null && $data['rsi_14'] !== 'N/A') {
            $rsi = (float)$data['rsi_14'];
            if ($rsi > 70)      $rsiSignal = ' [Overbought]';
            elseif ($rsi < 30)  $rsiSignal = ' [Oversold]';
        }

        $newsText = $na($data['news_text']);
        $peersText = $na($data['peers_text']);

        return <<<PROMPT
Analyse {$data['company_name']} (NSE: {$data['symbol']}) — Sector: {$data['sector']}
Indices membership: {$data['indices']}

--- PRICE & TECHNICAL ---
Current Price: Rs {$data['price']} | Prev Close: Rs {$data['prev_close']}
Change today: {$data['change_pct']}% | VWAP: Rs {$data['vwap']}
52-week High: Rs {$data['week_high']} ({$data['week_high_date']}) | 52-week Low: Rs {$data['week_low']} ({$data['week_low_date']})
50-DMA: Rs {$data['dma50']} | 200-DMA: Rs {$data['dma200']}
Price vs 50-DMA: {$data['vs_dma50']}% | Price vs 200-DMA: {$data['vs_dma200']}%
RSI-14: {$data['rsi_14']}{$rsiSignal}
Circuit limits: Lower Rs {$data['lower_circuit']} / Upper Rs {$data['upper_circuit']}

--- VALUATION ---
Market Cap: Rs {$data['market_cap_cr']} Cr
P/E: {$data['pe']}x | Median P/E (5yr): {$data['median_pe']}x | P/E vs Median: {$data['pe_vs_median']}%{$peSignal}
Sector P/E: {$data['sector_pe']}x
EV/EBITDA: {$data['ev_ebitda']}x | Median EV/EBITDA: {$data['median_ev']}x
P/BV: {$data['pbv']}x | Median P/BV: {$data['median_pbv']}x
MCap/Sales: {$data['mcap_sales']}x | Median MCap/Sales: {$data['median_mcap_sales']}x
Dividend Yield: {$data['div_yield']}%

--- BUSINESS QUALITY ---
Revenue (latest year): Rs {$data['revenue_latest']} Cr | 5-yr CAGR: {$data['revenue_cagr']}%
Net Profit (latest year): Rs {$data['profit_latest']} Cr | 5-yr CAGR: {$data['profit_cagr']}%
Revenue YoY growth (last year): {$data['sales_yoy']}%
Gross Margin: {$data['gpm']}% | Operating Margin: {$data['opm']}% | Net Margin: {$data['npm']}%
Margin trend (5yr): {$data['margin_trend']}
Material Cost %: {$data['material_cost']}%

--- BALANCE SHEET ---
Total Borrowings: Rs {$data['borrowings']} Cr (trend: {$data['debt_trend']})
Face Value: Rs {$data['face_value']} | Book Value per share: Rs {$data['book_value']}

--- CASH FLOW ---
Operating Cash Flow (latest): Rs {$data['op_cf']} Cr
Investing Cash Flow (latest): Rs {$data['inv_cf']} Cr
Free Cash Flow: Rs {$data['fcf']} Cr
FCF-to-PAT ratio: {$data['fcf_to_pat']}x (>0.8 = healthy earnings quality; <0 = profit not converting to cash)

--- SECTOR PEERS (for context) ---
{$peersText}

--- RECENT NEWS (last 7 days) ---
{$newsText}

Now write the 8-section analysis. Be specific with numbers. Be opinionated. Tell me if this is worth owning.
PROMPT;
    }
}
