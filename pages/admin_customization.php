<?php
/**
 * Admin: Site Customization (GM 9+ only).
 *
 * First section: Footer. Toggle the built-in quick-links and add custom
 * label+URL rows (e.g. a donations-disclaimer link). Stored in the
 * `site_settings` table via includes/site_settings.php — config.php stays
 * the bootstrap; this is a DB override that survives updates.
 *
 * Built on the shared site_settings foundation so future sections
 * (home-page, theming) become more cards here without re-architecting.
 * PRG + CSRF + audit-logged, same conventions as /admin_forum.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/site_settings.php';

// GM 9+ guard (same as /admin_forum, /admin_shop)
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm->fetchColumn() ?: 0);
if ($gm_level < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $gm_level;

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

$redirect = function (string $key = '', string $val = '1') {
    $u = '/admin_customization';
    if ($key !== '') $u .= '?' . urlencode($key) . '=' . urlencode($val);
    header('Location: ' . $u);
    exit;
};

// ─── POST (PRG) ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('err', 'csrf');
    }
    if (($_POST['action'] ?? '') === 'save_footer') {
        $builtin = [];
        foreach (['home', 'register', 'login', 'support'] as $k) {
            $builtin[$k] = !empty($_POST['bi'][$k]) ? 1 : 0;
        }
        $custom = [];
        $labels = $_POST['c_label'] ?? [];
        $urls   = $_POST['c_url']   ?? [];
        if (is_array($labels)) {
            foreach ($labels as $i => $lbl) {
                $label = trim((string)$lbl);
                $url   = trim((string)($urls[$i] ?? ''));
                if ($label === '' || $url === '') continue;
                if (!footer_link_url_ok($url)) continue;
                $custom[] = ['label' => mb_substr($label, 0, 40), 'url' => $url];
                if (count($custom) >= 12) break;
            }
        }
        if (site_setting_set($pdo_auth, 'footer', ['builtin' => $builtin, 'custom' => $custom])) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'site_footer_update', null,
                'builtin=' . json_encode($builtin) . ' custom=' . count($custom), null);
            $redirect('saved', 'footer');
        }
        $redirect('err', 'save');
    }
    if (($_POST['action'] ?? '') === 'save_languages') {
        // Enabled = boxes ticked; disabled = the rest. EN is never disabled.
        $available = array_keys(languages_available());
        $enabled   = is_array($_POST['lang'] ?? null) ? array_keys($_POST['lang']) : [];
        $disabled  = [];
        foreach ($available as $code) {
            if ($code === 'en') continue;
            if (!in_array($code, $enabled, true)) $disabled[] = $code;
        }
        if (site_setting_set($pdo_auth, 'languages', ['disabled' => $disabled])) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'site_languages_update', null,
                'disabled=' . json_encode($disabled), null);
            $redirect('saved', 'languages');
        }
        $redirect('err', 'save');
    }
    if (($_POST['action'] ?? '') === 'save_theme') {
        $cur = theme_get($pdo_auth);          // start from the live theme…
        $new = $cur;                          // …and only change what was posted.

        // — Colours —
        $acc = strtolower(trim((string)($_POST['accent'] ?? '')));
        $new['accent'] = theme_hex_ok($acc) ? $acc : THEME_ACCENT_DEFAULT;
        foreach (['bg_dark', 'bg_card', 'text'] as $k) {
            $v = strtolower(trim((string)($_POST[$k] ?? '')));
            $new[$k] = ($v === '') ? '' : (theme_hex_ok($v) ? $v : $cur[$k]);
        }
        $new['preset']       = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['preset'] ?? '')));
        $new['custom_css_on'] = !empty($_POST['custom_css_on']) ? 1 : 0;
        $new['custom_css']    = theme_sanitize_css((string)($_POST['custom_css'] ?? ''));

        // — Branding uploads / resets —
        $brand_dir = __DIR__ . '/../uploads/branding';
        if (!is_dir($brand_dir) && !@mkdir($brand_dir, 0775, true) && !is_dir($brand_dir)) {
            error_log('admin_customization: cannot create ' . $brand_dir);
            $redirect('err', 'upload');
        }
        // slot => [maxBytes, [mime => ext]]
        $img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $slots = [
            'logo_main' => [3 * 1024 * 1024,  $img],
            'logo_top'  => [3 * 1024 * 1024,  $img],
            'favicon'   => [512 * 1024,       ['image/png' => 'png', 'image/webp' => 'webp',
                                               'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico']],
            'header_bg' => [25 * 1024 * 1024, $img + ['video/mp4' => 'mp4', 'video/webm' => 'webm']],
        ];
        $clear_slot = function (string $slot) use ($brand_dir) {
            foreach (glob($brand_dir . '/' . $slot . '.*') ?: [] as $f) {
                if (is_file($f)) @unlink($f);
            }
        };
        $upload_err = null;
        foreach ($slots as $slot => [$max, $allowed]) {
            if (!empty($_POST['reset'][$slot])) {            // explicit "remove" wins
                $clear_slot($slot);
                $new[$slot] = '';
                if ($slot === 'header_bg') $new['header_bg_kind'] = '';
                continue;
            }
            $f = $_FILES[$slot] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;                                    // nothing posted — keep existing
            }
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $upload_err = 'upload'; continue; }
            if ((int)$f['size'] > $max)               { $upload_err = 'big';    continue; }
            $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: '') : '';
            if (!isset($allowed[$mime]))              { $upload_err = 'type';   continue; }
            $ext  = $allowed[$mime];
            $clear_slot($slot);                              // drop any prior different-ext file
            $dest = $brand_dir . '/' . $slot . '.' . $ext;
            if (!@move_uploaded_file($f['tmp_name'], $dest)) {
                error_log('admin_customization: move_uploaded_file failed for ' . $dest);
                $upload_err = 'upload';
                continue;
            }
            @chmod($dest, 0644);
            $new[$slot] = '/uploads/branding/' . $slot . '.' . $ext;
            if ($slot === 'header_bg') {
                $new['header_bg_kind'] = (strncmp($mime, 'image/', 6) === 0) ? 'image' : 'video';
            }
        }

        if (site_setting_set($pdo_auth, 'theme', $new)) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'site_theme_update', null,
                'accent=' . $new['accent'] . ' css_on=' . $new['custom_css_on']
                . ' logo_main=' . ($new['logo_main'] !== '' ? '1' : '0')
                . ' logo_top=' . ($new['logo_top'] !== '' ? '1' : '0')
                . ' favicon=' . ($new['favicon'] !== '' ? '1' : '0')
                . ' header_bg=' . ($new['header_bg_kind'] ?: '0'), null);
            $redirect('saved', $upload_err ? ('theme_' . $upload_err) : 'theme');
        }
        $redirect('err', 'save');
    }
    if (($_POST['action'] ?? '') === 'save_settings') {
        // Only the presentational keys. Blank = "no override" → config seed.
        $new = [
            'site_title'        => settings_clean_text($_POST['site_title'] ?? '', 80),
            'realm_name'        => settings_clean_text($_POST['realm_name'] ?? '', 80),
            'realm_description' => settings_clean_text($_POST['realm_description'] ?? '', 300),
            'social'            => [],
            'kofi_url'          => '',
            'currency'          => '',
            'min_amount'        => '',
            'dp_per_hour'       => '',
            'daily_cap_dp'      => '',
            'vote_sites'        => [],
        ];
        foreach (['discord', 'youtube', 'twitter', 'instagram'] as $k) {
            $u = trim((string)($_POST['social'][$k] ?? ''));
            $new['social'][$k] = ($u === '' || settings_url_ok($u)) ? mb_substr($u, 0, 300) : '';
        }
        $ku = trim((string)($_POST['kofi_url'] ?? ''));
        $new['kofi_url'] = ($ku === '' || settings_url_ok($ku)) ? mb_substr($ku, 0, 300) : '';
        $cc = strtoupper(trim((string)($_POST['currency'] ?? '')));
        $new['currency'] = preg_match('/^[A-Z]{3}$/', $cc) ? $cc : '';
        // numeric tunables: '' kept as "use config", else clamped
        $ma = trim((string)($_POST['min_amount'] ?? ''));
        $new['min_amount'] = ($ma === '') ? '' : (string)(settings_int_or_null($ma, 0, 100000) ?? '');
        $dh = trim((string)($_POST['dp_per_hour'] ?? ''));
        $new['dp_per_hour'] = ($dh === '') ? '' : (string)(settings_int_or_null($dh, 0, 10000) ?? '');
        $dc = trim((string)($_POST['daily_cap_dp'] ?? ''));
        $new['daily_cap_dp'] = ($dc === '') ? '' : (string)(settings_int_or_null($dc, 0, 1000000) ?? '');

        $vn = $_POST['v_name'] ?? [];
        $vu = $_POST['v_url']  ?? [];
        $vc = $_POST['v_cd']   ?? [];
        if (is_array($vn)) {
            foreach ($vn as $i => $nm) {
                $n = settings_clean_text($nm, 60);
                $u = trim((string)($vu[$i] ?? ''));
                if ($n === '' || !settings_url_ok($u)) continue;
                $new['vote_sites'][] = [
                    'name'           => $n,
                    'url'            => mb_substr($u, 0, 300),
                    'cooldown_hours' => settings_int_or_null($vc[$i] ?? 12, 1, 8760) ?? 12,
                ];
                if (count($new['vote_sites']) >= 20) break;
            }
        }

        if (site_setting_set($pdo_auth, 'settings', $new)) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'site_settings_update', null,
                'identity=' . (($new['site_title'] !== '' || $new['realm_name'] !== '') ? '1' : '0')
                . ' social=' . count(array_filter($new['social']))
                . ' votes=' . count($new['vote_sites']), null);
            $redirect('saved', 'settings');
        }
        $redirect('err', 'save');
    }
    if (($_POST['action'] ?? '') === 'save_homepage') {
        require_once __DIR__ . '/../includes/homepage.php';
        // $_POST['hp'] is keyed by section id; PHP preserves the submission
        // order = DOM order = the drag-sorted order. Hero is pinned first.
        $posted   = is_array($_POST['hp'] ?? null) ? $_POST['hp'] : [];
        $builtins = homepage_builtin_keys();
        $customs  = homepage_custom_types();

        $layout = [['id' => 'hero', 'type' => 'hero',
                    'on' => !empty($posted['hero']['on']) ? 1 : 0]];
        foreach ($posted as $id => $f) {
            if ($id === 'hero' || !is_array($f)) continue;
            $type = (string)($f['type'] ?? '');
            $on   = !empty($f['on']) ? 1 : 0;
            if ($type !== 'hero' && in_array($type, $builtins, true)) {
                $layout[] = ['id' => $type, 'type' => $type, 'on' => $on];
            } elseif (in_array($type, $customs, true)) {
                $cid = (string)$id;
                if (!preg_match('/^c_[a-z0-9]{2,16}$/', $cid)) {
                    $cid = 'c_' . substr(bin2hex(random_bytes(6)), 0, 10);
                }
                $layout[] = [
                    'id'   => $cid,
                    'type' => $type,
                    'on'   => $on,
                    'data' => homepage_sanitize_custom_data($type, $f['data'] ?? []),
                ];
            }
        }
        // Normalise: de-dupe, re-clean, ensure every built-in is present.
        $layout = homepage_normalize_layout($layout);
        if (site_setting_set($pdo_auth, 'homepage', $layout)) {
            $n_custom = 0;
            foreach ($layout as $s) { if (!in_array($s['type'], $builtins, true)) $n_custom++; }
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'site_homepage_update', null,
                'sections=' . count($layout) . ' custom=' . $n_custom, null);
            $redirect('saved', 'homepage');
        }
        $redirect('err', 'save');
    }
    $redirect();
}

// ─── GET ────────────────────────────────────────────────────────────────────
$flash = '';
$saved = $_GET['saved'] ?? '';
if ($saved === 'footer') {
    $flash = $TEXT['cz_saved_footer'] ?? 'Footer saved.';
} elseif ($saved === 'languages') {
    $flash = $TEXT['cz_saved_languages'] ?? 'Languages saved.';
} elseif ($saved === 'theme') {
    $flash = $TEXT['cz_saved_theme'] ?? 'Theme saved.';
} elseif (strncmp($saved, 'theme_', 6) === 0) {
    // saved, but one of the file uploads was rejected
    $flash = $TEXT['cz_saved_theme'] ?? 'Theme saved.';
} elseif ($saved === 'settings') {
    $flash = $TEXT['cz_saved_settings'] ?? 'Settings saved.';
} elseif ($saved === 'homepage') {
    $flash = $TEXT['cz_saved_homepage'] ?? 'Home page saved.';
}
$flash_err = '';
if (strncmp($saved, 'theme_', 6) === 0) {
    $why = substr($saved, 6);
    $flash_err = $why === 'big'  ? ($TEXT['cz_theme_err_big']  ?? 'A file was too large and was skipped — the rest was saved.')
              : ($why === 'type' ? ($TEXT['cz_theme_err_type'] ?? 'A file had an unsupported format and was skipped — the rest was saved.')
              :                    ($TEXT['cz_theme_err_upload'] ?? 'A file upload failed and was skipped — the rest was saved.'));
}
if (isset($_GET['err'])) {
    $flash_err = $_GET['err'] === 'csrf'
        ? ($TEXT['cz_err_csrf'] ?? 'Session expired. Please try again.')
        : ($_GET['err'] === 'upload'
            ? ($TEXT['cz_theme_err_dir'] ?? 'Could not write the branding upload folder.')
            : ($TEXT['cz_err_save'] ?? 'Could not save changes.'));
}

$footer = footer_links_get($pdo_auth);
$langs_all      = languages_available();
$langs_disabled = languages_disabled($pdo_auth);
$theme          = theme_get($pdo_auth);
$settings       = settings_get($pdo_auth, $config);
$set_raw        = $settings['_raw'] ?? [];   // stored overrides, to repopulate the form blank-where-unset
require_once __DIR__ . '/../includes/homepage.php';
$hp_layout      = homepage_layout_get($pdo_auth, $config);

$page_title = ($TEXT['cz_title'] ?? 'Site Customization') . ' — ' . $settings['site_title'];
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();

$bi_labels = [
    'home'     => $TEXT['home'] ?? 'Home',
    'register' => $TEXT['register'] ?? 'Register',
    'login'    => $TEXT['login'] ?? 'Login',
    'support'  => $TEXT['footer_support'] ?? 'Support',
];
?>
<style>
.cz-wrap { padding-top:120px; padding-bottom:3rem; }
.cz-card {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border:1px solid rgba(var(--btn-bg-rgb), .3); border-radius:12px;
    padding:1.4rem 1.6rem; margin-bottom:1.4rem;
}
.cz-card h2 { color:var(--accent); font-size:1.15rem; font-weight:700; margin:0 0 .3rem; }
.cz-card .sub { color:#8899aa; font-size:.85rem; margin-bottom:1.1rem; }
.cz-label { color:var(--accent); font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; font-weight:600; display:block; margin:1rem 0 .5rem; }
.cz-input {
    background:#0a0a0f; border:1px solid rgba(var(--btn-bg-rgb), .35); border-radius:6px;
    color:#dee2e6; padding:.5rem .7rem; font-size:.9rem; font-family:inherit; width:100%;
}
.cz-input:focus { outline:none; border-color:rgba(200,169,110,.6); }
.cz-bi { display:flex; flex-wrap:wrap; gap:1.3rem; padding:.3rem 0 .2rem; }
.cz-bi label { display:flex; align-items:center; gap:.45rem; color:#dee2e6; font-size:.9rem; cursor:pointer; }
.cz-row { display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; }
.cz-row .cz-input.lbl { max-width:200px; }
.cz-btn {
    background:var(--btn-bg); color:#fff; border:1px solid var(--btn-bg-hover); border-radius:6px;
    padding:.55rem 1.1rem; font-size:.88rem; cursor:pointer; font-family:inherit; text-decoration:none;
    display:inline-block; transition:background .14s ease;
}
.cz-btn:hover { background:var(--btn-bg-hover); color:#fff; }
.cz-btn-sm { padding:.4rem .6rem; font-size:.8rem; }
.cz-btn-ghost { background:transparent; color:var(--accent); border-color:rgba(200,169,110,.4); }
.cz-del { background:rgba(231,76,60,.15); border:1px solid rgba(231,76,60,.4); color:#f87e8a; }
.cz-del:hover { background:rgba(231,76,60,.3); color:#fff; }
.cz-flash-ok  { background:rgba(46,125,50,.15); border:1px solid rgba(46,125,50,.5); color:#9ae6a4; padding:.7rem 1rem; border-radius:8px; margin-bottom:1rem; }
.cz-flash-err { background:rgba(231,76,60,.12); border:1px solid rgba(231,76,60,.5); color:#f8b4b4; padding:.7rem 1rem; border-radius:8px; margin-bottom:1rem; }
.cz-prev { background:#0a0a0f; border:1px solid rgba(var(--btn-bg-rgb), .25); border-radius:8px; padding:.9rem 1.1rem; margin-top:1rem; font-size:.85rem; }
.cz-prev a { color:rgba(255,255,255,.6); text-decoration:none; }
.cz-prev a:hover { color:var(--accent); }
.cz-prev .sep { color:rgba(255,255,255,.2); margin:0 .5rem; }
.cz-hint { color:#4a5568; font-size:.78rem; margin-top:.5rem; }
/* Theme card */
.cz-color-row { display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; }
.cz-color-row input[type=color] {
    width:46px; height:38px; padding:0; border:1px solid rgba(var(--btn-bg-rgb), .35);
    border-radius:6px; background:#0a0a0f; cursor:pointer;
}
.cz-presets { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.8rem; }
.cz-preset {
    display:flex; align-items:center; gap:.45rem; background:#0a0a0f;
    border:1px solid rgba(var(--btn-bg-rgb), .35); color:#dee2e6; border-radius:20px;
    padding:.35rem .8rem; font-size:.8rem; cursor:pointer; font-family:inherit;
}
.cz-preset:hover { border-color:rgba(200,169,110,.6); }
.cz-dot { width:13px; height:13px; border-radius:50%; box-shadow:0 0 0 1px rgba(255,255,255,.15); }
.cz-theme-prev { margin-top:1.1rem; --p:#c89b3c; }
.cz-prev-demo { display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap; }
.cz-prev-btn {
    background:var(--p); color:#1a1206; font-weight:700; border-radius:6px;
    padding:.45rem 1.1rem; font-size:.85rem; box-shadow:0 0 16px -2px var(--p);
}
.cz-prev-link { color:var(--p); text-decoration:none; font-size:.88rem; border-bottom:1px dashed var(--p); }
.cz-prev-chip {
    border:1px solid var(--p); color:var(--p); border-radius:20px;
    padding:.2rem .7rem; font-size:.75rem; text-transform:uppercase; letter-spacing:.5px;
}
.cz-tone { display:flex; flex-wrap:wrap; gap:1rem; }
.cz-tone-item { display:flex; flex-direction:column; gap:.3rem; font-size:.82rem; color:#9aa7b4; }
.cz-tone-item .cz-input { max-width:150px; }
.cz-brand {
    border:1px solid rgba(var(--btn-bg-rgb), .22); border-radius:8px;
    padding:.85rem 1rem; margin-bottom:.7rem; background:#0a0a0f;
}
.cz-brand-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:.6rem; margin-bottom:.6rem; }
.cz-brand-head strong { color:#dee2e6; font-size:.92rem; }
.cz-brand-body { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.cz-brand-thumb {
    width:84px; height:54px; object-fit:contain; background:#161616;
    border:1px solid rgba(var(--btn-bg-rgb), .3); border-radius:6px;
}
.cz-brand-none { color:#4a5568; font-size:.8rem; font-style:italic; }
.cz-brand-ctl { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.cz-file { color:#9aa7b4; font-size:.82rem; max-width:260px; }
.cz-file::file-selector-button {
    background:#1e1e1e; color:var(--accent); border:1px solid rgba(var(--btn-bg-rgb), .4);
    border-radius:5px; padding:.35rem .7rem; font-size:.8rem; cursor:pointer; margin-right:.6rem;
}
.cz-reset, .cz-adv-toggle { display:flex; align-items:center; gap:.45rem; color:#f0a; font-size:.8rem; cursor:pointer; }
.cz-reset { color:#f87e8a; }
.cz-adv-toggle { color:#dee2e6; font-size:.88rem; }
/* Settings card */
.cz-set-grid { display:flex; flex-wrap:wrap; gap:1rem; }
.cz-set-field { display:flex; flex-direction:column; gap:.3rem; font-size:.82rem; color:#9aa7b4; flex:1 1 280px; }
.cz-set-field span { color:#9aa7b4; }
.cz-vote-row { display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; flex-wrap:wrap; }
.cz-vote-row .cz-input { flex:1 1 160px; }
.cz-vote-row .cz-vote-cd { flex:0 0 90px; max-width:90px; }
/* Home page designer */
.hp-sort { display:flex; flex-direction:column; gap:.5rem; margin:.5rem 0 .9rem; }
.hp-row { background:#0a0a0f; border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:8px; }
.hp-row.hp-hero { margin:.6rem 0 .3rem; border-color:rgba(var(--accent-rgb),.35); }
.hp-row.sortable-ghost { opacity:.4; }
.hp-row.sortable-chosen { border-color:rgba(var(--accent-rgb),.6); }
.hp-bar { display:flex; align-items:center; gap:.6rem; padding:.6rem .8rem; flex-wrap:wrap; }
.hp-grip { cursor:grab; color:#8899aa; font-size:1.1rem; }
.hp-grip-off { cursor:default; color:var(--accent); }
.hp-tico { color:var(--accent); }
.hp-name { color:#dee2e6; font-weight:600; font-size:.92rem; }
.hp-tag { font-size:.66rem; text-transform:uppercase; letter-spacing:.5px; color:#8899aa; border:1px solid rgba(255,255,255,.12); border-radius:4px; padding:.05rem .35rem; }
.hp-tag-c { color:var(--accent); border-color:rgba(var(--accent-rgb),.4); }
.hp-switch { display:flex; align-items:center; gap:.35rem; margin-left:auto; color:#9aa7b4; font-size:.8rem; cursor:pointer; }
.hp-edit-panel { padding:.2rem .8rem .9rem; display:flex; flex-direction:column; gap:.4rem; }
.hp-card-row, .hp-faq-row { display:flex; gap:.4rem; align-items:center; margin-bottom:.4rem; flex-wrap:wrap; }
.hp-card-row .cz-input, .hp-faq-row .cz-input { flex:1 1 130px; }
.hp-add { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-top:.4rem; }
</style>

<div class="container cz-wrap" style="max-width:880px">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:var(--accent);margin:0;font-weight:700">
            <i class="bi bi-palette me-2"></i><?= htmlspecialchars($TEXT['cz_title'] ?? 'Site Customization') ?>
        </h1>
        <a href="/admin_dashboard" class="cz-btn cz-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
    </div>

    <?php if ($flash !== ''): ?><div class="cz-flash-ok"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($flash_err !== ''): ?><div class="cz-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

    <form method="post" action="/admin_customization">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_footer">

        <div class="cz-card">
            <h2><i class="bi bi-layout-text-window-reverse me-2"></i><?= htmlspecialchars($TEXT['cz_footer_title'] ?? 'Footer links') ?></h2>
            <div class="sub"><?= htmlspecialchars($TEXT['cz_footer_sub'] ?? 'Choose which built-in quick-links show, and add your own (e.g. a donations-disclaimer page).') ?></div>

            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_footer_builtin'] ?? 'Built-in links') ?></span>
            <div class="cz-bi">
                <?php foreach ($bi_labels as $k => $lbl): ?>
                    <label>
                        <input type="checkbox" name="bi[<?= $k ?>]" value="1" <?= !empty($footer['builtin'][$k]) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="cz-hint"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_footer_support_note'] ?? '"Support" also requires the tickets feature to be enabled to appear.') ?></div>

            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_footer_custom'] ?? 'Custom links') ?></span>
            <div id="czRows">
                <?php foreach ($footer['custom'] as $row): ?>
                    <div class="cz-row">
                        <input class="cz-input lbl" type="text" name="c_label[]" maxlength="40" placeholder="<?= htmlspecialchars($TEXT['cz_footer_label_ph'] ?? 'Label') ?>" value="<?= htmlspecialchars($row['label']) ?>">
                        <input class="cz-input" type="text" name="c_url[]" maxlength="300" placeholder="https://… or /forum/general/disclaimer" value="<?= htmlspecialchars($row['url']) ?>">
                        <button type="button" class="cz-btn cz-btn-sm cz-del" onclick="this.closest('.cz-row').remove()"><i class="bi bi-x-lg"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost" id="czAdd"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['cz_footer_add'] ?? 'Add link') ?></button>
            <div class="cz-hint"><?= htmlspecialchars($TEXT['cz_footer_url_hint'] ?? 'URLs must be https:// (or http://) or a site path starting with / — anything else is dropped on save.') ?></div>

            <div style="margin-top:1.4rem">
                <button type="submit" class="cz-btn"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['cz_save'] ?? 'Save footer') ?></button>
            </div>

            <?php
            // Live preview of how the footer quick-links currently resolve.
            $prev = [];
            if (!empty($footer['builtin']['home']))     $prev[] = ['/', $bi_labels['home']];
            if (!empty($footer['builtin']['register'])) $prev[] = ['/register', $bi_labels['register']];
            if (!empty($footer['builtin']['login']))    $prev[] = ['/login', $bi_labels['login']];
            if (!empty($footer['builtin']['support']) && !empty($config['features']['tickets'])) $prev[] = ['/tickets', $bi_labels['support']];
            foreach ($footer['custom'] as $row) $prev[] = [$row['url'], $row['label']];
            ?>
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_footer_preview'] ?? 'Current footer (saved)') ?></span>
            <div class="cz-prev">
                <?php if (empty($prev)): ?>
                    <span style="color:#4a5568"><?= htmlspecialchars($TEXT['cz_footer_empty'] ?? '(no footer links)') ?></span>
                <?php else: foreach ($prev as $i => $p): ?>
                    <?php if ($i > 0): ?><span class="sep">·</span><?php endif; ?>
                    <a href="<?= htmlspecialchars($p[0]) ?>"><?= htmlspecialchars($p[1]) ?></a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </form>

    <form method="post" action="/admin_customization">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_languages">

        <div class="cz-card">
            <h2><i class="bi bi-translate me-2"></i><?= htmlspecialchars($TEXT['cz_lang_title'] ?? 'Languages') ?></h2>
            <div class="sub"><?= htmlspecialchars($TEXT['cz_lang_sub'] ?? 'Pick which languages appear in the site language menu. English is always on — it is the fallback for any untranslated text.') ?></div>

            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_lang_available'] ?? 'Available languages') ?></span>
            <div class="cz-bi" style="flex-direction:column;gap:.55rem;align-items:flex-start">
                <?php foreach ($langs_all as $code => $label):
                    $isEn = ($code === 'en');
                    $on   = $isEn || !in_array($code, $langs_disabled, true);
                ?>
                    <label>
                        <input type="checkbox" name="lang[<?= htmlspecialchars($code) ?>]" value="1" <?= $on ? 'checked' : '' ?> <?= $isEn ? 'disabled' : '' ?>>
                        <strong><?= htmlspecialchars($label) ?></strong>
                        <span style="color:#4a5568;font-size:.8rem">(<code><?= htmlspecialchars($code) ?>.php</code>)</span>
                        <?php if ($isEn): ?><span style="color:#5dd87c;font-size:.75rem;margin-left:.3rem">— <?= htmlspecialchars($TEXT['cz_lang_always_on'] ?? 'always on') ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="cz-prev" style="margin-top:1.2rem">
                <strong style="color:var(--accent)"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_lang_howto_title'] ?? 'Add a custom language') ?></strong>
                <ol style="margin:.6rem 0 0;padding-left:1.2rem;color:#9aa7b4;line-height:1.8">
                    <li><?= str_replace(['{from}', '{to}'], ['<code>lang/en.php</code>', '<code>lang/&lt;code&gt;.php</code>'],
                            htmlspecialchars($TEXT['cz_lang_howto_1'] ?? 'Copy {from} to {to} — use a 2-letter code (e.g. lang/fr.php, lang/de.php).')) ?></li>
                    <li><?= htmlspecialchars($TEXT['cz_lang_howto_2'] ?? 'Translate the values (the text after =>). Keep every key name exactly as-is.') ?></li>
                    <li><?= htmlspecialchars($TEXT['cz_lang_howto_3'] ?? 'It shows up in this list automatically — tick it and Save to put it in the menu.') ?></li>
                    <li><?= htmlspecialchars($TEXT['cz_lang_howto_4'] ?? 'Any key you miss falls back to English automatically, so a partial translation is safe to ship.') ?></li>
                </ol>
            </div>
            <div class="cz-hint"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_lang_note'] ?? 'Disabling a language hides it from the menu; it is not deleted, so you can re-enable it anytime. Files survive updates only if you keep your own copy — see the Updating guide.') ?></div>

            <div style="margin-top:1.4rem">
                <button type="submit" class="cz-btn"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['cz_lang_save'] ?? 'Save languages') ?></button>
            </div>
        </div>
    </form>

    <?php
    // Preset palettes — name => [accent hex, display label]. Picking one just
    // fills the accent field (admins can still fine-tune the hex after).
    $cz_presets = [
        'gold'    => ['#c89b3c', $TEXT['cz_theme_preset_gold']    ?? 'WoW Gold'],
        'azure'   => ['#3c8fc8', $TEXT['cz_theme_preset_azure']   ?? 'Azure'],
        'verdant' => ['#4caf50', $TEXT['cz_theme_preset_verdant'] ?? 'Verdant'],
        'crimson' => ['#c0392b', $TEXT['cz_theme_preset_crimson'] ?? 'Crimson'],
        'arcane'  => ['#9b59b6', $TEXT['cz_theme_preset_arcane']  ?? 'Arcane'],
        'teal'    => ['#1abc9c', $TEXT['cz_theme_preset_teal']    ?? 'Teal'],
    ];
    $cz_brand_rows = [
        'logo_main' => [$TEXT['cz_theme_logo_main'] ?? 'Main logo',
                        $TEXT['cz_theme_logo_main_sub'] ?? 'The big logo on the homepage hero. Default: assets/img/logo.webp.'],
        'logo_top'  => [$TEXT['cz_theme_logo_top'] ?? 'Top-left logo',
                        $TEXT['cz_theme_logo_top_sub'] ?? 'The small logo in the navbar (every page). Default: assets/img/top-logo.webp.'],
        'favicon'   => [$TEXT['cz_theme_favicon'] ?? 'Favicon',
                        $TEXT['cz_theme_favicon_sub'] ?? 'Browser tab / bookmark icon. .ico, .png or .webp.'],
        'header_bg' => [$TEXT['cz_theme_header_bg'] ?? 'Header background',
                        $TEXT['cz_theme_header_bg_sub'] ?? 'The full-screen homepage hero background — an image or a looping video (mp4/webm).'],
    ];
    ?>
    <form method="post" action="/admin_customization" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_theme">
        <input type="hidden" name="preset" id="czPreset" value="<?= htmlspecialchars($theme['preset']) ?>">

        <div class="cz-card">
            <h2><i class="bi bi-palette2 me-2"></i><?= htmlspecialchars($TEXT['cz_theme_title'] ?? 'Theme & branding') ?></h2>
            <div class="sub"><?= htmlspecialchars($TEXT['cz_theme_sub'] ?? 'Recolour the site and swap the logos / favicon / hero background. Stored in the database — your changes survive updates.') ?></div>

            <!-- Accent colour -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_theme_accent'] ?? 'Accent colour') ?></span>
            <div class="cz-color-row">
                <input type="color" id="czAccentPick" value="<?= htmlspecialchars($theme['accent']) ?>" aria-label="Accent colour picker">
                <input class="cz-input" type="text" id="czAccent" name="accent" maxlength="7" value="<?= htmlspecialchars($theme['accent']) ?>" placeholder="#c89b3c" style="max-width:140px;font-family:monospace">
                <span class="cz-hint" style="margin:0"><?= htmlspecialchars($TEXT['cz_theme_accent_hint'] ?? 'Any #rrggbb. Buttons, links, highlights and glows all follow it.') ?></span>
            </div>
            <div class="cz-presets">
                <?php foreach ($cz_presets as $pk => $pv): ?>
                    <button type="button" class="cz-preset" data-name="<?= htmlspecialchars($pk) ?>" data-hex="<?= htmlspecialchars($pv[0]) ?>" title="<?= htmlspecialchars($pv[0]) ?>">
                        <span class="cz-dot" style="background:<?= htmlspecialchars($pv[0]) ?>"></span><?= htmlspecialchars($pv[1]) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Live preview -->
            <div class="cz-prev cz-theme-prev" id="czThemePrev">
                <span class="cz-label" style="margin:0 0 .6rem"><?= htmlspecialchars($TEXT['cz_theme_preview'] ?? 'Live preview') ?></span>
                <div class="cz-prev-demo">
                    <span class="cz-prev-btn"><?= htmlspecialchars($TEXT['register'] ?? 'Register') ?></span>
                    <a href="#" class="cz-prev-link" onclick="return false"><?= htmlspecialchars($TEXT['cz_theme_preview_link'] ?? 'a sample link') ?></a>
                    <span class="cz-prev-chip"><?= htmlspecialchars($TEXT['cz_theme_preview_badge'] ?? 'Badge') ?></span>
                </div>
            </div>

            <!-- Base tone (optional / advanced) -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_theme_tone'] ?? 'Base tone (optional)') ?></span>
            <div class="cz-hint" style="margin-top:0;margin-bottom:.6rem"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($TEXT['cz_theme_tone_hint'] ?? 'Leave blank to keep the shipped dark theme. Override only if you know what you are doing — a poor choice here can make text unreadable.') ?></div>
            <div class="cz-tone">
                <?php foreach ([
                    'bg_dark' => $TEXT['cz_theme_bg_dark'] ?? 'Page background',
                    'bg_card' => $TEXT['cz_theme_bg_card'] ?? 'Card background',
                    'text'    => $TEXT['cz_theme_text']    ?? 'Body text',
                ] as $tk => $tl): ?>
                    <label class="cz-tone-item">
                        <span><?= htmlspecialchars($tl) ?></span>
                        <input class="cz-input" type="text" name="<?= $tk ?>" maxlength="7" value="<?= htmlspecialchars($theme[$tk]) ?>" placeholder="<?= htmlspecialchars($TEXT['cz_theme_default_ph'] ?? 'default') ?>" style="font-family:monospace">
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- Branding files -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_theme_branding'] ?? 'Logos, favicon & hero background') ?></span>
            <?php foreach ($cz_brand_rows as $slot => $info):
                $cur = $theme[$slot];
                $is_video = ($slot === 'header_bg' && $theme['header_bg_kind'] === 'video');
            ?>
                <div class="cz-brand">
                    <div class="cz-brand-head">
                        <strong><?= htmlspecialchars($info[0]) ?></strong>
                        <span class="cz-hint" style="margin:0"><?= htmlspecialchars($info[1]) ?></span>
                    </div>
                    <div class="cz-brand-body">
                        <div class="cz-brand-cur">
                            <?php if ($cur === ''): ?>
                                <span class="cz-brand-none"><?= htmlspecialchars($TEXT['cz_theme_using_default'] ?? 'Using default') ?></span>
                            <?php elseif ($is_video): ?>
                                <video src="<?= htmlspecialchars($cur) ?>" muted loop class="cz-brand-thumb" style="object-fit:cover"></video>
                            <?php else: ?>
                                <img src="<?= htmlspecialchars($cur) ?>" alt="" class="cz-brand-thumb">
                            <?php endif; ?>
                        </div>
                        <div class="cz-brand-ctl">
                            <input class="cz-file" type="file" name="<?= $slot ?>"
                                   accept="<?= $slot === 'favicon' ? 'image/png,image/webp,.ico' : ($slot === 'header_bg' ? 'image/*,video/mp4,video/webm' : 'image/png,image/jpeg,image/webp,image/gif') ?>">
                            <?php if ($cur !== ''): ?>
                                <label class="cz-reset">
                                    <input type="checkbox" name="reset[<?= $slot ?>]" value="1">
                                    <?= htmlspecialchars($TEXT['cz_theme_reset'] ?? 'Remove (revert to default)') ?>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="cz-hint"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_theme_branding_hint'] ?? 'Logos & favicon ≤ 3 MB (favicon ≤ 512 KB); header background ≤ 25 MB. SVG is not accepted for security reasons.') ?></div>

            <!-- Advanced custom CSS -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_theme_css'] ?? 'Advanced: custom CSS') ?></span>
            <label class="cz-adv-toggle">
                <input type="checkbox" name="custom_css_on" id="czCssOn" value="1" <?= !empty($theme['custom_css_on']) ? 'checked' : '' ?>>
                <?= htmlspecialchars($TEXT['cz_theme_css_enable'] ?? 'Enable custom CSS (advanced — you own any breakage)') ?>
            </label>
            <div id="czCssWrap" style="<?= !empty($theme['custom_css_on']) ? '' : 'display:none' ?>">
                <textarea class="cz-input" name="custom_css" id="czCss" rows="6" spellcheck="false"
                          style="font-family:monospace;font-size:.82rem;margin-top:.6rem"
                          placeholder=".btn-gold{ letter-spacing:.5px } /* injected site-wide */"><?= htmlspecialchars($theme['custom_css']) ?></textarea>
                <div class="cz-hint"><i class="bi bi-shield-exclamation me-1"></i><?= htmlspecialchars($TEXT['cz_theme_css_hint'] ?? 'Injected on every page after the theme variables. Tags, @import, expression() and javascript: are stripped on save; it can still break your layout — test before relying on it.') ?></div>
            </div>

            <div style="margin-top:1.5rem">
                <button type="submit" class="cz-btn"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['cz_theme_save'] ?? 'Save theme') ?></button>
            </div>
        </div>
    </form>

    <?php
    // Config defaults shown as placeholders so "blank = use config.php" is obvious.
    $cfg_rdesc = $config['realm']['description'] ?? '';
    if (is_array($cfg_rdesc)) $cfg_rdesc = $cfg_rdesc['en'] ?? (reset($cfg_rdesc) ?: '');
    $cfg_social = is_array($config['social'] ?? null) ? $config['social'] : [];
    $cfg_don    = is_array($config['donation'] ?? null) ? $config['donation'] : [];
    $cfg_pr     = is_array($config['playtime_reward'] ?? null) ? $config['playtime_reward'] : [];
    $pr_on      = !empty($cfg_pr['enabled']);
    $def_ph = function ($v) use ($TEXT) {
        $v = trim((string)$v);
        return $v === '' ? ($TEXT['cz_set_no_default'] ?? '(no default)')
                         : sprintf($TEXT['cz_set_default_fmt'] ?? 'Default: %s', $v);
    };
    // initial vote rows = effective (DB override if saved, else config seed)
    $vote_rows = $settings['vote_sites'] ?: [];
    ?>
    <form method="post" action="/admin_customization">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_settings">

        <div class="cz-card">
            <h2><i class="bi bi-sliders me-2"></i><?= htmlspecialchars($TEXT['cz_set_title'] ?? 'Site settings') ?></h2>
            <div class="sub"><?= htmlspecialchars($TEXT['cz_set_sub'] ?? 'Edit the presentational bits that used to live in config.php. Leave a field blank to keep the config.php default — config is never overwritten, it stays the fallback.') ?></div>

            <!-- Site identity -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_set_identity'] ?? 'Site identity') ?></span>
            <div class="cz-set-grid">
                <label class="cz-set-field">
                    <span><?= htmlspecialchars($TEXT['cz_set_site_title'] ?? 'Browser/site title') ?></span>
                    <input class="cz-input" type="text" name="site_title" maxlength="80" value="<?= htmlspecialchars($set_raw['site_title'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph($config['site']['title'] ?? '')) ?>">
                </label>
                <label class="cz-set-field">
                    <span><?= htmlspecialchars($TEXT['cz_set_realm_name'] ?? 'Realm name (© / headings / OG)') ?></span>
                    <input class="cz-input" type="text" name="realm_name" maxlength="80" value="<?= htmlspecialchars($set_raw['realm_name'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph($config['realm']['name'] ?? '')) ?>">
                </label>
            </div>
            <label class="cz-set-field" style="margin-top:.7rem">
                <span><?= htmlspecialchars($TEXT['cz_set_realm_desc'] ?? 'Realm description (homepage subtitle / OG)') ?></span>
                <input class="cz-input" type="text" name="realm_description" maxlength="300" value="<?= htmlspecialchars($set_raw['realm_description'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph($cfg_rdesc)) ?>">
            </label>
            <div class="cz-hint"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_set_desc_note'] ?? 'A value here applies to every language. For per-language descriptions, leave this blank and keep the array in config.php.') ?></div>

            <!-- Social links -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_set_social'] ?? 'Social links') ?></span>
            <div class="cz-set-grid">
                <?php foreach ([
                    'discord'   => ['Discord',     'bi-discord'],
                    'youtube'   => ['YouTube',     'bi-youtube'],
                    'twitter'   => ['X / Twitter', 'bi-twitter-x'],
                    'instagram' => ['Instagram',   'bi-instagram'],
                ] as $sk => $si): ?>
                    <label class="cz-set-field">
                        <span><i class="bi <?= $si[1] ?> me-1"></i><?= htmlspecialchars($si[0]) ?></span>
                        <input class="cz-input" type="url" name="social[<?= $sk ?>]" maxlength="300" value="<?= htmlspecialchars($set_raw['social'][$sk] ?? '') ?>" placeholder="<?= htmlspecialchars(($cfg_social[$sk] ?? '') !== '' ? $def_ph($cfg_social[$sk]) : 'https://…') ?>">
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="cz-hint"><?= htmlspecialchars($TEXT['cz_set_url_hint'] ?? 'Full https:// URLs only; blank hides that link / uses the config default.') ?></div>

            <!-- Donation (presentational) -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_set_donation'] ?? 'Donation (display only)') ?></span>
            <div class="cz-set-grid">
                <label class="cz-set-field">
                    <span><?= htmlspecialchars($TEXT['cz_set_kofi_url'] ?? 'Ko-fi page URL') ?></span>
                    <input class="cz-input" type="url" name="kofi_url" maxlength="300" value="<?= htmlspecialchars($set_raw['kofi_url'] ?? '') ?>" placeholder="<?= htmlspecialchars(($cfg_don['kofi_url'] ?? '') !== '' ? $def_ph($cfg_don['kofi_url']) : 'https://ko-fi.com/…') ?>">
                </label>
                <label class="cz-set-field" style="max-width:140px">
                    <span><?= htmlspecialchars($TEXT['cz_set_currency'] ?? 'Currency (3-letter)') ?></span>
                    <input class="cz-input" type="text" name="currency" maxlength="3" style="text-transform:uppercase" value="<?= htmlspecialchars($set_raw['currency'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph($cfg_don['currency'] ?? 'EUR')) ?>">
                </label>
                <label class="cz-set-field" style="max-width:160px">
                    <span><?= htmlspecialchars($TEXT['cz_set_min_amount'] ?? 'Minimum amount') ?></span>
                    <input class="cz-input" type="number" min="0" step="1" name="min_amount" value="<?= htmlspecialchars($set_raw['min_amount'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph((string)($cfg_don['min_amount'] ?? 0))) ?>">
                </label>
            </div>
            <div class="cz-hint"><i class="bi bi-shield-lock me-1"></i><?= htmlspecialchars($TEXT['cz_set_donation_note'] ?? 'Display only. The Battle-Coins-per-1.00 rate lives in Shop Management; the Ko-fi webhook token stays in config.php (never web-editable).') ?> <a href="/admin_shop" class="cz-prev-a" style="color:var(--accent)">/admin_shop →</a></div>

            <!-- Playtime reward tunables -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_set_playtime'] ?? 'Playtime reward') ?></span>
            <div class="cz-hint" style="margin-top:0;margin-bottom:.6rem">
                <?= htmlspecialchars($TEXT['cz_set_playtime_state'] ?? 'Master switch (config.php):') ?>
                <strong style="color:<?= $pr_on ? '#5dd87c' : '#f87e8a' ?>"><?= $pr_on ? htmlspecialchars($TEXT['cz_set_on'] ?? 'ON') : htmlspecialchars($TEXT['cz_set_off'] ?? 'OFF') ?></strong>
                — <?= htmlspecialchars($TEXT['cz_set_playtime_flag_note'] ?? 'enable/disable stays a file flag (playtime_reward.enabled).') ?>
            </div>
            <div class="cz-set-grid">
                <label class="cz-set-field" style="max-width:180px">
                    <span><?= htmlspecialchars($TEXT['cz_set_dp_hour'] ?? 'DP per hour') ?></span>
                    <input class="cz-input" type="number" min="0" max="10000" step="1" name="dp_per_hour" value="<?= htmlspecialchars($set_raw['dp_per_hour'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph((string)($cfg_pr['dp_per_hour'] ?? 10))) ?>">
                </label>
                <label class="cz-set-field" style="max-width:180px">
                    <span><?= htmlspecialchars($TEXT['cz_set_daily_cap'] ?? 'Daily cap (DP)') ?></span>
                    <input class="cz-input" type="number" min="0" max="1000000" step="1" name="daily_cap_dp" value="<?= htmlspecialchars($set_raw['daily_cap_dp'] ?? '') ?>" placeholder="<?= htmlspecialchars($def_ph((string)($cfg_pr['daily_cap_dp'] ?? 50))) ?>">
                </label>
            </div>
            <div class="cz-hint"><?= htmlspecialchars($TEXT['cz_set_playtime_bounds'] ?? 'Bounds enforced server-side: DP/hour 0–10000, daily cap 0–1,000,000.') ?></div>

            <!-- Vote sites -->
            <span class="cz-label"><?= htmlspecialchars($TEXT['cz_set_votes'] ?? 'Vote sites') ?></span>
            <div id="czVoteRows">
                <?php foreach ($vote_rows as $vr): ?>
                    <div class="cz-vote-row">
                        <input class="cz-input" type="text" name="v_name[]" maxlength="60" placeholder="<?= htmlspecialchars($TEXT['cz_set_vote_name'] ?? 'Site name') ?>" value="<?= htmlspecialchars($vr['name']) ?>">
                        <input class="cz-input" type="url" name="v_url[]" maxlength="300" placeholder="https://…" value="<?= htmlspecialchars($vr['url']) ?>">
                        <input class="cz-input cz-vote-cd" type="number" min="1" max="8760" step="1" name="v_cd[]" placeholder="12" value="<?= htmlspecialchars((string)$vr['cooldown_hours']) ?>" title="<?= htmlspecialchars($TEXT['cz_set_vote_cd'] ?? 'Cooldown (hours)') ?>">
                        <button type="button" class="cz-btn cz-btn-sm cz-del" onclick="this.closest('.cz-vote-row').remove()"><i class="bi bi-x-lg"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost" id="czVoteAdd"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['cz_set_vote_add'] ?? 'Add vote site') ?></button>
            <div class="cz-hint"><?= htmlspecialchars($TEXT['cz_set_votes_note'] ?? 'Empty list = the Vote & Reward block is hidden. Cooldown is hours between rewarded votes per site (1–8760).') ?></div>

            <div style="margin-top:1.5rem">
                <button type="submit" class="cz-btn"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['cz_set_save'] ?? 'Save settings') ?></button>
            </div>
        </div>
    </form>

    <?php
    $hp_meta = homepage_section_meta();
    $hp_builtins = homepage_builtin_keys();
    $hp_label = function (string $type) use ($hp_meta, $TEXT) {
        if ($type === 'faq' && !isset($hp_meta['faq'])) return 'FAQ';
        $m = $hp_meta[$type] ?? null;
        if (!$m) return ucfirst(str_replace('-', ' ', $type));
        return $TEXT[$m[0]] ?? ucfirst(str_replace('-', ' ', $type));
    };
    $hp_icon = function (string $type) use ($hp_meta) {
        return $hp_meta[$type][1] ?? 'bi-puzzle';
    };

    // Renders the structured-field editor for one custom section. Used both
    // for existing sections and (with id "__ID__") inside the JS templates.
    $hp_custom_fields = function (string $id, string $type, array $d) use ($TEXT) {
        $n = function ($k) use ($id) { return 'hp[' . $id . '][data][' . $k . ']'; };
        ob_start();
        if ($type === 'text'): ?>
            <input class="cz-input" type="text" name="<?= $n('title') ?>" maxlength="120" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_title'] ?? 'Heading (optional)') ?>" value="<?= htmlspecialchars($d['title'] ?? '') ?>" style="margin-bottom:.5rem">
            <textarea class="cz-input" name="<?= $n('body') ?>" rows="5" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_md'] ?? 'Markdown supported') ?>" style="font-family:monospace;font-size:.85rem"><?= htmlspecialchars($d['body'] ?? '') ?></textarea>
        <?php elseif ($type === 'cta'): ?>
            <input class="cz-input" type="text" name="<?= $n('title') ?>" maxlength="120" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_title'] ?? 'Heading') ?>" value="<?= htmlspecialchars($d['title'] ?? '') ?>" style="margin-bottom:.5rem">
            <input class="cz-input" type="text" name="<?= $n('text') ?>" maxlength="400" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_text'] ?? 'Sub-text') ?>" value="<?= htmlspecialchars($d['text'] ?? '') ?>" style="margin-bottom:.5rem">
            <div class="cz-set-grid">
                <input class="cz-input" type="text" name="<?= $n('btn_label') ?>" maxlength="60" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_btn'] ?? 'Button label') ?>" value="<?= htmlspecialchars($d['btn_label'] ?? '') ?>">
                <input class="cz-input" type="url" name="<?= $n('btn_url') ?>" maxlength="300" placeholder="https://… or /register" value="<?= htmlspecialchars($d['btn_url'] ?? '') ?>">
            </div>
        <?php elseif ($type === 'card-grid'):
            $cols = (int)($d['cols'] ?? 3); $cards = $d['cards'] ?? []; ?>
            <div class="cz-set-grid" style="align-items:flex-end">
                <input class="cz-input" type="text" name="<?= $n('title') ?>" maxlength="120" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_title'] ?? 'Heading (optional)') ?>" value="<?= htmlspecialchars($d['title'] ?? '') ?>">
                <label class="cz-set-field" style="max-width:150px">
                    <span><?= htmlspecialchars($TEXT['cz_hp_f_cols'] ?? 'Columns') ?></span>
                    <select class="cz-input" name="<?= $n('cols') ?>">
                        <?php foreach ([2,3,4] as $cv): ?><option value="<?= $cv ?>" <?= $cols===$cv?'selected':'' ?>><?= $cv ?></option><?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="hp-cards" style="margin-top:.6rem">
                <?php foreach (($cards ?: [[]]) as $ci => $cd): ?>
                    <div class="hp-card-row">
                        <input class="cz-input" type="text" name="hp[<?= $id ?>][data][cards][<?= $ci ?>][icon]" maxlength="40" placeholder="bi-trophy" value="<?= htmlspecialchars($cd['icon'] ?? '') ?>" style="max-width:120px">
                        <input class="cz-input" type="text" name="hp[<?= $id ?>][data][cards][<?= $ci ?>][title]" maxlength="80" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_ctitle'] ?? 'Card title') ?>" value="<?= htmlspecialchars($cd['title'] ?? '') ?>">
                        <input class="cz-input" type="text" name="hp[<?= $id ?>][data][cards][<?= $ci ?>][text]" maxlength="400" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_ctext'] ?? 'Card text') ?>" value="<?= htmlspecialchars($cd['text'] ?? '') ?>">
                        <input class="cz-input" type="url" name="hp[<?= $id ?>][data][cards][<?= $ci ?>][url]" maxlength="300" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_curl'] ?? 'Link (optional)') ?>" value="<?= htmlspecialchars($cd['url'] ?? '') ?>">
                        <button type="button" class="cz-btn cz-btn-sm cz-del hp-card-del"><i class="bi bi-x-lg"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost hp-card-add"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['cz_hp_card_add'] ?? 'Add card') ?></button>
        <?php elseif ($type === 'qa'):
            $items = $d['items'] ?? []; ?>
            <input class="cz-input" type="text" name="<?= $n('title') ?>" maxlength="120" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_title'] ?? 'Heading (optional)') ?>" value="<?= htmlspecialchars($d['title'] ?? '') ?>" style="margin-bottom:.5rem">
            <div class="hp-faqs">
                <?php foreach (($items ?: [[]]) as $fi => $fd): ?>
                    <div class="hp-faq-row">
                        <input class="cz-input" type="text" name="hp[<?= $id ?>][data][items][<?= $fi ?>][q]" maxlength="200" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_q'] ?? 'Question') ?>" value="<?= htmlspecialchars($fd['q'] ?? '') ?>">
                        <input class="cz-input" type="text" name="hp[<?= $id ?>][data][items][<?= $fi ?>][a]" maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_a'] ?? 'Answer (markdown)') ?>" value="<?= htmlspecialchars($fd['a'] ?? '') ?>">
                        <button type="button" class="cz-btn cz-btn-sm cz-del hp-faq-del"><i class="bi bi-x-lg"></i></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost hp-faq-add"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['cz_hp_faq_add'] ?? 'Add Q&A') ?></button>
        <?php endif;
        return ob_get_clean();
    };

    // Renders one section row (built-in or custom).
    $hp_row = function (array $sec) use ($hp_label, $hp_icon, $hp_builtins, $hp_custom_fields, $TEXT) {
        $id = $sec['id']; $type = $sec['type']; $on = !empty($sec['on']);
        $is_builtin = in_array($type, $hp_builtins, true);
        ob_start(); ?>
        <div class="hp-row" data-id="<?= htmlspecialchars($id) ?>" data-type="<?= htmlspecialchars($type) ?>">
            <div class="hp-bar">
                <span class="hp-grip" title="<?= htmlspecialchars($TEXT['cz_hp_drag'] ?? 'Drag to reorder') ?>"><i class="bi bi-grip-vertical"></i></span>
                <i class="bi <?= htmlspecialchars($hp_icon($type)) ?> hp-tico"></i>
                <span class="hp-name"><?= htmlspecialchars($hp_label($type)) ?></span>
                <?php if ($is_builtin): ?>
                    <span class="hp-tag"><?= htmlspecialchars($TEXT['cz_hp_builtin'] ?? 'built-in') ?></span>
                <?php else: ?>
                    <span class="hp-tag hp-tag-c"><?= htmlspecialchars($TEXT['cz_hp_custom'] ?? 'custom') ?></span>
                    <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost hp-edit"><i class="bi bi-pencil"></i></button>
                    <button type="button" class="cz-btn cz-btn-sm cz-del hp-row-del"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
                <label class="hp-switch" title="<?= htmlspecialchars($TEXT['cz_hp_toggle'] ?? 'Show / hide') ?>">
                    <input type="checkbox" name="hp[<?= htmlspecialchars($id) ?>][on]" value="1" <?= $on ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($TEXT['cz_hp_on'] ?? 'On') ?></span>
                </label>
                <input type="hidden" name="hp[<?= htmlspecialchars($id) ?>][type]" value="<?= htmlspecialchars($type) ?>">
            </div>
            <?php if (!$is_builtin): ?>
                <div class="hp-edit-panel" style="display:none">
                    <?= $hp_custom_fields($id, $type, is_array($sec['data'] ?? null) ? $sec['data'] : []) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php return ob_get_clean();
    };

    // split hero (pinned, separate control) from the sortable rest
    $hp_hero = null; $hp_rest = [];
    foreach ($hp_layout as $s) { if ($s['type'] === 'hero') $hp_hero = $s; else $hp_rest[] = $s; }
    $hp_hero_on = $hp_hero ? !empty($hp_hero['on']) : true;
    ?>
    <form method="post" action="/admin_customization" id="hpForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_homepage">

        <div class="cz-card">
            <h2><i class="bi bi-layout-wtf me-2"></i><?= htmlspecialchars($TEXT['cz_hp_title'] ?? 'Home page') ?></h2>
            <div class="sub"><?= htmlspecialchars($TEXT['cz_hp_sub'] ?? 'Toggle, reorder (drag) and add sections to the homepage body. Built-in sections keep their live content; the nav and footer are not affected.') ?></div>

            <!-- Hero (pinned top) -->
            <div class="hp-row hp-hero">
                <div class="hp-bar">
                    <span class="hp-grip hp-grip-off" title="<?= htmlspecialchars($TEXT['cz_hp_hero_pin'] ?? 'Hero is always the top section') ?>"><i class="bi bi-pin-angle-fill"></i></span>
                    <i class="bi bi-stars hp-tico"></i>
                    <span class="hp-name"><?= htmlspecialchars($TEXT['cz_hp_s_hero'] ?? 'Hero banner') ?></span>
                    <span class="hp-tag"><?= htmlspecialchars($TEXT['cz_hp_pinned'] ?? 'pinned top') ?></span>
                    <label class="hp-switch">
                        <input type="checkbox" name="hp[hero][on]" value="1" <?= $hp_hero_on ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($TEXT['cz_hp_on'] ?? 'On') ?></span>
                    </label>
                    <input type="hidden" name="hp[hero][type]" value="hero">
                </div>
            </div>

            <div id="hpSort" class="hp-sort">
                <?php foreach ($hp_rest as $s) echo $hp_row($s); ?>
            </div>

            <div class="hp-add">
                <select class="cz-input" id="hpAddType" style="max-width:200px">
                    <option value="card-grid"><?= htmlspecialchars($hp_label('card-grid')) ?></option>
                    <option value="text"><?= htmlspecialchars($hp_label('text')) ?></option>
                    <option value="cta"><?= htmlspecialchars($hp_label('cta')) ?></option>
                    <option value="qa"><?= htmlspecialchars($hp_label('qa')) ?></option>
                </select>
                <button type="button" class="cz-btn cz-btn-sm cz-btn-ghost" id="hpAddBtn"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['cz_hp_add'] ?? 'Add section') ?></button>
                <a href="/" target="_blank" rel="noopener" class="cz-btn cz-btn-sm cz-btn-ghost" style="margin-left:auto"><i class="bi bi-box-arrow-up-right me-1"></i><?= htmlspecialchars($TEXT['cz_hp_view'] ?? 'View homepage') ?></a>
            </div>
            <div class="cz-hint"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_hp_note'] ?? 'Built-in sections only show when they have content (e.g. News appears when posts exist). Custom sections use safe, structured fields — no raw HTML.') ?></div>

            <div style="margin-top:1.4rem">
                <button type="submit" class="cz-btn"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['cz_hp_save'] ?? 'Save home page') ?></button>
            </div>
        </div>

        <?php foreach (homepage_custom_types() as $ct): ?>
        <template id="hpTpl-<?= htmlspecialchars($ct) ?>"><?= $hp_row(['id' => '__ID__', 'type' => $ct, 'on' => 1, 'data' => []]) ?></template>
        <?php endforeach; ?>
        <template id="hpTpl-card"><div class="hp-card-row">
            <input class="cz-input" type="text" name="__CN__[icon]" maxlength="40" placeholder="bi-trophy" style="max-width:120px">
            <input class="cz-input" type="text" name="__CN__[title]" maxlength="80" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_ctitle'] ?? 'Card title', ENT_QUOTES) ?>">
            <input class="cz-input" type="text" name="__CN__[text]" maxlength="400" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_ctext'] ?? 'Card text', ENT_QUOTES) ?>">
            <input class="cz-input" type="url" name="__CN__[url]" maxlength="300" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_curl'] ?? 'Link (optional)', ENT_QUOTES) ?>">
            <button type="button" class="cz-btn cz-btn-sm cz-del hp-card-del"><i class="bi bi-x-lg"></i></button>
        </div></template>
        <template id="hpTpl-faqitem"><div class="hp-faq-row">
            <input class="cz-input" type="text" name="__FN__[q]" maxlength="200" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_q'] ?? 'Question', ENT_QUOTES) ?>">
            <input class="cz-input" type="text" name="__FN__[a]" maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['cz_hp_f_a'] ?? 'Answer (markdown)', ENT_QUOTES) ?>">
            <button type="button" class="cz-btn cz-btn-sm cz-del hp-faq-del"><i class="bi bi-x-lg"></i></button>
        </div></template>
    </form>
</div>

<script>
(function () {
    var add = document.getElementById('czAdd');
    var rows = document.getElementById('czRows');
    if (!add || !rows) return;
    add.addEventListener('click', function () {
        var d = document.createElement('div');
        d.className = 'cz-row';
        d.innerHTML =
            '<input class="cz-input lbl" type="text" name="c_label[]" maxlength="40" placeholder="<?= htmlspecialchars($TEXT['cz_footer_label_ph'] ?? 'Label', ENT_QUOTES) ?>">' +
            '<input class="cz-input" type="text" name="c_url[]" maxlength="300" placeholder="https://… or /forum/general/disclaimer">' +
            '<button type="button" class="cz-btn cz-btn-sm cz-del"><i class="bi bi-x-lg"></i></button>';
        d.querySelector('.cz-del').addEventListener('click', function () { d.remove(); });
        rows.appendChild(d);
    });
})();

// Theme card: keep hex text ↔ colour picker ↔ live preview in sync,
// wire presets and the advanced-CSS toggle.
(function () {
    var txt  = document.getElementById('czAccent');
    if (!txt) return;
    var pick = document.getElementById('czAccentPick');
    var prev = document.getElementById('czThemePrev');
    var presetField = document.getElementById('czPreset');
    var HEX = /^#[0-9a-fA-F]{6}$/;

    function apply(hex, fromPreset) {
        if (!HEX.test(hex)) return;
        if (pick)  pick.value = hex;
        if (prev)  prev.style.setProperty('--p', hex);
        if (!fromPreset && presetField) presetField.value = ''; // manual edit clears preset
    }
    apply(txt.value, true);

    txt.addEventListener('input', function () { apply(txt.value.trim(), false); });
    if (pick) pick.addEventListener('input', function () {
        txt.value = pick.value;
        apply(pick.value, false);
    });

    document.querySelectorAll('.cz-preset').forEach(function (b) {
        b.addEventListener('click', function () {
            var hex = b.getAttribute('data-hex');
            txt.value = hex;
            if (presetField) presetField.value = b.getAttribute('data-name') || '';
            apply(hex, true);
        });
    });

    var cssOn = document.getElementById('czCssOn');
    var cssWrap = document.getElementById('czCssWrap');
    if (cssOn && cssWrap) {
        cssOn.addEventListener('change', function () {
            cssWrap.style.display = cssOn.checked ? '' : 'none';
        });
    }
})();

// Settings card: add a blank vote-site row.
(function () {
    var add = document.getElementById('czVoteAdd');
    var rows = document.getElementById('czVoteRows');
    if (!add || !rows) return;
    add.addEventListener('click', function () {
        var d = document.createElement('div');
        d.className = 'cz-vote-row';
        d.innerHTML =
            '<input class="cz-input" type="text" name="v_name[]" maxlength="60" placeholder="<?= htmlspecialchars($TEXT['cz_set_vote_name'] ?? 'Site name', ENT_QUOTES) ?>">' +
            '<input class="cz-input" type="url" name="v_url[]" maxlength="300" placeholder="https://…">' +
            '<input class="cz-input cz-vote-cd" type="number" min="1" max="8760" step="1" name="v_cd[]" placeholder="12">' +
            '<button type="button" class="cz-btn cz-btn-sm cz-del"><i class="bi bi-x-lg"></i></button>';
        d.querySelector('.cz-del').addEventListener('click', function () { d.remove(); });
        rows.appendChild(d);
    });
})();
</script>

<!-- Home page designer: SortableJS drag-reorder + add/edit/delete sections -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
(function () {
    var sort = document.getElementById('hpSort');
    if (!sort) return;
    var gIdx = 1; // unique suffix for dynamically-added card/faq rows

    if (window.Sortable) {
        Sortable.create(sort, {
            handle: '.hp-grip', animation: 150,
            ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen'
        });
    }

    function rand(n) {
        var s = ''; var c = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for (var i = 0; i < n; i++) s += c[Math.floor(Math.random() * c.length)];
        return s;
    }

    // Add a new custom section from its <template>.
    var addBtn = document.getElementById('hpAddBtn');
    var addSel = document.getElementById('hpAddType');
    if (addBtn && addSel) {
        addBtn.addEventListener('click', function () {
            var tpl = document.getElementById('hpTpl-' + addSel.value);
            if (!tpl) return;
            var id = 'c_' + rand(10);
            var html = tpl.innerHTML.split('__ID__').join(id);
            var tmp = document.createElement('div');
            tmp.innerHTML = html.trim();
            var row = tmp.firstElementChild;
            var panel = row.querySelector('.hp-edit-panel');
            if (panel) panel.style.display = ''; // open editor for a fresh section
            sort.appendChild(row);
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    // Delegated handlers (work for server-rendered AND newly added rows).
    sort.addEventListener('click', function (e) {
        var t = e.target.closest('button');
        if (!t || !sort.contains(t)) return;

        if (t.classList.contains('hp-edit')) {
            var p = t.closest('.hp-row').querySelector('.hp-edit-panel');
            if (p) p.style.display = (p.style.display === 'none' || !p.style.display) ? '' : 'none';
            return;
        }
        if (t.classList.contains('hp-row-del')) {
            t.closest('.hp-row').remove();
            return;
        }
        if (t.classList.contains('hp-card-add')) {
            var row = t.closest('.hp-row');
            var box = t.closest('.hp-edit-panel').querySelector('.hp-cards');
            var ctpl = document.getElementById('hpTpl-card');
            var nm = 'hp[' + row.dataset.id + '][data][cards][x' + (gIdx++) + ']';
            var d = document.createElement('div');
            d.innerHTML = ctpl.innerHTML.split('__CN__').join(nm).trim();
            box.appendChild(d.firstElementChild);
            return;
        }
        if (t.classList.contains('hp-faq-add')) {
            var row2 = t.closest('.hp-row');
            var box2 = t.closest('.hp-edit-panel').querySelector('.hp-faqs');
            var ftpl = document.getElementById('hpTpl-faqitem');
            var nm2 = 'hp[' + row2.dataset.id + '][data][items][x' + (gIdx++) + ']';
            var d2 = document.createElement('div');
            d2.innerHTML = ftpl.innerHTML.split('__FN__').join(nm2).trim();
            box2.appendChild(d2.firstElementChild);
            return;
        }
        if (t.classList.contains('hp-card-del')) { t.closest('.hp-card-row').remove(); return; }
        if (t.classList.contains('hp-faq-del')) { t.closest('.hp-faq-row').remove(); return; }
    });
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
