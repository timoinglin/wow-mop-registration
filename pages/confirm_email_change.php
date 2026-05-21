<?php
/**
 * Change account email — step 2: confirm.
 *
 *   /confirm_email_change?id=<account_id>&token=<plain_token>
 *
 * Landing target for the link mailed to the user's CURRENT address.
 * Validates the token (bcrypt-verified, 1-hour TTL), shows a clear
 * "from X → to Y" summary, requires a button click to commit (so a
 * link-preview/anti-malware crawler can't trigger the change), then
 * UPDATEs account.email and DELETEs the token row.
 *
 * Open to logged-out users on purpose — the user might be confirming
 * from a different device than the one logged in.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

$errors  = [];
$flash   = '';
$pending = null;

$account_id = (int)($_GET['id']    ?? 0);
$token      = trim((string)($_GET['token'] ?? ''));

if ($account_id <= 0 || $token === '') {
    $errors[] = $TEXT['invalid_token'] ?? 'Invalid or expired link.';
} else {
    try {
        $stmt = $pdo_auth->prepare(
            "SELECT current_email, new_email, token_key, created_at
             FROM web_email_changes WHERE account_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $account_id]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($token, $row['token_key'])) {
            $errors[] = $TEXT['invalid_token'] ?? 'Invalid or expired link.';
        } elseif (time() - strtotime($row['created_at']) > 3600) {
            $pdo_auth->prepare("DELETE FROM web_email_changes WHERE account_id = :id")
                     ->execute(['id' => $account_id]);
            $errors[] = $TEXT['invalid_token'] ?? 'Invalid or expired link.';
        } else {
            $pending = $row;
        }
    } catch (PDOException $e) {
        error_log('confirm_email_change lookup: ' . $e->getMessage());
        $errors[] = $TEXT['error_db'] ?? 'Database error.';
    }
}

if ($pending && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token. Refresh and try again.';
    }
    if (empty($errors)) {
        try {
            $pdo_auth->beginTransaction();
            // Race-check: don't clobber if some other account grabbed the address since the request was sent.
            $stmt = $pdo_auth->prepare("SELECT id FROM account WHERE email = :e AND id != :id LIMIT 1");
            $stmt->execute(['e' => $pending['new_email'], 'id' => $account_id]);
            if ($stmt->fetchColumn()) {
                $pdo_auth->rollBack();
                $errors[] = $TEXT['email_change_taken'] ?? 'That email is already in use by another account.';
            } else {
                $pdo_auth->prepare("UPDATE account SET email = :e WHERE id = :id")
                         ->execute(['e' => $pending['new_email'], 'id' => $account_id]);
                $pdo_auth->prepare("DELETE FROM web_email_changes WHERE account_id = :id")
                         ->execute(['id' => $account_id]);
                $pdo_auth->commit();

                // Username for audit — best-effort.
                $stmt = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
                $stmt->execute(['id' => $account_id]);
                $u_name = (string)($stmt->fetchColumn() ?: '');
                log_admin_action($pdo_auth, $account_id, $u_name, 'email_change_confirm', $pending['new_email'], null);

                $flash   = $TEXT['email_change_confirmed'] ?? 'Your email has been changed.';
                $pending = null;
            }
        } catch (PDOException $e) {
            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
            error_log('confirm_email_change commit: ' . $e->getMessage());
            $errors[] = $TEXT['error_db'] ?? 'Database error.';
        }
    }
}

require_once __DIR__ . '/../templates/header.php';
?>

<style>
.cm-wrap { max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
.cm-card { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:12px; padding:1.5rem 1.6rem; }
.cm-title { color:var(--accent); font-weight:800; font-size:1.5rem; margin:0 0 .8rem; }
.cm-pair  { display:grid; grid-template-columns: 1fr auto 1fr; gap:.7rem; align-items:center; margin:1.1rem 0; }
.cm-cell  { background:#0d1116; border:1px solid rgba(var(--accent-rgb),.35); border-radius:8px; padding:.55rem .8rem; font-size:.95rem; color:#dee2e6; text-align:center; word-break: break-all; }
.cm-cell .lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:.2rem; }
.cm-arrow { color:var(--accent); font-size:1.5rem; }
@media (max-width: 480px) { .cm-pair { grid-template-columns: 1fr; } .cm-arrow { transform:rotate(90deg); justify-self:center } }
</style>

<div class="cm-wrap">
    <div class="cm-card">
        <h1 class="cm-title"><i class="bi bi-envelope-check-fill me-2"></i><?= htmlspecialchars($TEXT['confirm_email_change_title'] ?? 'Confirm email change') ?></h1>

        <?php if ($flash): ?>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($flash) ?></div>
            <a class="btn btn-primary" href="/dashboard"><?= htmlspecialchars($TEXT['twofa_back_to_dashboard'] ?? 'Back to dashboard') ?></a>
        <?php elseif (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <a class="btn btn-link" href="/dashboard" style="padding-left:0"><?= htmlspecialchars($TEXT['twofa_back_to_dashboard'] ?? 'Back to dashboard') ?></a>
        <?php elseif ($pending): ?>
            <p style="color:#8899aa;margin:0 0 .8rem"><?= htmlspecialchars($TEXT['confirm_email_change_intro'] ?? 'Click the button below to commit this email change.') ?></p>
            <div class="cm-pair">
                <div class="cm-cell"><div class="lbl"><?= htmlspecialchars($TEXT['change_email_current'] ?? 'Current email') ?></div><?= htmlspecialchars($pending['current_email']) ?></div>
                <div class="cm-arrow"><i class="bi bi-arrow-right"></i></div>
                <div class="cm-cell"><div class="lbl"><?= htmlspecialchars($TEXT['change_email_new'] ?? 'New email') ?></div><?= htmlspecialchars($pending['new_email']) ?></div>
            </div>
            <form action="/confirm_email_change?id=<?= (int)$account_id ?>&token=<?= urlencode($token) ?>" method="POST" style="display:flex;gap:.6rem;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['confirm_email_change_btn'] ?? 'Yes, change my email') ?></button>
                <a class="btn btn-link" href="/dashboard" style="color:#8899aa"><?= htmlspecialchars($TEXT['cancel'] ?? 'Cancel') ?></a>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
