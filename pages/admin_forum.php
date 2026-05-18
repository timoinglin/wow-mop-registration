<?php
/**
 * Admin Forum config — three-panel page (GM 9+ only):
 *
 *   - Settings         enable/disable, auto-approve threshold
 *   - Categories       CRUD (one level, with Bootstrap-Icons icon)
 *   - Bans             list + add (by username) + remove
 *
 * All actions are audit-logged. POST handlers redirect with a query-string
 * status so refresh doesn't re-submit (Post/Redirect/Get).
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';

// GM 9+ guard
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm->fetchColumn() ?: 0);
if ($gm_level < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $gm_level;

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Admin';

$errors  = [];
$success = '';

$redirect = function (string $key = '', ?string $val = null) {
    $u = '/admin_forum';
    if ($key !== '') $u .= '?' . urlencode($key) . '=' . urlencode($val !== null ? $val : '1');
    header('Location: ' . $u);
    exit;
};

// ─── POST handlers ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        // -- Settings --
        if ($action === 'save_settings') {
            $enabled   = !empty($_POST['enabled']);
            $threshold = (int)($_POST['auto_approve_threshold'] ?? 3);
            if (forum_settings_update($pdo_auth, $enabled, $threshold)) {
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_settings_update', null,
                    'enabled=' . ($enabled ? 1 : 0) . ', threshold=' . $threshold, null);
                $redirect('saved', 'settings');
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not save settings.';
        }

        // -- Category create / update --
        elseif ($action === 'save_category') {
            $cid   = (int)($_POST['id'] ?? 0);
            $name  = trim((string)($_POST['name'] ?? ''));
            $desc  = trim((string)($_POST['description'] ?? ''));
            $icon  = trim((string)($_POST['icon'] ?? 'bi-chat-square-text'));
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $slug  = trim((string)($_POST['slug'] ?? ''));
            $admin_only    = isset($_POST['admin_only']) ? 1 : 0;
            $allow_replies = isset($_POST['allow_replies']) ? 1 : 0;

            if ($name === '')               $errors[] = $TEXT['forum_err_cat_name']  ?? 'Category name is required.';
            if (mb_strlen($name) > 120)     $errors[] = $TEXT['forum_err_cat_name_long'] ?? 'Name too long.';
            if (mb_strlen($desc) > 500)     $errors[] = $TEXT['forum_err_cat_desc_long'] ?? 'Description too long.';
            if ($icon !== '' && !preg_match('/^bi-[a-z0-9-]+$/i', $icon)) {
                $errors[] = $TEXT['forum_err_cat_icon'] ?? 'Icon must be a Bootstrap-Icons class (e.g. bi-megaphone).';
            }

            if (empty($errors)) {
                $newId = forum_category_save($pdo_auth, $cid ?: null, $name, $desc, $icon, $sort, $slug, $admin_only, $allow_replies);
                if ($newId) {
                    log_admin_action($pdo_auth, $admin_id, $admin_name,
                        $cid ? 'forum_category_update' : 'forum_category_create',
                        "id:$newId ($name)", null, null);
                    $redirect('saved', 'category');
                }
                $errors[] = $TEXT['forum_err_save'] ?? 'Could not save category.';
            }
        }

        // -- Category delete --
        elseif ($action === 'delete_category') {
            $cid = (int)($_POST['id'] ?? 0);
            if ($cid > 0) {
                $cat = forum_category_get($pdo_auth, $cid);
                if (forum_category_delete($pdo_auth, $cid)) {
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_category_delete',
                        "id:$cid (" . ($cat['name'] ?? '?') . ")", null, null);
                    $redirect('saved', 'deleted');
                }
                $errors[] = $TEXT['forum_err_save'] ?? 'Could not delete category.';
            }
        }

        // -- Ban add --
        elseif ($action === 'ban') {
            $uname    = trim((string)($_POST['username'] ?? ''));
            $reason   = trim((string)($_POST['reason'] ?? ''));
            $expires  = trim((string)($_POST['expires_at'] ?? ''));

            $acct = forum_find_account_by_username($pdo_auth, $uname);
            if (!$acct) {
                $errors[] = $TEXT['forum_err_user_not_found'] ?? 'No account with that username.';
            } else {
                $expires_at = $expires !== '' ? date('Y-m-d H:i:s', strtotime($expires)) : null;
                [$ok, $err] = forum_ban_user($pdo_auth, (int)$acct['id'], $acct['username'], $admin_name, $reason ?: null, $expires_at);
                if (!$ok) {
                    if ($err === 'cannot_ban_admin') {
                        $errors[] = $TEXT['forum_err_ban_admin'] ?? 'You cannot ban another admin (GM 9+).';
                    } else {
                        $errors[] = $TEXT['forum_err_save'] ?? 'Could not ban user.';
                    }
                } else {
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_ban_create', $acct['username'],
                        'reason=' . ($reason ?: 'n/a') . ', expires=' . ($expires_at ?: 'permanent'), null);
                    $redirect('saved', 'banned');
                }
            }
        }

        // -- Approve / reject pending content --
        elseif ($action === 'approve_thread') {
            $tid = (int)($_POST['thread_id'] ?? 0);
            if ($tid > 0 && forum_approve_thread($pdo_auth, $tid)) {
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_thread_approve', "thread:$tid", null, null);
                $redirect('saved', 'approved');
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not approve.';
        }
        elseif ($action === 'reject_thread') {
            $tid = (int)($_POST['thread_id'] ?? 0);
            if ($tid > 0) {
                $info = $pdo_auth->prepare("SELECT title, author_name FROM forum_threads WHERE id = :id");
                $info->execute(['id' => $tid]);
                $row = $info->fetch(PDO::FETCH_ASSOC);
                if (forum_reject_thread($pdo_auth, $tid)) {
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_thread_reject',
                        "thread:$tid (" . ($row['title'] ?? '?') . ")",
                        'author:' . ($row['author_name'] ?? '?'), null);
                    $redirect('saved', 'rejected');
                }
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not reject.';
        }
        elseif ($action === 'approve_post') {
            $pid = (int)($_POST['post_id'] ?? 0);
            if ($pid > 0 && forum_approve_post($pdo_auth, $pid)) {
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_post_approve', "post:$pid", null, null);
                $redirect('saved', 'approved');
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not approve.';
        }
        elseif ($action === 'reject_post') {
            $pid = (int)($_POST['post_id'] ?? 0);
            if ($pid > 0) {
                $info = $pdo_auth->prepare("SELECT thread_id, author_name FROM forum_posts WHERE id = :id");
                $info->execute(['id' => $pid]);
                $row = $info->fetch(PDO::FETCH_ASSOC);
                if (forum_reject_post($pdo_auth, $pid)) {
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_post_reject',
                        "post:$pid (thread:" . ($row['thread_id'] ?? '?') . ')',
                        'author:' . ($row['author_name'] ?? '?'), null);
                    $redirect('saved', 'rejected');
                }
            }
            $errors[] = $TEXT['forum_err_save'] ?? 'Could not reject.';
        }
        // -- Ban remove --
        elseif ($action === 'unban') {
            $aid = (int)($_POST['account_id'] ?? 0);
            if ($aid > 0) {
                $uname = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
                $uname->execute(['id' => $aid]);
                $u = $uname->fetchColumn() ?: ('id:' . $aid);
                if (forum_unban_user($pdo_auth, $aid)) {
                    log_admin_action($pdo_auth, $admin_id, $admin_name, 'forum_ban_remove', $u, null, null);
                    $redirect('saved', 'unbanned');
                }
            }
        }
    }
}

// ─── GET: render ────────────────────────────────────────────────────────────
$settings      = forum_settings_get($pdo_auth);
$categories    = forum_categories_list($pdo_auth);
$bans          = forum_bans_list($pdo_auth);
$pending_thrs  = forum_pending_threads_list($pdo_auth);
$pending_posts = forum_pending_posts_list($pdo_auth);
$edit_cat_id   = (int)($_GET['edit_cat'] ?? 0);
$edit_cat      = $edit_cat_id > 0 ? forum_category_get($pdo_auth, $edit_cat_id) : null;

$page_title = ($TEXT['forum_admin_title'] ?? 'Forum Configuration') . ' — ' . ($config['site']['title'] ?? 'WoW');

require_once __DIR__ . '/../templates/header.php';
$csrf = generate_csrf_token();

// Saved-flash from query-string
$flash = '';
if (isset($_GET['saved'])) {
    $flash = match ($_GET['saved']) {
        'settings'  => $TEXT['forum_saved_settings']  ?? 'Settings saved.',
        'category'  => $TEXT['forum_saved_category']  ?? 'Category saved.',
        'deleted'   => $TEXT['forum_saved_deleted']   ?? 'Category deleted.',
        'banned'    => $TEXT['forum_saved_banned']    ?? 'User banned from forum.',
        'unbanned'  => $TEXT['forum_saved_unbanned']  ?? 'User unbanned.',
        'approved'  => $TEXT['forum_saved_approved']  ?? 'Post approved and published.',
        'rejected'  => $TEXT['forum_saved_rejected']  ?? 'Post rejected and removed.',
        default     => '',
    };
}

require_once __DIR__ . '/../includes/markdown.php';
?>

<style>
.fa-wrap { padding-top:120px; padding-bottom:3rem; }
.fa-card {
    background: linear-gradient(145deg,#15151f,#0e0e17);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.fa-card h2 {
    color:var(--accent);
    font-size:1.1rem;
    text-transform:uppercase;
    letter-spacing:1px;
    font-weight:700;
    margin:0 0 1rem;
    padding-bottom:.6rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), .2);
}
.fa-input, .fa-select, .fa-textarea {
    width:100%; padding:.55rem .75rem; background:#0a0a0f;
    border:1px solid rgba(var(--btn-bg-rgb), .3); border-radius:4px; color:#fff;
    font-size:.92rem; font-family:inherit;
}
.fa-input:focus, .fa-select:focus, .fa-textarea:focus { outline:none; border-color:var(--accent); }
.fa-label { display:block; font-size:.72rem; color:#8899aa; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.25rem; }
.fa-btn {
    padding:.45rem 1rem; border-radius:4px; border:1px solid; cursor:pointer;
    font-size:.85rem; text-decoration:none; display:inline-block; transition:all .15s ease;
    font-family: inherit;
}
.fa-btn-primary { background:var(--btn-bg); color:#fff; border-color:var(--btn-bg-hover); }
.fa-btn-primary:hover { background:var(--btn-bg-hover); }
.fa-btn-ghost { background:transparent; color:#8899aa; border-color:rgba(var(--btn-bg-rgb), .3); }
.fa-btn-ghost:hover { color:var(--accent); border-color:var(--accent); }
.fa-btn-danger { background:#5a1f1f; color:#fff; border-color:#7a2a2a; }
.fa-btn-danger:hover { background:#7a2a2a; }
.fa-tbl { width:100%; border-collapse:collapse; color:#dee2e6; font-size:.9rem; }
.fa-tbl th { text-align:left; padding:.6rem .7rem; border-bottom:1px solid rgba(var(--btn-bg-rgb), .3); color:#8899aa; font-weight:600; text-transform:uppercase; font-size:.7rem; letter-spacing:.5px; }
.fa-tbl td { padding:.6rem .7rem; border-bottom:1px solid rgba(var(--btn-bg-rgb), .1); vertical-align:middle; }
.fa-tbl tr:hover td { background:rgba(var(--btn-bg-rgb), .05); }
.fa-pill { display:inline-block; padding:.15rem .55rem; border-radius:10px; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; }
.fa-pill-on { background:rgba(46,204,113,.15); color:#5dd87c; border:1px solid rgba(46,204,113,.3); }
.fa-pill-off { background:rgba(139,139,139,.15); color:#8899aa; border:1px solid rgba(139,139,139,.3); }
.fa-flash-ok { background:rgba(46,204,113,.1); border:1px solid rgba(46,204,113,.3); color:#5dd87c; padding:.7rem 1rem; border-radius:4px; margin-bottom:1rem; }
.fa-flash-err { background:rgba(231,76,60,.1); border:1px solid rgba(231,76,60,.3); color:#e74c3c; padding:.7rem 1rem; border-radius:4px; margin-bottom:1rem; }
.fa-icon-preview {
    display:inline-flex; width:32px; height:32px; align-items:center; justify-content:center;
    background:linear-gradient(145deg,#1a1a2e,#12121f); border:1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius:50%; color:var(--accent);
}
.toggle-row { display:flex; align-items:center; gap:.6rem; }
.toggle-switch { position:relative; display:inline-block; width:46px; height:24px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider {
    position:absolute; inset:0; cursor:pointer; background:#2a2a3a;
    transition:.2s; border-radius:24px; border:1px solid rgba(var(--btn-bg-rgb), .3);
}
.toggle-slider::before {
    position:absolute; content:''; height:18px; width:18px; left:2px; top:2px;
    background:#8899aa; transition:.2s; border-radius:50%;
}
input:checked + .toggle-slider { background: var(--btn-bg); border-color:var(--btn-bg-hover); }
input:checked + .toggle-slider::before { transform: translateX(22px); background:#fff; }
</style>

<div class="container fa-wrap">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <h1 style="color:var(--accent);margin:0;font-weight:700"><i class="bi bi-chat-square-text me-2"></i><?= htmlspecialchars($TEXT['forum_admin_title'] ?? 'Forum Configuration') ?></h1>
        <a href="/admin_dashboard" class="fa-btn fa-btn-ghost"><i class="bi bi-arrow-left me-1"></i><?= htmlspecialchars($TEXT['news_admin_back'] ?? 'Back to Admin') ?></a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="fa-flash-ok"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <div class="fa-flash-err"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <!-- ════════════ MODERATION QUEUE ════════════ -->
    <?php $pending_total = count($pending_thrs) + count($pending_posts); ?>
    <div class="fa-card" id="moderation-queue">
        <h2 style="display:flex;align-items:center;gap:.6rem">
            <i class="bi bi-hourglass-split"></i>
            <span><?= htmlspecialchars($TEXT['forum_queue_title'] ?? 'Moderation Queue') ?></span>
            <?php if ($pending_total > 0): ?>
                <span style="background:rgba(240,192,64,.18);color:#f0c040;border:1px solid rgba(240,192,64,.4);padding:.1rem .55rem;border-radius:10px;font-size:.72rem;letter-spacing:.5px"><?= $pending_total ?></span>
            <?php endif; ?>
        </h2>

        <?php if ($pending_total === 0): ?>
            <p class="text-center my-3" style="color:#4a5568">
                <i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($TEXT['forum_queue_empty'] ?? 'Nothing waiting. Everything is approved.') ?>
            </p>
        <?php else: ?>
            <p style="color:#8899aa;font-size:.85rem;margin-top:-.5rem;margin-bottom:1rem">
                <?= htmlspecialchars($TEXT['forum_queue_hint'] ?? 'Approve to publish; reject to delete. Both actions are audit-logged.') ?>
            </p>

            <?php if (!empty($pending_thrs)): ?>
                <h3 style="color:#8899aa;font-size:.78rem;text-transform:uppercase;letter-spacing:1px;margin:1rem 0 .6rem">
                    <i class="bi bi-chat-square-text me-1"></i><?= htmlspecialchars($TEXT['forum_queue_threads'] ?? 'Pending threads') ?> (<?= count($pending_thrs) ?>)
                </h3>
                <?php foreach ($pending_thrs as $t): ?>
                    <div style="background:#0e0e17;border:1px solid rgba(240,192,64,.25);border-radius:8px;padding:1rem 1.2rem;margin-bottom:.7rem">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                            <div style="min-width:0;flex:1">
                                <strong style="color:var(--accent)"><?= htmlspecialchars($t['title']) ?></strong>
                                <div style="color:#8899aa;font-size:.78rem;margin-top:.15rem">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($t['author_name']) ?>
                                    &middot;
                                    <i class="bi bi-folder me-1"></i><?= htmlspecialchars($t['category_name']) ?>
                                    &middot;
                                    <i class="bi bi-clock me-1"></i><?= htmlspecialchars(date('M j, Y · H:i', strtotime($t['created_at']))) ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <form method="post" action="/admin_forum" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="approve_thread">
                                    <input type="hidden" name="thread_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-primary" style="padding:.3rem .7rem;font-size:.8rem"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['forum_queue_approve'] ?? 'Approve') ?></button>
                                </form>
                                <form method="post" action="/admin_forum" class="d-inline"
                                      onsubmit="return confirm('<?= htmlspecialchars($TEXT['forum_queue_reject_thread_confirm'] ?? 'Reject this thread? It will be deleted.', ENT_QUOTES) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="reject_thread">
                                    <input type="hidden" name="thread_id" value="<?= (int)$t['id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-danger" style="padding:.3rem .7rem;font-size:.8rem"><i class="bi bi-x-lg me-1"></i><?= htmlspecialchars($TEXT['forum_queue_reject'] ?? 'Reject') ?></button>
                                </form>
                            </div>
                        </div>
                        <details>
                            <summary style="cursor:pointer;color:#8899aa;font-size:.85rem"><?= htmlspecialchars($TEXT['forum_queue_show_body'] ?? 'Show body') ?></summary>
                            <div style="margin-top:.7rem;padding:.85rem 1rem;background:#0a0a0f;border-radius:6px;color:rgba(255,255,255,.85);line-height:1.6;font-size:.92rem">
                                <?= render_markdown((string)($t['op_body'] ?? '')) ?>
                            </div>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($pending_posts)): ?>
                <h3 style="color:#8899aa;font-size:.78rem;text-transform:uppercase;letter-spacing:1px;margin:1.3rem 0 .6rem">
                    <i class="bi bi-reply me-1"></i><?= htmlspecialchars($TEXT['forum_queue_replies'] ?? 'Pending replies') ?> (<?= count($pending_posts) ?>)
                </h3>
                <?php foreach ($pending_posts as $p): ?>
                    <div style="background:#0e0e17;border:1px solid rgba(240,192,64,.25);border-radius:8px;padding:1rem 1.2rem;margin-bottom:.7rem">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2 flex-wrap">
                            <div style="min-width:0;flex:1">
                                <span style="color:#8899aa;font-size:.78rem"><?= htmlspecialchars($TEXT['forum_queue_reply_to'] ?? 'Reply to') ?>:</span>
                                <a href="/forum/<?= htmlspecialchars(rawurlencode($p['category_slug']), ENT_QUOTES) ?>/<?= htmlspecialchars(rawurlencode($p['thread_slug']), ENT_QUOTES) ?>"
                                   target="_blank" style="color:var(--accent);text-decoration:none"><?= htmlspecialchars($p['thread_title']) ?></a>
                                <div style="color:#8899aa;font-size:.78rem;margin-top:.15rem">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($p['author_name']) ?>
                                    &middot;
                                    <i class="bi bi-folder me-1"></i><?= htmlspecialchars($p['category_name']) ?>
                                    &middot;
                                    <i class="bi bi-clock me-1"></i><?= htmlspecialchars(date('M j, Y · H:i', strtotime($p['created_at']))) ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2 flex-shrink-0">
                                <form method="post" action="/admin_forum" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="approve_post">
                                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-primary" style="padding:.3rem .7rem;font-size:.8rem"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['forum_queue_approve'] ?? 'Approve') ?></button>
                                </form>
                                <form method="post" action="/admin_forum" class="d-inline"
                                      onsubmit="return confirm('<?= htmlspecialchars($TEXT['forum_queue_reject_post_confirm'] ?? 'Reject this reply? It will be deleted.', ENT_QUOTES) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="reject_post">
                                    <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-danger" style="padding:.3rem .7rem;font-size:.8rem"><i class="bi bi-x-lg me-1"></i><?= htmlspecialchars($TEXT['forum_queue_reject'] ?? 'Reject') ?></button>
                                </form>
                            </div>
                        </div>
                        <details>
                            <summary style="cursor:pointer;color:#8899aa;font-size:.85rem"><?= htmlspecialchars($TEXT['forum_queue_show_body'] ?? 'Show body') ?></summary>
                            <div style="margin-top:.7rem;padding:.85rem 1rem;background:#0a0a0f;border-radius:6px;color:rgba(255,255,255,.85);line-height:1.6;font-size:.92rem">
                                <?= render_markdown((string)$p['body']) ?>
                            </div>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ════════════ SETTINGS ════════════ -->
    <div class="fa-card">
        <h2><i class="bi bi-sliders me-2"></i><?= htmlspecialchars($TEXT['forum_settings_title'] ?? 'Settings') ?></h2>
        <form method="post" action="/admin_forum">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_settings">

            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_field_enabled'] ?? 'Forum enabled') ?></label>
                    <div class="toggle-row">
                        <label class="toggle-switch">
                            <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span style="color:#8899aa;font-size:.85rem">
                            <?= htmlspecialchars($TEXT['forum_field_enabled_hint'] ?? 'When enabled, the "Forum" link appears in the main nav.') ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="fa-label" for="threshold"><?= htmlspecialchars($TEXT['forum_field_threshold'] ?? 'Auto-approve threshold') ?></label>
                    <input id="threshold" name="auto_approve_threshold" type="number" min="0" max="1000"
                           value="<?= (int)$settings['auto_approve_threshold'] ?>" class="fa-input">
                    <div style="font-size:.78rem;color:#4a5568;margin-top:.3rem">
                        <?= htmlspecialchars($TEXT['forum_field_threshold_hint'] ?? '0 = auto-approve all posts. Otherwise, posts go to a moderation queue until the user has this many approved posts.') ?>
                    </div>
                </div>
                <div class="col-md-3 text-md-end">
                    <button type="submit" class="fa-btn fa-btn-primary"><i class="bi bi-save me-1"></i><?= htmlspecialchars($TEXT['forum_save_settings'] ?? 'Save Settings') ?></button>
                </div>
            </div>
        </form>
    </div>

    <!-- ════════════ CATEGORIES ════════════ -->
    <div class="fa-card">
        <h2><i class="bi bi-folder me-2"></i><?= htmlspecialchars($TEXT['forum_categories_title'] ?? 'Categories') ?></h2>

        <!-- Add / edit form -->
        <form method="post" action="/admin_forum" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="id" value="<?= $edit_cat ? (int)$edit_cat['id'] : 0 ?>">

            <div class="row g-2">
                <div class="col-md-4">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_name'] ?? 'Name *') ?></label>
                    <input name="name" type="text" maxlength="120" required class="fa-input"
                           value="<?= htmlspecialchars($edit_cat['name'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_icon'] ?? 'Icon (BI class)') ?></label>
                    <input name="icon" type="text" maxlength="60" class="fa-input"
                           value="<?= htmlspecialchars($edit_cat['icon'] ?? 'bi-chat-square-text') ?>"
                           placeholder="bi-chat-square-text">
                </div>
                <div class="col-md-2">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_sort'] ?? 'Sort order') ?></label>
                    <input name="sort_order" type="number" class="fa-input"
                           value="<?= (int)($edit_cat['sort_order'] ?? 0) ?>">
                </div>
                <div class="col-md-4">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_slug'] ?? 'Slug (auto from name if blank)') ?></label>
                    <input name="slug" type="text" maxlength="160" class="fa-input"
                           value="<?= htmlspecialchars($edit_cat['slug'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_desc'] ?? 'Description') ?></label>
                    <input name="description" type="text" maxlength="500" class="fa-input"
                           value="<?= htmlspecialchars($edit_cat['description'] ?? '') ?>">
                </div>
                <?php
                $cat_admin_only = isset($edit_cat['admin_only']) ? (int)$edit_cat['admin_only'] : 0;
                // New categories default to "replies allowed" so behaviour is unchanged.
                $cat_allow_replies = isset($edit_cat['allow_replies']) ? (int)$edit_cat['allow_replies'] : 1;
                ?>
                <div class="col-12 mt-1">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_cat_posting'] ?? 'Posting policy') ?></label>
                    <div style="display:flex;flex-wrap:wrap;gap:1.4rem;padding:.6rem .2rem">
                        <label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer;font-size:.88rem;color:#dee2e6;max-width:340px">
                            <input type="checkbox" name="admin_only" value="1" style="margin-top:.2rem" <?= $cat_admin_only ? 'checked' : '' ?>>
                            <span>
                                <strong><?= htmlspecialchars($TEXT['forum_cat_admin_only'] ?? 'Only GMs can start threads') ?></strong><br>
                                <span style="color:#8899aa;font-size:.8rem"><?= htmlspecialchars($TEXT['forum_cat_admin_only_help'] ?? 'Announcement-style category. Regular users can read (and reply, unless disabled below) but cannot create threads.') ?></span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:.5rem;cursor:pointer;font-size:.88rem;color:#dee2e6;max-width:340px">
                            <input type="checkbox" name="allow_replies" value="1" style="margin-top:.2rem" <?= $cat_allow_replies ? 'checked' : '' ?>>
                            <span>
                                <strong><?= htmlspecialchars($TEXT['forum_cat_allow_replies'] ?? 'Allow user replies') ?></strong><br>
                                <span style="color:#8899aa;font-size:.8rem"><?= htmlspecialchars($TEXT['forum_cat_allow_replies_help'] ?? 'Uncheck for a fully read-only category (only GMs can reply). Threads still display normally.') ?></span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2 mt-2">
                    <button type="submit" class="fa-btn fa-btn-primary">
                        <i class="bi bi-<?= $edit_cat ? 'save' : 'plus-lg' ?> me-1"></i>
                        <?= htmlspecialchars($edit_cat ? ($TEXT['forum_cat_update'] ?? 'Update Category') : ($TEXT['forum_cat_add'] ?? 'Add Category')) ?>
                    </button>
                    <?php if ($edit_cat): ?>
                        <a href="/admin_forum" class="fa-btn fa-btn-ghost"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- List -->
        <?php if (empty($categories)): ?>
            <p class="text-center my-3" style="color:#4a5568">
                <?= htmlspecialchars($TEXT['forum_cat_none'] ?? 'No categories yet. Add the first one above.') ?>
            </p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fa-tbl">
                    <thead>
                        <tr>
                            <th></th>
                            <th><?= htmlspecialchars($TEXT['forum_cat_name'] ?? 'Name') ?></th>
                            <th><?= htmlspecialchars($TEXT['forum_cat_slug'] ?? 'Slug') ?></th>
                            <th class="text-center"><?= htmlspecialchars($TEXT['forum_cat_sort'] ?? 'Sort') ?></th>
                            <th class="text-center"><?= htmlspecialchars($TEXT['forum_cat_threads'] ?? 'Threads') ?></th>
                            <th class="text-end"><?= htmlspecialchars($TEXT['news_admin_col_actions'] ?? 'Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><span class="fa-icon-preview"><i class="bi <?= htmlspecialchars($c['icon'] ?: 'bi-chat-square-text') ?>"></i></span></td>
                            <td>
                                <strong style="color:var(--accent)"><?= htmlspecialchars($c['name']) ?></strong>
                                <?php if (!empty($c['description'])): ?>
                                    <div style="color:#8899aa;font-size:.78rem;margin-top:.2rem"><?= htmlspecialchars($c['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="color:#8899aa;font-family:monospace;font-size:.8rem"><?= htmlspecialchars($c['slug']) ?></td>
                            <td class="text-center"><?= (int)$c['sort_order'] ?></td>
                            <td class="text-center"><?= (int)$c['thread_count'] ?></td>
                            <td class="text-end">
                                <a href="/admin_forum?edit_cat=<?= (int)$c['id'] ?>" class="fa-btn fa-btn-ghost" style="padding:.2rem .5rem;font-size:.75rem"><i class="bi bi-pencil"></i></a>
                                <form method="post" action="/admin_forum" class="d-inline"
                                      onsubmit="return confirm('<?= htmlspecialchars($TEXT['forum_cat_delete_confirm'] ?? 'Delete this category and ALL its threads/posts? This cannot be undone.', ENT_QUOTES) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-danger" style="padding:.2rem .5rem;font-size:.75rem"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ════════════ BANS ════════════ -->
    <div class="fa-card">
        <h2><i class="bi bi-slash-circle me-2"></i><?= htmlspecialchars($TEXT['forum_bans_title'] ?? 'Forum Bans') ?></h2>
        <p style="color:#8899aa;font-size:.85rem;margin-top:-.5rem;margin-bottom:1rem">
            <?= htmlspecialchars($TEXT['forum_bans_hint'] ?? 'Forum bans only block posting and replying — the user can still log in and play the game. GM 9+ accounts cannot be banned.') ?>
        </p>

        <!-- Add ban -->
        <form method="post" action="/admin_forum" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="ban">

            <div class="row g-2">
                <div class="col-md-3">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_ban_username'] ?? 'Username *') ?></label>
                    <input name="username" type="text" required maxlength="50" class="fa-input">
                </div>
                <div class="col-md-4">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_ban_reason'] ?? 'Reason') ?></label>
                    <input name="reason" type="text" maxlength="500" class="fa-input">
                </div>
                <div class="col-md-3">
                    <label class="fa-label"><?= htmlspecialchars($TEXT['forum_ban_expires'] ?? 'Expires (blank = permanent)') ?></label>
                    <input name="expires_at" type="datetime-local" class="fa-input">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="fa-btn fa-btn-danger w-100"><i class="bi bi-slash-circle me-1"></i><?= htmlspecialchars($TEXT['forum_ban_add'] ?? 'Ban') ?></button>
                </div>
            </div>
        </form>

        <!-- List -->
        <?php if (empty($bans)): ?>
            <p class="text-center my-3" style="color:#4a5568">
                <?= htmlspecialchars($TEXT['forum_bans_none'] ?? 'No active bans.') ?>
            </p>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fa-tbl">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars($TEXT['forum_ban_username'] ?? 'Username') ?></th>
                            <th><?= htmlspecialchars($TEXT['forum_ban_reason'] ?? 'Reason') ?></th>
                            <th><?= htmlspecialchars($TEXT['forum_ban_by'] ?? 'By') ?></th>
                            <th><?= htmlspecialchars($TEXT['forum_ban_at'] ?? 'Banned') ?></th>
                            <th><?= htmlspecialchars($TEXT['forum_ban_until'] ?? 'Expires') ?></th>
                            <th class="text-end"><?= htmlspecialchars($TEXT['news_admin_col_actions'] ?? 'Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bans as $b): ?>
                        <?php $active = ($b['expires_at'] === null || strtotime($b['expires_at']) > time()); ?>
                        <tr>
                            <td>
                                <strong style="color:var(--accent)"><?= htmlspecialchars($b['username']) ?></strong>
                                <?php if (!$active): ?>
                                    <span class="fa-pill fa-pill-off ms-2"><?= htmlspecialchars($TEXT['forum_ban_expired'] ?? 'Expired') ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#dee2e6"><?= htmlspecialchars($b['reason'] ?: '—') ?></td>
                            <td style="color:#8899aa"><?= htmlspecialchars($b['banned_by']) ?></td>
                            <td style="color:#8899aa;font-size:.85rem"><?= htmlspecialchars(date('M j, Y H:i', strtotime($b['banned_at']))) ?></td>
                            <td style="color:#8899aa;font-size:.85rem"><?= $b['expires_at'] ? htmlspecialchars(date('M j, Y H:i', strtotime($b['expires_at']))) : '<span style="color:#f87e8a">' . htmlspecialchars($TEXT['forum_ban_permanent'] ?? 'Permanent') . '</span>' ?></td>
                            <td class="text-end">
                                <form method="post" action="/admin_forum" class="d-inline"
                                      onsubmit="return confirm('<?= htmlspecialchars($TEXT['forum_unban_confirm'] ?? 'Unban this user from the forum?', ENT_QUOTES) ?>')">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="account_id" value="<?= (int)$b['account_id'] ?>">
                                    <button type="submit" class="fa-btn fa-btn-ghost" style="padding:.2rem .55rem;font-size:.78rem"><i class="bi bi-arrow-counterclockwise me-1"></i><?= htmlspecialchars($TEXT['forum_unban_btn'] ?? 'Unban') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
