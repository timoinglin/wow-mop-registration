<?php
/**
 * News image upload endpoint — called by the EasyMDE editor on the admin
 * news form. GM 9+ only, CSRF-protected, MIME-validated, size-capped, and
 * filenames are server-generated so users never control the on-disk name.
 *
 * POST multipart/form-data:
 *   csrf_token = (session token)
 *   image      = (the file, field name matches EasyMDE's default)
 *
 * Response: JSON
 *   success: { "url": "/uploads/news/news-1715561234-abc12345.webp" }
 *   error:   { "error": "human-readable reason" }  + 4xx status
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

// ─── Auth: GM 9+ ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
if ((int)($gm->fetchColumn() ?: 0) < 9) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ─── Method + CSRF ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token. Reload the page and try again.']);
    exit;
}

// ─── File validation ────────────────────────────────────────────────────────
if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload failed.']);
    exit;
}

$file = $_FILES['image'];
$max_size = 5 * 1024 * 1024; // 5 MB
if ((int)$file['size'] > $max_size) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large (max 5 MB).']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

// Detect the real MIME from file contents, not from the client's claim
$detected_mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
if ($detected_mime === '' || !isset($allowed[$detected_mime])) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported image type. Allowed: jpg, png, webp, gif.']);
    exit;
}
$ext = $allowed[$detected_mime];

// ─── Destination directory ──────────────────────────────────────────────────
$dest_dir = __DIR__ . '/../uploads/news';
if (!is_dir($dest_dir)) {
    if (!@mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Server could not create upload directory.']);
        exit;
    }
}

// ─── Server-generated filename (clients never control it) ───────────────────
$rand = bin2hex(random_bytes(4)); // 8 hex chars
$basename = 'news-' . date('YmdHis') . '-' . $rand . '.' . $ext;
$dest_path = $dest_dir . DIRECTORY_SEPARATOR . $basename;

if (!@move_uploaded_file($file['tmp_name'], $dest_path)) {
    error_log('news_image: move_uploaded_file failed for ' . $dest_path);
    http_response_code(500);
    echo json_encode(['error' => 'Could not save file on the server.']);
    exit;
}

// Tighten perms (best-effort — fine on Linux, no-op on Windows)
@chmod($dest_path, 0644);

// ─── Audit log + response ───────────────────────────────────────────────────
log_admin_action(
    $pdo_auth,
    (int)$_SESSION['user_id'],
    $_SESSION['username'] ?? 'Admin',
    'news_image_upload',
    $basename,
    sprintf('%.1f KB, %s', $file['size'] / 1024, $detected_mime),
    null
);

echo json_encode([
    'url'  => '/uploads/news/' . $basename,
    'name' => $basename,
    'size' => (int)$file['size'],
]);
