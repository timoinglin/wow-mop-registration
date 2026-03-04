<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Feature guard
if (empty($config['features']['recover_password'])) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../templates/header.php';

$errors         = [];
$successMessage = '';
$token          = trim($_GET['token'] ?? '');
$email          = trim($_GET['email'] ?? '');
$showForm       = false;

// --- Validate token from URL ---
if (empty($token) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = $TEXT['invalid_token'];
} else {
    try {
        $stmt = $pdo_auth->prepare("SELECT token_key, created_at FROM password_resets WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $reset_data = $stmt->fetch();

        if (!$reset_data) {
            $errors[] = $TEXT['invalid_token'];
        } elseif (!password_verify($token, $reset_data['token_key'])) {
            $errors[] = $TEXT['invalid_token'];
        } elseif (time() - strtotime($reset_data['created_at']) > 3600) {
            // Expired — clean up and tell user
            $pdo_auth->prepare("DELETE FROM password_resets WHERE email = :email")->execute(['email' => $email]);
            $errors[] = $TEXT['invalid_token'];
        } else {
            $showForm = true;
        }
    } catch (PDOException $e) {
        error_log("Reset Password Token Check DB Error: " . $e->getMessage());
        $errors[] = $TEXT['error_db'];
    }
}

// --- Handle new password submission ---
if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password     = $_POST['new_password']     ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf             = $_POST['csrf_token']        ?? null;

    if (!validate_csrf_token($csrf)) {
        $errors[] = 'Invalid request. Please go back and try again.';
        $showForm  = false;
    }
    if (empty($new_password) || empty($confirm_password)) {
        $errors[] = $TEXT['all_fields_required'];
    }
    if ($new_password !== $confirm_password) {
        $errors[] = $TEXT['password_mismatch'];
    }
    if (strlen($new_password) < 6) {
        $errors[] = $TEXT['new_password_min_length'];
    }

    if (empty($errors)) {
        try {
            $stmt_user = $pdo_auth->prepare("SELECT username FROM account WHERE email = :email");
            $stmt_user->execute(['email' => $email]);
            $username = $stmt_user->fetchColumn();

            if (!$username) {
                throw new Exception("Cannot find username for email with valid token.");
            }

            $new_hash = sha_password($username, $new_password);

            $pdo_auth->beginTransaction();
            $pdo_auth->prepare("UPDATE account SET sha_pass_hash = :hash, v = '', s = '' WHERE email = :email")
                     ->execute(['hash' => $new_hash, 'email' => $email]);
            $pdo_auth->prepare("DELETE FROM password_resets WHERE email = :email")
                     ->execute(['email' => $email]);
            $pdo_auth->commit();

            $successMessage = $TEXT['password_reset_success'];
            $showForm = false;

        } catch (PDOException $e) {
            error_log("Reset Password Update DB Error: " . $e->getMessage());
            $errors[] = $TEXT['password_reset_failed'];
            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
        } catch (Exception $e) {
            error_log("Reset Password General Error: " . $e->getMessage());
            $errors[] = $TEXT['password_reset_failed'];
            if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
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
.auth-title { font-size: 1.5rem; font-weight: 700; letter-spacing: 1px; color: #c8a96e; margin-bottom: .3rem; }
.auth-sub   { font-size: .85rem; color: #8899aa; margin-bottom: 2rem; line-height: 1.5; }
.auth-label {
    font-size: .75rem; color: #8899aa; text-transform: uppercase;
    letter-spacing: .8px; font-weight: 600; display: block; margin-bottom: .45rem;
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
/* Password strength meter */
.strength-bar { height: 4px; border-radius: 2px; margin-top: .4rem; background: rgba(255,255,255,0.07); overflow: hidden; }
.strength-fill { height: 100%; border-radius: 2px; width: 0; transition: width .3s, background .3s; }
.strength-hint { font-size: .72rem; color: #8899aa; margin-top: .3rem; }

.auth-btn {
    width: 100%; padding: .9rem; border: none; border-radius: 10px;
    background: linear-gradient(135deg, #8B4513, #A0522D);
    color: #fff; font-size: 1rem; font-weight: 700; letter-spacing: .8px;
    cursor: pointer; transition: all .25s; margin-top: 1.4rem;
}
.auth-btn:hover { background: linear-gradient(135deg,#A0522D,#c8a96e); transform:translateY(-2px); box-shadow:0 6px 20px rgba(139,69,19,.35); }
.auth-back { display:block; text-align:center; margin-top:1.2rem; font-size:.85rem; color:#8899aa; text-decoration:none; transition:color .2s; }
.auth-back:hover { color:#c8a96e; }
.auth-alert-success { background:rgba(93,216,124,.1); border:1px solid rgba(93,216,124,.3); border-radius:10px; color:#5dd87c; padding:1.2rem 1.4rem; font-size:.92rem; text-align:center; }
.auth-alert-error   { background:rgba(220,53,69,.1); border:1px solid rgba(220,53,69,.3); border-radius:10px; color:#f87e8a; padding:1rem 1.4rem; font-size:.88rem; margin-bottom:1.2rem; }
</style>

<div class="auth-wrap px-3">
<div class="auth-panel">

    <div class="auth-title">🔐 <?= $TEXT['reset_password_title'] ?></div>

    <?php if ($successMessage): ?>
        <div class="auth-alert-success">
            <i class="bi bi-check-circle" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
            <?= htmlspecialchars($successMessage) ?>
        </div>
        <a href="/login" class="auth-back"><?= $TEXT['proceed_to_login'] ?> →</a>

    <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="auth-alert-error">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
            <?php if (!$showForm && !empty($config['features']['recover_password'])): ?>
                <div style="margin-top:.6rem">
                    <a href="/recover" style="color:#c8a96e"><?= $TEXT['request_new_link'] ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
        <div class="auth-sub">Choose a strong password with at least 6 characters.</div>

        <form action="/reset_password?token=<?= urlencode($token) ?>&email=<?= urlencode($email) ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

            <div class="mb-4">
                <label class="auth-label"><?= $TEXT['new_password'] ?></label>
                <input type="password" class="auth-input" name="new_password" id="newPwd"
                       minlength="6" autocomplete="new-password"
                       oninput="checkStrength(this.value)" required>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-hint" id="strengthHint">Enter a password</div>
            </div>

            <div class="mb-3">
                <label class="auth-label"><?= $TEXT['confirm_password'] ?></label>
                <input type="password" class="auth-input" name="confirm_password"
                       minlength="6" autocomplete="new-password" required>
            </div>

            <button type="submit" class="auth-btn">
                <i class="bi bi-shield-lock me-2"></i><?= $TEXT['submit'] ?>
            </button>
        </form>
        <?php endif; ?>

    <?php endif; ?>

</div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<script>
function checkStrength(v) {
    const fill = document.getElementById('strengthFill');
    const hint = document.getElementById('strengthHint');
    let score = 0;
    if (v.length >= 6)  score++;
    if (v.length >= 10) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const levels = [
        { w: '20%',  bg: '#f87171', label: 'Very weak' },
        { w: '40%',  bg: '#fb923c', label: 'Weak' },
        { w: '60%',  bg: '#facc15', label: 'Fair' },
        { w: '80%',  bg: '#4ade80', label: 'Good' },
        { w: '100%', bg: '#5dd87c', label: 'Strong 💪' },
    ];
    const lvl = levels[Math.min(score, 4)];
    fill.style.width      = v.length ? lvl.w  : '0';
    fill.style.background = v.length ? lvl.bg : 'transparent';
    hint.textContent      = v.length ? lvl.label : 'Enter a password';
    hint.style.color      = v.length ? lvl.bg  : '#8899aa';
}
</script>
