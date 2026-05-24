-- Migration 003: Clear stale peers cache entries
-- Run once to purge any cached null/empty peers data so the
-- next analysis re-fetches fresh Screener peer rows.
--
-- Safe to run multiple times (DELETE on already-empty set is a no-op).

USE ai_stock_platform;

DELETE FROM chart_cache
WHERE metric_group = 'peers';
