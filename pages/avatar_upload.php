<?php
/**
 * Avatar upload / delete endpoint — called from the user dashboard.
 *
 * POST multipart/form-data, two supported actions:
 *   action=upload + image=<file>   → save avatar, replace any existing one
 *   action=delete                  → remove the user's avatar (revert to initials)
 *
 * Auth: any logged-in user can manage their own avatar.
 * Security: CSRF, server-side MIME sniff, 2 MB cap, server-generated filename.
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard');
    exit;
}

$redirect_with = function (string $key, ?string $val = null) {
    $u = '/dashboard';
    if ($val !== null) $u .= '?' . urlencode($key) . '=' . urlencode($val);
    elseif ($key !== '') $u .= '?' . urlencode($key) . '=1';
    header('Location: ' . $u . '#avatar');
    exit;
};

if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
    $redirect_with('avatar_error', 'csrf');
}

$action = $_POST['action'] ?? '';

$dest_dir = __DIR__ . '/../uploads/avatars';
if (!is_dir($dest_dir)) {
    if (!@mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
        error_log('avatar_upload: cannot create ' . $dest_dir);
        $redirect_with('avatar_error', 'server');
    }
}

// ─── DELETE ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    try {
        // Pull existing record so we can remove the file too
        $stmt = $pdo_auth->prepare("SELECT filename FROM web_user_avatars WHERE account_id = :id");
        $stmt->execute(['id' => $user_id]);
        $old = $stmt->fetchColumn();
        if ($old) {
            $old_path = $dest_dir . DIRECTORY_SEPARATOR . basename($old);
            if (is_file($old_path)) @unlink($old_path);
            $del = $pdo_auth->prepare("DELETE FROM web_user_avatars WHERE account_id = :id");
            $del->execute(['id' => $user_id]);
            log_admin_action($pdo_auth, $user_id, $username, 'avatar_delete', $old, null, null);
        }
    } catch (PDOException $e) {
        error_log('avatar delete failed: ' . $e->getMessage());
        $redirect_with('avatar_error', 'server');
    }
    $redirect_with('avatar_removed');
}

// ─── UPLOAD ─────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $redirect_with('avatar_error', 'no_file');
    }
    $file = $_FILES['image'];

    $max_size = 2 * 1024 * 1024; // 2 MB
    if ((int)$file['size'] > $max_size) {
        $redirect_with('avatar_error', 'size');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $detected_mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
    if ($detected_mime === '' || !isset($allowed[$detected_mime])) {
        $redirect_with('avatar_error', 'type');
    }
    $ext = $allowed[$detected_mime];

    // Server-generated filename keyed by account id (so this user's avatar
    // is always at the same slot — overwrite any previous file).
    $basename = $user_id . '.' . $ext;
    $dest_path = $dest_dir . DIRECTORY_SEPARATOR . $basename;

    // If a previous avatar exists with a *different* extension, clean it up
    // so we don't leave orphan files lying around.
    try {
        $stmt = $pdo_auth->prepare("SELECT filename FROM web_user_avatars WHERE account_id = :id");
        $stmt->execute(['id' => $user_id]);
        $old = $stmt->fetchColumn();
        if ($old && $old !== $basename) {
            $old_path = $dest_dir . DIRECTORY_SEPARATOR . basename($old);
            if (is_file($old_path)) @unlink($old_path);
        }
    } catch (PDOException $e) {
        error_log('avatar cleanup failed: ' . $e->getMessage());
    }

    if (!@move_uploaded_file($file['tmp_name'], $dest_path)) {
        error_log('avatar upload: move_uploaded_file failed for ' . $dest_path);
        $redirect_with('avatar_error', 'server');
    }
    @chmod($dest_path, 0644);

    // Upsert the DB row
    try {
        $upsert = $pdo_auth->prepare(
            "INSERT INTO web_user_avatars (account_id, filename, mime_type)
             VALUES (:id, :fn, :mt)
             ON DUPLICATE KEY UPDATE filename = VALUES(filename), mime_type = VALUES(mime_type)"
        );
        $upsert->execute(['id' => $user_id, 'fn' => $basename, 'mt' => $detected_mime]);
    } catch (PDOException $e) {
        error_log('avatar db upsert failed: ' . $e->getMessage());
        $redirect_with('avatar_error', 'server');
    }

    log_admin_action(
        $pdo_auth,
        $user_id,
        $username,
        'avatar_upload',
        $basename,
        sprintf('%.1f KB, %s', $file['size'] / 1024, $detected_mime),
        null
    );

    $redirect_with('avatar_uploaded');
}

// Unknown action
header('Location: /dashboard');
exit;
