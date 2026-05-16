# WoW Mists of Pandaria Registration Portal

A complete, secure, and modern registration portal for **World of Warcraft: Mists of Pandaria (5.4.8)** private servers. Built for TrinityCore-based cores (including repacks).

🌐 **Live demo:** [wow-legends.eu](https://wow-legends.eu/) — every feature you see in this README is running there.

![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue) ![Bootstrap 5](https://img.shields.io/badge/Bootstrap-5-purple) ![License: MIT](https://img.shields.io/badge/License-MIT-green) ![Status: In Development](https://img.shields.io/badge/status-In%20Development-orange) ![GitHub Release](https://img.shields.io/github/v/release/timoinglin/wow-mop-registration?label=release&color=8B4513) [![Live Demo](https://img.shields.io/badge/demo-wow--legends.eu-c8a96e?logo=globe)](https://wow-legends.eu/)

> ⚠️ **Active Development** — This portal is still evolving. Features land in `main` regularly. Pin a release tag if you need stability, or follow the [How to Update](#how-to-update) section to stay current.

> 💛 **Enjoying the portal?** It's free and open-source, built and maintained in spare time. If it saved you hours of setup or you'd like to see it keep growing, a coffee genuinely helps — see [Support the Project](#support-the-project).
>
> [![Support the project on Ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/kneuma)

## Table of Contents

- [Features](#features)
- [Preview](#preview)
  - [Home Page](#home-page)
  - [User Dashboard](#user-dashboard)
  - [Public Armory](#public-armory)
  - [Leaderboards](#leaderboards)
  - [Custom 404 Page](#custom-404-page)
  - [Forum — Thread Page](#forum--thread-page)
  - [Forum — Admin Configuration](#forum--admin-configuration)
  - [Admin Dashboard - Overview](#admin-dashboard---overview)
  - [Admin Dashboard - Accounts](#admin-dashboard---accounts)
  - [Admin Dashboard - Tickets](#admin-dashboard---tickets)
- [Quick Start](#quick-start)
- [One-Click Installer](#one-click-installer)
- [Release Packaging For The Installer](#release-packaging-for-the-installer)
- [Requirements](#requirements)
  - [Recommended: XAMPP](#recommended-xampp)
  - [PHP Extensions](#php-extensions)
- [Installation](#installation)
  - [1. Download](#1-download)
  - [2. Configure](#2-configure)
  - [3. Database Setup](#3-database-setup)
  - [4. Feature Flags](#4-feature-flags)
  - [5. Social Links & Content](#5-social-links--content)
  - [6. Dependencies](#6-dependencies)
  - [7. Enable mod_rewrite](#7-enable-mod_rewrite)
- [How to Update](#how-to-update)
  - [Upgrading from v0.3.x → v0.4.0](#upgrading-from-v03x--v040)
  - [Upgrading from v0.4.x → v0.5.0](#upgrading-from-v04x--v050)
- [Admin Dashboard](#admin-dashboard)
  - [Managing News](#managing-news)
  - [Managing the Forum](#managing-the-forum)
- [Customization](#customization)
  - [Changing Text and Labels](#changing-text-and-labels)
  - [Replacing Images and Logo](#replacing-images-and-logo)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Support the Project](#support-the-project)
- [License](#license)

## Features

- 🔒 **Security** — CSRF tokens, Google reCAPTCHA v2, PDO prepared statements, PHP-execution blocking on uploads
- 🛡️ **Rate Limiting** — Automatic lockout after failed login attempts (configurable)
- 🗝️ **Auth** — SHA-1 password hashing matching the TrinityCore format
- 📧 **Email** — SMTP password recovery and ticket notifications via PHPMailer
- 📊 **Live Stats** — Real-time server status, player counts, and animated counters
- 🌍 **Multilingual** — English and Spanish included; easy to add more
- 🎨 **Modern UI** — Dark gaming theme, Bootstrap 5, responsive design
- ⚙️ **Feature Flags** — Toggle tickets, password recovery, reCAPTCHA, and maintenance mode from config
- 🧑‍💼 **Admin Dashboard** — Account management, ban/unban, ticket management, audit log, character lookup, IP bans, email broadcast
- 🔍 **Public Armory** — Search any character on the realm; profile pages with equipped gear (Wowhead tooltips), stats, achievements, and account-mate links
- 🏆 **Leaderboards** — Top players by level, playtime, gold, PvP kills, and achievements, plus top guilds — with faction filters and gold/silver/bronze top-3 styling
- 💎 **Playtime Reward** — Auto-grants Battle Pay (DP) for time spent in-game; configurable hourly rate + daily cap. AFK still counts, login/logout farming doesn't.
- 💀 **Custom 404 Page** — Themed "You died." page with floating Spirit Healer art and a hidden murloc easter egg
- 🔗 **OG / Twitter Cards** — Rich previews when sharing armory and leaderboard links on Discord, Twitter, etc.
- 🎫 **Ticket System** — Multi-turn conversation threads (user ↔ GM), Markdown formatting, image attachments with auth-gated serving, separate detail pages, and audit-logged status changes
- 📰 **News / Blog** — Admin-managed posts with a GitHub-style **EasyMDE editor** (toolbar with bold/headings/lists/links/tables, drag-and-drop image upload, side-by-side preview, fullscreen mode). Posts are stored as Markdown in the DB, render server-side through Parsedown safe mode, have draft/published states with windowed pagination, and feed both the public `/news` list / `/news/{slug}` detail pages and the homepage "Latest Updates" section. A starter "Welcome!" post is seeded by `sql/setup.sql` on first install.
- 💬 **Forum** — Lean community forum with categories, threads, replies, **per-user approval workflow** (auto-publish kicks in after N approved posts; configurable, 0 = auto-publish everyone), forum-only bans that don't affect game login, EasyMDE composer with image uploads, sticky/locked threads, inline GM moderation (approve / delete / lock / sticky on the thread itself), per-session view de-dupe, and a 30-second anti-spam cooldown. Disabled by default — admin flips one toggle to expose `/forum` in the navbar.
- 👤 **User Avatars** — Each user can upload an avatar from the dashboard. When no avatar is uploaded, a deterministic colored-initials badge is rendered (no external service like Gravatar). Avatars appear on the dashboard, the navbar dropdown, and next to every forum post.
- ❓ **FAQ** — Configurable FAQ accordion on the home page
- 🗳️ **Vote System** — Vote site links on the user dashboard (configurable)
- 🔗 **Social Links** — Discord, YouTube, X (Twitter), Instagram — each individually toggleable

---

## Preview

> 💡 Screenshots below — or [**view it running live at wow-legends.eu →**](https://wow-legends.eu/)

### Home Page
![Home Page](assets/img/screenshots/home.png)

### User Dashboard
![User Dashboard](assets/img/screenshots/user.png)

### Public Armory
![Public Armory](assets/img/screenshots/armory.png)

### Leaderboards
![Leaderboards](assets/img/screenshots/leaderboards.png)

### Custom 404 Page
*"You died." — your players will never feel less lost. With a hidden murloc easter egg.*

![404 Page](assets/img/404-spirit-healer.png)

### Forum — Thread Page
*A thread on `/forum/{category}/{thread}` — same page everyone sees, but viewed as a GM 9+ admin so the **Mod tools** row (Approve / Sticky / Lock / Delete) appears in the hero, alongside per-post Edit and Delete links.*

![Forum Thread Page](assets/img/screenshots/forum_user.png)

### Forum — Admin Configuration
*The full forum admin page at `/admin_forum` — Moderation Queue (pending threads + replies with Approve / Reject), Settings (enable toggle + auto-approve threshold), Categories CRUD, and Forum Bans.*

![Forum Admin Configuration](assets/img/screenshots/forum_admin.png)

### Admin Dashboard - Overview
![Admin Overview](assets/img/screenshots/admin1.png)

### Admin Dashboard - Accounts
![Admin Accounts](assets/img/screenshots/admin-accounts.png)

### Admin Dashboard - Tickets
![Admin Tickets](assets/img/screenshots/admin-tickets.png)

---

## Quick Start

```bash
# 1. Install XAMPP — https://www.apachefriends.org/
# 2. Serve this project from your Apache web root or a dedicated VirtualHost/Alias
#    Do not run it from a subfolder such as /wow-legends without remapping DocumentRoot

# 3. Copy the sample config
copy config.sample.php config.php

# 4. Edit config.php with your DB credentials, realm info, site base URL, reCAPTCHA keys, etc.

# 5. Run the SQL setup (see Database Setup section below)

# 6. Start Apache from the XAMPP Control Panel (your repack already runs its own MySQL)

# 7. Visit the URL configured in config.php, for example http://localhost/
#
# Note: vendor/ (PHPMailer, Parsedown) is committed to the repo, so no
# `composer install` step is needed for a normal install.
```

---

## One-Click Installer

Windows users can bootstrap a guided installer with PowerShell:

Open PowerShell **as Administrator**, then run:

```powershell
irm "https://raw.githubusercontent.com/timoinglin/wow-mop-registration/main/install.ps1" | iex
```
<img src="assets/img/screenshots/oneclick_installer.png" width="780" alt="One-Click Installer Screenshot">

Before running it, make sure your WoW repack is already installed and its database server is already running. The installer sets up the website and local web server; it does not install or start the repack itself.

If you do not already have a repack, you can get one from [EmuCoach](https://www.emucoach.com/).

What the installer does:

1. Checks that it is running as Administrator.
2. Shows the prerequisites and asks whether to continue before making any changes.
3. Checks whether XAMPP already exists and lets you choose whether to reuse it or stop.
4. Installs XAMPP 8.2 with `winget` when needed.
5. Downloads the latest prepared release ZIP and deploys it into `C:\xampp\htdocs\`.
6. Enables the required PHP extensions in XAMPP's `php.ini`.
7. Creates `config.php` from `config.sample.php` with safe defaults.
8. Prompts for database credentials, validates the MySQL server connection, verifies that the entered auth/characters database names exist, and offers to import `sql/setup.sql`.
9. Starts Apache and opens `http://localhost/`.

The installer intentionally disables advanced features in the generated `config.php` so the site can come up cleanly on first run:

- `recaptcha`
- `recover_password`
- `tickets`

It also sets `site.base_url` to `http://localhost` and leaves social/client links empty.

After the installer finishes, open `config.php` and add your reCAPTCHA keys and SMTP settings before enabling those features.

> [!WARNING]
> The installer is designed for a fresh local XAMPP setup. If `C:\xampp\htdocs\` already contains files, it offers to back them up and then replaces the web root contents so the app can run at `http://localhost/`.

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
-- This creates 13 tables (plus idempotent column-add and charset migrations):
-- 1. password_resets       — password recovery tokens
-- 2. tickets               — support ticket headers (subject, category, status…)
-- 3. admin_audit_log       — chronological log of admin actions
-- 4. playtime_rewards      — per-account state for the Battle Pay reward feature
-- 5. playtime_reward_log   — audit trail for every Battle Pay claim
-- 6. ticket_messages       — per-message thread (user + admin replies, attachments)
-- 7. news_posts            — admin-authored blog/news (Markdown body, draft/published)
-- 8. user_avatars          — per-account uploaded avatars (one row when uploaded)
-- 9. forum_settings        — single-row forum config (enabled + auto-approve threshold)
-- 10. forum_categories     — one level (no sub-categories), slug + name + icon + sort
-- 11. forum_threads        — title + author + status + sticky/locked + view/reply counts
-- 12. forum_posts          — every message (OP + replies), status, edited_at/by
-- 13. forum_bans           — forum-only mutes (game login unaffected); expires_at NULL = permanent

-- Compatible with MySQL 5.5.9+
-- All CREATEs use IF NOT EXISTS. Idempotent migration blocks handle:
--   - ticket_messages.attachments column add (INFORMATION_SCHEMA-checked)
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
> News is stored in the `news_posts` database table and managed from the admin panel at `/admin_news`. A starter "Welcome!" post is seeded by `sql/setup.sql` on a fresh install so the news section is never empty out of the gate — edit or delete it from the admin UI. There is no `config.news` entry; everything lives in the DB.

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

## How to Update

Already running and want the latest version? **Keep your existing `config.php`, `uploads/`, and `cache/`** — everything else can be replaced.

> [!IMPORTANT]
> Before updating, back up `config.php` and the `uploads/` folder. Both are gitignored, so `git pull` won't touch them — but a backup is cheap insurance in case you need to roll back.

### Option A — One-Click Updater (recommended, Windows)

Run this from **inside your website folder** in PowerShell (no admin needed):

```powershell
cd C:\xampp\htdocs\wow-legends   # your install folder
irm "https://raw.githubusercontent.com/timoinglin/wow-mop-registration/main/update.ps1" -OutFile update.ps1
.\update.ps1
```

The updater is fully guided and safe-by-default. It will:

1. **Verify** the folder really is a WoW Legends install (fingerprint check).
2. **Stop Apache** automatically (one keypress) — or wait until you stop it.
3. **Make a FULL zip backup of the entire website folder** to a sibling folder (`..\wow-legends-backup-<timestamp>.zip`) **before touching anything**, plus a quick `config.php`/`uploads/`/`cache/` copy-aside for fast rollback.
4. Download the **latest release** and overwrite program files only — `config.php`, `uploads/`, `cache/` and `.git/` are never touched (it uses `robocopy` without purge, and the release archive contains tracked files only).
5. Apply `sql/setup.sql` (idempotent) using the **bundled XAMPP PHP** (no `mysql` client needed).
6. **Report any new `config.php` keys** to add by hand (it never auto-rewrites your config).
7. Restart Apache, open the site, and show `old → new` version.

> [!TIP]
> The full backup zip exists because many admins customise shipped files **in place** — logo, background images, `assets/bg-video-mop.mp4`, theme CSS, `lang/*.php`. A clean update replaces those; the zip is your guaranteed recovery point. Delete it once the updated site looks good.

### Option B — Git

If you cloned with Git originally:

```bash
# 1. Stop Apache from the XAMPP Control Panel

# 2. Pull the latest files (this brings in updated vendor/ too — it's tracked)
git pull origin main

# 3. Re-run the SQL setup — it's idempotent (CREATE TABLE IF NOT EXISTS)
mysql -u root -pascent auth < sql/setup.sql

# 4. Restart Apache and you're done
```

### Option C — Manual (release ZIP)

If you installed by extracting a release ZIP:

1. **Stop Apache.**
2. **Back up** `config.php` and the `uploads/` folder.
3. **Delete** the old project files **except** `config.php`, `uploads/`, and `cache/`.
4. **Extract** the new release ZIP into the same folder, letting it overwrite everything else (the ZIP includes `vendor/`).
5. **Re-run** `sql/setup.sql` on your `auth` database — safe to run multiple times.
6. **Restart Apache.**

### What carries over automatically

| File / Folder | Why it's preserved |
|---|---|
| `config.php` | DB credentials, realm info, social links, news, FAQ, vote sites, feature flags |
| `uploads/` | User avatars, news/forum images, ticket attachments |
| `cache/` | Login history and rate-limit data |
| `.git/` | Left intact for git-cloned installs (bonus rollback path) |

### Worth knowing

- New features may add **new keys** to `lang/en.php` and `lang/es.php`. Missing keys fall back to English automatically — updating is non-breaking, but translations may show English until you copy in the new keys (or just update both `lang/` files from the new release).
- New SQL columns or tables, when introduced, are added to `sql/setup.sql` with `IF NOT EXISTS` patterns, so re-running it is always safe.
- Check the [release notes](https://github.com/timoinglin/wow-mop-registration/releases) for any version-specific upgrade steps.

---

### Upgrading from v0.3.x → v0.4.0

v0.4.0 introduces the **News / Blog system**, which replaces the legacy `config.news` array with a database-backed editor (EasyMDE), public `/news` pages, image uploads, and a windowed pager. None of the existing v0.3.x features were removed.

Follow the three steps below in order. Total time: ~5 minutes.

#### 1. Pull the new code

```bash
# stop Apache first (XAMPP Control Panel → Stop)
git pull origin main
# (or extract the v0.4.0 release ZIP, overwriting everything except
#  config.php, uploads/, cache/)
```

#### 2. Run the SQL setup (required)

The new `news_posts` table must be created in your `auth` database, and a starter "Welcome!" post is seeded on first run:

```bash
mysql -u root -pascent auth < sql/setup.sql
```

`sql/setup.sql` is idempotent:
- The `CREATE TABLE news_posts (...)` uses `IF NOT EXISTS` so re-running on an existing install does nothing destructive.
- The seed `INSERT` is guarded by `WHERE NOT EXISTS (SELECT 1 FROM news_posts LIMIT 1)`, so it only fires when the table is empty. If you delete the seed post from the admin UI later, re-running setup.sql won't resurrect it.

#### 3. Make sure `uploads/news/` exists (required)

The admin editor uploads post images to `uploads/news/`. The release ships a tracked `uploads/news/.htaccess` (override that makes the dir publicly readable while still blocking script execution) — if you used `git pull` or extracted the new ZIP, this is already in place. Verify with:

```bash
ls uploads/news/.htaccess
```

If it's missing for any reason, create the dir and add this `.htaccess`:

```apache
<RequireAll>
    Require all granted
</RequireAll>

<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh|html?)$">
    Require all denied
</FilesMatch>

Options -Indexes -ExecCGI
```

#### 4. (Optional) Clean up `config.news`

The `'news' => [...]` array in `config.php` is **no longer read** — v0.4.0 sources news exclusively from the `news_posts` table. You can:

- **Leave it alone** — it's silently ignored. No harm done.
- **Delete it** for a cleaner config file (recommended).
- **Migrate existing entries** — if your `config.news` array had real news posts you want to keep, log in as a GM 9+ admin, go to `/admin_news`, and copy each entry into a new post (~10 seconds per entry).

#### 5. Restart Apache + verify

```bash
# Start Apache from the XAMPP Control Panel
```

Then in a browser:

1. Visit `/news` — you should see the seed "Welcome!" post (plus any you migrated).
2. Visit the homepage — the "Latest Updates" section should pull from the DB now, with a "View All News" CTA underneath.
3. Log in as a GM 9+ account, click **Admin Panel → News** tab — the new "Manage News" link takes you to `/admin_news`. Click "New Post" to open the EasyMDE editor.
4. On any public news article you'll also see a small **Edit Post** button (admins only).

#### What's new at a glance

| Area | Change |
|---|---|
| Database | New table `news_posts` (+ idempotent seed) |
| Filesystem | New dir `uploads/news/` (image uploads, tracked `.htaccess`) |
| Routing | New `.htaccess` rule: `/news/{slug}` → `pages/news.php?slug={slug}` |
| Pages | `pages/news.php`, `pages/admin_news.php`, `pages/news_image.php`, `pages/news_preview.php` |
| Helpers | `includes/news.php` (slug, fetchers) |
| Admin UI | New **News** tab in `/admin_dashboard` |
| Public UI | New `/news` list (paginated, 9/page, windowed pager) + `/news/{slug}` detail |
| Homepage | "Latest Updates" section reads 3 newest published posts from DB; new "View All News" CTA |
| Config | `config.news` deprecated (still ignored gracefully if left) |
| i18n | ~90 new keys in `lang/en.php` and `lang/es.php` |
| External | EasyMDE editor loads from jsDelivr CDN (only on `/admin_news` edit form) |

> [!NOTE]
> The EasyMDE editor is loaded from a public CDN (`cdn.jsdelivr.net`). The admin form needs internet access to render it — the **public** news pages don't depend on the CDN at all (they're rendered server-side through Parsedown).

---

### Upgrading from v0.4.x → v0.5.0

v0.5.0 introduces the **Forum** system, **user avatars**, and an internal **charset migration** for the legacy tables. Forum stays disabled by default — flip one toggle when you're ready to expose it.

Follow the steps in order. Total time: ~5 minutes (the charset conversion runs once on existing data and is sub-second for typical installs).

#### 1. Pull the new code

```bash
# stop Apache first (XAMPP Control Panel → Stop)
git pull origin main
# (or extract the v0.5.0 release ZIP, overwriting everything except
#  config.php, uploads/, cache/)
```

#### 2. Run the SQL setup (required)

`sql/setup.sql` adds 6 new tables (`user_avatars` + the 5 `forum_*` tables) and runs a one-time charset migration on the original 6 legacy tables to upgrade them from `utf8` (alias for utf8mb3) to `utf8mb4`:

```bash
mysql -u root -pascent auth < sql/setup.sql
```

Idempotent — safe to re-run:
- `CREATE TABLE IF NOT EXISTS` for every new table.
- The settings + default-category + welcome-thread seeds are guarded by `WHERE NOT EXISTS`, so admins who delete them won't see them resurrected on a re-run.
- The charset migration only fires on tables not already at utf8mb4 (INFORMATION_SCHEMA-checked). A second run of the entire script measures ~3 ms total.

> [!NOTE]
> The charset migration rewrites the legacy tables in place (`ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4`). For a typical install with thousands of rows it finishes in under a second. The only edge case is **very old MySQL with InnoDB ANTELOPE row format** where the `password_resets.idx_email` index could exceed the 767-byte prefix limit at utf8mb4 — modern XAMPP / MariaDB 10.2+ / MySQL 5.7.7+ default to DYNAMIC row format, which supports 3072 bytes, so it just works. If your install hits the edge case, the ALTER errors cleanly with no data loss; fix is `ALTER TABLE password_resets DROP INDEX idx_email, ADD INDEX idx_email (email(191))` and re-run setup.sql.

#### 3. Make sure `uploads/avatars/` and `uploads/forum/` exist (required)

Both new directories have tracked override `.htaccess` files that allow public reads (so images render on the site) while still blocking script extensions. `git pull` / fresh ZIP extraction puts them in place automatically — verify with:

```bash
ls uploads/avatars/.htaccess uploads/forum/.htaccess
```

If either is missing, create the dir and add this `.htaccess`:

```apache
<RequireAll>
    Require all granted
</RequireAll>

<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh|html?)$">
    Require all denied
</FilesMatch>

Options -Indexes -ExecCGI
```

#### 4. (Optional) Decide if you want the forum exposed

The forum table seeds with `enabled = 0`, so even after running the SQL the public `/forum` URL stays invisible. To turn it on:

1. Log in as GM 9+
2. Visit **Admin Panel → Forum tab → Configure Forum**
3. Flip **Forum enabled** to on, set the **auto-approve threshold** (default 3; `0` = auto-publish everyone)
4. The **Forum** link will appear in the main nav for everyone

You can also leave it disabled, browse it yourself as an admin (`/forum` is admin-previewable when off), populate some categories, and only enable it when you're ready.

#### 5. Restart Apache + verify

Then in a browser:

1. Visit `/dashboard` → click your avatar in the hero (or in the navbar dropdown) → upload an image. Refresh — your avatar appears across the site.
2. Visit `/admin_forum` → the seeded "General Discussion" category + "Welcome to the forum!" thread are there. Edit the welcome thread or delete it.
3. Toggle the forum on. The nav link appears. Visit `/forum` as a regular user → category index. Click in → thread list → thread detail. Reply, see the approval-queue flow if you're below the threshold.
4. As a GM: open any thread → Mod tools row in the hero (Approve / Sticky / Lock / Delete) + Approve/Delete links on individual pending posts.

#### What's new at a glance

| Area | Change |
|---|---|
| Database | 6 new tables (`user_avatars`, `forum_settings`, `forum_categories`, `forum_threads`, `forum_posts`, `forum_bans`) + idempotent seeds (forum settings row, default category, welcome thread + OP post) |
| Schema migrations | One-time `utf8` → `utf8mb4` charset upgrade on the 6 legacy tables, INFORMATION_SCHEMA-checked so re-runs are no-ops (~3 ms total) |
| Filesystem | New dirs `uploads/avatars/` and `uploads/forum/` (each with tracked override `.htaccess`) |
| Routing | `.htaccess` gains rules for `/forum`, `/forum/{cat}`, `/forum/{cat}/{thread}`, `/forum/new/{cat}`, `/forum/edit/{post}`, `/forum/reply`, `/forum/mod`, `/avatar_upload` |
| Pages | `pages/forum.php`, `pages/forum_new_thread.php`, `pages/forum_reply.php`, `pages/forum_edit.php`, `pages/forum_mod.php`, `pages/forum_image.php`, `pages/admin_forum.php`, `pages/avatar_upload.php` |
| Helpers | `includes/forum.php` (settings, slug, fetchers, write, approve/reject, moderation, view de-dupe, anti-spam), `includes/avatar.php` (render, fallback initials, batch lookup) |
| Admin UI | New **Forum** tab in `/admin_dashboard` with stat tiles + moderation queue (deep-link from "Pending approvals"). Full config at `/admin_forum` (settings, categories CRUD, bans CRUD, queue) |
| Public UI | `/forum` index, `/forum/{cat}` category, `/forum/{cat}/{thread}` detail. Windowed pager (20/page). Avatars next to every post. Author + admins see their own pending content with a yellow border + "Awaiting approval" pill |
| Dashboard | Hero now has an avatar block (click to upload/remove). Navbar dropdown shows the same avatar |
| i18n | ~200 new EN+ES keys covering the entire forum surface + avatar UI |
| External | EasyMDE editor loads from jsDelivr CDN (only on `/admin_news`, `/admin_forum`, and the forum write pages — the public forum read pages don't depend on it) |
| Config | Nothing to change. The forum's enable + threshold live in the DB, edited from the admin panel |

#### Notable behavioural notes

- **Forum bans are forum-only.** Banned users can still log in, play the game, and use tickets — they just can't post or reply. GM 9+ accounts cannot be banned (prevents admin-vs-admin lockout).
- **GM 9+ bypasses everything**: auto-approval, anti-spam cooldown, locked threads (admins can reply through locked threads), and the public-disabled gate (admins get an "admin preview" banner instead of the friendly notice).
- **Editing never resets approval status.** A pending post stays pending; a published post stays published. Admin edits stamp the editor name as `"{name} (admin)"`.
- **Anti-spam cooldown is 30 seconds**, hardcoded. It reads `MAX(created_at)` from the user's posts at submit time — no new storage.
- **Per-session view de-dupe** keeps refresh-spam out of the view counter. Tracked in `$_SESSION` (capped at 500 thread IDs FIFO, so long sessions can't bloat).

---

## Admin Dashboard

Accessible at `/admin_dashboard` for accounts with **GM level ≥ 9**.

### GM ranks at a glance

The portal reads `gmlevel` from TrinityCore's `account_access` table. Threshold for the Admin Panel link in the navbar:

| GM level | Behaviour |
|---|---|
| **0** (regular player) | No GM badge anywhere; Admin Panel link not shown |
| **1 – 8** | "GM N" badge on the dashboard hero; Admin Panel item appears in the user dropdown but is **disabled** with a tooltip explaining the threshold |
| **9 +** | Admin Panel link is active and clickable; full access to `/admin_dashboard` |

To grant or revoke access, edit the `account_access` table directly (or use any GM-rank command your core supports). To change the threshold, search for `>= 9` in `templates/header.php` and `pages/admin_dashboard.php`.

### Admin Dashboard tabs

| Tab | Features |
|---|---|
| **Overview** | Server status, registration chart (14 days), class distribution, recent bans, top characters |
| **Accounts** | Full account list with search/filter, inline Ban/Unban buttons, account detail modal (view chars, reset password, edit email, set GM level) |
| **Tickets** | View all support tickets, filter by status, reply to tickets, close/reopen |
| **News** | Quick view of recent posts with deep-link to the full `/admin_news` editor (create/edit/publish/delete + live Markdown preview) |
| **Forum** | Status + counters (enabled, categories, pending approvals, active bans). "Pending approvals" tile glows gold when non-zero and deep-links to the moderation queue at `/admin_forum#moderation-queue`. Full config (settings, categories, bans, queue) at `/admin_forum`. |
| **Audit Log** | Chronological log of all admin actions (bans, unbans, edits, etc.) |
| **Tools** | Character lookup, IP ban management, server stats, email broadcast to all users |

All admin actions are logged to the `admin_audit_log` table automatically.

### Managing News

The News tab in `/admin_dashboard` shows the 10 most recent posts and a **Manage News** button that opens the full editor at `/admin_news`. From there you can:

- **Write posts** in the EasyMDE editor with a familiar toolbar (bold, italic, headings, lists, links, tables, code, quote, image upload, fullscreen, side-by-side preview).
- **Drag-and-drop images** directly into the editor — they upload to `uploads/news/` with server-generated filenames (`news-{timestamp}-{hash}.{ext}`), get audit-logged, and the `![alt](url)` reference is auto-inserted at your cursor. Max 5 MB per image; accepted formats: jpg, png, webp, gif.
- **Save as draft** while you're still writing — drafts return 404 to anonymous visitors and don't appear in the homepage or `/news` list. Toggle a post to published when ready.
- **Override the slug** if the auto-generated one (derived from the title) isn't what you want. Slugs are URL-safe and unique — duplicates get a `-2`, `-3`, … suffix.
- **Override the publish time** if you want to backdate or schedule a post.
- **Live preview** through the eye / side-by-side icons. The preview runs through the same Parsedown safe-mode renderer that the public page uses, so what you see is exactly what readers will get.
- **Delete** posts — there's a confirmation dialog and the action is audit-logged.

When viewing a published post on `/news/{slug}` while logged in as GM 9+, you'll see an **Edit Post** button in the top-right that jumps straight to the editor for that post — no need to go back to the admin list.

### Managing the Forum

The Forum tab in `/admin_dashboard` shows status tiles and a **Configure Forum** button that opens `/admin_forum`. From there you have four sections:

**Settings** — one row in `forum_settings`:
- **Forum enabled** — when off, `/forum` shows a "currently disabled" notice to regular users (admins still get an "admin preview" view so they can populate the forum before launch).
- **Auto-approve threshold** — `0` means every post publishes instantly; otherwise a user's posts queue for admin approval until they've accumulated this many *approved* posts. GM 9+ always bypass.

**Categories** — one level only (no sub-categories by design). Each has a slug, name, description, Bootstrap-Icons class, and sort order. Deleting a category cascades to its threads + posts (confirmation-gated).

**Forum Bans** — forum-only mute. Banned users can still log in and play; they just can't post or reply. Add by username + reason + optional expiry datetime. GM 9+ accounts cannot be banned.

**Moderation Queue** — pending threads + replies waiting for approval. Each shows the body (collapsed by default), author, category, time, and Approve / Reject buttons. Approve flips the row to published and bumps the right counters; Reject hard-deletes. Threads are also approvable inline from the thread page itself (see below).

When viewing any thread as GM 9+, you also get a **Mod tools** row in the thread hero:
- **Approve** (only when the thread itself is pending)
- **Sticky** / **Unsticky** toggle
- **Lock** / **Unlock** toggle
- **Delete Thread** (cascades — thread + all posts gone)

Plus per-post actions in each post's meta row:
- **Approve** (only on pending replies)
- **Edit** (any post — admin edits stamp the editor as `"{name} (admin)"`)
- **Delete** (any post — deleting the OP is the same as deleting the thread)

Every moderation action is recorded in `admin_audit_log` with the admin name, target, and IP.

### Managing the In-Game Shop

> Manages the repack's **Battle Pay store** directly in the `world` DB (`battle_pay_*` tables). Off by default — set `features.shop_admin = true` and a reachable `db.name_world` (commonly `world`, some repacks use `mop_world`). If the tables aren't present the page degrades with a clear notice, so it's safe on any repack.

The Shop tab in `/admin_dashboard` shows status + counts and links to `/admin_shop`, where you can:

- **Categories**: add / edit (name ≤16, icon, type) / delete (cascades tiles, cleans truly-orphaned products) / reorder (▲▼).
- **Items (tiles)**: add / edit / delete / reorder / move between categories / inline price quick-edit. Adding an item writes the verified product + product_items + entry triple in one transaction with known-good single-item defaults; editing preserves arcane flags so boost/balance products aren't broken.
- **Item picker**: type a name (or paste an item id) → live `item_template` search. Every saved itemId is validated against `item_template` — an unknown id rejects the whole save (nothing half-written). Picked items get a **Wowhead (mop-classic) tooltip/link** (same widget the armory uses) so you see the real icon/model/tooltip of what you're selling.
- **Appearance fields** (`icon` FileDataID, `displayId`) are collapsed into an optional section with plain-language help. The website **cannot** thumbnail a raw FileDataID (no game client files) — instead the tile icon is **auto-filled** from a matching shop entry when you pick an item, or you can **copy an icon from any existing tile** via a dropdown.

> [!IMPORTANT]
> **Shop changes need a worldserver restart.** The `battle_pay_*` tables are read into worldserver memory at startup only — there is no in-game reload. After any change the page shows a persistent "restart required" banner (with an *I've restarted it* dismiss). Restarting disconnects online players for ~1–2 min.

> [!NOTE]
> Custom rows are assigned ids ≥ **9000** (a reserved range) so a repack update that re-imports `battle_pay_*` is unlikely to collide with them. A repack update *can* still overwrite custom shop data — keep a DB backup before applying repack updates. Every shop write is recorded in `admin_audit_log`.

### Public Shop Catalog & Ko-fi Donations

Two **independent** feature flags drive the player-facing side — neither implies the other, so you can run any combination:

| Flag | What it does |
|---|---|
| `features.shop` | The public **/shop** catalog: a read-only, in-game-shop-styled browse of what's buyable with Battle Coins (left category rail, real item icons via Wowhead, prices). Buying still happens **in-game** — this is a showcase, not a web checkout. Degrades gracefully if the world DB / `battle_pay_*` tables are absent. |
| `features.donations` | The **Ko-fi donate panel** on /shop + automatic Battle Coins (DP) crediting. Touches only the `auth` DB, so it works even with `features.shop` off or the world DB down. |

**Why Ko-fi only?** It's the only no-merchant-KYC option with an automatic-crediting webhook on its free tier. PayPal/Stripe require business verification and bring refunds, chargebacks, tax and fraud you'd inherit — deliberately out of scope. If you need another processor, fork; core stays Ko-fi-only.

**Ko-fi setup (one-time):**

1. Create a free [Ko-fi](https://ko-fi.com) account and set your page currency.
2. Ko-fi dashboard → left menu **More** (the **•••** "three dots" item) → **API**. In the **Webhooks** card:
   - set the **Webhook URL** to `https://<your-site>/kofi_webhook` and click **Update**;
   - expand the **▾ Advanced** block and copy the **Verification token** (use **Refresh** if you want a new one — then re-copy).
3. In `config.php`, fill the `donation` block:
   - `kofi_verification_token` — paste the token from step 2 (keep it secret);
   - `eur_to_dp_rate` — Battle Coins per 1.00 of your currency. This is only the **bootstrap default** — a GM can change the rate at any time in **/admin_shop → Battle Coins exchange rate**, which saves a DB override that wins over this value (default `1000`, tuned to typical in-game prices: a normal item ≈ 1–5k, a premium mount ≈ 25k; `floor()` applied);
   - `currency` — your Ko-fi page currency (display label only);
   - `min_amount` — donations below this are logged but credit 0 DP;
   - `kofi_url` — your public Ko-fi page (the Donate button links here).
4. Set `features.donations = true`.
5. Apply `sql/setup.sql` (idempotent) so the `donation_codes` / `donation_log` / `shop_settings` tables exist.

> [!TIP]
> The exchange rate is editable in-app: **/admin_shop → Battle Coins exchange rate**. It shows live `1€ / 5€ / 25€` examples and your median in-game item price as an anchor, and the saved value overrides `config.donation.eur_to_dp_rate` (the config line is then only the fallback for fresh installs).

**How crediting works:** a logged-in player opens **/shop**, copies their personal `WL-XXXXXXXX` code, and pastes it into the Ko-fi message when donating. The webhook reads the real paid amount, so the donate button is fully dynamic — any amount works. Crediting happens **only** via the webhook (never the Ko-fi thank-you/redirect page).

> [!CAUTION]
> **The donor MUST paste their `WL-XXXXXXXX` code into the Ko-fi message field.** This is the only thing that links a payment to an account — Ko-fi does not share the payer's site identity. A donation with no (or a wrong) code is recorded as **unattributed**: it is logged in `donation_log` + `admin_audit_log` for the donor's protection, but **no Battle Coins are credited automatically** — a GM has to resolve it by hand. The `/shop` donate panel makes this requirement very prominent (red warning + a highlighted step), but you should also state it on your Ko-fi page description.

> [!IMPORTANT]
> The webhook is **idempotent**: every delivery is keyed by Ko-fi's `kofi_transaction_id` (UNIQUE), so a re-delivered webhook never double-credits. Donations whose message has no valid code are logged as **unattributed** (status in `donation_log`, plus an `admin_audit_log` entry) for manual GM resolution — they are *not* credited automatically. Refunds/chargebacks are an accepted small-server risk: a GM can claw back DP via the admin panel. Test with Ko-fi's free **webhook test button** first, then a single ~€1 self-donation as the live smoke test.

---

## Customization

### Changing Text and Labels

All user-facing text is in the `lang/` folder:

```
lang/
├── en.php   ← English
└── es.php   ← Spanish
```

Edit the key-value pairs to change any text on the site:

```php
// lang/en.php
'welcome'    => 'Welcome to WoW Legends',
'index_lead' => 'Join our MoP private server!',
```

**Adding a new language:**

The portal accepts any ISO 639-1 language code (e.g. `de`, `fr`, `ro`, `ru`). To add one, **four** files need a small edit each:

1. **Create the translation file.** Copy `lang/en.php` to `lang/{code}.php` (e.g. `lang/ro.php`) and translate the right-hand values. Keep the keys identical to the English file — any key you don't translate falls back to its English value automatically, so partial translations are fine.

2. **Whitelist the code in `includes/lang.php`.** Find the line near the top:

   ```php
   $allowed_langs = ['en', 'es'];
   ```

   …and add your code:

   ```php
   $allowed_langs = ['en', 'es', 'ro'];
   ```

   This whitelist is a security guard — without it, the new code is silently rejected and the site falls back to English (a common cause of "I added the file but the dropdown doesn't switch").

3. **Whitelist the code in `templates/header.php` (twice).** The same list appears in two JavaScript blocks in this file — both must include your new code. Search for:

   ```js
   const allowedLangs = ['en', 'es']; // Keep this in sync with PHP
   ```

   Update **both** occurrences to:

   ```js
   const allowedLangs = ['en', 'es', 'ro'];
   ```

4. **Add the option to the language dropdown** in `templates/header.php`. Find the dropdown block (search for `languageDropdown`) and add a new `<li>` for your code:

   ```html
   <li><a class="dropdown-item py-2 <?= ($lang === 'ro') ? 'active' : '' ?>" href="?lang=ro">RO <span class="text-white-50 ms-2">(Română)</span></a></li>
   ```

After those four edits: save, refresh the page, and the new language appears in the dropdown and the language cookie. No restart needed.

### Replacing Images and Logo

All images are stored in `assets/img/`:

| File | Usage |
|---|---|
| `logo.webp` | Large hero logo on the homepage |
| `top-logo.webp` | Small navbar logo |
| Background images | `.webp` format recommended |

---

## Security Notes

- `config.php` is in `.gitignore` — **never commit it**
- The `uploads/` folder denies **all** direct HTTP access by default. Ticket attachments are served only through `/ticket_attachment` after an ownership-or-GM check.
- The `uploads/news/` subdir is the one **intentional exception**: it has its own `.htaccess` that allows public reads (so post images can load on the public news pages) **but blocks any executable extension** (`.php`, `.phtml`, `.phar`, `.pl`, `.py`, `.cgi`, `.sh`, `.html`) as defense-in-depth.
- News image uploads (`/news_image`):
  - Restricted to GM 9+ via session check
  - CSRF-token validated
  - Real MIME sniffed server-side with `mime_content_type()` (not the client's claimed `Content-Type`) — a `.exe` renamed `.png` is rejected with 415
  - 5 MB size cap returns 413
  - Filenames are **server-generated** (`news-{timestamp}-{8hex}.{ext}`); the client never controls the on-disk name, so path-traversal payloads in the original filename are moot
  - Every successful upload is recorded in `admin_audit_log`
- Forum image uploads (`/forum_image`) follow the same pipeline as news uploads (CSRF, server-side MIME sniff, 5 MB cap, server-generated filenames, audit-logged), but the auth gate is **"logged in AND not forum-banned"** instead of GM 9+. Forum bans propagate to the upload endpoint, so a muted user can't sneak attachments in.
- Avatar uploads (`/avatar_upload`) are open to any logged-in user but capped at 2 MB, MIME-sniffed (jpg/png/webp/gif), server-renamed to `{account_id}.{ext}` so each user has exactly one slot — no path traversal, no slot collision.
- The `uploads/avatars/` and `uploads/forum/` directories override the parent deny-all with tracked `.htaccess` files. Both still block script extensions (`.php`, `.phtml`, `.phar`, `.pl`, `.py`, `.cgi`, `.sh`, `.html`) as defense-in-depth.
- Forum **approval queue** keeps new users from spamming the front page on day one. Threshold is admin-configurable per-install; bypass is GM-only.
- Forum **anti-spam cooldown** (30 seconds, hardcoded) reads `MAX(created_at)` from the user's posts at submit time, fail-open on DB error so a hiccup can't lock out legitimate posters.
- Forum **bans are forum-only** (separate `forum_bans` table). They don't touch `account_banned` and don't affect game login or the ticket system. GM 9+ accounts cannot be added to `forum_bans` — admin-vs-admin lockout is impossible by construction.
- Markdown in tickets, news posts, and forum posts is rendered through Parsedown's safe mode (HTML stripped, no `javascript:` URLs). Even though only authenticated users write forum content, defense-in-depth keeps script tags from rendering even if an account were compromised.
- Attachment endpoint also blocks path traversal (filenames must match `[A-Za-z0-9._-]{1,200}`) and resolves inside `uploads/tickets/` only
- All forms use CSRF tokens
- All DB queries use PDO prepared statements
- Directory listing is disabled via `Options -Indexes`
- Rate limiting protects login from brute-force attacks
- Admin actions are logged to `admin_audit_log` with IP, timestamp, and details

---

## Troubleshooting

| Problem | Solution |
|---|---|
| **Links go to `/login` or `/assets/...` at the wrong location** | The app is being served from a subfolder. Serve it from the web root or map the repo folder to its own Apache VirtualHost/Alias. |
| **404 on `/login`, `/register`** | Enable `mod_rewrite` — see step 7 above |
| **"Database error" on tickets or password recovery** | Run `sql/setup.sql` on your `auth` database — see step 3 |
| **"Invalid default value" when running SQL** | Your MySQL is very old. Use the latest `setup.sql` which is compatible with MySQL 5.5.9+ |
| **reCAPTCHA not showing** | Check your site key/secret in `config.php`, or set `recaptcha => false` |
| **`PHPMailer` class is missing** | `vendor/` is tracked in the repo, so this should not happen. If it does (e.g. an interrupted clone), run `composer install --no-dev` from the project root. |
| **Emails not sending** | Verify SMTP credentials; for Gmail use an [App Password](https://support.google.com/accounts/answer/185833) |
| **Blank page / 500 error** | Check `C:\xampp\php\logs\php_error_log` for details |
| **Admin dashboard not loading** | Your account needs GM level ≥ 9 in the `account_access` table, and the route is `/admin_dashboard` |
| **`/news` shows "No news posts yet"** | The `news_posts` table is empty or missing. Re-run `sql/setup.sql` (idempotent), then refresh. The seed creates a starter "Welcome!" post. |
| **EasyMDE editor doesn't load on `/admin_news?new=1`** | The editor is loaded from `cdn.jsdelivr.net`. Check that your browser can reach the CDN, that nothing is blocking the script (corporate proxy, ad-blocker rules), and that JavaScript is enabled. The public news pages don't depend on the CDN. |
| **Image upload returns 415 "Unsupported"** | Server-side MIME sniff didn't recognize the file as one of jpg/png/webp/gif. Re-export from your image tool or convert with `magick input.bmp output.png`. The PHP `fileinfo` extension must be enabled (it is by default). |
| **Image upload returns 413** | File is over the 5 MB cap. Resize or re-compress and try again. |
| **Post images return 403 on the public page** | The `uploads/news/` directory is missing its override `.htaccess`. The parent `uploads/.htaccess` denies everything; the news subdir needs its own file to opt back in. See the "Upgrading from v0.3.x" section for the contents. |
| **Edit Post button doesn't show on `/news/{slug}`** | Confirm you're logged in **and** that your `gm_level` is ≥ 9. The session check is `$_SESSION['gm_level']`, which is set fresh at every login — if you changed your `account_access` row, log out and log back in. |
| **`/forum` shows "Forum is currently disabled"** | The forum's enable flag is off in the DB. Log in as GM 9+, go to `/admin_forum`, toggle **Forum enabled**. GMs always see the forum (with an admin-preview banner) regardless of the toggle. |
| **My new thread shows "waiting for admin approval" but doesn't appear in the category** | Expected — the auto-approve threshold isn't met yet. The thread is visible *to you* (with a yellow border + "Awaiting approval" pill) and to GMs (in `/admin_forum`'s moderation queue). Other users can't see it until a GM approves. Set the threshold to `0` to auto-publish everyone. |
| **"Please wait N seconds before posting again"** | Anti-spam cooldown — hardcoded 30 seconds between forum posts per user. GM 9+ bypasses. |
| **404 after a moderation action (sticky / lock / approve)** | Fixed in v0.5.0 (`e3f1039`). If you're on an older `main` snapshot, pull the latest. |
| **MySQL warning 1681 ("Integer display width is deprecated")** | Fixed in v0.5.0 — the original `TINYINT(1)` columns were narrowed to plain `TINYINT`. If you still see it, re-run `sql/setup.sql` against the newest schema. |
| **MySQL warning 3719 ("utf8 is currently an alias for UTF8MB3")** | Fixed in v0.5.0 — `sql/setup.sql` now runs an idempotent `CONVERT TO CHARACTER SET utf8mb4` block on the legacy tables. One re-run silences the warning permanently. |
| **EasyMDE fullscreen toolbar hidden behind navbar / second row clipped** | Fixed during Phase 5 polish. The site navbar auto-hides while EasyMDE is fullscreen, the toolbar is wrap-friendly, and floated icons are stripped. If you still see it, hard-refresh to bypass CSS cache. |

---

## Support the Project

This portal is **free and MIT-licensed**, and it always will be — no paywall, no locked features. But a lot of evenings and weekends go into building it, testing every release on a live realm, writing the docs, and helping people get their servers online.

If this saved you real development time, helped you launch your community, or you simply like where it's heading, a small tip keeps the momentum going — new features, fixes, and support. Every coffee is hugely appreciated and genuinely motivating. 💛

[![Support the project on Ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/kneuma)

> ℹ️ This supports development of the **open-source portal itself**. It is **separate** from the in-app [Ko-fi donations feature](#public-shop-catalog--ko-fi-donations), which is something *you* configure to credit Battle Coins to *your* players.

---

## License

This project is licensed under the [MIT License](LICENSE).
