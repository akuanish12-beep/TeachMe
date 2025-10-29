-- Initial schema for Tutorly application

-- users table
CREATE TABLE IF NOT EXISTS users (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- subscriptions table
CREATE TABLE IF NOT EXISTS subscriptions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  status ENUM('none','active','canceled','past_due') NOT NULL DEFAULT 'none',
  stripe_customer_id VARCHAR(120),
  stripe_subscription_id VARCHAR(120),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- generations table (tracks topic attempts for free/pro logic)
CREATE TABLE IF NOT EXISTS generations (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  topic VARCHAR(255) NOT NULL,
  language VARCHAR(80) NOT NULL,
  result_json JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- lessons table (normalized storage per generated lesson)
CREATE TABLE IF NOT EXISTS lessons (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  topic VARCHAR(255) NOT NULL,
  language VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  content_json JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Index for lessons query performance
CREATE INDEX IF NOT EXISTS idx_lessons_user ON lessons(user_id, created_at);

