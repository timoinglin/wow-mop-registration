<?php
/**
 * Single ticket detail view (GM-facing).
 *
 * URL: /admin_ticket/{id}    (rewritten via .htaccess)
 *
 * - Same thread + attachment rendering as /tickets/{id}, but the requester
 *   sees ANY ticket regardless of owner (after GM check).
 * - Reply form supports markdown + attachments.
 * - Status controls (close / reopen / mark in_progress).
 * - Quick links back to the user account in /admin_dashboard.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/audit.php';

// Auth
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }

// GM check (admin_dashboard threshold)
$_gm_check = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$_gm_check->execute(['id' => $_SESSION['user_id']]);
$_gm = (int)($_gm_check->fetchColumn() ?: 0);
if ($_gm < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $_gm;

$admin_name = $_SESSION['username'] ?? ('GM #' . $_SESSION['user_id']);
$admin_id   = (int)$_SESSION['user_id'];
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) { header('Location: /admin_dashboard'); exit; }

// Load ticket (no ownership constraint — GMs see all)
try {
    $stmt = $pdo_auth->prepare("SELECT * FROM tickets WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('admin_ticket load failed: ' . $e->getMessage());
    header('Location: /admin_dashboard'); exit;
}

if (!$ticket) {
    http_response_code(404);
    require_once __DIR__ . '/../templates/header.php';
    ?>
    <div class="container" style="padding-top:120px;padding-bottom:3rem;text-align:center">
        <h2 style="color:#c8a96e"><?= htmlspecialchars($TEXT['ticket_not_found_title'] ?? 'Ticket not found') ?></h2>
        <p style="color:#8899aa">#<?= $ticket_id ?></p>
        <a href="/admin_dashboard#tab-tickets" class="btn btn-gold mt-2">← <?= htmlspecialchars($TEXT['admin_support_tickets'] ?? 'Support Tickets') ?></a>
    </div>
    <?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ─── Action handler ─────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ticket_action'] ?? '';
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    }

    if (empty($errors) && $action === 'reply') {
        $reply_text = trim((string)($_POST['reply_text'] ?? ''));
        $new_status = $_POST['status_after'] ?? 'in_progress';
        if (!in_array($new_status, ['open', 'in_progress', 'closed'], true)) $new_status = 'in_progress';

        if ($reply_text === '') {
            $errors[] = $TEXT['ticket_required_message'] ?? 'Message is required.';
        } elseif (mb_strlen($reply_text) > 2000) {
            $errors[] = $TEXT['tickets_reply_too_long'] ?? 'Reply too long.';
        } else {
            // Process attachments — same rules as user side
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

                    // Append admin message
                    $msg = $pdo_auth->prepare(
                        "INSERT INTO ticket_messages (ticket_id, sender_type, sender_username, message, attachments, created_at)
                         VALUES (:tid, 'admin', :name, :msg, :attach, NOW())"
                    );
                    $msg->execute([
                        'tid'    => $ticket_id,
                        'name'   => $admin_name,
                        'msg'    => $reply_text,
                        'attach' => !empty($attachment_names) ? json_encode($attachment_names) : null,
                    ]);

                    // Keep the legacy admin_reply column up to date (last admin reply)
                    $pdo_auth->prepare(
                        "UPDATE tickets SET admin_reply = :reply, replied_by = :by, status = :st, updated_at = NOW() WHERE id = :id"
                    )->execute([
                        'reply' => $reply_text,
                        'by'    => $admin_name,
                        'st'    => $new_status,
                        'id'    => $ticket_id,
                    ]);
                    $pdo_auth->commit();

                    // Audit log
                    if (function_exists('log_admin_action')) {
                        log_admin_action($pdo_auth, $admin_id, $admin_name, 'ticket_reply', "Ticket #$ticket_id", "Status: $new_status", $ip);
                    }

                    // Notify user via email (mirror the existing admin_api flow)
                    if (!empty($ticket['email'])) {
                        require_once __DIR__ . '/../includes/email.php';
                        $server_name = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');
                        $inner = "<h2 style='color:#c8a96e;margin-top:0'>📋 Ticket Reply</h2>
                            <p style='color:#8899aa'>Your ticket <strong style='color:#e2e8f0'>\"" . htmlspecialchars($ticket['subject']) . "\"</strong> has received a reply:</p>
                            <div style='background:#1a1a2e;border-left:3px solid #c8a96e;padding:16px;border-radius:6px;white-space:pre-line;color:#e2e8f0'>"
                            . htmlspecialchars($reply_text) . "</div>
                            <p style='color:#8899aa;margin-top:16px;font-size:13px'>Status: <strong style='color:#c8a96e'>" . ucfirst(str_replace('_', ' ', $new_status)) . "</strong></p>";
                        @send_email($ticket['email'], "[$server_name] Ticket Reply: " . $ticket['subject'], email_template($inner, "Ticket reply: " . $ticket['subject']));
                    }

                    header('Location: /admin_ticket/' . $ticket_id . '?action=replied#bottom');
                    exit;
                } catch (Exception $e) {
                    if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
                    error_log('admin_ticket reply failed: ' . $e->getMessage());
                    $errors[] = $TEXT['error_db'] ?? 'Database error.';
                }
            }
        }
    } elseif (empty($errors) && in_array($action, ['close', 'reopen', 'mark_in_progress'], true)) {
        $new = $action === 'close' ? 'closed' : ($action === 'reopen' ? 'open' : 'in_progress');
        $pdo_auth->prepare("UPDATE tickets SET status = :st, updated_at = NOW() WHERE id = :id")
                 ->execute(['st' => $new, 'id' => $ticket_id]);
        if (function_exists('log_admin_action')) {
            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ticket_status', "Ticket #$ticket_id", "New status: $new", $ip);
        }
        header('Location: /admin_ticket/' . $ticket_id . '?action=status_changed');
        exit;
    }
}

// Reload ticket after mutations
$stmt = $pdo_auth->prepare("SELECT * FROM tickets WHERE id = :id");
$stmt->execute(['id' => $ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

// Load thread
$thread = [];
try {
    $msg_stmt = $pdo_auth->prepare(
        "SELECT id, sender_type, sender_username, message, attachments, created_at
         FROM ticket_messages WHERE ticket_id = :tid ORDER BY created_at ASC, id ASC"
    );
    $msg_stmt->execute(['tid' => $ticket_id]);
    $thread = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('admin_ticket thread load failed: ' . $e->getMessage());
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
$page_title = '#' . $ticket_id . ' (admin) — ' . htmlspecialchars($ticket['subject']);
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.tv-wrap { padding-top: 90px; padding-bottom: 3rem; max-width: 1000px; margin: 0 auto; }
.tv-back { color: #8899aa; text-decoration: none; font-size: .88rem; }
.tv-back:hover { color: #c8a96e; }

.tv-header {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    margin: 1rem 0 1.5rem;
}
.tv-header .meta-row {
    display: flex; align-items: center; flex-wrap: wrap; gap: .6rem;
    font-size: .82rem; color: #8899aa; margin-bottom: .6rem;
}
.tv-header h1 {
    color: #e2e8f0; font-size: clamp(1.2rem, 2.5vw, 1.6rem);
    margin: 0; word-wrap: break-word;
}
.tv-status {
    display: inline-block; padding: .15rem .55rem;
    border-radius: 6px; font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
}
.tv-status.status-open        { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.tv-status.status-in_progress { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.tv-status.status-closed      { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

.tv-user-info {
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    padding: .8rem 1rem; margin-top: .8rem;
    background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.05);
    border-radius: 10px; font-size: .85rem;
}
.tv-user-info .label { color: #6c7a8c; font-size: .72rem; text-transform: uppercase; letter-spacing: .8px; }
.tv-user-info .value { color: #e2e8f0; }
.tv-user-info .username { color: #c8a96e; font-weight: 700; }

/* Thread + bubbles + lightbox + reply form: shared with user side */
.tv-thread {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(139,69,19,.25);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    margin-bottom: 1.5rem;
}
.tv-bubble { margin-bottom: 1rem; padding: 1rem 1.1rem; border-radius: 10px; font-size: .92rem; line-height: 1.6; }
.tv-bubble:last-child { margin-bottom: 0; }
.tv-bubble.user  { background: rgba(255,255,255,0.03); border-left: 3px solid rgba(200,169,110,0.5); }
.tv-bubble.admin { background: rgba(93,216,124,0.06); border-left: 3px solid #5dd87c; }
.tv-bubble .tv-bubble-meta {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .8px;
    margin-bottom: .55rem; color: #8899aa;
    display: flex; align-items: center; flex-wrap: wrap; gap: .5rem;
}
.tv-bubble.user  .tv-bubble-meta { color: #c8a96e; }
.tv-bubble.admin .tv-bubble-meta { color: #5dd87c; }
.tv-bubble-body { color: #e2e8f0; word-wrap: break-word; }
.tv-bubble-body p { margin: 0 0 .6rem; }
.tv-bubble-body p:last-child { margin-bottom: 0; }
.tv-bubble-body code { background: rgba(0,0,0,.3); padding: .15rem .4rem; border-radius: 4px; color: #f4d390; font-size: .88em; }
.tv-bubble-body pre { background: rgba(0,0,0,.4); border: 1px solid rgba(255,255,255,.05); padding: .75rem 1rem; border-radius: 6px; overflow-x: auto; font-size: .85rem; }
.tv-bubble-body pre code { background: none; padding: 0; color: #e2e8f0; }
.tv-bubble-body blockquote { border-left: 3px solid rgba(200,169,110,.4); padding-left: 1rem; margin: 0 0 .6rem; color: #c0c8d8; font-style: italic; }
.tv-bubble-body a { color: #69ccf0; }
.tv-bubble-body a:hover { color: #cfe9f6; }
.tv-bubble-body ul, .tv-bubble-body ol { margin: 0 0 .6rem 1.4rem; }

.tv-attachments { margin-top: .8rem; display: flex; flex-wrap: wrap; gap: .6rem; }
.tv-attachment {
    background: rgba(0,0,0,.3); border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px; overflow: hidden; transition: all .2s ease;
    cursor: zoom-in; max-width: 220px;
}
.tv-attachment:hover { border-color: rgba(200,169,110,.5); transform: translateY(-2px); }
.tv-attachment img { width: 100%; height: 140px; object-fit: cover; display: block; }
.tv-attachment .tv-att-name { font-size: .72rem; color: #8899aa; padding: .35rem .55rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.tv-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 2rem; cursor: zoom-out; }
.tv-lightbox.show { display: flex; }
.tv-lightbox img { max-width: 100%; max-height: 100%; box-shadow: 0 0 40px rgba(0,0,0,.6); }
.tv-lightbox-close { position: absolute; top: 1rem; right: 1.5rem; color: #fff; font-size: 2rem; background: none; border: none; cursor: pointer; }

.tv-reply { background: linear-gradient(145deg, #12121f, #1a1a2e); border: 1px solid rgba(139,69,19,.25); border-radius: 14px; padding: 1.4rem 1.6rem; }
.tv-reply h3 { font-size: .82rem; color: #c8a96e; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; margin: 0 0 1rem; }
.tv-reply textarea {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; color: #e2e8f0; padding: .85rem 1rem; font-size: .95rem; line-height: 1.5;
    transition: border-color .2s ease; outline: none; resize: vertical; min-height: 110px;
    box-sizing: border-box; font-family: inherit;
}
.tv-reply textarea:focus { border-color: rgba(200,169,110,0.5); box-shadow: 0 0 0 3px rgba(139,69,19,0.15); }

.tv-md-hint { display: flex; align-items: center; gap: .5rem; font-size: .75rem; color: #6c7a8c; margin-top: .4rem; flex-wrap: wrap; }
.tv-md-hint code { background: rgba(0,0,0,.3); padding: .05rem .3rem; border-radius: 3px; color: #c8a96e; font-size: .9em; }

.tv-attach-row { border: 2px dashed rgba(255,255,255,0.1); border-radius: 10px; padding: .9rem; margin-top: .9rem; transition: all .2s ease; cursor: pointer; position: relative; }
.tv-attach-row:hover { border-color: rgba(200,169,110,.4); background: rgba(139,69,19,.05); }
.tv-attach-row input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.tv-attach-row .placeholder { color: #8899aa; font-size: .85rem; }

.tv-status-row {
    display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
    margin-top: 1rem;
}
.tv-status-row label { font-size: .72rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px; }
.tv-status-row select {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); color: #e2e8f0;
    border-radius: 6px; padding: .35rem .65rem; font-size: .85rem;
}
.tv-status-row select option { background: #1a1a2e; }

.tv-actions { display: flex; gap: .6rem; margin-top: 1rem; flex-wrap: wrap; }
.tv-btn { padding: .65rem 1.3rem; border-radius: 8px; font-weight: 700; font-size: .88rem; letter-spacing: .4px; cursor: pointer; border: 1px solid; transition: all .2s ease; display: inline-flex; align-items: center; gap: .4rem; text-decoration: none; }
.tv-btn-primary { background: linear-gradient(135deg, #8B4513, #A0522D); border-color: #A0522D; color: #fff; }
.tv-btn-primary:hover { background: linear-gradient(135deg, #A0522D, #c8a96e); transform: translateY(-1px); box-shadow: 0 6px 18px rgba(139,69,19,.35); }
.tv-btn-danger { background: rgba(220,53,69,0.08); border-color: rgba(220,53,69,0.4); color: #f87e8a; }
.tv-btn-danger:hover { background: rgba(220,53,69,0.18); border-color: rgba(220,53,69,0.6); color: #fff; }
.tv-btn-secondary { background: rgba(105,204,240,0.08); border-color: rgba(105,204,240,0.4); color: #69ccf0; }
.tv-btn-secondary:hover { background: rgba(105,204,240,0.18); border-color: rgba(105,204,240,0.6); color: #cfe9f6; }
.tv-btn-warn { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.4); color: #fbbf24; }
.tv-btn-warn:hover { background: rgba(245,158,11,.18); border-color: rgba(245,158,11,.6); color: #fff; }

.tv-toast { border-radius: 10px; padding: .8rem 1.1rem; margin-bottom: 1rem; background: rgba(93,216,124,0.12); border: 1px solid rgba(93,216,124,0.4); color: #5dd87c; font-size: .9rem; animation: tv-toast-in .35s ease; }
@keyframes tv-toast-in { from { transform: translateY(-10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>

<div class="container tv-wrap px-3">
    <a class="tv-back" href="/admin_dashboard#tab-tickets">← <?= htmlspecialchars($TEXT['admin_support_tickets'] ?? 'Support Tickets') ?></a>

    <?php if ($action_toast === 'replied'): ?>
        <div class="tv-toast" style="margin-top:1rem"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_replied'] ?? 'Reply sent.') ?></div>
    <?php elseif ($action_toast === 'status_changed'): ?>
        <div class="tv-toast" style="margin-top:1rem"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['admin_ticket_status_updated'] ?? 'Status updated.') ?></div>
    <?php endif; ?>

    <div class="tv-header">
        <div class="meta-row">
            <span class="tv-status <?= $status_class ?>"><?= htmlspecialchars($status_label) ?></span>
            <span style="color:#4a5568">#<?= $ticket_id ?></span>
            <span style="color:<?= $cat['color'] ?>"><i class="bi <?= $cat['icon'] ?>"></i> <?= htmlspecialchars($cat['label']) ?></span>
            <span style="color:#4a5568;margin-left:auto"><?= htmlspecialchars($TEXT['tickets_opened'] ?? 'Opened') ?>: <?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?></span>
        </div>
        <h1><?= htmlspecialchars($ticket['subject']) ?></h1>

        <div class="tv-user-info">
            <span><span class="label"><?= htmlspecialchars($TEXT['admin_col_username'] ?? 'Username') ?>:</span> <span class="value username"><?= htmlspecialchars($ticket['username']) ?></span></span>
            <span><span class="label"><?= htmlspecialchars($TEXT['admin_col_email'] ?? 'Email') ?>:</span> <span class="value"><?= htmlspecialchars($ticket['email']) ?></span></span>
            <span style="margin-left:auto;font-size:.8rem;color:#6c7a8c"><i class="bi bi-person"></i> ID <?= (int)$ticket['user_id'] ?></span>
        </div>
    </div>

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
                            $display = preg_replace('/^[a-f0-9.]+_/', '', $a);
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

    <!-- Reply (admins can always reply, even to closed tickets — they just have to reopen first conceptually, but we let them do it inline by setting status_after=open) -->
    <form class="tv-reply" method="POST" action="/admin_ticket/<?= $ticket_id ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <input type="hidden" name="ticket_action" value="reply">
        <h3><i class="bi bi-reply me-2"></i><?= htmlspecialchars($TEXT['admin_send_reply'] ?? 'Send Reply') ?></h3>
        <textarea name="reply_text" maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['admin_reply_placeholder'] ?? 'Type your reply…') ?>" required></textarea>

        <div class="tv-md-hint">
            <i class="bi bi-markdown"></i>
            <?= htmlspecialchars($TEXT['tickets_md_hint'] ?? 'Markdown supported') ?>
            — <code>**bold**</code> <code>*italic*</code> <code>`code`</code> <code>[link](url)</code>
        </div>

        <label class="tv-attach-row">
            <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp">
            <div class="placeholder"><i class="bi bi-paperclip"></i> <?= htmlspecialchars($TEXT['tickets_attach_files'] ?? 'Attach images') ?> <span style="color:#4a5568;font-size:.78rem">(<?= htmlspecialchars($TEXT['ticket_attachments_info'] ?? 'max 5, 3MB each, JPG/PNG/WEBP') ?>)</span></div>
        </label>

        <div class="tv-status-row">
            <label><?= htmlspecialchars($TEXT['admin_set_status'] ?? 'Set Status') ?>:</label>
            <select name="status_after">
                <option value="in_progress" <?= $ticket['status'] === 'in_progress' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['admin_js_status_in_progress'] ?? 'In Progress') ?></option>
                <option value="open"        <?= $ticket['status'] === 'open' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['admin_js_status_open'] ?? 'Open') ?></option>
                <option value="closed"      <?= $ticket['status'] === 'closed' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['admin_js_status_closed'] ?? 'Closed') ?></option>
            </select>
        </div>

        <div class="tv-actions">
            <button type="submit" class="tv-btn tv-btn-primary"><i class="bi bi-send"></i> <?= htmlspecialchars($TEXT['admin_send_reply'] ?? 'Send Reply') ?></button>
        </div>
    </form>

    <!-- Status-only quick actions (no reply) -->
    <div class="tv-actions" style="margin-top:1rem">
        <?php if ($ticket['status'] !== 'closed'): ?>
            <form method="POST" action="/admin_ticket/<?= $ticket_id ?>" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="ticket_action" value="close">
                <button type="submit" class="tv-btn tv-btn-danger"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($TEXT['admin_js_close_btn'] ?? 'Close') ?></button>
            </form>
        <?php else: ?>
            <form method="POST" action="/admin_ticket/<?= $ticket_id ?>" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="ticket_action" value="reopen">
                <button type="submit" class="tv-btn tv-btn-secondary"><i class="bi bi-arrow-counterclockwise"></i> <?= htmlspecialchars($TEXT['admin_js_reopen'] ?? 'Reopen') ?></button>
            </form>
        <?php endif; ?>
        <?php if ($ticket['status'] === 'open'): ?>
            <form method="POST" action="/admin_ticket/<?= $ticket_id ?>" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <input type="hidden" name="ticket_action" value="mark_in_progress">
                <button type="submit" class="tv-btn tv-btn-warn"><i class="bi bi-arrow-right-circle"></i> <?= htmlspecialchars($TEXT['admin_js_status_in_progress'] ?? 'In Progress') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Lightbox -->
<div class="tv-lightbox" id="lightbox" onclick="closeLightbox(event)">
    <button class="tv-lightbox-close" onclick="closeLightbox(event)">&times;</button>
    <img id="lightboxImg" src="" alt="">
</div>

<script>
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
    if (e && e.target && e.target.tagName === 'IMG') return;
    document.getElementById('lightbox').classList.remove('show');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('lightbox').classList.remove('show'); });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
