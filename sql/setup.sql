-- WoW Legends — Required Database Tables
-- Run this SQL on your `auth` database via phpMyAdmin or MySQL CLI.
-- Compatible with MySQL 5.5.9+

-- 1. Password Resets (may already exist)
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token_key VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 2. Support Tickets (DB-stored with admin replies)
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','in_progress','closed') DEFAULT 'open',
  admin_reply TEXT,
  replied_by VARCHAR(50) DEFAULT NULL,
  attachments TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  INDEX idx_user (user_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 3. Admin Audit Log
CREATE TABLE IF NOT EXISTS admin_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  admin_name VARCHAR(50) NOT NULL,
  action VARCHAR(100) NOT NULL,
  target VARCHAR(255) DEFAULT NULL,
  details TEXT,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin (admin_id),
  INDEX idx_action (action),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 4. Playtime Rewards (per-account rolling state)
CREATE TABLE IF NOT EXISTS playtime_rewards (
  account_id INT NOT NULL PRIMARY KEY,
  last_total_seconds INT NOT NULL DEFAULT 0,
  today_dp_claimed INT NOT NULL DEFAULT 0,
  today_date DATE NOT NULL,
  total_paid_dp INT NOT NULL DEFAULT 0,
  last_claim_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 5. Playtime Rewards Log (audit trail of every claim)
CREATE TABLE IF NOT EXISTS playtime_reward_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  dp_amount INT NOT NULL,
  seconds_claimed INT NOT NULL,
  total_seconds_at_claim INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account (account_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 6. Ticket Messages (multi-turn conversation between user and admins).
-- Replaces the old single-message + single-admin_reply model. The original
-- ticket subject/category lives on `tickets`; every actual message in the
-- thread (including the original one) lives here, in chronological order.
-- `attachments` is a JSON array of filenames stored in /uploads/tickets/.
CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  sender_type ENUM('user', 'admin') NOT NULL,
  sender_username VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  attachments TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket (ticket_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Idempotent column add for installs that have ticket_messages without the
-- attachments column. Uses prepared statements + INFORMATION_SCHEMA so it
-- works in phpMyAdmin / mysql CLI alike (no DELIMITER quirks needed).
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'ticket_messages'
    AND COLUMN_NAME  = 'attachments'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE ticket_messages ADD COLUMN attachments TEXT DEFAULT NULL AFTER message',
  'SELECT 1');
PREPARE add_col FROM @ddl;
EXECUTE add_col;
DEALLOCATE PREPARE add_col;

-- Idempotent backfill: any existing ticket whose first user message hasn't
-- been migrated yet gets one inserted from `tickets.message`. Likewise for
-- the admin reply if present. Safe to re-run — both INSERTs are guarded
-- by NOT EXISTS checks.
INSERT INTO ticket_messages (ticket_id, sender_type, sender_username, message, created_at)
SELECT t.id, 'user', t.username, t.message, t.created_at
FROM tickets t
WHERE NOT EXISTS (
  SELECT 1 FROM ticket_messages tm WHERE tm.ticket_id = t.id
);

INSERT INTO ticket_messages (ticket_id, sender_type, sender_username, message, created_at)
SELECT t.id, 'admin', COALESCE(NULLIF(t.replied_by, ''), 'Admin'), t.admin_reply, COALESCE(t.updated_at, t.created_at)
FROM tickets t
WHERE t.admin_reply IS NOT NULL
  AND t.admin_reply != ''
  AND NOT EXISTS (
    SELECT 1 FROM ticket_messages tm
    WHERE tm.ticket_id = t.id AND tm.sender_type = 'admin'
  );

-- 7. News Posts (admin-authored blog/news, Markdown body, public /news pages).
-- Monolingual on purpose — admins write in whatever language fits their audience.
-- Slug is unique and URL-safe. `icon` is a Bootstrap-Icons class shown on cards.
CREATE TABLE IF NOT EXISTS news_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL,
  title VARCHAR(200) NOT NULL,
  excerpt VARCHAR(500) DEFAULT NULL,
  body MEDIUMTEXT NOT NULL,
  icon VARCHAR(60) DEFAULT 'bi-megaphone',
  author_id INT DEFAULT NULL,
  author_name VARCHAR(50) DEFAULT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slug (slug),
  INDEX idx_status_published (status, published_at),
  INDEX idx_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
