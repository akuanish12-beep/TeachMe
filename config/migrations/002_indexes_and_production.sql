-- Production readiness migration
-- Indexes for performance optimization

-- Lessons index (already created in 001_init.sql but ensuring it exists)
CREATE INDEX IF NOT EXISTS idx_lessons_user ON lessons(user_id, created_at);

-- Generations index for faster queries
CREATE INDEX IF NOT EXISTS idx_generations_user ON generations(user_id, created_at);

-- Subscriptions index for user lookups
CREATE INDEX IF NOT EXISTS idx_subscriptions_user ON subscriptions(user_id);

-- Users email index for login performance
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Lesson management columns (if not already added)
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS is_favorite TINYINT(1) DEFAULT 0;
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS user_notes TEXT;

