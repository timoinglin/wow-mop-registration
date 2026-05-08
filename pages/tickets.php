<?php
require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// Feature guard
if (empty($config['features']['tickets'])) {
    header('Location: /dashboard');
    exit;
}

$errors  = [];
$success = false;

// ─── User-side ticket actions: reply / close / reopen ────────────────────────
// Each action validates ownership before mutating. PRG redirect afterwards
// keeps refreshes idempotent.
$post_action = $_POST['ticket_action'] ?? '';
if ($post_action !== '' && in_array($post_action, ['reply', 'close', 'reopen'], true)) {
    $action_csrf  = $_POST['csrf_token'] ?? null;
    $action_tid   = (int)($_POST['ticket_id'] ?? 0);
    $action_reply = trim((string)($_POST['reply_text'] ?? ''));

    if (!validate_csrf_token($action_csrf)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    }
    if (empty($errors) && $action_tid <= 0) {
        $errors[] = $TEXT['ticket_action_invalid'] ?? 'Invalid ticket.';
    }

    if (empty($errors)) {
        try {
            // Ownership check — must be the user's own ticket
            $own = $pdo_auth->prepare("SELECT id, status FROM tickets WHERE id = :id AND user_id = :uid LIMIT 1");
            $own->execute(['id' => $action_tid, 'uid' => $_SESSION['user_id']]);
            $ticket_row = $own->fetch();

            if (!$ticket_row) {
                http_response_code(403);
                $errors[] = $TEXT['ticket_action_forbidden'] ?? 'You can only act on your own tickets.';
            } else {
                $current_status = $ticket_row['status'];

                if ($post_action === 'reply') {
                    if ($action_reply === '') {
                        $errors[] = $TEXT['ticket_required_message'] ?? 'Message is required.';
                    } elseif (mb_strlen($action_reply) > 2000) {
                        $errors[] = $TEXT['ticket_reply_too_long'] ?? 'Reply is too long (max 2000 characters).';
                    } elseif ($current_status === 'closed') {
                        $errors[] = $TEXT['ticket_action_closed'] ?? 'You cannot reply to a closed ticket. Reopen it first.';
                    } else {
                        $pdo_auth->beginTransaction();
                        try {
                            $msg = $pdo_auth->prepare(
                                "INSERT INTO ticket_messages (ticket_id, sender_type, sender_username, message, created_at)
                                 VALUES (:tid, 'user', :name, :msg, NOW())"
                            );
                            $msg->execute([
                                'tid'  => $action_tid,
                                'name' => $_SESSION['username'] ?? 'User',
                                'msg'  => $action_reply,
                            ]);
                            // Bump status back to in_progress on a user follow-up so admins see it as live
                            $newst = ($current_status === 'closed') ? 'in_progress' : 'in_progress';
                            $upd = $pdo_auth->prepare("UPDATE tickets SET status = :st, updated_at = NOW() WHERE id = :id");
                            $upd->execute(['st' => $newst, 'id' => $action_tid]);
                            $pdo_auth->commit();
                            header('Location: /tickets?tab=history&action=replied&tid=' . $action_tid . '#ticket-' . $action_tid);
                            exit;
                        } catch (Exception $e) {
                            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
                            error_log('User ticket reply failed: ' . $e->getMessage());
                            $errors[] = $TEXT['error_db'] ?? 'Database error.';
                        }
                    }
                } elseif ($post_action === 'close') {
                    if ($current_status === 'closed') {
                        // Idempotent — just redirect
                        header('Location: /tickets?tab=history#ticket-' . $action_tid);
                        exit;
                    }
                    $upd = $pdo_auth->prepare("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = :id AND user_id = :uid");
                    $upd->execute(['id' => $action_tid, 'uid' => $_SESSION['user_id']]);
                    header('Location: /tickets?tab=history&action=closed&tid=' . $action_tid . '#ticket-' . $action_tid);
                    exit;
                } elseif ($post_action === 'reopen') {
                    if ($current_status !== 'closed') {
                        header('Location: /tickets?tab=history#ticket-' . $action_tid);
                        exit;
                    }
                    $upd = $pdo_auth->prepare("UPDATE tickets SET status = 'in_progress', updated_at = NOW() WHERE id = :id AND user_id = :uid");
                    $upd->execute(['id' => $action_tid, 'uid' => $_SESSION['user_id']]);
                    header('Location: /tickets?tab=history&action=reopened&tid=' . $action_tid . '#ticket-' . $action_tid);
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log('User ticket action failed: ' . $e->getMessage());
            $errors[] = $TEXT['error_db'] ?? 'Database error.';
        }
    }
}

$stmt = $pdo_auth->prepare("SELECT username, email FROM account WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$account_row = $stmt->fetch();
$user_email    = $account_row['email']    ?? '';
$user_username = $account_row['username'] ?? '';

$categories = [
    'account'    => ['label' => $TEXT['tickets_cat_account']    ?? 'Account Issues', 'icon' => 'bi-person-exclamation', 'color' => '#69CCF0'],
    'bug'        => ['label' => $TEXT['tickets_cat_bug']        ?? 'Report Bug',     'icon' => 'bi-bug',                'color' => '#ABD473'],
    'player'     => ['label' => $TEXT['tickets_cat_player']     ?? 'Report Player',  'icon' => 'bi-shield-exclamation', 'color' => '#F58CBA'],
    'payment'    => ['label' => $TEXT['tickets_cat_payment']    ?? 'Payment Issues', 'icon' => 'bi-credit-card',        'color' => '#FFF569'],
    'suggestion' => ['label' => $TEXT['tickets_cat_suggestion'] ?? 'Suggestion',     'icon' => 'bi-lightbulb',          'color' => '#C79C6E'],
    'other'      => ['label' => $TEXT['tickets_cat_other']      ?? 'Other',          'icon' => 'bi-chat-dots',          'color' => '#9482C9'],
];

$selected_category = $_POST['category'] ?? '';
$subject_val = htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES);
$message_val = htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES);

// Active tab
$active_tab = $_GET['tab'] ?? 'new';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject  = trim($_POST['subject']  ?? '');
    $category = $_POST['category']      ?? '';
    $message  = trim($_POST['message']  ?? '');
    $files    = $_FILES['attachments']  ?? null;
    $csrf     = $_POST['csrf_token']    ?? null;

    if (!validate_csrf_token($csrf)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    }
    if (empty($subject)) {
        $errors[] = $TEXT['ticket_required_subject'];
    }
    if (empty($category) || !array_key_exists($category, $categories)) {
        $errors[] = $TEXT['ticket_required_category'];
    }
    if (empty($message)) {
        $errors[] = $TEXT['ticket_required_message'];
    }

    // File uploads
    $uploaded_files = [];
    $attachment_names = [];
    if (empty($errors) && $files && $files['name'][0] !== '') {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $max_size  = 3 * 1024 * 1024;
        $max_files = 5;

        if (count($files['name']) > $max_files) {
            $errors[] = str_replace('{count}', $max_files, $TEXT['ticket_max_files']);
        }

        foreach ($files['tmp_name'] as $key => $tmp_name) {
            if ($files['error'][$key] === UPLOAD_ERR_OK) {
                $file_type = mime_content_type($tmp_name);
                $file_size = $files['size'][$key];

                if (!in_array($file_type, $allowed_types)) {
                    $errors[] = str_replace('{filename}', $files['name'][$key], $TEXT['ticket_invalid_file_type']);
                    continue;
                }
                if ($file_size > $max_size) {
                    $errors[] = str_replace('{filename}', $files['name'][$key], $TEXT['ticket_file_too_large']);
                    continue;
                }

                $upload_dir = __DIR__ . '/../../uploads/tickets/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name   = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($files['name'][$key]));
                $target_path = $upload_dir . $file_name;

                if (move_uploaded_file($tmp_name, $target_path)) {
                    $uploaded_files[] = $target_path;
                    $attachment_names[] = $file_name;
                }
            }
        }
    }

    if (empty($errors)) {
        // Save ticket to database
        try {
            $stmt = $pdo_auth->prepare(
                "INSERT INTO tickets (user_id, username, email, category, subject, message, attachments, created_at)
                 VALUES (:uid, :uname, :email, :cat, :subj, :msg, :attach, NOW())"
            );
            $stmt->execute([
                'uid'    => $_SESSION['user_id'],
                'uname'  => $user_username,
                'email'  => $user_email,
                'cat'    => $category,
                'subj'   => $subject,
                'msg'    => $message,
                'attach' => !empty($attachment_names) ? json_encode($attachment_names) : null,
            ]);

            // Also send email notification to admin
            $server_name = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');
            $cat_label   = $categories[$category]['label'] ?? $category;

            $inner = "
                <h2 style='color:#c8a96e;margin-top:0'>&#127903; New Support Ticket</h2>
                <table style='width:100%;border-collapse:collapse;font-size:14px'>
                  <tr><td style='padding:6px 0;color:#8899aa;width:120px'>Server</td>
                      <td style='padding:6px 0;color:#e2e8f0;font-weight:600'>{$server_name}</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>From</td>
                      <td style='padding:6px 0;color:#e2e8f0'>"
                      . htmlspecialchars($user_username) . " &lt;" . htmlspecialchars($user_email) . "&gt;</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>Category</td>
                      <td style='padding:6px 0;color:#e2e8f0'>{$cat_label}</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>Subject</td>
                      <td style='padding:6px 0;color:#e2e8f0;font-weight:600'>"
                      . htmlspecialchars($subject) . "</td></tr>
                </table>
                <hr style='border:none;border-top:1px solid #2a2a3e;margin:20px 0'>
                <p style='color:#8899aa;margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:1px'>Message</p>
                <div style='background:#1a1a2e;border-left:3px solid #c8a96e;padding:16px;border-radius:6px;white-space:pre-line'>"
                    . htmlspecialchars($message) . "</div>
            ";

            if (!empty($uploaded_files)) {
                $inner .= "<p style='margin-top:16px;color:#8899aa;font-size:13px'>&#128206; " . count($uploaded_files) . " attachment(s) included.</p>";
            }

            $email_body      = email_template($inner, "New ticket from {$user_username}: {$subject}");
            $ticket_to       = $config['smtp']['ticket_recipient'] ?? $config['smtp']['username'] ?? '';

            @send_ticket_email($ticket_to, "[{$server_name} Ticket] {$subject}", $email_body, $uploaded_files);

            $success = true;

        } catch (PDOException $e) {
            error_log("Ticket DB Error: " . $e->getMessage());
            $errors[] = $TEXT['ticket_error'];
        }
    }
}

// Load user's tickets for the "My Tickets" tab + their conversation threads
$my_tickets = [];
$ticket_threads = []; // [ticket_id => [ {sender_type, sender_username, message, created_at}, ... ]]
try {
    $stmt = $pdo_auth->prepare("SELECT * FROM tickets WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $my_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($my_tickets)) {
        $ticket_ids = array_column($my_tickets, 'id');
        $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
        $msg_stmt = $pdo_auth->prepare(
            "SELECT ticket_id, sender_type, sender_username, message, created_at
             FROM ticket_messages
             WHERE ticket_id IN ($placeholders)
             ORDER BY created_at ASC, id ASC"
        );
        $msg_stmt->execute($ticket_ids);
        foreach ($msg_stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $ticket_threads[(int)$m['ticket_id']][] = $m;
        }
    }
} catch (PDOException $e) {
    error_log("Ticket history error: " . $e->getMessage());
}

$open_count = count(array_filter($my_tickets, fn($t) => $t['status'] === 'open'));
$progress_count = count(array_filter($my_tickets, fn($t) => $t['status'] === 'in_progress'));

// Toast banner from PRG redirect (?action=replied|closed|reopened&tid=N)
$action_toast = $_GET['action'] ?? '';
$action_toast_tid = (int)($_GET['tid'] ?? 0);

require_once __DIR__ . '/../templates/header.php';
?>

<style>
.ticket-wrap {
    padding-top: 90px;
    padding-bottom: 3rem;
}

/* Page header */
.ticket-header {
    background: linear-gradient(135deg, rgba(139,69,19,0.35) 0%, rgba(10,10,20,0.9) 70%),
                url('/assets/img/wow-bg/4-2.webp') center/cover no-repeat;
    border: 1px solid rgba(139,69,19,0.4);
    border-radius: 16px;
    padding: 2.2rem 2rem;
    margin-bottom: 2rem;
}
.ticket-header h1 {
    font-size: 1.9rem;
    font-weight: 700;
    letter-spacing: 1.5px;
    background: linear-gradient(90deg, #c8a96e, #fff 60%, #c8a96e);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
}
.ticket-header p {
    color: rgba(200,169,110,.7);
    font-size: .9rem;
    margin: .4rem 0 0;
}

/* Tab navigation */
.ticket-tabs {
    display: flex;
    gap: .5rem;
    margin-bottom: 1.5rem;
}
.ticket-tab-btn {
    padding: .7rem 1.5rem;
    border-radius: 10px;
    border: 1px solid rgba(139,69,19,0.3);
    background: rgba(255,255,255,0.03);
    color: #8899aa;
    font-weight: 600;
    font-size: .88rem;
    cursor: pointer;
    transition: all .2s ease;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.ticket-tab-btn:hover { background: rgba(255,255,255,0.07); color: #c8a96e; }
.ticket-tab-btn.active {
    background: linear-gradient(135deg, rgba(139,69,19,0.3), rgba(139,69,19,0.15));
    border-color: rgba(200,169,110,0.5);
    color: #c8a96e;
}
.tab-badge {
    background: rgba(200,169,110,0.2);
    color: #c8a96e;
    font-size: .72rem;
    padding: .1rem .45rem;
    border-radius: 6px;
    font-weight: 700;
}

/* Panel */
.ticket-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(139,69,19,0.25);
    border-radius: 14px;
    padding: 2rem;
}
.panel-section-title {
    font-size: .72rem;
    color: #c8a96e;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    margin-bottom: 1rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid rgba(139,69,19,0.25);
}

/* Category Cards */
.category-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .75rem;
    margin-bottom: 1.8rem;
}
@media(max-width:576px) { .category-grid { grid-template-columns: repeat(2, 1fr); } }

.cat-card {
    position: relative;
    padding: 1rem .8rem;
    border-radius: 10px;
    border: 2px solid rgba(255,255,255,0.07);
    background: rgba(255,255,255,0.03);
    cursor: pointer;
    transition: all .2s ease;
    text-align: center;
    user-select: none;
}
.cat-card:hover {
    background: rgba(255,255,255,0.07);
    border-color: rgba(255,255,255,0.2);
    transform: translateY(-2px);
}
.cat-card.selected {
    background: rgba(139,69,19,0.2);
    border-color: var(--cat-color, #c8a96e);
    box-shadow: 0 0 16px rgba(139,69,19,0.25);
}
.cat-card i {
    font-size: 1.5rem;
    display: block;
    margin-bottom: .4rem;
}
.cat-card span {
    font-size: .78rem;
    font-weight: 600;
    color: #c0c8d8;
}
.cat-card.selected span { color: #fff; }
.cat-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }

/* Form fields */
.ticket-label {
    font-size: .78rem;
    color: #8899aa;
    text-transform: uppercase;
    letter-spacing: .8px;
    font-weight: 600;
    margin-bottom: .5rem;
    display: block;
}
.ticket-input {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    color: #e2e8f0;
    padding: .85rem 1rem;
    font-size: .95rem;
    transition: border-color .2s ease, box-shadow .2s ease;
    outline: none;
    resize: vertical;
}
.ticket-input:focus {
    border-color: rgba(200,169,110,0.5);
    box-shadow: 0 0 0 3px rgba(139,69,19,0.15);
    background: rgba(255,255,255,0.07);
}
.ticket-input::placeholder { color: #4a5568; }
.char-count { font-size: .75rem; color: #4a5568; text-align: right; margin-top: .3rem; }
.char-count.warn { color: #f6ad55; }
.char-count.over { color: #f87171; }

/* File drop zone */
.drop-zone {
    border: 2px dashed rgba(255,255,255,0.12);
    border-radius: 10px;
    padding: 1.8rem 1rem;
    text-align: center;
    cursor: pointer;
    transition: all .2s ease;
    background: rgba(255,255,255,0.02);
    position: relative;
}
.drop-zone:hover, .drop-zone.dragover {
    border-color: rgba(200,169,110,0.4);
    background: rgba(139,69,19,0.08);
}
.drop-zone i { font-size: 2rem; color: #4a5568; display: block; margin-bottom: .5rem; }
.drop-zone p  { color: #8899aa; font-size: .85rem; margin: 0; }
.drop-zone small { color: #4a5568; font-size: .75rem; }
.drop-zone input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.file-list { margin-top: .75rem; display: flex; flex-wrap: wrap; gap: .5rem; }
.file-pill {
    background: rgba(200,169,110,0.12);
    border: 1px solid rgba(200,169,110,0.25);
    color: #c8a96e;
    font-size: .75rem;
    padding: .2rem .6rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: .3rem;
}

/* Submit button */
.submit-btn {
    width: 100%;
    padding: 1rem;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #8B4513, #A0522D);
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all .25s ease;
    margin-top: 1.5rem;
}
.submit-btn:hover {
    background: linear-gradient(135deg, #A0522D, #c8a96e);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(139,69,19,0.4);
}
.submit-btn:active { transform: translateY(0); }

/* Success card */
.success-card {
    text-align: center;
    padding: 3.5rem 2rem;
}
.success-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(93,216,124,0.15);
    border: 2px solid rgba(93,216,124,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    font-size: 2rem;
    animation: pop .4s ease;
}
@keyframes pop {
    0%   { transform: scale(0); opacity: 0; }
    70%  { transform: scale(1.15); }
    100% { transform: scale(1); opacity: 1; }
}
.success-card h2 { color: #5dd87c; font-weight: 700; margin-bottom: .5rem; }
.success-card p  { color: #8899aa; }

/* Ticket History */
.ticket-history-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 1.2rem 1.4rem;
    margin-bottom: .75rem;
    transition: all .2s ease;
    cursor: pointer;
}
.ticket-history-card:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(200,169,110,0.3);
}
.ticket-history-card.expanded {
    border-color: rgba(200,169,110,0.4);
    background: rgba(139,69,19,0.08);
}
.ticket-status {
    display: inline-block;
    padding: .15rem .55rem;
    border-radius: 6px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.status-open        { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.status-in_progress { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.status-closed      { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

.ticket-detail {
    display: none;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255,255,255,0.08);
}
.ticket-detail.show { display: block; }
.admin-reply-box {
    background: rgba(93,216,124,0.06);
    border-left: 3px solid #5dd87c;
    padding: 1rem;
    border-radius: 0 8px 8px 0;
    margin-top: .75rem;
}
.no-tickets {
    text-align: center;
    padding: 3rem 2rem;
    color: #8899aa;
}
.no-tickets i { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: 1rem; }

/* ── Conversation thread bubbles ─────────────────────────────────────── */
.thread-bubble {
    margin-bottom: .8rem;
    padding: .85rem 1rem;
    border-radius: 10px;
    font-size: .9rem;
    line-height: 1.55;
}
.thread-bubble:last-of-type { margin-bottom: 1rem; }
.thread-bubble .thread-meta {
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: .35rem;
    color: #8899aa;
}
.thread-bubble .thread-body { color: #e2e8f0; white-space: pre-wrap; word-wrap: break-word; }
.thread-bubble.thread-user {
    background: rgba(255,255,255,0.03);
    border-left: 3px solid rgba(200,169,110,0.4);
}
.thread-bubble.thread-user .thread-meta { color: #c8a96e; }
.thread-bubble.thread-admin {
    background: rgba(93,216,124,0.06);
    border-left: 3px solid #5dd87c;
}
.thread-bubble.thread-admin .thread-meta { color: #5dd87c; }

/* ── Reply form (inline inside expanded ticket) ──────────────────────── */
.thread-reply-form {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px dashed rgba(255,255,255,0.08);
}
.thread-reply-form .ticket-input { font-size: .9rem; }
.thread-closed-msg {
    color: #8899aa;
    font-size: .85rem;
    padding: .6rem 0;
    font-style: italic;
}

.thread-btn {
    padding: .55rem 1.1rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: .85rem;
    letter-spacing: .3px;
    cursor: pointer;
    border: 1px solid;
    transition: all .2s ease;
    display: inline-flex;
    align-items: center;
}
.thread-btn-primary {
    background: linear-gradient(135deg, #8B4513, #A0522D);
    border-color: #A0522D;
    color: #fff;
}
.thread-btn-primary:hover {
    background: linear-gradient(135deg, #A0522D, #c8a96e);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(139,69,19,.35);
}
.thread-btn-danger {
    background: rgba(220,53,69,0.08);
    border-color: rgba(220,53,69,0.4);
    color: #f87e8a;
}
.thread-btn-danger:hover {
    background: rgba(220,53,69,0.18);
    border-color: rgba(220,53,69,0.6);
    color: #fff;
}
.thread-btn-secondary {
    background: rgba(105,204,240,0.08);
    border-color: rgba(105,204,240,0.4);
    color: #69ccf0;
}
.thread-btn-secondary:hover {
    background: rgba(105,204,240,0.18);
    border-color: rgba(105,204,240,0.6);
    color: #cfe9f6;
}

/* ── PRG toast banner ────────────────────────────────────────────────── */
.thread-toast {
    border-radius: 10px;
    padding: .75rem 1.1rem;
    font-size: .9rem;
    margin-bottom: 1rem;
    animation: thread-toast-in .35s ease;
}
.thread-toast-success {
    background: rgba(93,216,124,0.12);
    border: 1px solid rgba(93,216,124,0.4);
    color: #5dd87c;
}
@keyframes thread-toast-in {
    from { transform: translateY(-10px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}
</style>

<div class="container ticket-wrap px-3">

    <!-- Header -->
    <div class="ticket-header">
        <h1><i class="bi bi-ticket-perforated me-2"></i><?= $TEXT['submit_ticket'] ?></h1>
        <p><?= sprintf($TEXT['tickets_intro_html'] ?? 'Describe your issue — our team will respond to <strong style="color:#c8a96e">%s</strong>', htmlspecialchars($user_email)) ?></p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3" style="border-radius:10px;border:1px solid rgba(220,53,69,.4);background:rgba(220,53,69,.12)">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="ticket-tabs">
        <button class="ticket-tab-btn <?= $active_tab === 'new' ? 'active' : '' ?>" onclick="switchTicketTab('new')">
            <i class="bi bi-plus-circle"></i> <?= htmlspecialchars($TEXT['tickets_tab_new'] ?? 'New Ticket') ?>
        </button>
        <button class="ticket-tab-btn <?= $active_tab === 'history' ? 'active' : '' ?>" onclick="switchTicketTab('history')">
            <i class="bi bi-clock-history"></i> <?= htmlspecialchars($TEXT['tickets_tab_my'] ?? 'My Tickets') ?>
            <?php if ($open_count + $progress_count > 0): ?>
                <span class="tab-badge"><?= $open_count + $progress_count ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- NEW TICKET TAB -->
    <div id="tab-new" class="ticket-panel" style="<?= $active_tab !== 'new' ? 'display:none' : '' ?>">

    <?php if ($success): ?>
        <!-- SUCCESS STATE -->
        <div class="success-card">
            <div class="success-icon">✓</div>
            <h2><?= htmlspecialchars($TEXT['tickets_submitted_title'] ?? 'Ticket Submitted!') ?></h2>
            <p><?= $TEXT['ticket_success'] ?></p>
            <div class="d-flex gap-2 justify-content-center mt-3">
                <a href="/tickets?tab=history" class="action-btn action-btn-secondary" style="
                    display:inline-flex;align-items:center;gap:.5rem;padding:.8rem 1.5rem;
                    border-radius:10px;font-weight:700;text-decoration:none;
                    background:rgba(255,255,255,0.05);color:#c8a96e;border:1px solid rgba(200,169,110,0.3);
                    transition:all .2s ease;
                ">
                    <i class="bi bi-clock-history"></i> <?= htmlspecialchars($TEXT['tickets_view_my_btn'] ?? 'View My Tickets') ?>
                </a>
                <a href="/dashboard" class="action-btn action-btn-primary" style="
                    display:inline-flex;align-items:center;gap:.5rem;padding:.8rem 1.5rem;
                    border-radius:10px;font-weight:700;text-decoration:none;
                    background:linear-gradient(135deg,#8B4513,#A0522D);color:#fff;
                    transition:all .2s ease;
                ">
                    <i class="bi bi-speedometer2"></i> <?= htmlspecialchars($TEXT['dashboard'] ?? 'Dashboard') ?>
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- FORM -->
        <form action="/tickets" method="POST" enctype="multipart/form-data" id="ticketForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="category" id="categoryInput" value="<?= htmlspecialchars($selected_category) ?>">

            <!-- Category Picker -->
            <div class="panel-section-title"><i class="bi bi-tag me-2"></i><?= $TEXT['ticket_category'] ?></div>
            <div class="category-grid mb-4">
            <?php foreach ($categories as $key => $cat): ?>
                <div class="cat-card <?= $selected_category === $key ? 'selected' : '' ?>"
                     style="--cat-color:<?= $cat['color'] ?>"
                     data-key="<?= $key ?>"
                     data-color="<?= $cat['color'] ?>"
                     onclick="selectCategory(this)">
                    <i class="bi <?= $cat['icon'] ?>" style="color:<?= $cat['color'] ?>"></i>
                    <span><?= htmlspecialchars($cat['label']) ?></span>
                </div>
            <?php endforeach; ?>
            </div>

            <!-- Subject -->
            <div class="mb-4">
                <label class="ticket-label"><i class="bi bi-pencil me-1"></i><?= $TEXT['ticket_subject'] ?></label>
                <input type="text" class="ticket-input" name="subject" id="subject"
                       maxlength="120" placeholder="<?= htmlspecialchars($TEXT['tickets_subject_placeholder'] ?? 'Brief summary of your issue…') ?>"
                       value="<?= $subject_val ?>"
                       oninput="updateCount(this,'subjectCount',120)" required>
                <div class="char-count" id="subjectCount"><?= strlen($_POST['subject'] ?? '') ?>/120</div>
            </div>

            <!-- Message -->
            <div class="mb-4">
                <label class="ticket-label"><i class="bi bi-chat-text me-1"></i><?= $TEXT['ticket_message'] ?></label>
                <textarea class="ticket-input" name="message" id="message" rows="6"
                          maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['tickets_message_placeholder'] ?? 'Describe your issue in detail…') ?>"
                          oninput="updateCount(this,'msgCount',2000)" required><?= $message_val ?></textarea>
                <div class="char-count" id="msgCount"><?= strlen($_POST['message'] ?? '') ?>/2000</div>
            </div>

            <!-- File Attachments -->
            <div class="mb-2">
                <label class="ticket-label"><i class="bi bi-paperclip me-1"></i><?= $TEXT['ticket_attachments'] ?> <span style="color:#4a5568;font-weight:400;text-transform:none">(<?= htmlspecialchars($TEXT['common_optional'] ?? 'Optional') ?>)</span></label>
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="attachments[]" id="attachments"
                           multiple accept=".jpg,.jpeg,.png,.webp"
                           onchange="showFiles(this)">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <p><?= $TEXT['tickets_drop_zone_text'] ?? 'Drag &amp; drop files here, or <strong style="color:#c8a96e">browse</strong>' ?></p>
                    <small><?= $TEXT['ticket_attachments_info'] ?></small>
                </div>
                <div class="file-list" id="fileList"></div>
            </div>

            <button type="submit" class="submit-btn" id="submitBtn">
                <i class="bi bi-send me-2"></i><?= $TEXT['submit_ticket'] ?>
            </button>
        </form>
    <?php endif; ?>

    </div><!-- /tab-new -->

    <!-- MY TICKETS TAB -->
    <div id="tab-history" class="ticket-panel" style="<?= $active_tab !== 'history' ? 'display:none' : '' ?>">
        <div class="panel-section-title"><i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($TEXT['tickets_tab_my'] ?? 'My Tickets') ?></div>

        <?php if ($action_toast === 'replied'): ?>
            <div class="thread-toast thread-toast-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_replied'] ?? 'Reply sent. Our team will see it.') ?></div>
        <?php elseif ($action_toast === 'closed'): ?>
            <div class="thread-toast thread-toast-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_closed'] ?? 'Ticket closed.') ?></div>
        <?php elseif ($action_toast === 'reopened'): ?>
            <div class="thread-toast thread-toast-success"><i class="bi bi-arrow-counterclockwise me-2"></i><?= htmlspecialchars($TEXT['tickets_toast_reopened'] ?? 'Ticket reopened.') ?></div>
        <?php endif; ?>

        <?php if (empty($my_tickets)): ?>
            <div class="no-tickets">
                <i class="bi bi-ticket-perforated"></i>
                <p><?= htmlspecialchars($TEXT['tickets_no_tickets'] ?? "You haven't submitted any tickets yet.") ?></p>
                <button class="submit-btn" style="max-width:250px;margin:1rem auto 0" onclick="switchTicketTab('new')">
                    <i class="bi bi-plus-circle me-2"></i><?= htmlspecialchars($TEXT['tickets_create_btn'] ?? 'Create Ticket') ?>
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($my_tickets as $t):
                $tid = (int)$t['id'];
                $status_label = $t['status'] === 'open'
                    ? '● ' . ($TEXT['tickets_status_open'] ?? 'Open')
                    : ($t['status'] === 'in_progress'
                        ? '◐ ' . ($TEXT['tickets_status_in_progress'] ?? 'In Progress')
                        : '○ ' . ($TEXT['tickets_status_closed'] ?? 'Closed'));
                $thread = $ticket_threads[$tid] ?? [];
                $auto_open = ($action_toast_tid === $tid);
                $msg_count = count($thread);
            ?>
                <div id="ticket-<?= $tid ?>" class="ticket-history-card <?= $auto_open ? 'expanded' : '' ?>" onclick="toggleTicket(event, this)">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div style="flex:1;min-width:0;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="ticket-status status-<?= htmlspecialchars($t['status']) ?>">
                                    <?= htmlspecialchars($status_label) ?>
                                </span>
                                <span style="color:#4a5568;font-size:.78rem;">#<?= $tid ?></span>
                                <span style="color:#4a5568;font-size:.78rem;">
                                    <i class="bi bi-tag"></i> <?= htmlspecialchars($categories[$t['category']]['label'] ?? $t['category']) ?>
                                </span>
                                <?php if ($msg_count > 1): ?>
                                    <span style="color:#8899aa;font-size:.72rem;"><i class="bi bi-chat-left-text"></i> <?= $msg_count ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-weight:600;color:#e2e8f0;font-size:.95rem;"><?= htmlspecialchars($t['subject']) ?></div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div style="color:#4a5568;font-size:.78rem;"><?= date('M d, Y', strtotime($t['created_at'])) ?></div>
                            <?php if ($t['admin_reply']): ?>
                                <span style="color:#5dd87c;font-size:.72rem;"><i class="bi bi-reply"></i> <?= htmlspecialchars($TEXT['tickets_replied'] ?? 'Replied') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ticket-detail <?= $auto_open ? 'show' : '' ?>" onclick="event.stopPropagation()">
                        <!-- Conversation thread -->
                        <?php if (empty($thread)): ?>
                            <!-- Fallback for any ticket missing migrated messages -->
                            <div class="thread-bubble thread-user">
                                <div class="thread-meta"><?= htmlspecialchars($TEXT['tickets_your_message'] ?? 'Your Message') ?> · <?= date('M d, Y H:i', strtotime($t['created_at'])) ?></div>
                                <div class="thread-body"><?= nl2br(htmlspecialchars($t['message'])) ?></div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($thread as $m):
                                $is_user = $m['sender_type'] === 'user';
                            ?>
                                <div class="thread-bubble <?= $is_user ? 'thread-user' : 'thread-admin' ?>">
                                    <div class="thread-meta">
                                        <?php if ($is_user): ?>
                                            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($m['sender_username']) ?>
                                        <?php else: ?>
                                            <i class="bi bi-shield-check"></i> <?= htmlspecialchars($m['sender_username']) ?> <span style="color:#5dd87c;font-size:.7rem">(<?= htmlspecialchars($TEXT['tickets_admin_reply'] ?? 'Admin') ?>)</span>
                                        <?php endif; ?>
                                        · <?= date('M d, Y H:i', strtotime($m['created_at'])) ?>
                                    </div>
                                    <div class="thread-body"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Action controls -->
                        <?php if ($t['status'] !== 'closed'): ?>
                            <!-- Reply form (open / in_progress) -->
                            <form method="POST" action="/tickets" class="thread-reply-form" onclick="event.stopPropagation()">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="ticket_action" value="reply">
                                <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                                <label class="ticket-label"><i class="bi bi-reply me-1"></i><?= htmlspecialchars($TEXT['tickets_add_reply'] ?? 'Add a reply') ?></label>
                                <textarea class="ticket-input" name="reply_text" rows="3"
                                          maxlength="2000"
                                          placeholder="<?= htmlspecialchars($TEXT['tickets_reply_placeholder'] ?? 'Write your reply…') ?>"
                                          required></textarea>
                                <div class="d-flex gap-2 mt-2 flex-wrap">
                                    <button type="submit" class="thread-btn thread-btn-primary">
                                        <i class="bi bi-send me-1"></i><?= htmlspecialchars($TEXT['tickets_send_reply'] ?? 'Send Reply') ?>
                                    </button>
                                    <button type="submit" formaction="/tickets" class="thread-btn thread-btn-danger"
                                            formnovalidate
                                            onclick="this.form.querySelector('[name=ticket_action]').value='close';this.form.querySelector('[name=reply_text]').removeAttribute('required');">
                                        <i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($TEXT['tickets_close_ticket'] ?? 'Close Ticket') ?>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Closed: show reopen button only -->
                            <form method="POST" action="/tickets" class="thread-reply-form" onclick="event.stopPropagation()">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                <input type="hidden" name="ticket_action" value="reopen">
                                <input type="hidden" name="ticket_id" value="<?= $tid ?>">
                                <div class="thread-closed-msg">
                                    <i class="bi bi-lock-fill me-1"></i><?= htmlspecialchars($TEXT['tickets_thread_closed'] ?? 'This ticket is closed.') ?>
                                </div>
                                <button type="submit" class="thread-btn thread-btn-secondary mt-2">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i><?= htmlspecialchars($TEXT['tickets_reopen_ticket'] ?? 'Reopen Ticket') ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($t['updated_at']): ?>
                            <div style="color:#4a5568;font-size:.75rem;margin-top:.75rem;">
                                <?= htmlspecialchars($TEXT['tickets_last_updated'] ?? 'Last updated') ?>: <?= date('M d, Y H:i', strtotime($t['updated_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- /tab-history -->

</div><!-- /ticket-wrap -->

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<script>
// Tab switching
function switchTicketTab(tab) {
    // If we're on the success card and the user clicks "New Ticket", do a full nav
    // back to /tickets so the form re-renders fresh. Otherwise the success card stays
    // visible because it lives inside #tab-new (handoff issue #5).
    if (tab === 'new' && document.querySelector('.success-card')) {
        window.location.href = '/tickets';
        return;
    }
    document.querySelectorAll('.ticket-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-new').style.display = tab === 'new' ? '' : 'none';
    document.getElementById('tab-history').style.display = tab === 'history' ? '' : 'none';
    event.target.closest('.ticket-tab-btn').classList.add('active');
}

// Toggle ticket detail. Clicks bubbling up from the inner form/details are
// pre-stopped via onclick="event.stopPropagation()" on those elements; this
// handler only fires for clicks on the card *header*.
function toggleTicket(event, card) {
    // Defensive: skip if click started on something interactive inside the detail
    if (event && event.target && event.target.closest('.ticket-detail, button, a, input, textarea, form')) {
        return;
    }
    const detail = card.querySelector('.ticket-detail');
    const wasOpen = detail.classList.contains('show');
    document.querySelectorAll('.ticket-detail').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.ticket-history-card').forEach(c => c.classList.remove('expanded'));
    if (!wasOpen) {
        detail.classList.add('show');
        card.classList.add('expanded');
    }
}

// Category selection
function selectCategory(card) {
    document.querySelectorAll('.cat-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    document.getElementById('categoryInput').value = card.dataset.key;
}

// Character counter
function updateCount(el, counterId, max) {
    const len = el.value.length;
    const ctr = document.getElementById(counterId);
    ctr.textContent = len + '/' + max;
    ctr.className = 'char-count' + (len > max * 0.9 ? (len >= max ? ' over' : ' warn') : '');
}

// File pill display
function showFiles(input) {
    const list = document.getElementById('fileList');
    list.innerHTML = '';
    Array.from(input.files).forEach(f => {
        const pill = document.createElement('div');
        pill.className = 'file-pill';
        const kb = (f.size / 1024).toFixed(0);
        pill.innerHTML = `<i class="bi bi-image"></i> ${f.name} <span style="color:#4a5568">(${kb}KB)</span>`;
        list.appendChild(pill);
    });
}

// Drag-and-drop styling
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop',      e => {
        e.preventDefault();
        dz.classList.remove('dragover');
        const fi = document.getElementById('attachments');
        fi.files = e.dataTransfer.files;
        showFiles(fi);
    });
}

// Prevent submit without category
document.getElementById('ticketForm')?.addEventListener('submit', function(e) {
    if (!document.getElementById('categoryInput').value) {
        e.preventDefault();
        document.querySelectorAll('.cat-card').forEach(c => {
            c.style.borderColor = 'rgba(248,113,113,0.5)';
            setTimeout(() => c.style.borderColor = '', 1800);
        });
    }
});

// Init char counts on load
const subj = document.getElementById('subject');
const msg  = document.getElementById('message');
if (subj) updateCount(subj, 'subjectCount', 120);
if (msg)  updateCount(msg,  'msgCount',     2000);
</script>