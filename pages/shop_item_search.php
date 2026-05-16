<?php
/**
 * Shop item search — AJAX endpoint for the tile editor's item picker.
 * GM 9+ only. GET ?q=term  →  { items: [ {entry, name}, ... ] }
 *
 * Searches world.item_template by name (or exact entry id if numeric).
 * Read-only, no side effects.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/shop.php';

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

[$ok] = shop_availability($pdo_world ?? null, $config);
if (!$ok) {
    http_response_code(409);
    echo json_encode(['error' => 'shop_unavailable']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
echo json_encode(['items' => shop_item_search($pdo_world, $q, 25)]);
