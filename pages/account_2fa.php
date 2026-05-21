<?php
/**
 * Two-Factor Authentication — user-facing setup & management.
 *
 *   /account_2fa
 *
 * States:
 *   - 2FA not set up        → show "Enable 2FA" button
 *   - Setup in progress     → show QR + secret + verify-code form (and a Cancel button)
 *   - Just enabled / recodes → show backup codes once (only visible right after enable/regen)
 *   - 2FA enabled           → show "Enabled since <date>" + Disable / Regenerate buttons
 *
 * All POST handlers are CSRF-protected. Disable + Regenerate require the
 * current account password (defense against session-hijack → silent 2FA-off).
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wl_2fa.php';
require_once __DIR__ . '/../includes/audit.php';

// Start session BEFORE the template, so the auth check + every POST
// branch that calls header('Location: ...') can redirect cleanly
// (templates/header.php emits HTML — any header() call after it warns
// "headers already sent" and silently drops the redirect).
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}
$user_id   = (int)$_SESSION['user_id'];
$username  = $_SESSION['username'] ?? '';

$errors  = [];
$flash   = '';
$show_codes_once = null;        // populated only right after enable or regenerate
$site_title = $config['site']['title'] ?? 'WoW Legends';

// ─── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token. Refresh and try again.';
    }

    if (empty($errors) && $action === 'begin') {
        // User clicked "Enable 2FA" — generate a fresh secret and stash a
        // pending row. The user is redirected (PRG) back here, which will
        // pick up the pending row and show the QR/verify form.
        $secret = wl_totp_generate_secret();
        if (wl_2fa_setup_begin($pdo_auth, $user_id, $secret)) {
            log_admin_action($pdo_auth, $user_id, $username, '2fa_setup_begin', null, null);
            header('Location: /account_2fa');
            exit;
        }
        $errors[] = $TEXT['twofa_err_setup'] ?? 'Could not start 2FA setup. Please try again.';
    }

    if (empty($errors) && $action === 'cancel_setup') {
        // Abandon a pending setup — wipes the row outright.
        wl_2fa_disable($pdo_auth, $user_id);
        log_admin_action($pdo_auth, $user_id, $username, '2fa_setup_cancel', null, null);
        header('Location: /account_2fa');
        exit;
    }

    if (empty($errors) && $action === 'confirm') {
        // Verify the user's first code against the pending secret. On success,
        // flip enabled=1, mint backup codes, and show them once.
        $code = trim($_POST['code'] ?? '');
        $row  = wl_2fa_get($pdo_auth, $user_id);
        if ($row === null) {
            $errors[] = $TEXT['twofa_err_no_pending'] ?? 'No 2FA setup in progress.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $errors[] = $TEXT['twofa_err_code_format'] ?? 'Enter the 6-digit code from your authenticator app.';
        } elseif (!wl_totp_verify($row['secret'], $code)) {
            $errors[] = $TEXT['twofa_err_code_bad'] ?? 'That code did not match. Check your authenticator and try again.';
        } else {
            $plain = wl_2fa_generate_backup_codes(8);
            if (wl_2fa_setup_finalize($pdo_auth, $user_id, $plain)) {
                log_admin_action($pdo_auth, $user_id, $username, '2fa_enable', null, null);
                $show_codes_once = $plain;
                $flash = $TEXT['twofa_flash_enabled'] ?? '2FA is now enabled on your account.';
            } else {
                $errors[] = $TEXT['twofa_err_setup'] ?? 'Could not enable 2FA. Please try again.';
            }
        }
    }

    if (empty($errors) && $action === 'disable') {
        // Require the account password to disable 2FA — closes the session-
        // hijack hole where an attacker with a stolen cookie could silently
        // turn the second factor off.
        $current_password = (string)($_POST['current_password'] ?? '');
        $code             = (string)($_POST['code'] ?? '');
        $stmt = $pdo_auth->prepare("SELECT username, sha_pass_hash FROM account WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $u = $stmt->fetch();
        if (!$u || sha_password($u['username'], $current_password) !== $u['sha_pass_hash']) {
            $errors[] = $TEXT['twofa_err_bad_password'] ?? 'Current password is wrong.';
        } elseif (!wl_2fa_verify($pdo_auth, $user_id, $code)) {
            $errors[] = $TEXT['twofa_err_code_bad'] ?? 'That 2FA code is wrong. Try again.';
        } elseif (wl_2fa_disable($pdo_auth, $user_id)) {
            log_admin_action($pdo_auth, $user_id, $username, '2fa_disable', null, null);
            $flash = $TEXT['twofa_flash_disabled'] ?? '2FA has been turned off for your account.';
        } else {
            $errors[] = $TEXT['twofa_err_setup'] ?? 'Could not disable 2FA. Please try again.';
        }
    }

    if (empty($errors) && $action === 'regen') {
        // Same defense — regenerating invalidates all existing backup codes,
        // so it's a security-sensitive change.
        $current_password = (string)($_POST['current_password'] ?? '');
        $code             = (string)($_POST['code'] ?? '');
        $stmt = $pdo_auth->prepare("SELECT username, sha_pass_hash FROM account WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $u = $stmt->fetch();
        if (!$u || sha_password($u['username'], $current_password) !== $u['sha_pass_hash']) {
            $errors[] = $TEXT['twofa_err_bad_password'] ?? 'Current password is wrong.';
        } elseif (!wl_2fa_verify($pdo_auth, $user_id, $code)) {
            $errors[] = $TEXT['twofa_err_code_bad'] ?? 'That 2FA code is wrong. Try again.';
        } else {
            $show_codes_once = wl_2fa_regenerate_backup_codes($pdo_auth, $user_id, 8);
            if (!empty($show_codes_once)) {
                log_admin_action($pdo_auth, $user_id, $username, '2fa_regen_codes', null, null);
                $flash = $TEXT['twofa_flash_regen'] ?? 'New backup codes generated. The previous codes no longer work.';
            } else {
                $errors[] = $TEXT['twofa_err_setup'] ?? 'Could not generate new backup codes. Please try again.';
            }
        }
    }
}

// ─── State for render ────────────────────────────────────────────────────────
$row     = wl_2fa_get($pdo_auth, $user_id);
$has_row = $row !== null;
$enabled = $has_row && (int)$row['enabled'] === 1;
$pending = $has_row && (int)$row['enabled'] === 0;
$backup_left = $has_row && $enabled
    ? count(json_decode($row['backup_codes'] ?? '[]', true) ?: [])
    : 0;

// Build the QR provisioning URI only when we're showing the setup form.
$totp_uri = $totp_secret_pretty = '';
if ($pending) {
    $totp_uri = wl_totp_uri($row['secret'], $username . '@' . preg_replace('~^https?://~', '', $config['site']['base_url'] ?? 'wow-legends'), $site_title);
    $totp_secret_pretty = trim(chunk_split($row['secret'], 4, ' '));
}

// All POST handlers / redirects done — safe to emit HTML.
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.tfa-page { max-width: 760px; margin: 2rem auto; padding: 0 1rem; }
.tfa-card { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:12px; padding:1.5rem 1.6rem; margin-bottom:1.1rem; }
.tfa-h1 { color:var(--accent); font-weight:800; font-size:1.65rem; margin:0 0 .35rem; }
.tfa-sub { color:#8899aa; margin:0 0 1.3rem; font-size:.95rem; }
.tfa-status { display:flex; align-items:center; gap:.7rem; font-weight:700; }
.tfa-status .badge-on  { background: rgba(93,216,124,.18); color:#5dd87c; padding:.3rem .75rem; border-radius:999px; font-size:.8rem; letter-spacing:.5px; }
.tfa-status .badge-off { background: rgba(255,255,255,.06); color:#aab; padding:.3rem .75rem; border-radius:999px; font-size:.8rem; letter-spacing:.5px; }
.tfa-meta { color:#8899aa; font-size:.85rem; margin-top:.6rem; }
.tfa-stepnote { background: rgba(255,255,255,.025); border-left: 3px solid var(--accent); padding:.65rem .9rem; border-radius:6px; color:#cfd5dc; font-size:.9rem; line-height:1.5; margin-bottom:1rem; }
.tfa-qr-row { display:grid; grid-template-columns: 200px 1fr; gap:1.2rem; align-items:center; margin:1rem 0 1.3rem; }
@media (max-width: 640px) { .tfa-qr-row { grid-template-columns: 1fr; } }
.tfa-qr { width:200px; height:200px; background:#fff; padding:8px; border-radius:8px; }
.tfa-secret-block { background: rgba(0,0,0,.35); border:1px solid rgba(var(--accent-rgb),.35); border-radius:8px; padding:.65rem .85rem; }
.tfa-secret-lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.4px; margin-bottom:.3rem; }
.tfa-secret-val { color:var(--accent); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size:1rem; letter-spacing:.04em; word-break: break-all; user-select: all; }

.tfa-codes-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:.5rem; margin:.8rem 0 1.1rem; }
@media (max-width: 480px) { .tfa-codes-grid { grid-template-columns: 1fr; } }
.tfa-code { background:#0d1116; border:1px solid rgba(var(--accent-rgb),.4); border-radius:6px; padding:.55rem .8rem; color:var(--accent); font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size:1.05rem; text-align:center; letter-spacing:.06em; user-select: all; }
.tfa-warning { background: rgba(248,126,138,.1); border:1px solid rgba(248,126,138,.4); color:#f4c0c6; padding:.65rem .9rem; border-radius:8px; font-size:.88rem; margin: .9rem 0; }
.tfa-actions { display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1rem; }
.tfa-actions form { margin:0; }
.btn-tfa-danger { background:#7e2530; color:#fff; border:1px solid #a13845; }
.btn-tfa-danger:hover { background:#9c2e3c; color:#fff; }
</style>

<div class="tfa-page">
    <div class="tfa-card">
        <h1 class="tfa-h1"><i class="bi bi-shield-lock-fill me-2"></i><?= htmlspecialchars($TEXT['twofa_title'] ?? 'Two-Factor Authentication') ?></h1>
        <p class="tfa-sub"><?= htmlspecialchars($TEXT['twofa_subtitle'] ?? 'Protect your account with a second code from an authenticator app on your phone.') ?></p>

        <?php if ($flash): ?>
            <div class="alert alert-success" role="alert"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Status badge -->
        <div class="tfa-status">
            <?php if ($enabled): ?>
                <span class="badge-on"><i class="bi bi-check2-circle me-1"></i><?= htmlspecialchars($TEXT['twofa_on'] ?? 'ENABLED') ?></span>
                <?php if (!empty($row['enabled_at'])): ?>
                <span class="tfa-meta" style="margin:0"><?= sprintf(htmlspecialchars($TEXT['twofa_on_since'] ?? 'On since %s'), htmlspecialchars(date('Y-m-d', strtotime($row['enabled_at'])))) ?></span>
                <?php endif; ?>
            <?php elseif ($pending): ?>
                <span class="badge-off"><i class="bi bi-hourglass-split me-1"></i><?= htmlspecialchars($TEXT['twofa_pending'] ?? 'SETUP IN PROGRESS') ?></span>
            <?php else: ?>
                <span class="badge-off"><?= htmlspecialchars($TEXT['twofa_off'] ?? 'NOT ENABLED') ?></span>
            <?php endif; ?>
        </div>

        <?php if ($enabled && !$show_codes_once): ?>
            <p class="tfa-meta">
                <?= sprintf(htmlspecialchars($TEXT['twofa_codes_left'] ?? '%d backup codes remaining'), $backup_left) ?>
                <?php if ($backup_left < 3): ?>
                    · <strong style="color:#f87e8a"><?= htmlspecialchars($TEXT['twofa_codes_low'] ?? 'Running low — regenerate them below.') ?></strong>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($show_codes_once)): ?>
    <!-- One-shot backup codes display (only this render). -->
    <div class="tfa-card">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.7rem"><i class="bi bi-key-fill me-2"></i><?= htmlspecialchars($TEXT['twofa_backup_codes'] ?? 'Backup codes') ?></h2>
        <p class="tfa-sub"><?= htmlspecialchars($TEXT['twofa_backup_intro'] ?? 'Print these or save them somewhere safe. Each code logs in once if you lose access to your authenticator app.') ?></p>
        <div class="tfa-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($TEXT['twofa_backup_warning'] ?? "We won't show these codes again. Save them now.") ?></div>
        <div class="tfa-codes-grid">
            <?php foreach ($show_codes_once as $c): ?>
                <div class="tfa-code"><?= htmlspecialchars($c) ?></div>
            <?php endforeach; ?>
        </div>
        <a class="btn btn-primary" href="/dashboard"><?= htmlspecialchars($TEXT['twofa_back_to_dashboard'] ?? 'Back to dashboard') ?></a>
    </div>
    <?php endif; ?>

    <?php if ($pending && empty($show_codes_once)): ?>
    <!-- Setup form: QR + secret + verify-code field. -->
    <div class="tfa-card">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.7rem"><i class="bi bi-qr-code me-2"></i><?= htmlspecialchars($TEXT['twofa_setup_title'] ?? 'Pair your authenticator app') ?></h2>
        <div class="tfa-stepnote">
            <strong>1.</strong> <?= htmlspecialchars($TEXT['twofa_setup_step1'] ?? 'Install an authenticator app (Google Authenticator, Authy, 1Password, Bitwarden, Aegis…).') ?><br>
            <strong>2.</strong> <?= htmlspecialchars($TEXT['twofa_setup_step2'] ?? 'Scan the QR code below — or type the secret key into the app manually.') ?><br>
            <strong>3.</strong> <?= htmlspecialchars($TEXT['twofa_setup_step3'] ?? 'Enter the 6-digit code the app shows to confirm.') ?>
        </div>

        <div class="tfa-qr-row">
            <div id="tfa-qr" class="tfa-qr" aria-label="2FA QR code"></div>
            <div>
                <div class="tfa-secret-block">
                    <div class="tfa-secret-lbl"><?= htmlspecialchars($TEXT['twofa_secret_label'] ?? 'Secret key (manual entry)') ?></div>
                    <div class="tfa-secret-val"><?= htmlspecialchars($totp_secret_pretty) ?></div>
                </div>
                <p class="tfa-meta" style="margin-top:.7rem"><?= htmlspecialchars($TEXT['twofa_issuer_note'] ?? 'Algorithm: SHA-1 · 6 digits · 30-second period.') ?></p>
            </div>
        </div>

        <form action="/account_2fa" method="POST" autocomplete="off" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm">
            <div style="flex:1;min-width:200px">
                <label class="form-label"><?= htmlspecialchars($TEXT['twofa_code_label'] ?? '6-digit code from your app') ?></label>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       autocomplete="one-time-code" required
                       class="form-control" style="font-family:ui-monospace,Menlo,monospace;letter-spacing:.3em;font-size:1.15rem;text-align:center">
            </div>
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['twofa_confirm_btn'] ?? 'Verify & enable') ?></button>
        </form>

        <form action="/account_2fa" method="POST" style="margin-top:.7rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel_setup">
            <button class="btn btn-link" type="submit" style="color:#8899aa;padding:0"><?= htmlspecialchars($TEXT['twofa_cancel_setup'] ?? 'Cancel setup') ?></button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script>
    (function () {
        var el = document.getElementById('tfa-qr');
        if (!el || typeof qrcode === 'undefined') return;
        var qr = qrcode(0, 'M');
        qr.addData(<?= json_encode($totp_uri) ?>);
        qr.make();
        // Render as inline SVG sized to the container.
        el.innerHTML = qr.createSvgTag({scalable: true, margin: 0});
        var svg = el.querySelector('svg');
        if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
    })();
    </script>
    <?php endif; ?>

    <?php if (!$has_row && empty($show_codes_once)): ?>
    <!-- Not set up yet — show the Enable button. -->
    <div class="tfa-card">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.5rem"><?= htmlspecialchars($TEXT['twofa_offered_title'] ?? 'Add a second factor') ?></h2>
        <p class="tfa-sub"><?= htmlspecialchars($TEXT['twofa_offered_body'] ?? 'When 2FA is on, signing in requires both your password and a 6-digit code from your authenticator app. Password recovery and password changes will also require a code.') ?></p>
        <form action="/account_2fa" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="begin">
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-shield-plus me-2"></i><?= htmlspecialchars($TEXT['twofa_enable_btn'] ?? 'Enable 2FA') ?></button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($enabled && empty($show_codes_once)): ?>
    <!-- Enabled — Disable + Regenerate forms (both require password + current code). -->
    <div class="tfa-card">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.5rem"><i class="bi bi-key me-2"></i><?= htmlspecialchars($TEXT['twofa_regen_title'] ?? 'Regenerate backup codes') ?></h2>
        <p class="tfa-sub"><?= htmlspecialchars($TEXT['twofa_regen_body'] ?? 'Issues a fresh set of 8 single-use codes. Your existing backup codes stop working immediately.') ?></p>
        <form action="/account_2fa" method="POST" autocomplete="off" style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem;align-items:end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="regen">
            <div><label class="form-label"><?= htmlspecialchars($TEXT['current_password'] ?? 'Current password') ?></label>
                 <input type="password" name="current_password" required class="form-control"></div>
            <div><label class="form-label"><?= htmlspecialchars($TEXT['twofa_code_or_backup'] ?? '2FA code (or backup code)') ?></label>
                 <input type="text" name="code" required class="form-control"></div>
            <button class="btn btn-primary" type="submit" style="grid-column:1/-1"><i class="bi bi-arrow-clockwise me-1"></i><?= htmlspecialchars($TEXT['twofa_regen_btn'] ?? 'Generate new codes') ?></button>
        </form>
    </div>

    <div class="tfa-card">
        <h2 style="color:#f87e8a;font-size:1.1rem;font-weight:800;margin-bottom:.5rem"><i class="bi bi-shield-x me-2"></i><?= htmlspecialchars($TEXT['twofa_disable_title'] ?? 'Disable 2FA') ?></h2>
        <p class="tfa-sub"><?= htmlspecialchars($TEXT['twofa_disable_body'] ?? 'Removes the second factor from your account. The next login will need only your password again. Requires a current code to confirm.') ?></p>
        <form action="/account_2fa" method="POST" autocomplete="off" style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem;align-items:end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="disable">
            <div><label class="form-label"><?= htmlspecialchars($TEXT['current_password'] ?? 'Current password') ?></label>
                 <input type="password" name="current_password" required class="form-control"></div>
            <div><label class="form-label"><?= htmlspecialchars($TEXT['twofa_code_or_backup'] ?? '2FA code (or backup code)') ?></label>
                 <input type="text" name="code" required class="form-control"></div>
            <button class="btn btn-tfa-danger" type="submit" style="grid-column:1/-1"><?= htmlspecialchars($TEXT['twofa_disable_btn'] ?? 'Disable 2FA') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
