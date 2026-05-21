<?php
// 1. Core includes needed for logic & session start
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recaptcha.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/login_history.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/wl_2fa.php';

// 2. Start session EARLY before any potential redirects or session changes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard');
    exit;
}

// 4. Initialize variables for the form/processing
$errors = [];
$username = ''; // Keep username filled on error

// Stage 2 of two-stage login: a pending_2fa session blob means the user
// already passed the password check and is now being asked for a 6-digit
// code (or a backup code). The blob expires after 5 minutes so an
// abandoned login never sits forever.
$pending_2fa = $_SESSION['pending_2fa'] ?? null;
if ($pending_2fa && (int)($pending_2fa['expires'] ?? 0) < time()) {
    unset($_SESSION['pending_2fa']);
    $pending_2fa = null;
}

// Helper: complete a successful login (used by both stage 1 -no 2FA- and stage 2).
$finalize_login = function (array $user, string $user_ip) use ($pdo_auth) {
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $stmt_gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
    $stmt_gm->execute(['id' => $user['id']]);
    $_SESSION['gm_level'] = (int)($stmt_gm->fetchColumn() ?: 0);
    $updateStmt = $pdo_auth->prepare("UPDATE account SET last_ip = :last_ip WHERE id = :id");
    $updateStmt->execute(['last_ip' => $user_ip, 'id' => $user['id']]);
    record_login((int)$user['id'], $user_ip);
    unset($_SESSION['pending_2fa']);
    header('Location: /dashboard');
    exit;
};

// 5. Handle form submission (all logic BEFORE HTML output)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $csrf_token = $_POST['csrf_token'] ?? null;
    $user_ip = get_user_ip() ?: '127.0.0.1';

    // --- Rate limit check (applies to both stages) ---
    if (is_locked_out($pdo_auth, $user_ip)) {
        $remaining = ceil(lockout_seconds_remaining($pdo_auth, $user_ip) / 60);
        $errors[] = str_replace('{minutes}', $remaining, $TEXT['login_locked_out']);
    }
    if (empty($errors) && !validate_csrf_token($csrf_token)) {
        $errors[] = $TEXT['invalid_csrf'] ?? 'Invalid CSRF token. Please refresh the page and try again.';
    }

    // STAGE 2 — 2FA code submission. Recognized by presence of the pending blob.
    if (empty($errors) && $pending_2fa && isset($_POST['twofa_code'])) {
        $code  = (string)$_POST['twofa_code'];
        $u_id  = (int)$pending_2fa['user_id'];
        if (!wl_2fa_verify($pdo_auth, $u_id, $code)) {
            record_failed_attempt($pdo_auth, $user_ip);
            $errors[] = $TEXT['twofa_err_code_bad'] ?? 'That code did not match. Try again.';
        } else {
            // Fetch the account row fresh — never trust the session blob.
            $stmt = $pdo_auth->prepare("SELECT id, username FROM account WHERE id = :id");
            $stmt->execute(['id' => $u_id]);
            $user = $stmt->fetch();
            if (!$user) {
                $errors[] = $TEXT['login_failed'];
            } else {
                clear_attempts($pdo_auth, $user_ip);
                $finalize_login($user, $user_ip);
            }
        }
    }

    // STAGE 1 — username + password. Only handled when stage 2 didn't already.
    if (empty($errors) && !$pending_2fa) {
        $username           = trim($_POST['username'] ?? '');
        $password           = $_POST['password'] ?? '';
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? null;

        if (empty($errors) && !verifyRecaptcha($recaptcha_response)) {
            $errors[] = $TEXT['recaptcha_error'];
        }
        if (empty($errors) && empty($username)) {
            $errors[] = $TEXT['username_required'];
        }
        if (empty($errors) && empty($password)) {
            $errors[] = $TEXT['password_required'];
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo_auth->prepare("SELECT id, username, sha_pass_hash FROM account WHERE username = :username");
                $stmt->execute(['username' => strtoupper($username)]);
                $user = $stmt->fetch();

                if ($user && $user['sha_pass_hash'] === sha_password($username, $password)) {
                    if (wl_2fa_is_enabled($pdo_auth, (int)$user['id'])) {
                        // Stash the password-OK state, redirect to stage 2.
                        // No partial login: $_SESSION['user_id'] is NOT set yet.
                        $_SESSION['pending_2fa'] = [
                            'user_id'  => (int)$user['id'],
                            'username' => $user['username'],
                            'expires'  => time() + 300, // 5 minutes
                        ];
                        header('Location: /login');
                        exit;
                    }
                    clear_attempts($pdo_auth, $user_ip);
                    $finalize_login($user, $user_ip);
                } else {
                    record_failed_attempt($pdo_auth, $user_ip);
                    $errors[] = $TEXT['login_failed'];
                }
            } catch (PDOException $e) {
                error_log("Login DB Error: " . $e->getMessage());
                $errors[] = $TEXT['error_db'];
            }
        }
    }
} // End of POST handling

// Stage 2 GET — if the user hits cancel, let them clear the pending state.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel_2fa']) && $pending_2fa) {
    unset($_SESSION['pending_2fa']);
    header('Location: /login');
    exit;
}

// 6. Set page title and NOW include the header (outputs HTML)
$page_title = $TEXT['login']; 
require_once __DIR__ . '/../templates/header.php';

// 7. HTML form starts here
?>

<div class="form-container" style="margin-top: 150px;">
    <h2 class="text-center mt-4 mb-4"><?= $TEXT['login'] ?></h2>

    <?php // Display messages using $errors array from above ?>
    <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success" role="alert">
            <?= $TEXT['registration_success'] ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['reset_success'])): ?>
        <div class="alert alert-success" role="alert">
            <?= $TEXT['password_reset_success'] ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($pending_2fa): /* STAGE 2 — 2FA code prompt */ ?>
    <form action="/login" method="POST" autocomplete="off">
        <p class="text-center mb-3" style="color:#8899aa">
            <i class="bi bi-shield-lock-fill me-1" style="color:var(--accent)"></i>
            <?= sprintf(htmlspecialchars($TEXT['twofa_login_prompt'] ?? 'Enter the 6-digit code from your authenticator app for %s.'), '<strong style="color:#dee2e6">' . htmlspecialchars($pending_2fa['username']) . '</strong>') ?>
        </p>
        <div class="mb-3">
            <label for="twofa_code" class="form-label"><?= htmlspecialchars($TEXT['twofa_code_or_backup'] ?? '2FA code (or backup code)') ?></label>
            <input type="text" class="form-control" id="twofa_code" name="twofa_code"
                   inputmode="numeric" autocomplete="one-time-code" maxlength="20" autofocus required
                   style="font-family:ui-monospace,Menlo,monospace;letter-spacing:.28em;font-size:1.15rem;text-align:center">
        </div>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check2 me-1"></i><?= htmlspecialchars($TEXT['twofa_confirm_btn'] ?? 'Verify & sign in') ?></button>
        </div>
        <div class="mt-3 text-center">
            <a href="/login?cancel_2fa=1" style="color:#8899aa"><?= htmlspecialchars($TEXT['twofa_login_cancel'] ?? 'Use a different account') ?></a>
        </div>
    </form>
    <?php else: /* STAGE 1 — username + password */ ?>
    <form action="/login" method="POST">
        <div class="mb-3">
            <label for="username" class="form-label"><?= $TEXT['username'] ?></label>
            <input type="text" class="form-control" id="username" name="username" required value="<?= htmlspecialchars($username) ?>">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label"><?= $TEXT['password'] ?></label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

        <!-- Google reCAPTCHA -->
        <?php if (!empty($config['features']['recaptcha'])): ?>
        <div class="mb-3 d-flex justify-content-center">
             <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($config['recaptcha']['site_key']) ?>"></div>
        </div>
        <?php endif; ?>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg"><?= $TEXT['submit'] ?></button>
        </div>
        <?php if (!empty($config['features']['recover_password'])): ?>
        <div class="mt-3 text-center">
            <a href="/recover"><?= $TEXT['recover_password'] ?>?</a>
        </div>
        <?php endif; ?>

        <div class="mt-4 p-3 text-center rounded" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #e2e8f0; font-size: 0.9rem; line-height: 1.5;">
            <strong><?= htmlspecialchars($TEXT['private_server_notice_title'] ?? 'Notice') ?>:</strong> <?= htmlspecialchars($TEXT['private_server_notice_body'] ?? 'This is a private fan server.') ?><br><?= htmlspecialchars($TEXT['not_affiliated_blizzard'] ?? 'We are not affiliated with Blizzard Entertainment.') ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php 
// 8. Include the footer
require_once __DIR__ . '/../templates/footer.php'; 
?>
