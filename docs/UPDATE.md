# Updating

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — the one-click updater plus every version upgrade guide.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

---

## How to Update

Already running and want the latest version? **Keep your existing `config.php`, `uploads/`, and `cache/`** — everything else can be replaced.

> [!IMPORTANT]
> Before updating, back up `config.php` and the `uploads/` folder. Both are gitignored, so `git pull` won't touch them — but a backup is cheap insurance in case you need to roll back.

### Option A — One-Click Updater (recommended, Windows)

No admin rights needed. Works from **Command Prompt _or_ PowerShell**.

1. Open your **website folder** in File Explorer — the folder that contains `index.php` and `config.php` (for example `C:\xampp\htdocs` or `C:\xampp\htdocs\wow-legends`).
2. Click the address bar, type **`cmd`**, press **Enter** — a terminal opens already in that folder.
3. Paste this **one line** and press Enter:

```
powershell -NoProfile -ExecutionPolicy Bypass -Command "irm https://raw.githubusercontent.com/timoinglin/wow-mop-registration/main/update.ps1 -OutFile update.ps1; .\update.ps1"
```

> It downloads the updater into the current folder and runs it with the correct execution policy — so there's no `irm is not recognized` (that happens if you paste the bare `irm …` into Command Prompt) and no *“running scripts is disabled”* error. The updater operates on whatever folder you launched it from.

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

### Option C — Clean install (release ZIP) — *cleanest, recommended for big updates*

A **clean install** = delete the old program files first, then extract the
new release — keeping only `config.php`, `uploads/`, `cache/`. This is the
tidiest path and the only one that also clears files a release **removes**
(e.g. retired screenshots/assets). Overwrite-only updates leave those as
harmless orphans; a clean install doesn't.

1. **Stop Apache.**
2. **Back up** `config.php` and the `uploads/` folder (and zip the whole site folder — cheap rollback).
3. **Delete** the old project files **except** `config.php`, `uploads/`, and `cache/`.
4. **Extract** the new release ZIP into the same folder (the ZIP includes `vendor/`).
5. **Re-run** `sql/setup.sql` on your `auth` database — idempotent, safe to run repeatedly.
6. **Restart Apache.**

### What carries over automatically

| File / Folder | Why it's preserved |
|---|---|
| `config.php` | DB credentials, realm info, social links, news, FAQ, vote sites, feature flags |
| `uploads/` | User avatars, news/forum images, ticket attachments, site branding (logos / favicon / hero bg) |
| `cache/` | Login history and rate-limit data |
| `.git/` | Left intact for git-cloned installs (bonus rollback path) |

### Worth knowing

- New features may add **new keys** to `lang/en.php` and `lang/es.php`. Missing keys fall back to English automatically — updating is non-breaking, but translations may show English until you copy in the new keys (or just update both `lang/` files from the new release).
- New SQL columns or tables, when introduced, are added to `sql/setup.sql` with `IF NOT EXISTS` / `INFORMATION_SCHEMA` patterns, so re-running it is always safe.
- A release may also **remove** bundled files (old screenshots, retired assets). The one-click updater and `git pull` handle this fine; an *overwrite-only* manual extract leaves the removed files behind as **harmless orphans**. If you want a spotless tree, do a **clean install** (Option C) — your full backup zip makes it risk-free.
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

### Upgrading from v0.6.x → v0.7.0

v0.7.0 is **The Customize release** — a full no-code, update-safe Site
Customization suite (Theme & branding, the drag-and-drop home-page
designer, Site settings, editable footer, no-code languages), plus the
forum per-category posting policy and the `/shop` donation-framing text.
**Everything is additive and backward-compatible** — nothing was removed,
and an un-customized install looks and behaves exactly as before.

#### 1. Pull the new code

```bash
# stop Apache first (XAMPP Control Panel → Stop)
git checkout main && git pull
# (or extract the v0.7.0 release ZIP, overwriting everything except
#  config.php, uploads/, cache/)
```

#### 2. Run the SQL setup (required)

```bash
mysql -u root -pascent auth < sql/setup.sql
```

Idempotent (`CREATE TABLE IF NOT EXISTS`). It adds the `site_settings`
table (the customization store) and the `forum_categories.admin_only` /
`allow_replies` columns. **No existing data is changed**; until you save
something in `/admin_customization`, every value falls back to
`config.php`. (The one-click updater applies this for you with the
bundled XAMPP PHP — no `mysql` client needed.)

#### 3. Nothing else to do

`uploads/branding/` (admin-uploaded logos / favicon / hero background) is
**created automatically** on first save and is preserved by the updater
like `uploads/avatars/`. `config.php` is never rewritten — it stays the
seed/fallback. Secrets & bootstrap (Ko-fi webhook token, `db`/`smtp`/
`recaptcha`, `site.base_url`, all `features.*` including
`playtime_reward.enabled`) deliberately remain file-only.

> v0.7.0 also trims some bundled screenshots from the repo. A `git pull`
> or the one-click updater handles that automatically; if you update by
> hand, a **clean install** (Option C) leaves the tidiest tree — old
> screenshots left behind are harmless either way.

#### What's new at a glance

- **Customization** (`/admin_customization`, GM 9+): Theme & branding
  (accent / presets / logos / favicon / hero bg, live preview, optional
  sanitised custom CSS) · **Home page designer** (reorder & toggle the
  built-in sections, add card-grid / text / CTA / Q&A) · **Site
  settings** (titles, social links, Ko-fi/playtime/vote config) ·
  editable **footer** · no-code **languages** (drop `lang/<code>.php`).
- Forum **per-category posting policy** (announcement-only / read-only).
- `/shop` **donation framing** (voluntary-tip / non-refundable wording).

---

[↑ Back to the README](../README.md)
