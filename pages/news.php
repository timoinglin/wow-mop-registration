<?php
/**
 * News — public list page + slug detail dispatch.
 *
 *   /news              → paginated card grid of published posts
 *   /news/{slug}       → detail view (routed through .htaccess)
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/news.php';

// Auto-import legacy config.news the first time this page is hit
news_maybe_autoimport($pdo_auth, $config);

// If a slug came in (via /news/{slug} rewrite OR ?slug=… fallback), hand off
// to the detail renderer.
$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug !== '') {
    $post = news_get_by_slug($pdo_auth, $slug);
    if (!$post) {
        http_response_code(404);
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <div class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
            <h2 style="color:#c8a96e"><?= htmlspecialchars($TEXT['news_not_found_title'] ?? 'Post not found') ?></h2>
            <p style="color:#8899aa"><?= htmlspecialchars($TEXT['news_not_found_hint'] ?? 'This news post does not exist or is no longer published.') ?></p>
            <a href="/news" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['news_back_to_list'] ?? 'Back to News') ?></a>
        </div>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    require_once __DIR__ . '/../includes/markdown.php';

    // OG/Twitter meta override
    $page_title     = $post['title'];
    $og_title       = $post['title'];
    $og_description = $post['excerpt'] ?: mb_substr(strip_tags($post['body']), 0, 200);
    $og_type        = 'article';

    require_once __DIR__ . '/../templates/header.php';

    $body_html = render_markdown((string)$post['body']);
    $published = $post['published_at'] ? date('F j, Y', strtotime($post['published_at'])) : '';
    ?>
    <main class="container" style="padding-top:120px;padding-bottom:3rem;max-width:880px">
        <article>
            <a href="/news" style="color:#8899aa;text-decoration:none;font-size:.9rem">
                <i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_back_to_list'] ?? 'Back to News') ?>
            </a>
            <header class="text-center my-4">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <div style="width:60px;height:60px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(145deg,#1a1a2e,#12121f);border:1px solid rgba(139,69,19,.3)">
                        <i class="bi <?= htmlspecialchars($post['icon'] ?: 'bi-megaphone') ?>" style="color:#c8a96e;font-size:1.5rem"></i>
                    </div>
                </div>
                <h1 style="color:#c8a96e;font-weight:700"><?= htmlspecialchars($post['title']) ?></h1>
                <p style="color:#8899aa;font-size:.9rem;margin-top:.5rem">
                    <?php if ($published): ?>
                        <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($published) ?>
                    <?php endif; ?>
                    <?php if (!empty($post['author_name'])): ?>
                        <span class="mx-2">·</span>
                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($post['author_name']) ?>
                    <?php endif; ?>
                </p>
            </header>
            <div class="game-card">
                <div class="card-body p-4 p-md-5">
                    <div class="news-body" style="color:rgba(255,255,255,.85);line-height:1.8;font-size:1.05rem">
                        <?= $body_html ?>
                    </div>
                </div>
            </div>
        </article>
    </main>
    <style>
    .news-body h1,.news-body h2,.news-body h3,.news-body h4 { color:#c8a96e; margin-top:1.5rem; }
    .news-body a { color:#69CCF0; }
    .news-body code { background:rgba(255,255,255,.06); padding:.1rem .35rem; border-radius:3px; font-size:.95em; }
    .news-body pre { background:rgba(0,0,0,.4); padding:1rem; border-radius:6px; overflow-x:auto; }
    .news-body img { max-width:100%; height:auto; border-radius:6px; }
    .news-body blockquote { border-left:3px solid #c8a96e; padding-left:1rem; color:#8899aa; margin:1rem 0; }
    .news-body hr { border-color:rgba(139,69,19,.3); }
    .news-body ul, .news-body ol { padding-left:1.5rem; }
    </style>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ─── List page ───────────────────────────────────────────────────────────────
$per_page = 9;
$page     = max(1, (int)($_GET['page'] ?? 1));
$total    = news_count_published($pdo_auth);
$pages    = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$posts    = news_published_page($pdo_auth, $page, $per_page);

$page_title = ($TEXT['news_page_title'] ?? 'News') . ' — ' . ($config['site']['title'] ?? 'WoW');

require_once __DIR__ . '/../templates/header.php';
?>

<main class="container" style="padding-top:120px;padding-bottom:3rem">
    <header class="text-center mb-5">
        <h1 style="color:#c8a96e;font-weight:700"><i class="bi bi-newspaper me-2"></i><?= htmlspecialchars($TEXT['news_page_title'] ?? 'News') ?></h1>
        <p style="color:#8899aa"><?= htmlspecialchars($TEXT['news_page_subtitle'] ?? 'Updates, patch notes, and announcements.') ?></p>
    </header>

    <?php if (empty($posts)): ?>
        <div class="text-center py-5" style="color:#4a5568">
            <i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.4"></i>
            <p><?= htmlspecialchars($TEXT['news_empty'] ?? 'No news posts yet. Check back soon!') ?></p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($posts as $p): ?>
                <?php
                $href = '/news/' . rawurlencode($p['slug']);
                $date_str = $p['published_at'] ? date('M j, Y', strtotime($p['published_at'])) : '';
                $excerpt  = $p['excerpt'] ?: mb_substr(strip_tags($p['body'] ?? ''), 0, 180);
                ?>
                <div class="col-lg-4 col-md-6">
                    <a href="<?= htmlspecialchars($href) ?>" style="text-decoration:none;color:inherit">
                        <div class="game-card h-100" style="cursor:pointer;transition:transform .15s ease">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(145deg,#1a1a2e,#12121f);border:1px solid rgba(139,69,19,.3);flex-shrink:0">
                                        <i class="bi <?= htmlspecialchars($p['icon'] ?: 'bi-megaphone') ?>" style="color:#c8a96e"></i>
                                    </div>
                                    <div style="min-width:0;flex:1">
                                        <h5 style="color:#c8a96e;margin:0;font-weight:700;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($p['title']) ?></h5>
                                        <span style="color:#4a5568;font-size:.78rem"><?= htmlspecialchars($date_str) ?></span>
                                    </div>
                                </div>
                                <p style="color:rgba(255,255,255,.65);font-size:.92rem;margin:0;line-height:1.6"><?= htmlspecialchars($excerpt) ?></p>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-5" aria-label="Pagination">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>" style="background:#1a1a2e;color:#c8a96e;border-color:rgba(139,69,19,.3)"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
