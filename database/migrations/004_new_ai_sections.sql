-- Migration 004: New AI analysis sections
-- Adds columns for trade setup, scenarios, conviction matrix,
-- news sentiment, and support/resistance to analysis_history.
-- Compatible with MySQL 5.7+ — uses stored procedures for idempotency.

USE ai_stock_platform;

DROP PROCEDURE IF EXISTS sp_m004_add_columns;
DELIMITER //
CREATE PROCEDURE sp_m004_add_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analysis_history' AND COLUMN_NAME='section_trade_setup') THEN
        ALTER TABLE analysis_history ADD COLUMN section_trade_setup TEXT NULL AFTER section_verdict;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analysis_history' AND COLUMN_NAME='section_scenarios') THEN
        ALTER TABLE analysis_history ADD COLUMN section_scenarios TEXT NULL AFTER section_trade_setup;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analysis_history' AND COLUMN_NAME='conviction_json') THEN
        ALTER TABLE analysis_history ADD COLUMN conviction_json JSON NULL AFTER section_scenarios;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analysis_history' AND COLUMN_NAME='news_sentiment_json') THEN
        ALTER TABLE analysis_history ADD COLUMN news_sentiment_json JSON NULL AFTER conviction_json;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='analysis_history' AND COLUMN_NAME='support_resistance_json') THEN
        ALTER TABLE analysis_history ADD COLUMN support_resistance_json JSON NULL AFTER news_sentiment_json;
    END IF;
END //
DELIMITER ;

CALL sp_m004_add_columns();
DROP PROCEDURE IF EXISTS sp_m004_add_columns;

-- Bump analysis cache TTL to 6 hrs default (no DB change needed; .env controls it)
-- Reminder: set OPENROUTER_MAX_TOKENS=3000 in .env for richer output
