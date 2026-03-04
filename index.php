<?php
require_once __DIR__ . '/templates/header.php';
require_once __DIR__ . '/includes/db.php'; // Provides $pdo_auth, $pdo_chars
require_once __DIR__ . '/includes/functions.php'; // Includes check_port_status
$config = require __DIR__ . '/config.php';

// --- Dynamic Data Fetching ---

// Realm status & player counts (guarded to avoid errors when a realm is offline)

// Shared auth server (check once)
$auth_server_status_r1 = check_port_status($config['db']['host'], $config['realm']['auth_port']);

// Realm 1
$world_server_status_r1 = check_port_status($config['db']['host'], $config['realm']['world_port']);
$player_count_r1 = $world_server_status_r1 ? '0' : 'Offline';
if ($world_server_status_r1) {
    if ($pdo_chars) {
        try {
            $stmt = $pdo_chars->query("SELECT COUNT(*) FROM characters WHERE online = 1");
            $player_count_r1 = $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Player Count R1 DB Error: " . $e->getMessage());
            $player_count_r1 = 'Error';
        }
    } else {
        error_log("Cannot fetch R1 player count: Characters DB connection failed.");
        $player_count_r1 = 'N/A';
    }
}

// Animated counter data
$total_accounts = 0;
$total_characters = 0;
try {
    if ($pdo_auth) $total_accounts = (int)$pdo_auth->query("SELECT COUNT(*) FROM account")->fetchColumn();
    if ($pdo_chars) $total_characters = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters")->fetchColumn();
} catch (PDOException $e) {
    error_log("Counter fetch error: " . $e->getMessage());
}

// Social links
$social = $config['social'] ?? [];
$discord_url   = !empty($social['discord'])   ? $social['discord']   : '';
$youtube_url   = !empty($social['youtube'])    ? $social['youtube']   : '';
$twitter_url   = !empty($social['twitter'])    ? $social['twitter']   : '';
$instagram_url = !empty($social['instagram'])  ? $social['instagram'] : '';

// News
$news_items = $config['news'] ?? [];

// FAQ
$faq_items = $config['faq'] ?? [];
?>

<!-- Background Video Container -->
<div class="video-container position-relative">
    <!-- Background Video -->
    <video playsinline="playsinline" autoplay="autoplay" muted="muted" loop="loop" id="bg-video" class="position-absolute w-100 h-100 object-fit-cover">
        <source src="/assets/bg-video-mop.mp4" type="video/mp4">
        <p class="visually-hidden">Your browser does not support HTML5 video.</p>
    </video>

    <!-- Video Header Content Section -->
    <div class="video-header">
        <div class="container">
            <div class="overlay-content text-center">
                <!-- Logo -->
                <div class="logo-container">
                    <img src="/assets/img/logo.webp" alt="<?= $TEXT['wow_logo_alt'] ?>" class="img-fluid">
                </div>

                <h1 class="hero-title mb-3"><?= $TEXT['welcome'] ?></h1>
                <p class="hero-lead mb-4"><?= $TEXT['index_lead'] ?></p>
                <hr class="my-4 mx-auto" style="max-width: 80px; border-color: var(--accent); border-width: 2px; opacity:1;">
                <p class="mb-5" style="color:rgba(255,255,255,.7)"><?= $TEXT['index_cta'] ?></p>

                <!-- CTA Buttons -->
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <a class="btn btn-gold btn-lg px-5" href="<?= isset($_SESSION['user_id']) ? '/dashboard' : '/register' ?>">
                        <i class="bi bi-person-plus-fill me-2"></i><?= $TEXT['register'] ?>
                    </a>
                    <a class="btn btn-outline-gold btn-lg px-5" href="<?= isset($_SESSION['user_id']) ? '/dashboard' : '/login' ?>">
                        <i class="bi bi-box-arrow-in-right me-2"></i><?= $TEXT['login'] ?>
                    </a>
                    <?php if ($discord_url): ?>
                    <!-- <a class="btn btn-discord btn-lg px-4" href="<?= htmlspecialchars($discord_url) ?>" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-discord me-2"></i>Join Discord
                    </a> -->
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Area (Starts After Full-Screen Header) -->
<main class="container content-section">

    <!-- ═══════════════ NEWS SECTION ═══════════════ -->
    <?php if (!empty($news_items)): ?>
    <section id="news" class="content-section py-5 my-4 rounded">
        <div class="container">
            <div class="section-title text-center mb-5">
                <h2><i class="bi bi-newspaper me-2"></i>Latest Updates</h2>
            </div>
            <div class="row justify-content-center g-4">
                <?php foreach ($news_items as $news): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="game-card h-100 news-card">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="news-icon-circle">
                                    <i class="bi <?= htmlspecialchars($news['icon'] ?? 'bi-megaphone') ?>"></i>
                                </div>
                                <div>
                                    <h5 style="color:#c8a96e;margin:0;font-weight:700;font-size:1rem"><?= htmlspecialchars($news['title']) ?></h5>
                                    <span style="color:#4a5568;font-size:.78rem"><?= htmlspecialchars($news['date']) ?></span>
                                </div>
                            </div>
                            <p style="color:rgba(255,255,255,.65);font-size:.92rem;margin:0;line-height:1.6"><?= htmlspecialchars($news['text']) ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- How to Connect Section -->
    <section id="how-to-connect" class="content-section py-5 my-4 rounded">
        <div class="container">
            <div class="section-title text-center mb-5">
                <h2><?= $TEXT['how_to_connect_title'] ?></h2>
            </div>

            <div class="row justify-content-center align-items-stretch g-4">
                <!-- Step 1: Create Account -->
                <div class="col-lg-3 col-md-6">
                    <div class="game-card h-100 text-center">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="step-icon"><i class="bi bi-person-plus-fill text-accent"></i></div>
                            <h4 class="text-accent mb-3"><?= $TEXT['step1_title'] ?></h4>
                            <p style="color:var(--text-muted)"><?= $TEXT['step1_desc'] ?></p>
                            <a href="<?= isset($_SESSION['user_id']) ? '/dashboard' : '/register' ?>" class="btn btn-gold mt-auto"><?= $TEXT['go_register'] ?></a>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Download Client -->
                <div class="col-lg-3 col-md-6">
                    <div class="game-card h-100 text-center">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="step-icon"><i class="bi bi-download" style="color:#2ecc71"></i></div>
                            <h4 class="text-accent mb-3"><?= $TEXT['step2_title'] ?></h4>
                            <p style="color:var(--text-muted)"><?= $TEXT['client_download_info'] ?></p>
                            <?php 
                            $dl_link = $config['client']['download_link'] ?? '#';
                            $dl_disabled = ($dl_link === '#' || strpos($dl_link, 'example.com') !== false || empty($dl_link)); 
                            ?>
                            <a href="<?= htmlspecialchars($dl_link) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-game-green mt-auto <?= $dl_disabled ? 'disabled' : '' ?>">
                                <i class="bi bi-cloud-arrow-down-fill me-1"></i> <?= $TEXT['step2_title'] ?? 'Download Client' ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Set Realmlist -->
                <div class="col-lg-3 col-md-6">
                    <div class="game-card h-100 text-center">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="step-icon"><i class="bi bi-pencil-square" style="color:#f0c040"></i></div>
                            <h4 class="text-accent mb-3"><?= $TEXT['step3_title'] ?></h4>
                            <p style="color:var(--text-muted)"><?= $TEXT['step3_desc'] ?></p>
                            <div class="realmlist-badge mt-auto">SET realmlist <?= htmlspecialchars($config['realm']['realmlist']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Login & Play -->
                <div class="col-lg-3 col-md-6">
                    <div class="game-card h-100 text-center">
                        <div class="card-body d-flex flex-column p-4">
                            <div class="step-icon"><i class="bi bi-joystick" style="color:#e74c3c"></i></div>
                            <h4 class="text-accent mb-3"><?= $TEXT['step4_title'] ?></h4>
                            <p style="color:var(--text-muted)"><?= $TEXT['step4_desc'] ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════ ANIMATED COUNTER BAR ═══════════════ -->
    <section id="stats-counter" class="content-section py-5 my-4 rounded">
        <div class="container">
            <div class="row g-4 justify-content-center text-center">
                <div class="col-md-4">
                    <div class="counter-card">
                        <div class="counter-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="counter-value" data-target="<?= $total_accounts ?>">0</div>
                        <div class="counter-label">Registered Accounts</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="counter-card">
                        <div class="counter-icon" style="color:#ABD473"><i class="bi bi-sword"></i></div>
                        <div class="counter-value" data-target="<?= $total_characters ?>" style="color:#ABD473">0</div>
                        <div class="counter-label">Characters Created</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="counter-card">
                        <div class="counter-icon" style="color:#5dd87c"><i class="bi bi-lightning-charge-fill"></i></div>
                        <div class="counter-value" data-target="<?= is_numeric($player_count_r1) ? $player_count_r1 : 0 ?>" style="color:#5dd87c">0</div>
                        <div class="counter-label">Players Online</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Server Info & Status Section -->
    <section id="server-info-status" class="content-section py-5 my-4 rounded">
        <div class="container">
            <div class="section-title text-center mb-5">
                <h2><?= htmlspecialchars($config['realm']['name']) ?> <?= $TEXT['server_status'] ?></h2>
            </div>

            <p class="text-center lead mb-4 text-warning" style="font-size: 1.3rem;"><?= htmlspecialchars($config['realm']['description']) ?></p>
            <div class="row text-center justify-content-center g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/xpx2.webp" class="card-img-top" alt="<?= $TEXT['feature_xp'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_xp'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_xp_desc'] ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/underwater.webp" class="card-img-top" alt="<?= $TEXT['feature_underwater'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_underwater'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_underwater_desc'] ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/faction-grouping.webp" class="card-img-top" alt="<?= $TEXT['feature_crossfaction'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_crossfaction'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_crossfaction_desc'] ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/gift.webp" class="card-img-top" alt="<?= $TEXT['feature_gift'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_gift'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_gift_desc'] ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/walk-speed.webp" class="card-img-top" alt="<?= $TEXT['feature_walkspeed'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_walkspeed'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_walkspeed_desc'] ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="game-card h-100">
                        <img src="/assets/img/play-with-bots.webp" class="card-img-top" alt="<?= $TEXT['feature_bots'] ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= $TEXT['feature_bots'] ?></h5>
                            <p class="card-text"><?= $TEXT['feature_bots_desc'] ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Server Status Bar -->
            <div class="server-status-bar">
                <div class="stat-item">
                    <span class="stat-label"><?= $TEXT['auth_server'] ?>:</span>
                    <span class="status-dot <?= $auth_server_status_r1 ? 'status-online' : 'status-offline' ?>"></span>
                    <span class="stat-value"><?= $auth_server_status_r1 ? $TEXT['status_online'] : $TEXT['status_offline'] ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?= $TEXT['world_server_r1'] ?>:</span>
                    <span class="status-dot <?= $world_server_status_r1 ? 'status-online' : 'status-offline' ?>"></span>
                    <span class="stat-value"><?= $world_server_status_r1 ? $TEXT['status_online'] : $TEXT['status_offline'] ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label"><?= $TEXT['players_online'] ?>:</span>
                    <span class="stat-value"><?= htmlspecialchars($player_count_r1) ?></span>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════ FAQ SECTION ═══════════════ -->
    <?php if (!empty($faq_items)): ?>
    <section id="faq" class="content-section py-5 my-4 rounded">
        <div class="container" style="max-width:800px">
            <div class="section-title text-center mb-5">
                <h2><i class="bi bi-question-circle me-2"></i>Frequently Asked Questions</h2>
            </div>
            <div class="accordion" id="faqAccordion">
                <?php foreach ($faq_items as $i => $faq): ?>
                <div class="accordion-item faq-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed faq-btn" type="button" data-bs-toggle="collapse" data-bs-target="#faq-<?= $i ?>" aria-expanded="false">
                            <i class="bi bi-patch-question me-2" style="color:#c8a96e"></i>
                            <?= htmlspecialchars($faq['q']) ?>
                        </button>
                    </h2>
                    <div id="faq-<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body faq-body">
                            <?= htmlspecialchars($faq['a']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

</main>

<style>
/* Discord button */
.btn-discord {
    background: #5865F2;
    color: #fff;
    border: 2px solid #5865F2;
    font-weight: 600;
    transition: all .3s ease;
}
.btn-discord:hover {
    background: #4752C4;
    border-color: #4752C4;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(88,101,242,.4);
}

/* News cards */
.news-card {
    transition: transform .3s ease, border-color .3s ease;
}
.news-card:hover {
    transform: translateY(-4px);
    border-color: rgba(200,169,110,.5);
}
.news-icon-circle {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(139,69,19,.3), rgba(200,169,110,.15));
    border: 1px solid rgba(200,169,110,.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #c8a96e;
    flex-shrink: 0;
}

/* Animated counter */
.counter-card {
    padding: 2rem 1rem;
}
.counter-icon {
    font-size: 2.5rem;
    color: #c8a96e;
    margin-bottom: .75rem;
}
.counter-value {
    font-size: 2.8rem;
    font-weight: 800;
    color: #c8a96e;
    line-height: 1;
    margin-bottom: .3rem;
    font-variant-numeric: tabular-nums;
}
.counter-label {
    font-size: .82rem;
    color: #8899aa;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 600;
}

/* FAQ Accordion */
.faq-item {
    background: transparent;
    border: 1px solid rgba(139,69,19,.2);
    border-radius: 12px !important;
    margin-bottom: .75rem;
    overflow: hidden;
}
.faq-btn {
    background: rgba(255,255,255,.03);
    color: #e2e8f0;
    font-weight: 600;
    font-size: .95rem;
    border: none;
    padding: 1.1rem 1.3rem;
    box-shadow: none !important;
    transition: all .2s ease;
}
.faq-btn:not(.collapsed) {
    background: rgba(139,69,19,.15);
    color: #c8a96e;
}
.faq-btn::after {
    filter: invert(1) brightness(.6);
}
.faq-btn:not(.collapsed)::after {
    filter: invert(1) brightness(.8) sepia(1) hue-rotate(-20deg);
}
.faq-btn:hover {
    background: rgba(255,255,255,.06);
}
.faq-body {
    color: rgba(255,255,255,.65);
    font-size: .93rem;
    line-height: 1.7;
    background: rgba(0,0,0,.2);
    border-top: 1px solid rgba(139,69,19,.15);
    padding: 1.2rem 1.3rem;
}
</style>

<?php require_once __DIR__ . '/templates/footer.php'; ?>

<script>
// ── Animated Counter (count up on scroll) ────────────────────────────────────
(function(){
    let triggered = false;
    const counters = document.querySelectorAll('.counter-value');
    if (!counters.length) return;

    function animateCounters() {
        if (triggered) return;
        triggered = true;
        counters.forEach(el => {
            const target = parseInt(el.dataset.target, 10) || 0;
            const duration = 2000; // ms
            const frames = 80;
            const step = target / frames;
            let current = 0;
            const interval = setInterval(() => {
                current += step;
                if (current >= target) { current = target; clearInterval(interval); }
                el.textContent = Math.floor(current).toLocaleString();
            }, duration / frames);
        });
    }

    // Use IntersectionObserver if available
    if ('IntersectionObserver' in window) {
        const ob = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) { animateCounters(); ob.disconnect(); } });
        }, { threshold: 0.3 });
        ob.observe(document.getElementById('stats-counter'));
    } else {
        // Fallback: animate on load
        window.addEventListener('load', animateCounters);
    }
})();
</script>
