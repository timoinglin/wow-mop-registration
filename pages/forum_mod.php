<?php
/**
 * Forum inline-moderation POST endpoint (GM 9+ only).
 *
 * URL: /forum/mod  (POST)
 *
 * Body:
 *   csrf_token = (session token)
 *   action     = approve_post | approve_thread | delete_post |
 *                delete_thread | toggle_lock | toggle_sticky
 *   post_id    = (when applicable)
 *   thread_id  = (when applicable)
 *
 * Always redirects back to where the GM came from (Referer fallback to
 * /forum) and appends a small ?mod=… status flag so the UI can flash a
 * toast.
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm->fetchColumn() ?: 0);
if ($gm_level < 9) { header('Location: /forum'); exit; }
$_SESSION['gm_level'] = $gm_level;

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /forum'); exit; }
if (!validate_csrf_token($_POST['csrf_token'] ?? null)) { header('Location: /forum?mod=csrf'); exit; }

$action     = $_POST['action'] ?? '';
$post_id    = (int)($_POST['post_id'] ?? 0);
$thread_id  = (int)($_POST['thread_id'] ?? 0);

// Default redirect → Referer when same-origin, else /forum
$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
$host = $_SERVER['HTTP_HOST'] ?? '';
$same_origin = $ref !== '' && (strpos($ref, '://' . $host) !== false);
$back = $same_origin ? $ref : '/forum';
$append = function (string $url, string $flag): string {
    // Strip any prior mod= flag so the toast doesn't pile up. Important to
    // do this BEFORE picking the separator — otherwise we see an existing
    // `?mod=…`, pick `&`, then strip the `?` and end up gluing on `…&mod=`
    // with no `?` in front (which Apache treats as part of the path → 404).
    $url = preg_replace('/([?&])mod=[^&#]*(&|$)/', '$1', $url);
    $url = rtrim($url, '?&');
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . 'mod=' . urlencode($flag);
};

switch ($action) {
    // ─── Approve ────────────────────────────────────────────────────────────
    case 'approve_post':
        if ($post_id > 0 && forum_approve_post($pdo_auth, $post_id)) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_post_approve', "post:$post_id", 'inline', null);
            header('Location: ' . $append($back, 'approved'));
            exit;
        }
        header('Location: ' . $append($back, 'err'));
        exit;

    case 'approve_thread':
        if ($thread_id > 0 && forum_approve_thread($pdo_auth, $thread_id)) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_thread_approve', "thread:$thread_id", 'inline', null);
            header('Location: ' . $append($back, 'approved'));
            exit;
        }
        header('Location: ' . $append($back, 'err'));
        exit;

    // ─── Delete ─────────────────────────────────────────────────────────────
    case 'delete_post':
        if ($post_id > 0) {
            // Look up so we can build the redirect target before deletion
            $info_q = $pdo_auth->prepare(
                "SELECT p.is_op, t.slug AS thread_slug, c.slug AS category_slug
                 FROM web_forum_posts p
                 JOIN web_forum_threads t ON t.id = p.thread_id
                 JOIN web_forum_categories c ON c.id = t.category_id
                 WHERE p.id = :id"
            );
            $info_q->execute(['id' => $post_id]);
            $info = $info_q->fetch(PDO::FETCH_ASSOC);

            $res = forum_delete_post($pdo_auth, $post_id);
            if ($res !== null) {
                log_admin_action(
                    $pdo_auth, $admin_id, $admin_name,
                    $res['deleted_thread'] ? 'forum_thread_delete' : 'forum_post_delete',
                    $res['deleted_thread'] ? "thread:{$res['thread_id']} (via OP delete)" : "post:$post_id",
                    null, null
                );
                if ($res['deleted_thread'] && $info) {
                    // Bounce back to the category since the thread is gone
                    header('Location: ' . $append('/forum/' . rawurlencode($info['category_slug']), 'deleted'));
                } else {
                    header('Location: ' . $append($back, 'deleted'));
                }
                exit;
            }
        }
        header('Location: ' . $append($back, 'err'));
        exit;

    case 'delete_thread':
        if ($thread_id > 0) {
            $info_q = $pdo_auth->prepare(
                "SELECT t.title, c.slug AS category_slug
                 FROM web_forum_threads t JOIN web_forum_categories c ON c.id = t.category_id
                 WHERE t.id = :id"
            );
            $info_q->execute(['id' => $thread_id]);
            $info = $info_q->fetch(PDO::FETCH_ASSOC);
            if ($info && forum_delete_thread($pdo_auth, $thread_id)) {
                log_admin_action(
                    $pdo_auth, $admin_id, $admin_name, 'forum_thread_delete',
                    "thread:$thread_id (" . ($info['title'] ?? '?') . ")", null, null
                );
                header('Location: ' . $append('/forum/' . rawurlencode($info['category_slug']), 'deleted'));
                exit;
            }
        }
        header('Location: ' . $append($back, 'err'));
        exit;

    // ─── Lock / Sticky toggles ──────────────────────────────────────────────
    case 'toggle_lock':
        if ($thread_id > 0) {
            $now = forum_toggle_lock($pdo_auth, $thread_id);
            if ($now !== null) {
                log_admin_action(
                    $pdo_auth, $admin_id, $admin_name,
                    $now ? 'forum_thread_lock' : 'forum_thread_unlock',
                    "thread:$thread_id", null, null
                );
                header('Location: ' . $append($back, $now ? 'locked' : 'unlocked'));
                exit;
            }
        }
        header('Location: ' . $append($back, 'err'));
        exit;

    case 'toggle_sticky':
        if ($thread_id > 0) {
            $now = forum_toggle_sticky($pdo_auth, $thread_id);
            if ($now !== null) {
                log_admin_action(
                    $pdo_auth, $admin_id, $admin_name,
                    $now ? 'forum_thread_sticky' : 'forum_thread_unsticky',
                    "thread:$thread_id", null, null
                );
                header('Location: ' . $append($back, $now ? 'stickied' : 'unstickied'));
                exit;
            }
        }
        header('Location: ' . $append($back, 'err'));
        exit;
}

header('Location: ' . $back);
exit;
