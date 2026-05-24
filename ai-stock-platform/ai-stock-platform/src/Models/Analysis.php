<?php

class Analysis {

    public static function save(int $companyId, array $aiResult, array $metrics, float $priceAtTime): int {
        $db       = Database::get();
        $sections = $aiResult['sections'];

        // Different AI models may format headings slightly differently.
        // Try multiple key variants for each section.
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
             section_risks, section_verdict, key_metrics_json,
             model_used, prompt_tokens, output_tokens)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            $companyId,
            $priceAtTime,
            $sections['verdict_label'] ?? 'Hold',
            $sections['confidence_value'] ?? 50,
            $get(['price_and_technical_setup', 'price_technical_setup', 'price_and_technical', 'technical_setup', 'price_setup']),
            $get(['valuation']),
            $get(['business_quality', 'business_quality_']),
            $get(['balance_sheet_health', 'balance_sheet', 'balance_sheet_health_']),
            $get(['cash_flow_quality', 'cash_flow', 'cashflow_quality', 'cash_flow_quality_']),
            $get(['catalysts_and_risks', 'catalysts_risks', 'catalysts_and_risks_', 'key_catalysts_and_risks']),
            null,
            $get(['verdict']),
            json_encode($metrics),
            $aiResult['model_used']    ?? null,
            $aiResult['prompt_tokens'] ?? 0,
            $aiResult['output_tokens'] ?? 0,
        ]);

        return (int)$db->lastInsertId();
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

    public static function getHistory(int $limit = 20, int $offset = 0, ?string $verdict = null): array {
        try {
            $db     = Database::get();
            $where  = $verdict ? "AND a.verdict = '$verdict'" : '';
            $stmt   = $db->prepare("SELECT a.*, c.nse_symbol, c.company_name, c.sector
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
            $where = $verdict ? "AND verdict = '" . $verdict . "'" : '';
            return (int)$db->query("SELECT COUNT(*) FROM analysis_history WHERE 1=1 $where")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function getRecent(int $limit = 6): array {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT a.*, c.nse_symbol, c.company_name, c.sector
                                  FROM analysis_history a
                                  JOIN companies c ON c.id = a.company_id
                                  GROUP BY c.id
                                  ORDER BY MAX(a.created_at) DESC
                                  LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
