<?php

class MetricsComputer {

    public static function computeAll(array $raw): array {
        $schedules = $raw['schedules'] ?? [];
        $charts    = $raw['charts']    ?? [];
        $nse       = $raw['nse_quote'] ?? [];

        $last  = function($arr) { return !empty($arr) ? (float)end($arr) : 0; };
        $prev  = function($arr) { return count($arr) >= 2 ? (float)$arr[count($arr) - 2] : 0; };
        $first = function($arr) { return !empty($arr) ? (float)$arr[0] : 0; };

        $sales      = $schedules['pl_sales']['values']    ?? [];
        $profit     = $schedules['pl_profit']['values']   ?? [];
        $borrowings = $schedules['bs_borrow']['values']   ?? [];
        $op_cf      = $schedules['cf_ops']['values']      ?? [];
        $inv_cf     = $schedules['cf_inv']['values']      ?? [];
        $fin_cf     = $schedules['cf_fin']['values']      ?? [];
        $fixed      = $schedules['bs_fixed']['values']    ?? [];

        $sales_now   = $last($sales);
        $sales_old   = $first($sales);
        $profit_now  = $last($profit);
        $profit_old  = $first($profit);
        $n           = max(count($sales) - 1, 1);

        // Free cash flow
        $fcf_latest = $last($op_cf) + $last($inv_cf);

        // CAGR helper
        $cagr = function ($now, $old, $years) {
            if ($old <= 0 || $now <= 0 || $years <= 0) return null;
            return round((pow($now / $old, 1 / $years) - 1) * 100, 1);
        };

        // Market cap from NSE
        $issuedSize = (float)($nse['securityInfo']['issuedSize']    ?? 0);
        $lastPrice  = (float)($nse['priceInfo']['lastPrice']        ?? 0);
        $marketCap  = $issuedSize > 0 && $lastPrice > 0 ? round(($issuedSize * $lastPrice) / 1e7, 0) : null;

        // Technical indicators from chart data
        $rsi14    = self::computeRsi($charts['price'] ?? [], 14);
        $dma50    = self::computeDma($charts['price'] ?? [], 50);
        $dma200   = self::computeDma($charts['price'] ?? [], 200);

        // Margin trends
        $marginTrend = self::analyzeTrend($schedules['margins']['values'] ?? [], 5);

        // Debt trend
        $debtTrend = self::analyzeTrend($borrowings, 3, true); // inverted for debt

        // Latest PE from charts
        $peValues  = self::extractLastDatasetValue($charts['pe'] ?? [], 0);
        $medianPe  = self::extractLastDatasetValue($charts['pe'] ?? [], 1);
        $peVsMedian = ($peValues && $medianPe && $medianPe > 0)
            ? round((($peValues - $medianPe) / $medianPe) * 100, 1) : null;

        // EV/EBITDA
        $evEbitda = self::extractLastDatasetValue($charts['ev'] ?? [], 0);
        $medianEv = self::extractLastDatasetValue($charts['ev'] ?? [], 1);

        // PBV
        $pbv      = self::extractLastDatasetValue($charts['pbv'] ?? [], 0);
        $medianPbv = self::extractLastDatasetValue($charts['pbv'] ?? [], 1);

        // MCap/Sales
        $mcapSales      = self::extractLastDatasetValue($charts['mcap'] ?? [], 0);
        $medianMcapSales = self::extractLastDatasetValue($charts['mcap'] ?? [], 1);

        // Dividend yield
        $divYield = self::extractLastDatasetValue($charts['dividend'] ?? [], 0);

        // Margin latest values
        $gpm = self::extractLastDatasetValue($charts['margins'] ?? [], 0);
        $opm = self::extractLastDatasetValue($charts['margins'] ?? [], 1);
        $npm = self::extractLastDatasetValue($charts['margins'] ?? [], 2);

        // DMA comparison
        $vsDma50  = ($lastPrice > 0 && $dma50  > 0) ? round((($lastPrice - $dma50)  / $dma50)  * 100, 1) : null;
        $vsDma200 = ($lastPrice > 0 && $dma200 > 0) ? round((($lastPrice - $dma200) / $dma200) * 100, 1) : null;

        // Support / Resistance levels
        $srLevels = self::computeSupportResistance(
            $charts['price'] ?? [],
            $lastPrice,
            $dma50,
            $dma200,
            $nse['priceInfo']['weekHighLow']['max'] ?? null,
            $nse['priceInfo']['weekHighLow']['min'] ?? null
        );

        return [
            'support_resistance' => $srLevels,
            'revenue_cagr'     => $cagr($sales_now, $sales_old, $n),
            'profit_cagr'      => $cagr($profit_now, $profit_old, $n),
            'free_cash_flow'   => round($fcf_latest, 0),
            'fcf_to_pat'       => $profit_now != 0 ? round($fcf_latest / $profit_now, 2) : null,
            'debt_latest'      => $last($borrowings),
            'debt_trend'       => $debtTrend,
            'sales_yoy'        => $prev($sales) > 0
                ? round((($sales_now - $prev($sales)) / $prev($sales)) * 100, 1) : null,
            'market_cap_cr'    => $marketCap,
            'rsi_14'           => $rsi14,
            'dma_50'           => $dma50,
            'dma_200'          => $dma200,
            'vs_dma50'         => $vsDma50,
            'vs_dma200'        => $vsDma200,
            'margin_trend'     => $marginTrend,
            'gpm'              => $gpm,
            'opm'              => $opm,
            'npm'              => $npm,
            'pe_current'       => $peValues,
            'median_pe'        => $medianPe,
            'pe_vs_median'     => $peVsMedian,
            'ev_ebitda'        => $evEbitda,
            'median_ev'        => $medianEv,
            'pbv'              => $pbv,
            'median_pbv'       => $medianPbv,
            'mcap_sales'       => $mcapSales,
            'median_mcap_sales' => $medianMcapSales,
            'div_yield'        => $divYield,
            'revenue_latest'   => $sales_now,
            'profit_latest'    => $profit_now,
            'op_cf_latest'     => $last($op_cf),
            'inv_cf_latest'    => $last($inv_cf),
            'material_cost'    => self::extractLastDatasetValue($schedules['pl_material'] ?? [], 0),
        ];
    }

    private static function computeRsi(array $chartData, int $period): ?float {
        $prices = [];
        foreach ($chartData['datasets'][0]['values'] ?? [] as $point) {
            $prices[] = (float)($point[1] ?? $point);
        }
        if (count($prices) < $period + 1) return null;

        $gains  = [];
        $losses = [];
        for ($i = 1; $i < count($prices); $i++) {
            $diff     = $prices[$i] - $prices[$i - 1];
            $gains[]  = max($diff, 0);
            $losses[] = max(-$diff, 0);
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) return 100.0;
        $rs = $avgGain / $avgLoss;
        return round(100 - (100 / (1 + $rs)), 1);
    }

    private static function computeDma(array $chartData, int $period): ?float {
        $prices = [];
        foreach ($chartData['datasets'][0]['values'] ?? [] as $point) {
            $prices[] = (float)($point[1] ?? $point);
        }
        if (count($prices) < $period) return null;
        $slice = array_slice($prices, -$period);
        return round(array_sum($slice) / $period, 2);
    }

    private static function extractLastDatasetValue(array $chartData, int $datasetIndex): ?float {
        $values = $chartData['datasets'][$datasetIndex]['values'] ?? [];
        if (empty($values)) return null;
        $last = end($values);
        $val  = is_array($last) ? ($last[1] ?? null) : $last;
        return $val !== null ? (float)$val : null;
    }

    /**
     * Compute key support and resistance levels from chart + NSE data.
     * Returns sorted array of levels with type, price, and label.
     */
    private static function computeSupportResistance(
        array $priceChartData,
        float $currentPrice,
        ?float $dma50,
        ?float $dma200,
        mixed $week52High,
        mixed $week52Low
    ): array {
        $levels = [];

        // Extract all price points from chart
        $prices = [];
        foreach ($priceChartData['datasets'][0]['values'] ?? [] as $point) {
            $p = (float)(is_array($point) ? ($point[1] ?? 0) : $point);
            if ($p > 0) $prices[] = $p;
        }

        // 52-week high/low — most reliable hard levels
        if ($week52High) {
            $levels[] = ['price' => round((float)$week52High, 2), 'label' => '52-Week High', 'type' => 'resistance', 'strength' => 'strong'];
        }
        if ($week52Low) {
            $levels[] = ['price' => round((float)$week52Low, 2), 'label' => '52-Week Low',  'type' => 'support',    'strength' => 'strong'];
        }

        // Moving averages — dynamic S/R
        if ($dma200 && $dma200 > 0) {
            $type = $currentPrice >= $dma200 ? 'support' : 'resistance';
            $levels[] = ['price' => round($dma200, 2), 'label' => '200-DMA', 'type' => $type, 'strength' => 'strong'];
        }
        if ($dma50 && $dma50 > 0) {
            $type = $currentPrice >= $dma50 ? 'support' : 'resistance';
            $levels[] = ['price' => round($dma50, 2), 'label' => '50-DMA', 'type' => $type, 'strength' => 'medium'];
        }

        // Find pivot points from last 6 months of price history (approx 130 trading days)
        if (count($prices) >= 20) {
            $recentPrices = array_slice($prices, -130);
            $pivots       = self::findPivotLevels($recentPrices, $currentPrice);
            foreach ($pivots as $pivot) {
                $levels[] = $pivot;
            }
        }

        // Sort: resistances descending, supports ascending around current price
        usort($levels, fn($a, $b) => $b['price'] <=> $a['price']);

        // Deduplicate levels within 1% of each other
        $deduped = [];
        foreach ($levels as $level) {
            $isDuplicate = false;
            foreach ($deduped as $existing) {
                if (abs($level['price'] - $existing['price']) / max($existing['price'], 0.01) < 0.01) {
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) $deduped[] = $level;
        }

        return $deduped;
    }

    /**
     * Find pivot high/low clusters from a price series.
     */
    private static function findPivotLevels(array $prices, float $currentPrice): array {
        $count  = count($prices);
        $window = 10; // look-back/forward window for pivot detection
        $pivots = ['high' => [], 'low' => []];

        for ($i = $window; $i < $count - $window; $i++) {
            $slice     = array_slice($prices, $i - $window, $window * 2 + 1);
            $localMax  = max($slice);
            $localMin  = min($slice);
            if ($prices[$i] == $localMax) $pivots['high'][] = $prices[$i];
            if ($prices[$i] == $localMin) $pivots['low'][]  = $prices[$i];
        }

        $levels = [];

        // Cluster pivot highs
        if (!empty($pivots['high'])) {
            sort($pivots['high']);
            $clusters = self::clusterPrices($pivots['high'], 0.03);
            foreach (array_slice($clusters, 0, 2) as $cluster) {
                $avg = array_sum($cluster) / count($cluster);
                if (abs($avg - $currentPrice) / $currentPrice > 0.005) {
                    $type     = $avg > $currentPrice ? 'resistance' : 'support';
                    $levels[] = ['price' => round($avg, 2), 'label' => 'Pivot Zone', 'type' => $type, 'strength' => 'medium'];
                }
            }
        }

        // Cluster pivot lows
        if (!empty($pivots['low'])) {
            sort($pivots['low']);
            $clusters = self::clusterPrices($pivots['low'], 0.03);
            foreach (array_slice($clusters, 0, 2) as $cluster) {
                $avg = array_sum($cluster) / count($cluster);
                if (abs($avg - $currentPrice) / $currentPrice > 0.005) {
                    $type     = $avg < $currentPrice ? 'support' : 'resistance';
                    $levels[] = ['price' => round($avg, 2), 'label' => 'Pivot Zone', 'type' => $type, 'strength' => 'medium'];
                }
            }
        }

        return $levels;
    }

    /**
     * Group prices within pct tolerance into clusters.
     */
    private static function clusterPrices(array $sortedPrices, float $pct): array {
        $clusters = [];
        $current  = [];
        foreach ($sortedPrices as $price) {
            if (empty($current) || abs($price - end($current)) / end($current) <= $pct) {
                $current[] = $price;
            } else {
                $clusters[] = $current;
                $current    = [$price];
            }
        }
        if (!empty($current)) $clusters[] = $current;
        // Sort clusters by size descending (most touched = strongest level)
        usort($clusters, fn($a, $b) => count($b) - count($a));
        return $clusters;
    }

    private static function analyzeTrend(array $values, int $periods = 5, bool $invertGoodBad = false): string {
        if (count($values) < 2) return 'insufficient data';
        $recent = array_slice($values, -$periods);
        if (count($recent) < 2) return 'insufficient data';

        $first = (float)$recent[0];
        $last  = (float)end($recent);
        if ($first == 0) return 'flat';

        $change = (($last - $first) / abs($first)) * 100;
        $up     = !$invertGoodBad ? 'improving' : 'increasing (negative)';
        $down   = !$invertGoodBad ? 'declining'  : 'decreasing (positive)';

        if ($change > 5)   return $up;
        if ($change < -5)  return $down;
        return 'stable';
    }
}
