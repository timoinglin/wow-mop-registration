# WoW Mists of Pandaria Registration Portal

A complete, secure, and modern registration portal for **World of Warcraft: Mists of Pandaria (5.4.8)** private servers. Built for TrinityCore-based cores (including repacks).

![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue) ![Bootstrap 5](https://img.shields.io/badge/Bootstrap-5-purple) ![License: MIT](https://img.shields.io/badge/License-MIT-green) ![Status: In Development](https://img.shields.io/badge/status-In%20Development-orange) ![GitHub Release](https://img.shields.io/github/v/release/timoinglin/wow-mop-registration?label=release&color=8B4513)

> ⚠️ **Active Development** — This portal is still evolving. Features land in `main` regularly. Pin a release tag if you need stability, or follow the [How to Update](#how-to-update) section to stay current.

## Table of Contents

- [Features](#features)
- [Preview](#preview)
  - [Home Page](#home-page)
  - [User Dashboard](#user-dashboard)
  - [Public Armory](#public-armory)
  - [Leaderboards](#leaderboards)
  - [Custom 404 Page](#custom-404-page)
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
- [Admin Dashboard](#admin-dashboard)
- [Customization](#customization)
  - [Changing Text and Labels](#changing-text-and-labels)
  - [Replacing Images and Logo](#replacing-images-and-logo)
- [Project Structure](#project-structure)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
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
- 📰 **News & FAQ** — Configurable news section and FAQ accordion on the home page
- 🗳️ **Vote System** — Vote site links on the user dashboard (configurable)
- 🔗 **Social Links** — Discord, YouTube, X (Twitter), Instagram — each individually toggleable

---

## Preview

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
| **News** | Array of news entries shown on the home page |
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
-- This creates 6 tables (and an idempotent column-add migration):
-- 1. password_resets       — password recovery tokens
-- 2. tickets               — support ticket headers (subject, category, status…)
-- 3. admin_audit_log       — chronological log of admin actions
-- 4. playtime_rewards      — per-account state for the Battle Pay reward feature
-- 5. playtime_reward_log   — audit trail for every Battle Pay claim
-- 6. ticket_messages       — per-message thread (user + admin replies, attachments)

-- Compatible with MySQL 5.5.9+
-- All CREATEs use IF NOT EXISTS, and the ticket_messages.attachments
-- ALTER is INFORMATION_SCHEMA-checked, so it's safe to re-run.
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

News entries and FAQ items are also configured in `config.php`:

```php
'news' => [
    ['title' => 'Server Launch!', 'date' => '2026-03-02', 'text' => 'We are live!', 'icon' => 'bi-megaphone'],
],

'faq' => [
    ['q' => 'Is it free to play?', 'a' => 'Yes, 100% free.'],
],

'vote_sites' => [
    ['name' => 'TopG', 'url' => 'https://topg.org/...', 'cooldown_hours' => 12],
],
```

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

### Option A — Git (recommended)

If you cloned with Git originally:

```bash
# 1. Stop Apache from the XAMPP Control Panel

# 2. Pull the latest files (this brings in updated vendor/ too — it's tracked)
git pull origin main

# 3. Re-run the SQL setup — it's idempotent (CREATE TABLE IF NOT EXISTS)
mysql -u root -pascent auth < sql/setup.sql

# 4. Restart Apache and you're done
```

### Option B — Manual (release ZIP)

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
| `uploads/` | Ticket attachments uploaded by users |
| `cache/` | Login history and rate-limit data |

### Worth knowing

- New features may add **new keys** to `lang/en.php` and `lang/es.php`. Missing keys fall back to English automatically — updating is non-breaking, but translations may show English until you copy in the new keys (or just update both `lang/` files from the new release).
- New SQL columns or tables, when introduced, are added to `sql/setup.sql` with `IF NOT EXISTS` patterns, so re-running it is always safe.
- Check the [release notes](https://github.com/timoinglin/wow-mop-registration/releases) for any version-specific upgrade steps.

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
| **Audit Log** | Chronological log of all admin actions (bans, unbans, edits, etc.) |
| **Tools** | Character lookup, IP ban management, server stats, email broadcast to all users |

All admin actions are logged to the `admin_audit_log` table automatically.

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
1. Copy `lang/en.php` to e.g. `lang/de.php`
2. Translate all values
3. Add the new option to the language dropdown in `templates/header.php`

### Replacing Images and Logo

All images are stored in `assets/img/`:

| File | Usage |
|---|---|
| `logo.webp` | Large hero logo on the homepage |
| `top-logo.webp` | Small navbar logo |
| Background images | `.webp` format recommended |

---

## Project Structure

```
wow-legends/
├── .htaccess               ← URL rewriting + ErrorDocument 404 + asset hardening
├── .gitignore
├── assets/
│   ├── css/                ← style.css
│   ├── img/                ← logos, backgrounds, race/class icons, screenshots, 404 art
│   └── bg-video-mop.mp4    ← Homepage hero video
├── cache/                  ← Runtime data (gitignored except the .htaccess deny rules)
│   ├── login_history/      ← Per-user login history JSON files
│   └── rate_limit/         ← File-based login throttling data
├── includes/
│   ├── audit.php           ← Admin audit log helper
│   ├── auth.php            ← Password hashing (TrinityCore SHA-1), IP detection
│   ├── csrf.php            ← CSRF token generation/validation
│   ├── db.php              ← Database connections (auth + characters)
│   ├── email.php           ← PHPMailer send functions
│   ├── functions.php       ← Backward-compat loader
│   ├── helpers.php         ← WoW helpers (format playtime, gold, race/class names — i18n-aware)
│   ├── lang.php            ← Language loader (cookie + ?lang= query)
│   ├── login_history.php   ← File-based login history helper
│   ├── markdown.php        ← Parsedown wrapper (safe mode + URL auto-link)
│   ├── Parsedown.php       ← Markdown parser (Erusev, single-file)
│   ├── playtime_rewards.php← Battle Pay (DP) reward logic + atomic claim
│   ├── rate_limiter.php    ← File-based brute-force protection
│   └── recaptcha.php       ← reCAPTCHA verification (respects feature flag)
├── lang/                   ← Translations (en.php, es.php — ~400+ keys each)
├── pages/
│   ├── 404.php             ← Themed "You died." Spirit Healer + murloc easter egg
│   ├── admin_api.php       ← AJAX API for admin actions
│   ├── admin_dashboard.php ← Admin overview + tabs (Accounts, Tickets, Audit, Tools)
│   ├── admin_ticket.php    ← Per-ticket detail page for GMs (thread, reply, status)
│   ├── armory.php          ← Public Armory (search + character profiles)
│   ├── change_password.php
│   ├── dashboard.php       ← User dashboard (chars, Battle Pay, playtime reward, vote, links)
│   ├── leaderboards.php    ← Top players + guilds across multiple categories
│   ├── login.php
│   ├── logout.php
│   ├── recover.php
│   ├── register.php
│   ├── reset_password.php
│   ├── ticket_attachment.php ← Auth-gated serve endpoint for ticket attachments
│   ├── ticket_view.php     ← Per-ticket detail page for users (thread, reply, close, reopen)
│   └── tickets.php         ← New-ticket form + list of user's tickets
├── sql/
│   └── setup.sql           ← All required tables + idempotent migrations
├── templates/              ← header.php (nav, OG meta, lang) + footer.php
├── uploads/
│   ├── .htaccess           ← Denies ALL direct access (files served via /ticket_attachment)
│   └── tickets/            ← User-uploaded ticket images (auth-gated, gitignored content)
├── vendor/                 ← Composer deps (PHPMailer, Parsedown) — tracked in repo
├── composer.json
├── composer.lock
├── config.php              ← Your live config (gitignored)
├── config.sample.php       ← Safe template to commit
├── favicon.ico
├── index.php               ← Homepage / router
└── install.ps1             ← Windows one-click installer (PowerShell, downloads release ZIP)
```

---

## Security Notes

- `config.php` is in `.gitignore` — **never commit it**
- The `uploads/` folder denies **all** direct HTTP access. Ticket attachments are served only through `/ticket_attachment` after an ownership-or-GM check
- Attachment endpoint also blocks path traversal (filenames must match `[A-Za-z0-9._-]{1,200}`) and resolves inside `uploads/tickets/` only
- Markdown in tickets is rendered through Parsedown's safe mode (HTML stripped, no `javascript:` URLs)
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

---

## License

This project is licensed under the [MIT License](LICENSE).
