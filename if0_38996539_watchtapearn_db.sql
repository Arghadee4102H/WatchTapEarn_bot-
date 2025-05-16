-- Create the database if it doesn't exist (optional, usually done by hosting)
-- CREATE DATABASE IF NOT EXISTS if0_38996539_watchtapearn_db;
-- USE if0_38996539_watchtapearn_db;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Telegram User ID',
    `username` VARCHAR(255) NULL COMMENT 'Telegram Username',
    `first_name` VARCHAR(255) NULL COMMENT 'Telegram First Name',
    `points` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `energy` INT NOT NULL DEFAULT 100,
    `max_energy` INT NOT NULL DEFAULT 100,
    `energy_per_second` FLOAT NOT NULL DEFAULT 0.1 COMMENT 'Energy points regenerated per second',
    `last_energy_update_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `taps_today` INT NOT NULL DEFAULT 0,
    `last_tap_date_utc` DATE NULL,
    `ads_watched_today` INT NOT NULL DEFAULT 0,
    `last_ad_date_utc` DATE NULL,
    `last_ad_watched_timestamp` TIMESTAMP NULL,
    `daily_tasks_completed_mask` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Bitmask for 4 daily tasks. 1=task1, 2=task2, 4=task3, 8=task4',
    `last_daily_tasks_date_utc` DATE NULL,
    `referred_by_user_id` BIGINT UNSIGNED NULL,
    `referral_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `join_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`referred_by_user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withdrawals Table
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `withdrawal_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `points_withdrawn` BIGINT UNSIGNED NOT NULL,
    `method` VARCHAR(50) NOT NULL COMMENT 'e.g., TON, BinancePayID',
    `wallet_address_or_id` VARCHAR(255) NOT NULL,
    `status` ENUM('pending', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (Optional) For more detailed referral tracking if needed later
-- CREATE TABLE IF NOT EXISTS `referral_log` (
--     `id` INT AUTO_INCREMENT PRIMARY KEY,
--     `referrer_id` BIGINT UNSIGNED NOT NULL,
--     `referred_id` BIGINT UNSIGNED NOT NULL,
--     `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (`referrer_id`) REFERENCES `users`(`user_id`),
--     FOREIGN KEY (`referred_id`) REFERENCES `users`(`user_id`),
--     UNIQUE KEY `unique_referral` (`referrer_id`, `referred_id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
