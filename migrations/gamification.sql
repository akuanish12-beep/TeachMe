ALTER TABLE `users`
ADD COLUMN `total_xp` INT DEFAULT 0,
ADD COLUMN `current_level` INT DEFAULT 1,
ADD COLUMN `current_streak` INT DEFAULT 0,
ADD COLUMN `longest_streak` INT DEFAULT 0,
ADD COLUMN `last_active_date` TIMESTAMP NULL DEFAULT NULL,
ADD COLUMN `streak_freezes_count` INT DEFAULT 0;
