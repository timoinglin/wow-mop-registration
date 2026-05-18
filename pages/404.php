<?php
/**
 * Custom 404 — "You died."
 *
 * Served via Apache ErrorDocument when a route cannot be resolved.
 * Includes a randomly-appearing murloc easter egg (~50% of page loads).
 */

http_response_code(404);

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';

$page_title     = ($TEXT['err_404_title'] ?? 'You died.') . ' — 404';
$og_title       = $page_title;
$og_description = $TEXT['err_404_subtitle'] ?? 'The page you sought has crossed into the Shadowlands.';

require_once __DIR__ . '/../templates/header.php';
?>

<style>
.dead-wrap {
    min-height: calc(100vh - 240px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6rem 1rem 4rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.dead-glow {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(ellipse at 50% 35%, rgba(93,216,124,.10) 0%, transparent 50%),
        radial-gradient(ellipse at 50% 65%, rgba(105,204,240,.06) 0%, transparent 60%);
}

.dead-img {
    width: clamp(260px, 45vw, 520px);
    margin-bottom: 1.5rem;
    filter: drop-shadow(0 0 60px rgba(105,204,240,.35));
    animation: float 6s ease-in-out infinite;
    user-select: none;
}
@keyframes float {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-14px); }
}

.dead-title {
    font-size: clamp(2.6rem, 8vw, 5rem);
    font-weight: 800;
    letter-spacing: 6px;
    text-transform: uppercase;
    margin: 0 0 1rem;
    background: linear-gradient(180deg, #cfe9f6 0%, #69ccf0 50%, #5dd87c 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    text-shadow: 0 0 60px rgba(93,216,124,.25);
    animation: spectral 4s ease-in-out infinite;
}
@keyframes spectral {
    0%, 100% { filter: brightness(1); }
    50%      { filter: brightness(1.2) drop-shadow(0 0 22px rgba(105,204,240,.5)); }
}

.dead-code {
    font-size: .8rem;
    letter-spacing: 4px;
    color: rgba(var(--accent-rgb), .4);
    text-transform: uppercase;
    margin-bottom: .5rem;
    font-weight: 600;
}

.dead-subtitle {
    color: rgba(200,220,240,.75);
    font-size: 1.05rem;
    margin: 0 auto 2rem;
    max-width: 540px;
    line-height: 1.6;
}

.dead-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
.dead-btn {
    padding: .9rem 1.8rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: .92rem;
    letter-spacing: 1.5px;
    text-decoration: none;
    text-transform: uppercase;
    transition: all .25s ease;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    border: 1px solid;
}
.dead-btn-primary {
    background: linear-gradient(135deg, #2c5e7a, #1a4d68);
    border-color: #5b9cb8;
    color: #cfe9f6;
}
.dead-btn-primary:hover {
    background: linear-gradient(135deg, #5b9cb8, #69ccf0);
    border-color: #69ccf0;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(105,204,240,.35);
}
.dead-btn-secondary {
    background: rgba(255,255,255,.04);
    border-color: rgba(var(--accent-rgb), .3);
    color: var(--accent);
}
.dead-btn-secondary:hover {
    background: rgba(var(--accent-rgb), .12);
    border-color: rgba(var(--accent-rgb), .6);
    color: #e8c87e;
    transform: translateY(-2px);
}

.dead-rez-hint {
    margin-top: 2rem;
    color: rgba(255,255,255,.4);
    font-size: .82rem;
    font-style: italic;
}

/* ── Murloc easter egg ────────────────────────────────────────── */
.murloc {
    position: fixed;
    bottom: 80px;
    left: -240px;
    z-index: 9999;
    pointer-events: none;
    white-space: nowrap;
    color: var(--accent);
    font-weight: 700;
    font-size: 1rem;
    text-shadow: 0 0 12px rgba(0,0,0,.7), 0 2px 4px rgba(0,0,0,.5);
    animation: murloc-walk 14s linear forwards;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.murloc-fish {
    font-size: 2.4rem;
    animation: murloc-bounce .35s ease-in-out infinite alternate;
    transform-origin: center;
}
.murloc-bubble {
    background: rgba(0,0,0,.6);
    border: 1px solid rgba(var(--accent-rgb), .3);
    border-radius: 12px;
    padding: .25rem .75rem;
    font-size: .85rem;
    letter-spacing: .5px;
}

@keyframes murloc-walk {
    0%   { left: -240px; opacity: 0; }
    8%   { opacity: 1; }
    92%  { opacity: 1; }
    100% { left: calc(100% + 60px); opacity: 0; }
}
@keyframes murloc-bounce {
    from { transform: translateY(0)    rotate(-8deg); }
    to   { transform: translateY(-8px) rotate(8deg); }
}

@media (max-width: 768px) {
    .dead-title { letter-spacing: 4px; }
    .murloc { bottom: 60px; }
    .murloc-fish { font-size: 1.8rem; }
}
</style>

<div class="dead-glow"></div>
<div class="dead-wrap">
    <div>
        <img src="/assets/img/404-spirit-healer.png"
             alt="<?= htmlspecialchars($TEXT['err_404_alt'] ?? 'Spirit Healer') ?>"
             class="dead-img">

        <div class="dead-code">Error 404 · Lost Spirit</div>
        <h1 class="dead-title"><?= htmlspecialchars($TEXT['err_404_title'] ?? 'You died.') ?></h1>
        <p class="dead-subtitle"><?= htmlspecialchars($TEXT['err_404_subtitle'] ?? 'The page you sought has crossed into the Shadowlands.') ?></p>

        <div class="dead-btns">
            <a class="dead-btn dead-btn-primary" href="/">
                <i class="bi bi-arrow-up-circle-fill"></i>
                <?= htmlspecialchars($TEXT['err_404_release_spirit'] ?? 'Release Spirit') ?>
            </a>
            <a class="dead-btn dead-btn-secondary" href="javascript:history.length>1?history.back():(location.href='/')">
                <i class="bi bi-arrow-left-circle"></i>
                <?= htmlspecialchars($TEXT['err_404_run_back'] ?? 'Run Back to Corpse') ?>
            </a>
        </div>

        <p class="dead-rez-hint">⚱️ <?= htmlspecialchars($TEXT['err_404_rez_sickness'] ?? 'Resurrection sickness: 0 minutes. Lucky you.') ?></p>
    </div>
</div>

<script>
// ── Murloc easter egg — fires ~50% of the time after a random delay ──
(function(){
    if (Math.random() >= 0.5) return;
    const delay = 5000 + Math.random() * 15000; // 5-20 sec
    setTimeout(() => {
        const m = document.createElement('div');
        m.className = 'murloc';
        m.innerHTML = '<span class="murloc-fish">🐟</span><span class="murloc-bubble"><?= htmlspecialchars($TEXT['err_404_murloc'] ?? 'Mrglglglglgl!', ENT_QUOTES) ?></span>';
        document.body.appendChild(m);
        m.addEventListener('animationend', () => m.remove());
    }, delay);
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
