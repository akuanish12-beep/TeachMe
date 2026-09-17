-- Email OTP verification for signup

CREATE TABLE IF NOT EXISTS email_otp_codes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  purpose VARCHAR(32) NOT NULL DEFAULT 'signup',
  code_hash VARCHAR(255) NOT NULL,
  payload JSON NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_otp_lookup (email, purpose, expires_at)
);
