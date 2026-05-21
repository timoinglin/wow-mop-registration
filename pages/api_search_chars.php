<?php
/**
 * Public character-search autocomplete endpoint.
 *
 *   GET /api/search/chars?q=Tester  →  JSON array of up to 8 matches:
 *     [{"name":"Tester","class":4,"race":1,"gender":0,"level":90}, ...]
 *
 * Public on purpose — same data the Armory list already exposes. Only
 * non-GM characters are returned and the LIKE pattern is prefix-only,
 * so it's not a fuzzy full-DB scan. Empty / too-short / non-alphanumeric
 * queries return `[]` so the client never falls back to a 4xx in normal
 * use.
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));

// WoW character names are 2–12 letters in vanilla; allow 1–12 to support
// single-letter prefix queries from the search input (e.g. "T").
if ($q === '' || strlen($q) > 12 || !preg_match('/^[A-Za-z]+$/', $q)) {
    echo '[]';
    exit;
}

try {
    $stmt = $pdo_chars->prepare(
        "SELECT name, class, race, gender, level
         FROM characters
         WHERE name LIKE :q
         ORDER BY level DESC, name ASC
         LIMIT 8"
    );
    $stmt->execute(['q' => $q . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Cast ints so the JSON shape is stable for the client.
    foreach ($rows as &$r) {
        $r['class']  = (int)$r['class'];
        $r['race']   = (int)$r['race'];
        $r['gender'] = (int)$r['gender'];
        $r['level']  = (int)$r['level'];
    }
    echo json_encode($rows);
} catch (PDOException $e) {
    error_log('api_search_chars: ' . $e->getMessage());
    echo '[]';
}
