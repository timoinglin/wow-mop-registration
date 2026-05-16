<?php
/**
 * Admin: in-game Battle Pay shop management.
 *
 * Phase A1 = READ-ONLY viewer. Proves the world DB connection + schema
 * mapping before any write code exists (A2 = category CRUD, A3 = item CRUD).
 *
 * GM 9+ only. Degrades gracefully when the shop feature is off, the world DB
 * is unreachable, or the repack has no battle_pay_* tables.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/shop.php';

// GM 9+ guard (same pattern as /admin_forum, /admin_news)
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm->fetchColumn() ?: 0);
if ($gm_level < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $gm_level;

[$shop_ok, $shop_reason] = shop_availability($pdo_world ?? null, $config);

$page_title = ($TEXT['shop_admin_title'] ?? 'Shop Management') . ' — ' . ($config['site']['title'] ?? 'WoW');
require_once __DIR__ . '/../templates/header.php';
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
.sh-btn-ghost {
    padding:.45rem 1rem; border-radius:4px; border:1px solid rgba(139,69,19,.3);
    background:transparent; color:#8899aa; text-decoration:none; font-size:.85rem;
    display:inline-block; transition:all .15s ease;
}
.sh-btn-ghost:hover { color:#c8a96e; border-color:#c8a96e; }
.sh-notice {
    background:rgba(240,192,64,.1); border:1px solid rgba(240,192,64,.3);
    color:#f0c040; padding:1rem 1.2rem; border-radius:6px; font-size:.92rem;
}
.sh-notice code { background:rgba(0,0,0,.35); padding:.05rem .4rem; border-radius:3px; color:#f0c040; }
.sh-cat-head {
    display:flex; align-items:center; gap:.7rem; margin:0 0 .8rem;
    color:#c8a96e; font-weight:700; font-size:1.05rem;
}
.sh-cat-meta { color:#4a5568; font-size:.78rem; font-weight:400; }
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
.sh-readonly-pill {
    background:rgba(105,204,240,.12); color:#69ccf0; border:1px solid rgba(105,204,240,.3);
    padding:.12rem .55rem; border-radius:10px; font-size:.68rem; text-transform:uppercase; letter-spacing:.5px;
}
</style>

<div class="container sh-wrap">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:#c8a96e;margin:0;font-weight:700">
            <i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_admin_title'] ?? 'Shop Management') ?>
            <span class="sh-readonly-pill ms-2"><?= htmlspecialchars($TEXT['shop_readonly'] ?? 'Read-only') ?></span>
        </h1>
        <a href="/admin_dashboard" class="sh-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
    </div>

    <?php if (!$shop_ok): ?>
        <div class="sh-card">
            <div class="sh-notice">
                <i class="bi bi-info-circle me-2"></i>
                <?php if ($shop_reason === 'disabled'): ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_disabled'] ?? 'Shop management is disabled. Set features.shop_admin = true in config.php to enable it.') ?>
                <?php elseif ($shop_reason === 'no_world_db'): ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_no_db'] ?? 'Cannot reach the world database. Add a correct db.name_world to config.php (commonly "world" or "mop_world").') ?>
                <?php else: /* no_tables */ ?>
                    <?= htmlspecialchars($TEXT['shop_unavail_no_tables'] ?? 'The world database is reachable but has no battle_pay_* tables. Your repack may not support shop management.') ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php
        $shop = shop_get_full($pdo_world);
        $cnt  = shop_counts($pdo_world);
        ?>
        <div class="sh-card">
            <div style="color:#8899aa;font-size:.9rem;display:flex;gap:1.5rem;flex-wrap:wrap">
                <span><i class="bi bi-folder me-1"></i><strong style="color:#c8a96e"><?= (int)$cnt['categories'] ?></strong> <?= htmlspecialchars($TEXT['shop_categories'] ?? 'categories') ?></span>
                <span><i class="bi bi-grid me-1"></i><strong style="color:#c8a96e"><?= (int)$cnt['tiles'] ?></strong> <?= htmlspecialchars($TEXT['shop_tiles'] ?? 'item tiles') ?></span>
                <span style="color:#4a5568"><i class="bi bi-database me-1"></i><?= htmlspecialchars($config['db']['name_world'] ?? '') ?></span>
            </div>
            <p style="color:#4a5568;font-size:.82rem;margin:.8rem 0 0">
                <i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['shop_readonly_hint'] ?? 'Phase 1 — read-only overview. Category & item editing arrives in the next phases.') ?>
            </p>
        </div>

        <?php if (empty($shop)): ?>
            <div class="sh-card"><div class="sh-empty"><?= htmlspecialchars($TEXT['shop_no_categories'] ?? 'No shop categories found in the world DB.') ?></div></div>
        <?php else: ?>
            <?php foreach ($shop as $cat): ?>
                <div class="sh-card">
                    <div class="sh-cat-head">
                        <i class="bi bi-folder2-open"></i>
                        <span><?= htmlspecialchars($cat['name']) ?></span>
                        <span class="sh-cat-meta">
                            id <?= (int)$cat['id'] ?> · idx <?= (int)$cat['idx'] ?>
                            <?php if ($cat['type'] === 1): ?> · <span style="color:#f0c040">special</span><?php endif; ?>
                            · icon <?= (int)$cat['icon'] ?>
                            · <?= count($cat['tiles']) ?> <?= htmlspecialchars($TEXT['shop_tiles'] ?? 'tiles') ?>
                        </span>
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
                                        <th><?= htmlspecialchars($TEXT['shop_col_price'] ?? 'Price') ?></th>
                                        <th><?= htmlspecialchars($TEXT['shop_col_ids'] ?? 'IDs') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($cat['tiles'] as $t): ?>
                                    <tr>
                                        <td>
                                            <strong style="color:#c8a96e"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></strong>
                                        </td>
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
                                        <td class="sh-mono">
                                            e<?= (int)$t['entry_id'] ?> · p<?= $t['product_id'] !== null ? (int)$t['product_id'] : '—' ?>
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

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
