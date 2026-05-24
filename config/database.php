<?php

class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        // Reconnect if the connection has gone away (MySQL 2006 on long-running requests)
        if (self::$instance !== null) {
            try {
                self::$instance->query('SELECT 1');
            } catch (PDOException) {
                self::$instance = null; // force reconnect
            }
        }

        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('DB_HOST', 'localhost'),
                env('DB_PORT', 3306),
                env('DB_NAME', 'ai_stock_platform')
            );
            self::$instance = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
            // Keep connection alive on long AI requests
            self::$instance->exec("SET SESSION wait_timeout=600, interactive_timeout=600");
        }
        return self::$instance;
    }

    public static function reset(): void {
        self::$instance = null;
    }
}
