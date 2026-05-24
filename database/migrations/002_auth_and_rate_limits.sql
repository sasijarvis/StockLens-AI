-- ============================================================
-- Migration 002: Auth system + rate limiting
-- Supersedes: 001_auth_rate_limits.sql (safe to run after 001
--             was fully, partially, or never applied)
--
-- Compatible with: MySQL 5.7+, MySQL 8.0+, MariaDB 10.x
-- Idempotent: yes — safe to run multiple times
--
-- Run:
--   mysql -u root -p ai_stock_platform < database/migrations/002_auth_and_rate_limits.sql
-- ============================================================

USE ai_stock_platform;

-- ── 1. users table ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    name          VARCHAR(100)  NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. rate_limits table ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rate_limits (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash    VARCHAR(64)  NOT NULL,
    endpoint   VARCHAR(50)  NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint_created (ip_hash, endpoint, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Add user_id column to watchlist ───────────────────────────────────────

DROP PROCEDURE IF EXISTS sp_m002_add_user_id;

DELIMITER //
CREATE PROCEDURE sp_m002_add_user_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'watchlist'
          AND  COLUMN_NAME  = 'user_id'
    ) THEN
        ALTER TABLE watchlist ADD COLUMN user_id INT UNSIGNED NULL AFTER id;
    END IF;
END //
DELIMITER ;

CALL sp_m002_add_user_id();
DROP PROCEDURE IF EXISTS sp_m002_add_user_id;

-- ── 4. Replace uq_company with uq_user_company ───────────────────────────────
--
-- Problem: uq_company(company_id) is the backing index for the existing
--   FOREIGN KEY (company_id) REFERENCES companies(id)
-- MySQL error #1553 fires if you try to DROP that index while the FK exists.
--
-- Fix sequence:
--   a) Look up the auto-generated FK name for company_id → companies
--   b) Drop that FK (removes the block on the index)
--   c) Drop uq_company
--   d) Add idx_company_id — a plain index so the FK can be re-added
--      (uq_user_company starts with user_id, so MySQL can't use it for this FK)
--   e) Re-add the FK with a stable name fk_watchlist_company
--   f) Add the new uq_user_company(user_id, company_id) unique key

DROP PROCEDURE IF EXISTS sp_m002_rebuild_watchlist_keys;

DELIMITER //
CREATE PROCEDURE sp_m002_rebuild_watchlist_keys()
BEGIN
    DECLARE v_fk_company VARCHAR(255) DEFAULT NULL;

    -- ── a) Find the FK name for company_id → companies ───────────────────────
    SELECT CONSTRAINT_NAME INTO v_fk_company
    FROM   information_schema.KEY_COLUMN_USAGE
    WHERE  TABLE_SCHEMA          = DATABASE()
      AND  TABLE_NAME            = 'watchlist'
      AND  COLUMN_NAME           = 'company_id'
      AND  REFERENCED_TABLE_NAME = 'companies'
    LIMIT 1;

    -- ── b) Drop the company FK so we can touch the index it uses ─────────────
    IF v_fk_company IS NOT NULL THEN
        SET @drop_fk = CONCAT('ALTER TABLE watchlist DROP FOREIGN KEY `', v_fk_company, '`');
        PREPARE s FROM @drop_fk;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;

    -- ── c) Drop uq_company ───────────────────────────────────────────────────
    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'watchlist'
          AND  INDEX_NAME   = 'uq_company'
    ) THEN
        ALTER TABLE watchlist DROP INDEX uq_company;
    END IF;

    -- ── d) Add plain index on company_id for FK support ──────────────────────
    --  (uq_user_company has user_id as leftmost column so cannot serve this FK)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'watchlist'
          AND  INDEX_NAME   = 'idx_company_id'
    ) THEN
        ALTER TABLE watchlist ADD INDEX idx_company_id (company_id);
    END IF;

    -- ── e) Re-add FK company_id → companies with a stable name ───────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE  TABLE_SCHEMA          = DATABASE()
          AND  TABLE_NAME            = 'watchlist'
          AND  COLUMN_NAME           = 'company_id'
          AND  REFERENCED_TABLE_NAME = 'companies'
    ) THEN
        ALTER TABLE watchlist
            ADD CONSTRAINT fk_watchlist_company
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
    END IF;

    -- ── f) Add per-user unique key ────────────────────────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'watchlist'
          AND  INDEX_NAME   = 'uq_user_company'
    ) THEN
        ALTER TABLE watchlist
            ADD UNIQUE KEY uq_user_company (user_id, company_id);
    END IF;

END //
DELIMITER ;

CALL sp_m002_rebuild_watchlist_keys();
DROP PROCEDURE IF EXISTS sp_m002_rebuild_watchlist_keys;

-- ── 5. Add foreign key fk_watchlist_user → users(id) ─────────────────────────
-- MySQL does not enforce FKs on NULL values, so existing rows are unaffected.

DROP PROCEDURE IF EXISTS sp_m002_add_fk_user;

DELIMITER //
CREATE PROCEDURE sp_m002_add_fk_user()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE  TABLE_SCHEMA          = DATABASE()
          AND  TABLE_NAME            = 'watchlist'
          AND  CONSTRAINT_NAME       = 'fk_watchlist_user'
          AND  REFERENCED_TABLE_NAME = 'users'
    ) THEN
        ALTER TABLE watchlist
            ADD CONSTRAINT fk_watchlist_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END //
DELIMITER ;

CALL sp_m002_add_fk_user();
DROP PROCEDURE IF EXISTS sp_m002_add_fk_user;

-- ── Verification (run manually after migration) ───────────────────────────────
-- DESCRIBE watchlist;
-- SHOW INDEX FROM watchlist;
-- SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
--   FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = 'ai_stock_platform' AND TABLE_NAME = 'watchlist';
