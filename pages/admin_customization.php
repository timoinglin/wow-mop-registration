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

$page_title = ($TEXT['cz_title'] ?? 'Site Customization') . ' — ' . ($config['site']['title'] ?? 'WoW');
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
    border:1px solid rgba(139,69,19,.3); border-radius:12px;
    padding:1.4rem 1.6rem; margin-bottom:1.4rem;
}
.cz-card h2 { color:#c8a96e; font-size:1.15rem; font-weight:700; margin:0 0 .3rem; }
.cz-card .sub { color:#8899aa; font-size:.85rem; margin-bottom:1.1rem; }
.cz-label { color:#c8a96e; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; font-weight:600; display:block; margin:1rem 0 .5rem; }
.cz-input {
    background:#0a0a0f; border:1px solid rgba(139,69,19,.35); border-radius:6px;
    color:#dee2e6; padding:.5rem .7rem; font-size:.9rem; font-family:inherit; width:100%;
}
.cz-input:focus { outline:none; border-color:rgba(200,169,110,.6); }
.cz-bi { display:flex; flex-wrap:wrap; gap:1.3rem; padding:.3rem 0 .2rem; }
.cz-bi label { display:flex; align-items:center; gap:.45rem; color:#dee2e6; font-size:.9rem; cursor:pointer; }
.cz-row { display:flex; gap:.5rem; align-items:center; margin-bottom:.5rem; }
.cz-row .cz-input.lbl { max-width:200px; }
.cz-btn {
    background:#8B4513; color:#fff; border:1px solid #A0522D; border-radius:6px;
    padding:.55rem 1.1rem; font-size:.88rem; cursor:pointer; font-family:inherit; text-decoration:none;
    display:inline-block; transition:background .14s ease;
}
.cz-btn:hover { background:#A0522D; color:#fff; }
.cz-btn-sm { padding:.4rem .6rem; font-size:.8rem; }
.cz-btn-ghost { background:transparent; color:#c8a96e; border-color:rgba(200,169,110,.4); }
.cz-del { background:rgba(231,76,60,.15); border:1px solid rgba(231,76,60,.4); color:#f87e8a; }
.cz-del:hover { background:rgba(231,76,60,.3); color:#fff; }
.cz-flash-ok  { background:rgba(46,125,50,.15); border:1px solid rgba(46,125,50,.5); color:#9ae6a4; padding:.7rem 1rem; border-radius:8px; margin-bottom:1rem; }
.cz-flash-err { background:rgba(231,76,60,.12); border:1px solid rgba(231,76,60,.5); color:#f8b4b4; padding:.7rem 1rem; border-radius:8px; margin-bottom:1rem; }
.cz-prev { background:#0a0a0f; border:1px solid rgba(139,69,19,.25); border-radius:8px; padding:.9rem 1.1rem; margin-top:1rem; font-size:.85rem; }
.cz-prev a { color:rgba(255,255,255,.6); text-decoration:none; }
.cz-prev a:hover { color:#c8a96e; }
.cz-prev .sep { color:rgba(255,255,255,.2); margin:0 .5rem; }
.cz-hint { color:#4a5568; font-size:.78rem; margin-top:.5rem; }
/* Theme card */
.cz-color-row { display:flex; align-items:center; gap:.7rem; flex-wrap:wrap; }
.cz-color-row input[type=color] {
    width:46px; height:38px; padding:0; border:1px solid rgba(139,69,19,.35);
    border-radius:6px; background:#0a0a0f; cursor:pointer;
}
.cz-presets { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.8rem; }
.cz-preset {
    display:flex; align-items:center; gap:.45rem; background:#0a0a0f;
    border:1px solid rgba(139,69,19,.35); color:#dee2e6; border-radius:20px;
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
    border:1px solid rgba(139,69,19,.22); border-radius:8px;
    padding:.85rem 1rem; margin-bottom:.7rem; background:#0a0a0f;
}
.cz-brand-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:.6rem; margin-bottom:.6rem; }
.cz-brand-head strong { color:#dee2e6; font-size:.92rem; }
.cz-brand-body { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.cz-brand-thumb {
    width:84px; height:54px; object-fit:contain; background:#161616;
    border:1px solid rgba(139,69,19,.3); border-radius:6px;
}
.cz-brand-none { color:#4a5568; font-size:.8rem; font-style:italic; }
.cz-brand-ctl { display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.cz-file { color:#9aa7b4; font-size:.82rem; max-width:260px; }
.cz-file::file-selector-button {
    background:#1e1e1e; color:#c8a96e; border:1px solid rgba(139,69,19,.4);
    border-radius:5px; padding:.35rem .7rem; font-size:.8rem; cursor:pointer; margin-right:.6rem;
}
.cz-reset, .cz-adv-toggle { display:flex; align-items:center; gap:.45rem; color:#f0a; font-size:.8rem; cursor:pointer; }
.cz-reset { color:#f87e8a; }
.cz-adv-toggle { color:#dee2e6; font-size:.88rem; }
</style>

<div class="container cz-wrap" style="max-width:880px">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:#c8a96e;margin:0;font-weight:700">
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
                <strong style="color:#c8a96e"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['cz_lang_howto_title'] ?? 'Add a custom language') ?></strong>
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
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
