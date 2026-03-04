<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recaptcha.php';

// Feature guard
if (empty($config['features']['recover_password'])) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../templates/header.php';

$errors         = [];
$successMessage = '';
$submitted_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_email    = trim($_POST['email'] ?? '');
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? null;
    $csrf               = $_POST['csrf_token'] ?? null;

    if (!validate_csrf_token($csrf)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    }
    if (empty($submitted_email) || !filter_var($submitted_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $TEXT['invalid_email'];
    }
    if (!empty($config['features']['recaptcha']) && !verifyRecaptcha($recaptcha_response)) {
        $errors[] = $TEXT['recaptcha_error'];
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo_auth->prepare("SELECT id, username FROM account WHERE email = :email");
            $stmt->execute(['email' => $submitted_email]);
            $account = $stmt->fetch();

            if ($account) {
                $token      = bin2hex(random_bytes(32));
                $token_hash = password_hash($token, PASSWORD_DEFAULT);

                $pdo_auth->beginTransaction();
                $pdo_auth->prepare("DELETE FROM password_resets WHERE email = :email")
                         ->execute(['email' => $submitted_email]);
                $pdo_auth->prepare("INSERT INTO password_resets (email, token_key, created_at) VALUES (:email, :token_hash, NOW())")
                         ->execute(['email' => $submitted_email, 'token_hash' => $token_hash]);
                $pdo_auth->commit();

                $base_url   = rtrim($config['site']['base_url'], '/');
                $reset_link = $base_url . '/reset_password?token=' . urlencode($token) . '&email=' . urlencode($submitted_email);
                $server     = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');

                // Build email using email_template() — zero hardcoded strings
                $inner = str_replace(
                    ['{username}', '{reset_link}', '{server}'],
                    [htmlspecialchars($account['username']), $reset_link, $server],
                    $TEXT['email_body_pw_reset']
                );
                $body = email_template($inner, "Reset your {$server} password");

                $subject = "[{$server}] " . $TEXT['email_subject_pw_reset'];

                if (send_email($submitted_email, $subject, $body)) {
                    $successMessage = $TEXT['recovery_email_sent'];
                } else {
                    $errors[] = $TEXT['recovery_email_failed'];
                    if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
                }
            } else {
                // Always show the same message to prevent email enumeration
                $successMessage = $TEXT['recovery_email_sent'];
            }

        } catch (PDOException $e) {
            error_log("Password Recovery DB Error: " . $e->getMessage());
            $errors[] = $TEXT['error_db'];
            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
        } catch (Exception $e) {
            error_log("Password Recovery Token Error: " . $e->getMessage());
            $errors[] = $TEXT['recovery_email_failed'];
        }
    }
}
?>

<style>
.auth-wrap {
    max-width: 460px;
    margin: 0 auto;
    padding-top: 100px;
    padding-bottom: 3rem;
}
.auth-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(139,69,19,0.3);
    border-radius: 16px;
    padding: 2.4rem 2rem;
}
.auth-title {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: #c8a96e;
    margin-bottom: .3rem;
}
.auth-sub {
    font-size: .85rem;
    color: #8899aa;
    margin-bottom: 2rem;
    line-height: 1.5;
}
.auth-label {
    font-size: .75rem;
    color: #8899aa;
    text-transform: uppercase;
    letter-spacing: .8px;
    font-weight: 600;
    display: block;
    margin-bottom: .45rem;
}
.auth-input {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 10px;
    color: #e2e8f0;
    padding: .85rem 1rem;
    font-size: .95rem;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.auth-input:focus {
    border-color: rgba(200,169,110,0.5);
    box-shadow: 0 0 0 3px rgba(139,69,19,0.15);
    background: rgba(255,255,255,0.07);
}
.auth-btn {
    width: 100%;
    padding: .9rem;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #8B4513, #A0522D);
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: .8px;
    cursor: pointer;
    transition: all .25s;
    margin-top: 1.4rem;
}
.auth-btn:hover {
    background: linear-gradient(135deg, #A0522D, #c8a96e);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(139,69,19,.35);
}
.auth-back {
    display: block;
    text-align: center;
    margin-top: 1.2rem;
    font-size: .85rem;
    color: #8899aa;
    text-decoration: none;
    transition: color .2s;
}
.auth-back:hover { color: #c8a96e; }
.auth-alert-success {
    background: rgba(93,216,124,.1);
    border: 1px solid rgba(93,216,124,.3);
    border-radius: 10px;
    color: #5dd87c;
    padding: 1.2rem 1.4rem;
    font-size: .92rem;
    text-align: center;
}
.auth-alert-error {
    background: rgba(220,53,69,.1);
    border: 1px solid rgba(220,53,69,.3);
    border-radius: 10px;
    color: #f87e8a;
    padding: 1rem 1.4rem;
    font-size: .88rem;
    margin-bottom: 1.2rem;
}
</style>

<div class="auth-wrap px-3">
<div class="auth-panel">

    <div class="auth-title">🔑 <?= $TEXT['recover_password'] ?></div>

    <?php if ($successMessage): ?>
        <div class="auth-alert-success">
            <i class="bi bi-envelope-check" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
            <?= htmlspecialchars($successMessage) ?>
        </div>
        <a href="/login" class="auth-back"><?= $TEXT['back_to_login'] ?></a>

    <?php else: ?>
        <div class="auth-sub"><?= $TEXT['recover_form_intro'] ?></div>

        <?php if (!empty($errors)): ?>
        <div class="auth-alert-error">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        </div>
        <?php endif; ?>

        <form action="/recover" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

            <div class="mb-4">
                <label class="auth-label"><?= $TEXT['email'] ?></label>
                <input type="email" class="auth-input" name="email" autocomplete="email"
                       placeholder="you@example.com"
                       value="<?= htmlspecialchars($submitted_email) ?>" required>
            </div>

            <?php if (!empty($config['features']['recaptcha'])): ?>
            <div class="d-flex justify-content-center mb-3">
                <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($config['recaptcha']['site_key']) ?>"></div>
            </div>
            <?php endif; ?>

            <button type="submit" class="auth-btn">
                <i class="bi bi-send me-2"></i><?= $TEXT['submit'] ?>
            </button>
        </form>
        <a href="/login" class="auth-back">← <?= $TEXT['back_to_login'] ?></a>
    <?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
