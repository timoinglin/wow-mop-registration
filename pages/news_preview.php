<?php
/**
 * Markdown preview endpoint used by every EasyMDE composer on the site
 * (admin news editor + forum new-thread / reply / edit). Stateless render
 * service — purely returns the same HTML the public pages would render.
 *
 * POST { csrf_token, body }  →  { html: "<...>" }
 *
 * Auth: any logged-in user. The endpoint has no side effects and only
 * returns Parsedown-safe-mode HTML, so a GM-only gate would just block
 * regular forum users from previewing their own posts without adding any
 * security value (CSRF still keeps cross-origin abuse out).
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
