-- Migration 005: Change JSON columns → LONGTEXT
-- Problem: MariaDB's JSON type has an implicit JSON_VALID() CHECK constraint (error #4025).
-- Scraped/AI text can contain invalid UTF-8, causing json_encode() to return false (empty
-- string ""), which fails the constraint and blocks every INSERT into analysis_history.
-- Fix: LONGTEXT stores the same JSON payload without the strict validity check.
-- This ALTER is idempotent — safe to run multiple times.

USE ai_stock_platform;

ALTER TABLE analysis_history
    MODIFY COLUMN conviction_json         LONGTEXT NULL,
    MODIFY COLUMN news_sentiment_json     LONGTEXT NULL,
    MODIFY COLUMN support_resistance_json LONGTEXT NULL,
    MODIFY COLUMN key_metrics_json        LONGTEXT NULL;
