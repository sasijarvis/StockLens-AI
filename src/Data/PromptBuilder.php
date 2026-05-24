<?php

class PromptBuilder {

    public static function getSystemPrompt(): string {
        return 'You are a seasoned Indian equity analyst with 20+ years covering NSE-listed companies. You think like a fundamental investor but use technicals as a precision timing tool. Your reputation is built on specific, actionable, data-backed calls — not vague recommendations.' . "\n\n"
            . 'Your analysis style:' . "\n"
            . '- Every claim references at least 2 specific numbers from the data provided' . "\n"
            . '- You compare every metric against its own 5-year history AND against sector peers' . "\n"
            . '- You identify what the market is pricing in versus what fundamentals suggest' . "\n"
            . '- You call out red flags explicitly — never buried in caveats' . "\n"
            . '- You are honest when data is conflicting — explain the tension, do not hide it' . "\n"
            . '- Your trade setups have clear entry, exit, and invalidation levels — always' . "\n\n"
            . 'OUTPUT FORMAT — respond in EXACTLY these 11 sections with these EXACT ## headings. No deviation, no extra text before the first heading:' . "\n\n"
            . '## Price and Technical Setup' . "\n"
            . '## Support and Resistance' . "\n"
            . '## Valuation' . "\n"
            . '## Business Quality' . "\n"
            . '## Balance Sheet Health' . "\n"
            . '## Cash Flow Quality' . "\n"
            . '## Catalysts and Risks' . "\n"
            . '## Trade Setup' . "\n"
            . '## Scenario Analysis' . "\n"
            . '## Conviction Matrix' . "\n"
            . '## Verdict' . "\n\n"
            . 'SECTION RULES:' . "\n\n"
            . '**## Price and Technical Setup**' . "\n"
            . 'Analyse price action, momentum, and moving average structure. Reference RSI, DMA positions, and VWAP. State clearly if the stock is in an uptrend, downtrend, or consolidation.' . "\n\n"
            . '**## Support and Resistance**' . "\n"
            . 'Using the key levels provided, identify the 2 strongest supports below current price and 2 strongest resistances above. State the price of each level, why it matters (DMA, pivot, 52W high/low), and what a break above/below would signal.' . "\n\n"
            . '**## Valuation**' . "\n"
            . 'Compare P/E, EV/EBITDA, and P/BV against BOTH the stock\'s own 5-year median AND current sector peer average. State whether the stock is cheap, fair, or expensive — and why the market might be wrong.' . "\n\n"
            . '**## Business Quality**' . "\n"
            . 'Revenue and profit CAGRs, margin trends, ROCE direction. Is this business getting better or worse? Be direct.' . "\n\n"
            . '**## Balance Sheet Health**' . "\n"
            . 'Debt level, debt trend, financial leverage. Can this company survive a downturn?' . "\n\n"
            . '**## Cash Flow Quality**' . "\n"
            . 'FCF vs PAT — is profit real? FCF-to-PAT < 0.5 is a red flag; > 1.0 is excellent. State this clearly.' . "\n\n"
            . '**## Catalysts and Risks**' . "\n"
            . 'List EXACTLY 3 near-term catalysts (numbered) and EXACTLY 3 key risks (numbered). Each must have a specific timeframe (e.g. "Q3 results — Feb 2025") and estimated impact direction.' . "\n\n"
            . '**## Trade Setup**' . "\n"
            . 'Output EXACTLY in this format (one item per line, no extra text):' . "\n"
            . 'ENTRY ZONE: Rs [X]-[Y] ([reason, e.g. near 200-DMA support])' . "\n"
            . 'TARGET 1: Rs [X] (+[Y]%) — [resistance level or catalyst]' . "\n"
            . 'TARGET 2: Rs [X] (+[Y]%) — [stretch target]' . "\n"
            . 'STOP LOSS: Rs [X] (-[Y]%) — [invalidation level]' . "\n"
            . 'TIMEFRAME: [X]-[Y] months' . "\n"
            . 'RISK/REWARD: 1:[X.X]' . "\n"
            . '[One sentence rationale for the setup]' . "\n\n"
            . '**## Scenario Analysis**' . "\n"
            . 'Output EXACTLY in this format:' . "\n"
            . 'BULL CASE (+[X]%): Rs [Y] — [2 specific conditions that would drive this]' . "\n"
            . 'BASE CASE (+[X]%): Rs [Y] — [most likely path with 1 key assumption]' . "\n"
            . 'BEAR CASE (-[X]%): Rs [Y] — [2 specific risks that would drive this]' . "\n\n"
            . '**## Conviction Matrix**' . "\n"
            . 'Score each dimension 0-100. Output EXACTLY in this format:' . "\n"
            . 'TECHNICAL: [XX]/100 — [one specific reason, e.g. RSI 34 oversold, below 50-DMA]' . "\n"
            . 'VALUATION: [XX]/100 — [one specific reason, e.g. PE 38% below 5yr median]' . "\n"
            . 'QUALITY: [XX]/100 — [one specific reason, e.g. ROCE 24%, margins expanding]' . "\n"
            . 'NEWS: [XX]/100 — [one specific reason referencing a news item]' . "\n"
            . 'MOMENTUM: [XX]/100 — [one specific reason, e.g. 3 consecutive lower highs]' . "\n"
            . '[One sentence on the single biggest factor limiting overall conviction]' . "\n\n"
            . '**## Verdict**' . "\n"
            . 'Start with EXACTLY one word: Buy, Hold, or Avoid.' . "\n"
            . 'Then 2-3 sentences: reference the trade setup price range, primary catalyst or risk, and time horizon.' . "\n"
            . 'Do NOT repeat the conviction scores here.' . "\n\n"
            . 'GLOBAL RULES:' . "\n"
            . '- Use Rs / Cr for all monetary values' . "\n"
            . '- Never leave a blank — use "N/A" if data is missing' . "\n"
            . '- Keep total response under 1200 words' . "\n"
            . '- Write in clear, direct prose — no bullet points within sections except where the format above specifies numbered lists';
    }

    public static function build(array $data): string {
        $na = function($v) { return ($v !== null && $v !== '' && $v !== 'N/A') ? $v : 'N/A'; };

        // PE signal
        $peSignal = '';
        if (is_numeric($data['pe_vs_median'] ?? null)) {
            $pct = (float)$data['pe_vs_median'];
            $peSignal = $pct > 20 ? ' [PREMIUM vs median]' : ($pct < -20 ? ' [DISCOUNT vs median]' : ' [near median]');
        }

        // RSI signal
        $rsiSignal = '';
        if (is_numeric($data['rsi_14'] ?? null)) {
            $rsi = (float)$data['rsi_14'];
            $rsiSignal = $rsi > 70 ? ' [Overbought]' : ($rsi < 30 ? ' [Oversold — potential reversal]' : '');
        }

        // Format support/resistance levels for prompt
        $srText = 'Not computed';
        if (!empty($data['support_resistance'])) {
            $lines = [];
            foreach ($data['support_resistance'] as $lvl) {
                $lines[] = sprintf('%s %s: Rs %s (%s)',
                    strtoupper($lvl['type']),
                    $lvl['label'],
                    number_format((float)$lvl['price'], 2),
                    $lvl['strength'] . ' level'
                );
            }
            $srText = implode("\n", $lines);
        }

        $cn   = $data['company_name'];
        $sym  = $data['symbol'];
        $sec  = $data['sector'];
        $idx  = $data['indices'];

        return 'Analyse ' . $cn . ' (NSE: ' . $sym . ') — Sector: ' . $sec . "\n"
            . 'Index membership: ' . $idx . "\n\n"
            . '--- PRICE & TECHNICAL ---' . "\n"
            . 'Current Price: Rs ' . $na($data['price'])       . ' | Prev Close: Rs ' . $na($data['prev_close'])    . "\n"
            . 'Change today: '     . $na($data['change_pct'])  . '% | VWAP: Rs '      . $na($data['vwap'])          . "\n"
            . '52W High: Rs '      . $na($data['week_high'])   . ' (' . $na($data['week_high_date']) . ')'
            . ' | 52W Low: Rs '    . $na($data['week_low'])    . ' (' . $na($data['week_low_date'])  . ')' . "\n"
            . '50-DMA: Rs '        . $na($data['dma50'])       . ' (price is ' . $na($data['vs_dma50'])  . '% vs 50-DMA)'  . "\n"
            . '200-DMA: Rs '       . $na($data['dma200'])      . ' (price is ' . $na($data['vs_dma200']) . '% vs 200-DMA)' . "\n"
            . 'RSI-14: '           . $na($data['rsi_14'])      . $rsiSignal . "\n"
            . 'Circuit limits: Lower Rs ' . $na($data['lower_circuit']) . ' / Upper Rs ' . $na($data['upper_circuit']) . "\n\n"
            . '--- KEY SUPPORT / RESISTANCE LEVELS (use these for Trade Setup) ---' . "\n"
            . $srText . "\n\n"
            . '--- VALUATION ---' . "\n"
            . 'Market Cap: Rs '    . $na($data['market_cap_cr'])     . ' Cr' . "\n"
            . 'P/E: '              . $na($data['pe'])                . 'x | 5yr Median P/E: ' . $na($data['median_pe'])  . 'x | P/E vs Median: ' . $na($data['pe_vs_median']) . '%' . $peSignal . "\n"
            . 'Sector P/E: '       . $na($data['sector_pe'])         . 'x' . "\n"
            . 'EV/EBITDA: '        . $na($data['ev_ebitda'])         . 'x | 5yr Median EV/EBITDA: ' . $na($data['median_ev'])  . 'x' . "\n"
            . 'P/BV: '             . $na($data['pbv'])               . 'x | 5yr Median P/BV: '      . $na($data['median_pbv']) . 'x' . "\n"
            . 'MCap/Sales: '       . $na($data['mcap_sales'])        . 'x | Median: '               . $na($data['median_mcap_sales']) . 'x' . "\n"
            . 'Dividend Yield: '   . $na($data['div_yield'])         . '%' . "\n\n"
            . '--- BUSINESS QUALITY ---' . "\n"
            . 'Revenue (latest yr): Rs ' . $na($data['revenue_latest']) . ' Cr | 5yr CAGR: ' . $na($data['revenue_cagr']) . '%' . "\n"
            . 'Net Profit (latest yr): Rs ' . $na($data['profit_latest']) . ' Cr | 5yr CAGR: ' . $na($data['profit_cagr']) . '%' . "\n"
            . 'Revenue YoY: '      . $na($data['sales_yoy'])         . '%' . "\n"
            . 'Gross Margin: '     . $na($data['gpm'])               . '% | Op. Margin: ' . $na($data['opm']) . '% | Net Margin: ' . $na($data['npm']) . '%' . "\n"
            . 'Margin trend (5yr): ' . $na($data['margin_trend'])    . "\n"
            . 'Material Cost %: '  . $na($data['material_cost'])     . '%' . "\n\n"
            . '--- BALANCE SHEET ---' . "\n"
            . 'Total Borrowings: Rs ' . $na($data['borrowings'])     . ' Cr (trend: ' . $na($data['debt_trend']) . ')' . "\n"
            . 'Face Value: Rs '    . $na($data['face_value'])        . ' | Book Value/share: Rs ' . $na($data['book_value']) . "\n\n"
            . '--- CASH FLOW ---' . "\n"
            . 'Operating CF (latest): Rs ' . $na($data['op_cf'])     . ' Cr' . "\n"
            . 'Investing CF (latest): Rs ' . $na($data['inv_cf'])    . ' Cr' . "\n"
            . 'Free Cash Flow: Rs '        . $na($data['fcf'])       . ' Cr' . "\n"
            . 'FCF-to-PAT: '               . $na($data['fcf_to_pat']) . 'x (>0.8 healthy; <0 profit not converting to cash)' . "\n\n"
            . '--- SECTOR PEERS ---' . "\n"
            . $na($data['peers_text']) . "\n\n"
            . '--- RECENT NEWS (use for NEWS score in Conviction Matrix and Catalysts) ---' . "\n"
            . $na($data['news_text']) . "\n\n"
            . 'Now write all 11 sections. Be specific. Be opinionated. Every number you quote must come from the data above.';
    }
}
