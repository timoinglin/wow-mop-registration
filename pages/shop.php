<?php
/**
 * Public / user-facing shop catalog.
 *
 * Phase B1: read-only browse of the in-game Battle Pay store, styled like the
 * in-game shop (left category rail + item grid + real item icons via
 * Wowhead), + (when logged in) the user's Battle Coins balance. NO web
 * purchase — buying happens in-game. Ko-fi donate flow is Track B2; here it
 * is only a placeholder gated by the independent `features.donations` flag.
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
.shp-wrap { padding-top:110px; padding-bottom:3rem; }

.shp-topbar {
    display:flex; align-items:center; justify-content:space-between; gap:1.2rem;
    flex-wrap:wrap; margin-bottom:1.25rem;
}
.shp-topbar h1 { color:#c8a96e; margin:0; font-weight:700; letter-spacing:1px; font-size:1.55rem; }
.shp-topbar .sub { color:#8899aa; font-size:.88rem; margin-top:.2rem; }
.shp-bal { text-align:right; }
.shp-bal .lbl { font-size:.68rem; color:#8899aa; text-transform:uppercase; letter-spacing:.6px; }
.shp-bal .val { color:#69ccf0; font-weight:700; font-size:1.55rem; line-height:1.1; }

.shp-donate {
    background: linear-gradient(145deg,#1f1b10,#241608); border:1px solid rgba(240,192,64,.4);
    border-radius:10px; padding:.95rem 1.3rem; margin-bottom:1.25rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}
.shp-donate .t { color:#f0c040; font-weight:700; }
.shp-donate .s { color:#8899aa; font-size:.84rem; margin-top:.15rem; }
.shp-btn { padding:.5rem 1.15rem; border-radius:6px; border:1px solid; font-size:.88rem; text-decoration:none; display:inline-block; font-family:inherit; }
.shp-btn-kofi { background:#8B4513; color:#fff; border-color:#A0522D; }
.shp-btn-kofi.disabled { opacity:.55; pointer-events:none; }

/* ── In-game-shop-style layout: left category rail + content panel ── */
.shp-shell {
    display:flex; gap:1rem; align-items:flex-start;
    background: linear-gradient(160deg, rgba(20,17,11,.92), rgba(10,10,15,.96));
    border:1px solid rgba(139,69,19,.45);
    border-radius:14px; padding:1rem; box-shadow:0 8px 40px rgba(0,0,0,.4);
}
.shp-rail {
    width:230px; flex-shrink:0; display:flex; flex-direction:column; gap:.35rem;
    border-right:1px solid rgba(139,69,19,.3); padding-right:1rem;
}
.shp-rail-btn {
    display:flex; align-items:center; gap:.7rem; width:100%;
    background:linear-gradient(145deg,#1a1a26,#12121b);
    border:1px solid rgba(139,69,19,.3); border-radius:8px;
    color:#c8a96e; padding:.7rem .9rem; cursor:pointer; font-family:inherit;
    font-size:.92rem; font-weight:600; text-align:left; transition:all .14s ease;
}
.shp-rail-btn:hover { border-color:rgba(200,169,110,.6); color:#fff; }
.shp-rail-btn.active {
    background:linear-gradient(145deg,#3a2410,#241608);
    border-color:rgba(240,192,64,.6); color:#f0c040;
    box-shadow:inset 3px 0 0 #f0c040;
}
.shp-rail-btn .ri {
    width:30px; height:30px; flex-shrink:0; border-radius:50%;
    background:linear-gradient(145deg,#2a1f10,#1a1206);
    border:1px solid rgba(139,69,19,.4);
    display:flex; align-items:center; justify-content:center; color:#c8a96e; font-size:.95rem;
}
.shp-rail-btn .rc { margin-left:auto; font-size:.72rem; color:#6c7a8c; font-weight:400; }
.shp-rail-note { color:#4a5568; font-size:.72rem; text-align:center; margin-top:.6rem; font-style:italic; }

.shp-main { flex:1; min-width:0; }
.shp-pane { display:none; }
.shp-pane.active { display:block; animation:shpfade .18s ease; }
@keyframes shpfade { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }
.shp-pane-h { color:#c8a96e; font-weight:700; font-size:1.15rem; margin:.2rem 0 1rem; padding:0 .25rem; }

.shp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:.9rem; }
.shp-tile {
    background:linear-gradient(160deg,#17131f,#0d0d15);
    border:1px solid rgba(139,69,19,.35); border-radius:10px;
    padding:1rem .9rem; text-align:center; transition:border-color .14s ease, transform .14s ease;
}
.shp-tile:hover { border-color:rgba(200,169,110,.6); transform:translateY(-2px); }
.shp-ico {
    width:64px; height:64px; margin:0 auto .7rem; border-radius:10px;
    border:1px solid rgba(200,169,110,.35);
    background:
        radial-gradient(circle at 35% 30%, rgba(200,169,110,.18), transparent 60%),
        linear-gradient(135deg,#1a1f2e,#0a0d18);
    background-size:cover; background-position:center;
    display:flex; align-items:center; justify-content:center;
}
.shp-ico.icon-loaded { border-color:rgba(200,169,110,.6); }
.shp-ico .qm { color:rgba(200,169,110,.5); font-size:1.9rem; }
.shp-ico.icon-loaded .qm { display:none; }
.shp-tile .tt {
    color:#c8a96e; font-weight:700; font-size:.92rem; line-height:1.3; margin-bottom:.35rem;
    min-height:2.4em; display:flex; align-items:center; justify-content:center;
}
.shp-tile .gi { font-size:.78rem; color:#8899aa; margin-bottom:.55rem; min-height:1.1em; }
.shp-tile .gi a { color:#69ccf0; text-decoration:none; }
.shp-tile .pr {
    color:#f0c040; font-weight:700; font-size:1rem;
    border-top:1px solid rgba(139,69,19,.2); padding-top:.55rem; margin-top:.3rem;
    display:flex; align-items:center; justify-content:center; gap:.3rem;
}
.shp-tile .pr small { color:#8899aa; font-weight:400; font-size:.7rem; }

.shp-notice { background:rgba(240,192,64,.1); border:1px solid rgba(240,192,64,.3); color:#f0c040; padding:1rem 1.2rem; border-radius:8px; }
.shp-empty { color:#4a5568; text-align:center; padding:2.5rem 1rem; }

@media (max-width: 820px) {
    .shp-shell { flex-direction:column; }
    .shp-rail {
        width:100%; flex-direction:row; overflow-x:auto; gap:.4rem;
        border-right:none; border-bottom:1px solid rgba(139,69,19,.3);
        padding-right:0; padding-bottom:.8rem;
    }
    .shp-rail-btn { width:auto; white-space:nowrap; }
    .shp-rail-btn .rc, .shp-rail-note { display:none; }
}
</style>

<div class="container shp-wrap">

    <?php if (!$ok): ?>
        <div class="shp-topbar"><div><h1><i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_nav'] ?? 'Shop') ?></h1></div></div>
        <div class="shp-notice">
            <i class="bi bi-info-circle me-2"></i>
            <?= htmlspecialchars($reason === 'disabled'
                ? ($TEXT['shop_pub_disabled'] ?? 'The shop is currently unavailable.')
                : ($TEXT['shop_pub_unavailable'] ?? 'The shop is temporarily unavailable. Please check back later.')) ?>
        </div>
    <?php else: ?>
        <?php
        $shop = shop_get_full($pdo_world);
        // Public catalog: only non-empty categories, and never the in-game
        // "Balance" pseudo-page (it shows the player's coin balance in-game —
        // not a purchasable, so it has no place in a browse catalog).
        $shop = array_values(array_filter(
            $shop,
            fn($c) => !empty($c['tiles']) && strcasecmp(trim($c['name']), 'Balance') !== 0
        ));
        ?>

        <div class="shp-topbar">
            <div>
                <h1><i class="bi bi-shop me-2"></i><?= htmlspecialchars($TEXT['shop_pub_title'] ?? 'Battle Coins Shop') ?></h1>
                <div class="sub"><?= htmlspecialchars($TEXT['shop_pub_sub'] ?? 'Everything below is purchasable in-game with Battle Coins.') ?></div>
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
            <div class="shp-shell">
                <div class="shp-rail" role="tablist">
                    <?php foreach ($shop as $i => $cat): ?>
                        <button type="button" class="shp-rail-btn<?= $i === 0 ? ' active' : '' ?>" data-cat="<?= (int)$cat['id'] ?>" role="tab">
                            <span class="ri"><i class="bi bi-bag-fill"></i></span>
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                            <span class="rc"><?= count($cat['tiles']) ?></span>
                        </button>
                    <?php endforeach; ?>
                    <div class="shp-rail-note">*<?= htmlspecialchars($TEXT['shop_coins'] ?? 'Battle Coins') ?></div>
                </div>

                <div class="shp-main">
                    <?php foreach ($shop as $i => $cat): ?>
                        <div class="shp-pane<?= $i === 0 ? ' active' : '' ?>" id="shopcat-<?= (int)$cat['id'] ?>" data-loaded="0">
                            <div class="shp-pane-h"><?= htmlspecialchars($cat['name']) ?></div>
                            <div class="shp-grid">
                                <?php foreach ($cat['tiles'] as $t): ?>
                                    <?php
                                    $firstItem = $t['items'][0] ?? null;
                                    $iid       = $firstItem ? (int)$firstItem['itemId'] : 0;
                                    $iname     = $firstItem['item_name'] ?? null;
                                    ?>
                                    <div class="shp-tile">
                                        <div class="shp-ico" <?= $iid > 0 ? 'data-wh-icon-id="' . $iid . '"' : '' ?>><span class="qm"><i class="bi bi-gift-fill"></i></span></div>
                                        <div class="tt"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></div>
                                        <div class="gi">
                                            <?php if ($iname !== null): ?>
                                                <a href="<?= htmlspecialchars(shop_wowhead_item_url($iid)) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($iname) ?></a><?= (int)$firstItem['count'] > 1 ? ' ×' . (int)$firstItem['count'] : '' ?><?= count($t['items']) > 1 ? ' +' . (count($t['items']) - 1) : '' ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="pr"><i class="bi bi-gem"></i><?= $t['price'] !== null ? number_format((int)$t['price']) : '—' ?><small><?= htmlspecialchars($TEXT['shop_coins'] ?? 'Battle Coins') ?></small></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($ok): ?>
<script>
const whTooltips = { colorLinks: true, iconizeLinks: true, renameLinks: true };
</script>
<script src="https://wow.zamimg.com/widgets/power.js"></script>
<script>
(function () {
    // Lazy icon resolution per category (avoid hammering Wowhead with every
    // tile on load) — resolve a pane's icons the first time it's shown.
    function setIcon(el, iconName) {
        if (!iconName) return;
        el.style.backgroundImage = 'url(https://wow.zamimg.com/images/wow/icons/large/' + iconName.toLowerCase() + '.jpg)';
        el.classList.add('icon-loaded');
    }
    function resolvePane(pane) {
        if (!pane || pane.getAttribute('data-loaded') === '1') return;
        pane.setAttribute('data-loaded', '1');
        pane.querySelectorAll('.shp-ico[data-wh-icon-id]').forEach(function (el) {
            var id = el.getAttribute('data-wh-icon-id');
            if (!id) return;
            fetch('https://nether.wowhead.com/tooltip/item/' + id + '?dataEnv=12&locale=0')
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function (d) { return d && d.icon ? setIcon(el, d.icon) : Promise.reject(); })
                .catch(function () {
                    fetch('https://nether.wowhead.com/tooltip/item/' + id)
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (d) { if (d && d.icon) setIcon(el, d.icon); })
                        .catch(function () {});
                });
        });
    }

    var rail  = document.querySelectorAll('.shp-rail-btn');
    var panes = document.querySelectorAll('.shp-pane');
    rail.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-cat');
            rail.forEach(function (b) { b.classList.toggle('active', b === btn); });
            panes.forEach(function (p) { p.classList.toggle('active', p.id === 'shopcat-' + id); });
            resolvePane(document.getElementById('shopcat-' + id));
        });
    });
    // Resolve the initially-visible pane
    resolvePane(document.querySelector('.shp-pane.active'));
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
