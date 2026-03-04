<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recaptcha.php';
require_once __DIR__ . '/../templates/header.php'; // Also includes lang.php
$config = require __DIR__ . '/../config.php';

$errors = [];
$successMessage = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Input sanitization (basic example)
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? null;
    $csrf_token = $_POST['csrf_token'] ?? null;

    // --- Validation --- 

    // CSRF verification
    if (!validate_csrf_token($csrf_token)) {
        $errors[] = "Invalid CSRF token. Please refresh the page and try again.";
    }

    // reCAPTCHA verification
    if (!verifyRecaptcha($recaptcha_response)) {
        $errors[] = $TEXT['recaptcha_error'];
    }

    // Username validation
    if (empty($username)) {
        $errors[] = $TEXT['username_required'];
    } elseif (strlen($username) > 32) {
        $errors[] = $TEXT['username_length'];
    } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
        $errors[] = $TEXT['username_format'];
    }

    // Email validation
    if (empty($email)) {
        $errors[] = $TEXT['email_required'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $TEXT['invalid_email'];
    } elseif (strlen($email) > 255) {
        $errors[] = $TEXT['email_length'];
    }

    // Password validation
    if (empty($password)) {
        $errors[] = $TEXT['password_required'];
    } elseif (strlen($password) < 6) { // Example: Minimum length
        $errors[] = $TEXT['password_min_length'];
    }
    if ($password !== $confirm_password) {
        $errors[] = $TEXT['password_mismatch'];
    }

    // --- Database Checks (only if basic validation passes) ---
    if (empty($errors)) {
        try {
            // Check if username exists
            $stmt = $pdo_auth->prepare("SELECT id FROM account WHERE username = :username");
            $stmt->execute(['username' => strtoupper($username)]);
            if ($stmt->fetch()) {
                $errors[] = $TEXT['username_taken'];
            }

            // Check if email exists (optional, based on server rules)
            $stmt = $pdo_auth->prepare("SELECT id FROM account WHERE email = :email");
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $errors[] = $TEXT['email_taken'];
            }

        } catch (PDOException $e) {
            error_log("Registration Check DB Error: " . $e->getMessage());
            $errors[] = $TEXT['error_db'];
        }
    }

    // --- Create Account (if no errors) ---
    if (empty($errors)) {
        try {
            // $s_hex = null; // SRP6 disabled
            // $v_hex = calculate_srp6_verifier($username, $password, $s_hex); // SRP6 disabled
            // if ($v_hex === null || $s_hex === null) { // SRP6 disabled
            //    throw new Exception("Failed to calculate SRP6 verifier. Check GMP extension and PHP logs."); // SRP6 disabled
            // } // SRP6 disabled

            $hashed_password = sha_password($username, $password); // Calculate ONLY sha_pass_hash
            $user_ip = get_user_ip() ?: '127.0.0.1';

            // Original SQL only inserting sha_pass_hash
            $sql = "INSERT INTO account (username, sha_pass_hash, email, reg_mail, joindate, last_ip, expansion) 
                    VALUES (:username, :sha_pass_hash, :email, :reg_mail, NOW(), :last_ip, :expansion)";
            $stmt = $pdo_auth->prepare($sql);

            $stmt->execute([
                'username' => strtoupper($username),
                'sha_pass_hash' => $hashed_password, // Only sha_pass_hash
                // 'v' => $v_hex, // SRP6 disabled
                // 's' => $s_hex, // SRP6 disabled
                'email' => $email,
                'reg_mail' => $email,
                'last_ip' => $user_ip,
                'expansion' => $config['realm']['expansion'] // Expansion ID from config
            ]);

            $successMessage = $TEXT['registration_success'];

        } catch (PDOException $e) {
            error_log("Registration Insert DB Error: " . $e->getMessage());
            $errors[] = $TEXT['registration_failed'] . " (" . $TEXT['error_db'] . ")";
        }
    }
}

?>

<div class="form-container" style="margin-top: 150px;">
    <h2 class="text-center mt-4 mb-4"><?= $TEXT['register'] ?></h2>

    <?php if ($successMessage): ?>
        <div class="alert alert-success" role="alert">
            <?= htmlspecialchars($successMessage) ?>
            <p><a href="/login" class="alert-link"><?= $TEXT['proceed_to_login'] ?></a></p>
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

    <?php if (empty($successMessage)): // Hide form on success ?>
    <form action="/register" method="POST">
        <div class="mb-3">
            <label for="username" class="form-label"><?= $TEXT['username'] ?></label>
            <input type="text" class="form-control" id="username" name="username" maxlength="32" required value="<?= htmlspecialchars($username ?? '') ?>">
            <div id="usernameHelp" class="form-text text-light"><?= $TEXT['username_help'] ?></div>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label"><?= $TEXT['email'] ?></label>
            <input type="email" class="form-control" id="email" name="email" maxlength="255" required value="<?= htmlspecialchars($email ?? '') ?>">
        </div>
        <div class="mb-3">
            <label for="password" class="form-label"><?= $TEXT['password'] ?></label>
            <input type="password" class="form-control" id="password" name="password" minlength="6" required>
            <div id="passwordHelp" class="form-text text-light"><?= $TEXT['password_min_length'] ?></div>
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label"><?= $TEXT['confirm_password'] ?></label>
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
        </div>

        <!-- Google reCAPTCHA -->
        <?php if (!empty($config['features']['recaptcha'])): ?>
        <div class="mb-3 d-flex justify-content-center">
             <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($config['recaptcha']['site_key']) ?>"></div>
        </div>
        <?php endif; ?>

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg"><?= $TEXT['submit'] ?></button>
        </div>
    </form>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
