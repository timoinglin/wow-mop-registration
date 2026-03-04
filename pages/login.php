<?php
// 1. Core includes needed for logic & session start
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recaptcha.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/login_history.php';
require_once __DIR__ . '/../includes/lang.php';

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

// 5. Handle form submission (all logic BEFORE HTML output)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? null;
    $csrf_token = $_POST['csrf_token'] ?? null;
    $user_ip = get_user_ip() ?: '127.0.0.1';

    // --- Rate limit check ---
    if (is_locked_out($pdo_auth, $user_ip)) {
        $remaining = ceil(lockout_seconds_remaining($pdo_auth, $user_ip) / 60);
        $errors[] = str_replace('{minutes}', $remaining, $TEXT['login_locked_out']);
    }

    // --- Validation ---
    if (empty($errors) && !validate_csrf_token($csrf_token)) {
        $errors[] = "Invalid CSRF token. Please refresh the page and try again.";
    }
    if (empty($errors) && !verifyRecaptcha($recaptcha_response)) {
        $errors[] = $TEXT['recaptcha_error'];
    }
    if (empty($errors) && empty($username)) {
        $errors[] = $TEXT['username_required'];
    }
    if (empty($errors) && empty($password)) {
        $errors[] = $TEXT['password_required'];
    }

    // --- Authentication --- (only if basic validation passes)
    if (empty($errors)) {
        try {
            $stmt = $pdo_auth->prepare("SELECT id, username, sha_pass_hash FROM account WHERE username = :username");
            $stmt->execute(['username' => strtoupper($username)]);
            $user = $stmt->fetch();

            if ($user && $user['sha_pass_hash'] === sha_password($username, $password)) {

                if (empty($errors)) {
                    // Clear failed attempts
                    clear_attempts($pdo_auth, $user_ip);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];

                    $stmt_gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
                    $stmt_gm->execute(['id' => $user['id']]);
                    $_SESSION['gm_level'] = (int)($stmt_gm->fetchColumn() ?: 0);

                    $updateStmt = $pdo_auth->prepare("UPDATE account SET last_ip = :last_ip WHERE id = :id");
                    $updateStmt->execute(['last_ip' => $user_ip, 'id' => $user['id']]);

                    // Record login history (flat file, no DB)
                    record_login((int)$user['id'], $user_ip);

                    header("Location: /dashboard");
                    exit;
                }

            } else {
                // Record failed attempt
                record_failed_attempt($pdo_auth, $user_ip);
                $errors[] = $TEXT['login_failed'];
            }

        } catch (PDOException $e) {
            error_log("Login DB Error: " . $e->getMessage());
            $errors[] = $TEXT['error_db'];
        }
    }
} // End of POST handling

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
            <strong>Notice:</strong> This is a private fan server.<br>We are not affiliated with Blizzard Entertainment.
        </div>
    </form>
</div>

<?php 
// 8. Include the footer
require_once __DIR__ . '/../templates/footer.php'; 
?>
