-- Add analysis_jobs table for async job queue
-- Run: mysql -u root -p ai_stock_platform < database/migrate_add_jobs.sql

USE ai_stock_platform;

CREATE TABLE IF NOT EXISTS analysis_jobs (
    job_id          CHAR(36)      NOT NULL PRIMARY KEY,
    symbol          VARCHAR(20)   NOT NULL,
    force_refresh   TINYINT(1)    DEFAULT 0,
    model           VARCHAR(100)  NULL,
    status          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    step            VARCHAR(100)  NOT NULL DEFAULT 'queued',
    progress        TINYINT       NOT NULL DEFAULT 0,
    result_json     LONGTEXT      NULL,
    error           VARCHAR(500)  NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_symbol_status (symbol, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto-cleanup: delete jobs older than 24 hours (keeps the table lean)
-- Add this to your cron: mysql -u root ai_stock_platform -e "DELETE FROM analysis_jobs WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);"
