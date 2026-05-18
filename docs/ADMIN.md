# Admin & Customization

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — admin dashboard, News/Forum/Shop, theming.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

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

Each category also has a **posting policy** (two independent toggles on the add/edit form):

- **Only GMs can start threads** (`admin_only`) — turns it into an announcement category: regular users can read it but can't create threads. It shows an "Announcements" badge on the forum index. GMs (gmlevel ≥ 9) post as normal.
- **Allow user replies** (`allow_replies`) — uncheck for a fully read-only category (only GMs can reply). Combine the two for News/Patch-notes (GM threads, users discuss) or pure read-only announcements (GM threads, no replies).

Both default to fully open (anyone posts & replies), so existing categories are unchanged after the upgrade. Enforced server-side, not just hidden in the UI.

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

### Site Customization page (admin, no code)

`/admin_dashboard` → **Customization** tab → **Customize Site** (GM 9+), or go straight to `/admin_customization`.

**Footer links** (first section): toggle which built-in quick-links show (Home / Register / Login / Support — "Support" also needs the tickets feature on), and add your own **custom label + URL rows** (e.g. a donations-disclaimer page). A live preview of the saved footer is shown.

- Stored in the `site_settings` table (DB), **not** `config.php` — so it survives updates (like avatars/news/forum), and `config.php` stays the bootstrap/fallback. No migration needed: until you save here, the built-in defaults apply.
- Custom URLs are sanitised on save — only `https://` / `http://` or a site path starting with `/` are kept; anything else (e.g. `javascript:`, protocol-relative `//host`) is dropped.
- Audit-logged (`site_footer_update`).

**Languages**: every `lang/<code>.php` file is **auto-discovered** and listed here — drop a new file in and it appears (no code change). Tick to enable/disable which languages show in the site's language menu; **English is always on** (the fallback for any untranslated key). The section includes step-by-step instructions to add a custom language:

1. Copy `lang/en.php` → `lang/<code>.php` (2-letter code, e.g. `lang/fr.php`).
2. Translate the values; keep the key names unchanged.
3. It appears in the list automatically — enable it and Save.
4. Missing keys fall back to English automatically (`$TEXT[...] ?? '...'`), so a partial translation is safe.

Disabling hides a language from the menu (not deleted — re-enable anytime). Stored in `site_settings` (`site_languages_update`, audit-logged). `lang.php` discovers files from the filesystem and stays DB-free.

**Theme & branding**: recolour the whole site and swap the branding without editing files — all DB-stored, so it **survives updates** (the stylesheet stays the shipped fallback).

- **Accent colour** — set any `#rrggbb` (typed or via the colour picker), or click a **preset palette** (Gold / Azure / Verdant / Crimson / Arcane / Teal). One value drives the accent everywhere: `--accent` is overridden and `--accent-dim` / `--accent-glow` are auto-derived. A live preview updates as you type.
- **Base tone** (optional, advanced) — override page / card background and body-text colours. Left blank = the shipped dark theme. A warning flags that a poor choice can hurt readability.
- **Branding uploads** — **Main logo** (homepage hero), **Top-left logo** (navbar, every page), **Favicon**, and **Header background** (the full-screen homepage hero — an image *or* a looping `mp4`/`webm` video). Each shows the current asset with a one-click *Remove (revert to default)*. Limits: logos ≤ 3 MB, favicon ≤ 512 KB, header background ≤ 25 MB. **SVG is rejected** (XSS surface). Files are stored under `uploads/branding/` (updater-preserved, like avatars).
- **Advanced: custom CSS** — an opt-in textarea injected site-wide *after* the theme variables. Tags, `@import`, `expression()` and `javascript:` are stripped on save, but it can still break your layout — you own any breakage.

Stored as one `site_settings['theme']` row (`site_theme_update`, audit-logged). The override `<style>` is emitted only when the theme is *not* stock, so a default install ships byte-identical markup. How the operator on a live deployment hand-edited `style.css` and lost it on the next update is exactly what this removes.

**Site settings**: the presentational values that used to require editing `config.php` — no file edit, survives updates. **Every field is blank = use the `config.php` default**; config is never overwritten, it stays the seed/fallback.

- **Site identity** — browser/site title, realm name (© line, headings, OG tags), realm description (homepage subtitle / OG). A description set here applies to all languages; leave it blank to keep a per-language array in `config.php`.
- **Social links** — Discord / YouTube / X / Instagram (full `https://` URLs; blank hides the link).
- **Donation (display only)** — Ko-fi page URL, currency, minimum amount. The Battle-Coins-per-1.00 **rate** is still set in **Shop Management**; the **Ko-fi webhook token stays in `config.php`** and is never web-editable. These overrides are presentational — the Ko-fi webhook keeps validating against `config.php` so the money path never changes.
- **Playtime reward** — `DP per hour` and `daily cap` (server-clamped to 0–10000 / 0–1,000,000). The **master on/off stays a `config.php` feature flag** (`playtime_reward.enabled`) and is shown read-only here.
- **Vote sites** — name / URL / cooldown-hours rows. An empty list hides the Vote & Reward block.

Stored as one `site_settings['settings']` row (`site_settings_update`, audit-logged), resolved everywhere through `settings_get()` (DB → `config.php`). **Stays file-only (locked):** `donation.kofi_verification_token`, `db.*`, `smtp.*`, `recaptcha.*`, `security.*`, `site.base_url`, realm connection fields, and **all `features.*`** including `playtime_reward.enabled`.

> Foundation for a growing Customization page — home-page sections (client download + FAQ become content blocks there) are planned as further sections on the same page; secrets/bootstrap (`db.*`, `smtp.*`, `recaptcha.*`, `site.base_url`, feature flags) deliberately stay file-only in `config.php`.

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


---

[↑ Back to the README](../README.md)
