# WoW Mists of Pandaria Registration Portal

A complete, secure, and modern registration portal for **World of Warcraft: Mists of Pandaria (5.4.8)** private servers. Built for TrinityCore-based cores (including repacks).

🌐 **Live demo:** [wow-legends.eu](https://wow-legends.eu/) — every feature you see in this README is running there.

![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue) ![Bootstrap 5](https://img.shields.io/badge/Bootstrap-5-purple) ![License: MIT](https://img.shields.io/badge/License-MIT-green) ![Status: In Development](https://img.shields.io/badge/status-In%20Development-orange) ![GitHub Release](https://img.shields.io/github/v/release/timoinglin/wow-mop-registration?label=release&color=8B4513) [![Live Demo](https://img.shields.io/badge/demo-wow--legends.eu-c8a96e?logo=globe)](https://wow-legends.eu/)

> ⚠️ **Active Development** — two ways to run it:
> - **Tagged release** *(recommended / production)* — what the [one-click installer & updater](docs/UPDATE.md) install. Stable.
> - **`develop` branch** — finished, tested features that aren't in a release yet. `git clone -b develop …` (or `git pull` on `develop`), then run the idempotent `sql/setup.sql`. See **[What's on `develop`](#whats-on-develop-not-yet-released)** for the current list.

> 💛 **Enjoying the portal?** It's free and open-source, built and maintained in spare time. If it saved you hours of setup or you'd like to see it keep growing, a coffee genuinely helps — see [Support the Project](#support-the-project).
>
> [![Support the project on Ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/kneuma)

## Table of Contents

- [Features](#features)
- [Preview](#preview)
- [Quick Start](#quick-start)
- [One-Click Installer](#one-click-installer)
- [What's on `develop`](#whats-on-develop-not-yet-released)
- [Documentation](#documentation)
- [Support the Project](#support-the-project)
- [License](#license)

## Features

- 🔒 **Security** — CSRF tokens, Google reCAPTCHA v2, PDO prepared statements, PHP-execution blocking on uploads
- 🛡️ **Rate Limiting** — Automatic lockout after failed login attempts (configurable)
- 🗝️ **Auth** — SHA-1 password hashing matching the TrinityCore format
- 📧 **Email** — SMTP password recovery and ticket notifications via PHPMailer
- 📊 **Live Stats** — Real-time server status, player counts, and animated counters
- 🌍 **Multilingual** — English and Spanish included; **adding a language is no-code** (drop `lang/<code>.php`, enable it in the admin)
- 🎨 **Modern UI** — Dark gaming theme, Bootstrap 5, responsive design; the accent recolours the whole site
- 🛠️ **Site Customization (no-code, update-safe)** — one GM-only `/admin_customization` page: **Theme & branding** (accent / preset palettes / logos / favicon / hero background, live preview), a **drag-and-drop home-page designer** (reorder & toggle built-ins, add card-grid / text / CTA / Q&A sections), an editable **footer**, **languages**, and the presentational **site settings** (titles, social links, Ko-fi/playtime/vote config). All DB-stored — `config.php` stays the untouched fallback, so changes survive updates.
- ⚙️ **Feature Flags** — Toggle tickets, password recovery, reCAPTCHA, and maintenance mode from config
- 🧑‍💼 **Admin Dashboard** — Account management, ban/unban, ticket management, audit log, character lookup, IP bans, email broadcast
- 🔍 **Public Armory** — Search any character on the realm; profile pages with equipped gear (Wowhead tooltips), stats, achievements, and account-mate links
- 🏆 **Leaderboards** — Top players by level, playtime, gold, PvP kills, **Arena rating** (2v2 / 3v3 / Rated BG, current season), and achievements, plus top guilds — with faction filters and gold/silver/bronze top-3 styling
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

> ▶ **[See it running live → wow-legends.eu](https://wow-legends.eu/)** — the live demo tracks the latest `develop`, so it's always the most accurate look (themed accent, custom home-page sections, current UI). The shots below are just a snapshot; admin tooling is covered in **[docs/ADMIN.md](docs/ADMIN.md)**.

### Home Page
![Home Page](assets/img/screenshots/home.webp)

### Customization — no-code, update-safe
*One GM-only page (`/admin_customization`): **Theme & branding** (accent / preset palettes / logos / favicon / hero background + live preview), a **section-based home-page designer** (drag-reorder, toggle, add card-grid / text / CTA / Q&A), an editable **footer**, **languages**, and the presentational **site settings** — all DB-stored and survives updates.*

![Customization](assets/img/screenshots/customization.webp)

### User Dashboard
![User Dashboard](assets/img/screenshots/user.png)

### Public Armory
![Public Armory](assets/img/screenshots/armory.png)

### Leaderboards
![Leaderboards](assets/img/screenshots/leaderboards.png)

### Public Shop
*The player-facing `/shop` — an in-game-shop-styled, read-only catalog of everything buyable with Battle Coins (left category rail, real item icons + hover tooltips via Wowhead), the logged-in user's balance, and the Ko-fi donate panel with a personal attribution code.*

![Public Shop](assets/img/screenshots/shop_public.png)

### Community Forum
*Threads on `/forum/{category}/{thread}` — categories, stickies/announcements, per-category posting policy, and (for GM 9+) inline Mod tools. Full forum admin lives in **[docs/ADMIN.md](docs/ADMIN.md)**.*

![Community Forum](assets/img/screenshots/forum_user.png)

### Custom 404 Page
*"You died." — your players will never feel less lost. With a hidden murloc easter egg.*

![404 Page](assets/img/404-spirit-healer.png)

<!-- Screenshot policy (keep this list lean): ~8 public-facing shots max.
     A new feature gets a bullet in Features + the live demo, and at most
     REPLACES the most relevant shot here — do NOT append a new ### per
     feature. Admin deep-dives = docs/ + live demo, not the README. New/
     replacement shots: .webp, ≤ ~500 KB. The 5 admin PNGs removed from
     this list (forum_admin, shop_management, admin1, admin-accounts,
     admin-tickets) can be deleted from assets/img/screenshots/ in a
     cleanup commit if nothing else references them. -->


---

## Quick Start

```bash
# 1. Install XAMPP — https://www.apachefriends.org/
# 2. Serve this project from your Apache web root or a dedicated VirtualHost/Alias
#    Do not run it from a subfolder such as /wow-legends without remapping DocumentRoot

# 3. Copy the sample config
copy config.sample.php config.php

# 4. Edit config.php with your DB credentials, realm info, site base URL, reCAPTCHA keys, etc.

# 5. Run the SQL setup (see docs/INSTALL.md -> Database Setup)

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

## What's on `develop` (not yet released)

These features are **finished and tested** but haven't been bundled into a tagged release yet. To run them now:

```bash
git clone -b develop https://github.com/timoinglin/wow-mop-registration.git
# already installed? from your install folder:
git checkout develop && git pull
```

Then run `sql/setup.sql` once (it's idempotent — safe to re-run). These fold into the next release automatically.

- **Armory character portrait** — HQ race/gender art in a class-coloured ring with a faction-tinted glow and a class-emblem badge, used both as the **hero avatar** beside the character name and as the **equipment-panel centre** so the profile reads as one piece. Accent-themed, no fragile 3D model, repack-safe.
- **"Update available" notice (admin)** — the admin dashboard always shows a small **`Portal vX.Y.Z · up to date / update available`** chip under the title, and when you're behind it also surfaces a prominent banner with the release-notes link and the one-paste `update.ps1` command (copy button). Version = `WL_VERSION` (overridden by an updater-written `/VERSION` when higher); GitHub check cached ~6h, fail-silent, **advisory only — it never updates anything itself**. No DB migration.
- **Who's online** (`/online`) — live roster of currently-logged-in characters: name (class-coloured), level, race · class, guild, faction, clickable into the Armory. Faction filter, mobile-first responsive (table → cards on phones). The homepage **Players Online** counter is now a click-through to this page. Read-only, defensive (graceful when the characters DB is unavailable), no migration.
- **Players ▾ nav dropdown** — Armory / Leaderboards / Who's Online consolidated under one navbar item so the bar doesn't grow per feature; future Guild pages slot in for free. Submenu icons track the theme accent (Online keeps its semantic green).
- **Armory expansion** — three more panels on the character profile, all repack-defensive (any missing column/table just hides that bit, never breaks the page): **PvP** (total HKs + rated bracket ratings for the current season, from `rated_pvp_info`), **Professions** (primary + secondary skills with value/max bars; names from a curated map so no "Skill #ID" garbage), and a **Title** row in the Info panel (curated id → suffix-form text). No DB migration.
- **Guild pages** (`/guild/<name>`) — per-guild profile with faction-tinted hero (crest, name, level, leader → /armory, founded date, MOTD), live stat strip (level / members / online now / guild bank gold), full members table (rank, character → /armory, level, race · class, online status). Mobile-first responsive (table → cards). The Leaderboards "Guilds" tab rows, the Armory character header `<Guild>` text, and the Who's Online guild column are all now click-throughs to it. Repack-defensive (any missing column hides that bit; falls back to "Rank #N" if `guild_rank` table is absent). No migration.
- **Armory Talents — in-game-style 6×3 grid** for all 11 classes (every cell verified against Wowhead's mop-classic redirect slugs; 198/198). Chosen talent glows in the class colour; unchosen options dimmed. Mobile: 1-column stack.
- **Armory Glyphs panel** — 3 major + 3 minor slots per spec, Wowhead chips for chosen glyphs, "Unlocked at Lv N" hint for empty unlocked slots. Repack-defensive (hides if `character_glyphs` is absent).
- **Armory Professions — icons** — each profession row now shows the in-game tradeskill icon (Wowhead CDN) alongside the skill name + value bar, matching the visual rhythm of the equipment slots.
- **Portal tables get a `web_` prefix** — every table the registration portal owns is now namespaced (`web_forum_posts`, `web_tickets`, `web_news_posts`, …) so it's visually distinct from the worldserver's auth/character/world tables that live in the same database. `sql/setup.sql` auto-renames any pre-v0.7.x unprefixed tables in place (`RENAME TABLE` preserves data + indexes — no rebuild, no row-by-row copy). Idempotent: re-running setup.sql is a no-op on already-migrated installs.
- **Armory Achievements + Reputation panels** — Achievements: total earned + the 12 most recent as Wowhead-tooltip chips (click any chip for full detail). Reputation: 17 major MoP factions (Klaxxi, Shado-Pan Assault, Black Prince, August Celestials, …) with rank-coloured progress bars (Hated → Exalted, "Maxed" gold for Exalted). Both are repack-defensive — hide when the underlying table is absent.
- **Navbar character search** — debounced autocomplete input in the header. Type a few letters → dropdown of up to 8 matches with race icon, class-coloured name, level, and class label. Click or Enter on a row → `/armory/<name>`. Keyboard navigation (↑/↓/Enter/Esc) supported. Backed by a public `/api/search/chars?q=…` JSON endpoint.

*Empty = the latest tagged release is fully current.*

---

## Documentation

Full guides live in the [`docs/`](docs/) folder so this README stays focused on what the project is and how to get it running:

| Guide | What's inside |
|---|---|
| **[Installation](docs/INSTALL.md)** | Requirements, manual setup, database, the configuration steps, feature flags, `mod_rewrite` |
| **[Updating](docs/UPDATE.md)** | The one-click updater, Git & manual paths, and every version-specific upgrade guide |
| **[Admin &amp; Customization](docs/ADMIN.md)** | Admin dashboard & GM ranks, managing News / Forum / In-Game Shop & Ko-fi donations, and the no-code **Customization** suite (theme & branding, home-page designer, footer, languages, site settings) |
| **[Security Notes](docs/SECURITY.md)** | What's protected out of the box and what you must configure |
| **[Troubleshooting](docs/TROUBLESHOOTING.md)** | Common issues and their fixes |
| **[Shop DB Schema](docs/SHOP_SCHEMA.md)** | Reverse-engineered `battle_pay_*` reference for debugging / hand-editing the in-game shop |

> New here? Use the [One-Click Installer](#one-click-installer) above, or follow the [manual Installation guide](docs/INSTALL.md).

---

## Support the Project

This portal is **free and MIT-licensed**, and it always will be — no paywall, no locked features. But a lot of evenings and weekends go into building it, testing every release on a live realm, writing the docs, and helping people get their servers online.

If this saved you real development time, helped you launch your community, or you simply like where it's heading, a small tip keeps the momentum going — new features, fixes, and support. Every coffee is hugely appreciated and genuinely motivating. 💛

[![Support the project on Ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/kneuma)

> ℹ️ This supports development of the **open-source portal itself**. It is **separate** from the in-app [Ko-fi donations feature](docs/ADMIN.md#public-shop-catalog--ko-fi-donations), which is something *you* configure to credit Battle Coins to *your* players.

---

## License

This project is licensed under the [MIT License](LICENSE).
