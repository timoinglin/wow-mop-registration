<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
// Generate a random number between 1 and 5 for the background image (since we have 5 images)
$random_bg = rand(1, 5);
$bg_image = "/assets/img/wow-bg/4-{$random_bg}.webp";
$social = $config['social'] ?? [];

// Footer quick-links: admin-customizable (DB override), config/built-in
// fallback. $pdo_auth is set by header.php's defensive db include on every
// page; null-safe resolver returns built-in defaults if it isn't.
require_once __DIR__ . '/../includes/site_settings.php';
$footer_cfg = footer_links_get($pdo_auth ?? null);
$footer_quick = [];
if (!empty($footer_cfg['builtin']['home']))     $footer_quick[] = ['/', $TEXT['home'] ?? 'Home'];
if (!empty($footer_cfg['builtin']['register'])) $footer_quick[] = ['/register', $TEXT['register'] ?? 'Register'];
if (!empty($footer_cfg['builtin']['login']))    $footer_quick[] = ['/login', $TEXT['login'] ?? 'Login'];
if (!empty($footer_cfg['builtin']['support']) && !empty($config['features']['tickets'])) {
    $footer_quick[] = ['/tickets', $TEXT['footer_support'] ?? 'Support'];
}
foreach ($footer_cfg['custom'] as $row) {
    $footer_quick[] = [$row['url'], $row['label']];
}
?>
<footer class="footer text-center" style="padding-top: 140px; padding-bottom: 140px; background-image: url('<?= $bg_image ?>'); background-size: cover; background-position: center top; background-repeat: no-repeat; margin-top: 100px; position: relative;">
    <!-- Add a dark overlay to ensure text readability -->
    <div class="position-absolute top-0 start-0 w-100 h-100" style="background-color: rgba(0, 0, 0, 0.5);"></div>
    <div class="container position-relative">

        <!-- Social Media Icons -->
        <?php if (!empty(array_filter($social))): ?>
        <div class="mb-4 d-flex justify-content-center gap-3 flex-wrap">
            <?php if (!empty($social['discord'])): ?>
                <a href="<?= htmlspecialchars($social['discord']) ?>" target="_blank" rel="noopener noreferrer" class="footer-social-btn" title="Discord" style="background:rgba(88,101,242,.15);border-color:rgba(88,101,242,.3);color:#5865F2">
                    <i class="bi bi-discord"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($social['youtube'])): ?>
                <a href="<?= htmlspecialchars($social['youtube']) ?>" target="_blank" rel="noopener noreferrer" class="footer-social-btn" title="YouTube" style="background:rgba(255,0,0,.12);border-color:rgba(255,0,0,.25);color:#FF0000">
                    <i class="bi bi-youtube"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($social['twitter'])): ?>
                <a href="<?= htmlspecialchars($social['twitter']) ?>" target="_blank" rel="noopener noreferrer" class="footer-social-btn" title="X / Twitter" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.15);color:#fff">
                    <i class="bi bi-twitter-x"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($social['instagram'])): ?>
                <a href="<?= htmlspecialchars($social['instagram']) ?>" target="_blank" rel="noopener noreferrer" class="footer-social-btn" title="Instagram" style="background:rgba(225,48,108,.12);border-color:rgba(225,48,108,.25);color:#E1306C">
                    <i class="bi bi-instagram"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Quick Links (admin-customizable via /admin_customization) -->
        <?php if (!empty($footer_quick)): ?>
        <div class="mb-3" style="font-size:.85rem">
            <?php foreach ($footer_quick as $i => $ql): ?>
                <?php if ($i > 0): ?><span class="footer-sep">·</span><?php endif; ?>
                <a href="<?= htmlspecialchars($ql[0]) ?>" class="footer-link"><?= htmlspecialchars($ql[1]) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="mb-3" style="font-size:.85rem; color: rgba(255,255,255,.6);">
            <?= htmlspecialchars($TEXT['footer_disclaimer'] ?? 'Notice: This is a private fan server. We are not affiliated with Blizzard Entertainment.') ?>
        </div>

        <span class="text-light">&copy; <?= date('Y') ?> <?= htmlspecialchars($config['realm']['name']) ?>. All rights reserved.</span>
    </div>
</footer>

<style>
.footer-social-btn {
    width: 48px; height: 48px; border-radius: 12px; border: 1px solid;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.3rem; text-decoration: none;
    transition: all .25s ease;
}
.footer-social-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,.3);
    filter: brightness(1.2);
}
.footer-link {
    color: rgba(255,255,255,.6);
    text-decoration: none;
    transition: color .2s;
}
.footer-link:hover { color: #c8a96e; }
.footer-sep { color: rgba(255,255,255,.2); margin: 0 .5rem; }
</style>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<!-- Navbar Scroll Script -->
<script>
    const mainNavbar = document.getElementById('mainNavbar');
    if (mainNavbar) {
        window.addEventListener('scroll', function() {
            // Add class if scrolled down more than a threshold (e.g., 50 pixels)
            if (window.scrollY > 50) {
                mainNavbar.classList.add('navbar-scrolled');
                // Optional: Remove opacity class if it interferes
                 mainNavbar.classList.remove('bg-opacity-75');
            } else {
                mainNavbar.classList.remove('navbar-scrolled');
                 // Optional: Add opacity class back
                 mainNavbar.classList.add('bg-opacity-75');
            }
        });

        // Mobile: force the dark navbar look while the collapsible menu is
        // expanded, otherwise the dropped-down items render with a
        // transparent background over the hero and become unreadable.
        const navCollapse = document.getElementById('navbarNav');
        if (navCollapse) {
            navCollapse.addEventListener('show.bs.collapse',  () => mainNavbar.classList.add('navbar-scrolled'));
            navCollapse.addEventListener('hidden.bs.collapse', () => {
                if (window.scrollY <= 50) mainNavbar.classList.remove('navbar-scrolled');
            });
        }
    }
</script>
<?php if (!empty($extra_scripts)) { echo $extra_scripts; } ?>

</body>
</html>
