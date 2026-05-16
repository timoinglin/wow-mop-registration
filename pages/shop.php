<?php
/**
 * Public / user-facing shop catalog.
 *
 * Phase B1: read-only browse of the in-game Battle Pay store + (when logged
 * in) the user's Battle Coins balance. NO web purchase — buying happens
 * in-game. The Ko-fi donate flow is Track B2; here it's only a placeholder
 * gated by the independent `features.donations` flag.
 *
 * Public (no login required to browse). Gated by `features.shop`; degrades
 * gracefully when the world DB / battle_pay tables are absent.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/shop.php';

[$ok, $reason] = shop_public_availability($pdo_world ?? null, $config);
$donations_on  = shop_donations_enabled($config);

// Logged-in user's Battle Coins (account.dp) — same source as the dashboard.
$balance = null;
if (isset($_SESSION['user_id'])) {
    try {
        $st = $pdo_auth->prepare("SELECT dp FROM account WHERE id = :id");
        $st->execute(['id' => (int)$_SESSION['user_id']]);
        $v = $st->fetchColumn();
        if ($v !== false) $balance = (int)$v;
    } catch (PDOException $e) {
        error_log('shop balance lookup: ' . $e->getMessage());
    }
}

$page_title = ($TEXT['shop_nav'] ?? 'Shop') . ' — ' . ($config['site']['title'] ?? 'WoW');
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.shp-wrap { padding-top:120px; padding-bottom:3rem; }
.shp-hero {
    background: linear-gradient(135deg, rgba(139,69,19,.25) 0%, rgba(10,10,20,.85) 60%);
    border: 1px solid rgba(139,69,19,.4);
    border-radius: 12px; padding: 1.6rem 1.8rem; margin-bottom: 1.5rem;
    display:flex; align-items:center; justify-content:space-between; gap:1.5rem; flex-wrap:wrap;
}
.shp-hero h1 { color:#c8a96e; margin:0; font-weight:700; letter-spacing:1px; font-size:1.7rem; }
.shp-hero p  { color:#8899aa; margin:.35rem 0 0; font-size:.92rem; }
.shp-bal {
    text-align:right; flex-shrink:0;
}
.shp-bal .lbl { font-size:.72rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; }
.shp-bal .val { color:#69ccf0; font-weight:700; font-size:1.8rem; line-height:1.1; }
.shp-donate {
    background: linear-gradient(145deg,#1f1b10,#241608); border:1px solid rgba(240,192,64,.4);
    border-radius:10px; padding:1.1rem 1.4rem; margin-bottom:1.5rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}
.shp-donate .t { color:#f0c040; font-weight:700; }
.shp-donate .s { color:#8899aa; font-size:.85rem; margin-top:.15rem; }
.shp-btn {
    padding:.55rem 1.2rem; border-radius:6px; border:1px solid; font-size:.9rem;
    text-decoration:none; display:inline-block; font-family:inherit;
}
.shp-btn-kofi { background:#8B4513; color:#fff; border-color:#A0522D; }
.shp-btn-kofi:hover { background:#A0522D; color:#fff; }
.shp-btn-kofi.disabled { opacity:.55; pointer-events:none; }
.shp-cat { margin-bottom:2rem; }
.shp-cat-h {
    color:#c8a96e; font-weight:700; font-size:1.15rem; letter-spacing:.5px;
    display:flex; align-items:center; gap:.6rem; margin:0 0 1rem;
    padding-bottom:.5rem; border-bottom:1px solid rgba(139,69,19,.25);
}
.shp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:1rem; }
.shp-tile {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border:1px solid rgba(139,69,19,.3); border-radius:10px; padding:1.1rem 1.2rem;
    transition:border-color .15s ease, transform .15s ease;
}
.shp-tile:hover { border-color:rgba(200,169,110,.55); transform:translateY(-2px); }
.shp-tile .tt { color:#c8a96e; font-weight:700; font-size:1rem; margin-bottom:.4rem; }
.shp-tile .it { color:#dee2e6; font-size:.86rem; margin:.15rem 0; }
.shp-tile .it a { color:#69ccf0; text-decoration:none; }
.shp-tile .desc { color:#8899aa; font-size:.82rem; margin:.5rem 0 .7rem; line-height:1.5; }
.shp-tile .price {
    color:#69ccf0; font-weight:700; font-size:1.05rem;
    display:flex; align-items:center; gap:.35rem; margin-top:.6rem;
    border-top:1px solid rgba(139,69,19,.18); padding-top:.6rem;
}
.shp-notice {
    background:rgba(240,192,64,.1); border:1px solid rgba(240,192,64,.3);
    color:#f0c040; padding:1rem 1.2rem; border-radius:8px;
}
.shp-empty { color:#4a5568; text-align:center; padding:2.5rem 1rem; }
</style>

<div class="container shp-wrap">

    <?php if (!$ok): ?>
        <div class="shp-hero"><h1><i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_nav'] ?? 'Shop') ?></h1></div>
        <div class="shp-notice">
            <i class="bi bi-info-circle me-2"></i>
            <?php if ($reason === 'disabled'): ?>
                <?= htmlspecialchars($TEXT['shop_pub_disabled'] ?? 'The shop is currently unavailable.') ?>
            <?php else: ?>
                <?= htmlspecialchars($TEXT['shop_pub_unavailable'] ?? 'The shop is temporarily unavailable. Please check back later.') ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php
        $shop = shop_get_full($pdo_world);
        // Public catalog: only categories that actually have items.
        $shop = array_values(array_filter($shop, fn($c) => !empty($c['tiles'])));
        ?>

        <div class="shp-hero">
            <div>
                <h1><i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_pub_title'] ?? 'Battle Coins Shop') ?></h1>
                <p><?= htmlspecialchars($TEXT['shop_pub_sub'] ?? 'Everything below is purchasable in-game with Battle Coins.') ?></p>
            </div>
            <?php if ($balance !== null): ?>
                <div class="shp-bal">
                    <div class="lbl"><?= htmlspecialchars($TEXT['shop_your_balance'] ?? 'Your Battle Coins') ?></div>
                    <div class="val"><i class="bi bi-gem"></i> <?= number_format($balance) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($donations_on): ?>
            <div class="shp-donate">
                <div>
                    <div class="t"><i class="bi bi-heart-fill me-1"></i><?= htmlspecialchars($TEXT['shop_donate_title'] ?? 'Support the server — get Battle Coins') ?></div>
                    <div class="s"><?= htmlspecialchars($TEXT['shop_donate_soon'] ?? 'Donation checkout is being set up — available very soon.') ?></div>
                </div>
                <span class="shp-btn shp-btn-kofi disabled"><i class="bi bi-cup-hot me-1"></i><?= htmlspecialchars($TEXT['shop_donate_btn'] ?? 'Donate') ?></span>
            </div>
        <?php endif; ?>

        <?php if (empty($shop)): ?>
            <div class="shp-empty">
                <i class="bi bi-bag-x" style="font-size:2.5rem;display:block;margin-bottom:.6rem;opacity:.4"></i>
                <?= htmlspecialchars($TEXT['shop_pub_empty'] ?? 'The shop has no items yet. Check back soon!') ?>
            </div>
        <?php else: ?>
            <?php foreach ($shop as $cat): ?>
                <section class="shp-cat">
                    <h2 class="shp-cat-h"><i class="bi bi-collection"></i><?= htmlspecialchars($cat['name']) ?></h2>
                    <div class="shp-grid">
                        <?php foreach ($cat['tiles'] as $t): ?>
                            <div class="shp-tile">
                                <div class="tt"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></div>
                                <?php foreach ($t['items'] as $it): ?>
                                    <?php if ($it['item_name'] !== null): ?>
                                        <div class="it">
                                            <i class="bi bi-box-seam me-1"></i><a href="<?= htmlspecialchars(shop_wowhead_item_url((int)$it['itemId'])) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($it['item_name']) ?></a><?= (int)$it['count'] > 1 ? ' ×' . (int)$it['count'] : '' ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!empty($t['entry_desc'] ?? '')): /* description not always present in shop_get_full */ ?>
                                <?php endif; ?>
                                <div class="price">
                                    <i class="bi bi-gem"></i>
                                    <?= $t['price'] !== null ? number_format((int)$t['price']) : '—' ?>
                                    <span style="color:#8899aa;font-size:.78rem;font-weight:400"><?= htmlspecialchars($TEXT['shop_coins'] ?? 'Battle Coins') ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($ok): ?>
<!-- Wowhead: mop-classic item links → colored, icon'd, hover-tooltip names -->
<script>
const whTooltips = { colorLinks: true, iconizeLinks: true, renameLinks: true };
</script>
<script src="https://wow.zamimg.com/widgets/power.js"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
