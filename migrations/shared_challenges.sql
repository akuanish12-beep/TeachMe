CREATE TABLE `shared_challenges` (
  `id` VARCHAR(36) PRIMARY KEY,
  `player_one_id` BIGINT(20) NOT NULL,
  `player_two_id` BIGINT(20) NOT NULL,
  `target_xp_goal` INT NOT NULL,
  `player_one_contribution` INT DEFAULT 0,
  `player_two_contribution` INT DEFAULT 0,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `status` ENUM('active', 'completed', 'expired') DEFAULT 'active',
  FOREIGN KEY (`player_one_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`player_two_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
