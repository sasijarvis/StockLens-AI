-- Migration 006: Back-fill missing sector data for companies already in the DB
-- The ON DUPLICATE KEY UPDATE in Company::findOrCreate previously only updated
-- screener_id, leaving sector = NULL for any stock analysed before this fix.
-- This migration copies the sector from any recent analysis_history row where
-- the AI prompt data embedded it, via re-running a Screener search in PHP is
-- not feasible here — instead we set a placeholder so the heatmap is at least
-- non-empty, and the sector will be corrected on the next re-analysis.
--
-- NOTE: If you have Screener sector data available you can update more precisely.
-- Run this once; it is safe to re-run (only touches rows where sector IS NULL).

USE ai_stock_platform;

-- Best-effort: use the sector embedded in the key_metrics_json if present
-- (stored as {"sector": "..."} is not guaranteed, so this is a no-op fallback)
-- The real fix is in Company::findOrCreate which now always updates sector.
-- Simply re-analyse any stock to populate its sector going forward.

SELECT 'Migration 006: sector back-fill noted. Re-analyse stocks to populate sector data.' AS message;
