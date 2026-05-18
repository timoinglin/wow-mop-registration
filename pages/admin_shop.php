<?php
/**
 * Admin: in-game Battle Pay shop management.
 *
 * Phase A1 = read-only viewer. Phase A2 = category CRUD (add / edit / delete /
 * reorder `battle_pay_group`). Item/tile editing is still read-only here —
 * that arrives in A3.
 *
 * GM 9+ only. Degrades gracefully when the shop feature is off, the world DB
 * is unreachable, or the repack has no battle_pay_* tables. Every write raises
 * the worldserver "pending restart" flag (changes are invisible in-game until
 * the worldserver is restarted).
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/shop.php';
require_once __DIR__ . '/../includes/donation.php';

// GM 9+ guard (same pattern as /admin_forum, /admin_news)
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm->fetchColumn() ?: 0);
if ($gm_level < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $gm_level;

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

[$shop_ok, $shop_reason] = shop_availability($pdo_world ?? null, $config);

$redirect = function (string $key = '', string $val = '1') {
    $u = '/admin_shop';
    if ($key !== '') $u .= '?' . urlencode($key) . '=' . urlencode($val);
    header('Location: ' . $u);
    exit;
};

// ─── Donation rate (auth DB only — works even if the world DB is down) ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_donation_rate') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('err', 'csrf');
    }
    $rate = (int)($_POST['eur_to_dp_rate'] ?? 0);
    if ($rate < 1 || $rate > 100000000) {
        $redirect('err', 'rate');
    }
    if (donation_set_rate($pdo_auth, $rate)) {
        log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_donation_rate', null, "eur_to_dp_rate=$rate", null);
        $redirect('saved', 'rate_saved');
    }
    $redirect('err', 'save');
}

// ─── POST handlers (PRG) ────────────────────────────────────────────────────
if ($shop_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $redirect('err', 'csrf');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_dirty') {
        shop_clear_dirty();
        log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_restart_ack', null, 'worldserver restart acknowledged', null);
        $redirect('saved', 'restart_ack');
    }

    elseif ($action === 'add_category') {
        $name = (string)($_POST['name'] ?? '');
        $icon = (int)($_POST['icon'] ?? 0);
        $type = (int)($_POST['type'] ?? 0);
        if (trim($name) === '') $redirect('err', 'name');
        $newId = shop_category_add($pdo_world, $name, $icon, $type);
        if ($newId) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_category_add', "group:$newId", "name=" . trim($name) . ", type=$type", null);
            $redirect('saved', 'cat_added');
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'edit_category') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = (string)($_POST['name'] ?? '');
        $icon = (int)($_POST['icon'] ?? 0);
        $type = (int)($_POST['type'] ?? 0);
        if ($id <= 0 || trim($name) === '') $redirect('err', 'name');
        if (shop_category_update($pdo_world, $id, $name, $icon, $type)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_category_edit', "group:$id", "name=" . trim($name) . ", type=$type", null);
            $redirect('saved', 'cat_saved');
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        $cleanup = !empty($_POST['cleanup_orphans']);
        if ($id <= 0) $redirect('err', 'save');
        $info = shop_category_get($pdo_world, $id);
        if (shop_category_delete($pdo_world, $id, $cleanup)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_category_delete', "group:$id", "name=" . ($info['name'] ?? '?') . ", orphan_cleanup=" . ($cleanup ? '1' : '0'), null);
            $redirect('saved', 'cat_deleted');
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'move_category') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = (string)($_POST['dir'] ?? '');
        if (shop_category_move($pdo_world, $id, $dir)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_category_move', "group:$id", "dir=$dir", null);
            $redirect('saved', 'cat_moved');
        }
        $redirect('err', 'save');
    }

    // ── Tiles (product + product_items + entry) ──────────────────────────
    elseif ($action === 'save_tile') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);          // 0 = create
        $groupId  = (int)($_POST['groupId'] ?? 0);
        // Build item rows from parallel arrays itemId[] / count[]
        $rawItems = [];
        $iids = $_POST['itemId'] ?? [];
        $cnts = $_POST['count']  ?? [];
        if (is_array($iids)) {
            foreach ($iids as $k => $iid) {
                $iid = (int)$iid;
                if ($iid <= 0) continue;
                $rawItems[] = ['itemId' => $iid, 'count' => (int)($cnts[$k] ?? 1)];
            }
        }
        [$iok, $ires] = shop_validate_items($pdo_world, $rawItems);
        if (!$iok) {
            $redirect('err', $ires === 0 ? 'noitems' : ('baditem:' . $ires));
        }
        $d = [
            'title'       => (string)($_POST['title'] ?? ''),
            'description' => (string)($_POST['description'] ?? ''),
            'price'       => (int)($_POST['price'] ?? 0),
            'discount'    => (int)($_POST['discount'] ?? 0),
            'icon'        => (int)($_POST['icon'] ?? 0),
            'displayId'   => (int)($_POST['displayId'] ?? 0),
            'groupId'     => $groupId,
            'items'       => $ires,
        ];
        if (trim($d['title']) === '') $redirect('err', 'tile_title');

        if ($entry_id > 0) {
            if (shop_tile_update($pdo_world, $entry_id, $d)) {
                shop_mark_dirty();
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_tile_edit', "entry:$entry_id", "title=" . trim($d['title']) . ", price=" . $d['price'], null);
                $redirect('saved', 'tile_saved');
            }
        } else {
            if ($groupId <= 0) $redirect('err', 'save');
            $newEid = shop_tile_add($pdo_world, $groupId, $d);
            if ($newEid) {
                shop_mark_dirty();
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_tile_add', "entry:$newEid", "group:$groupId, title=" . trim($d['title']) . ", price=" . $d['price'], null);
                $redirect('saved', 'tile_added');
            }
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'delete_tile') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        if ($entry_id <= 0) $redirect('err', 'save');
        if (shop_tile_delete($pdo_world, $entry_id)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_tile_delete', "entry:$entry_id", null, null);
            $redirect('saved', 'tile_deleted');
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'move_tile') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $dir      = (string)($_POST['dir'] ?? '');
        if (shop_tile_move($pdo_world, $entry_id, $dir)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_tile_move', "entry:$entry_id", "dir=$dir", null);
            $redirect('saved', 'tile_moved');
        }
        $redirect('err', 'save');
    }

    elseif ($action === 'update_price') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $price      = (int)($_POST['price'] ?? 0);
        if (shop_price_update($pdo_world, $product_id, $price)) {
            shop_mark_dirty();
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'shop_price_update', "product:$product_id", "price=$price", null);
            $redirect('saved', 'price_saved');
        }
        $redirect('err', 'save');
    }

    $redirect();
}

// ─── GET ────────────────────────────────────────────────────────────────────
$edit_cat_id = (int)($_GET['edit_cat'] ?? 0);
$edit_cat    = ($shop_ok && $edit_cat_id > 0) ? shop_category_get($pdo_world, $edit_cat_id) : null;

// Tile editor mode: ?edit_tile=ENTRYID  or  ?new_tile=1&cat=GROUPID
$mode      = 'overview';
$tile      = null;
$tile_cat  = 0;
if ($shop_ok && isset($_GET['edit_tile'])) {
    $tile = shop_tile_get($pdo_world, (int)$_GET['edit_tile']);
    if ($tile) { $mode = 'tile'; $tile_cat = (int)$tile['groupId']; }
} elseif ($shop_ok && isset($_GET['new_tile'])) {
    $tile_cat = (int)($_GET['cat'] ?? 0);
    if ($tile_cat > 0 && shop_category_get($pdo_world, $tile_cat)) $mode = 'tile';
}

$flash = '';
if (isset($_GET['saved'])) {
    $flash = match ($_GET['saved']) {
        'cat_added'   => $TEXT['shop_flash_cat_added']   ?? 'Category added.',
        'cat_saved'   => $TEXT['shop_flash_cat_saved']   ?? 'Category updated.',
        'cat_deleted' => $TEXT['shop_flash_cat_deleted'] ?? 'Category deleted.',
        'cat_moved'   => $TEXT['shop_flash_cat_moved']   ?? 'Category reordered.',
        'restart_ack' => $TEXT['shop_flash_restart_ack'] ?? 'Restart acknowledged — banner cleared.',
        'tile_added'  => $TEXT['shop_flash_tile_added']  ?? 'Item added.',
        'tile_saved'  => $TEXT['shop_flash_tile_saved']  ?? 'Item updated.',
        'tile_deleted'=> $TEXT['shop_flash_tile_deleted']?? 'Item deleted.',
        'tile_moved'  => $TEXT['shop_flash_tile_moved']  ?? 'Item reordered.',
        'price_saved' => $TEXT['shop_flash_price_saved'] ?? 'Price updated.',
        'rate_saved'  => $TEXT['shop_flash_rate_saved']  ?? 'Exchange rate updated.',
        default       => '',
    };
}
$flash_err = '';
if (isset($_GET['err'])) {
    $e = (string)$_GET['err'];
    if (str_starts_with($e, 'baditem:')) {
        $flash_err = sprintf($TEXT['shop_err_baditem'] ?? 'Item #%s was not found in item_template — nothing was saved.', substr($e, 8));
    } else {
        $flash_err = match ($e) {
            'csrf'       => $TEXT['shop_err_csrf']       ?? 'Session expired. Please try again.',
            'name'       => $TEXT['shop_err_name']       ?? 'Category name is required (max 16 chars).',
            'tile_title' => $TEXT['shop_err_tile_title'] ?? 'Item title is required (max 50 chars).',
            'noitems'    => $TEXT['shop_err_noitems']    ?? 'Add at least one valid item.',
            'rate'       => $TEXT['shop_err_rate']       ?? 'Enter a whole number of Battle Coins per €1 (1 or more).',
            default      => $TEXT['shop_err_save']       ?? 'Could not save the change.',
        };
    }
}

$dirty_ts   = shop_is_dirty();

// ─── Donation exchange-rate settings (auth DB; independent of world DB) ──────
$don_flag_on   = !empty($config['features']['donations']);
$don_stored    = donation_stored_rate($pdo_auth);          // null = no override
$don_rate_eff  = donation_rate($pdo_auth, $config);        // effective value
$don_cur_code  = strtoupper(donation_config($config, $pdo_auth)['currency']);
$don_cur_sym   = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$'][$don_cur_code]
                 ?? ($don_cur_code . ' ');
// Real median in-game price → a concrete "for reference" anchor for the admin.
$don_median = 0;
if ($shop_ok && $pdo_world) {
    try {
        $prices = $pdo_world->query("SELECT price FROM battle_pay_product WHERE price > 0 ORDER BY price")
                            ->fetchAll(PDO::FETCH_COLUMN);
        if ($prices) {
            $don_median = (int)$prices[intdiv(count($prices), 2)];
        }
    } catch (PDOException $e) {
        error_log('shop median price: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/site_settings.php';
$page_title = ($TEXT['shop_admin_title'] ?? 'Shop Management') . ' — ' . settings_site_title($pdo_auth ?? null, $config);
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();
?>

<style>
.sh-wrap { padding-top:120px; padding-bottom:3rem; }
.sh-card {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    /* When the Edit link jumps to #cat-<id>, keep the card clear of the
       fixed navbar instead of landing flush under it. */
    scroll-margin-top: 100px;
}
/* Briefly glow the category being edited so it's obvious where the jump landed. */
.sh-card.sh-editing { border-color: rgba(var(--accent-rgb), .7); box-shadow: 0 0 0 1px rgba(var(--accent-rgb), .35); }
.sh-card h2 {
    color:var(--accent); font-size:1.05rem; text-transform:uppercase; letter-spacing:1px;
    font-weight:700; margin:0 0 1rem; padding-bottom:.6rem;
    border-bottom:1px solid rgba(var(--btn-bg-rgb), .2);
    display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;
}
.sh-btn {
    padding:.45rem 1rem; border-radius:4px; border:1px solid; cursor:pointer;
    font-size:.85rem; text-decoration:none; display:inline-block;
    transition:all .15s ease; font-family:inherit;
}
.sh-btn-primary { background:var(--btn-bg); color:#fff; border-color:var(--btn-bg-hover); }
.sh-btn-primary:hover { background:var(--btn-bg-hover); color:#fff; }
.sh-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(var(--btn-bg-rgb), .3); }
.sh-btn-ghost:hover { color:var(--accent); border-color:var(--accent); }
.sh-btn-danger { background:#5a1f1f; color:#fff; border-color:#7a2a2a; }
.sh-btn-danger:hover { background:#7a2a2a; }
.sh-btn-sm { padding:.22rem .5rem; font-size:.78rem; }
.sh-input, .sh-select {
    padding:.5rem .7rem; background:#0a0a0f; border:1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius:4px; color:#fff; font-size:.9rem; font-family:inherit;
}
.sh-input:focus, .sh-select:focus { outline:none; border-color:var(--accent); }
.sh-label { display:block; font-size:.7rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.25rem; }
.sh-notice {
    background:rgba(var(--accent-rgb), .1); border:1px solid rgba(var(--accent-rgb), .3);
    color:var(--accent); padding:1rem 1.2rem; border-radius:6px; font-size:.92rem;
}
.sh-notice code { background:rgba(0,0,0,.35); padding:.05rem .4rem; border-radius:3px; color:var(--accent); }
.sh-flash-ok { background:rgba(46,204,113,.1); border:1px solid rgba(46,204,113,.3); color:#5dd87c; padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; }
.sh-flash-err { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; }
.sh-restart {
    background:linear-gradient(145deg,#3a2410,#241608); border:1px solid rgba(var(--accent-rgb), .45);
    color:var(--accent); padding:.9rem 1.2rem; border-radius:8px; margin-bottom:1.5rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}
.sh-cat-head {
    display:flex; align-items:center; gap:.7rem; margin:0 0 .8rem; flex-wrap:wrap;
    color:var(--accent); font-weight:700; font-size:1.05rem;
}
.sh-cat-meta { color:#4a5568; font-size:.78rem; font-weight:400; }
.sh-cat-actions { margin-left:auto; display:flex; gap:.35rem; flex-wrap:wrap; }
.sh-tbl { width:100%; border-collapse:collapse; color:#dee2e6; font-size:.88rem; }
.sh-tbl th {
    text-align:left; padding:.55rem .7rem; border-bottom:1px solid rgba(var(--btn-bg-rgb), .3);
    color:#8899aa; font-weight:600; text-transform:uppercase; font-size:.68rem; letter-spacing:.5px;
}
.sh-tbl td { padding:.55rem .7rem; border-bottom:1px solid rgba(var(--btn-bg-rgb), .1); vertical-align:top; }
.sh-tbl tr:hover td { background:rgba(var(--btn-bg-rgb), .05); }
.sh-price { color:#69ccf0; font-weight:700; white-space:nowrap; }
.sh-empty { color:#4a5568; font-style:italic; padding:.6rem .7rem; }
.sh-item-missing { color:#f87e8a; font-style:italic; }
.sh-mono { font-family:monospace; font-size:.8rem; color:#8899aa; }
.sh-special-pill { background:rgba(var(--accent-rgb), .15); color:var(--accent); border:1px solid rgba(var(--accent-rgb), .3); padding:.08rem .45rem; border-radius:10px; font-size:.66rem; text-transform:uppercase; letter-spacing:.5px; }
</style>

<div class="container sh-wrap">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:var(--accent);margin:0;font-weight:700">
            <i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_admin_title'] ?? 'Shop Management') ?>
        </h1>
        <a href="/admin_dashboard" class="sh-btn sh-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
    </div>

    <?php if ($flash !== ''): ?><div class="sh-flash-ok"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($flash_err !== ''): ?><div class="sh-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

    <!-- ── Battle Coins exchange rate (Ko-fi €→DP) ──────────────────────── -->
    <div class="sh-card">
        <h2><i class="bi bi-coin"></i><?= htmlspecialchars($TEXT['shop_rate_title'] ?? 'Battle Coins exchange rate') ?></h2>
        <p style="color:#8899aa;font-size:.86rem;margin:.1rem 0 1rem;line-height:1.6">
            <?= htmlspecialchars(sprintf($TEXT['shop_rate_intro'] ?? 'How many Battle Coins a player receives for each %s1 donated via Ko-fi. Saved in the database — this overrides the eur_to_dp_rate in config.php.', $don_cur_sym)) ?>
        </p>
        <form method="post" action="/admin_shop">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_donation_rate">
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="sh-label"><?= htmlspecialchars(sprintf($TEXT['shop_rate_label'] ?? 'Battle Coins per %s1', $don_cur_sym)) ?></label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <span style="color:var(--accent);font-weight:700;white-space:nowrap"><?= htmlspecialchars('1' . $don_cur_sym) ?>=</span>
                        <input id="rateInput" class="sh-input" name="eur_to_dp_rate" type="number" min="1" max="100000000" step="1" required
                               value="<?= (int)$don_rate_eff ?>" style="max-width:180px">
                        <span style="color:#8899aa;font-size:.85rem;white-space:nowrap"><?= htmlspecialchars($TEXT['shop_coins'] ?? 'Battle Coins') ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="sh-btn sh-btn-primary w-100"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['shop_rate_save'] ?? 'Save rate') ?></button>
                </div>
            </div>
        </form>
        <div style="margin-top:.9rem;font-size:.82rem;color:#4a5568">
            <?php if ($don_stored !== null): ?>
                <span style="color:#5dd87c"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($TEXT['shop_rate_override'] ?? 'Saved database override is active.') ?></span>
            <?php else: ?>
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars(sprintf($TEXT['shop_rate_default'] ?? 'Currently using the config default (%s). Saving here stores a database override.', number_format((int)$don_rate_eff))) ?>
            <?php endif; ?>
            <?php if (!$don_flag_on): ?>
                <div style="color:var(--accent);margin-top:.4rem"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($TEXT['shop_rate_disabled'] ?? 'Donations are disabled (features.donations = false). You can still set the rate now — it applies once you enable donations.') ?></div>
            <?php endif; ?>
            <div id="rateExamples" style="margin-top:.55rem;color:#8899aa"></div>
            <?php if ($don_median > 0): ?>
                <div style="margin-top:.4rem">
                    <i class="bi bi-bag me-1"></i><?= htmlspecialchars(sprintf($TEXT['shop_rate_median'] ?? 'For reference, your median in-game item costs ~%s Battle Coins', number_format($don_median))) ?><span id="rateMedianEur"></span>.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var inp = document.getElementById('rateInput');
        var ex  = document.getElementById('rateExamples');
        var med = <?= (int)$don_median ?>;
        var medEl = document.getElementById('rateMedianEur');
        var sym = <?= json_encode($don_cur_sym) ?>;
        var coins = <?= json_encode($TEXT['shop_coins'] ?? 'Battle Coins') ?>;
        if (!inp) return;
        function fmt(n) { return Math.round(n).toLocaleString(); }
        function upd() {
            var r = parseInt(inp.value, 10) || 0;
            if (r < 1) { ex.textContent = ''; if (medEl) medEl.textContent = ''; return; }
            ex.innerHTML = '<i class="bi bi-calculator me-1"></i>1' + sym + ' → ' + fmt(r) +
                '  ·  5' + sym + ' → ' + fmt(r * 5) +
                '  ·  25' + sym + ' → ' + fmt(r * 25) + ' ' + coins;
            if (med > 0 && medEl) { medEl.textContent = ' (≈ ' + sym + (med / r).toFixed(2) + ')'; }
        }
        inp.addEventListener('input', upd);
        upd();
    })();
    </script>

    <?php if (!$shop_ok): ?>
        <div class="sh-card">
            <div class="sh-notice">
                <i class="bi bi-info-circle me-2"></i>
                <?php if ($shop_reason === 'disabled'): ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_disabled'] ?? 'Shop management is disabled. Set features.shop_admin = true in config.php to enable it.') ?>
                <?php elseif ($shop_reason === 'no_world_db'): ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_no_db'] ?? 'Cannot reach the world database. Add a correct db.name_world to config.php (commonly "world" or "mop_world").') ?>
                <?php else: ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_no_tables'] ?? 'The world database is reachable but has no battle_pay_* tables. Your repack may not support shop management.') ?>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($mode === 'tile'): ?>
        <?php
        $isEdit  = ($tile !== null);
        $cats    = shop_categories_simple($pdo_world);
        $curGrp  = $isEdit ? (int)$tile['groupId'] : $tile_cat;
        $itemRows = $isEdit ? $tile['items'] : [];
        $backUrl = '/admin_shop#cat-' . $curGrp;
        ?>
        <div style="margin-bottom:1rem">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="sh-btn sh-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['shop_back_overview'] ?? 'Back to shop') ?></a>
        </div>
        <div class="sh-card">
            <h2>
                <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-square' ?>"></i>
                <?= htmlspecialchars($isEdit ? ($TEXT['shop_tile_edit_title'] ?? 'Edit item') : ($TEXT['shop_tile_new_title'] ?? 'New item')) ?>
            </h2>
            <form method="post" action="/admin_shop" id="tileForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save_tile">
                <input type="hidden" name="entry_id" value="<?= $isEdit ? (int)$tile['entry_id'] : 0 ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_title'] ?? 'Title (max 50)') ?></label>
                        <input class="sh-input w-100" name="title" maxlength="50" required
                               value="<?= htmlspecialchars($isEdit ? (string)$tile['entry_title'] : '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_cat'] ?? 'Category') ?></label>
                        <select class="sh-select w-100" name="groupId">
                            <?php foreach ($cats as $gid => $gname): ?>
                                <option value="<?= (int)$gid ?>" <?= $gid === $curGrp ? 'selected' : '' ?>><?= htmlspecialchars($gname) ?> (#<?= (int)$gid ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_desc'] ?? 'Description (max 500)') ?></label>
                        <textarea class="sh-input w-100" name="description" maxlength="500" rows="2"><?= htmlspecialchars($isEdit ? (string)$tile['entry_desc'] : '') ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_price'] ?? 'Price (Battle Coins)') ?></label>
                        <input class="sh-input w-100" name="price" type="number" min="0" value="<?= $isEdit ? (int)$tile['price'] : 0 ?>">
                    </div>
                </div>

                <details style="margin-top:1.25rem;border:1px solid rgba(var(--btn-bg-rgb), .25);border-radius:6px" <?= ($isEdit && ((int)$tile['discount'] > 0 || (int)$tile['p_displayId'] > 0)) ? 'open' : '' ?>>
                    <summary style="cursor:pointer;padding:.7rem 1rem;color:var(--accent);font-weight:600;font-size:.9rem">
                        <i class="bi bi-sliders me-1"></i><?= htmlspecialchars($TEXT['shop_tile_appearance'] ?? 'Appearance & extras (optional)') ?>
                    </summary>
                    <div style="padding:0 1rem 1rem">
                        <p style="color:#8899aa;font-size:.8rem;line-height:1.5;margin:.2rem 0 1rem">
                            <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['shop_appearance_help'] ?? 'These are WoW client asset IDs. The website cannot preview them (no game files). Easiest: leave them — when you pick an item, the tile icon is auto-filled from a matching shop entry where possible. Or copy an icon from an existing tile below.') ?>
                        </p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_icon'] ?? 'Icon (FileDataID)') ?></label>
                                <input id="iconField" class="sh-input w-100" name="icon" type="number" min="0" value="<?= $isEdit ? (int)$tile['p_icon'] : 0 ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="sh-label"><?= htmlspecialchars($TEXT['shop_icon_copy'] ?? 'Copy icon from an existing tile') ?></label>
                                <select id="iconCopy" class="sh-select w-100">
                                    <option value=""><?= htmlspecialchars($TEXT['shop_icon_copy_ph'] ?? '— pick a tile to reuse its icon —') ?></option>
                                    <?php foreach (shop_icon_catalog($pdo_world) as $ic): ?>
                                        <option value="<?= (int)$ic['icon'] ?>"><?= htmlspecialchars($ic['label']) ?> (#<?= (int)$ic['icon'] ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_discount'] ?? 'Discount') ?></label>
                                <input class="sh-input w-100" name="discount" type="number" min="0" value="<?= $isEdit ? (int)$tile['discount'] : 0 ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_display'] ?? 'displayId (3D model, 0 = icon only)') ?></label>
                                <input class="sh-input w-100" name="displayId" type="number" min="0" value="<?= $isEdit ? (int)$tile['p_displayId'] : 0 ?>">
                            </div>
                        </div>
                    </div>
                </details>

                <div style="margin-top:1.5rem;border-top:1px solid rgba(var(--btn-bg-rgb), .2);padding-top:1rem">
                    <label class="sh-label"><?= htmlspecialchars($TEXT['shop_tile_f_items'] ?? 'Granted item(s)') ?></label>
                    <div style="display:flex;gap:.5rem;margin-bottom:.6rem;flex-wrap:wrap">
                        <input id="itemSearch" class="sh-input" style="flex:1;min-width:220px" placeholder="<?= htmlspecialchars($TEXT['shop_item_search_ph'] ?? 'Search item by name or id…') ?>" autocomplete="off">
                    </div>
                    <div id="itemSearchResults" style="margin-bottom:.6rem"></div>
                    <table class="sh-tbl" id="itemRows">
                        <thead><tr>
                            <th><?= htmlspecialchars($TEXT['shop_item_col_id'] ?? 'Item ID') ?></th>
                            <th><?= htmlspecialchars($TEXT['shop_item_col_name'] ?? 'Name') ?></th>
                            <th style="width:90px"><?= htmlspecialchars($TEXT['shop_item_col_qty'] ?? 'Qty') ?></th>
                            <th style="width:60px"></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                    <div style="color:#4a5568;font-size:.78rem;margin-top:.5rem">
                        <?= htmlspecialchars($TEXT['shop_items_hint'] ?? 'One item = a normal mount/pet tile (the verified case). Multiple rows create a bundle — advanced; in-game behaviour depends on the repack.') ?>
                    </div>
                </div>

                <div style="margin-top:1.5rem;display:flex;gap:.5rem">
                    <button type="submit" class="sh-btn sh-btn-primary"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['shop_save'] ?? 'Save') ?></button>
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="sh-btn sh-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
                </div>
            </form>
        </div>

        <script>
        (function () {
            var tbody = document.querySelector('#itemRows tbody');
            var search = document.getElementById('itemSearch');
            var results = document.getElementById('itemSearchResults');
            var seed = <?= json_encode(array_map(function ($r) {
                return ['itemId' => (int)$r['itemId'], 'count' => (int)$r['count'], 'name' => $r['item_name'] !== null ? $r['item_name'] : ''];
            }, $itemRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }

            var WH = 'https://www.wowhead.com/mop-classic/item=';
            function refreshWowhead(){ try { if (window.$WowheadPower) $WowheadPower.refreshLinks(); } catch(e){} }

            function addRow(itemId, name, count) {
                var tr = document.createElement('tr');
                var nameCell = (itemId|0) > 0 && name
                    ? '<a href="' + WH + (itemId|0) + '" target="_blank" rel="noopener noreferrer">' + esc(name) + '</a>'
                    : esc(name || '');
                tr.innerHTML =
                    '<td><input class="sh-input" style="width:110px" type="number" min="1" name="itemId[]" value="' + (itemId|0) + '" required></td>' +
                    '<td class="rowname" style="color:#dee2e6">' + nameCell + '</td>' +
                    '<td><input class="sh-input" style="width:70px" type="number" min="1" name="count[]" value="' + (count > 0 ? count : 1) + '"></td>' +
                    '<td><button type="button" class="sh-btn sh-btn-danger sh-btn-sm rowdel"><i class="bi bi-x-lg"></i></button></td>';
                tbody.appendChild(tr);
                tr.querySelector('.rowdel').addEventListener('click', function(){ tr.remove(); });
                refreshWowhead();
            }

            (seed.length ? seed : [{itemId:0,name:'',count:1}]).forEach(function(r){ addRow(r.itemId, r.name, r.count); });
            // drop the placeholder empty row's required if it's the only blank one for "new"
            if (!seed.length) { var f = tbody.querySelector('input[name="itemId[]"]'); if (f) f.value = ''; }

            // "Copy icon from an existing tile" → fill the icon field
            var iconCopy = document.getElementById('iconCopy');
            var iconField = document.getElementById('iconField');
            if (iconCopy && iconField) {
                iconCopy.addEventListener('change', function(){
                    if (this.value) iconField.value = this.value;
                });
            }

            var t;
            search.addEventListener('input', function () {
                clearTimeout(t);
                var q = search.value.trim();
                if (q.length < 2) { results.innerHTML = ''; return; }
                t = setTimeout(function () {
                    fetch('/shop_item_search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            var its = (j && j.items) || [];
                            if (!its.length) { results.innerHTML = '<div style="color:#4a5568;font-size:.82rem;padding:.3rem 0">' + <?= json_encode($TEXT['shop_item_no_results'] ?? 'No matching items.') ?> + '</div>'; return; }
                            results.innerHTML = its.map(function(it){
                                return '<button type="button" class="sh-btn sh-btn-ghost sh-btn-sm pick" data-id="' + it.entry + '" data-name="' + esc(it.name) + '" data-icon="' + (it.suggest_icon|0) + '" style="margin:.15rem .25rem .15rem 0">' + esc(it.name) + ' <span class="sh-mono">#' + it.entry + '</span></button>';
                            }).join('');
                            results.querySelectorAll('.pick').forEach(function(b){
                                b.addEventListener('click', function(){
                                    addRow(parseInt(b.dataset.id,10), b.dataset.name, 1);
                                    // Auto-prefill the tile icon from a matching shop entry,
                                    // but never overwrite an icon the admin already set.
                                    var sug = parseInt(b.dataset.icon,10) || 0;
                                    if (sug > 0 && iconField && (!iconField.value || iconField.value === '0')) {
                                        iconField.value = sug;
                                    }
                                    results.innerHTML = ''; search.value = '';
                                });
                            });
                        })
                        .catch(function(){ results.innerHTML = ''; });
                }, 250);
            });
        })();
        </script>

    <?php else: ?>
        <?php if ($dirty_ts !== null): ?>
            <div class="sh-restart">
                <div>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong><?= htmlspecialchars($TEXT['shop_restart_title'] ?? 'Worldserver restart required') ?></strong>
                    — <?= htmlspecialchars($TEXT['shop_restart_body'] ?? 'Shop changes are saved to the database but stay invisible in-game until you restart the worldserver. (Restarting disconnects online players for ~1–2 min.)') ?>
                    <span style="color:#8899aa;font-size:.82rem">(<?= htmlspecialchars($TEXT['shop_restart_since'] ?? 'pending since') ?> <?= htmlspecialchars(date('M j, H:i', $dirty_ts)) ?>)</span>
                </div>
                <form method="post" action="/admin_shop" style="flex-shrink:0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="clear_dirty">
                    <button type="submit" class="sh-btn sh-btn-ghost" style="border-color:rgba(var(--accent-rgb), .5);color:var(--accent)">
                        <i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['shop_restart_done'] ?? "I've restarted it") ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php
        $shop = shop_get_full($pdo_world);
        $cnt  = shop_counts($pdo_world);
        ?>
        <div class="sh-card">
            <div style="color:#8899aa;font-size:.9rem;display:flex;gap:1.5rem;flex-wrap:wrap;align-items:center">
                <span><i class="bi bi-folder me-1"></i><strong style="color:var(--accent)"><?= (int)$cnt['categories'] ?></strong> <?= htmlspecialchars($TEXT['shop_categories'] ?? 'categories') ?></span>
                <span><i class="bi bi-grid me-1"></i><strong style="color:var(--accent)"><?= (int)$cnt['tiles'] ?></strong> <?= htmlspecialchars($TEXT['shop_tiles'] ?? 'item tiles') ?></span>
                <span style="color:#4a5568"><i class="bi bi-database me-1"></i><?= htmlspecialchars($config['db']['name_world'] ?? '') ?></span>
            </div>
            <p style="color:#4a5568;font-size:.82rem;margin:.8rem 0 0">
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['shop_a2_hint'] ?? 'Categories are now editable. Item/tile editing arrives in the next phase (still read-only below).') ?>
            </p>
        </div>

        <!-- ── Add category ─────────────────────────────────────────────── -->
        <div class="sh-card">
            <h2><i class="bi bi-folder-plus"></i><?= htmlspecialchars($TEXT['shop_add_cat'] ?? 'Add category') ?></h2>
            <form method="post" action="/admin_shop">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add_category">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_name'] ?? 'Name (max 16)') ?></label>
                        <input class="sh-input w-100" name="name" maxlength="16" required placeholder="e.g. Mounts">
                    </div>
                    <div class="col-md-3">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_icon'] ?? 'Icon (FileDataID)') ?></label>
                        <input class="sh-input w-100" name="icon" type="number" min="0" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_type'] ?? 'Type') ?></label>
                        <select class="sh-select w-100" name="type">
                            <option value="0"><?= htmlspecialchars($TEXT['shop_cat_type_normal'] ?? 'Normal') ?></option>
                            <option value="1"><?= htmlspecialchars($TEXT['shop_cat_type_special'] ?? 'Special (banner)') ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="sh-btn sh-btn-primary w-100"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['shop_add'] ?? 'Add') ?></button>
                    </div>
                </div>
                <div style="color:#4a5568;font-size:.78rem;margin-top:.5rem">
                    <?= htmlspecialchars($TEXT['shop_cat_icon_hint'] ?? 'Icon is a client FileDataID — copy one from an existing category below. New rows get a reserved id ≥ 9000.') ?>
                </div>
            </form>
        </div>

        <?php if (empty($shop)): ?>
            <div class="sh-card"><div class="sh-empty"><?= htmlspecialchars($TEXT['shop_no_categories'] ?? 'No shop categories found in the world DB.') ?></div></div>
        <?php else: ?>
            <?php $catCount = count($shop); foreach ($shop as $ci => $cat):
                $is_editing = $edit_cat && (int)$edit_cat['id'] === (int)$cat['id']; ?>
                <div class="sh-card<?= $is_editing ? ' sh-editing' : '' ?>" id="cat-<?= (int)$cat['id'] ?>">
                    <div class="sh-cat-head">
                        <i class="bi bi-folder2-open"></i>
                        <span><?= htmlspecialchars($cat['name']) ?></span>
                        <?php if ($cat['type'] === 1): ?><span class="sh-special-pill">special</span><?php endif; ?>
                        <span class="sh-cat-meta">id <?= (int)$cat['id'] ?> · idx <?= (int)$cat['idx'] ?> · icon <?= (int)$cat['icon'] ?> · <?= count($cat['tiles']) ?> <?= htmlspecialchars($TEXT['shop_tiles'] ?? 'tiles') ?></span>

                        <div class="sh-cat-actions">
                            <form method="post" action="/admin_shop" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="move_category">
                                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                <input type="hidden" name="dir" value="up">
                                <button type="submit" class="sh-btn sh-btn-ghost sh-btn-sm" title="<?= htmlspecialchars($TEXT['shop_move_up'] ?? 'Move up') ?>" <?= $ci === 0 ? 'disabled style="opacity:.3"' : '' ?>><i class="bi bi-arrow-up"></i></button>
                            </form>
                            <form method="post" action="/admin_shop" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="move_category">
                                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                <input type="hidden" name="dir" value="down">
                                <button type="submit" class="sh-btn sh-btn-ghost sh-btn-sm" title="<?= htmlspecialchars($TEXT['shop_move_down'] ?? 'Move down') ?>" <?= $ci === $catCount - 1 ? 'disabled style="opacity:.3"' : '' ?>><i class="bi bi-arrow-down"></i></button>
                            </form>
                            <a href="/admin_shop?edit_cat=<?= (int)$cat['id'] ?>#cat-<?= (int)$cat['id'] ?>" class="sh-btn sh-btn-ghost sh-btn-sm"><i class="bi bi-pencil me-1"></i><?= htmlspecialchars($TEXT['shop_edit'] ?? 'Edit') ?></a>
                            <form method="post" action="/admin_shop" style="display:inline"
                                  onsubmit="return confirm('<?= htmlspecialchars($TEXT['shop_cat_del_confirm'] ?? 'Delete this category and all its tiles? Orphaned products are cleaned up. This cannot be undone.', ENT_QUOTES) ?>')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                <input type="hidden" name="cleanup_orphans" value="1">
                                <button type="submit" class="sh-btn sh-btn-danger sh-btn-sm"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>

                    <?php if ($edit_cat && (int)$edit_cat['id'] === (int)$cat['id']): ?>
                        <form method="post" action="/admin_shop" style="background:#0a0a0f;border:1px solid rgba(var(--btn-bg-rgb), .3);border-radius:6px;padding:1rem;margin-bottom:1rem">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="edit_category">
                            <input type="hidden" name="id" value="<?= (int)$edit_cat['id'] ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_name'] ?? 'Name (max 16)') ?></label>
                                    <input class="sh-input w-100" name="name" maxlength="16" required value="<?= htmlspecialchars($edit_cat['name']) ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_icon'] ?? 'Icon (FileDataID)') ?></label>
                                    <input class="sh-input w-100" name="icon" type="number" min="0" value="<?= (int)$edit_cat['icon'] ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="sh-label"><?= htmlspecialchars($TEXT['shop_cat_type'] ?? 'Type') ?></label>
                                    <select class="sh-select w-100" name="type">
                                        <option value="0" <?= (int)$edit_cat['type'] === 0 ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['shop_cat_type_normal'] ?? 'Normal') ?></option>
                                        <option value="1" <?= (int)$edit_cat['type'] === 1 ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['shop_cat_type_special'] ?? 'Special (banner)') ?></option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex gap-2">
                                    <button type="submit" class="sh-btn sh-btn-primary"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['shop_save'] ?? 'Save') ?></button>
                                    <a href="/admin_shop" class="sh-btn sh-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div style="margin-bottom:.8rem">
                        <a href="/admin_shop?new_tile=1&cat=<?= (int)$cat['id'] ?>" class="sh-btn sh-btn-primary sh-btn-sm"><i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['shop_add_item'] ?? 'Add item') ?></a>
                    </div>

                    <?php if (empty($cat['tiles'])): ?>
                        <div class="sh-empty"><?= htmlspecialchars($TEXT['shop_cat_empty'] ?? 'No items in this category.') ?></div>
                    <?php else: ?>
                        <div style="overflow-x:auto">
                            <table class="sh-tbl">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars($TEXT['shop_col_tile'] ?? 'Tile') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_grants'] ?? 'Grants item(s)') ?></th>
                                        <th style="width:170px"><?= htmlspecialchars($TEXT['shop_col_price'] ?? 'Price') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_ids'] ?? 'IDs') ?></th>
                                        <th style="width:170px" class="text-end"><?= htmlspecialchars($TEXT['shop_col_actions'] ?? 'Actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $tn = count($cat['tiles']); foreach ($cat['tiles'] as $ti => $t): ?>
                                    <tr>
                                        <td><strong style="color:var(--accent)"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></strong></td>
                                        <td>
                                            <?php if (empty($t['items'])): ?>
                                                <span class="sh-empty"><?= htmlspecialchars($TEXT['shop_no_items'] ?? 'no item rows') ?></span>
                                            <?php else: foreach ($t['items'] as $it): ?>
                                                <div>
                                                    <?php if ($it['item_name'] !== null): ?>
                                                        <a href="<?= htmlspecialchars(shop_wowhead_item_url((int)$it['itemId'])) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($it['item_name']) ?></a>
                                                    <?php else: ?>
                                                        <span class="sh-item-missing"><?= htmlspecialchars($TEXT['shop_item_missing'] ?? 'item not in item_template') ?></span>
                                                    <?php endif; ?>
                                                    <span class="sh-mono">#<?= (int)$it['itemId'] ?><?= (int)$it['count'] > 1 ? ' ×' . (int)$it['count'] : '' ?></span>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['product_id'] !== null): ?>
                                                <form method="post" action="/admin_shop" style="display:flex;gap:.3rem;align-items:center">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                    <input type="hidden" name="action" value="update_price">
                                                    <input type="hidden" name="product_id" value="<?= (int)$t['product_id'] ?>">
                                                    <input class="sh-input" style="width:95px;padding:.25rem .4rem" type="number" min="0" name="price" value="<?= (int)$t['price'] ?>">
                                                    <button type="submit" class="sh-btn sh-btn-ghost sh-btn-sm" title="<?= htmlspecialchars($TEXT['shop_save_price'] ?? 'Save price') ?>"><i class="bi bi-check2"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="sh-price">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="sh-mono">e<?= (int)$t['entry_id'] ?> · p<?= $t['product_id'] !== null ? (int)$t['product_id'] : '—' ?></td>
                                        <td class="text-end" style="white-space:nowrap">
                                            <form method="post" action="/admin_shop" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="move_tile">
                                                <input type="hidden" name="entry_id" value="<?= (int)$t['entry_id'] ?>">
                                                <input type="hidden" name="dir" value="up">
                                                <button type="submit" class="sh-btn sh-btn-ghost sh-btn-sm" title="<?= htmlspecialchars($TEXT['shop_move_up'] ?? 'Move up') ?>" <?= $ti === 0 ? 'disabled style="opacity:.3"' : '' ?>><i class="bi bi-arrow-up"></i></button>
                                            </form>
                                            <form method="post" action="/admin_shop" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="move_tile">
                                                <input type="hidden" name="entry_id" value="<?= (int)$t['entry_id'] ?>">
                                                <input type="hidden" name="dir" value="down">
                                                <button type="submit" class="sh-btn sh-btn-ghost sh-btn-sm" title="<?= htmlspecialchars($TEXT['shop_move_down'] ?? 'Move down') ?>" <?= $ti === $tn - 1 ? 'disabled style="opacity:.3"' : '' ?>><i class="bi bi-arrow-down"></i></button>
                                            </form>
                                            <a href="/admin_shop?edit_tile=<?= (int)$t['entry_id'] ?>" class="sh-btn sh-btn-ghost sh-btn-sm"><i class="bi bi-pencil"></i></a>
                                            <form method="post" action="/admin_shop" style="display:inline"
                                                  onsubmit="return confirm('<?= htmlspecialchars($TEXT['shop_tile_del_confirm'] ?? 'Delete this item tile? If its product is used nowhere else it is removed too. This cannot be undone.', ENT_QUOTES) ?>')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="delete_tile">
                                                <input type="hidden" name="entry_id" value="<?= (int)$t['entry_id'] ?>">
                                                <button type="submit" class="sh-btn sh-btn-danger sh-btn-sm"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($shop_ok): ?>
<!-- Wowhead: turns mop-classic item links into colored, icon'd, hover-tooltip names -->
<script>
const whTooltips = { colorLinks: true, iconizeLinks: true, renameLinks: true };
</script>
<script src="https://wow.zamimg.com/widgets/power.js"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
