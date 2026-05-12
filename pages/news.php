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
    <?php $is_admin = isset($_SESSION['user_id']) && (int)($_SESSION['gm_level'] ?? 0) >= 9; ?>
    <main class="container" style="padding-top:120px;padding-bottom:3rem;max-width:880px">
        <article>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <a href="/news" style="color:#8899aa;text-decoration:none;font-size:.9rem">
                    <i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_back_to_list'] ?? 'Back to News') ?>
                </a>
                <?php if ($is_admin): ?>
                    <a href="/admin_news?id=<?= (int)$post['id'] ?>"
                       class="btn btn-sm"
                       style="background:#8B4513;color:#fff;border:1px solid #A0522D;font-size:.8rem;padding:.3rem .75rem">
                        <i class="bi bi-pencil-square me-1"></i><?= htmlspecialchars($TEXT['news_edit_post'] ?? 'Edit Post') ?>
                    </a>
                <?php endif; ?>
            </div>
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
        <?php
        // "Showing X–Y of Z" counter
        $from = ($page - 1) * $per_page + 1;
        $to   = min($page * $per_page, $total);
        $counter_tpl = $TEXT['news_pager_counter'] ?? 'Showing {from}–{to} of {total}';
        ?>
        <div class="text-center mb-3" style="color:#8899aa;font-size:.9rem">
            <?= htmlspecialchars(strtr($counter_tpl, ['{from}' => $from, '{to}' => $to, '{total}' => $total])) ?>
        </div>

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
            <?php
            // Windowed pager: first/prev | 1 … (n-2)(n-1)[n](n+1)(n+2) … last | next/last
            // Adjacent-page window of 2 each side, with ellipses when gaps exist.
            $win = 2;
            $start = max(1, $page - $win);
            $end   = min($pages, $page + $win);

            $items = [];
            if ($start > 1) {
                $items[] = ['type' => 'num',  'n' => 1];
                if ($start > 2) $items[] = ['type' => 'ellipsis'];
            }
            for ($i = $start; $i <= $end; $i++) {
                $items[] = ['type' => 'num', 'n' => $i];
            }
            if ($end < $pages) {
                if ($end < $pages - 1) $items[] = ['type' => 'ellipsis'];
                $items[] = ['type' => 'num', 'n' => $pages];
            }
            ?>
            <nav class="mt-5" aria-label="<?= htmlspecialchars($TEXT['news_pager_label'] ?? 'News pagination') ?>">
                <ul class="pagination news-pager justify-content-center flex-wrap">
                    <?php
                    $prev_p = max(1, $page - 1);
                    $next_p = min($pages, $page + 1);
                    $is_first = ($page <= 1);
                    $is_last  = ($page >= $pages);
                    ?>

                    <!-- « First -->
                    <li class="page-item <?= $is_first ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=1" aria-label="<?= htmlspecialchars($TEXT['news_pager_first'] ?? 'First page') ?>" <?= $is_first ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&laquo;</a>
                    </li>
                    <!-- ‹ Prev -->
                    <li class="page-item <?= $is_first ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $prev_p ?>" aria-label="<?= htmlspecialchars($TEXT['news_pager_prev'] ?? 'Previous page') ?>" <?= $is_first ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&lsaquo;</a>
                    </li>

                    <?php foreach ($items as $it): ?>
                        <?php if ($it['type'] === 'ellipsis'): ?>
                            <li class="page-item disabled" aria-hidden="true"><span class="page-link">&hellip;</span></li>
                        <?php else: $n = $it['n']; ?>
                            <li class="page-item <?= $n === $page ? 'active' : '' ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>>
                                <a class="page-link" href="?page=<?= $n ?>"><?= $n ?></a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- › Next -->
                    <li class="page-item <?= $is_last ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $next_p ?>" aria-label="<?= htmlspecialchars($TEXT['news_pager_next'] ?? 'Next page') ?>" <?= $is_last ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&rsaquo;</a>
                    </li>
                    <!-- » Last -->
                    <li class="page-item <?= $is_last ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $pages ?>" aria-label="<?= htmlspecialchars($TEXT['news_pager_last'] ?? 'Last page') ?>" <?= $is_last ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&raquo;</a>
                    </li>
                </ul>
            </nav>
            <style>
                .news-pager .page-link {
                    background: #1a1a2e;
                    color: #c8a96e;
                    border-color: rgba(139,69,19,.3);
                    min-width: 2.4rem;
                    text-align: center;
                }
                .news-pager .page-link:hover {
                    background: #2a1f10;
                    color: #fff;
                    border-color: #c8a96e;
                }
                .news-pager .page-item.active .page-link {
                    background: #8B4513;
                    border-color: #A0522D;
                    color: #fff;
                    font-weight: 600;
                }
                .news-pager .page-item.disabled .page-link {
                    background: #12121f;
                    color: #4a5568;
                    border-color: rgba(139,69,19,.15);
                    cursor: not-allowed;
                }
            </style>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
