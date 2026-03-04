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
    ],

    // Realm Configuration
    'realm' => [
        'name' => 'Your Server Name',
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

    // News entries shown on the home page (newest first, max ~4 recommended)
    'news' => [
        ['date' => '2026-01-01', 'title' => 'Server Launch!', 'text' => 'Welcome to our server!', 'icon' => 'bi-megaphone'],
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
];
