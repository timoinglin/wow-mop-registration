<?php
/**
 * Markdown preview endpoint for the admin news editor.
 * POST { csrf_token, body }  →  { html: "<...>" }
 *
 * GM 9+ only. CSRF-checked. No persistence — purely a render-as-you-type helper.
 */

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm->execute(['id' => $_SESSION['user_id']]);
if ((int)($gm->fetchColumn() ?: 0) < 9) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
    exit;
}

$body = (string)($_POST['body'] ?? '');
if (mb_strlen($body) > 100000) {
    echo json_encode(['error' => 'too long']);
    exit;
}

echo json_encode(['html' => render_markdown($body)]);
