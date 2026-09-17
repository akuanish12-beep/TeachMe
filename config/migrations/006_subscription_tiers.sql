-- Subscription tiers (Pro / Pro+ / Ultra), optional BYOK, plan close status

ALTER TABLE subscriptions
  ADD COLUMN plan_tier ENUM('pro','pro_plus','ultra') NOT NULL DEFAULT 'pro' AFTER status;

ALTER TABLE users
  ADD COLUMN gemini_api_key_encrypted TEXT NULL AFTER password_hash,
  ADD COLUMN gemini_api_key_hint VARCHAR(12) NULL AFTER gemini_api_key_encrypted;

ALTER TABLE learning_plans
  MODIFY COLUMN status ENUM('active','completed','paused','closed') NOT NULL DEFAULT 'active';
