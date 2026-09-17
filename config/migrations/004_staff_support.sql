-- Staff access and support tickets

ALTER TABLE users
  ADD COLUMN is_staff TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NULL,
  guest_name VARCHAR(120) NOT NULL,
  guest_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status ENUM('open','in_progress','waiting','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_support_tickets_status (status),
  INDEX idx_support_tickets_user (user_id),
  INDEX idx_support_tickets_email (guest_email)
);

CREATE TABLE IF NOT EXISTS support_ticket_messages (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  ticket_id BIGINT NOT NULL,
  author_type ENUM('user','staff') NOT NULL,
  author_user_id BIGINT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  INDEX idx_support_messages_ticket (ticket_id)
);
