<?php

class NseApi {
    private string $cookieFile;
    private const MAX_RETRIES = 3;

    public function __construct() {
        // sys_get_temp_dir() returns the correct OS temp path (C:\Windows\Temp on Windows)
        $this->cookieFile = env('NSE_COOKIE_FILE', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nse_cookies.txt');
        // Ensure the directory is writable — silently ignore if already exists
        $dir = dirname($this->cookieFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
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
            CURLOPT_CONNECTTIMEOUT => 10,
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
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }

    /**
     * Execute a single CURL request to the NSE API and return [data, httpCode].
     */
    private function doRequest(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Referer: https://www.nseindia.com',
                'X-Requested-With: XMLHttpRequest',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            logError('NseApi::doRequest', new RuntimeException("cURL error for $url: $err"));
            return [null, 0];
        }

        if ($code !== 200 || !$body) return [null, $code];

        $data = json_decode($body, true);
        return [$data, $code];
    }

    /**
     * GET an NSE API URL with retry + session-refresh logic.
     * Retries on: 401/403 (session expired), 5xx (server error), network errors.
     */
    private function apiGet(string $url): ?array {
        $this->initSession();

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            [$data, $code] = $this->doRequest($url);

            if ($code === 200 && $data !== null) {
                return $data;
            }

            if ($code === 401 || $code === 403) {
                // Session expired — force full re-init
                @unlink($this->cookieFile);
                $this->initSession();
                // Don't sleep between auth retries
            } elseif ($code >= 500 || $code === 0) {
                // Server error or network failure — back off before retry
                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt); // 1s, then 2s
                }
            } else {
                // 404 or other client error — no point retrying
                break;
            }
        }

        return null;
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
