<?php 
require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
$current_page = basename($_SERVER['PHP_SELF']);

// --- Maintenance Mode ---
if (!empty($config['features']['maintenance'])) {
    $gm_level = $_SESSION['gm_level'] ?? 0;
    if ((int)$gm_level < 9 && $current_page !== 'login.php') {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Maintenance</title>'
            . '<style>body{background:#0a0a0f;color:#c8a96e;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;}'
            . 'h1{font-size:3rem;margin-bottom:1rem;}p{font-size:1.2rem;color:#aaa;}</style></head><body>'
            . '<div><h1>&#9881; Under Maintenance</h1><p>' . htmlspecialchars($config['maintenance_message']) . '</p></div></body></html>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) : htmlspecialchars($config['site']['title']) ?></title>
    <link rel="icon" href="/favicon.ico">

    <?php
    // ── OpenGraph / Twitter Card meta ──────────────────────────────────────────
    // Pages can set $og_title, $og_description, $og_image, $og_type, $og_url
    // before requiring this header to override the defaults.
    $_og_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_og_host   = $_SERVER['HTTP_HOST'] ?? parse_url($config['site']['base_url'] ?? '', PHP_URL_HOST) ?? 'localhost';
    $_og_base   = $_og_scheme . '://' . $_og_host;

    $_og_title       = $og_title       ?? ($page_title ?? ($config['realm']['name'] ?? $config['site']['title']));
    // realm.description supports plain string OR per-language array
    $_raw_realm_desc = $config['realm']['description'] ?? 'World of Warcraft: Mists of Pandaria private server.';
    if (is_array($_raw_realm_desc)) {
        $_raw_realm_desc = $_raw_realm_desc[$lang] ?? ($_raw_realm_desc['en'] ?? reset($_raw_realm_desc) ?: '');
    }
    $_og_description = $og_description ?? $_raw_realm_desc;
    $_og_image       = $og_image       ?? ($_og_base . '/assets/img/logo.webp');
    $_og_type        = $og_type        ?? 'website';
    $_og_url         = $og_url         ?? ($_og_base . ($_SERVER['REQUEST_URI'] ?? '/'));

    // Trim/clamp description to a Discord-friendly length
    if (mb_strlen($_og_description) > 200) {
        $_og_description = mb_substr($_og_description, 0, 197) . '…';
    }
    ?>
    <meta name="description"        content="<?= htmlspecialchars($_og_description) ?>">
    <meta property="og:type"        content="<?= htmlspecialchars($_og_type) ?>">
    <meta property="og:site_name"   content="<?= htmlspecialchars($config['realm']['name'] ?? 'WoW') ?>">
    <meta property="og:title"       content="<?= htmlspecialchars($_og_title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($_og_description) ?>">
    <meta property="og:url"         content="<?= htmlspecialchars($_og_url) ?>">
    <meta property="og:image"       content="<?= htmlspecialchars($_og_image) ?>">
    <meta property="og:image:alt"   content="<?= htmlspecialchars($config['realm']['name'] ?? 'WoW') ?>">
    <meta property="og:locale"      content="<?= $lang === 'es' ? 'es_ES' : 'en_US' ?>">
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="<?= htmlspecialchars($_og_title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($_og_description) ?>">
    <meta name="twitter:image"       content="<?= htmlspecialchars($_og_image) ?>">
    <meta name="theme-color" content="#c8a96e">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">
    <!-- Google reCAPTCHA (only loaded when feature is enabled) -->
    <?php if (!empty($config['features']['recaptcha'])): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Custom Tab Styles -->
    <style>
        /* Custom Tab Styles */
        .nav-tabs .nav-link {
            /* Style for inactive tabs */
            color: #adb5bd; /* Lighter grey text */
            background-color: #343a40; /* Slightly lighter dark background */
            border: 1px solid #495057; /* Subtle border */
            border-bottom-color: transparent; /* Remove bottom border for inactive */
            margin-bottom: -1px; /* Overlap bottom border */
            border-top-left-radius: .375rem; /* Match Bootstrap's default radius */
            border-top-right-radius: .375rem;
        }

        .nav-tabs .nav-link:hover:not(.active) {
          /* Style for hover on inactive tabs */
          border-color: #6c757d; /* Slightly lighter border on hover */
          background-color: #495057; /* Darker background on hover */
          color: #dee2e6; /* Lighter text on hover */
        }

        .nav-tabs .nav-link.active,
        .nav-tabs .nav-item.show .nav-link {
            /* Style for the active tab - matching custom btn-primary */
            color: #fff; /* White text */
            background-color: #8B4513; /* Custom brownish-orange from button image */
            border-color: #A0522D; /* Match background color */
            font-weight: 500; /* Slightly bolder text */
        }
    </style>
    <?php if (!empty($extra_head)) { echo $extra_head; } ?>
</head>
<body class="bg-dark text-light">

<nav id="mainNavbar" class="navbar navbar-expand-lg navbar-dark fixed-top py-3 py-md-4 transition-all">
    <div class="container-fluid px-md-5">
        <a class="navbar-brand d-flex align-items-center" href="/">
            <img src="/assets/img/top-logo.webp" alt="<?= $TEXT['nav_logo_alt'] ?>" style="height: 28px;">
        </a>
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 nav-main">
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'index.php') ? 'active' : '' ?>" href="/">
                        <i class="bi bi-house-door-fill me-1"></i> <?= $TEXT['home'] ?? 'Home' ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'news.php') ? 'active' : '' ?>" href="/news">
                        <i class="bi bi-newspaper me-1"></i> <?= $TEXT['news_nav'] ?? 'News' ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'armory.php') ? 'active' : '' ?>" href="/armory">
                        <i class="bi bi-search me-1"></i> <?= $TEXT['armory'] ?? 'Armory' ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'leaderboards.php') ? 'active' : '' ?>" href="/leaderboards">
                        <i class="bi bi-trophy-fill me-1"></i> <?= $TEXT['leaderboards'] ?? 'Leaderboards' ?>
                    </a>
                </li>
            </ul>
            <div class="navbar-nav ms-auto align-items-center">
                <!-- User Menu Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link nav-user dropdown-toggle d-flex align-items-center px-3" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle fs-5 <?= isset($_SESSION['user_id']) ? 'me-md-2' : '' ?>"></i>
                        <?php if (isset($_SESSION['username'])): ?>
                            <span class="d-none d-md-inline nav-user-name"><?= htmlspecialchars($_SESSION['username']) ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu game-dropdown dropdown-menu-end" aria-labelledby="userDropdown">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <li><a class="dropdown-item py-2 <?= ($current_page === 'dashboard.php') ? 'active' : '' ?>" href="/dashboard"><i class="bi bi-speedometer2 me-3 text-primary"></i><?= $TEXT['dashboard'] ?></a></li>
                            <?php if (isset($_SESSION['gm_level']) && $_SESSION['gm_level'] >= 1): ?>
                                <?php if ($_SESSION['gm_level'] >= 9): ?>
                                    <li><a class="dropdown-item py-2 <?= ($current_page === 'admin_dashboard.php') ? 'active' : '' ?>" href="/admin_dashboard"><i class="bi bi-shield-lock me-3 text-danger"></i><?= $TEXT['admin_panel'] ?></a></li>
                                <?php else: ?>
                                    <li><span class="dropdown-item py-2 disabled" style="opacity:.5;cursor:not-allowed" title="<?= sprintf(htmlspecialchars($TEXT['admin_panel_requires'] ?? 'Requires GM rank %d+ (you are %d)'), 9, (int)$_SESSION['gm_level']) ?>">
                                        <i class="bi bi-shield-lock me-3 text-secondary"></i><?= $TEXT['admin_panel'] ?> <small style="color:#6c7a8c">(GM 9+)</small>
                                    </span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($config['features']['tickets'])): ?>
                            <li><a class="dropdown-item py-2 <?= ($current_page === 'tickets.php') ? 'active' : '' ?>" href="/tickets"><i class="bi bi-ticket-perforated me-3 text-warning"></i><?= $TEXT['submit_ticket'] ?></a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider border-secondary opacity-25"></li>
                            <li><a class="dropdown-item py-2 <?= ($current_page === 'logout.php') ? 'active' : '' ?>" href="/logout"><i class="bi bi-box-arrow-right me-3 text-secondary"></i><?= $TEXT['logout'] ?></a></li>
                        <?php else: ?>
                            <li><a class="dropdown-item py-2 <?= ($current_page === 'register.php') ? 'active' : '' ?>" href="/register"><i class="bi bi-person-plus me-3 text-success"></i><?= $TEXT['register'] ?></a></li>
                            <li><a class="dropdown-item py-2 <?= ($current_page === 'login.php') ? 'active' : '' ?>" href="/login"><i class="bi bi-box-arrow-in-right me-3 text-info"></i><?= $TEXT['login'] ?></a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <!-- Language Dropdown -->
                <li class="nav-item dropdown ms-2">
                    <a class="nav-link dropdown-toggle btn btn-sm btn-outline-secondary text-white border-0" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius: 20px; padding: 0.4rem 1rem;">
                        <i class="bi bi-globe me-1"></i> <?= strtoupper($lang) ?>
                    </a>
                    <ul class="dropdown-menu game-dropdown dropdown-menu-end" aria-labelledby="languageDropdown">
                        <li><a class="dropdown-item py-2 <?= ($lang === 'en') ? 'active' : '' ?>" href="?lang=en">EN <span class="text-white-50 ms-2">(English)</span></a></li>
                        <li><a class="dropdown-item py-2 <?= ($lang === 'es') ? 'active' : '' ?>" href="?lang=es">ES <span class="text-white-50 ms-2">(Español)</span></a></li>
                    </ul>
                </li>
            </div>
        </div>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serverLang = '<?= $lang ?>'; // Get the language loaded by PHP
    const storedLang = localStorage.getItem('preferred_language');
    const urlParams = new URLSearchParams(window.location.search);
    const langParam = urlParams.get('lang');
    const allowedLangs = ['en', 'es']; // Keep this in sync with PHP

    // Redirect if localStorage preference exists, differs from server, and wasn't just set via URL
    if (storedLang && storedLang !== serverLang && !langParam && allowedLangs.includes(storedLang)) {
        console.log(`Redirecting: stored=${storedLang}, server=${serverLang}`);
        const currentPath = window.location.pathname.replace(/\.php$/, ''); // Use path without .php
        const currentUrl = new URL(currentPath, window.location.origin);
        currentUrl.searchParams.set('lang', storedLang);
        window.location.href = currentUrl.toString(); // Redirect to set language via GET param on pretty URL
        return; // Stop further execution to allow redirect
    }

    // Update language link handling for pretty URLs
    const langLinks = document.querySelectorAll('#languageDropdown .dropdown-item');
    langLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default link navigation

            const url = new URL(this.href, window.location.origin); // Ensure base URL
            const newLang = url.searchParams.get('lang');
            const allowedLangs = ['en', 'es'];

            if (newLang && allowedLangs.includes(newLang)) {
                console.log(`Storing preferred_language: ${newLang}`);
                localStorage.setItem('preferred_language', newLang);

                // Construct the new URL *without* the .php extension
                const currentPath = window.location.pathname.replace(/\.php$/, '');
                const targetUrl = new URL(currentPath, window.location.origin);
                targetUrl.searchParams.set('lang', newLang);

                console.log(`Redirecting to language URL: ${targetUrl.toString()}`);
                window.location.href = targetUrl.toString(); // Navigate to the pretty URL with lang param
            } else {
                console.warn('Could not determine language from link:', this.href);
            }
        });
    });
});
</script>
