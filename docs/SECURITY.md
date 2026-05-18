# Security Notes

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — what's protected and what you must configure.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

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
- **Site Customization** (`/admin_customization`, GM 9+, CSRF + audit-logged): branding uploads (logo / favicon / hero bg) are MIME-sniffed, size-capped and server-renamed into `uploads/branding/`, whose tracked `.htaccess` blocks script extensions **plus `.svg`** (SVG can carry script). The Theme tab's optional custom-CSS box is opt-in and sanitised (tags, `\`, `@import`, `expression()`, `javascript:` stripped). The home-page designer accepts **predefined section types with structured fields only — no raw HTML**; section text renders through the same Parsedown safe-mode, URLs are scheme-checked, icons are `bi-*` allow-listed. All customization persists to the `site_settings` DB table — secrets & bootstrap (Ko-fi webhook token, `db`/`smtp`/`recaptcha`, `site.base_url`, every `features.*`) deliberately stay file-only in `config.php` and are never web-editable.
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


---

[↑ Back to the README](../README.md)
