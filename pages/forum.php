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
        <h2 style="color:#c8a96e"><?= htmlspecialchars($TEXT['forum_disabled_title'] ?? 'Forum is currently disabled') ?></h2>
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
    background: linear-gradient(135deg, rgba(139,69,19,.25) 0%, rgba(10,10,20,.85) 60%);
    border: 1px solid rgba(139,69,19,.4);
    border-radius: 12px;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.5rem;
}
.fo-hero h1 {
    color: #c8a96e;
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
.fo-crumb a { color: #c8a96e; text-decoration: none; }
.fo-crumb a:hover { color: #fff; }

/* Category card on the /forum index */
.fo-cat-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    background: linear-gradient(145deg, #15151f, #0e0e17);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 10px;
    padding: 1.1rem 1.4rem;
    margin-bottom: 1rem;
    text-decoration: none;
    color: inherit;
    transition: border-color .15s ease, transform .15s ease;
}
.fo-cat-card:hover { border-color: rgba(200,169,110,.6); transform: translateY(-1px); color: inherit; }
.fo-cat-icon {
    width: 56px; height: 56px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(145deg, #1a1a2e, #12121f);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 50%;
    color: #c8a96e;
    font-size: 1.5rem;
    flex-shrink: 0;
}
.fo-cat-body { flex: 1; min-width: 0; }
.fo-cat-name { color: #c8a96e; font-weight: 700; font-size: 1.1rem; }
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
    border: 1px solid rgba(139,69,19,.2);
    border-radius: 8px;
    padding: .85rem 1.1rem;
    margin-bottom: .55rem;
    transition: border-color .15s ease;
}
.fo-thread-row:hover { border-color: rgba(200,169,110,.4); }
.fo-thread-row .wl-avatar { width: 40px !important; height: 40px !important; font-size: 16px !important; border-width: 1.5px !important; }
.fo-thread-main { flex: 1; min-width: 0; }
.fo-thread-title {
    color: #c8a96e;
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
.fo-badge-sticky { background: rgba(240,192,64,.15); color: #f0c040; border: 1px solid rgba(240,192,64,.3); }
.fo-badge-locked { background: rgba(231,76,60,.15); color: #f87e8a; border: 1px solid rgba(231,76,60,.3); }
.fo-thread-stats {
    text-align: right;
    color: #8899aa;
    font-size: .78rem;
    flex-shrink: 0;
    min-width: 170px;
}
.fo-thread-stats .latest-row { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; display: inline-block; }
.fo-stat-count { color:#c8a96e; font-weight:600; }

/* Post (OP + replies on the thread page) */
.fo-post {
    display: flex;
    gap: 1.2rem;
    background: linear-gradient(145deg, #15151f, #0e0e17);
    border: 1px solid rgba(139,69,19,.3);
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
.fo-post-author { color: #c8a96e; font-weight: 700; margin-top: .55rem; font-size: .9rem; word-break: break-word; }
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
    border-bottom: 1px solid rgba(139,69,19,.15);
    padding-bottom: .55rem;
    margin-bottom: .85rem;
}
.fo-post-edited {
    color: #4a5568;
    font-size: .75rem;
    font-style: italic;
    margin-top: .8rem;
    padding-top: .55rem;
    border-top: 1px dashed rgba(139,69,19,.15);
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
.fo-post-body h4 { color: #c8a96e; margin-top: 1.2rem; }
.fo-post-body a { color: #69CCF0; }
.fo-post-body code { background: rgba(255,255,255,.06); padding: .1rem .35rem; border-radius: 3px; }
.fo-post-body pre { background: rgba(0,0,0,.4); padding: .8rem 1rem; border-radius: 6px; overflow-x: auto; }
.fo-post-body img { max-width: 100%; height: auto; border-radius: 6px; }
.fo-post-body blockquote { border-left: 3px solid #c8a96e; padding-left: 1rem; color: #8899aa; margin: 1rem 0; }
.fo-post-body ul, .fo-post-body ol { padding-left: 1.5rem; }
.fo-post-body table { border-collapse: collapse; margin: .6rem 0; }
.fo-post-body th, .fo-post-body td { border: 1px solid rgba(139,69,19,.3); padding: .35rem .6rem; }

/* Reuse the news-pager styling via .news-pager class (already defined on /news,
   but the forum page may render before that CSS, so duplicate the minimum.) */
.news-pager .page-link {
    background: #1a1a2e;
    color: #c8a96e;
    border-color: rgba(139,69,19,.3);
    min-width: 2.4rem;
    text-align: center;
}
.news-pager .page-link:hover { background:#2a1f10; color:#fff; border-color:#c8a96e; }
.news-pager .page-item.active .page-link { background:#8B4513; border-color:#A0522D; color:#fff; font-weight:600; }
.news-pager .page-item.disabled .page-link { background:#12121f; color:#4a5568; border-color:rgba(139,69,19,.15); cursor:not-allowed; }

/* Admin-disabled banner shown to GMs previewing while the public toggle is off */
.fo-preview-banner {
    background: rgba(240,192,64,.1);
    border: 1px solid rgba(240,192,64,.3);
    color: #f0c040;
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
    $thread = forum_thread_get_by_slug($pdo_auth, $thread_slug);
    if (!$thread || $thread['category_slug'] !== $cat_slug) {
        http_response_code(404);
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <main class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
            <h2 style="color:#c8a96e"><?= htmlspecialchars($TEXT['forum_thread_not_found'] ?? 'Thread not found') ?></h2>
            <p style="color:#8899aa"><?= htmlspecialchars($TEXT['forum_thread_not_found_hint'] ?? 'This thread does not exist or is no longer published.') ?></p>
            <a href="/forum" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') ?></a>
        </main>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    // Increment view count (don't count the author refreshing their own post repeatedly —
    // a session-based de-dupe would be nicer, but Phase 6 polish will handle that)
    forum_thread_increment_views($pdo_auth, (int)$thread['id']);

    $per_page = 20;
    $total    = forum_posts_count_in_thread($pdo_auth, (int)$thread['id']);
    $pages    = max(1, (int)ceil($total / $per_page));
    if ($page > $pages) $page = $pages;
    $posts = forum_posts_in_thread_for_user($pdo_auth, (int)$thread['id'], $user_id ?: null, $page, $per_page);
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

        <div class="fo-hero">
            <h1>
                <?php if ($thread['is_sticky']): ?><span class="fo-badge fo-badge-sticky"><i class="bi bi-pin-angle-fill"></i> <?= htmlspecialchars($TEXT['forum_sticky'] ?? 'Sticky') ?></span><?php endif; ?>
                <?php if ($thread['is_locked']): ?><span class="fo-badge fo-badge-locked"><i class="bi bi-lock-fill"></i> <?= htmlspecialchars($TEXT['forum_locked'] ?? 'Locked') ?></span><?php endif; ?>
                <?= htmlspecialchars($thread['title']) ?>
            </h1>
            <p>
                <i class="bi bi-eye me-1"></i><?= (int)$thread['view_count'] ?>
                &middot;
                <i class="bi bi-chat-left-text me-1"></i><?= (int)$thread['reply_count'] ?> <?= htmlspecialchars($TEXT['forum_replies'] ?? 'replies') ?>
            </p>
        </div>

        <?php foreach ($posts as $p):
            $is_pending = ($p['status'] === 'pending');
            $is_mine    = ($user_id > 0 && (int)$p['author_id'] === $user_id);
            $can_edit   = ($is_admin || $is_mine);
        ?>
            <article class="fo-post" id="post-<?= (int)$p['id'] ?>" <?= $is_pending ? 'style="border-color: rgba(240,192,64,.35); background: linear-gradient(145deg, #1f1b14, #14110b);"' : '' ?>>
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
                                <span style="margin-left:.6rem;padding:.1rem .5rem;background:rgba(240,192,64,.15);color:#f0c040;border:1px solid rgba(240,192,64,.35);border-radius:10px;font-size:.7rem;text-transform:uppercase;letter-spacing:.5px"><i class="bi bi-hourglass-split"></i> <?= htmlspecialchars($TEXT['forum_pending_pill'] ?? 'Awaiting approval') ?></span>
                            <?php endif; ?>
                        </span>
                        <span style="display:flex;align-items:center;gap:.6rem">
                            <?php if ($can_edit): ?>
                                <a href="/forum/edit/<?= (int)$p['id'] ?>" style="color:#8899aa;text-decoration:none;font-size:.78rem" title="<?= htmlspecialchars($TEXT['forum_edit_link'] ?? 'Edit') ?>"><i class="bi bi-pencil"></i> <?= htmlspecialchars($TEXT['forum_edit_link'] ?? 'Edit') ?></a>
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
            <div style="margin-top:1.5rem;padding:.85rem 1rem;background:rgba(240,192,64,.1);border:1px solid rgba(240,192,64,.3);border-radius:6px;color:#f0c040;font-size:.92rem">
                <i class="bi bi-hourglass-split me-1"></i><?= htmlspecialchars($TEXT['forum_reply_pending_msg'] ?? 'Your reply was submitted and is waiting for admin approval.') ?>
            </div>
        <?php endif; ?>
        <?php if ($reply_error !== ''):
            $msg = match ($reply_error) {
                'csrf'           => $TEXT['forum_reply_err_csrf']   ?? 'Session expired. Please try again.',
                'empty'          => $TEXT['forum_reply_err_empty']  ?? 'Reply cannot be empty.',
                'too_long'       => $TEXT['forum_reply_err_long']   ?? 'Reply too long.',
                'banned'         => $TEXT['forum_banned_hint']      ?? 'You are banned from posting in the forum.',
                'locked'         => $TEXT['forum_locked_hint']      ?? 'This thread is locked. No new replies.',
                'forum_disabled' => $TEXT['forum_disabled_hint']    ?? 'The forum is currently disabled.',
                'not_logged_in'  => $TEXT['forum_login_to_reply']   ?? 'Log in to reply.',
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
            .EasyMDEContainer .editor-toolbar { background:#15151f; border:1px solid rgba(139,69,19,.3); border-bottom:none; }
            .EasyMDEContainer .editor-toolbar button { color:#c8a96e !important; border-color:transparent !important; }
            .EasyMDEContainer .editor-toolbar button:hover, .EasyMDEContainer .editor-toolbar button.active { background:#2a1f10 !important; border-color:rgba(139,69,19,.3) !important; color:#fff !important; }
            .EasyMDEContainer .CodeMirror { background:#0a0a0f; color:#dee2e6; border:1px solid rgba(139,69,19,.3); border-top:none; font-family:'SFMono-Regular',Consolas,monospace; font-size:.92rem; line-height:1.55; min-height:200px; }
            .EasyMDEContainer .editor-preview, .EasyMDEContainer .editor-preview-side { background:#0a0a0f; color:rgba(255,255,255,.85); border-color:rgba(139,69,19,.3); line-height:1.7; }
            .EasyMDEContainer .editor-preview h1,.EasyMDEContainer .editor-preview h2,.EasyMDEContainer .editor-preview h3, .EasyMDEContainer .editor-preview-side h1, .EasyMDEContainer .editor-preview-side h2, .EasyMDEContainer .editor-preview-side h3 { color:#c8a96e; }
            .EasyMDEContainer .editor-statusbar { color:#4a5568; border:1px solid rgba(139,69,19,.15); border-top:none; background:#12121f; padding:.35rem .8rem; font-size:.75rem; }
            .EasyMDEContainer .editor-toolbar.fullscreen, .EasyMDEContainer .CodeMirror-fullscreen, .EasyMDEContainer .editor-preview-side { z-index:1050; }
            body:has(.editor-toolbar.fullscreen) #mainNavbar, body:has(.editor-preview-side.fullscreen) #mainNavbar, body.easymde-fullscreen #mainNavbar { display:none; }
            .EasyMDEContainer .editor-toolbar.fullscreen { display:flex; flex-wrap:wrap; align-items:center; height:auto; min-height:50px; padding:0 4px; }
            .EasyMDEContainer .editor-toolbar.fullscreen > * { float:none !important; margin:0 !important; flex:0 0 auto; }
            </style>
            <div style="margin-top:2rem">
                <h4 style="color:#c8a96e;font-size:1rem;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:.8rem">
                    <i class="bi bi-reply me-2"></i><?= htmlspecialchars($TEXT['forum_post_reply'] ?? 'Post a reply') ?>
                </h4>
                <?php
                $auto = forum_should_auto_approve($pdo_auth, $user_id, $gm_level, $settings);
                if (!$auto):
                    $need = max(0, (int)$settings['auto_approve_threshold'] - forum_user_approved_post_count($pdo_auth, $user_id));
                ?>
                    <div style="background:rgba(240,192,64,.08);border:1px solid rgba(240,192,64,.25);color:#f0c040;padding:.55rem .85rem;border-radius:6px;font-size:.85rem;margin-bottom:.8rem">
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
                        <button type="submit" style="padding:.55rem 1.1rem;border-radius:4px;border:1px solid #A0522D;background:#8B4513;color:#fff;cursor:pointer;font-family:inherit">
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
            <div style="margin-top:2rem;padding:1rem 1.2rem;background:#0e0e17;border:1px solid rgba(139,69,19,.25);border-radius:8px;color:#8899aa;text-align:center;font-size:.9rem">
                <?php
                $msg = match ($reply_reason) {
                    'not_logged_in'  => sprintf($TEXT['forum_login_to_reply_link'] ?? 'Please <a href="%s" style="color:#c8a96e">log in</a> to reply.', '/login'),
                    'banned'         => $TEXT['forum_banned_hint']   ?? 'You are banned from posting in the forum.',
                    'locked'         => '<i class="bi bi-lock-fill me-1"></i>' . htmlspecialchars($TEXT['forum_locked_hint'] ?? 'This thread is locked. No new replies.'),
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
            <h2 style="color:#c8a96e"><?= htmlspecialchars($TEXT['forum_cat_not_found'] ?? 'Category not found') ?></h2>
            <a href="/forum" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['forum_back_to_index'] ?? 'Back to forum') ?></a>
        </main>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    $per_page = 20;
    $total    = forum_threads_count_in_category($pdo_auth, (int)$category['id']);
    $pages    = max(1, (int)ceil($total / $per_page));
    if ($page > $pages) $page = $pages;
    $threads = forum_threads_in_category($pdo_auth, (int)$category['id'], $page, $per_page);

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
            <div style="margin-bottom:1rem;padding:.7rem 1rem;background:rgba(240,192,64,.1);border:1px solid rgba(240,192,64,.3);border-radius:6px;color:#f0c040;font-size:.92rem">
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
                [$can_post_here, $post_reason] = forum_can_user_post($pdo_auth, $user_id ?: null, $gm_level, $settings, null);
                if ($can_post_here):
                ?>
                    <a href="/forum/new/<?= htmlspecialchars(rawurlencode($category['slug']), ENT_QUOTES) ?>"
                       style="display:inline-block;margin-top:.6rem;padding:.45rem 1rem;background:#8B4513;color:#fff;border:1px solid #A0522D;border-radius:4px;text-decoration:none;font-size:.85rem">
                        <i class="bi bi-plus-lg me-1"></i><?= htmlspecialchars($TEXT['forum_new_thread_btn'] ?? 'New Thread') ?>
                    </a>
                <?php elseif ($post_reason === 'not_logged_in'): ?>
                    <a href="/login" style="display:inline-block;margin-top:.6rem;padding:.45rem 1rem;background:transparent;color:#c8a96e;border:1px solid rgba(200,169,110,.4);border-radius:4px;text-decoration:none;font-size:.85rem">
                        <i class="bi bi-box-arrow-in-right me-1"></i><?= htmlspecialchars($TEXT['forum_login_to_post'] ?? 'Log in to post') ?>
                    </a>
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
                <div class="fo-thread-row">
                    <?= render_avatar((string)$t['author_name'], $av, 40) ?>
                    <div class="fo-thread-main">
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
                    <div class="fo-cat-name"><?= htmlspecialchars($c['name']) ?></div>
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
