# Installation

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — manual setup, requirements, database, feature flags.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

---

## Requirements

### Recommended: XAMPP

The easiest way to run this project is with [**XAMPP**](https://www.apachefriends.org/), which bundles **Apache** and **PHP** in a single installer. Your repack already provides its own MySQL database, so you only need XAMPP for the web server.

| Requirement | Minimum | Recommended |
|---|---|---|
| **PHP** | 7.4 | 8.0+ |
| **Apache** | with `mod_rewrite` enabled | Included in XAMPP |
| **Composer** | 2.x | Latest stable |

### PHP Extensions

The following extensions must be enabled in `php.ini`. In XAMPP, open `C:\xampp\php\php.ini`, search for each extension, remove the leading `;` to uncomment it, then **restart Apache**.

| Extension | Purpose |
|---|---|
| `pdo_mysql` | Database access |
| `openssl` | SMTP TLS/SSL for emails |
| `mbstring` | String handling |
| `hash` | SHA-1 password hashing *(enabled by default in PHP 8)* |
| `curl` | Recommended for reCAPTCHA and outbound HTTP requests |
| `gmp` | Big number math *(optional, for SRP6)* |
| `fileinfo` | MIME type checking for ticket attachments |

---

## Installation

### 1. Download

Clone the repo or download the ZIP, then serve it from your Apache site root:

```
C:\xampp\htdocs\
```

> [!IMPORTANT]
> This project currently uses root-relative URLs such as `/login`, `/register`, `/assets/...` and `.htaccess` rules that assume the app is mounted at the web root. If you keep the repo in a subfolder like `C:\xampp\htdocs\wow-legends`, configure an Apache `VirtualHost` or `Alias` so that folder is served as its own site root.

### 2. Configure

Copy `config.sample.php` to `config.php`:

```
config.sample.php  →  config.php
```

Open `config.php` and set:

| Setting | Description |
|---|---|
| **Database** | MySQL host, user, password, auth DB name, characters DB name |
| **Realm** | Realmlist address, realm name, expansion ID, server ports |
| **Site** | Site title, base URL |
| **reCAPTCHA** | Site key + secret from [Google reCAPTCHA](https://www.google.com/recaptcha) (v2 Checkbox) |
| **SMTP** | Email host, port, credentials for password recovery and ticket notifications |
| **Client** | External download link for the game client (Mega, MediaFire, etc.) |
| **Social Links** | Discord, YouTube, X (Twitter), Instagram URLs — leave empty to hide |
| **FAQ** | Array of question/answer pairs for the FAQ accordion |
| **Vote Sites** | Array of vote site links shown on the user dashboard |

> [!TIP]
> Common local DB credentials are often `host=127.0.0.1`, `user=root`, `password=ascent`, but database names vary by repack. Some installs use `auth` / `characters`, while others use `mop_auth` / `mop_characters`.

> [!IMPORTANT]
> Set `site.base_url` to the exact URL where the app is reachable. Password recovery emails build reset links from this value.

### 3. Database Setup

> [!IMPORTANT]
> **The ticket system, admin audit log, and password recovery require extra tables in your `auth` database.** Without these tables, those features will show database errors.

Open **phpMyAdmin** (your repack's DB manager), select the **`auth`** database, go to the **SQL** tab, and run the contents of `sql/setup.sql`:

```sql
-- All portal-owned tables use a `web_` prefix so they're visually distinct
-- from the worldserver's own auth/character/world tables in the same DB.
-- Pre-v0.7.x installs with un-prefixed names are auto-renamed in place
-- (RENAME TABLE preserves data + indexes — no rebuild).
--
-- This creates 17 tables (plus idempotent column-add and charset migrations):
--  1. web_password_resets     — password recovery tokens
--  2. web_tickets             — support ticket headers (subject, category, status…)
--  3. web_admin_audit_log     — chronological log of admin actions
--  4. web_playtime_rewards    — per-account state for the Battle Pay reward feature
--  5. web_playtime_reward_log — audit trail for every Battle Pay claim
--  6. web_ticket_messages     — per-message thread (user + admin replies, attachments)
--  7. web_news_posts          — admin-authored blog/news (Markdown body, draft/published)
--  8. web_user_avatars        — per-account uploaded avatars (one row when uploaded)
--  9. web_forum_settings      — single-row forum config (enabled + auto-approve threshold)
-- 10. web_forum_categories    — one level (no sub-categories), slug + name + icon + sort
-- 11. web_forum_threads       — title + author + status + sticky/locked + view/reply counts
-- 12. web_forum_posts         — every message (OP + replies), status, edited_at/by
-- 13. web_forum_bans          — forum-only mutes (game login unaffected); expires_at NULL = permanent
-- 14. web_donation_codes      — per-account Ko-fi attribution code
-- 15. web_donation_log        — Ko-fi webhook audit + idempotency
-- 16. web_shop_settings       — admin-editable shop exchange rate
-- 17. web_site_settings       — generic key → JSON store for site customization

-- Compatible with MySQL 5.5.9+
-- All CREATEs use IF NOT EXISTS. Idempotent migration blocks handle:
--   - Legacy un-prefixed → web_ rename (only fires when old name exists)
--   - web_ticket_messages.attachments column add (INFORMATION_SCHEMA-checked)
--   - utf8 → utf8mb4 conversion for the legacy tables (only fires when needed)
-- so re-running setup.sql is always safe.
-- See sql/setup.sql for the full script.
```

You can also run it from the command line:

```bash
mysql -u root -pascent auth < sql/setup.sql
```

The script uses `CREATE TABLE IF NOT EXISTS`, so it's safe to run multiple times.

### 4. Feature Flags

The `features` block in `config.php` lets you toggle features without touching code:

```php
'features' => [
    'recaptcha'        => true,   // reCAPTCHA on all forms
    'recover_password' => true,   // Password recovery via email
    'tickets'          => true,   // Support ticket system
    'maintenance'      => false,  // Maintenance mode (GMs can still log in)
],

// Brute-force lockout settings (file-based, no DB table needed)
'security' => [
    'max_login_attempts' => 5,   // Failed attempts before lockout
    'lockout_minutes'    => 15,  // Duration of lockout in minutes
],

// Message shown during maintenance
'maintenance_message' => 'The server is currently under maintenance.',
```

| Flag | When `false` |
|---|---|
| `recaptcha` | reCAPTCHA widget hidden, JS not loaded, server-side check bypassed |
| `recover_password` | `/recover` and `/reset_password` redirect to `/login`; link hidden |
| `tickets` | `/tickets` redirects to `/dashboard`; menu item hidden |
| `maintenance` | All pages show a maintenance screen (GMs with level ≥ 9 are exempt) |

### 5. Social Links & Content

Social links appear in the hero section and footer. Set to empty string `''` to hide any link:

```php
'social' => [
    'discord'   => 'https://discord.gg/your-invite',
    'youtube'   => '',   // hidden when empty
    'twitter'   => '',
    'instagram' => '',
],
```

FAQ items and vote sites are configured in `config.php`:

```php
'faq' => [
    ['q' => 'Is it free to play?', 'a' => 'Yes, 100% free.'],
],

'vote_sites' => [
    ['name' => 'TopG', 'url' => 'https://topg.org/...', 'cooldown_hours' => 12],
],
```

> [!NOTE]
> News is stored in the `web_news_posts` database table and managed from the admin panel at `/admin_news`. A starter "Welcome!" post is seeded by `sql/setup.sql` on a fresh install so the news section is never empty out of the gate — edit or delete it from the admin UI. There is no `config.news` entry; everything lives in the DB.

### 6. Dependencies

`vendor/` (PHPMailer + Parsedown) **is committed to the repo** so that fresh clones and the one-click installer work out of the box without requiring Composer locally. You should not need to run `composer install` for a normal install.

If you ever want to refresh dependencies (e.g. after pulling an upstream upgrade) or your `vendor/` is missing for some reason:

```bash
composer install --no-dev
```

### 7. Enable mod_rewrite

Pretty URLs (`/login`, `/register`) require Apache's `mod_rewrite`:

1. Open `C:\xampp\apache\conf\httpd.conf`
2. Find and uncomment: `LoadModule rewrite_module modules/mod_rewrite.so`
3. Find your `<Directory>` block and set `AllowOverride All`
4. Restart Apache

---


---

[↑ Back to the README](../README.md)
