<?php
/**
 * Change account email — step 1: request.
 *
 *   /change_email   (logged-in only)
 *
 * Flow: user submits new email + current password (+ current 2FA code if
 * 2FA on). We hash a fresh token, persist it with the *old* + *new*
 * emails on web_email_changes, and email the confirmation link to the
 * OLD address. Clicking that link lands on /confirm_email_change which
 * commits the change.
 *
 * This means even a hijacked session can't silently rotate the email —
 * the legitimate owner of the old address still gets a chance to deny.
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wl_2fa.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../templates/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}
$user_id  = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

$errors  = [];
$flash   = '';

// Pull current account info to render alongside the form.
$current_email = '';
try {
    $stmt = $pdo_auth->prepare("SELECT email FROM account WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $current_email = (string)($stmt->fetchColumn() ?: '');
} catch (PDOException $e) {
    error_log('change_email lookup: ' . $e->getMessage());
}

$twofa_required = wl_2fa_is_enabled($pdo_auth, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_email        = trim((string)($_POST['new_email'] ?? ''));
    $current_password = (string)($_POST['current_password'] ?? '');
    $twofa_code       = (string)($_POST['twofa_code'] ?? '');

    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token. Refresh and try again.';
    }
    if (empty($errors) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $TEXT['invalid_email'] ?? 'Please enter a valid email address.';
    }
    if (empty($errors) && strcasecmp($new_email, $current_email) === 0) {
        $errors[] = $TEXT['email_change_same'] ?? 'The new email is the same as the current one.';
    }

    if (empty($errors)) {
        // Password check.
        try {
            $stmt = $pdo_auth->prepare("SELECT username, sha_pass_hash FROM account WHERE id = :id");
            $stmt->execute(['id' => $user_id]);
            $u = $stmt->fetch();
            if (!$u || sha_password($u['username'], $current_password) !== $u['sha_pass_hash']) {
                $errors[] = $TEXT['twofa_err_bad_password'] ?? 'Current password is wrong.';
            }
        } catch (PDOException $e) {
            error_log('change_email password check: ' . $e->getMessage());
            $errors[] = $TEXT['error_db'] ?? 'Database error.';
        }
    }
    if (empty($errors) && $twofa_required && !wl_2fa_verify($pdo_auth, $user_id, $twofa_code)) {
        $errors[] = $TEXT['twofa_err_code_bad'] ?? 'That 2FA code is wrong. Try again.';
    }

    // Reject if the new address is already in use by another account.
    if (empty($errors)) {
        try {
            $stmt = $pdo_auth->prepare("SELECT id FROM account WHERE email = :e AND id != :id LIMIT 1");
            $stmt->execute(['e' => $new_email, 'id' => $user_id]);
            if ($stmt->fetchColumn()) {
                $errors[] = $TEXT['email_change_taken'] ?? 'That email is already in use by another account.';
            }
        } catch (PDOException $e) {
            error_log('change_email uniqueness check: ' . $e->getMessage());
        }
    }

    if (empty($errors)) {
        // Build & email the token.
        $token = bin2hex(random_bytes(32));
        $hash  = password_hash($token, PASSWORD_DEFAULT);
        try {
            $pdo_auth->prepare(
                "REPLACE INTO web_email_changes (account_id, current_email, new_email, token_key, created_at)
                 VALUES (:id, :cur, :new, :tok, NOW())"
            )->execute(['id' => $user_id, 'cur' => $current_email, 'new' => $new_email, 'tok' => $hash]);
        } catch (PDOException $e) {
            error_log('change_email INSERT: ' . $e->getMessage());
            $errors[] = $TEXT['error_db'] ?? 'Database error.';
        }

        if (empty($errors)) {
            $base_url   = rtrim($config['site']['base_url'] ?? '', '/');
            $link       = $base_url . '/confirm_email_change?id=' . $user_id . '&token=' . urlencode($token);
            $server     = htmlspecialchars($config['realm']['name'] ?? ($config['site']['title'] ?? 'WoW Server'));

            $inner = str_replace(
                ['{username}', '{new_email}', '{confirm_link}', '{server}'],
                [htmlspecialchars($username), htmlspecialchars($new_email), $link, $server],
                $TEXT['email_body_email_change']
                    ?? "Hi {username},\n\nYou requested to change the email on your {server} account to {new_email}.\n\nClick this link within 1 hour to confirm — it was sent to your CURRENT address on purpose, so even if someone has your password they can't move the email without you knowing:\n\n{confirm_link}\n\nIf you didn't request this, ignore this email and your address stays unchanged."
            );
            $body    = email_template($inner, "Confirm email change · {$server}");
            $subject = "[{$server}] " . ($TEXT['email_subject_email_change'] ?? 'Confirm your email change');

            if (send_email($current_email, $subject, $body)) {
                audit_log($pdo_auth, $user_id, $username, 'email_change_request', $new_email, null);
                $flash = sprintf(
                    $TEXT['email_change_sent']
                        ?? 'Check %s — we sent a confirmation link there. Click it within 1 hour to finish the change.',
                    $current_email
                );
            } else {
                $errors[] = $TEXT['recovery_email_failed'] ?? 'Could not send the confirmation email. Try again later.';
            }
        }
    }
}
?>

<style>
.cm-wrap { max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
.cm-card { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:12px; padding:1.5rem 1.6rem; }
.cm-title { color:var(--accent); font-weight:800; font-size:1.5rem; margin:0 0 .4rem; }
.cm-sub   { color:#8899aa; margin:0 0 1.3rem; font-size:.95rem; line-height:1.5; }
.cm-current { background: rgba(0,0,0,.25); border:1px solid rgba(var(--btn-bg-rgb),.25); border-radius:8px; padding:.65rem .85rem; margin-bottom:1rem; font-size:.9rem; color:#cfd5dc; }
.cm-current b { color:#dee2e6; }
</style>

<div class="cm-wrap">
    <div class="cm-card">
        <h1 class="cm-title"><i class="bi bi-envelope-at-fill me-2"></i><?= htmlspecialchars($TEXT['change_email_title'] ?? 'Change email address') ?></h1>
        <p class="cm-sub"><?= htmlspecialchars($TEXT['change_email_intro'] ?? "We'll send a confirmation link to your current email — clicking it commits the change. Your address stays the same until you click.") ?></p>

        <?php if ($flash): ?>
            <div class="alert alert-success" role="alert"><i class="bi bi-envelope-check me-2"></i><?= htmlspecialchars($flash) ?></div>
            <a class="btn btn-link" href="/dashboard" style="padding-left:0"><?= htmlspecialchars($TEXT['twofa_back_to_dashboard'] ?? 'Back to dashboard') ?></a>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <div class="cm-current">
                <?= htmlspecialchars($TEXT['change_email_current'] ?? 'Current email') ?>: <b><?= htmlspecialchars($current_email ?: '—') ?></b>
            </div>

            <form action="/change_email" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($TEXT['change_email_new'] ?? 'New email') ?></label>
                    <input type="email" name="new_email" required class="form-control"
                           value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= htmlspecialchars($TEXT['current_password'] ?? 'Current password') ?></label>
                    <input type="password" name="current_password" required class="form-control">
                </div>
                <?php if ($twofa_required): ?>
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-shield-lock-fill me-1" style="color:var(--accent)"></i>
                        <?= htmlspecialchars($TEXT['twofa_code_or_backup'] ?? '2FA code (or backup code)') ?>
                    </label>
                    <input type="text" name="twofa_code" inputmode="numeric" autocomplete="one-time-code" required
                           class="form-control"
                           style="font-family:ui-monospace,Menlo,monospace;letter-spacing:.25em;text-align:center">
                </div>
                <?php endif; ?>
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-send me-1"></i><?= htmlspecialchars($TEXT['change_email_send_btn'] ?? 'Send confirmation link') ?></button>
                <a class="btn btn-link" href="/dashboard"><?= htmlspecialchars($TEXT['cancel'] ?? 'Cancel') ?></a>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
