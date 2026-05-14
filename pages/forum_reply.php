<?php
/**
 * Forum: reply POST handler. The form lives inline at the bottom of the
 * thread detail page (pages/forum.php).
 *
 * URL: /forum/reply  (POST only)
 * Body: csrf_token, thread_id, body
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forum');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');
$gm_level = (int)($_SESSION['gm_level'] ?? 0);

$thread_id = (int)($_POST['thread_id'] ?? 0);
$thread    = null;
if ($thread_id > 0) {
    $stmt = $pdo_auth->prepare(
        "SELECT t.*, c.slug AS category_slug
         FROM forum_threads t
         JOIN forum_categories c ON c.id = t.category_id
         WHERE t.id = :id AND t.status = 'published'
         LIMIT 1"
    );
    $stmt->execute(['id' => $thread_id]);
    $thread = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$thread) {
    header('Location: /forum');
    exit;
}

$back = '/forum/' . rawurlencode($thread['category_slug']) . '/' . rawurlencode($thread['slug']);

$settings = forum_settings_get($pdo_auth);
[$can_post, $reason] = forum_can_user_post($pdo_auth, $user_id, $gm_level, $settings, $thread);
if (!$can_post) {
    header('Location: ' . $back . '?reply_error=' . urlencode($reason));
    exit;
}

if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: ' . $back . '?reply_error=csrf');
    exit;
}

$body = trim((string)($_POST['body'] ?? ''));
if ($body === '') {
    header('Location: ' . $back . '?reply_error=empty');
    exit;
}
if (mb_strlen($body) > 50000) {
    header('Location: ' . $back . '?reply_error=too_long');
    exit;
}

// Anti-spam cooldown (GM 9+ bypasses)
if ($gm_level < 9) {
    [$ok, $wait] = forum_user_can_post_now($pdo_auth, $user_id, 30);
    if (!$ok) {
        header('Location: ' . $back . '?reply_error=cooldown&wait=' . (int)$wait);
        exit;
    }
}

$auto = forum_should_auto_approve($pdo_auth, $user_id, $gm_level, $settings);
$result = forum_create_reply($pdo_auth, (int)$thread['id'], $user_id, $username, $body, $auto);
if (!$result) {
    header('Location: ' . $back . '?reply_error=server');
    exit;
}

log_admin_action(
    $pdo_auth, $user_id, $username, 'forum_reply_create',
    "thread:{$thread['id']} (post:{$result['post_id']})",
    "status:{$result['status']}", null
);

if ($result['status'] === 'published') {
    // Land directly on the new reply via anchor
    header('Location: ' . $back . '#post-' . (int)$result['post_id']);
} else {
    header('Location: ' . $back . '?reply_pending=1');
}
exit;
