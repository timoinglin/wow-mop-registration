<?php
/**
 * Tickets — list view + new ticket form (user-facing).
 *
 * Single ticket reply / close / reopen now lives at /tickets/{id}
 * (pages/ticket_view.php).
 */

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

$stmt = $pdo_auth->prepare("SELECT username, email FROM account WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$account_row   = $stmt->fetch();
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

$active_tab = $_GET['tab'] ?? 'new';

// ─── New-ticket POST handler ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject  = trim($_POST['subject']  ?? '');
    $category = $_POST['category']      ?? '';
    $message  = trim($_POST['message']  ?? '');
    $files    = $_FILES['attachments']  ?? null;
    $csrf     = $_POST['csrf_token']    ?? null;

    if (!validate_csrf_token($csrf)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token.';
    }
    if (empty($subject))                                            $errors[] = $TEXT['ticket_required_subject'];
    if (empty($category) || !array_key_exists($category, $categories)) $errors[] = $TEXT['ticket_required_category'];
    if (empty($message))                                            $errors[] = $TEXT['ticket_required_message'];

    // File uploads (FIXED PATH: was ../../uploads — landed outside the project!)
    $uploaded_files   = [];
    $attachment_names = [];
    if (empty($errors) && $files && !empty($files['name'][0])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $max_size  = 3 * 1024 * 1024;
        $max_files = 5;

        if (count($files['name']) > $max_files) {
            $errors[] = str_replace('{count}', $max_files, $TEXT['ticket_max_files']);
        }

        foreach ($files['tmp_name'] as $key => $tmp_name) {
            if (count($attachment_names) >= $max_files) break;
            if (($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $file_type = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';
            $file_size = (int)$files['size'][$key];
            $orig_name = $files['name'][$key] ?? '';

            if (!in_array($file_type, $allowed_types, true)) {
                $errors[] = str_replace('{filename}', $orig_name, $TEXT['ticket_invalid_file_type']);
                continue;
            }
            if ($file_size > $max_size) {
                $errors[] = str_replace('{filename}', $orig_name, $TEXT['ticket_file_too_large']);
                continue;
            }

            $upload_dir = __DIR__ . '/../uploads/tickets/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_name   = uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($orig_name));
            $target_path = $upload_dir . $file_name;

            if (move_uploaded_file($tmp_name, $target_path)) {
                $uploaded_files[]   = $target_path;
                $attachment_names[] = $file_name;
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo_auth->beginTransaction();

            $stmt = $pdo_auth->prepare(
                "INSERT INTO web_tickets (user_id, username, email, category, subject, message, attachments, created_at)
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
            $new_ticket_id = (int)$pdo_auth->lastInsertId();

            // Mirror the initial user message into web_ticket_messages so the thread
            // view sees it immediately (older code skipped this — bug fixed).
            $msg_stmt = $pdo_auth->prepare(
                "INSERT INTO web_ticket_messages (ticket_id, sender_type, sender_username, message, attachments, created_at)
                 VALUES (:tid, 'user', :uname, :msg, :attach, NOW())"
            );
            $msg_stmt->execute([
                'tid'    => $new_ticket_id,
                'uname'  => $user_username,
                'msg'    => $message,
                'attach' => !empty($attachment_names) ? json_encode($attachment_names) : null,
            ]);

            $pdo_auth->commit();

            // Email notification to admin (unchanged)
            $server_name = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');
            $cat_label   = $categories[$category]['label'] ?? $category;
            $inner = "
                <h2 style='color:var(--accent);margin-top:0'>&#127903; New Support Ticket</h2>
                <table style='width:100%;border-collapse:collapse;font-size:14px'>
                  <tr><td style='padding:6px 0;color:#8899aa;width:120px'>Server</td>
                      <td style='padding:6px 0;color:#e2e8f0;font-weight:600'>{$server_name}</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>From</td>
                      <td style='padding:6px 0;color:#e2e8f0'>" . htmlspecialchars($user_username) . " &lt;" . htmlspecialchars($user_email) . "&gt;</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>Category</td>
                      <td style='padding:6px 0;color:#e2e8f0'>{$cat_label}</td></tr>
                  <tr><td style='padding:6px 0;color:#8899aa'>Subject</td>
                      <td style='padding:6px 0;color:#e2e8f0;font-weight:600'>" . htmlspecialchars($subject) . "</td></tr>
                </table>
                <hr style='border:none;border-top:1px solid #2a2a3e;margin:20px 0'>
                <p style='color:#8899aa;margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:1px'>Message</p>
                <div style='background:#1a1a2e;border-left:3px solid var(--accent);padding:16px;border-radius:6px;white-space:pre-line'>" . htmlspecialchars($message) . "</div>
            ";
            if (!empty($uploaded_files)) {
                $inner .= "<p style='margin-top:16px;color:#8899aa;font-size:13px'>&#128206; " . count($uploaded_files) . " attachment(s) included.</p>";
            }
            $email_body = email_template($inner, "New ticket from {$user_username}: {$subject}");
            $ticket_to  = $config['smtp']['ticket_recipient'] ?? $config['smtp']['username'] ?? '';
            @send_ticket_email($ticket_to, "[{$server_name} Ticket] {$subject}", $email_body, $uploaded_files);

            $success = true;
        } catch (PDOException $e) {
            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
            error_log("Ticket DB Error: " . $e->getMessage());
            $errors[] = $TEXT['ticket_error'];
        }
    }
}

// ─── List of user's tickets (summary only — full thread is on /tickets/{id}) ─
$my_tickets = [];
try {
    $stmt = $pdo_auth->prepare(
        "SELECT t.id, t.subject, t.category, t.status, t.created_at, t.updated_at,
                (SELECT COUNT(*) FROM web_ticket_messages tm WHERE tm.ticket_id = t.id) AS msg_count,
                (SELECT MAX(created_at) FROM web_ticket_messages tm WHERE tm.ticket_id = t.id) AS last_message_at,
                (SELECT sender_type FROM web_ticket_messages tm WHERE tm.ticket_id = t.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_sender,
                (SELECT COUNT(*) FROM web_ticket_messages tm WHERE tm.ticket_id = t.id AND tm.attachments IS NOT NULL AND tm.attachments != '') AS attach_count
         FROM web_tickets t
         WHERE t.user_id = :uid
         ORDER BY COALESCE((SELECT MAX(created_at) FROM web_ticket_messages tm WHERE tm.ticket_id = t.id), t.created_at) DESC
         LIMIT 50"
    );
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $my_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Ticket list error: " . $e->getMessage());
}

$open_count     = count(array_filter($my_tickets, fn($t) => $t['status'] === 'open'));
$progress_count = count(array_filter($my_tickets, fn($t) => $t['status'] === 'in_progress'));

require_once __DIR__ . '/../templates/header.php';
?>

<style>
.ticket-wrap { padding-top: 90px; padding-bottom: 3rem; }

.ticket-header {
    background: linear-gradient(135deg, rgba(var(--btn-bg-rgb), 0.35) 0%, rgba(10,10,20,0.9) 70%),
                url('/assets/img/wow-bg/4-2.webp') center/cover no-repeat;
    border: 1px solid rgba(var(--btn-bg-rgb), 0.4);
    border-radius: 16px;
    padding: 2.2rem 2rem;
    margin-bottom: 2rem;
}
.ticket-header h1 {
    font-size: 1.9rem; font-weight: 700; letter-spacing: 1.5px;
    background: linear-gradient(90deg, var(--accent), #fff 60%, var(--accent));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    margin: 0;
}
.ticket-header p { color: rgba(var(--accent-rgb), .7); font-size: .9rem; margin: .4rem 0 0; }

.ticket-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
.ticket-tab-btn {
    padding: .7rem 1.5rem; border-radius: 10px;
    border: 1px solid rgba(var(--btn-bg-rgb), 0.3); background: rgba(255,255,255,0.03);
    color: #8899aa; font-weight: 600; font-size: .88rem; cursor: pointer;
    transition: all .2s ease; display: flex; align-items: center; gap: .5rem;
}
.ticket-tab-btn:hover { background: rgba(255,255,255,0.07); color: var(--accent); }
.ticket-tab-btn.active { background: linear-gradient(135deg, rgba(var(--btn-bg-rgb), 0.3), rgba(var(--btn-bg-rgb), 0.15)); border-color: rgba(var(--accent-rgb), 0.5); color: var(--accent); }
.tab-badge { background: rgba(var(--accent-rgb), 0.2); color: var(--accent); font-size: .72rem; padding: .1rem .45rem; border-radius: 6px; font-weight: 700; }

.ticket-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), 0.25);
    border-radius: 14px;
    padding: 2rem;
}
.panel-section-title {
    font-size: .72rem; color: var(--accent); text-transform: uppercase; letter-spacing: 1.5px;
    font-weight: 700; margin-bottom: 1rem; padding-bottom: .5rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), 0.25);
}

/* Category cards (unchanged) */
.category-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; margin-bottom: 1.8rem; }
@media(max-width:576px) { .category-grid { grid-template-columns: repeat(2, 1fr); } }
.cat-card { position: relative; padding: 1rem .8rem; border-radius: 10px; border: 2px solid rgba(255,255,255,0.07); background: rgba(255,255,255,0.03); cursor: pointer; transition: all .2s ease; text-align: center; user-select: none; }
.cat-card:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.2); transform: translateY(-2px); }
.cat-card.selected { background: rgba(var(--btn-bg-rgb), 0.2); border-color: var(--cat-color, var(--accent)); box-shadow: 0 0 16px rgba(var(--btn-bg-rgb), 0.25); }
.cat-card i { font-size: 1.5rem; display: block; margin-bottom: .4rem; }
.cat-card span { font-size: .78rem; font-weight: 600; color: #c0c8d8; }
.cat-card.selected span { color: #fff; }

.ticket-label { font-size: .78rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px; font-weight: 600; margin-bottom: .5rem; display: block; }
.ticket-input {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px; color: #e2e8f0; padding: .85rem 1rem; font-size: .95rem;
    transition: all .2s ease; outline: none; resize: vertical; box-sizing: border-box; font-family: inherit;
}
.ticket-input:focus { border-color: rgba(var(--accent-rgb), 0.5); box-shadow: 0 0 0 3px rgba(var(--btn-bg-rgb), 0.15); background: rgba(255,255,255,0.07); }
.ticket-input::placeholder { color: #4a5568; }
.char-count { font-size: .75rem; color: #4a5568; text-align: right; margin-top: .3rem; }
.char-count.warn { color: #f6ad55; }
.char-count.over { color: #f87171; }

.md-hint { display: flex; align-items: center; gap: .5rem; font-size: .75rem; color: #6c7a8c; margin-top: .4rem; flex-wrap: wrap; }
.md-hint code { background: rgba(0,0,0,.3); padding: .05rem .3rem; border-radius: 3px; color: var(--accent); font-size: .9em; }

/* File drop zone */
.drop-zone { border: 2px dashed rgba(255,255,255,0.12); border-radius: 10px; padding: 1.8rem 1rem; text-align: center; cursor: pointer; transition: all .2s ease; background: rgba(255,255,255,0.02); position: relative; }
.drop-zone:hover, .drop-zone.dragover { border-color: rgba(var(--accent-rgb), 0.4); background: rgba(var(--btn-bg-rgb), 0.08); }
.drop-zone i { font-size: 2rem; color: #4a5568; display: block; margin-bottom: .5rem; }
.drop-zone p  { color: #8899aa; font-size: .85rem; margin: 0; }
.drop-zone small { color: #4a5568; font-size: .75rem; }
.drop-zone input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.file-list { margin-top: .75rem; display: flex; flex-wrap: wrap; gap: .5rem; }
.file-pill { background: rgba(var(--accent-rgb), 0.12); border: 1px solid rgba(var(--accent-rgb), 0.25); color: var(--accent); font-size: .75rem; padding: .2rem .6rem; border-radius: 6px; display: flex; align-items: center; gap: .3rem; }

/* Submit */
.submit-btn { width: 100%; padding: 1rem; border: none; border-radius: 10px; background: linear-gradient(135deg, var(--btn-bg), var(--btn-bg-hover)); color: #fff; font-size: 1rem; font-weight: 700; letter-spacing: 1px; cursor: pointer; transition: all .25s ease; margin-top: 1.5rem; }
.submit-btn:hover { background: linear-gradient(135deg, var(--btn-bg-hover), var(--accent)); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(var(--btn-bg-rgb), 0.4); }

/* Success */
.success-card { text-align: center; padding: 3.5rem 2rem; }
.success-icon { width: 72px; height: 72px; border-radius: 50%; background: rgba(93,216,124,0.15); border: 2px solid rgba(93,216,124,0.3); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2rem; animation: pop .4s ease; }
@keyframes pop { 0% { transform: scale(0); opacity: 0; } 70% { transform: scale(1.15); } 100% { transform: scale(1); opacity: 1; } }
.success-card h2 { color: #5dd87c; font-weight: 700; margin-bottom: .5rem; }
.success-card p  { color: #8899aa; }

/* Compact list rows (NEW — replaces old expand-on-click cards) */
.ticket-row {
    display: flex; align-items: center; gap: 1rem; padding: 1rem 1.2rem;
    background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07);
    border-left: 3px solid;
    border-radius: 10px; margin-bottom: .55rem;
    text-decoration: none; transition: all .2s ease;
    color: inherit;
}
.ticket-row:hover {
    background: rgba(var(--accent-rgb), 0.06);
    border-color: rgba(var(--accent-rgb), 0.35);
    transform: translateX(3px);
    color: inherit;
}
.ticket-row.row-open        { border-left-color: #60a5fa; }
.ticket-row.row-in_progress { border-left-color: var(--accent); }
.ticket-row.row-closed      { border-left-color: #6b7280; }

.ticket-row .row-info { flex: 1; min-width: 0; }
.ticket-row .row-meta-line {
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
    font-size: .72rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px;
    margin-bottom: .2rem;
}
.ticket-row .row-id { color: #4a5568; font-family: monospace; }
.ticket-row .row-subject { font-weight: 600; color: #e2e8f0; font-size: .96rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ticket-row .row-side {
    text-align: right; font-size: .72rem; color: #6c7a8c;
    white-space: nowrap; flex-shrink: 0;
}
.ticket-row .row-side .last { display: block; }
.ticket-row .row-side .by-admin { color: #5dd87c; }
.ticket-row .row-side .by-user  { color: var(--accent); }

.ticket-status { display: inline-block; padding: .12rem .55rem; border-radius: 6px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.status-open        { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.status-in_progress { background: rgba(245,158,11,0.15); color: var(--accent); border: 1px solid rgba(245,158,11,0.3); }
.status-closed      { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

.ticket-pill { display: inline-flex; align-items: center; gap: .25rem; font-size: .68rem; }

.no-tickets { text-align: center; padding: 3rem 2rem; color: #8899aa; }
.no-tickets i { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: 1rem; }
</style>

<div class="container ticket-wrap px-3">

    <div class="ticket-header">
        <h1><i class="bi bi-ticket-perforated me-2"></i><?= $TEXT['submit_ticket'] ?></h1>
        <p><?= sprintf($TEXT['tickets_intro_html'] ?? 'Describe your issue — our team will respond to <strong style="color:var(--accent)">%s</strong>', htmlspecialchars($user_email)) ?></p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3" style="border-radius:10px;border:1px solid rgba(220,53,69,.4);background:rgba(220,53,69,.12)">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
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
        <div class="success-card">
            <div class="success-icon">✓</div>
            <h2><?= htmlspecialchars($TEXT['tickets_submitted_title'] ?? 'Ticket Submitted!') ?></h2>
            <p><?= $TEXT['ticket_success'] ?></p>
            <div class="d-flex gap-2 justify-content-center mt-3">
                <a href="/tickets?tab=history" class="action-btn action-btn-secondary" style="display:inline-flex;align-items:center;gap:.5rem;padding:.8rem 1.5rem;border-radius:10px;font-weight:700;text-decoration:none;background:rgba(255,255,255,0.05);color:var(--accent);border:1px solid rgba(var(--accent-rgb), 0.3);transition:all .2s ease;">
                    <i class="bi bi-clock-history"></i> <?= htmlspecialchars($TEXT['tickets_view_my_btn'] ?? 'View My Tickets') ?>
                </a>
                <a href="/dashboard" class="action-btn action-btn-primary" style="display:inline-flex;align-items:center;gap:.5rem;padding:.8rem 1.5rem;border-radius:10px;font-weight:700;text-decoration:none;background:linear-gradient(135deg,var(--btn-bg),var(--btn-bg-hover));color:#fff;transition:all .2s ease;">
                    <i class="bi bi-speedometer2"></i> <?= htmlspecialchars($TEXT['dashboard'] ?? 'Dashboard') ?>
                </a>
            </div>
        </div>
    <?php else: ?>
        <form action="/tickets" method="POST" enctype="multipart/form-data" id="ticketForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="category" id="categoryInput" value="<?= htmlspecialchars($selected_category) ?>">

            <div class="panel-section-title"><i class="bi bi-tag me-2"></i><?= $TEXT['ticket_category'] ?></div>
            <div class="category-grid mb-4">
            <?php foreach ($categories as $key => $cat): ?>
                <div class="cat-card <?= $selected_category === $key ? 'selected' : '' ?>"
                     style="--cat-color:<?= $cat['color'] ?>" data-key="<?= $key ?>" data-color="<?= $cat['color'] ?>"
                     onclick="selectCategory(this)">
                    <i class="bi <?= $cat['icon'] ?>" style="color:<?= $cat['color'] ?>"></i>
                    <span><?= htmlspecialchars($cat['label']) ?></span>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="mb-4">
                <label class="ticket-label"><i class="bi bi-pencil me-1"></i><?= $TEXT['ticket_subject'] ?></label>
                <input type="text" class="ticket-input" name="subject" id="subject"
                       maxlength="120" placeholder="<?= htmlspecialchars($TEXT['tickets_subject_placeholder'] ?? 'Brief summary of your issue…') ?>"
                       value="<?= $subject_val ?>" oninput="updateCount(this,'subjectCount',120)" required>
                <div class="char-count" id="subjectCount"><?= strlen($_POST['subject'] ?? '') ?>/120</div>
            </div>

            <div class="mb-2">
                <label class="ticket-label"><i class="bi bi-chat-text me-1"></i><?= $TEXT['ticket_message'] ?></label>
                <textarea class="ticket-input" name="message" id="message" rows="6"
                          maxlength="2000" placeholder="<?= htmlspecialchars($TEXT['tickets_message_placeholder'] ?? 'Describe your issue in detail…') ?>"
                          oninput="updateCount(this,'msgCount',2000)" required><?= $message_val ?></textarea>
                <div class="char-count" id="msgCount"><?= strlen($_POST['message'] ?? '') ?>/2000</div>
                <div class="md-hint">
                    <i class="bi bi-markdown"></i>
                    <?= htmlspecialchars($TEXT['tickets_md_hint'] ?? 'Markdown supported') ?>
                    — <code>**<?= htmlspecialchars($TEXT['tickets_md_bold'] ?? 'bold') ?>**</code>
                    <code>*<?= htmlspecialchars($TEXT['tickets_md_italic'] ?? 'italic') ?>*</code>
                    <code>`<?= htmlspecialchars($TEXT['tickets_md_code'] ?? 'code') ?>`</code>
                    <code>[<?= htmlspecialchars($TEXT['tickets_md_link'] ?? 'link') ?>](url)</code>
                </div>
            </div>

            <div class="mb-2">
                <label class="ticket-label"><i class="bi bi-paperclip me-1"></i><?= $TEXT['ticket_attachments'] ?> <span style="color:#4a5568;font-weight:400;text-transform:none">(<?= htmlspecialchars($TEXT['common_optional'] ?? 'Optional') ?>)</span></label>
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="attachments[]" id="attachments" multiple accept=".jpg,.jpeg,.png,.webp" onchange="showFiles(this)">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <p><?= $TEXT['tickets_drop_zone_text'] ?? 'Drag &amp; drop files here, or <strong style="color:var(--accent)">browse</strong>' ?></p>
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

    <!-- MY TICKETS — compact list, click into /tickets/{id} for the thread -->
    <div id="tab-history" class="ticket-panel" style="<?= $active_tab !== 'history' ? 'display:none' : '' ?>">
        <div class="panel-section-title"><i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($TEXT['tickets_tab_my'] ?? 'My Tickets') ?></div>

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
                $status_label = $t['status'] === 'open'
                    ? '● ' . ($TEXT['tickets_status_open'] ?? 'Open')
                    : ($t['status'] === 'in_progress'
                        ? '◐ ' . ($TEXT['tickets_status_in_progress'] ?? 'In Progress')
                        : '○ ' . ($TEXT['tickets_status_closed'] ?? 'Closed'));
                $cat_label = $categories[$t['category']]['label'] ?? $t['category'];
                $cat_color = $categories[$t['category']]['color'] ?? '#888';
                $last = $t['last_message_at'] ?: $t['created_at'];
                $msg_count = (int)$t['msg_count'];
                $attach_count = (int)$t['attach_count'];
                $last_sender = $t['last_sender'] ?: 'user';
            ?>
                <a href="/tickets/<?= (int)$t['id'] ?>" class="ticket-row row-<?= htmlspecialchars($t['status']) ?>">
                    <div class="row-info">
                        <div class="row-meta-line">
                            <span class="ticket-status status-<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($status_label) ?></span>
                            <span class="row-id">#<?= (int)$t['id'] ?></span>
                            <span style="color:<?= $cat_color ?>"><i class="bi <?= $categories[$t['category']]['icon'] ?? 'bi-tag' ?>"></i> <?= htmlspecialchars($cat_label) ?></span>
                            <?php if ($msg_count > 0): ?>
                                <span class="ticket-pill" style="color:#8899aa"><i class="bi bi-chat-left-text"></i> <?= $msg_count ?></span>
                            <?php endif; ?>
                            <?php if ($attach_count > 0): ?>
                                <span class="ticket-pill" style="color:#8899aa"><i class="bi bi-paperclip"></i> <?= $attach_count ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="row-subject"><?= htmlspecialchars($t['subject']) ?></div>
                    </div>
                    <div class="row-side">
                        <span class="last">
                            <?php if ($last_sender === 'admin'): ?>
                                <span class="by-admin"><i class="bi bi-reply"></i> <?= htmlspecialchars($TEXT['tickets_replied'] ?? 'Replied') ?></span>
                            <?php else: ?>
                                <span class="by-user"><i class="bi bi-person"></i> <?= htmlspecialchars($TEXT['tickets_awaiting_reply'] ?? 'Awaiting reply') ?></span>
                            <?php endif; ?>
                        </span>
                        <span><?= date('M d, Y H:i', strtotime($last)) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<script>
function switchTicketTab(tab) {
    if (tab === 'new' && document.querySelector('.success-card')) {
        window.location.href = '/tickets';
        return;
    }
    document.querySelectorAll('.ticket-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-new').style.display = tab === 'new' ? '' : 'none';
    document.getElementById('tab-history').style.display = tab === 'history' ? '' : 'none';
    event.target.closest('.ticket-tab-btn').classList.add('active');
}
function selectCategory(card) {
    document.querySelectorAll('.cat-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    document.getElementById('categoryInput').value = card.dataset.key;
}
function updateCount(el, counterId, max) {
    const len = el.value.length;
    const ctr = document.getElementById(counterId);
    ctr.textContent = len + '/' + max;
    ctr.className = 'char-count' + (len > max * 0.9 ? (len >= max ? ' over' : ' warn') : '');
}
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
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop',      e => { e.preventDefault(); dz.classList.remove('dragover');
        const fi = document.getElementById('attachments'); fi.files = e.dataTransfer.files; showFiles(fi); });
}
document.getElementById('ticketForm')?.addEventListener('submit', function(e) {
    if (!document.getElementById('categoryInput').value) {
        e.preventDefault();
        document.querySelectorAll('.cat-card').forEach(c => { c.style.borderColor = 'rgba(248,113,113,0.5)'; setTimeout(() => c.style.borderColor = '', 1800); });
    }
});
const subj = document.getElementById('subject');
const msg  = document.getElementById('message');
if (subj) updateCount(subj, 'subjectCount', 120);
if (msg)  updateCount(msg,  'msgCount',     2000);
</script>
