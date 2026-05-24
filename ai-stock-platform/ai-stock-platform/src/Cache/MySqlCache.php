<?php

class MySqlCache {

    /**
     * Returns cached row if fresh, null if expired or missing.
     */
    public static function get(string $table, array $where, int $ttl_seconds): ?array {
        try {
            $db   = Database::get();
            $cols = implode(' AND ', array_map(fn($k) => "$k = :$k", array_keys($where)));
            $stmt = $db->prepare("SELECT *, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
                                  FROM $table WHERE $cols ORDER BY fetched_at DESC LIMIT 1");
            $stmt->execute($where);
            $row = $stmt->fetch();
            if (!$row || $row['age'] > $ttl_seconds) return null;
            return $row;
        } catch (Throwable $e) {
            logError('MySqlCache::get', $e);
            return null;
        }
    }

    /**
     * Upsert: insert or replace on duplicate unique key.
     */
    public static function set(string $table, array $data): void {
        try {
            $db   = Database::get();
            $data['fetched_at'] = date('Y-m-d H:i:s');
            $cols = implode(', ', array_keys($data));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($data)));
            $upd  = implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($data)));
            $stmt = $db->prepare("INSERT INTO $table ($cols) VALUES ($vals)
                                  ON DUPLICATE KEY UPDATE $upd");
            $stmt->execute($data);
        } catch (Throwable $e) {
            logError('MySqlCache::set', $e);
        }
    }

    /**
     * Delete expired rows older than $days days.
     */
    public static function cleanup(string $table, int $days = 7): int {
        try {
            $db   = Database::get();
            $stmt = $db->prepare("DELETE FROM $table WHERE fetched_at < NOW() - INTERVAL $days DAY");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            logError('MySqlCache::cleanup', $e);
            return 0;
        }
    }
}
