<?php

class Watchlist {

    /**
     * Get all watchlist rows for the current user (or global if not logged in).
     */
    public static function getAll(): array {
        try {
            $userId = Auth::id();
            $db     = Database::get();

            if ($userId) {
                $stmt = $db->prepare("
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
                    WHERE w.user_id = ?
                    ORDER BY w.added_at DESC
                ");
                $stmt->execute([$userId]);
            } else {
                // Legacy global watchlist for unauthenticated access
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
                    WHERE w.user_id IS NULL
                    ORDER BY w.added_at DESC
                ");
            }
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            logError('Watchlist::getAll', $e);
            return [];
        }
    }

    public static function add(int $companyId, ?float $alertAbove = null, ?float $alertBelow = null): bool {
        try {
            $userId = Auth::id();
            $db     = Database::get();
            $db->prepare("INSERT IGNORE INTO watchlist (user_id, company_id, alert_price_above, alert_price_below) VALUES (?, ?, ?, ?)")
               ->execute([$userId, $companyId, $alertAbove, $alertBelow]);
            return true;
        } catch (Throwable $e) {
            logError('Watchlist::add', $e);
            return false;
        }
    }

    public static function remove(int $companyId): bool {
        try {
            $userId = Auth::id();
            $db     = Database::get();
            if ($userId) {
                $db->prepare("DELETE FROM watchlist WHERE company_id = ? AND user_id = ?")
                   ->execute([$companyId, $userId]);
            } else {
                $db->prepare("DELETE FROM watchlist WHERE company_id = ? AND user_id IS NULL")
                   ->execute([$companyId]);
            }
            return true;
        } catch (Throwable $e) {
            logError('Watchlist::remove', $e);
            return false;
        }
    }

    public static function has(int $companyId): bool {
        try {
            $userId = Auth::id();
            $db     = Database::get();
            if ($userId) {
                $stmt = $db->prepare("SELECT 1 FROM watchlist WHERE company_id = ? AND user_id = ? LIMIT 1");
                $stmt->execute([$companyId, $userId]);
            } else {
                $stmt = $db->prepare("SELECT 1 FROM watchlist WHERE company_id = ? AND user_id IS NULL LIMIT 1");
                $stmt->execute([$companyId]);
            }
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public static function updateAlerts(int $companyId, ?float $above, ?float $below): bool {
        try {
            $userId = Auth::id();
            $db     = Database::get();
            if ($userId) {
                $db->prepare("UPDATE watchlist SET alert_price_above = ?, alert_price_below = ? WHERE company_id = ? AND user_id = ?")
                   ->execute([$above, $below, $companyId, $userId]);
            } else {
                $db->prepare("UPDATE watchlist SET alert_price_above = ?, alert_price_below = ? WHERE company_id = ? AND user_id IS NULL")
                   ->execute([$above, $below, $companyId]);
            }
            return true;
        } catch (Throwable $e) {
            logError('Watchlist::updateAlerts', $e);
            return false;
        }
    }

    /**
     * Get all symbols for cron jobs (all users, or filtered by user_id).
     */
    public static function getSymbols(?int $userId = null): array {
        try {
            $db = Database::get();
            if ($userId !== null) {
                $stmt = $db->prepare("SELECT c.nse_symbol, c.screener_id, w.company_id, w.user_id
                                      FROM watchlist w JOIN companies c ON c.id = w.company_id
                                      WHERE w.user_id = ?");
                $stmt->execute([$userId]);
            } else {
                $stmt = $db->query("SELECT c.nse_symbol, c.screener_id, w.company_id, w.user_id,
                                           w.alert_price_above, w.alert_price_below,
                                           u.email AS user_email
                                    FROM watchlist w
                                    JOIN companies c ON c.id = w.company_id
                                    LEFT JOIN users u ON u.id = w.user_id");
            }
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
