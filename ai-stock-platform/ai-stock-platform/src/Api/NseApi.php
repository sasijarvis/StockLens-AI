<?php

class NseApi {
    private string $cookieFile;

    public function __construct() {
        $this->cookieFile = env('NSE_COOKIE_FILE', '/tmp/nse_cookies.txt');
    }

    /**
     * Initialise NSE session cookie. Reuses if < 1 hour old.
     */
    public function initSession(): void {
        if (file_exists($this->cookieFile) && filemtime($this->cookieFile) > time() - 3600) {
            return;
        }

        $ch = curl_init('https://www.nseindia.com');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Second hit to the charts page to get full cookies
        sleep(1);
        $ch2 = curl_init('https://www.nseindia.com/market-data/live-equity-market');
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }

    private function apiGet(string $url): ?array {
        $this->initSession();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Referer: https://www.nseindia.com',
                'X-Requested-With: XMLHttpRequest',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 401 || $code === 403) {
            // Session expired — force re-init once
            @unlink($this->cookieFile);
            $this->initSession();
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEFILE     => $this->cookieFile,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Referer: https://www.nseindia.com',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                ],
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($code !== 200 || !$body) return null;
        return json_decode($body, true);
    }

    public function getQuote(string $symbol): ?array {
        return $this->apiGet(NSE_BASE . '/quote-equity?symbol=' . urlencode($symbol));
    }

    public function getTradeInfo(string $symbol): ?array {
        return $this->apiGet(NSE_BASE . '/quote-equity?symbol=' . urlencode($symbol) . '&section=trade_info');
    }

    public function getSectorPeers(string $index): ?array {
        return $this->apiGet(NSE_BASE . '/equity-stockIndices?index=' . urlencode($index));
    }

    public function getDerivatives(string $symbol): ?array {
        return $this->apiGet(NSE_BASE . '/quote-derivative?symbol=' . urlencode($symbol));
    }
}
