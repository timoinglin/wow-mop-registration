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
    $redirect();
}

// ─── GET ────────────────────────────────────────────────────────────────────
$flash = '';
if (isset($_GET['saved']) && $_GET['saved'] === 'footer') {
    $flash = $TEXT['cz_saved_footer'] ?? 'Footer saved.';
}
$flash_err = '';
if (isset($_GET['err'])) {
    $flash_err = $_GET['err'] === 'csrf'
        ? ($TEXT['cz_err_csrf'] ?? 'Session expired. Please try again.')
        : ($TEXT['cz_err_save'] ?? 'Could not save changes.');
}

$footer = footer_links_get($pdo_auth);

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
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
