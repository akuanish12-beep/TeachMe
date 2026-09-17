CREATE TABLE `leagues` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `rank_tier` INT NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `leagues` (`name`, `rank_tier`) VALUES
('Bronze', 1),
('Silver', 2),
('Gold', 3),
('Diamond', 4);

CREATE TABLE `leaderboard_cohorts` (
  `id` VARCHAR(36) PRIMARY KEY,
  `league_id` INT NOT NULL,
  `week_start_date` DATE NOT NULL,
  `week_end_date` DATE NOT NULL,
  FOREIGN KEY (`league_id`) REFERENCES `leagues`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `leaderboard_members` (
  `cohort_id` VARCHAR(36) NOT NULL,
  `user_id` BIGINT(20) NOT NULL,
  `weekly_xp` INT DEFAULT 0,
  PRIMARY KEY (`cohort_id`, `user_id`),
  FOREIGN KEY (`cohort_id`) REFERENCES `leaderboard_cohorts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
