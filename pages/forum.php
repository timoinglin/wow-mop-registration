<?php
/**
 * Public forum (read-only in Phase 3).
 *
 *   /forum                                  → category index
 *   /forum/{category-slug}                  → thread list
 *   /forum/{category-slug}/{thread-slug}    → thread with OP + replies
 *
 * Phase 4 adds writing (create thread / reply), Phase 5 adds moderation.
 * The whole module is gated by forum_settings.enabled — when off, anyone
 * who lands here gets a friendly "Forum is currently disabled" notice.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/forum.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/markdown.php';

$settings    = forum_settings_get($pdo_auth);
$user_id     = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username    = (string)($_SESSION['username'] ?? '');
$gm_level    = (int)($_SESSION['gm_level'] ?? 0);
$is_banned   = $user_id > 0 ? forum_is_user_banned($pdo_auth, $user_id) : false;
$is_admin    = $gm_level >= 9;

// Disabled forum gate — admins still allowed in for preview
if (!$settings['enabled'] && $gm_level < 9) {
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <main class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center;max-width:680px">
        <i class="bi bi-pause-circle" style="font-size:3rem;color:#8899aa;display:block;margin-bottom:1rem;opacity:.5"></i>
        <h2 style="color:var(--accent)"><?= htmlspecialchars($TEXT['forum_disabled_title'] ?? 'Forum is currently disabled') ?></h2>
        <p style="color:#8899aa"><?= htmlspecialchars($TEXT['forum_disabled_hint'] ?? 'The forum is not available right now. Please check back later.') ?></p>
        <a href="/" class="btn btn-gold mt-3"><i class="bi bi-house me-1"></i><?= htmlspecialchars($TEXT['home'] ?? 'Home') ?></a>
    </main>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ─── Route dispatch (params injected by the .htaccess rewrite rules) ────────
$cat_slug    = trim((string)($_GET['cat']    ?? ''));
$thread_slug = trim((string)($_GET['thread'] ?? ''));
$page        = max(1, (int)($_GET['page']   ?? 1));

$mode = 'index';
if ($cat_slug !== '' && $thread_slug !== '') $mode = 'thread';
elseif ($cat_slug !== '')                    $mode = 'category';

// ────────────────────────────────────────────────────────────────────────────
//  Helper: windowed pager (same shape as the news pager). $base is the URL
//  without ?page=; query params already in it are preserved via &page=N.
// ────────────────────────────────────────────────────────────────────────────
function fp_pager(int $current, int $total_pages, string $base, array $T): string
{
    if ($total_pages < 2) return '';
    $win = 2;
    $start = max(1, $current - $win);
    $end   = min($total_pages, $current + $win);

    $items = [];
    if ($start > 1) {
        $items[] = ['type' => 'num', 'n' => 1];
        if ($start > 2) $items[] = ['type' => 'ellipsis'];
    }
    for ($i = $start; $i <= $end; $i++) $items[] = ['type' => 'num', 'n' => $i];
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) $items[] = ['type' => 'ellipsis'];
        $items[] = ['type' => 'num', 'n' => $total_pages];
    }

    $glue = (strpos($base, '?') === false) ? '?' : '&';
    $link = fn($n) => htmlspecialchars($base . $glue . 'page=' . $n, ENT_QUOTES);

    $prev = max(1, $current - 1);
    $next = min($total_pages, $current + 1);
    $first_dis = $current <= 1;
    $last_dis  = $current >= $total_pages;

    $h  = '<nav class="mt-4" aria-label="' . htmlspecialchars($T['news_pager_label'] ?? 'Pagination') . '">';
    $h .= '<ul class="pagination news-pager justify-content-center flex-wrap">';
    $h .= '<li class="page-item ' . ($first_dis ? 'disabled' : '') . '"><a class="page-link" href="' . $link(1) . '">&laquo;</a></li>';
    $h .= '<li class="page-item ' . ($first_dis ? 'disabled' : '') . '"><a class="page-link" href="' . $link($prev) . '">&lsaquo;</a></li>';
    foreach ($items as $it) {
        if ($it['type'] === 'ellipsis') {
            $h .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        } else {
            $n = $it['n'];
            $cls = $n === $current ? 'active' : '';
            $h .= '<li class="page-item ' . $cls . '"><a class="page-link" href="' . $link($n) . '">' . $n . '</a></li>';
        }
    }
    $h .= '<li class="page-item ' . ($last_dis ? 'disabled' : '') . '"><a class="page-link" href="' . $link($next) . '">&rsaquo;</a></li>';
    $h .= '<li class="page-item ' . ($last_dis ? 'disabled' : '') . '"><a class="page-link" href="' . $link($total_pages) . '">&raquo;</a></li>';
    $h .= '</ul></nav>';
    return $h;
}

// Helper: "Last reply by X · 3 hours ago"
function fp_relative_time(?string $ts, array $T): string
{
    if (!$ts) return '—';
    $t = strtotime($ts);
    if (!$t) return '—';
    $diff = time() - $t;
    if ($diff < 60)      return ($T['fp_just_now']     ?? 'just now');
    if ($diff < 3600)    return sprintf($T['fp_min_ago']  ?? '%d min ago',   max(1, (int)($diff / 60)));
    if ($diff < 86400)   return sprintf($T['fp_hr_ago']   ?? '%d hr ago',    max(1, (int)($diff / 3600)));
    if ($diff < 86400*7) return sprintf($T['fp_d_ago']    ?? '%d days ago',  max(1, (int)($diff / 86400)));
    return date('M j, Y', $t);
}

// ────────────────────────────────────────────────────────────────────────────
//  Shared CSS used across all three modes
// ────────────────────────────────────────────────────────────────────────────
$forum_css = <<<'CSS'
<style>
.fo-wrap { padding-top:120px; padding-bottom:3rem; }
.fo-hero {
    background: linear-gradient(135deg, rgba(var(--btn-bg-rgb), .25) 0%, rgba(10,10,20,.85) 60%);
    border: 1px solid rgba(var(--btn-bg-rgb), .4);
    border-radius: 12px;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.5rem;
}
.fo-hero h1 {
    color: var(--accent);
    margin: 0;
    font-weight: 700;
    letter-spacing: 1px;
    font-size: 1.6rem;
}
.fo-hero p { color: #8899aa; margin: .3rem 0 0; }
.fo-crumb {
    color: #8899aa;
    font-size: .85rem;
    margin-bottom: 1rem;
}
.fo-crumb a { color: var(--accent); text-decoration: none; }
.fo-crumb a:hover { color: #fff; }

/* Category card on the /forum index */
.fo-cat-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    background: linear-gradient(145deg, #15151f, #0e0e17);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 10px;
    padding: 1.1rem 1.4rem;
    margin-bottom: 1rem;
    text-decoration: none;
    color: inherit;
    transition: border-color .15s ease, transform .15s ease;
}
.fo-cat-card:hover { border-color: rgba(var(--accent-rgb), .6); transform: translateY(-1px); color: inherit; }
.fo-cat-icon {
    width: 56px; height: 56px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(145deg, #1a1a2e, #12121f);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 50%;
    color: var(--accent);
    font-size: 1.5rem;
    flex-shrink: 0;
}
.fo-cat-body { flex: 1; min-width: 0; }
.fo-cat-name { color: var(--accent); font-weight: 700; font-size: 1.1rem; }
.fo-cat-desc { color: #8899aa; font-size: .88rem; margin-top: .2rem; }
.fo-cat-stats {
    text-align: right;
    color: #8899aa;
    font-size: .8rem;
    flex-shrink: 0;
    min-width: 180px;
}
.fo-cat-stats .latest-title {
    color: #dee2e6;
    display: block;
    max-width: 230px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Thread row on a category page */
.fo-thread-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: #0e0e17;
    border: 1px solid rgba(var(--btn-bg-rgb), .2);
    border-radius: 8px;
    padding: .85rem 1.1rem;
    margin-bottom: .55rem;
    transition: border-color .15s ease;
}
.fo-thread-row:hover { border-color: rgba(var(--accent-rgb), .4); }
.fo-thread-row .wl-avatar { width: 40px !important; height: 40px !important; font-size: 16px !important; border-width: 1.5px !important; }
.fo-thread-main { flex: 1; min-width: 0; }
.fo-thread-title {
    color: var(--accent);
    font-weight: 600;
    text-decoration: none;
    font-size: 1rem;
    display: inline-block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.fo-thread-title:hover { color: #fff; }
.fo-thread-sub { color: #8899aa; font-size: .78rem; margin-top: .15rem; }
.fo-badge { display:inline-block; padding:.12rem .5rem; border-radius:10px; font-size:.68rem; text-transform:uppercase; letter-spacing:.5px; margin-right:.4rem; vertical-align: middle; }
.fo-badge-sticky { background: rgba(var(--accent-rgb), .15); color: var(--accent); border: 1px solid rgba(var(--accent-rgb), .3); }
.fo-badge-locked { background: rgba(231,76,60,.15); color: #f87e8a; border: 1px solid rgba(231,76,60,.3); }
.fo-thread-stats {
    text-align: right;
    color: #8899aa;
    font-size: .78rem;
    flex-shrink: 0;
    min-width: 170px;
}
.fo-thread-stats .latest-row { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; display: inline-block; }
.fo-stat-count { color:var(--accent); font-weight:600; }

/* Post (OP + replies on the thread page) */
.fo-post {
    display: flex;
    gap: 1.2rem;
    background: linear-gradient(145deg, #15151f, #0e0e17);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 10px;
    padding: 1.4rem;
    margin-bottom: 1rem;
}
.fo-post-side {
    width: 90px;
    flex-shrink: 0;
    text-align: center;
}
.fo-post-side .wl-avatar { width: 64px !important; height: 64px !important; font-size: 26px !important; margin: 0 auto; }
.fo-post-author { color: var(--accent); font-weight: 700; margin-top: .55rem; font-size: .9rem; word-break: break-word; }
.fo-post-op-pill {
    display: inline-block;
    margin-top: .25rem;
    padding: .1rem .45rem;
    font-size: .65rem;
    background: rgba(105,204,240,.15);
    color: #69ccf0;
    border: 1px solid rgba(105,204,240,.3);
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.fo-post-main { flex: 1; min-width: 0; }
.fo-post-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #4a5568;
    font-size: .78rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), .15);
    padding-bottom: .55rem;
    margin-bottom: .85rem;
}
.fo-post-edited {
    color: #4a5568;
    font-size: .75rem;
    font-style: italic;
    margin-top: .8rem;
    padding-top: .55rem;
    border-top: 1px dashed rgba(var(--btn-bg-rgb), .15);
}
.fo-post-body {
    color: rgba(255,255,255,.85);
    line-height: 1.7;
    font-size: .95rem;
    word-wrap: break-word;
}
.fo-post-body h1,
.fo-post-body h2,
.fo-post-body h3,
.fo-post-body h4 { color: var(--accent); margin-top: 1.2rem; }
.fo-post-body a { color: #69CCF0; }
.fo-post-body code { background: rgba(255,255,255,.06); padding: .1rem .35rem; border-radius: 3px; }
.fo-post-body pre { background: rgba(0,0,0,.4); padding: .8rem 1rem; border-radius: 6px; overflow-x: auto; }
.fo-post-body img { max-width: 100%; height: auto; border-radius: 6px; }
.fo-post-body blockquote { border-left: 3px solid var(--accent); padding-left: 1rem; color: #8899aa; margin: 1rem 0; }
.fo-post-body ul, .fo-post-body ol { padding-left: 1.5rem; }
.fo-post-body table { border-collapse: collapse; margin: .6rem 0; }
.fo-post-body th, .fo-post-body td { border: 1px solid rgba(var(--btn-bg-rgb), .3); padding: .35rem .6rem; }

/* Reuse the news-pager styling via .news-pager class (already defined on /news,
   but the forum page may render before that CSS, so duplicate the minimum.) */
.news-pager .page-link {
    background: #1a1a2e;
    color: var(--accent);
    border-color: rgba(var(--btn-bg-rgb), .3);
    min-width: 2.4rem;
    text-align: center;
}
.news-pager .page-link:hover { background:#2a1f10; color:#fff; border-color:var(--accent); }
.news-pager .page-item.active .page-link { background:var(--btn-bg); border-color:var(--btn-bg-hover); color:#fff; font-weight:600; }
.news-pager .page-item.disabled .page-link { background:#12121f; color:#4a5568; border-color:rgba(var(--btn-bg-rgb), .15); cursor:not-allowed; }

/* Admin-disabled banner shown to GMs previewing while the public toggle is off */
/* Inline moderation buttons (GM-only) */
.fo-mod-btn {
    background: transparent;
    color: var(--accent);
    border: 1px solid rgba(var(--accent-rgb), .35);
    border-radius: 4px;
    padding: .35rem .75rem;
    font-size: .8rem;
    cursor: pointer;
    font-family: inherit;
    transition: all .15s ease;
}
.fo-mod-btn:hover { background: rgba(var(--accent-rgb), .12); color: #fff; border-color: var(--accent); }
.fo-mod-btn-ok { background: #2d6936; color: #fff; border-color: #3a7e44; }
.fo-mod-btn-ok:hover { background: #3a7e44; color: #fff; border-color: #5dd87c; }
.fo-mod-btn-danger { background: transparent; color: #f87e8a; border-color: rgba(231,76,60,.4); }
.fo-mod-btn-danger:hover { background: rgba(231,76,60,.15); color: #fff; border-color: #f87e8a; }

.fo-mod-link {
    background: transparent;
    border: 0;
    color: #8899aa;
    font-size: .78rem;
    cursor: pointer;
    font-family: inherit;
    padding: 0;
    text-decoration: none;
}
.fo-mod-link:hover { color: #fff; }
.fo-mod-link-ok { color: #5dd87c; }
.fo-mod-link-ok:hover { color: #98ffae; }
.fo-mod-link-danger { color: #f87e8a; }
.fo-mod-link-danger:hover { color: #ffbac0; }

.fo-preview-banner {
    background: rgba(var(--accent-rgb), .1);
    border: 1px solid rgba(var(--accent-rgb), .3);
    color: var(--accent);
    padding: .65rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: .85rem;
}
</style>
CSS;

// ════════════════════════════════════════════════════════════════════════════
//  MODE: thread detail
// ════════════════════════════════════════════════════════════════════════════
if ($mode === 'thread') {
    $thread = forum_thread_get_by_slug($pdo_auth, $thread_slug, $user_id ?: null, $is_admin);
    if (!$thread || $thread['category_slug'] !== $cat_slug) {
        http_response_code(404);
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <main class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
            <h2 style="color:var(--accent)"><?= htmlspecialchars($TEXT['forum_thread_not_found'] ?? 'Thread not found') ?></h2>
            <p style="color:#8899aa"><?= htmlspecialchars($TEXT['forum_thread_not_found_hint'] ?? 'This thread does not exist or is no longer published.') ?></p>
            <a href="/forum" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') ?></a>
        </main>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    // Session-tracked view de-dupe so refreshes and author re-visits don't
    // inflate the counter. First touch in a session bumps; subsequent ones
    // are no-ops. See forum_should_count_view() in includes/forum.php.
    if (forum_should_count_view((int)$thread['id'])) {
        forum_thread_increment_views($pdo_auth, (int)$thread['id']);
    }

    $per_page = 20;
    $total    = forum_posts_count_in_thread($pdo_auth, (int)$thread['id']);
    $pages    = max(1, (int)ceil($total / $per_page));
    if ($page > $pages) $page = $pages;
    $posts = forum_posts_in_thread_for_user($pdo_auth, (int)$thread['id'], $user_id ?: null, $is_admin, $page, $per_page);
    [$can_reply, $reply_reason] = forum_can_user_post($pdo_auth, $user_id ?: null, $gm_level, $settings, $thread);

    // Batch-load avatars for every author shown
    $author_ids = array_unique(array_map(fn($p) => (int)$p['author_id'], $posts));
    $avatars    = avatar_get_many($pdo_auth, $author_ids);

    // OG meta
    $page_title     = $thread['title'];
    $og_title       = $thread['title'];
    $first_body     = $posts[0]['body'] ?? '';
    $og_description = mb_substr(strip_tags($first_body), 0, 200);
    $og_type        = 'article';

    require_once __DIR__ . '/../templates/header.php';
    echo $forum_css;
    $base = '/forum/' . rawurlencode($thread['category_slug']) . '/' . rawurlencode($thread['slug']);
    ?>
    <div class="container fo-wrap" style="max-width: 980px">
        <?php if (!$settings['enabled'] && $gm_level >= 9): ?>
            <div class="fo-preview-banner"><i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['forum_preview_banner'] ?? 'Forum is currently disabled — you see this as an admin preview.') ?></div>
        <?php endif; ?>

        <div class="fo-crumb">
            <a href="/forum"><i class="bi bi-chevron-left"></i> <?= htmlspecialchars($TEXT['forum_nav'] ?? 'Forum') ?></a>
            &middot;
            <a href="/forum/<?= htmlspecialchars(rawurlencode($thread['category_slug']), ENT_QUOTES) ?>"><?= htmlspecialchars($thread['category_name']) ?></a>
        </div>

        <?php
        // Inline mod result toast
        $mod_flash = $_GET['mod'] ?? '';
        if ($mod_flash !== ''):
            $is_ok = !in_array($mod_flash, ['err','csrf'], true);
            $msg = match ($mod_flash) {
                'approved'   => $TEXT['mod_flash_approved']   ?? 'Approved and published.',
                'deleted'    => $TEXT['mod_flash_deleted']    ?? 'Deleted.',
                'locked'     => $TEXT['mod_flash_locked']     ?? 'Thread locked.',
                'unlocked'   => $TEXT['mod_flash_unlocked']   ?? 'Thread unlocked.',
                'stickied'   => $TEXT['mod_flash_stickied']   ?? 'Thread stuck to top.',
                'unstickied' => $TEXT['mod_flash_unstickied'] ?? 'Sticky removed.',
                'csrf'       => $TEXT['forum_reply_err_csrf'] ?? 'Session expired. Please try again.',
                default      => $TEXT['forum_err_save']        ?? 'Action failed.',
            };
        ?>
            <div style="margin-bottom:1rem;padding:.7rem 1rem;background:rgba(<?= $is_ok ? '46,204,113' : '231,76,60' ?>,.1);border:1px solid rgba(<?= $is_ok ? '46,204,113' : '231,76,60' ?>,.3);color:<?= $is_ok ? '#5dd87c' : '#e74c3c' ?>;border-radius:6px;font-size:.92rem">
                <i class="bi bi-<?= $is_ok ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i><?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div class="fo-hero">
            <h1>
                <?php if ($thread['status'] === 'pending'): ?><span class="fo-badge" style="background:rgba(var(--accent-rgb), .15);color:var(--accent);border:1px solid rgba(var(--accent-rgb), .35)"><i class="bi bi-hourglass-split"></i> <?= htmlspecialchars($TEXT['forum_pending_pill'] ?? 'Awaiting approval') ?></span><?php endif; ?>
                <?php if ($thread['is_sticky']): ?><span class="fo-badge fo-badge-sticky"><i class="bi bi-pin-angle-fill"></i> <?= htmlspecialchars($TEXT['forum_sticky'] ?? 'Sticky') ?></span><?php endif; ?>
                <?php if ($thread['is_locked']): ?><span class="fo-badge fo-badge-locked"><i class="bi bi-lock-fill"></i> <?= htmlspecialchars($TEXT['forum_locked'] ?? 'Locked') ?></span><?php endif; ?>
                <?= htmlspecialchars($thread['title']) ?>
            </h1>
            <p>
                <i class="bi bi-eye me-1"></i><?= (int)$thread['view_count'] ?>
                &middot;
                <i class="bi bi-chat-left-text me-1"></i><?= (int)$thread['reply_count'] ?> <?= htmlspecialchars($TEXT['forum_replies'] ?? 'replies') ?>
            </p>

            <?php if ($is_admin): $mod_csrf = generate_csrf_token(); ?>
                <div class="d-flex gap-2 flex-wrap" style="margin-top:.85rem;padding-top:.75rem;border-top:1px solid rgba(var(--btn-bg-rgb), .25)">
                    <span style="color:#8899aa;font-size:.72rem;text-transform:uppercase;letter-spacing:1px;padding:.35rem .15rem">
                        <i class="bi bi-shield-lock me-1"></i><?= htmlspecialchars($TEXT['forum_mod_label'] ?? 'Mod tools') ?>
                    </span>
                    <?php if ($thread['status'] === 'pending'): ?>
                        <form method="post" action="/forum/mod" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($mod_csrf) ?>">
                            <input type="hidden" name="action" value="approve_thread">
                            <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
                            <button type="submit" class="fo-mod-btn fo-mod-btn-ok"><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($TEXT['forum_mod_approve'] ?? 'Approve') ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="/forum/mod" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($mod_csrf) ?>">
                        <input type="hidden" name="action" value="toggle_sticky">
                        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
                        <button type="submit" class="fo-mod-btn"><i class="bi bi-pin-angle me-1"></i><?= htmlspecialchars($thread['is_sticky'] ? ($TEXT['forum_mod_unsticky'] ?? 'Unsticky') : ($TEXT['forum_mod_sticky'] ?? 'Sticky')) ?></button>
                    </form>
                    <form method="post" action="/forum/mod" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($mod_csrf) ?>">
                        <input type="hidden" name="action" value="toggle_lock">
                        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
                        <button type="submit" class="fo-mod-btn"><i class="bi bi-<?= $thread['is_locked'] ? 'unlock' : 'lock' ?> me-1"></i><?= htmlspecialchars($thread['is_locked'] ? ($TEXT['forum_mod_unlock'] ?? 'Unlock') : ($TEXT['forum_mod_lock'] ?? 'Lock')) ?></button>
                    </form>
                    <form method="post" action="/forum/mod" class="d-inline"
                          onsubmit="return confirm('<?= htmlspecialchars($TEXT['forum_mod_delete_thread_confirm'] ?? 'Delete this entire thread and all its replies? This cannot be undone.', ENT_QUOTES) ?>')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($mod_csrf) ?>">
                        <input type="hidden" name="action" value="delete_thread">
                        <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
                        <button type="submit" class="fo-mod-btn fo-mod-btn-danger"><i class="bi bi-trash me-1"></i><?= htmlspecialchars($TEXT['forum_mod_delete_thread'] ?? 'Delete Thread') ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php foreach ($posts as $p):
            $is_pending = ($p['status'] === 'pending');
            $is_mine    = ($user_id > 0 && (int)$p['author_id'] === $user_id);
            $can_edit   = ($is_admin || $is_mine);
        ?>
            <article class="fo-post" id="post-<?= (int)$p['id'] ?>" <?= $is_pending ? 'style="border-color: rgba(var(--accent-rgb), .35); background: linear-gradient(145deg, #1f1b14, #14110b);"' : '' ?>>
                <div class="fo-post-side">
                    <?= render_avatar((string)$p['author_name'], $avatars[(int)$p['author_id']] ?? null, 64) ?>
                    <div class="fo-post-author"><?= htmlspecialchars($p['author_name']) ?></div>
                    <?php if ($p['is_op']): ?>
                        <div><span class="fo-post-op-pill"><?= htmlspecialchars($TEXT['forum_op'] ?? 'Original Post') ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="fo-post-main">
                    <div class="fo-post-meta">
                        <span>
                            <i class="bi bi-clock me-1"></i><?= htmlspecialchars(date('M j, Y · H:i', strtotime($p['created_at']))) ?>
                            <?php if ($is_pending): ?>
                                <span style="margin-left:.6rem;padding:.1rem .5rem;background:rgba(var(--accent-rgb), .15);color:var(--accent);border:1px solid rgba(var(--accent-rgb), .35);border-radius:10px;font-size:.7rem;text-transform:uppercase;letter-spacing:.5px"><i class="bi bi-hourglass-split"></i> <?= htmlspecialchars($TEXT['forum_pending_pill'] ?? 'Awaiting approval') ?></span>
                            <?php endif; ?>
                        </span>
                        <span style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                            <?php if ($is_admin && $is_pending && !$p['is_op']): $pcsrf = generate_csrf_token(); ?>
                                <form method="post" action="/forum/mod" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pcsrf) ?>">
                                    <input type="hidden" name="action" value="approve_post">
                                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="fo-mod-link fo-mod-link-ok" title="<?= htmlspecialchars($TEXT['forum_mod_approve'] ?? 'Approve') ?>"><i class="bi bi-check2-circle"></i> <?= htmlspecialchars($TEXT['forum_mod_approve'] ?? 'Approve') ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($can_edit): ?>
                                <a href="/forum/edit/<?= (int)$p['id'] ?>" style="color:#8899aa;text-decoration:none;font-size:.78rem" title="<?= htmlspecialchars($TEXT['forum_edit_link'] ?? 'Edit') ?>"><i class="bi bi-pencil"></i> <?= htmlspecialchars($TEXT['forum_edit_link'] ?? 'Edit') ?></a>
                            <?php endif; ?>
                            <?php if ($is_admin): $pcsrf2 = generate_csrf_token(); ?>
                                <form method="post" action="/forum/mod" class="d-inline"
                                      onsubmit="return confirm('<?= htmlspecialchars($p['is_op'] ? ($TEXT['forum_mod_delete_thread_confirm'] ?? 'Delete this entire thread and all its replies? This cannot be undone.') : ($TEXT['forum_mod_delete_post_confirm'] ?? 'Delete this reply? This cannot be undone.'), ENT_QUOTES) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pcsrf2) ?>">
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="fo-mod-link fo-mod-link-danger" title="<?= htmlspecialchars($TEXT['forum_mod_delete'] ?? 'Delete') ?>"><i class="bi bi-trash"></i> <?= htmlspecialchars($TEXT['forum_mod_delete'] ?? 'Delete') ?></button>
                                </form>
                            <?php endif; ?>
                            <span style="font-family:monospace;font-size:.72rem;color:#4a5568">#<?= (int)$p['id'] ?></span>
                        </span>
                    </div>
                    <div class="fo-post-body"><?= render_markdown((string)$p['body']) ?></div>
                    <?php if (!empty($p['edited_at'])): ?>
                        <div class="fo-post-edited">
                            <i class="bi bi-pencil me-1"></i><?= htmlspecialchars(sprintf(
                                $TEXT['forum_edited_by_at'] ?? 'edited by %s on %s',
                                $p['edited_by'] ?? $p['author_name'],
                                date('M j, Y · H:i', strtotime($p['edited_at']))
                            )) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>

        <?= fp_pager($page, $pages, $base, $TEXT) ?>

        <?php
        // ─── Reply form (inline at the bottom of the thread page) ──────────
        $reply_pending = isset($_GET['reply_pending']);
        $reply_error   = $_GET['reply_error'] ?? '';
        ?>
        <?php if ($reply_pending): ?>
            <div style="margin-top:1.5rem;padding:.85rem 1rem;background:rgba(var(--accent-rgb), .1);border:1px solid rgba(var(--accent-rgb), .3);border-radius:6px;color:var(--accent);font-size:.92rem">
                <i class="bi bi-hourglass-split me-1"></i><?= htmlspecialchars($TEXT['forum_reply_pending_msg'] ?? 'Your reply was submitted and is waiting for admin approval.') ?>
            </div>
        <?php endif; ?>
        <?php if ($reply_error !== ''):
            $wait = max(0, (int)($_GET['wait'] ?? 0));
            $msg = match ($reply_error) {
                'csrf'           => $TEXT['forum_reply_err_csrf']   ?? 'Session expired. Please try again.',
                'empty'          => $TEXT['forum_reply_err_empty']  ?? 'Reply cannot be empty.',
                'too_long'       => $TEXT['forum_reply_err_long']   ?? 'Reply too long.',
                'banned'         => $TEXT['forum_banned_hint']      ?? 'You are banned from posting in the forum.',
                'locked'         => $TEXT['forum_locked_hint']      ?? 'This thread is locked. No new replies.',
                'replies_closed' => $TEXT['forum_replies_closed_hint'] ?? 'This category is read-only. Only GMs can reply here.',
                'forum_disabled' => $TEXT['forum_disabled_hint']    ?? 'The forum is currently disabled.',
                'not_logged_in'  => $TEXT['forum_login_to_reply']   ?? 'Log in to reply.',
                'cooldown'       => sprintf($TEXT['forum_err_cooldown'] ?? 'Please wait %d more second(s) before posting again.', $wait),
                default          => $TEXT['forum_err_save']         ?? 'Could not save reply.',
            };
        ?>
            <div style="margin-top:1.5rem;padding:.85rem 1rem;background:rgba(231,76,60,.1);border:1px solid rgba(231,76,60,.3);border-radius:6px;color:#e74c3c;font-size:.92rem">
                <i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($can_reply): ?>
            <?php $reply_csrf = generate_csrf_token(); ?>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
            <script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js" defer></script>
            <style>
            .EasyMDEContainer .editor-toolbar { background:#15151f; border:1px solid rgba(var(--btn-bg-rgb), .3); border-bottom:none; }
            .EasyMDEContainer .editor-toolbar button { color:var(--accent) !important; border-color:transparent !important; }
            .EasyMDEContainer .editor-toolbar button:hover, .EasyMDEContainer .editor-toolbar button.active { background:#2a1f10 !important; border-color:rgba(var(--btn-bg-rgb), .3) !important; color:#fff !important; }
            .EasyMDEContainer .CodeMirror { background:#0a0a0f; color:#dee2e6; border:1px solid rgba(var(--btn-bg-rgb), .3); border-top:none; font-family:'SFMono-Regular',Consolas,monospace; font-size:.92rem; line-height:1.55; min-height:200px; }
            .EasyMDEContainer .CodeMirror-cursor { border-left: 2px solid var(--accent) !important; }
            .EasyMDEContainer .CodeMirror-selected { background: rgba(var(--accent-rgb), .18); }
            .EasyMDEContainer .editor-preview, .EasyMDEContainer .editor-preview-side { background:#0a0a0f; color:rgba(255,255,255,.85); border-color:rgba(var(--btn-bg-rgb), .3); line-height:1.7; }
            .EasyMDEContainer .editor-preview h1,.EasyMDEContainer .editor-preview h2,.EasyMDEContainer .editor-preview h3, .EasyMDEContainer .editor-preview-side h1, .EasyMDEContainer .editor-preview-side h2, .EasyMDEContainer .editor-preview-side h3 { color:var(--accent); }
            .EasyMDEContainer .editor-statusbar { color:#4a5568; border:1px solid rgba(var(--btn-bg-rgb), .15); border-top:none; background:#12121f; padding:.35rem .8rem; font-size:.75rem; }
            .EasyMDEContainer .editor-toolbar.fullscreen, .EasyMDEContainer .CodeMirror-fullscreen, .EasyMDEContainer .editor-preview-side { z-index:1050; }
            body:has(.editor-toolbar.fullscreen) #mainNavbar, body:has(.editor-preview-side.fullscreen) #mainNavbar, body.easymde-fullscreen #mainNavbar { display:none; }
            .EasyMDEContainer .editor-toolbar.fullscreen { display:flex; flex-wrap:wrap; align-items:center; height:auto; min-height:50px; padding:0 4px; }
            .EasyMDEContainer .editor-toolbar.fullscreen > * { float:none !important; margin:0 !important; flex:0 0 auto; }
            </style>
            <div style="margin-top:2rem">
                <h4 style="color:var(--accent);font-size:1rem;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:.8rem">
                    <i class="bi bi-reply me-2"></i><?= htmlspecialchars($TEXT['forum_post_reply'] ?? 'Post a reply') ?>
                </h4>
                <?php
                $auto = forum_should_auto_approve($pdo_auth, $user_id, $gm_level, $settings);
                if (!$auto):
                    $need = max(0, (int)$settings['auto_approve_threshold'] - forum_user_approved_post_count($pdo_auth, $user_id));
                ?>
                    <div style="background:rgba(var(--accent-rgb), .08);border:1px solid rgba(var(--accent-rgb), .25);color:var(--accent);padding:.55rem .85rem;border-radius:6px;font-size:.85rem;margin-bottom:.8rem">
                        <i class="bi bi-hourglass-split me-1"></i>
                        <?= htmlspecialchars(sprintf(
                            $TEXT['forum_threshold_notice'] ?? 'Your post will wait for admin approval. After %d more approved posts, your posts publish instantly.',
                            $need
                        )) ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="/forum/reply">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($reply_csrf) ?>">
                    <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">
                    <textarea id="replyBody" name="body" required></textarea>
                    <div class="d-flex gap-2 justify-content-end mt-3">
                        <button type="submit" style="padding:.55rem 1.1rem;border-radius:4px;border:1px solid var(--btn-bg-hover);background:var(--btn-bg);color:#fff;cursor:pointer;font-family:inherit">
                            <i class="bi bi-send me-1"></i><?= htmlspecialchars($TEXT['forum_submit_reply'] ?? 'Post Reply') ?>
                        </button>
                    </div>
                </form>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const ta = document.getElementById('replyBody');
                if (!ta || typeof EasyMDE === 'undefined') return;
                const CSRF = '<?= htmlspecialchars($reply_csrf, ENT_QUOTES) ?>';
                const ed = new EasyMDE({
                    element: ta, autoDownloadFontAwesome: true, spellChecker: false,
                    status: ['lines','words'], minHeight: '180px',
                    autosave: { enabled: false }, forceSync: true,
                    placeholder: '<?= htmlspecialchars($TEXT['forum_reply_placeholder'] ?? "Write your reply in Markdown…", ENT_QUOTES) ?>',
                    previewRender: function (t, el) {
                        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('body', t);
                        fetch('/news_preview', { method:'POST', body:fd, credentials:'same-origin' })
                            .then(r => r.json()).then(j => { el.innerHTML = j.html || ''; })
                            .catch(() => { el.innerHTML = '<em>Preview unavailable.</em>'; });
                        return el.innerHTML;
                    },
                    uploadImage: true, imageMaxSize: 5 * 1024 * 1024,
                    imageAccept: 'image/png, image/jpeg, image/webp, image/gif',
                    imageUploadFunction: function (file, ok, err) {
                        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('image', file);
                        fetch('/forum_image', { method:'POST', body:fd, credentials:'same-origin' })
                            .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                            .then(({ ok: ok2, body }) => ok2 && body.url ? ok(body.url) : err(body.error || 'Upload failed.'))
                            .catch(() => err('Upload failed.'));
                    },
                    toolbar: ['bold','italic','strikethrough','|','heading-2','heading-3','|','quote','unordered-list','ordered-list','|','link','image','code','|','preview','side-by-side','fullscreen'],
                });
                const container = ed.element.parentNode;
                if (container) {
                    const sync = () => {
                        const tb = container.querySelector('.editor-toolbar');
                        document.body.classList.toggle('easymde-fullscreen', !!(tb && tb.classList.contains('fullscreen')));
                        const cmFs = container.querySelector('.CodeMirror-fullscreen');
                        const pvFs = container.querySelector('.editor-preview-side.fullscreen');
                        if (tb && tb.classList.contains('fullscreen')) {
                            const h = tb.offsetHeight + 'px';
                            if (cmFs) cmFs.style.top = h;
                            if (pvFs) pvFs.style.top = h;
                        } else {
                            if (cmFs) cmFs.style.top = '';
                            if (pvFs) pvFs.style.top = '';
                        }
                    };
                    new MutationObserver(sync).observe(container, { subtree:true, attributes:true, attributeFilter:['class'] });
                    window.addEventListener('resize', () => { if (document.body.classList.contains('easymde-fullscreen')) sync(); });
                    sync();
                }
            });
            </script>
        <?php else: ?>
            <div style="margin-top:2rem;padding:1rem 1.2rem;background:#0e0e17;border:1px solid rgba(var(--btn-bg-rgb), .25);border-radius:8px;color:#8899aa;text-align:center;font-size:.9rem">
                <?php
                $msg = match ($reply_reason) {
                    'not_logged_in'  => sprintf($TEXT['forum_login_to_reply_link'] ?? 'Please <a href="%s" style="color:var(--accent)">log in</a> to reply.', '/login'),
                    'banned'         => $TEXT['forum_banned_hint']   ?? 'You are banned from posting in the forum.',
                    'locked'         => '<i class="bi bi-lock-fill me-1"></i>' . htmlspecialchars($TEXT['forum_locked_hint'] ?? 'This thread is locked. No new replies.'),
                    'replies_closed' => '<i class="bi bi-megaphone-fill me-1"></i>' . htmlspecialchars($TEXT['forum_replies_closed_hint'] ?? 'This category is read-only. Only GMs can reply here.'),
                    'forum_disabled' => $TEXT['forum_disabled_hint'] ?? 'The forum is currently disabled.',
                    default          => $TEXT['forum_cannot_post']   ?? 'You cannot post right now.',
                };
                echo $msg;
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  MODE: category (thread list)
// ════════════════════════════════════════════════════════════════════════════
if ($mode === 'category') {
    $category = forum_category_get_by_slug($pdo_auth, $cat_slug);
    if (!$category) {
        http_response_code(404);
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <main class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
            <h2 style="color:var(--accent)"><?= htmlspecialchars($TEXT['forum_cat_not_found'] ?? 'Category not found') ?></h2>
            <a href="/forum" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') ?></a>
        </main>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    $per_page = 20;
    $total    = forum_threads_count_in_category_for_user($pdo_auth, (int)$category['id'], $user_id ?: null, $is_admin);
    $pages    = max(1, (int)ceil($total / $per_page));
    if ($page > $pages) $page = $pages;
    $threads = forum_threads_in_category_for_user($pdo_auth, (int)$category['id'], $user_id ?: null, $is_admin, $page, $per_page);

    $author_ids = array_unique(array_map(fn($t) => (int)$t['author_id'], $threads));
    $avatars    = avatar_get_many($pdo_auth, $author_ids);

    $page_title = $category['name'] . ' — ' . ($TEXT['forum_nav'] ?? 'Forum');
    require_once __DIR__ . '/../templates/header.php';
    echo $forum_css;
    $base = '/forum/' . rawurlencode($category['slug']);
    ?>
    <div class="container fo-wrap" style="max-width: 1080px">
        <?php if (!$settings['enabled'] && $gm_level >= 9): ?>
            <div class="fo-preview-banner"><i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['forum_preview_banner'] ?? 'Forum is currently disabled — you see this as an admin preview.') ?></div>
        <?php endif; ?>

        <div class="fo-crumb"><a href="/forum"><i class="bi bi-chevron-left"></i> <?= htmlspecialchars($TEXT['forum_nav'] ?? 'Forum') ?></a></div>

        <?php if (isset($_GET['pending'])): ?>
            <div style="margin-bottom:1rem;padding:.7rem 1rem;background:rgba(var(--accent-rgb), .1);border:1px solid rgba(var(--accent-rgb), .3);border-radius:6px;color:var(--accent);font-size:.92rem">
                <i class="bi bi-hourglass-split me-1"></i><?= htmlspecialchars($TEXT['forum_thread_pending_msg'] ?? 'Your thread was submitted and is waiting for admin approval. It will appear here once approved.') ?>
            </div>
        <?php endif; ?>

        <div class="fo-hero d-flex align-items-center gap-3">
            <div class="fo-cat-icon" style="width:64px;height:64px;font-size:1.7rem">
                <i class="bi <?= htmlspecialchars($category['icon'] ?: 'bi-chat-square-text') ?>"></i>
            </div>
            <div style="flex:1;min-width:0">
                <h1><?= htmlspecialchars($category['name']) ?></h1>
                <?php if (!empty($category['description'])): ?>
                    <p><?= htmlspecialchars($category['description']) ?></p>
                <?php endif; ?>
            </div>
            <div style="color:#8899aa;font-size:.85rem;text-align:right;flex-shrink:0">
                <div><?= (int)$total ?> <?= htmlspecialchars($TEXT['forum_threads'] ?? 'threads') ?></div>
                <?php
                [$can_post_here, $post_reason] = forum_can_user_post($pdo_auth, $user_id ?: null, $gm_level, $settings, null, $category);
                if ($can_post_here):
                ?>
                    <a href="/forum/new/<?= htmlspecialchars(rawurlencode($category['slug']), ENT_QUOTES) ?>"
                       style="display:inline-block;margin-top:.6rem;padding:.45rem 1rem;background:var(--btn-bg);color:#fff;border:1px solid var(--btn-bg-hover);border-radius:4px;text-decoration:none;font-size:.85rem">
                        <i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['forum_new_thread_btn'] ?? 'New Thread') ?>
                    </a>
                <?php elseif ($post_reason === 'not_logged_in'): ?>
                    <a href="/login" style="display:inline-block;margin-top:.6rem;padding:.45rem 1rem;background:transparent;color:var(--accent);border:1px solid rgba(var(--accent-rgb), .4);border-radius:4px;text-decoration:none;font-size:.85rem">
                        <i class="bi bi-box-arrow-in-right me-1"></i><?= htmlspecialchars($TEXT['forum_login_to_post'] ?? 'Log in to post') ?>
                    </a>
                <?php elseif ($post_reason === 'admin_only'): ?>
                    <div style="margin-top:.6rem;font-size:.78rem;color:var(--accent)">
                        <i class="bi bi-megaphone-fill me-1"></i><?= htmlspecialchars($TEXT['forum_announce_only'] ?? 'Announcements — only GMs can post here.') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($threads)): ?>
            <div class="text-center py-5" style="color:#4a5568">
                <i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.4"></i>
                <p><?= htmlspecialchars($TEXT['forum_cat_empty'] ?? 'No threads yet. Be the first to start one!') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($threads as $t):
                $href = '/forum/' . rawurlencode($category['slug']) . '/' . rawurlencode($t['slug']);
                $av   = $avatars[(int)$t['author_id']] ?? null;
            ?>
                <?php $t_pending = (($t['status'] ?? 'published') === 'pending'); ?>
                <div class="fo-thread-row" <?= $t_pending ? 'style="border-color: rgba(var(--accent-rgb), .35); background: linear-gradient(145deg, #1f1b14, #14110b);"' : '' ?>>
                    <?= render_avatar((string)$t['author_name'], $av, 40) ?>
                    <div class="fo-thread-main">
                        <?php if ($t_pending): ?><span class="fo-badge" style="background:rgba(var(--accent-rgb), .15);color:var(--accent);border:1px solid rgba(var(--accent-rgb), .35)" title="<?= htmlspecialchars($TEXT['forum_pending_pill'] ?? 'Awaiting approval') ?>"><i class="bi bi-hourglass-split"></i> <?= htmlspecialchars($TEXT['forum_pending_pill'] ?? 'Awaiting approval') ?></span><?php endif; ?>
                        <?php if ($t['is_sticky']): ?><span class="fo-badge fo-badge-sticky" title="<?= htmlspecialchars($TEXT['forum_sticky'] ?? 'Sticky') ?>"><i class="bi bi-pin-angle-fill"></i></span><?php endif; ?>
                        <?php if ($t['is_locked']): ?><span class="fo-badge fo-badge-locked" title="<?= htmlspecialchars($TEXT['forum_locked'] ?? 'Locked') ?>"><i class="bi bi-lock-fill"></i></span><?php endif; ?>
                        <a class="fo-thread-title" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= htmlspecialchars($t['title']) ?></a>
                        <div class="fo-thread-sub">
                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($t['author_name']) ?>
                            &middot;
                            <i class="bi bi-clock me-1"></i><?= htmlspecialchars(fp_relative_time($t['created_at'], $TEXT)) ?>
                        </div>
                    </div>
                    <div class="fo-thread-stats">
                        <div><span class="fo-stat-count"><?= (int)$t['reply_count'] ?></span> <?= htmlspecialchars($TEXT['forum_replies'] ?? 'replies') ?> &middot; <span class="fo-stat-count"><?= (int)$t['view_count'] ?></span> <?= htmlspecialchars($TEXT['forum_views'] ?? 'views') ?></div>
                        <?php if ($t['last_reply_at']): ?>
                            <div class="latest-row mt-1">
                                <i class="bi bi-chat-left-dots me-1"></i><?= htmlspecialchars($t['last_reply_by'] ?: $t['author_name']) ?> &middot; <?= htmlspecialchars(fp_relative_time($t['last_reply_at'], $TEXT)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?= fp_pager($page, $pages, $base, $TEXT) ?>
        <?php endif; ?>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
//  MODE: index (all categories)
// ════════════════════════════════════════════════════════════════════════════
$categories = forum_categories_with_stats($pdo_auth);
$page_title = ($TEXT['forum_nav'] ?? 'Forum') . ' — ' . ($config['site']['title'] ?? 'WoW');
require_once __DIR__ . '/../templates/header.php';
echo $forum_css;
?>
<div class="container fo-wrap" style="max-width: 1080px">
    <?php if (!$settings['enabled'] && $gm_level >= 9): ?>
        <div class="fo-preview-banner"><i class="bi bi-eye me-1"></i><?= htmlspecialchars($TEXT['forum_preview_banner'] ?? 'Forum is currently disabled — you see this as an admin preview.') ?></div>
    <?php endif; ?>

    <div class="fo-hero">
        <h1><i class="bi bi-chat-square-text me-2"></i><?= htmlspecialchars($TEXT['forum_index_title'] ?? 'Community Forum') ?></h1>
        <p><?= htmlspecialchars($TEXT['forum_index_subtitle'] ?? 'Discuss, ask, share — and meet the rest of the realm.') ?></p>
    </div>

    <?php $fo_recent = forum_recent_threads($pdo_auth, 6); ?>
    <?php if (!empty($fo_recent)): ?>
    <style>
    .fo-recent { background:linear-gradient(145deg,#15151f,#0e0e17); border:1px solid rgba(var(--btn-bg-rgb), .3); border-radius:10px; padding:1rem 1.2rem; margin-bottom:1.6rem; }
    .fo-recent-h { color:var(--accent); font-weight:700; font-size:1rem; margin-bottom:.5rem; }
    .fo-recent-row { display:flex; align-items:center; gap:.85rem; padding:.55rem .35rem; border-top:1px solid rgba(var(--btn-bg-rgb), .14); text-decoration:none; color:inherit; transition:background .12s ease; }
    .fo-recent-row:first-of-type { border-top:none; }
    .fo-recent-row:hover { background:rgba(var(--accent-rgb), .06); }
    .fo-recent-ic { width:30px; height:30px; flex-shrink:0; display:flex; align-items:center; justify-content:center; border-radius:50%; background:linear-gradient(145deg,#1a1a2e,#12121f); border:1px solid rgba(var(--btn-bg-rgb), .3); color:var(--accent); font-size:.9rem; }
    .fo-recent-main { flex:1; min-width:0; }
    .fo-recent-title { display:block; color:#dee2e6; font-weight:600; font-size:.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fo-recent-row:hover .fo-recent-title { color:#fff; }
    .fo-recent-meta { display:block; color:#8899aa; font-size:.74rem; margin-top:.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fo-recent-when { text-align:right; flex-shrink:0; font-size:.72rem; line-height:1.35; }
    .fo-recent-when .by { display:block; color:var(--accent); font-weight:600; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .fo-recent-when .ago { display:block; color:#4a5568; }
    @media (max-width:560px){ .fo-recent-when .by { max-width:90px; } .fo-recent-meta { max-width:52vw; } }
    </style>
    <div class="fo-recent">
        <div class="fo-recent-h"><i class="bi bi-activity me-2"></i><?= htmlspecialchars($TEXT['forum_recent_title'] ?? 'Latest activity') ?></div>
        <?php foreach ($fo_recent as $r):
            $r_href = '/forum/' . rawurlencode($r['category_slug']) . '/' . rawurlencode($r['slug']);
            $r_when = fp_relative_time($r['last_reply_at'] ?: $r['created_at'], $TEXT);
            $r_who  = $r['last_reply_by'] ?: $r['author_name'];
        ?>
        <a class="fo-recent-row" href="<?= htmlspecialchars($r_href, ENT_QUOTES) ?>">
            <span class="fo-recent-ic"><i class="bi <?= htmlspecialchars($r['category_icon'] ?: 'bi-chat-square-text') ?>"></i></span>
            <span class="fo-recent-main">
                <span class="fo-recent-title"><?= htmlspecialchars($r['title']) ?></span>
                <span class="fo-recent-meta"><?= htmlspecialchars($TEXT['forum_recent_in'] ?? 'in') ?> <?= htmlspecialchars($r['category_name']) ?> &middot; <?= (int)$r['reply_count'] ?> <?= htmlspecialchars($TEXT['forum_replies'] ?? 'replies') ?></span>
            </span>
            <span class="fo-recent-when">
                <span class="by"><?= htmlspecialchars($r_who) ?></span>
                <span class="ago"><?= htmlspecialchars($r_when) ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($categories)): ?>
        <div class="text-center py-5" style="color:#4a5568">
            <i class="bi bi-inbox" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.4"></i>
            <p><?= htmlspecialchars($TEXT['forum_index_empty'] ?? 'No categories yet. The admin needs to create one.') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $c):
            $href = '/forum/' . rawurlencode($c['slug']);
        ?>
            <a class="fo-cat-card" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">
                <div class="fo-cat-icon"><i class="bi <?= htmlspecialchars($c['icon'] ?: 'bi-chat-square-text') ?>"></i></div>
                <div class="fo-cat-body">
                    <div class="fo-cat-name">
                        <?= htmlspecialchars($c['name']) ?>
                        <?php if (!empty($c['admin_only'])): ?>
                            <span style="margin-left:.5rem;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--accent);background:rgba(var(--accent-rgb), .14);border:1px solid rgba(var(--accent-rgb), .35);padding:.12rem .5rem;border-radius:10px;vertical-align:middle">
                                <i class="bi bi-megaphone-fill me-1"></i><?= htmlspecialchars($TEXT['forum_badge_announce'] ?? 'Announcements') ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($c['description'])): ?>
                        <div class="fo-cat-desc"><?= htmlspecialchars($c['description']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fo-cat-stats">
                    <div><span class="fo-stat-count"><?= (int)$c['thread_count'] ?></span> <?= htmlspecialchars($TEXT['forum_threads'] ?? 'threads') ?></div>
                    <?php if (!empty($c['latest_title'])): ?>
                        <div class="latest-title mt-1" title="<?= htmlspecialchars($c['latest_title'], ENT_QUOTES) ?>"><?= htmlspecialchars($c['latest_title']) ?></div>
                        <div style="font-size:.74rem;color:#4a5568"><?= htmlspecialchars($c['latest_by'] ?: '') ?> &middot; <?= htmlspecialchars(fp_relative_time($c['latest_at'], $TEXT)) ?></div>
                    <?php else: ?>
                        <div class="mt-1" style="color:#4a5568;font-size:.78rem">—</div>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
