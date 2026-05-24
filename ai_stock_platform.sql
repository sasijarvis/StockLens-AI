-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 07, 2026 at 11:21 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ai_stock_platform`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_m002_drop_uq_company` ()   BEGIN
    IF EXISTS (
        SELECT 1
        FROM   information_schema.STATISTICS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'watchlist'
          AND  INDEX_NAME   = 'uq_company'
    ) THEN
        ALTER TABLE watchlist DROP INDEX uq_company;
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `analysis_history`
--

CREATE TABLE `analysis_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `price_at_time` decimal(10,2) DEFAULT NULL,
  `verdict` enum('Buy','Hold','Avoid') DEFAULT NULL,
  `confidence_score` tinyint(3) UNSIGNED DEFAULT NULL COMMENT '0-100 confidence score',
  `section_technical` text DEFAULT NULL,
  `section_valuation` text DEFAULT NULL,
  `section_quality` text DEFAULT NULL,
  `section_balance` text DEFAULT NULL,
  `section_cashflow` text DEFAULT NULL,
  `section_catalysts` text DEFAULT NULL,
  `section_risks` text DEFAULT NULL,
  `section_verdict` text DEFAULT NULL,
  `key_metrics_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot of key metrics used' CHECK (json_valid(`key_metrics_json`)),
  `model_used` varchar(100) DEFAULT NULL,
  `prompt_tokens` int(11) DEFAULT NULL,
  `output_tokens` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_cache`
--

CREATE TABLE `chart_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `metric_group` varchar(50) NOT NULL,
  `days` smallint(6) NOT NULL DEFAULT 1825,
  `raw_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`raw_json`)),
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(10) UNSIGNED NOT NULL,
  `nse_symbol` varchar(20) NOT NULL,
  `bse_code` varchar(10) DEFAULT NULL,
  `screener_id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `sector` varchar(100) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `face_value` decimal(8,2) DEFAULT NULL,
  `issued_shares` bigint(20) DEFAULT NULL,
  `isin` varchar(20) DEFAULT NULL,
  `is_fno` tinyint(1) DEFAULT 0,
  `listing_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `nse_symbol`, `bse_code`, `screener_id`, `company_name`, `sector`, `industry`, `face_value`, `issued_shares`, `isin`, `is_fno`, `listing_date`, `created_at`, `updated_at`) VALUES
(1, 'RELIANCE', NULL, 5765, 'Reliance Industries Ltd', 'Energy', 'Refineries', 10.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(2, 'TCS', NULL, 18163, 'Tata Consultancy Services Ltd', 'IT', 'IT Services & Consulting', 1.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(3, 'INFY', NULL, 22592, 'Infosys Ltd', 'IT', 'IT Services & Consulting', 5.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(4, 'HDFCBANK', NULL, 1695, 'HDFC Bank Ltd', 'Financial Services', 'Banks - Private Sector', 1.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(5, 'TATAMOTORS', NULL, 16788, 'Tata Motors Ltd', 'Automobile', 'Passenger & Commercial Vehicles', 2.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(6, 'WIPRO', NULL, 27013, 'Wipro Ltd', 'IT', 'IT Services & Consulting', 2.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(7, 'ICICIBANK', NULL, 4669, 'ICICI Bank Ltd', 'Financial Services', 'Banks - Private Sector', 2.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(8, 'HINDUNILVR', NULL, 4321, 'Hindustan Unilever Ltd', 'FMCG', 'Personal Products', 1.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(9, 'SUNPHARMA', NULL, 17751, 'Sun Pharmaceutical Industries', 'Pharma', 'Pharmaceuticals', 1.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08'),
(10, 'BAJFINANCE', NULL, 3580, 'Bajaj Finance Ltd', 'Financial Services', 'Finance - NBFC', 2.00, NULL, NULL, 0, NULL, '2026-04-07 09:11:08', '2026-04-07 09:11:08');

-- --------------------------------------------------------

--
-- Table structure for table `news_cache`
--

CREATE TABLE `news_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(500) NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `link` varchar(1000) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `price_cache`
--

CREATE TABLE `price_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `last_price` decimal(10,2) DEFAULT NULL,
  `prev_close` decimal(10,2) DEFAULT NULL,
  `open_price` decimal(10,2) DEFAULT NULL,
  `day_high` decimal(10,2) DEFAULT NULL,
  `day_low` decimal(10,2) DEFAULT NULL,
  `vwap` decimal(10,2) DEFAULT NULL,
  `change_abs` decimal(10,2) DEFAULT NULL,
  `change_pct` decimal(6,2) DEFAULT NULL,
  `week_high` decimal(10,2) DEFAULT NULL,
  `week_high_date` date DEFAULT NULL,
  `week_low` decimal(10,2) DEFAULT NULL,
  `week_low_date` date DEFAULT NULL,
  `upper_circuit` decimal(10,2) DEFAULT NULL,
  `lower_circuit` decimal(10,2) DEFAULT NULL,
  `pe_ratio` decimal(8,2) DEFAULT NULL,
  `sector_pe` decimal(8,2) DEFAULT NULL,
  `market_cap_cr` decimal(15,2) DEFAULT NULL,
  `indices` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`indices`)),
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(10) UNSIGNED NOT NULL,
  `ip_hash` varchar(64) NOT NULL,
  `endpoint` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_cache`
--

CREATE TABLE `schedule_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `section` enum('profit-loss','cash-flow','balance-sheet') NOT NULL,
  `parent` varchar(100) NOT NULL,
  `years_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`years_json`)),
  `values_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`values_json`)),
  `breakdown_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`breakdown_json`)),
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `watchlist`
--

CREATE TABLE `watchlist` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `alert_price_above` decimal(10,2) DEFAULT NULL,
  `alert_price_below` decimal(10,2) DEFAULT NULL,
  `alert_pe_above` decimal(8,2) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `analysis_history`
--
ALTER TABLE `analysis_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_created` (`company_id`,`created_at`),
  ADD KEY `idx_verdict` (`verdict`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `chart_cache`
--
ALTER TABLE `chart_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_metric_days` (`company_id`,`metric_group`,`days`),
  ADD KEY `idx_fetched` (`fetched_at`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nse_symbol` (`nse_symbol`),
  ADD KEY `idx_symbol` (`nse_symbol`),
  ADD KEY `idx_screener_id` (`screener_id`);

--
-- Indexes for table `news_cache`
--
ALTER TABLE `news_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_published` (`company_id`,`published_at`);

--
-- Indexes for table `price_cache`
--
ALTER TABLE `price_cache`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_fetched` (`company_id`,`fetched_at`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_endpoint_created` (`ip_hash`,`endpoint`,`created_at`);

--
-- Indexes for table `schedule_cache`
--
ALTER TABLE `schedule_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_section_parent` (`company_id`,`section`,`parent`),
  ADD KEY `idx_fetched` (`fetched_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `watchlist`
--
ALTER TABLE `watchlist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_company` (`user_id`,`company_id`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `analysis_history`
--
ALTER TABLE `analysis_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_cache`
--
ALTER TABLE `chart_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `news_cache`
--
ALTER TABLE `news_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `price_cache`
--
ALTER TABLE `price_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_cache`
--
ALTER TABLE `schedule_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `watchlist`
--
ALTER TABLE `watchlist`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `analysis_history`
--
ALTER TABLE `analysis_history`
  ADD CONSTRAINT `analysis_history_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chart_cache`
--
ALTER TABLE `chart_cache`
  ADD CONSTRAINT `chart_cache_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `news_cache`
--
ALTER TABLE `news_cache`
  ADD CONSTRAINT `news_cache_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `price_cache`
--
ALTER TABLE `price_cache`
  ADD CONSTRAINT `price_cache_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedule_cache`
--
ALTER TABLE `schedule_cache`
  ADD CONSTRAINT `schedule_cache_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `watchlist`
--
ALTER TABLE `watchlist`
  ADD CONSTRAINT `fk_watchlist_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_watchlist_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
