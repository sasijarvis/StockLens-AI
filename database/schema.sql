-- AI Stock Analysis Platform - Full Schema
-- Run: mysql -u root -p ai_stock_platform < database/schema.sql

CREATE DATABASE IF NOT EXISTS ai_stock_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ai_stock_platform;

-- Companies master table
CREATE TABLE IF NOT EXISTS companies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nse_symbol      VARCHAR(20)  NOT NULL UNIQUE,
    bse_code        VARCHAR(10)  NULL,
    screener_id     INT UNSIGNED NOT NULL,
    company_name    VARCHAR(200) NOT NULL,
    sector          VARCHAR(100) NULL,
    industry        VARCHAR(100) NULL,
    face_value      DECIMAL(8,2) NULL,
    issued_shares   BIGINT       NULL,
    isin            VARCHAR(20)  NULL,
    is_fno          TINYINT(1)   DEFAULT 0,
    listing_date    DATE         NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_symbol (nse_symbol),
    INDEX idx_screener_id (screener_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Price cache (TTL: 5 mins)
CREATE TABLE IF NOT EXISTS price_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id      INT UNSIGNED NOT NULL,
    last_price      DECIMAL(10,2) NULL,
    prev_close      DECIMAL(10,2) NULL,
    open_price      DECIMAL(10,2) NULL,
    day_high        DECIMAL(10,2) NULL,
    day_low         DECIMAL(10,2) NULL,
    vwap            DECIMAL(10,2) NULL,
    change_abs      DECIMAL(10,2) NULL,
    change_pct      DECIMAL(6,2)  NULL,
    week_high       DECIMAL(10,2) NULL,
    week_high_date  DATE          NULL,
    week_low        DECIMAL(10,2) NULL,
    week_low_date   DATE          NULL,
    upper_circuit   DECIMAL(10,2) NULL,
    lower_circuit   DECIMAL(10,2) NULL,
    pe_ratio        DECIMAL(8,2)  NULL,
    sector_pe       DECIMAL(8,2)  NULL,
    market_cap_cr   DECIMAL(15,2) NULL,
    indices         JSON          NULL,
    fetched_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_fetched (company_id, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chart data cache (TTL: 6 hrs)
CREATE TABLE IF NOT EXISTS chart_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id      INT UNSIGNED NOT NULL,
    metric_group    VARCHAR(50)  NOT NULL,
    days            SMALLINT     NOT NULL DEFAULT 1825,
    raw_json        JSON         NOT NULL,
    fetched_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uq_company_metric_days (company_id, metric_group, days),
    INDEX idx_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Financial schedule cache (TTL: 24 hrs)
CREATE TABLE IF NOT EXISTS schedule_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id      INT UNSIGNED NOT NULL,
    section         ENUM('profit-loss','cash-flow','balance-sheet') NOT NULL,
    parent          VARCHAR(100) NOT NULL,
    years_json      JSON         NOT NULL,
    values_json     JSON         NOT NULL,
    breakdown_json  JSON         NULL,
    fetched_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uq_company_section_parent (company_id, section, parent),
    INDEX idx_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- News cache (TTL: 30 mins)
CREATE TABLE IF NOT EXISTS news_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(500) NOT NULL,
    source          VARCHAR(100) NULL,
    link            VARCHAR(1000) NULL,
    published_at    TIMESTAMP    NULL,
    fetched_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_published (company_id, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI analysis history
CREATE TABLE IF NOT EXISTS analysis_history (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id          INT UNSIGNED NOT NULL,
    price_at_time       DECIMAL(10,2) NULL,
    verdict             ENUM('Buy','Hold','Avoid') NULL,
    confidence_score    TINYINT UNSIGNED NULL COMMENT '0-100 confidence score',
    section_technical   TEXT NULL,
    section_valuation   TEXT NULL,
    section_quality     TEXT NULL,
    section_balance     TEXT NULL,
    section_cashflow    TEXT NULL,
    section_catalysts   TEXT NULL,
    section_risks       TEXT NULL,
    section_verdict     TEXT NULL,
    key_metrics_json    JSON NULL COMMENT 'Snapshot of key metrics used',
    model_used          VARCHAR(100) NULL,
    prompt_tokens       INT NULL,
    output_tokens       INT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_company_created (company_id, created_at),
    INDEX idx_verdict (verdict),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Analysis job queue (async pipeline)
CREATE TABLE IF NOT EXISTS analysis_jobs (
    job_id          CHAR(36)      NOT NULL PRIMARY KEY,  -- UUID
    symbol          VARCHAR(20)   NOT NULL,
    force_refresh   TINYINT(1)    DEFAULT 0,
    model           VARCHAR(100)  NULL,
    status          ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    step            VARCHAR(100)  NOT NULL DEFAULT 'queued',
    progress        TINYINT       NOT NULL DEFAULT 0,   -- 0-100
    result_json     LONGTEXT      NULL,                 -- full JSON response on done
    error           VARCHAR(500)  NULL,                 -- error message on failed
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_symbol_status (symbol, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Watchlist
CREATE TABLE IF NOT EXISTS watchlist (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id          INT UNSIGNED NOT NULL,
    alert_price_above   DECIMAL(10,2) NULL,
    alert_price_below   DECIMAL(10,2) NULL,
    alert_pe_above      DECIMAL(8,2)  NULL,
    notes               VARCHAR(500)  NULL,
    added_at            TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY uq_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
