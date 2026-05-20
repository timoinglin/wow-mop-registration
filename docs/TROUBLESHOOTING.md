# Troubleshooting

> Part of the **[WoW MoP Registration Portal](../README.md)** documentation — common issues and their fixes.
>
> [↑ Back to the README](../README.md) · [Docs index](../README.md#documentation)

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
| **`/news` shows "No news posts yet"** | The `web_news_posts` table is empty or missing. Re-run `sql/setup.sql` (idempotent), then refresh. The seed creates a starter "Welcome!" post. |
| **EasyMDE editor doesn't load on `/admin_news?new=1`** | The editor is loaded from `cdn.jsdelivr.net`. Check that your browser can reach the CDN, that nothing is blocking the script (corporate proxy, ad-blocker rules), and that JavaScript is enabled. The public news pages don't depend on the CDN. |
| **Image upload returns 415 "Unsupported"** | Server-side MIME sniff didn't recognize the file as one of jpg/png/webp/gif. Re-export from your image tool or convert with `magick input.bmp output.png`. The PHP `fileinfo` extension must be enabled (it is by default). |
| **Image upload returns 413** | File is over the 5 MB cap. Resize or re-compress and try again. |
| **Post images return 403 on the public page** | The `uploads/news/` directory is missing its override `.htaccess`. The parent `uploads/.htaccess` denies everything; the news subdir needs its own file to opt back in. See [Updating → Upgrading from v0.3.x](UPDATE.md#upgrading-from-v03x--v040) for the contents. |
| **Edit Post button doesn't show on `/news/{slug}`** | Confirm you're logged in **and** that your `gm_level` is ≥ 9. The session check is `$_SESSION['gm_level']`, which is set fresh at every login — if you changed your `account_access` row, log out and log back in. |
| **`/forum` shows "Forum is currently disabled"** | The forum's enable flag is off in the DB. Log in as GM 9+, go to `/admin_forum`, toggle **Forum enabled**. GMs always see the forum (with an admin-preview banner) regardless of the toggle. |
| **My new thread shows "waiting for admin approval" but doesn't appear in the category** | Expected — the auto-approve threshold isn't met yet. The thread is visible *to you* (with a yellow border + "Awaiting approval" pill) and to GMs (in `/admin_forum`'s moderation queue). Other users can't see it until a GM approves. Set the threshold to `0` to auto-publish everyone. |
| **"Please wait N seconds before posting again"** | Anti-spam cooldown — hardcoded 30 seconds between forum posts per user. GM 9+ bypasses. |
| **404 after a moderation action (sticky / lock / approve)** | Fixed in v0.5.0 (`e3f1039`). If you're on an older `main` snapshot, pull the latest. |
| **MySQL warning 1681 ("Integer display width is deprecated")** | Fixed in v0.5.0 — the original `TINYINT(1)` columns were narrowed to plain `TINYINT`. If you still see it, re-run `sql/setup.sql` against the newest schema. |
| **MySQL warning 3719 ("utf8 is currently an alias for UTF8MB3")** | Fixed in v0.5.0 — `sql/setup.sql` now runs an idempotent `CONVERT TO CHARACTER SET utf8mb4` block on the legacy tables. One re-run silences the warning permanently. |
| **EasyMDE fullscreen toolbar hidden behind navbar / second row clipped** | Fixed during Phase 5 polish. The site navbar auto-hides while EasyMDE is fullscreen, the toolbar is wrap-friendly, and floated icons are stripped. If you still see it, hard-refresh to bypass CSS cache. |

---

---

[↑ Back to the README](../README.md)
