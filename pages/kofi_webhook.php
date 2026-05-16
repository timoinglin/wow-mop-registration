<?php
/**
 * Ko-fi Webhook endpoint  —  POST /kofi_webhook
 *
 * Ko-fi delivers donations as an application/x-www-form-urlencoded POST with a
 * single `data` field containing a JSON string. This endpoint is intentionally
 * bare: NO session, NO CSRF, NO login. The shared secret IS the auth — the
 * JSON's `verification_token` is checked against config.donation inside
 * donation_process_webhook(). It must not pull in templates/header.php (which
 * would emit HTML and a session cookie).
 *
 * Response contract:
 *   - 200 for every *handled* outcome (credited / unattributed / ignored /
 *     duplicate / rejected). Ko-fi retries non-2xx, so we deliberately 200 even
 *     a bad token to avoid a retry storm; the reason is logged server-side.
 *   - 405 if not POST, 400 if the payload is missing/!JSON (not Ko-fi).
 *   - 500 only on a genuine DB fault — a 5xx tells Ko-fi to retry later, which
 *     is the correct behaviour for a transient error.
 *
 * Crediting happens ONLY here (webhook). The Ko-fi thank-you/redirect page is
 * never trusted to credit anything.
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/donation.php';

header('Content-Type: text/plain; charset=utf-8');

// Feature must be on and a real token configured.
if (!donation_enabled($config)) {
    http_response_code(503);
    echo 'donations disabled';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'method not allowed';
    exit;
}

$raw = $_POST['data'] ?? null;
if (!is_string($raw) || $raw === '') {
    http_response_code(400);
    echo 'missing data';
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo 'invalid json';
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $result = donation_process_webhook($pdo_auth, $config, $data, $ip);
} catch (Throwable $e) {
    error_log('kofi_webhook fatal: ' . $e->getMessage());
    http_response_code(500);
    echo 'error';
    exit;
}

if ($result['status'] === 'rejected') {
    // Logged for the admin; still 200 so Ko-fi doesn't retry a bad token.
    error_log('kofi_webhook rejected: ' . ($result['message'] ?? 'unknown'));
}

http_response_code(200);
echo $result['status'];
