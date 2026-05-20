<?php
/**
 * Single ticket detail view (user-facing).
 *
 * URL: /tickets/{id}    (rewritten via .htaccess to ticket_view.php?id={id})
 *
 * - Shows the full conversation thread with markdown rendering.
 * - Inline reply form (with attachment upload + MD support).
 * - Close / reopen buttons.
 * - Ownership-checked: a non-owner gets 403.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';

// Auth
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// Feature flag
if (empty($config['features']['tickets'])) {
    header('Location: /dashboard');
    exit;
}

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    header('Location: /tickets');
    exit;
}

// ─── Load ticket + verify ownership ──────────────────────────────────────────
try {
    $stmt = $pdo_auth->prepare("SELECT * FROM web_tickets WHERE id = :id AND user_id = :uid LIMIT 1");
    $stmt->execute(['id' => $ticket_id, 'uid' => $_SESSION['user_id']]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ticket_view load failed: ' . $e->getMessage());
    header('Location: /tickets');
    exit;
}

if (!$ticket) {
    http_response_code(404);
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
        <h2 style="color:var(--accent)"><?= htmlspecialchars($TEXT['ticket_not_found_title'] ?? 'Ticket not found') ?></h2>
        <p style="color:#8899aa"><?= htmlspecialchars($TEXT['ticket_not_found_hint'] ?? 'This ticket does not exist or is not yours.') ?></p>
        <a href="/tickets" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['tickets_tab_my'] ?? 'My Tickets') ?></a>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ─── User account info (for the message author name) ────────────────────────
$user_username = $_SESSION['username'] ?? 'User';

// ─── Action handler (reply / close / reopen) ────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ticket_action'] ?? '';
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    }

    if (empty($errors) && $action === 'reply') {
        $reply_text = trim((string)($_POST['reply_text'] ?? ''));

        if ($ticket['status'] === 'closed') {
            $errors[] = $TEXT['ticket_action_closed'] ?? 'You cannot reply to a closed ticket.';
        } elseif ($reply_text === '') {
            $errors[] = $TEXT['ticket_required_message'] ?? 'Message is required.';
        } elseif (mb_strlen($reply_text) > 2000) {
            $errors[] = $TEXT['tickets_reply_too_long'] ?? 'Reply too long (max 2000).';
        } else {
            // Process attachments (same rules as the original ticket form)
            $attachment_names = [];
            $files = $_FILES['attachments'] ?? null;
            if ($files && !empty($files['name'][0])) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
                $max_size  = 3 * 1024 * 1024;
                $max_files = 5;

                if (count($files['name']) > $max_files) {
                    $errors[] = str_replace('{count}', $max_files, $TEXT['ticket_max_files'] ?? 'Maximum {count} files allowed');
                }
                foreach ($files['tmp_name'] as $key => $tmp_name) {
                    if (count($attachment_names) >= $max_files) break;
                    if (($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

                    $file_type = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';
                    $file_size = (int)$files['size'][$key];
                    $orig_name = $files['name'][$key] ?? '';

                    if (!in_array($file_type, $allowed_types, true)) {
                        $errors[] = str_replace('{filename}', $orig_name, $TEXT['ticket_invalid_file_type'] ?? 'Invalid file type: {filename}');
                        continue;
                    }
                    if ($file_size > $max_size) {
                        $errors[] = str_replace('{filename}', $orig_name, $TEXT['ticket_file_too_large'] ?? 'File too large: {filename}');
                        continue;
                    }

                    $upload_dir = __DIR__ . '/../uploads/tickets/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                    $file_name   = uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($orig_name));
                    $target_path = $upload_dir . $file_name;
                    if (move_uploaded_file($tmp_name, $target_path)) {
                        $attachment_names[] = $file_name;
                    }
                }
            }

            if (empty($errors)) {
                try {
                    $pdo_auth->beginTransaction();
                    $msg = $pdo_auth->prepare(
                        "INSERT INTO web_ticket_messages (ticket_id, sender_type, sender_username, message, attachments, created_at)
                         VALUES (:tid, 'user', :name, :msg, :attach, NOW())"
                    );
                    $msg->execute([
                        'tid'    => $ticket_id,
                        'name'   => $user_username,
                        'msg'    => $reply_text,
                        'attach' => !empty($attachment_names) ? json_encode($attachment_names) : null,
                    ]);
                    // Bump status to in_progress so admins see new replies as live
                    $pdo_auth->prepare("UPDATE web_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = :id")
                             ->execute(['id' => $ticket_id]);
                    $pdo_auth->commit();
                    header('Location: /tickets/' . $ticket_id . '?action=replied#bottom');
                    exit;
                } catch (Exception $e) {
                    if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
                    error_log('ticket_view reply failed: ' . $e->getMessage());
                    $errors[] = $TEXT['error_db'] ?? 'Database error.';
                }
            }
        }
    } elseif (empty($errors) && $action === 'close') {
        if ($ticket['status'] !== 'closed') {
            $pdo_auth->prepare("UPDATE web_tickets SET status = 'closed', updated_at = NOW() WHERE id = :id AND user_id = :uid")
                     ->execute(['id' => $ticket_id, 'uid' => $_SESSION['user_id']]);
        }
        header('Location: /tickets/' . $ticket_id . '?action=closed');
        exit;
    } elseif (empty($errors) && $action === 'reopen') {
        if ($ticket['status'] === 'closed') {
            $pdo_auth->prepare("UPDATE web_tickets SET status = 'in_progress', updated_at = NOW() WHERE id = :id AND user_id = :uid")
                     ->execute(['id' => $ticket_id, 'uid' => $_SESSION['user_id']]);
        }
        header('Location: /tickets/' . $ticket_id . '?action=reopened');
        exit;
    }
}

// ─── Reload ticket after any mutation, plus the thread ──────────────────────
$stmt = $pdo_auth->prepare("SELECT * FROM web_tickets WHERE id = :id AND user_id = :uid");
$stmt->execute(['id' => $ticket_id, 'uid' => $_SESSION['user_id']]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

$thread = [];
try {
    $msg_stmt = $pdo_auth->prepare(
        "SELECT id, sender_type, sender_username, message, attachments, created_at
         FROM web_ticket_messages WHERE ticket_id = :tid ORDER BY created_at ASC, id ASC"
    );
    $msg_stmt->execute(['tid' => $ticket_id]);
    $thread = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('ticket_view thread load failed: ' . $e->getMessage());
}

$categories = [
    'account'    => ['label' => $TEXT['tickets_cat_account']    ?? 'Account Issues', 'icon' => 'bi-person-exclamation', 'color' => '#69CCF0'],
    'bug'        => ['label' => $TEXT['tickets_cat_bug']        ?? 'Report Bug',     'icon' => 'bi-bug',                'color' => '#ABD473'],
    'player'     => ['label' => $TEXT['tickets_cat_player']     ?? 'Report Player',  'icon' => 'bi-shield-exclamation', 'color' => '#F58CBA'],
    'payment'    => ['label' => $TEXT['tickets_cat_payment']    ?? 'Payment Issues', 'icon' => 'bi-credit-card',        'color' => '#FFF569'],
    'suggestion' => ['label' => $TEXT['tickets_cat_suggestion'] ?? 'Suggestion',     'icon' => 'bi-lightbulb',          'color' => '#C79C6E'],
    'other'      => ['label' => $TEXT['tickets_cat_other']      ?? 'Other',          'icon' => 'bi-chat-dots',          'color' => '#9482C9'],
];
$cat = $categories[$ticket['category']] ?? ['label' => $ticket['category'], 'icon' => 'bi-tag', 'color' => '#888'];

$status_class = 'status-' . htmlspecialchars($ticket['status']);
$status_label = $ticket['status'] === 'open'
    ? '● ' . ($TEXT['tickets_status_open'] ?? 'Open')
    : ($ticket['status'] === 'in_progress'
        ? '◐ ' . ($TEXT['tickets_status_in_progress'] ?? 'In Progress')
        : '○ ' . ($TEXT['tickets_status_closed'] ?? 'Closed'));

$action_toast = $_GET['action'] ?? '';
$page_title = '#' . $ticket_id . ' — ' . htmlspecialchars($ticket['subject']);
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.tv-wrap { padding-top: 90px; padding-bottom: 3rem; max-width: 920px; margin: 0 auto; }
.tv-back { color: #8899aa; text-decoration: none; font-size: .88rem; }
.tv-back:hover { color: var(--accent); }

.tv-header {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    margin: 1rem 0 1.5rem;
}
.tv-header .meta-row {
    display: flex; align-items: center; flex-wrap: wrap; gap: .6rem;
    font-size: .82rem; color: #8899aa; margin-bottom: .5rem;
}
.tv-header h1 {
    color: #e2e8f0;
    font-size: clamp(1.2rem, 2.5vw, 1.6rem);
    margin: 0;
    word-wrap: break-word;
}
.tv-status {
    display: inline-block;
    padding: .15rem .55rem;
    border-radius: 6px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.tv-status.status-open        { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.tv-status.status-in_progress { background: rgba(245,158,11,0.15); color: var(--accent); border: 1px solid rgba(245,158,11,0.3); }
.tv-status.status-closed      { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

.tv-thread {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .25);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    margin-bottom: 1.5rem;
}
.tv-bubble {
    margin-bottom: 1rem;
    padding: 1rem 1.1rem;
    border-radius: 10px;
    font-size: .92rem;
    line-height: 1.6;
}
.tv-bubble:last-child { margin-bottom: 0; }
.tv-bubble.user {
    background: rgba(255,255,255,0.03);
    border-left: 3px solid rgba(var(--accent-rgb), 0.5);
}
.tv-bubble.admin {
    background: rgba(93,216,124,0.06);
    border-left: 3px solid #5dd87c;
}
.tv-bubble .tv-bubble-meta {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: .55rem;
    color: #8899aa;
    display: flex; align-items: center; flex-wrap: wrap; gap: .5rem;
}
.tv-bubble.user  .tv-bubble-meta { color: var(--accent); }
.tv-bubble.admin .tv-bubble-meta { color: #5dd87c; }
.tv-bubble-body { color: #e2e8f0; word-wrap: break-word; }
.tv-bubble-body p { margin: 0 0 .6rem; }
.tv-bubble-body p:last-child { margin-bottom: 0; }
.tv-bubble-body code {
    background: rgba(0,0,0,.3); padding: .15rem .4rem; border-radius: 4px;
    color: #f4d390; font-size: .88em;
}
.tv-bubble-body pre {
    background: rgba(0,0,0,.4); border: 1px solid rgba(255,255,255,.05);
    padding: .75rem 1rem; border-radius: 6px; overflow-x: auto;
    font-size: .85rem;
}
.tv-bubble-body pre code { background: none; padding: 0; color: #e2e8f0; }
.tv-bubble-body blockquote {
    border-left: 3px solid rgba(var(--accent-rgb), .4); padding-left: 1rem;
    margin: 0 0 .6rem; color: #c0c8d8; font-style: italic;
}
.tv-bubble-body a { color: #69ccf0; }
.tv-bubble-body a:hover { color: #cfe9f6; }
.tv-bubble-body ul, .tv-bubble-body ol { margin: 0 0 .6rem 1.4rem; }

.tv-attachments {
    margin-top: .8rem;
    display: flex; flex-wrap: wrap; gap: .6rem;
}
.tv-attachment {
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    overflow: hidden;
    transition: all .2s ease;
    cursor: zoom-in;
    max-width: 220px;
}
.tv-attachment:hover { border-color: rgba(var(--accent-rgb), .5); transform: translateY(-2px); }
.tv-attachment img { width: 100%; height: 140px; object-fit: cover; display: block; }
.tv-attachment .tv-att-name {
    font-size: .72rem; color: #8899aa; padding: .35rem .55rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Lightbox */
.tv-lightbox {
    position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 9999;
    display: none; align-items: center; justify-content: center;
    padding: 2rem; cursor: zoom-out;
}
.tv-lightbox.show { display: flex; }
.tv-lightbox img { max-width: 100%; max-height: 100%; box-shadow: 0 0 40px rgba(0,0,0,.6); }
.tv-lightbox-close {
    position: absolute; top: 1rem; right: 1.5rem; color: #fff; font-size: 2rem;
    background: none; border: none; cursor: pointer;
}

/* Reply form */
.tv-reply {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .25);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
}
.tv-reply h3 {
    font-size: .82rem;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: 700;
    margin: 0 0 1rem;
}
.tv-reply textarea {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    color: #e2e8f0;
    padding: .85rem 1rem;
    font-size: .95rem;
    line-height: 1.5;
    transition: border-color .2s ease;
    outline: none;
    resize: vertical;
    min-height: 110px;
    box-sizing: border-box;
    font-family: inherit;
}
.tv-reply textarea:focus {
    border-color: rgba(var(--accent-rgb), 0.5);
    box-shadow: 0 0 0 3px rgba(var(--btn-bg-rgb), 0.15);
}
.tv-md-hint {
    display: flex; align-items: center; gap: .5rem;
    font-size: .75rem; color: #6c7a8c; margin-top: .4rem;
}
.tv-md-hint code {
    background: rgba(0,0,0,.3); padding: .05rem .3rem; border-radius: 3px;
    color: var(--accent); font-size: .9em;
}
.tv-md-toggle {
    color: var(--accent); cursor: pointer; text-decoration: underline;
    text-decoration-style: dotted; user-select: none;
}
.tv-md-help {
    display: none;
    background: rgba(0,0,0,.3); border: 1px solid rgba(255,255,255,.06);
    border-radius: 8px; padding: .8rem 1rem; margin-top: .6rem;
    font-size: .82rem;
}
.tv-md-help.show { display: block; }
.tv-md-help table { width: 100%; border-collapse: collapse; }
.tv-md-help td { padding: .25rem .6rem; color: #c0c8d8; vertical-align: top; }
.tv-md-help td:first-child { color: #8899aa; font-family: monospace; white-space: nowrap; }

.tv-attach-row {
    border: 2px dashed rgba(255,255,255,0.1);
    border-radius: 10px;
    padding: .9rem;
    margin-top: .9rem;
    transition: border-color .2s ease, background .2s ease;
    cursor: pointer;
    position: relative;
}
.tv-attach-row:hover { border-color: rgba(var(--accent-rgb), .4); background: rgba(var(--btn-bg-rgb), .05); }
.tv-attach-row input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.tv-attach-row .placeholder { color: #8899aa; font-size: .85rem; }
.tv-attach-row .placeholder i { margin-right: .35rem; }

.tv-actions {
    display: flex; gap: .6rem; margin-top: 1rem; flex-wrap: wrap;
}
.tv-btn {
    padding: .65rem 1.3rem;
    border-radius: 8px;
    font-weight: 700;
    font-size: .88rem;
    letter-spacing: .4px;
    cursor: pointer;
    border: 1px solid;
    transition: all .2s ease;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
}
.tv-btn-primary {
    background: linear-gradient(135deg, var(--btn-bg), var(--btn-bg-hover));
    border-color: var(--btn-bg-hover);
    color: #fff;
}
.tv-btn-primary:hover {
    background: linear-gradient(135deg, var(--btn-bg-hover), var(--accent));
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(var(--btn-bg-rgb), .35);
}
.tv-btn-danger {
    background: rgba(220,53,69,0.08);
    border-color: rgba(220,53,69,0.4);
    color: #f87e8a;
}
.tv-btn-danger:hover { background: rgba(220,53,69,0.18); border-color: rgba(220,53,69,0.6); color: #fff; }
.tv-btn-secondary {
    background: rgba(105,204,240,0.08);
    border-color: rgba(105,204,240,0.4);
    color: #69ccf0;
}
.tv-btn-secondary:hover { background: rgba(105,204,240,0.18); border-color: rgba(105,204,240,0.6); color: #cfe9f6; }

.tv-toast {
    border-radius: 10px;
    padding: .8rem 1.1rem;
    margin-bottom: 1rem;
    background: rgba(93,216,124,0.12);
    border: 1px solid rgba(93,216,124,0.4);
    color: #5dd87c;
    animation: tv-toast-in .35s ease;
    font-size: .9rem;
}
@keyframes tv-toast-in { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.tv-closed-banner {
    text-align: center;
    color: #8899aa;
    font-style: italic;
    padding: 1.4rem 1rem;
    border: 1px dashed rgba(255,255,255,.08);
    border-radius: 10px;
    margin-bottom: 1rem;
}
</style>

<div class="container tv-wrap px-3">
    <a class="tv-back" href="/tickets">← <?= htmlspecialchars($TEXT['tickets_tab_my'] ?? 'My Tickets') ?></a>

    <!-- Toast -->
    <?php if ($action_toast === 'replied'): ?>
        <div class="tv-toast" style="margin-top:1rem"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_replied'] ?? 'Reply sent.') ?></div>
    <?php elseif ($action_toast === 'closed'): ?>
        <div class="tv-toast" style="margin-top:1rem"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_closed'] ?? 'Ticket closed.') ?></div>
    <?php elseif ($action_toast === 'reopened'): ?>
        <div class="tv-toast" style="margin-top:1rem"><i class="bi bi-arrow-counterclockwise me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_reopened'] ?? 'Ticket reopened.') ?></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="tv-header">
        <div class="meta-row">
            <span class="tv-status <?= $status_class ?>"><?= htmlspecialchars($status_label) ?></span>
            <span style="color:#4a5568">#<?= $ticket_id ?></span>
            <span style="color:<?= $cat['color'] ?>"><i class="bi <?= $cat['icon'] ?>"></i> <?= htmlspecialchars($cat['label']) ?></span>
            <span style="color:#4a5568;margin-left:auto"><?= htmlspecialchars($TEXT['tickets_opened'] ?? 'Opened') ?>: <?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?></span>
        </div>
        <h1><?= htmlspecialchars($ticket['subject']) ?></h1>
    </div>

    <!-- Validation errors -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:10px;border:1px solid rgba(220,53,69,.4);background:rgba(220,53,69,.12)">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        </div>
    <?php endif; ?>

    <!-- Thread -->
    <div class="tv-thread">
        <?php if (empty($thread)): ?>
            <div style="color:#8899aa;text-align:center;padding:2rem"><?= htmlspecialchars($TEXT['tickets_thread_empty'] ?? 'No messages in this thread yet.') ?></div>
        <?php else: foreach ($thread as $m):
            $is_user = $m['sender_type'] === 'user';
            $atts = !empty($m['attachments']) ? json_decode($m['attachments'], true) : [];
            if (!is_array($atts)) $atts = [];
        ?>
            <div class="tv-bubble <?= $is_user ? 'user' : 'admin' ?>">
                <div class="tv-bubble-meta">
                    <i class="bi <?= $is_user ? 'bi-person-circle' : 'bi-shield-check' ?>"></i>
                    <strong><?= htmlspecialchars($m['sender_username']) ?></strong>
                    <?php if (!$is_user): ?><span style="font-size:.7rem;opacity:.8">(<?= htmlspecialchars($TEXT['tickets_admin_reply'] ?? 'Admin') ?>)</span><?php endif; ?>
                    <span style="margin-left:auto;color:#6c7a8c;letter-spacing:0;text-transform:none"><?= date('M d, Y H:i', strtotime($m['created_at'])) ?></span>
                </div>
                <div class="tv-bubble-body"><?= render_markdown($m['message']) ?></div>
                <?php if (!empty($atts)): ?>
                    <div class="tv-attachments">
                        <?php foreach ($atts as $a):
                            $url = '/ticket_attachment?f=' . urlencode($a);
                            // Show last 60 chars of filename for display
                            $display = preg_replace('/^[a-f0-9.]+_/', '', $a); // strip uniqid prefix
                        ?>
                            <a class="tv-attachment" href="<?= htmlspecialchars($url) ?>" data-lightbox="1" target="_blank" rel="noopener noreferrer">
                                <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($display) ?>" loading="lazy">
                                <div class="tv-att-name" title="<?= htmlspecialchars($display) ?>"><?= htmlspecialchars($display) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
        <div id="bottom"></div>
    </div>

    <!-- Reply / actions -->
    <?php if ($ticket['status'] !== 'closed'): ?>
        <form class="tv-reply" method="POST" action="/tickets/<?= $ticket_id ?>" enctype="multipart/form-data" id="replyForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="ticket_action" value="reply">
            <h3><i class="bi bi-reply me-2"></i><?= htmlspecialchars($TEXT['tickets_add_reply'] ?? 'Add a reply') ?></h3>
            <textarea name="reply_text" maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['tickets_reply_placeholder'] ?? 'Write your reply…') ?>" required></textarea>

            <div class="tv-md-hint">
                <i class="bi bi-markdown"></i>
                <?= htmlspecialchars($TEXT['tickets_md_hint'] ?? 'Markdown supported') ?>
                — <code>**<?= htmlspecialchars($TEXT['tickets_md_bold'] ?? 'bold') ?>**</code>,
                <code>*<?= htmlspecialchars($TEXT['tickets_md_italic'] ?? 'italic') ?>*</code>,
                <code>`<?= htmlspecialchars($TEXT['tickets_md_code'] ?? 'code') ?>`</code>,
                <code>[<?= htmlspecialchars($TEXT['tickets_md_link'] ?? 'link') ?>](url)</code>,
                <a class="tv-md-toggle" onclick="document.getElementById('mdHelp').classList.toggle('show')"><?= htmlspecialchars($TEXT['tickets_md_more'] ?? 'more…') ?></a>
            </div>
            <div class="tv-md-help" id="mdHelp">
                <table>
                    <tr><td>**bold**</td><td><strong>bold</strong></td></tr>
                    <tr><td>*italic*</td><td><em>italic</em></td></tr>
                    <tr><td>`code`</td><td><code>code</code></td></tr>
                    <tr><td>[text](https://example.com)</td><td><a href="#">text</a></td></tr>
                    <tr><td>- list item</td><td><?= htmlspecialchars($TEXT['tickets_md_list_item'] ?? 'bulleted list item') ?></td></tr>
                    <tr><td>1. ordered item</td><td><?= htmlspecialchars($TEXT['tickets_md_ordered_item'] ?? 'numbered list item') ?></td></tr>
                    <tr><td>&gt; quoted</td><td><?= htmlspecialchars($TEXT['tickets_md_quote'] ?? 'blockquote') ?></td></tr>
                    <tr><td>```code block```</td><td><?= htmlspecialchars($TEXT['tickets_md_codeblock'] ?? 'multi-line code') ?></td></tr>
                </table>
            </div>

            <label class="tv-attach-row">
                <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp" id="replyFiles" onchange="updateFileList(this)">
                <div class="placeholder" id="filePlaceholder">
                    <i class="bi bi-paperclip"></i><?= htmlspecialchars($TEXT['tickets_attach_files'] ?? 'Attach images') ?> <span style="color:#4a5568;font-size:.78rem">(<?= htmlspecialchars($TEXT['ticket_attachments_info'] ?? 'max 5, 3MB each, JPG/PNG/WEBP') ?>)</span>
                </div>
            </label>

            <div class="tv-actions">
                <button type="submit" class="tv-btn tv-btn-primary"><i class="bi bi-send"></i> <?= htmlspecialchars($TEXT['tickets_send_reply'] ?? 'Send Reply') ?></button>
                <button type="submit" class="tv-btn tv-btn-danger" formnovalidate
                        onclick="this.form.querySelector('[name=ticket_action]').value='close';this.form.querySelector('[name=reply_text]').removeAttribute('required');">
                    <i class="bi bi-x-circle"></i> <?= htmlspecialchars($TEXT['tickets_close_ticket'] ?? 'Close Ticket') ?>
                </button>
            </div>
        </form>
    <?php else: ?>
        <div class="tv-closed-banner">
            <i class="bi bi-lock-fill me-2"></i><?= htmlspecialchars($TEXT['tickets_thread_closed'] ?? 'This ticket is closed.') ?>
        </div>
        <form method="POST" action="/tickets/<?= $ticket_id ?>" style="text-align:center">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="ticket_action" value="reopen">
            <button type="submit" class="tv-btn tv-btn-secondary"><i class="bi bi-arrow-counterclockwise"></i> <?= htmlspecialchars($TEXT['tickets_reopen_ticket'] ?? 'Reopen Ticket') ?></button>
        </form>
    <?php endif; ?>
</div>

<!-- Lightbox modal -->
<div class="tv-lightbox" id="lightbox" onclick="closeLightbox(event)">
    <button class="tv-lightbox-close" onclick="closeLightbox(event)">&times;</button>
    <img id="lightboxImg" src="" alt="">
</div>

<script>
// File picker label update
function updateFileList(input) {
    const ph = document.getElementById('filePlaceholder');
    const files = Array.from(input.files);
    if (!files.length) {
        ph.innerHTML = '<i class="bi bi-paperclip"></i><?= htmlspecialchars(addslashes($TEXT['tickets_attach_files'] ?? 'Attach images')) ?>';
        return;
    }
    ph.innerHTML = '<i class="bi bi-paperclip-fill" style="color:var(--accent)"></i> ' +
        files.map(f => f.name + ' (' + Math.round(f.size/1024) + ' KB)').join(', ');
}

// Lightbox
document.querySelectorAll('a.tv-attachment').forEach(a => {
    a.addEventListener('click', e => {
        if (a.dataset.lightbox === '1') {
            e.preventDefault();
            const img = a.querySelector('img');
            if (img && img.src) {
                document.getElementById('lightboxImg').src = img.src;
                document.getElementById('lightbox').classList.add('show');
            }
        }
    });
});
function closeLightbox(e) {
    if (e && e.target && e.target.tagName === 'IMG') return; // click on the img itself shouldn't close
    document.getElementById('lightbox').classList.remove('show');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('lightbox').classList.remove('show'); });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
