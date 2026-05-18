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
require_once __DIR__ . '/../includes/donation.php';

[$ok, $reason] = shop_public_availability($pdo_world ?? null, $config);

// Donations are fully independent of the catalog: they only touch the auth DB,
// so the donate panel renders even when features.shop is off or the world DB
// is down. Gated solely by features.donations + a configured Ko-fi token.
$donate_ready = donation_enabled($config);
$don_cfg      = $donate_ready ? donation_config($config) : null;
// Effective rate = admin's /admin_shop override, else config default.
$don_rate     = $donate_ready ? donation_rate($pdo_auth, $config) : 0;

$uid        = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$balance    = null;
$don_code   = null;
$don_recent = [];
if ($uid > 0) {
    try {
        $st = $pdo_auth->prepare("SELECT dp FROM account WHERE id = :id");
        $st->execute(['id' => $uid]);
        $v = $st->fetchColumn();
        if ($v !== false) $balance = (int)$v;
    } catch (PDOException $e) {
        error_log('shop balance lookup: ' . $e->getMessage());
    }
    if ($donate_ready) {
        $don_code   = donation_get_code($pdo_auth, $uid);
        $don_recent = donation_recent_for_account($pdo_auth, $uid, 3);
    }
}

// Currency symbol for the rate hint (display only — no conversion happens).
$cur_sym = '';
if ($don_cfg) {
    $cur = strtoupper($don_cfg['currency']);
    $cur_sym = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$'][$cur]
               ?? ($cur . ' ');
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
.shp-topbar h1 { color:var(--accent); margin:0; font-weight:700; letter-spacing:1px; font-size:1.55rem; }
.shp-topbar .sub { color:#8899aa; font-size:.88rem; margin-top:.2rem; }
.shp-bal { text-align:right; }
.shp-bal .lbl { font-size:.68rem; color:#8899aa; text-transform:uppercase; letter-spacing:.6px; }
.shp-bal .val { color:#69ccf0; font-weight:700; font-size:1.55rem; line-height:1.1; }

.shp-donate {
    background: linear-gradient(145deg,#1f1b10,#241608); border:1px solid rgba(240,192,64,.4);
    border-radius:10px 10px 0 0; padding:.95rem 1.3rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
}
.shp-donate.solo { border-radius:10px; margin-bottom:1.25rem; }
.shp-donate .t { color:#f0c040; font-weight:700; }
.shp-donate .s { color:#8899aa; font-size:.84rem; margin-top:.15rem; }
.shp-donate .s b { color:#f0c040; }
.shp-btn { padding:.55rem 1.2rem; border-radius:6px; border:1px solid; font-size:.9rem; text-decoration:none; display:inline-block; font-family:inherit; transition:background .14s ease; }
.shp-btn-kofi { background:var(--btn-bg); color:#fff; border-color:var(--btn-bg-hover); }
.shp-btn-kofi:hover { background:var(--btn-bg-hover); color:#fff; }

/* Attribution-code box (sits directly under the donate bar) */
.shp-code-box {
    background: rgba(0,0,0,.30); border:1px solid rgba(240,192,64,.3); border-top:none;
    border-radius:0 0 10px 10px; padding:1rem 1.3rem; margin-bottom:1.25rem;
}
.shp-code-box.muted {
    color:#9aa7b4; font-size:.9rem; display:flex; align-items:center; gap:.5rem;
}
.shp-code-box.muted a { color:#f0c040; text-decoration:none; font-weight:600; }
.shp-code-cap { color:var(--accent); font-size:.85rem; font-weight:600; margin-bottom:.55rem; }
.shp-code-row { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-bottom:.9rem; }
.shp-code {
    font-family:'Courier New',monospace; font-size:1.45rem; font-weight:700; letter-spacing:2px;
    color:#f0c040; background:linear-gradient(145deg,#241608,#15100a);
    border:1px dashed rgba(240,192,64,.55); border-radius:7px; padding:.4rem 1rem;
}
.shp-copy {
    background:var(--btn-bg); color:#fff; border:1px solid var(--btn-bg-hover); border-radius:6px;
    padding:.45rem .9rem; font-size:.82rem; cursor:pointer; font-family:inherit; transition:background .14s ease;
}
.shp-copy:hover { background:var(--btn-bg-hover); }
.shp-copy.ok { background:#2e7d32; border-color:#388e3c; }
.shp-warn {
    display:flex; align-items:flex-start; gap:.6rem;
    background:rgba(231,76,60,.12); border:1px solid rgba(231,76,60,.55);
    border-left:4px solid #e74c3c; border-radius:8px;
    padding:.7rem .9rem; margin:0 0 .95rem; color:#ffd9d4; font-size:.86rem; line-height:1.5;
}
.shp-warn i { color:#ff6b5b; font-size:1.05rem; margin-top:.05rem; flex-shrink:0; }
.shp-warn b { color:#fff; }
.shp-warn code {
    color:#ffe08a; background:rgba(0,0,0,.35); padding:.03rem .4rem; border-radius:4px;
    font-family:'Courier New',monospace; font-weight:700;
}
.shp-tipnote {
    display:flex; align-items:flex-start; gap:.55rem;
    background:rgba(255,255,255,.03); border:1px solid rgba(var(--btn-bg-rgb), .35);
    border-left:4px solid var(--accent); border-radius:8px;
    padding:.65rem .9rem; margin:0 0 1.25rem; color:#9aa7b4; font-size:.8rem; line-height:1.55;
}
.shp-tipnote i { color:var(--accent); font-size:1rem; margin-top:.05rem; flex-shrink:0; }
.shp-tipnote b { color:var(--accent); }
.shp-steps { margin:0; padding-left:1.2rem; color:#9aa7b4; font-size:.85rem; line-height:1.75; }
.shp-steps code, .shp-code-cap code {
    color:#f0c040; background:rgba(240,192,64,.12); padding:.03rem .35rem; border-radius:4px;
    font-family:'Courier New',monospace;
}
.shp-steps li.key { color:#ffd9d4; font-weight:600; }
.shp-recent {
    margin-top:.85rem; padding-top:.7rem; border-top:1px solid rgba(var(--btn-bg-rgb), .3);
    font-size:.8rem; color:#8899aa;
}
.shp-recent .ti { color:var(--accent); font-weight:600; margin-bottom:.3rem; }
.shp-recent .rr { display:flex; justify-content:space-between; gap:1rem; padding:.12rem 0; }
.shp-recent .rr b { color:#69ccf0; font-weight:700; }

/* ── In-game-shop-style layout: left category rail + content panel ── */
.shp-shell {
    display:flex; gap:1rem; align-items:flex-start;
    background: linear-gradient(160deg, rgba(20,17,11,.92), rgba(10,10,15,.96));
    border:1px solid rgba(var(--btn-bg-rgb), .45);
    border-radius:14px; padding:1rem; box-shadow:0 8px 40px rgba(0,0,0,.4);
}
.shp-rail {
    width:230px; flex-shrink:0; display:flex; flex-direction:column; gap:.35rem;
    border-right:1px solid rgba(var(--btn-bg-rgb), .3); padding-right:1rem;
}
.shp-rail-btn {
    display:flex; align-items:center; gap:.7rem; width:100%;
    background:linear-gradient(145deg,#1a1a26,#12121b);
    border:1px solid rgba(var(--btn-bg-rgb), .3); border-radius:8px;
    color:var(--accent); padding:.7rem .9rem; cursor:pointer; font-family:inherit;
    font-size:.92rem; font-weight:600; text-align:left; transition:all .14s ease;
}
.shp-rail-btn:hover { border-color:rgba(var(--accent-rgb), .6); color:#fff; }
.shp-rail-btn.active {
    background:linear-gradient(145deg,#3a2410,#241608);
    border-color:rgba(240,192,64,.6); color:#f0c040;
    box-shadow:inset 3px 0 0 #f0c040;
}
.shp-rail-btn .ri {
    width:30px; height:30px; flex-shrink:0; border-radius:50%;
    background:linear-gradient(145deg,#2a1f10,#1a1206);
    border:1px solid rgba(var(--btn-bg-rgb), .4);
    display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:.95rem;
}
.shp-rail-btn .rc { margin-left:auto; font-size:.72rem; color:#6c7a8c; font-weight:400; }
.shp-rail-note { color:#4a5568; font-size:.72rem; text-align:center; margin-top:.6rem; font-style:italic; }

.shp-main { flex:1; min-width:0; }
.shp-pane { display:none; }
.shp-pane.active { display:block; animation:shpfade .18s ease; }
@keyframes shpfade { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }
.shp-pane-h { color:var(--accent); font-weight:700; font-size:1.15rem; margin:.2rem 0 1rem; padding:0 .25rem; }

.shp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:.9rem; }
.shp-tile {
    background:linear-gradient(160deg,#17131f,#0d0d15);
    border:1px solid rgba(var(--btn-bg-rgb), .35); border-radius:10px;
    padding:1rem .9rem; text-align:center; transition:border-color .14s ease, transform .14s ease;
}
.shp-tile:hover { border-color:rgba(var(--accent-rgb), .6); transform:translateY(-2px); }
.shp-ico {
    width:64px; height:64px; margin:0 auto .7rem; border-radius:10px;
    border:1px solid rgba(var(--accent-rgb), .35);
    background:
        radial-gradient(circle at 35% 30%, rgba(var(--accent-rgb), .18), transparent 60%),
        linear-gradient(135deg,#1a1f2e,#0a0d18);
    background-size:cover; background-position:center;
    display:flex; align-items:center; justify-content:center;
}
.shp-ico.icon-loaded { border-color:rgba(var(--accent-rgb), .6); }
.shp-ico .qm { color:rgba(var(--accent-rgb), .5); font-size:1.9rem; }
.shp-ico.icon-loaded .qm { display:none; }
/* Icon doubles as the item-preview target (Wowhead tooltip on hover). */
.shp-ico[data-wowhead] { cursor:help; }
.shp-tile:hover .shp-ico[data-wowhead] { box-shadow:0 0 0 2px rgba(105,204,240,.45); }
.shp-tile .tt {
    color:var(--accent); font-weight:700; font-size:.92rem; line-height:1.3; margin-bottom:.35rem;
    min-height:2.4em; display:flex; align-items:center; justify-content:center;
}
.shp-tile .gi { font-size:.78rem; color:#8899aa; margin-bottom:.55rem; min-height:1.1em; }
.shp-tile .gi a { color:#69ccf0; text-decoration:none; }
.shp-tile .gi a:hover { text-decoration:underline; }
.shp-tile .gi .sep { color:#4a5568; }
.shp-tile .gi .miss { color:#f87e8a; font-style:italic; }
.shp-tile .pr {
    color:#f0c040; font-weight:700; font-size:1rem;
    border-top:1px solid rgba(var(--btn-bg-rgb), .2); padding-top:.55rem; margin-top:.3rem;
    display:flex; align-items:center; justify-content:center; gap:.3rem;
}
.shp-tile .pr small { color:#8899aa; font-weight:400; font-size:.7rem; }

.shp-notice { background:rgba(240,192,64,.1); border:1px solid rgba(240,192,64,.3); color:#f0c040; padding:1rem 1.2rem; border-radius:8px; }
.shp-empty { color:#4a5568; text-align:center; padding:2.5rem 1rem; }

@media (max-width: 820px) {
    .shp-shell { flex-direction:column; }
    .shp-rail {
        width:100%; flex-direction:row; overflow-x:auto; gap:.4rem;
        border-right:none; border-bottom:1px solid rgba(var(--btn-bg-rgb), .3);
        padding-right:0; padding-bottom:.8rem;
    }
    .shp-rail-btn { width:auto; white-space:nowrap; }
    .shp-rail-btn .rc, .shp-rail-note { display:none; }
}
</style>

<div class="container shp-wrap">

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

    <?php if ($donate_ready): ?>
        <?php
        // Code box renders when logged-in (the user's code) OR logged-out
        // (a "log in" prompt). Only a bare donate bar (".solo") shows in the
        // rare logged-in-but-code-mint-failed case.
        $show_code_box = ($uid > 0 && $don_code !== null) || $uid === 0;
        // Phrased as a thank-you ratio, NOT "1€ = X" — a price equation
        // would read like a sale, contradicting the not-a-purchase note below.
        $rate_hint = sprintf(
            $TEXT['shop_donate_rate'] ?? '%1$s %2$s per 1%3$s',
            number_format($don_rate), $TEXT['shop_coins'] ?? 'Battle Coins', $cur_sym
        );
        ?>
        <div class="shp-donate<?= $show_code_box ? '' : ' solo' ?>">
            <div>
                <div class="t"><i class="bi bi-heart-fill me-1"></i><?= htmlspecialchars($TEXT['shop_donate_title'] ?? 'Support the server — get Battle Coins') ?></div>
                <div class="s"><?= htmlspecialchars($TEXT['shop_donate_desc'] ?? "Donate via Ko-fi to help keep the server running — as a thank-you you're credited") ?>
                    <b><?= htmlspecialchars($rate_hint) ?></b>.</div>
            </div>
            <a href="<?= htmlspecialchars($don_cfg['kofi_url'] !== '' ? $don_cfg['kofi_url'] : '#') ?>" target="_blank" rel="noopener noreferrer" class="shp-btn shp-btn-kofi">
                <i class="bi bi-cup-hot me-1"></i><?= htmlspecialchars($TEXT['shop_donate_btn'] ?? 'Donate on Ko-fi') ?>
            </a>
        </div>
        <div class="shp-tipnote">
            <i class="bi bi-info-circle-fill"></i>
            <span><b><?= htmlspecialchars($TEXT['shop_donate_disclaimer_lead'] ?? 'This is a voluntary donation, not a purchase.') ?></b> <?= htmlspecialchars($TEXT['shop_donate_disclaimer'] ?? "It helps cover the server's running costs. Battle Coins are a complimentary thank-you gift — not goods or a service for sale — and donations are non-refundable.") ?></span>
        </div>
        <?php if ($uid > 0 && $don_code !== null): ?>
            <div class="shp-code-box">
                <div class="shp-code-cap"><?= htmlspecialchars($TEXT['shop_donate_code_cap'] ?? "Your personal donation code — paste it into the Ko-fi message so we know it's you:") ?></div>
                <div class="shp-code-row">
                    <span class="shp-code" id="donCode"><?= htmlspecialchars($don_code) ?></span>
                    <button type="button" class="shp-copy" data-code="<?= htmlspecialchars($don_code) ?>"><i class="bi bi-clipboard me-1"></i><?= htmlspecialchars($TEXT['shop_donate_copy'] ?? 'Copy') ?></button>
                </div>
                <div class="shp-warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= str_replace('{code}', '<code>' . htmlspecialchars($don_code) . '</code>',
                              htmlspecialchars($TEXT['shop_donate_warn'] ?? 'IMPORTANT: you MUST paste your code {code} into the Ko-fi message field when donating. Without it the payment cannot be matched to your account and Battle Coins will NOT be credited automatically.')) ?></span>
                </div>
                <ol class="shp-steps">
                    <li><?= htmlspecialchars($TEXT['shop_donate_step1'] ?? 'Click "Donate on Ko-fi" above and choose any amount.') ?></li>
                    <li class="key"><?= str_replace('{code}', '<code>' . htmlspecialchars($don_code) . '</code>',
                              htmlspecialchars($TEXT['shop_donate_step2'] ?? 'Paste your code {code} into the Ko-fi message field — this is what links the payment to your account.')) ?></li>
                    <li><?= htmlspecialchars($TEXT['shop_donate_step3'] ?? 'Battle Coins are credited automatically, usually within a minute of payment.') ?></li>
                </ol>
                <?php if (!empty($don_recent)): ?>
                    <div class="shp-recent">
                        <div class="ti"><?= htmlspecialchars($TEXT['shop_donate_recent'] ?? 'Your recent donations') ?></div>
                        <?php foreach ($don_recent as $r): ?>
                            <?php $amt = rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.'); ?>
                            <div class="rr">
                                <span><?= htmlspecialchars(date('M j, Y', strtotime((string)$r['created_at']))) ?> · <?= htmlspecialchars($amt) ?> <?= htmlspecialchars((string)$r['currency']) ?></span>
                                <b>+<?= number_format((int)$r['dp_credited']) ?> <?= htmlspecialchars($TEXT['shop_coins'] ?? 'Battle Coins') ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($uid === 0): ?>
            <div class="shp-code-box muted">
                <i class="bi bi-info-circle"></i>
                <span><a href="/login"><?= htmlspecialchars($TEXT['login'] ?? 'Log in') ?></a> <?= htmlspecialchars($TEXT['shop_donate_login_hint'] ?? 'to get your personal donation code so your Battle Coins are credited automatically.') ?></span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$ok): ?>
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
                    <div class="shp-rail-note"><i class="bi bi-search me-1"></i><?= htmlspecialchars($TEXT['shop_pub_hover'] ?? 'Hover an item for its in-game tooltip') ?></div>
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
                                        <div class="shp-ico"<?php if ($iid > 0): ?> data-wh-icon-id="<?= $iid ?>" data-wowhead="item=<?= $iid ?>&amp;domain=mop-classic"<?php endif; ?>><span class="qm"><i class="bi bi-gift-fill"></i></span></div>
                                        <div class="tt"><?= htmlspecialchars($t['entry_title'] !== '' ? $t['entry_title'] : (string)($t['product_title'] ?? '—')) ?></div>
                                        <div class="gi">
                                            <?php
                                            // Every granted item is its own hoverable Wowhead link (bundles
                                            // included) — the honest "preview", same widget as the armory.
                                            $parts = [];
                                            foreach ($t['items'] as $git) {
                                                $gid = (int)$git['itemId'];
                                                if ($gid <= 0) continue;
                                                $gnm = $git['item_name'] ?? null;
                                                $qty = (int)$git['count'] > 1 ? ' ×' . (int)$git['count'] : '';
                                                if ($gnm !== null) {
                                                    $parts[] = '<a href="' . htmlspecialchars(shop_wowhead_item_url($gid))
                                                             . '" target="_blank" rel="noopener noreferrer">'
                                                             . htmlspecialchars($gnm) . '</a>' . htmlspecialchars($qty);
                                                } else {
                                                    $parts[] = '<span class="miss">#' . $gid . '</span>' . htmlspecialchars($qty);
                                                }
                                            }
                                            echo implode('<span class="sep">, </span>', $parts);
                                            ?>
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

<?php if ($donate_ready): ?>
<script>
// Copy the donation code to the clipboard (independent of the catalog).
(function () {
    var btn = document.querySelector('.shp-copy');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var code = btn.getAttribute('data-code') || '';
        var done = function () {
            var html = btn.innerHTML;
            btn.classList.add('ok');
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i><?= htmlspecialchars($TEXT['shop_donate_copied'] ?? 'Copied!', ENT_QUOTES) ?>';
            setTimeout(function () { btn.classList.remove('ok'); btn.innerHTML = html; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = code; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta); done();
        }
    });
})();
</script>
<?php endif; ?>

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
