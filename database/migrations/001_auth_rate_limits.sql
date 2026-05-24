-- Migration 001: Auth system + rate limiting
-- Run: mysql -u root -p ai_stock_platform < database/migrations/001_auth_rate_limits.sql
-- Safe to re-run: each statement is guarded against duplicates

USE ai_stock_platform;

-- ── Users table ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(100) NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Watchlist: add user_id column ─────────────────────────────────────────────
-- ADD COLUMN IF NOT EXISTS requires MySQL 8.0.3+
-- If on older MySQL, run this only if the column doesn't already exist.
ALTER TABLE watchlist
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;

-- ── Drop old unique key (ignore error if it doesn't exist) ────────────────────
-- DROP INDEX IF EXISTS requires MySQL 8.0.29+; use plain DROP INDEX on older versions.
ALTER TABLE watchlist
    DROP INDEX IF EXISTS uq_company;

-- ── Add per-user unique key ───────────────────────────────────────────────────
-- We use a procedure to avoid "duplicate key name" errors on re-runs.
DROP PROCEDURE IF EXISTS sp_add_uq_user_company;
DELIMITER //
CREATE PROCEDURE sp_add_uq_user_company()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'watchlist'
          AND INDEX_NAME   = 'uq_user_company'
    ) THEN
        ALTER TABLE watchlist
            ADD UNIQUE KEY uq_user_company (user_id, company_id);
    END IF;
END //
DELIMITER ;
CALL sp_add_uq_user_company();
DROP PROCEDURE IF EXISTS sp_add_uq_user_company;

-- ── Add foreign key (guarded via procedure) ───────────────────────────────────
DROP PROCEDURE IF EXISTS sp_add_fk_watchlist_user;
DELIMITER //
CREATE PROCEDURE sp_add_fk_watchlist_user()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA        = DATABASE()
          AND TABLE_NAME          = 'watchlist'
          AND CONSTRAINT_NAME     = 'fk_watchlist_user'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ) THEN
        ALTER TABLE watchlist
            ADD CONSTRAINT fk_watchlist_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END //
DELIMITER ;
CALL sp_add_fk_watchlist_user();
DROP PROCEDURE IF EXISTS sp_add_fk_watchlist_user;

-- ── Rate limits table ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rate_limits (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash    VARCHAR(64)  NOT NULL,
    endpoint   VARCHAR(50)  NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint_created (ip_hash, endpoint, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
