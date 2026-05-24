<?php

/**
 * Yahoo Finance fallback for NSE price data.
 * Used when the NSE API is unavailable (e.g., blocked, market closed).
 * Returns data normalised to the same structure as NseApi::getQuote().
 */
class YahooFinanceApi {

    public function getQuote(string $nseSymbol): ?array {
        $yahooSymbol = strtoupper($nseSymbol) . '.NS';
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
             . urlencode($yahooSymbol)
             . '?interval=1d&range=1d';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$body) return null;

        $json = json_decode($body, true);
        $meta = $json['chart']['result'][0]['meta'] ?? null;
        if (!$meta) return null;

        $price     = (float)($meta['regularMarketPrice']   ?? 0);
        $prevClose = (float)($meta['chartPreviousClose']   ?? 0);
        $pChange   = ($prevClose > 0) ? round(($price - $prevClose) / $prevClose * 100, 2) : null;
        $shares    = (int)($meta['sharesOutstanding'] ?? 0);

        return [
            '_source'      => 'yahoo',          // flag so UI can show fallback notice
            'priceInfo'    => [
                'lastPrice'     => $price ?: null,
                'previousClose' => $prevClose ?: null,
                'pChange'       => $pChange,
                'vwap'          => null,
                'weekHighLow'   => [
                    'max'     => $meta['fiftyTwoWeekHigh'] ?? null,
                    'maxDate' => null,
                    'min'     => $meta['fiftyTwoWeekLow']  ?? null,
                    'minDate' => null,
                ],
                'upperCP'       => null,
                'lowerCP'       => null,
                'open'          => $meta['regularMarketOpen'] ?? null,
                'intraDayHighLow' => [
                    'max' => $meta['regularMarketDayHigh'] ?? null,
                    'min' => $meta['regularMarketDayLow']  ?? null,
                ],
            ],
            'metadata'     => [
                'pdSymbolPe'   => null,
                'pdSectorPe'   => null,
                'activeSeries' => [],
                'companyName'  => $meta['longName'] ?? $nseSymbol,
            ],
            'securityInfo' => [
                'faceValue'  => null,
                'issuedSize' => $shares,
            ],
        ];
    }
}
