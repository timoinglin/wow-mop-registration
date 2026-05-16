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

    $redirect();
}

// ─── GET ────────────────────────────────────────────────────────────────────
$edit_cat_id = (int)($_GET['edit_cat'] ?? 0);
$edit_cat    = ($shop_ok && $edit_cat_id > 0) ? shop_category_get($pdo_world, $edit_cat_id) : null;

$flash = '';
if (isset($_GET['saved'])) {
    $flash = match ($_GET['saved']) {
        'cat_added'   => $TEXT['shop_flash_cat_added']   ?? 'Category added.',
        'cat_saved'   => $TEXT['shop_flash_cat_saved']   ?? 'Category updated.',
        'cat_deleted' => $TEXT['shop_flash_cat_deleted'] ?? 'Category deleted.',
        'cat_moved'   => $TEXT['shop_flash_cat_moved']   ?? 'Category reordered.',
        'restart_ack' => $TEXT['shop_flash_restart_ack'] ?? 'Restart acknowledged — banner cleared.',
        default       => '',
    };
}
$flash_err = '';
if (isset($_GET['err'])) {
    $flash_err = match ($_GET['err']) {
        'csrf' => $TEXT['shop_err_csrf'] ?? 'Session expired. Please try again.',
        'name' => $TEXT['shop_err_name'] ?? 'Category name is required (max 16 chars).',
        default => $TEXT['shop_err_save'] ?? 'Could not save the change.',
    };
}

$dirty_ts   = shop_is_dirty();
$page_title = ($TEXT['shop_admin_title'] ?? 'Shop Management') . ' — ' . ($config['site']['title'] ?? 'WoW');
require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();
?>

<style>
.sh-wrap { padding-top:120px; padding-bottom:3rem; }
.sh-card {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.sh-card h2 {
    color:#c8a96e; font-size:1.05rem; text-transform:uppercase; letter-spacing:1px;
    font-weight:700; margin:0 0 1rem; padding-bottom:.6rem;
    border-bottom:1px solid rgba(139,69,19,.2);
    display:flex; align-items:center; gap:.6rem; flex-wrap:wrap;
}
.sh-btn {
    padding:.45rem 1rem; border-radius:4px; border:1px solid; cursor:pointer;
    font-size:.85rem; text-decoration:none; display:inline-block;
    transition:all .15s ease; font-family:inherit;
}
.sh-btn-primary { background:#8B4513; color:#fff; border-color:#A0522D; }
.sh-btn-primary:hover { background:#A0522D; color:#fff; }
.sh-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(139,69,19,.3); }
.sh-btn-ghost:hover { color:#c8a96e; border-color:#c8a96e; }
.sh-btn-danger { background:#5a1f1f; color:#fff; border-color:#7a2a2a; }
.sh-btn-danger:hover { background:#7a2a2a; }
.sh-btn-sm { padding:.22rem .5rem; font-size:.78rem; }
.sh-input, .sh-select {
    padding:.5rem .7rem; background:#0a0a0f; border:1px solid rgba(139,69,19,.3);
    border-radius:4px; color:#fff; font-size:.9rem; font-family:inherit;
}
.sh-input:focus, .sh-select:focus { outline:none; border-color:#c8a96e; }
.sh-label { display:block; font-size:.7rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.25rem; }
.sh-notice {
    background:rgba(240,192,64,.1); border:1px solid rgba(240,192,64,.3);
    color:#f0c040; padding:1rem 1.2rem; border-radius:6px; font-size:.92rem;
}
.sh-notice code { background:rgba(0,0,0,.35); padding:.05rem .4rem; border-radius:3px; color:#f0c040; }
.sh-flash-ok { background:rgba(46,204,113,.1); border:1px solid rgba(46,204,113,.3); color:#5dd87c; padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; }
.sh-flash-err { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; }
.sh-restart {
    background:linear-gradient(145deg,#3a2410,#241608); border:1px solid rgba(240,192,64,.45);
    color:#f0c040; padding:.9rem 1.2rem; border-radius:8px; margin-bottom:1.5rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}
.sh-cat-head {
    display:flex; align-items:center; gap:.7rem; margin:0 0 .8rem; flex-wrap:wrap;
    color:#c8a96e; font-weight:700; font-size:1.05rem;
}
.sh-cat-meta { color:#4a5568; font-size:.78rem; font-weight:400; }
.sh-cat-actions { margin-left:auto; display:flex; gap:.35rem; flex-wrap:wrap; }
.sh-tbl { width:100%; border-collapse:collapse; color:#dee2e6; font-size:.88rem; }
.sh-tbl th {
    text-align:left; padding:.55rem .7rem; border-bottom:1px solid rgba(139,69,19,.3);
    color:#8899aa; font-weight:600; text-transform:uppercase; font-size:.68rem; letter-spacing:.5px;
}
.sh-tbl td { padding:.55rem .7rem; border-bottom:1px solid rgba(139,69,19,.1); vertical-align:top; }
.sh-tbl tr:hover td { background:rgba(139,69,19,.05); }
.sh-price { color:#69ccf0; font-weight:700; white-space:nowrap; }
.sh-empty { color:#4a5568; font-style:italic; padding:.6rem .7rem; }
.sh-item-missing { color:#f87e8a; font-style:italic; }
.sh-mono { font-family:monospace; font-size:.8rem; color:#8899aa; }
.sh-special-pill { background:rgba(240,192,64,.15); color:#f0c040; border:1px solid rgba(240,192,64,.3); padding:.08rem .45rem; border-radius:10px; font-size:.66rem; text-transform:uppercase; letter-spacing:.5px; }
</style>

<div class="container sh-wrap">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:#c8a96e;margin:0;font-weight:700">
            <i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_admin_title'] ?? 'Shop Management') ?>
        </h1>
        <a href="/admin_dashboard" class="sh-btn sh-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
    </div>

    <?php if ($flash !== ''): ?><div class="sh-flash-ok"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($flash_err !== ''): ?><div class="sh-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

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
                    <button type="submit" class="sh-btn sh-btn-ghost" style="border-color:rgba(240,192,64,.5);color:#f0c040">
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
                <span><i class="bi bi-folder me-1"></i><strong style="color:#c8a96e"><?= (int)$cnt['categories'] ?></strong> <?= htmlspecialchars($TEXT['shop_categories'] ?? 'categories') ?></span>
                <span><i class="bi bi-grid me-1"></i><strong style="color:#c8a96e"><?= (int)$cnt['tiles'] ?></strong> <?= htmlspecialchars($TEXT['shop_tiles'] ?? 'item tiles') ?></span>
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
            <?php $catCount = count($shop); foreach ($shop as $ci => $cat): ?>
                <div class="sh-card">
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
                            <a href="/admin_shop?edit_cat=<?= (int)$cat['id'] ?>" class="sh-btn sh-btn-ghost sh-btn-sm"><i class="bi bi-pencil me-1"></i><?= htmlspecialchars($TEXT['shop_edit'] ?? 'Edit') ?></a>
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
                        <form method="post" action="/admin_shop" style="background:#0a0a0f;border:1px solid rgba(139,69,19,.3);border-radius:6px;padding:1rem;margin-bottom:1rem">
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

                    <?php if (empty($cat['tiles'])): ?>
                        <div class="sh-empty"><?= htmlspecialchars($TEXT['shop_cat_empty'] ?? 'No items in this category.') ?></div>
                    <?php else: ?>
                        <div style="overflow-x:auto">
                            <table class="sh-tbl">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars($TEXT['shop_col_tile'] ?? 'Tile') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_grants'] ?? 'Grants item(s)') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_price'] ?? 'Price') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_ids'] ?? 'IDs') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($cat['tiles'] as $t): ?>
                                    <tr>
                                        <td><strong style="color:#c8a96e"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></strong></td>
                                        <td>
                                            <?php if (empty($t['items'])): ?>
                                                <span class="sh-empty"><?= htmlspecialchars($TEXT['shop_no_items'] ?? 'no item rows') ?></span>
                                            <?php else: foreach ($t['items'] as $it): ?>
                                                <div>
                                                    <?php if ($it['item_name'] !== null): ?>
                                                        <?= htmlspecialchars($it['item_name']) ?>
                                                    <?php else: ?>
                                                        <span class="sh-item-missing"><?= htmlspecialchars($TEXT['shop_item_missing'] ?? 'item not in item_template') ?></span>
                                                    <?php endif; ?>
                                                    <span class="sh-mono">#<?= (int)$it['itemId'] ?><?= (int)$it['count'] > 1 ? ' ×' . (int)$it['count'] : '' ?></span>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </td>
                                        <td><span class="sh-price"><?= $t['price'] !== null ? number_format((int)$t['price']) : '—' ?></span></td>
                                        <td class="sh-mono">e<?= (int)$t['entry_id'] ?> · p<?= $t['product_id'] !== null ? (int)$t['product_id'] : '—' ?></td>
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
