<?php

class ScreenerApi {

    private function fetch(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$body) return null;
        return json_decode($body, true);
    }

    public function searchCompany(string $query): ?array {
        return $this->fetch('https://www.screener.in/api/company/search/?q=' . urlencode($query) . '&v=3&fts=1');
    }

    public function getPeers(int $id): ?array {
        return $this->fetch(sprintf('%s/%d/peers/', SCREENER_BASE, $id));
    }

    public function getChart(int $id, string $metrics, int $days = 1825): ?array {
        return $this->fetch(sprintf('%s/%d/chart/?q=%s&days=%d', SCREENER_BASE, $id, urlencode($metrics), $days));
    }

    public function getSchedule(int $id, string $parent, string $section): ?array {
        return $this->fetch(sprintf('%s/%d/schedules/?parent=%s&section=%s&consolidated=', SCREENER_BASE, $id, urlencode($parent), $section));
    }

    public function getAllCharts(int $id): array {
        $charts = [
            'price'    => 'Price-DMA50-DMA200-Volume',
            'pe'       => 'Price+to+Earning-Median+PE-EPS',
            'margins'  => 'GPM-OPM-NPM-Quarter+Sales',
            'ev'       => 'EV+Multiple-Median+EV+Multiple-EBITDA',
            'mcap'     => 'Market+Cap+to+Sales-Median+Market+Cap+to+Sales-Sales',
            'pbv'      => 'Price+to+book+value-Median+PBV-Book+value',
            'dividend' => 'Dividend+Yield',
        ];
        $requests = [];
        foreach ($charts as $key => $q) {
            $requests[$key] = ['url' => sprintf('%s/%d/chart/?q=%s&days=1825', SCREENER_BASE, $id, $q)];
        }
        return ParallelFetcher::fetch($requests);
    }

    public function getAllSchedules(int $id): array {
        $schedules = [
            'pl_sales'    => ['parent' => 'Sales',                        'section' => 'profit-loss'],
            'pl_expenses' => ['parent' => 'Expenses',                     'section' => 'profit-loss'],
            'pl_material' => ['parent' => 'Material Cost %',              'section' => 'profit-loss'],
            'pl_other'    => ['parent' => 'Other Income',                 'section' => 'profit-loss'],
            'pl_profit'   => ['parent' => 'Net Profit',                   'section' => 'profit-loss'],
            'cf_ops'      => ['parent' => 'Cash from Operating Activity', 'section' => 'cash-flow'],
            'cf_inv'      => ['parent' => 'Cash from Investing Activity', 'section' => 'cash-flow'],
            'cf_fin'      => ['parent' => 'Cash from Financing Activity', 'section' => 'cash-flow'],
            'bs_borrow'   => ['parent' => 'Borrowings',                   'section' => 'balance-sheet'],
            'bs_liab'     => ['parent' => 'Other Liabilities',            'section' => 'balance-sheet'],
            'bs_fixed'    => ['parent' => 'Fixed Assets',                 'section' => 'balance-sheet'],
            'bs_assets'   => ['parent' => 'Other Assets',                 'section' => 'balance-sheet'],
        ];
        $requests = [];
        foreach ($schedules as $key => $s) {
            $requests[$key] = ['url' => sprintf(
                '%s/%d/schedules/?parent=%s&section=%s&consolidated=',
                SCREENER_BASE, $id, urlencode($s['parent']), $s['section']
            )];
        }
        return ParallelFetcher::fetch($requests);
    }
}