<?php
/**
 * Auth-gated ticket attachment serve endpoint.
 *
 * URL: /ticket_attachment?f={filename}
 *
 * Streams a file from /uploads/tickets/{filename} only if the requester is:
 *   - logged in, AND
 *   - either a GM (gm_level >= 9), OR
 *   - the owner of a ticket whose tickets.attachments OR ticket_messages.attachments
 *     references this filename.
 *
 * Otherwise: 403. If the file doesn't exist on disk: 404.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

// 1) Must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Location: /login');
    exit;
}

// 2) Filename must be a sane basename only (no path traversal)
$filename = $_GET['f'] ?? '';
if (!is_string($filename) || $filename === '' || !preg_match('/^[A-Za-z0-9._-]{1,200}$/', $filename)) {
    http_response_code(400);
    echo 'Invalid filename.';
    exit;
}

// Defensive: basename() strips any path component that slipped through
$filename = basename($filename);

$path = realpath(__DIR__ . '/../uploads/tickets/' . $filename);
$base = realpath(__DIR__ . '/../uploads/tickets');

// 3) Must resolve inside uploads/tickets/ (no escaping)
if ($path === false || $base === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

if (!is_file($path)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

// 4) Authorization
$is_gm = isset($_SESSION['gm_level']) && (int)$_SESSION['gm_level'] >= 9;
$authorized = $is_gm;

if (!$authorized) {
    // Look for the filename in ANY ticket message belonging to this user, OR
    // in the legacy tickets.attachments column.
    try {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filename) . '%';

        // ticket_messages → tickets, where ticket.user_id = current
        $q1 = $pdo_auth->prepare(
            "SELECT 1 FROM ticket_messages tm
             JOIN tickets t ON t.id = tm.ticket_id
             WHERE t.user_id = :uid
               AND tm.attachments LIKE :like
             LIMIT 1"
        );
        $q1->execute(['uid' => $_SESSION['user_id'], 'like' => $like]);
        if ($q1->fetchColumn()) {
            $authorized = true;
        }

        if (!$authorized) {
            $q2 = $pdo_auth->prepare(
                "SELECT 1 FROM tickets WHERE user_id = :uid AND attachments LIKE :like LIMIT 1"
            );
            $q2->execute(['uid' => $_SESSION['user_id'], 'like' => $like]);
            if ($q2->fetchColumn()) $authorized = true;
        }
    } catch (PDOException $e) {
        error_log('ticket_attachment authz query failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'Server error.';
        exit;
    }
}

if (!$authorized) {
    http_response_code(403);
    echo 'Forbidden.';
    exit;
}

// 5) Stream the file with sensible headers
$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($path);
    if ($detected) $mime = $detected;
}

// Only allow image types to be inlined; everything else forces download
$inline_safe = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
$disposition = $inline_safe ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');

// Strip any leading filename junk (uniqid prefix) for nicer download names
readfile($path);
exit;
