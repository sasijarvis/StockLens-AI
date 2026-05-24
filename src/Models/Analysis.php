<?php

class Analysis {

    /**
     * Safe json_encode: handles invalid UTF-8 sequences that cause json_encode()
     * to return false (which would produce an empty string, breaking DB constraints).
     * Falls back to null JSON string on any encoding failure.
     */
    private static function jsonSafe($value): ?string {
        if ($value === null) return null;
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // Attempt to sanitise invalid UTF-8 then re-encode
            $clean   = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true);
            $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $encoded !== false ? $encoded : null;
    }

    public static function save(int $companyId, array $aiResult, array $metrics, float $priceAtTime, ?array $newsSentiment = null): int {
        $db       = Database::get();
        $sections = $aiResult['sections'];

        $get = function(array $keys) use ($sections): ?string {
            foreach ($keys as $key) {
                if (!empty($sections[$key])) return $sections[$key];
            }
            return null;
        };

        $stmt = $db->prepare("INSERT INTO analysis_history
            (company_id, price_at_time, verdict, confidence_score,
             section_technical, section_valuation, section_quality,
             section_balance, section_cashflow, section_catalysts,
             section_risks, section_verdict,
             section_trade_setup, section_scenarios,
             conviction_json, news_sentiment_json, support_resistance_json,
             key_metrics_json, model_used, prompt_tokens, output_tokens)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            $companyId,
            $priceAtTime,
            $sections['verdict_label']   ?? 'Hold',
            $sections['confidence_value'] ?? 50,
            $get(['price_and_technical_setup','price_technical_setup','price_and_technical','technical_setup']),
            $get(['valuation']),
            $get(['business_quality']),
            $get(['balance_sheet_health','balance_sheet']),
            $get(['cash_flow_quality','cash_flow']),
            $get(['catalysts_and_risks','catalysts_risks','key_catalysts_and_risks']),
            null,
            $get(['verdict']),
            $get(['trade_setup']),
            $get(['scenario_analysis','scenarios']),
            self::jsonSafe($sections['conviction_parsed'] ?? null),
            self::jsonSafe($newsSentiment),
            self::jsonSafe($metrics['support_resistance'] ?? null),
            self::jsonSafe($metrics),
            $aiResult['model_used']    ?? null,
            $aiResult['prompt_tokens'] ?? 0,
            $aiResult['output_tokens'] ?? 0,
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Back-fill news_sentiment_json on the most recent analysis row.
     * Called by the async sentiment endpoint after the main response was sent.
     */
    public static function updateSentiment(int $companyId, array $sentiment): void {
        try {
            $db = Database::get();
            $db->prepare("UPDATE analysis_history
                          SET news_sentiment_json = ?
                          WHERE company_id = ?
                          ORDER BY created_at DESC
                          LIMIT 1")
               ->execute([self::jsonSafe($sentiment), $companyId]);
        } catch (Throwable $e) {
            logError('Analysis::updateSentiment', $e);
        }
    }

    public static function getLatest(int $companyId, int $ttl = 21600): ?array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
                                  FROM analysis_history WHERE company_id=?
                                  ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch();
            if (!$row || $row['age'] > $ttl) return null;
            return $row;
        } catch (Throwable $e) {
            logError('Analysis::getLatest', $e);
            return null;
        }
    }

    /**
     * Get last N verdicts for a company — for the Verdict Change Tracker.
     */
    public static function getVerdictHistory(int $companyId, int $limit = 6): array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT id, verdict, confidence_score, price_at_time, created_at, model_used
                                  FROM analysis_history
                                  WHERE company_id = ?
                                  ORDER BY created_at DESC
                                  LIMIT ?");
            $stmt->execute([$companyId, $limit]);
            return array_reverse($stmt->fetchAll()); // chronological order
        } catch (Throwable $e) {
            logError('Analysis::getVerdictHistory', $e);
            return [];
        }
    }

    /**
     * Sector heatmap: latest verdict per company, grouped by sector.
     */
    public static function getSectorHeatmap(): array {
        try {
            $db   = Database::get();
            $rows = $db->query("
                SELECT
                    c.sector,
                    COUNT(*)                                                  AS total,
                    SUM(a.verdict = 'Buy')                                    AS buy_count,
                    SUM(a.verdict = 'Hold')                                   AS hold_count,
                    SUM(a.verdict = 'Avoid')                                  AS avoid_count,
                    ROUND(AVG(a.confidence_score), 1)                         AS avg_confidence,
                    MAX(a.created_at)                                          AS last_analysed
                FROM (
                    SELECT company_id, MAX(id) AS latest_id
                    FROM   analysis_history
                    GROUP  BY company_id
                ) latest
                JOIN analysis_history a ON a.id = latest.latest_id
                JOIN companies c ON c.id = a.company_id
                WHERE  c.sector IS NOT NULL AND c.sector != ''
                GROUP  BY c.sector
                ORDER  BY buy_count DESC, avg_confidence DESC
            ")->fetchAll();
            return $rows;
        } catch (Throwable $e) {
            logError('Analysis::getSectorHeatmap', $e);
            return [];
        }
    }

    public static function getHistory(int $limit = 20, int $offset = 0, ?string $verdict = null): array {
        try {
            $db    = Database::get();
            $where = $verdict ? "AND a.verdict = " . $db->quote($verdict) : '';
            $stmt  = $db->prepare("SELECT a.*, c.nse_symbol, c.company_name, c.sector
                                   FROM analysis_history a
                                   JOIN companies c ON c.id = a.company_id
                                   WHERE 1=1 $where
                                   ORDER BY a.created_at DESC
                                   LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            logError('Analysis::getHistory', $e);
            return [];
        }
    }

    public static function countHistory(?string $verdict = null): int {
        try {
            $db    = Database::get();
            $where = $verdict ? "AND verdict = " . $db->quote($verdict) : '';
            return (int)$db->query("SELECT COUNT(*) FROM analysis_history WHERE 1=1 $where")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function getRecent(int $limit = 6): array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT a.*, c.nse_symbol, c.company_name, c.sector
                                  FROM (
                                      SELECT company_id, MAX(id) AS latest_id
                                      FROM   analysis_history
                                      GROUP  BY company_id
                                  ) latest
                                  JOIN analysis_history a ON a.id = latest.latest_id
                                  JOIN companies c ON c.id = a.company_id
                                  ORDER BY a.created_at DESC
                                  LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
