<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recaptcha.php';
// header.php includes lang.php and starts the session
require_once __DIR__ . '/../templates/header.php';

// --- Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$successMessage = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // --- Validation ---
    // reCAPTCHA (optional, authenticated page)
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? null;
    if (!verifyRecaptcha($recaptcha_response)) {
        $errors[] = $TEXT['recaptcha_error'];
    }
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $errors[] = $TEXT['all_fields_required'];
    }
    if ($new_password !== $confirm_password) {
        $errors[] = $TEXT['password_mismatch'];
    }
    if (strlen($new_password) < 6) { // Ensure new password meets requirements
        $errors[] = $TEXT['new_password_min_length'];
    }

    // --- Verification and Update --- (only if basic validation passes)
    if (empty($errors)) {
        try {
            // Fetch current user details (username needed for hash check)
            $stmt = $pdo_auth->prepare("SELECT username, sha_pass_hash FROM account WHERE id = :id");
            $stmt->execute(['id' => $user_id]);
            $user = $stmt->fetch();

            if (!$user) {
                // Should not happen, but handle defensively
                throw new Exception('User not found despite active session.');
            }

            // Verify current password
            if (sha_password($user['username'], $current_password) !== $user['sha_pass_hash']) {
                $errors[] = $TEXT['invalid_current_password'];
            } else {
                // Current password is correct, proceed to update
                $new_hashed_password = sha_password($user['username'], $new_password);

                // Update sha_pass_hash and CLEAR v/s to force client re-generation
                $updateStmt = $pdo_auth->prepare("UPDATE account SET sha_pass_hash = :new_hash, v = '', s = '' WHERE id = :id");
                $updateStmt->execute([
                    'new_hash' => $new_hashed_password,
                    'id' => $user_id
                ]);

                if ($updateStmt->rowCount() > 0) {
                    $successMessage = $TEXT['password_changed'];
                } else {
                    $errors[] = $TEXT['password_change_failed'];
                }
            }
        } catch (PDOException $e) {
            error_log("Change Password DB Error: " . $e->getMessage());
            $errors[] = $TEXT['error_db'];
        } catch (Exception $e) {
             error_log("Change Password General Error: " . $e->getMessage());
             $errors[] = $TEXT['password_change_failed'];
             // Log out user if their session seems corrupted
             session_destroy();
             header('Location: /login?error=session_error');
             exit;
        }
    }
}

?>

<div class="form-container">
    <h2 class="text-center mb-4"><?= $TEXT['change_password'] ?></h2>

    <?php if ($successMessage): ?>
        <div class="alert alert-success" role="alert">
            <?= htmlspecialchars($successMessage) ?>
            <p><a href="/dashboard" class="alert-link"><?= $TEXT['return_to_dashboard'] ?></a></p>
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
    <form action="/change_password" method="POST">
        <div class="mb-3">
            <label for="current_password" class="form-label"><?= $TEXT['current_password'] ?></label>
            <input type="password" class="form-control" id="current_password" name="current_password" required>
        </div>
        <div class="mb-3">
            <label for="new_password" class="form-label"><?= $TEXT['new_password'] ?></label>
            <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" required>
             <div id="passwordHelp" class="form-text text-light"><?= $TEXT['new_password_min_length'] ?></div>
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

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg"><?= $TEXT['submit'] ?></button>
        </div>
        <div class="mt-3 text-center">
             <a href="/dashboard"><?= $TEXT['back_to_dashboard'] ?></a>
        </div>
    </form>
     <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
