-- Structured multi-day learning plans

CREATE TABLE IF NOT EXISTS learning_plans (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  topic VARCHAR(255) NOT NULL,
  language VARCHAR(80) NOT NULL DEFAULT 'English',
  skill_level ENUM('beginner','intermediate','expert','refresher') NOT NULL DEFAULT 'intermediate',
  duration_days TINYINT NOT NULL,
  goal_notes TEXT NULL,
  curriculum_json JSON NOT NULL,
  status ENUM('active','completed','paused') NOT NULL DEFAULT 'active',
  started_on DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_learning_plans_user_status (user_id, status)
);

CREATE TABLE IF NOT EXISTS learning_plan_days (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  plan_id BIGINT NOT NULL,
  day_number INT NOT NULL,
  scheduled_on DATE NOT NULL,
  day_title VARCHAR(255) NOT NULL,
  day_focus TEXT NOT NULL,
  difficulty_phase ENUM('foundation','building','practice','mastery') NOT NULL DEFAULT 'foundation',
  status ENUM('pending','ready','completed','skipped') NOT NULL DEFAULT 'pending',
  lesson_id BIGINT NULL,
  notified_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  skipped_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (plan_id) REFERENCES learning_plans(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_plan_day (plan_id, day_number),
  INDEX idx_plan_days_schedule (plan_id, scheduled_on, status)
);
