<?php

class ParallelFetcher {

    /**
     * Execute multiple HTTP requests in parallel using curl_multi_exec.
     * $requests = ['key' => ['url' => '...', 'headers' => [...], 'cookie_file' => '...']]
     */
    public static function fetch(array $requests, int $timeout = 15): array {
        if (empty($requests)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $req) {
            $ch = curl_init($req['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => $req['headers'] ?? [],
                CURLOPT_COOKIEFILE     => $req['cookie_file'] ?? '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $body           = curl_multi_getcontent($ch);
            $httpCode       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$key]  = ($httpCode === 200 && $body) ? json_decode($body, true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Fetch raw (non-JSON) responses, keyed the same way.
     */
    public static function fetchRaw(array $requests, int $timeout = 15): array {
        if (empty($requests)) return [];

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $req) {
            $ch = curl_init($req['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => $req['headers'] ?? [],
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $key => $ch) {
            $results[$key] = curl_multi_getcontent($ch) ?: null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }
}
