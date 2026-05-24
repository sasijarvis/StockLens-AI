<?php

class Watchlist {

    public static function getAll(): array {
        try {
            $db   = Database::get();
            $stmt = $db->query("
                SELECT w.*, c.nse_symbol, c.company_name, c.sector,
                       a.verdict AS last_verdict, a.confidence_score AS last_confidence,
                       a.created_at AS last_analysis_at
                FROM watchlist w
                JOIN companies c ON c.id = w.company_id
                LEFT JOIN analysis_history a ON a.id = (
                    SELECT id FROM analysis_history
                    WHERE company_id = w.company_id
                    ORDER BY created_at DESC LIMIT 1
                )
                ORDER BY w.added_at DESC
            ");
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            logError('Watchlist::getAll', $e);
            return [];
        }
    }

    public static function add(int $companyId): bool {
        try {
            $db = Database::get();
            $db->prepare("INSERT IGNORE INTO watchlist (company_id) VALUES (?)")->execute([$companyId]);
            return true;
        } catch (Throwable $e) {
            logError('Watchlist::add', $e);
            return false;
        }
    }

    public static function remove(int $companyId): bool {
        try {
            $db = Database::get();
            $db->prepare("DELETE FROM watchlist WHERE company_id = ?")->execute([$companyId]);
            return true;
        } catch (Throwable $e) {
            logError('Watchlist::remove', $e);
            return false;
        }
    }

    public static function has(int $companyId): bool {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT 1 FROM watchlist WHERE company_id = ? LIMIT 1");
            $stmt->execute([$companyId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function getSymbols(): array {
        try {
            $db = Database::get();
            return $db->query("SELECT c.nse_symbol, c.screener_id, w.company_id
                               FROM watchlist w JOIN companies c ON c.id = w.company_id")
                      ->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
