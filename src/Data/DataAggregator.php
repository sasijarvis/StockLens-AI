<?php

class DataAggregator {

    private NseApi     $nse;
    private ScreenerApi $screener;
    private NewsApi     $news;

    public function __construct() {
        $this->nse      = new NseApi();
        $this->screener = new ScreenerApi();
        $this->news     = new NewsApi();
    }

    /**
     * Fetch all data for a stock.
     *
     * $forceRefresh  — skips AI analysis cache (handled by caller) AND refreshes
     *                  live price + news.  Charts / schedules / peers are NOT
     *                  force-refreshed because they are expensive (20+ HTTP calls)
     *                  and change at most daily.  Their own TTLs still apply.
     */
    public function fetch(string $symbol, int $screenerId, bool $forceRefresh = false): array {
        $db      = Database::get();
        $company = $this->resolveCompany($symbol, $screenerId);

        $data = [
            'company'        => $company,
            'nse_quote'      => null,
            'charts'         => [],
            'schedules'      => [],
            'news'           => [],
            'screener_peers' => null,
        ];

        // --- NSE quote (price cache, TTL 5 min) ---
        // Always force-refresh price — it changes every second during market hours
        $priceRow = $forceRefresh ? null : MySqlCache::get('price_cache', ['company_id' => $company['id']], (int)env('CACHE_TTL_PRICE', 300));
        if ($priceRow) {
            $data['nse_quote'] = ['_cached' => true, '_row' => $priceRow];
        } else {
            try {
                $quote = $this->nse->getQuote($symbol);

                // --- Yahoo Finance fallback when NSE is unavailable ---
                if (!$quote) {
                    logError('DataAggregator::nse_quote', new RuntimeException("NSE returned null for $symbol — trying Yahoo Finance fallback"));
                    $yahoo = new YahooFinanceApi();
                    $quote = $yahoo->getQuote($symbol);
                    if ($quote) {
                        $data['_price_source'] = 'yahoo'; // flag for UI
                    }
                }

                if ($quote) {
                    $data['nse_quote'] = $quote;
                    $this->savePriceCache($company['id'], $quote);
                }
            } catch (Throwable $e) {
                logError('DataAggregator::nse_quote', $e);
            }
        }

        // --- Charts (TTL 6 hr) — never force-refresh; 20+ Screener API calls ---
        $chartKeys = ['price', 'pe', 'margins', 'ev', 'mcap', 'pbv', 'dividend'];
        $needCharts = [];
        foreach ($chartKeys as $key) {
            $cached = MySqlCache::get('chart_cache', ['company_id' => $company['id'], 'metric_group' => $key, 'days' => 1825], (int)env('CACHE_TTL_CHART', 21600));
            if ($cached) {
                $data['charts'][$key] = json_decode($cached['raw_json'], true);
            } else {
                $needCharts[] = $key;
            }
        }
        if (!empty($needCharts)) {
            try {
                $fetched = $this->screener->getAllCharts($screenerId);
                foreach ($fetched as $key => $chartData) {
                    if ($chartData) {
                        $data['charts'][$key] = $chartData;
                        MySqlCache::set('chart_cache', [
                            'company_id'   => $company['id'],
                            'metric_group' => $key,
                            'days'         => 1825,
                            'raw_json'     => json_encode($chartData),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                logError('DataAggregator::charts', $e);
            }
        }

        // --- Schedules (TTL 24 hr) — never force-refresh; 12 Screener API calls ---
        $scheduleKeys = ['pl_sales','pl_expenses','pl_material','pl_other','pl_profit','cf_ops','cf_inv','cf_fin','bs_borrow','bs_liab','bs_fixed','bs_assets'];
        $needSchedules = [];
        foreach ($scheduleKeys as $key) {
            [$section, $parentKey] = explode('_', $key, 2);
            $cached = $this->getScheduleCache($company['id'], $key);
            if ($cached) {
                $data['schedules'][$key] = $cached;
            } else {
                $needSchedules[] = $key;
            }
        }
        if (!empty($needSchedules)) {
            try {
                $fetched = $this->screener->getAllSchedules($screenerId);
                foreach ($fetched as $key => $schedData) {
                    if ($schedData) {
                        $data['schedules'][$key] = $schedData;
                        $this->saveScheduleCache($company['id'], $key, $schedData);
                    }
                }
            } catch (Throwable $e) {
                logError('DataAggregator::schedules', $e);
            }
        }

        // --- Screener peers (TTL 1 hr) — never force-refresh; uses own TTL ---
        $peersCacheKey = ['company_id' => $company['id'], 'metric_group' => 'peers', 'days' => 0];
        $cachedPeers   = MySqlCache::get('chart_cache', $peersCacheKey, (int)env('CACHE_TTL_PEERS', 3600));
        if ($cachedPeers) {
            $data['screener_peers'] = json_decode($cachedPeers['raw_json'], true);
        } else {
            try {
                $peers = $this->screener->getPeers($screenerId);
                if ($peers) {
                    $data['screener_peers'] = $peers;
                    MySqlCache::set('chart_cache', array_merge($peersCacheKey, [
                        'raw_json' => json_encode($peers),
                    ]));
                }
            } catch (Throwable $e) {
                logError('DataAggregator::screener_peers', $e);
            }
        }

        // --- News (TTL 30 min) — refresh on force since it's a single fast RSS call ---
        $newsRows = $forceRefresh ? [] : $this->getNewsCache($company['id']);
        if (!empty($newsRows)) {
            $data['news'] = $newsRows;
        } else {
            try {
                $newsItems = $this->news->getNews($company['company_name'], $symbol);
                $data['news'] = $newsItems;
                $this->saveNewsCache($company['id'], $newsItems);
            } catch (Throwable $e) {
                logError('DataAggregator::news', $e);
            }
        }

        return $data;
    }

    /**
     * Fetch only the cached news for a symbol (used by the async sentiment endpoint).
     * Falls back to a fresh fetch if cache is empty.
     */
    public function fetchNewsOnly(string $symbol): array {
        try {
            $company = Company::findBySymbol($symbol);
            if (!$company) return [];

            $cached = $this->getNewsCache((int)$company['id']);
            if (!empty($cached)) return $cached;

            // Cache miss — fetch fresh
            $items = $this->news->getNews($company['company_name'], $symbol);
            $this->saveNewsCache((int)$company['id'], $items);
            return $items;
        } catch (Throwable $e) {
            logError('DataAggregator::fetchNewsOnly', $e);
            return [];
        }
    }

    private function resolveCompany(string $symbol, int $screenerId): array {
        // Delegate to Company::findOrCreate so sector is always kept up to date.
        // searchCompany is only called here when DataAggregator is used without a
        // prior Screener search (e.g. direct watchlist refresh); in the main analysis
        // pipeline the caller already has the name + sector from analyse.php.
        $info   = $this->screener->searchCompany($symbol);
        $name   = $info[0]['name']   ?? $symbol;
        $sector = $info[0]['sector'] ?? '';
        return Company::findOrCreate($symbol, $screenerId, $name, $sector);
    }

    private function savePriceCache(int $companyId, array $quote): void {
        $p  = $quote['priceInfo'] ?? [];
        $s  = $quote['securityInfo'] ?? [];
        $m  = $quote['metadata'] ?? [];
        $issued = (float)($s['issuedSize'] ?? 0);
        $price  = (float)($p['lastPrice'] ?? 0);

        MySqlCache::set('price_cache', [
            'company_id'    => $companyId,
            'last_price'    => $p['lastPrice']   ?? null,
            'prev_close'    => $p['previousClose'] ?? null,
            'open_price'    => $p['open']         ?? null,
            'day_high'      => $p['intraDayHighLow']['max'] ?? null,
            'day_low'       => $p['intraDayHighLow']['min'] ?? null,
            'vwap'          => $p['vwap']         ?? null,
            'change_abs'    => $p['change']       ?? null,
            'change_pct'    => $p['pChange']      ?? null,
            'week_high'     => $p['weekHighLow']['max'] ?? null,
            'week_high_date'=> $p['weekHighLow']['maxDate'] ?? null,
            'week_low'      => $p['weekHighLow']['min'] ?? null,
            'week_low_date' => $p['weekHighLow']['minDate'] ?? null,
            'upper_circuit' => $p['upperCP']      ?? null,
            'lower_circuit' => $p['lowerCP']      ?? null,
            'pe_ratio'      => $m['pdSymbolPe']   ?? null,
            'sector_pe'     => $m['pdSectorPe']   ?? null,
            'market_cap_cr' => ($issued > 0 && $price > 0) ? round($issued * $price / 1e7, 0) : null,
            'indices'       => json_encode($quote['metadata']['activeSeries'] ?? []),
        ]);
    }

    private function getScheduleCache(int $companyId, string $key): ?array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
                                  FROM schedule_cache
                                  WHERE company_id = ? AND CONCAT(LEFT(section,2),'_',REPLACE(parent,' ','_')) LIKE ?
                                  ORDER BY fetched_at DESC LIMIT 1");
            // Simpler: just get by company+section+parent name
            $map = $this->scheduleKeyMap();
            if (!isset($map[$key])) return null;
            [$section, $parent] = $map[$key];
            $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
                                  FROM schedule_cache WHERE company_id=? AND section=? AND parent=? LIMIT 1");
            $stmt->execute([$companyId, $section, $parent]);
            $row = $stmt->fetch();
            if (!$row || $row['age'] > (int)env('CACHE_TTL_SCHEDULE', 86400)) return null;
            return [
                'years'  => json_decode($row['years_json'], true),
                'values' => json_decode($row['values_json'], true),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function saveScheduleCache(int $companyId, string $key, array $data): void {
        $map = $this->scheduleKeyMap();
        if (!isset($map[$key])) return;
        [$section, $parent] = $map[$key];
        MySqlCache::set('schedule_cache', [
            'company_id'     => $companyId,
            'section'        => $section,
            'parent'         => $parent,
            'years_json'     => json_encode($data['years']  ?? []),
            'values_json'    => json_encode($data['values'] ?? []),
            'breakdown_json' => json_encode($data['breakdown'] ?? null),
        ]);
    }

    private function getNewsCache(int $companyId): array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
                                  FROM news_cache WHERE company_id=? ORDER BY published_at DESC LIMIT 1");
            $stmt->execute([$companyId]);
            $check = $stmt->fetch();
            if (!$check || $check['age'] > (int)env('CACHE_TTL_NEWS', 1800)) return [];
            $stmt2 = $db->prepare("SELECT * FROM news_cache WHERE company_id=? ORDER BY published_at DESC LIMIT ?");
            $stmt2->execute([$companyId, NEWS_MAX]);
            return $stmt2->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function saveNewsCache(int $companyId, array $items): void {
        try {
            $db = Database::get();
            $db->prepare("DELETE FROM news_cache WHERE company_id=?")->execute([$companyId]);
            $stmt = $db->prepare("INSERT INTO news_cache (company_id,title,source,link,published_at) VALUES (?,?,?,?,?)");
            foreach ($items as $item) {
                $stmt->execute([$companyId, $item['title'], $item['source'] ?? '', $item['link'] ?? '', $item['published_at'] ?? null]);
            }
        } catch (Throwable $e) {
            logError('saveNewsCache', $e);
        }
    }

    private function scheduleKeyMap(): array {
        return [
            'pl_sales'    => ['profit-loss',   'Sales'],
            'pl_expenses' => ['profit-loss',   'Expenses'],
            'pl_material' => ['profit-loss',   'Material Cost %'],
            'pl_other'    => ['profit-loss',   'Other Income'],
            'pl_profit'   => ['profit-loss',   'Net Profit'],
            'cf_ops'      => ['cash-flow',     'Cash from Operating Activity'],
            'cf_inv'      => ['cash-flow',     'Cash from Investing Activity'],
            'cf_fin'      => ['cash-flow',     'Cash from Financing Activity'],
            'bs_borrow'   => ['balance-sheet', 'Borrowings'],
            'bs_liab'     => ['balance-sheet', 'Other Liabilities'],
            'bs_fixed'    => ['balance-sheet', 'Fixed Assets'],
            'bs_assets'   => ['balance-sheet', 'Other Assets'],
        ];
    }
}
