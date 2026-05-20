-- WoW Legends — Required Database Tables
-- Run this SQL on your `auth` database via phpMyAdmin or MySQL CLI.
-- Compatible with MySQL 5.5.9+
--
-- All portal-owned tables use a `web_` prefix so they're visually distinct
-- from the worldserver's own auth/character/world tables that live in the
-- same database. The block right below handles legacy un-prefixed installs:
-- if a pre-v0.7.x table exists at the old name AND the new `web_` name
-- does NOT yet exist, it's renamed in place (data preserved, indexes
-- preserved, no rebuild). Re-running setup.sql after migration is a no-op.

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 0 — Legacy rename migration (pre-v0.7.x → web_ prefix).
--
-- Same INFORMATION_SCHEMA + prepared-statement pattern the rest of this file
-- uses; each block is independent and idempotent.
-- ─────────────────────────────────────────────────────────────────────────────

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_password_resets');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE password_resets TO web_password_resets', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_tickets');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE tickets TO web_tickets', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_log');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_admin_audit_log');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE admin_audit_log TO web_admin_audit_log', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'playtime_rewards');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_playtime_rewards');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE playtime_rewards TO web_playtime_rewards', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'playtime_reward_log');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_playtime_reward_log');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE playtime_reward_log TO web_playtime_reward_log', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_ticket_messages');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE ticket_messages TO web_ticket_messages', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_posts');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_news_posts');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE news_posts TO web_news_posts', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_avatars');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_user_avatars');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE user_avatars TO web_user_avatars', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_settings');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_forum_settings');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE forum_settings TO web_forum_settings', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_categories');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_forum_categories');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE forum_categories TO web_forum_categories', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_threads');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_forum_threads');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE forum_threads TO web_forum_threads', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_forum_posts');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE forum_posts TO web_forum_posts', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_bans');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_forum_bans');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE forum_bans TO web_forum_bans', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donation_codes');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_donation_codes');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE donation_codes TO web_donation_codes', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donation_log');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_donation_log');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE donation_log TO web_donation_log', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_settings');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_shop_settings');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE shop_settings TO web_shop_settings', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

SET @oe := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_settings');
SET @ne := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'web_site_settings');
SET @ddl := IF(@oe = 1 AND @ne = 0, 'RENAME TABLE site_settings TO web_site_settings', 'SELECT 1');
PREPARE rn FROM @ddl; EXECUTE rn; DEALLOCATE PREPARE rn;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 1 — Schema (CREATE TABLE IF NOT EXISTS).
-- After Step 0 above, the legacy tables are already renamed; the blocks
-- below are therefore no-ops on existing installs and the source of truth
-- on fresh installs.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Password Resets (may already exist)
CREATE TABLE IF NOT EXISTS web_password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  token_key VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Support Tickets (DB-stored with admin replies)
CREATE TABLE IF NOT EXISTS web_tickets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Admin Audit Log
CREATE TABLE IF NOT EXISTS web_admin_audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Playtime Rewards (per-account rolling state)
CREATE TABLE IF NOT EXISTS web_playtime_rewards (
  account_id INT NOT NULL PRIMARY KEY,
  last_total_seconds INT NOT NULL DEFAULT 0,
  today_dp_claimed INT NOT NULL DEFAULT 0,
  today_date DATE NOT NULL,
  total_paid_dp INT NOT NULL DEFAULT 0,
  last_claim_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Playtime Rewards Log (audit trail of every claim)
CREATE TABLE IF NOT EXISTS web_playtime_reward_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  account_id INT NOT NULL,
  dp_amount INT NOT NULL,
  seconds_claimed INT NOT NULL,
  total_seconds_at_claim INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account (account_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Ticket Messages (multi-turn conversation between user and admins).
-- Replaces the old single-message + single-admin_reply model. The original
-- ticket subject/category lives on `web_tickets`; every actual message in
-- the thread (including the original one) lives here, in chronological
-- order. `attachments` is a JSON array of filenames stored in /uploads/tickets/.
CREATE TABLE IF NOT EXISTS web_ticket_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  sender_type ENUM('user', 'admin') NOT NULL,
  sender_username VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  attachments TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ticket (ticket_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotent column add for installs that have web_ticket_messages without the
-- attachments column. Uses prepared statements + INFORMATION_SCHEMA so it
-- works in phpMyAdmin / mysql CLI alike (no DELIMITER quirks needed).
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'web_ticket_messages'
    AND COLUMN_NAME  = 'attachments'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE web_ticket_messages ADD COLUMN attachments TEXT DEFAULT NULL AFTER message',
  'SELECT 1');
PREPARE add_col FROM @ddl;
EXECUTE add_col;
DEALLOCATE PREPARE add_col;

-- Idempotent backfill: any existing ticket whose first user message hasn't
-- been migrated yet gets one inserted from `web_tickets.message`. Likewise
-- for the admin reply if present. Safe to re-run — both INSERTs are
-- guarded by NOT EXISTS checks.
INSERT INTO web_ticket_messages (ticket_id, sender_type, sender_username, message, created_at)
SELECT t.id, 'user', t.username, t.message, t.created_at
FROM web_tickets t
WHERE NOT EXISTS (
  SELECT 1 FROM web_ticket_messages tm WHERE tm.ticket_id = t.id
);

INSERT INTO web_ticket_messages (ticket_id, sender_type, sender_username, message, created_at)
SELECT t.id, 'admin', COALESCE(NULLIF(t.replied_by, ''), 'Admin'), t.admin_reply, COALESCE(t.updated_at, t.created_at)
FROM web_tickets t
WHERE t.admin_reply IS NOT NULL
  AND t.admin_reply != ''
  AND NOT EXISTS (
    SELECT 1 FROM web_ticket_messages tm
    WHERE tm.ticket_id = t.id AND tm.sender_type = 'admin'
  );

-- ─────────────────────────────────────────────────────────────────────────────
-- Idempotent character-set upgrade for the legacy tables (1–6 above).
--
-- The original schema shipped with DEFAULT CHARSET=utf8 (an alias for the
-- 3-byte utf8mb3 encoding). MySQL 8 deprecates the `utf8` alias and will
-- repoint it to utf8mb4 in a future release — emitting warning 3719 on every
-- run until then. The newer tables (web_news_posts, web_user_avatars, and
-- all of the web_forum_* tables) already use utf8mb4.
--
-- These blocks convert any of the legacy tables that's still on utf8/utf8mb3
-- to utf8mb4 in place. The conversion only fires when the existing collation
-- isn't already utf8mb4, so subsequent re-runs are a no-op (no table rebuild).
-- ─────────────────────────────────────────────────────────────────────────────
SET @t := 'web_password_resets';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

SET @t := 'web_tickets';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

SET @t := 'web_admin_audit_log';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

SET @t := 'web_playtime_rewards';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

SET @t := 'web_playtime_reward_log';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

SET @t := 'web_ticket_messages';
SET @cs := (SELECT CCSA.CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.TABLES T
            JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON T.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME = @t);
SET @ddl := IF(@cs IS NOT NULL AND @cs <> 'utf8mb4',
  CONCAT('ALTER TABLE ', @t, ' CONVERT TO CHARACTER SET utf8mb4'), 'SELECT 1');
PREPARE conv FROM @ddl; EXECUTE conv; DEALLOCATE PREPARE conv;

-- 7. News Posts (admin-authored blog/news, Markdown body, public /news pages).
-- Monolingual on purpose — admins write in whatever language fits their audience.
-- Slug is unique and URL-safe. `icon` is a Bootstrap-Icons class shown on cards.
CREATE TABLE IF NOT EXISTS web_news_posts (
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

-- 8. User Avatars (one row per account that has uploaded an avatar; absence of
-- a row means the user has no avatar and the UI renders colored-initials).
CREATE TABLE IF NOT EXISTS web_user_avatars (
  account_id INT NOT NULL PRIMARY KEY,
  filename VARCHAR(160) NOT NULL,
  mime_type VARCHAR(40) NOT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Forum — single-row settings table. id is always 1; seeded once below.
CREATE TABLE IF NOT EXISTS web_forum_settings (
  id TINYINT NOT NULL PRIMARY KEY,
  enabled TINYINT NOT NULL DEFAULT 0,
  auto_approve_threshold INT NOT NULL DEFAULT 3,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the single settings row only when missing.
INSERT INTO web_forum_settings (id, enabled, auto_approve_threshold)
SELECT 1, 0, 3
WHERE NOT EXISTS (SELECT 1 FROM web_forum_settings WHERE id = 1);

-- 10. Forum Categories — one level (no sub-categories by design). Slug is
-- URL-safe and unique. `icon` is a Bootstrap-Icons class shown on the card.
CREATE TABLE IF NOT EXISTS web_forum_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  icon VARCHAR(60) DEFAULT 'bi-chat-square-text',
  sort_order INT NOT NULL DEFAULT 0,
  -- admin_only: only GMs (gmlevel >= 9) may start threads here (announcements).
  -- allow_replies: when 0, non-GMs cannot reply (fully read-only). Defaults
  -- keep existing categories fully open, so this migration is non-breaking.
  admin_only TINYINT NOT NULL DEFAULT 0,
  allow_replies TINYINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cat_slug (slug),
  INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotent column adds for installs whose web_forum_categories predates the
-- per-category posting policy (same INFORMATION_SCHEMA pattern as above).
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'web_forum_categories'
    AND COLUMN_NAME  = 'admin_only'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE web_forum_categories ADD COLUMN admin_only TINYINT NOT NULL DEFAULT 0 AFTER sort_order',
  'SELECT 1');
PREPARE add_col FROM @ddl;
EXECUTE add_col;
DEALLOCATE PREPARE add_col;

SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'web_forum_categories'
    AND COLUMN_NAME  = 'allow_replies'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE web_forum_categories ADD COLUMN allow_replies TINYINT NOT NULL DEFAULT 1 AFTER admin_only',
  'SELECT 1');
PREPARE add_col FROM @ddl;
EXECUTE add_col;
DEALLOCATE PREPARE add_col;

-- 11. Forum Threads — a topic under a category. First post in web_forum_posts
-- is the thread body (is_op=1); subsequent rows are replies.
CREATE TABLE IF NOT EXISTS web_forum_threads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  slug VARCHAR(180) NOT NULL,
  title VARCHAR(200) NOT NULL,
  author_id INT NOT NULL,
  author_name VARCHAR(50) NOT NULL,
  status ENUM('pending','published','hidden') NOT NULL DEFAULT 'pending',
  is_sticky TINYINT NOT NULL DEFAULT 0,
  is_locked TINYINT NOT NULL DEFAULT 0,
  view_count INT NOT NULL DEFAULT 0,
  reply_count INT NOT NULL DEFAULT 0,
  last_reply_at DATETIME DEFAULT NULL,
  last_reply_by VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_thread_slug (slug),
  INDEX idx_category (category_id, is_sticky, last_reply_at),
  INDEX idx_status (status),
  INDEX idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Forum Posts — every message in every thread. is_op=1 marks the thread
-- body (the OP), is_op=0 marks replies. status mirrors the thread approval
-- flow so individual replies can be moderated independently.
CREATE TABLE IF NOT EXISTS web_forum_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  thread_id INT NOT NULL,
  author_id INT NOT NULL,
  author_name VARCHAR(50) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  status ENUM('pending','published','hidden') NOT NULL DEFAULT 'pending',
  is_op TINYINT NOT NULL DEFAULT 0,
  edited_at DATETIME DEFAULT NULL,
  edited_by VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_thread (thread_id, created_at),
  INDEX idx_status (status),
  INDEX idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Forum Bans — forum-only mute (does not affect game login). account_id
-- is unique so a user is either banned or not. expires_at NULL = permanent.
CREATE TABLE IF NOT EXISTS web_forum_bans (
  account_id INT NOT NULL PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  banned_by VARCHAR(50) NOT NULL,
  reason VARCHAR(500) DEFAULT NULL,
  banned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME DEFAULT NULL,
  INDEX idx_username (username),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Donation Codes — one reusable attribution code per account. The user
-- pastes this code into the Ko-fi message; the webhook extracts it to know
-- which account.dp to credit. One row per account (account_id is PK), code
-- is globally unique. Codes never expire and are reused across donations.
CREATE TABLE IF NOT EXISTS web_donation_codes (
  account_id INT NOT NULL PRIMARY KEY,
  code VARCHAR(20) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Donation Log — audit trail + idempotency for Ko-fi webhook deliveries.
-- `kofi_transaction_id` is UNIQUE: Ko-fi may re-deliver the same webhook, and
-- the UNIQUE key is the replay-protection guarantee (a duplicate INSERT fails
-- and the credit is skipped). `account_id` NULL = unattributed (donor didn't
-- paste a valid code) — kept for manual GM resolution via the admin panel.
-- `status`: credited = DP added; unattributed = logged, no DP; ignored =
-- received but deliberately not credited (e.g. amount below minimum).
CREATE TABLE IF NOT EXISTS web_donation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kofi_transaction_id VARCHAR(64) NOT NULL,
  account_id INT DEFAULT NULL,
  username VARCHAR(50) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(8) DEFAULT NULL,
  dp_credited INT NOT NULL DEFAULT 0,
  kofi_type VARCHAR(32) DEFAULT NULL,
  from_name VARCHAR(100) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  status ENUM('credited','unattributed','ignored') NOT NULL DEFAULT 'credited',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_kofi_txn (kofi_transaction_id),
  INDEX idx_account (account_id),
  INDEX idx_created (created_at),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. Shop Settings — single-row (id is always 1) admin-managed shop settings.
-- Currently holds the Battle Coins per 1.00 of Ko-fi currency exchange rate,
-- editable from /admin_shop. NO seed row: when the row is absent the effective
-- rate falls back to config.donation.eur_to_dp_rate, so config remains the
-- documented bootstrap default and the DB row is the admin's UI override.
CREATE TABLE IF NOT EXISTS web_shop_settings (
  id TINYINT NOT NULL PRIMARY KEY,
  eur_to_dp_rate INT NOT NULL DEFAULT 1000,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. Site Settings — generic key → JSON store for admin-editable site
-- customization (footer links now; home-page sections / theming later).
-- NO seed: when a key is absent the resolver falls back to config.php /
-- built-in defaults, so config.php stays the bootstrap and the DB row is
-- purely the admin override (same pattern as the donation-rate override).
-- Secrets/bootstrap (db, smtp, recaptcha, base_url, feature flags) are NEVER
-- stored here — they remain file-only in config.php.
CREATE TABLE IF NOT EXISTS web_site_settings (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a default "General Discussion" category on first install. Idempotent —
-- the INSERT is guarded by NOT EXISTS so it only fires when the table is
-- completely empty (admins who delete it won't see it reappear on re-run).
INSERT INTO web_forum_categories (slug, name, description, icon, sort_order)
SELECT * FROM (SELECT
  'general'                                                                                  AS slug,
  'General Discussion'                                                                       AS name,
  'Anything related to the server — introductions, screenshots, questions, and chatting.'   AS description,
  'bi-chat-dots'                                                                             AS icon,
  0                                                                                          AS sort_order
) seed
WHERE NOT EXISTS (SELECT 1 FROM web_forum_categories LIMIT 1);

-- Seed an example thread under the default category on first install.
-- Fires only when web_forum_threads is empty AND the category exists.
INSERT INTO web_forum_threads (category_id, slug, title, author_id, author_name, status, last_reply_at, last_reply_by)
SELECT c.id,
       'welcome-to-the-forum',
       'Welcome to the forum!',
       0,
       'Admin',
       'published',
       NOW(),
       'Admin'
FROM web_forum_categories c
WHERE c.slug = 'general'
  AND NOT EXISTS (SELECT 1 FROM web_forum_threads LIMIT 1);

-- Seed the OP post (first message) for the example thread. Keyed off the
-- thread's slug + is_op=1 so re-running setup.sql never duplicates it,
-- even if the example thread is recreated after deletion.
INSERT INTO web_forum_posts (thread_id, author_id, author_name, body, status, is_op)
SELECT t.id,
       0,
       'Admin',
       CONCAT(
         '## Welcome to the forum!\n\n',
         'This is your community space. Share screenshots, ask questions, suggest features, or just say hi.\n\n',
         '### A few quick notes\n\n',
         '- **Markdown is supported** — use the editor toolbar for bold, italic, lists, links, and images.\n',
         '- **Be kind.** Treat everyone the way you''d want to be treated.\n',
         '- **GMs can edit or delete posts**, and may forum-ban users without affecting their game account.\n\n',
         'Have fun!'
       ),
       'published',
       1
FROM web_forum_threads t
WHERE t.slug = 'welcome-to-the-forum'
  AND NOT EXISTS (
    SELECT 1 FROM web_forum_posts p WHERE p.thread_id = t.id AND p.is_op = 1
  );

-- Seed one starter post the first time setup.sql is run on a fresh install.
-- Idempotent: the INSERT is guarded by NOT EXISTS so it never re-fires once
-- the table has any rows (so re-running setup.sql, or deleting the seed
-- post and re-running, never resurrects it).
INSERT INTO web_news_posts (slug, title, excerpt, body, icon, author_name, status, published_at)
SELECT * FROM (SELECT
  'welcome-to-your-new-portal'                                       AS slug,
  'Welcome!'                                                          AS title,
  'Your registration portal is up and running.'                       AS excerpt,
  CONCAT(
    '## Welcome to your new server portal!\n\n',
    'This is the first post in your news section. From the **Admin Panel → News** tab you can:\n\n',
    '- Edit or delete this post\n',
    '- Write new posts in Markdown\n',
    '- Save drafts before publishing\n',
    '- Pick a Bootstrap Icon for each post card\n\n',
    'Have fun, and good luck with launch!'
  )                                                                   AS body,
  'bi-megaphone'                                                      AS icon,
  'Admin'                                                             AS author_name,
  'published'                                                         AS status,
  NOW()                                                               AS published_at
) seed
WHERE NOT EXISTS (SELECT 1 FROM web_news_posts LIMIT 1);
