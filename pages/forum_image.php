<?php
/**
 * Forum image upload endpoint — called by EasyMDE on the forum composer
 * (new thread, reply, edit). Any logged-in, non-banned user can upload.
 *
 * POST multipart/form-data:
 *   csrf_token = (session token)
 *   image      = (the file, field name matches EasyMDE's default)
 *
 * Response: JSON { url, name, size } on success, { error } + 4xx on failure.
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/forum.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$user_id  = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');

if (forum_is_user_banned($pdo_auth, $user_id)) {
    http_response_code(403);
    echo json_encode(['error' => 'You are banned from posting.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
if (!validate_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid CSRF token. Reload and try again.']);
    exit;
}

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload failed.']);
    exit;
}

$file = $_FILES['image'];
$max_size = 5 * 1024 * 1024;
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
$detected_mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
if ($detected_mime === '' || !isset($allowed[$detected_mime])) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported image type. Allowed: jpg, png, webp, gif.']);
    exit;
}
$ext = $allowed[$detected_mime];

$dest_dir = __DIR__ . '/../uploads/forum';
if (!is_dir($dest_dir)) {
    if (!@mkdir($dest_dir, 0775, true) && !is_dir($dest_dir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Server could not create upload directory.']);
        exit;
    }
}

// Server-generated filename — clients never control the on-disk name.
$rand = bin2hex(random_bytes(4));
$basename = 'fp-' . $user_id . '-' . date('YmdHis') . '-' . $rand . '.' . $ext;
$dest_path = $dest_dir . DIRECTORY_SEPARATOR . $basename;

if (!@move_uploaded_file($file['tmp_name'], $dest_path)) {
    error_log('forum_image: move_uploaded_file failed for ' . $dest_path);
    http_response_code(500);
    echo json_encode(['error' => 'Could not save file on the server.']);
    exit;
}
@chmod($dest_path, 0644);

log_admin_action(
    $pdo_auth, $user_id, $username, 'forum_image_upload', $basename,
    sprintf('%.1f KB, %s', $file['size'] / 1024, $detected_mime), null
);

echo json_encode([
    'url'  => '/uploads/forum/' . $basename,
    'name' => $basename,
    'size' => (int)$file['size'],
]);
