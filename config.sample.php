<?php
// config.sample.php
// Rename this file to config.php and fill in your actual settings.
// EmuCoach repack default DB credentials: user=root, password=ascent

return [
    // Database Configuration
    // EmuCoach repack defaults: host=127.0.0.1, user=root, password=ascent
    'db' => [
        'host'       => '127.0.0.1',
        'port'       => '3306',
        'user'       => 'root',         // EmuCoach default: root
        'password'   => 'ascent',       // EmuCoach default: ascent
        'name_auth'  => 'auth',         // EmuCoach default auth DB
        'name_chars' => 'characters',   // EmuCoach default characters DB
        'name_world' => 'world',        // World DB — only needed for the in-game
                                        // Shop management feature. Some repacks
                                        // name it 'mop_world'. Safe to leave as-is
                                        // if you don't use shop management.
    ],

    // Realm Configuration
    'realm' => [
        'name' => 'Your Server Name',
        // Single string, or per-language array, e.g.:
        //   'description' => ['en' => '...', 'es' => '...']
        'description' => 'Your Server Description (e.g. x2 XP, Progressive Release)',
        'realmlist' => 'logon.yourserver.com',
        'expansion' => 5, // 5 for MoP, change if another expansion is used
        'auth_port' => 3724,
        'world_port' => 8085,
    ],

    // SMTP / Email Configuration for PHPMailer
    'smtp' => [
        'host' => 'smtp.example.com', // e.g. smtp.gmail.com
        'auth' => true,
        'username' => 'your_smtp_username_here',
        'password' => 'your_smtp_password_here',
        'secure' => 'ssl', // 'ssl' or 'tls'
        'port' => 465, // 465 for SSL, 587 for TLS
        'from_email' => 'no-reply@yourserver.com',
        'from_name' => 'Your Server Accounts',
        'support_from_name' => 'Your Server Support',
        'ticket_recipient'  => 'support@yourserver.com', // Email address that receives support tickets
    ],

    // Google reCAPTCHA v2 Constants
    'recaptcha' => [
        'site_key' => 'YOUR_RECAPTCHA_SITE_KEY_HERE',
        'secret_key' => 'YOUR_RECAPTCHA_SECRET_KEY_HERE'
    ],

    'site' => [
        'title'    => 'Your Server - Register',
        'base_url' => 'http://yourserver.com',
    ],

    // Client Download Options
    'client' => [
        'download_link' => 'https://example.com/your-client-download-link', // External direct download or share link (e.g., Mega, MediaFire, Google Drive)
    ],

    // Feature Flags — set to false to disable a feature site-wide
    'features' => [
        'recaptcha'        => true,  // Google reCAPTCHA on all forms
        'recover_password' => true,  // Password recovery via email (requires SMTP)
        'tickets'          => true,  // Support ticket system
        'maintenance'      => false, // Maintenance mode (GMs can still log in)
        'shop_admin'       => false, // In-game Battle Pay shop management.
                                     // Requires a reachable world DB (name_world)
                                     // with battle_pay_* tables. Off by default;
                                     // the page degrades gracefully if enabled
                                     // but the DB/tables aren't present.
        'shop'             => false, // Public user-facing shop catalog (/shop):
                                     // a read-only list of what's buyable in-game.
                                     // Independent of shop_admin and donations.
        'donations'        => false, // Ko-fi donate button + DP crediting.
                                     // Independent of 'shop' — you can show the
                                     // catalog with donations off, or vice versa.
                                     // Needs Ko-fi config (see 'donation' block).
    ],

    // Login Security — brute-force protection
    'security' => [
        'max_login_attempts' => 5,   // Max failed attempts before lockout
        'lockout_minutes'    => 15,  // Lockout duration in minutes
    ],

    // Maintenance Mode Message
    'maintenance_message' => 'The server is currently undergoing maintenance. Please check back soon!',

    // Social Links — set to '' (empty string) to hide a link
    'social' => [
        'discord'   => 'https://discord.gg/your-invite',
        'youtube'   => 'https://www.youtube.com/',
        'twitter'   => 'https://x.com/home',   // X (formerly Twitter)
        'instagram' => 'https://www.instagram.com/',
    ],

    // FAQ entries shown on the home page
    'faq' => [
        ['q' => 'Is it free to play?',      'a' => 'Yes! Registration and gameplay are completely free.'],
        ['q' => 'What expansion is this?',   'a' => 'Mists of Pandaria (5.4.8).'],
        ['q' => 'Can I play with friends?',  'a' => 'Absolutely! Cross-faction grouping is enabled.'],
        ['q' => 'How do I connect?',         'a' => 'Register, download the client, set your realmlist, and play!'],
    ],

    // Vote sites for the Vote & Reward system (empty = feature hidden)
    'vote_sites' => [
        ['name' => 'Top100Arena', 'url' => 'https://top100arena.com/in/your-server', 'cooldown_hours' => 12],
    ],

    // Playtime Reward — automatically grants Battle Pay (DP) for time spent in-game.
    // Calculated from SUM(characters.totaltime) per account, so AFK still counts but
    // login/logout farming doesn't help. Players claim from the dashboard.
    //
    // Defaults below: a player at the daily cap earns ~1500 DP/month.
    //   - dp_per_hour:     5 hours/day at 10 DP = 50 DP/day cap
    //   - daily_cap_dp:    20-hour AFK farmer is bounded at 50 DP/day too
    'playtime_reward' => [
        'enabled'      => true,
        'dp_per_hour'  => 10,   // DP awarded per hour played
        'daily_cap_dp' => 50,   // max DP earnable per server-day
    ],

    // Ko-fi Donations — automatic Battle Pay (DP) crediting from Ko-fi.
    // Only used when features.donations = true. Ko-fi is the ONLY supported
    // processor by design: it's free, offers a webhook on the free tier, and
    // needs no merchant identity verification. (PayPal/Stripe are deliberately
    // out of scope — once real money flows you inherit refunds, chargebacks,
    // tax and fraud; one well-supported path beats four half-supported ones.)
    //
    // One-time setup:
    //   1. Create a free Ko-fi account and set your page currency.
    //   2. Ko-fi dashboard → Settings → Advanced → API/Webhooks:
    //        - copy the Verification Token into kofi_verification_token below
    //        - set the Webhook URL to:  https://<your-site>/kofi_webhook
    //   3. Pick your rate + currency below, then set features.donations = true.
    //
    // How a donation reaches the right account: a logged-in player opens /shop,
    // copies their personal code, and pastes it into the Ko-fi message field.
    // The webhook reads the real paid amount, so the donate button is fully
    // dynamic — the donor chooses any amount, DP = floor(amount × rate).
    'donation' => [
        // Ko-fi → Settings → API → "Verification Token". The webhook rejects
        // any delivery whose token doesn't match this exactly. KEEP SECRET.
        'kofi_verification_token' => 'YOUR_KOFI_VERIFICATION_TOKEN_HERE',

        // DP granted per 1.00 unit of your Ko-fi currency. This is just the
        // bootstrap default — once a GM sets the rate in /admin_shop it is
        // stored in the DB and that UI value wins (this line is then ignored).
        // 1000 is tuned to typical in-game prices (a normal item ≈ 1–5k
        // Battle Coins, a premium mount ≈ 25k): e.g. a 5.00 donation → 5,000
        // DP (≈ a couple of items), 25.00 → a top-tier mount. floor() applied.
        'eur_to_dp_rate' => 1000,

        // Your Ko-fi page currency. Label/display only — Ko-fi sends the
        // amount already in this currency; there is no conversion table.
        'currency' => 'EUR',

        // Donations below this amount (in currency units) are logged but
        // credit 0 DP (status = ignored). Set to 0 to credit everything.
        'min_amount' => 1,

        // Your public Ko-fi page — the "Donate" button links here.
        'kofi_url' => 'https://ko-fi.com/yourpage',
    ],
];
