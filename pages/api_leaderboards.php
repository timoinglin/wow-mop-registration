<?php
/**
 * Public API — rated PvP leaderboard for a bracket.
 *
 *   GET /api/leaderboards/<bracket>[?limit=N]
 *
 *   bracket  ∈  { "2v2", "3v3", "rbg" }
 *   limit    1..100, default 20
 *
 * →  { bracket: "3v3", season: 14, top: [
 *       { rank, name, level, race, class, gender, rating, wins }, …
 *    ] }
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=60');

$bracket = strtolower(trim((string)($_GET['bracket'] ?? '')));
$slot_for = ['2v2' => 0, '3v3' => 1, 'rbg' => 3];
if (!isset($slot_for[$bracket])) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_bracket', 'allowed' => array_keys($slot_for)]);
    exit;
}
$slot  = $slot_for[$bracket];
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$auth_db = $config['db']['name_auth'] ?? 'auth';

$out = ['bracket' => $bracket, 'season' => null, 'top' => []];

try {
    // Current season — same logic as the /leaderboards page.
    $out['season'] = (int)$pdo_chars->query("SELECT MAX(season) FROM rated_pvp_info")->fetchColumn();

    $sql = "SELECT c.name, c.level, c.race, c.class, c.gender,
                   rpi.rating, rpi.season_wins
            FROM rated_pvp_info rpi
            JOIN characters c                 ON c.guid    = rpi.guid
            JOIN `{$auth_db}`.account a       ON a.id      = c.account
            WHERE a.username NOT LIKE 'BOT%'
              AND rpi.slot   = :slot
              AND rpi.season = :season
              AND rpi.rating > 0
            ORDER BY rpi.rating DESC, rpi.season_wins DESC, c.name ASC
            LIMIT $limit";
    $stmt = $pdo_chars->prepare($sql);
    $stmt->execute(['slot' => $slot, 'season' => $out['season']]);
    $rank = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out['top'][] = [
            'rank'   => ++$rank,
            'name'   => $r['name'],
            'level'  => (int)$r['level'],
            'race'   => (int)$r['race'],
            'class'  => (int)$r['class'],
            'gender' => (int)$r['gender'],
            'rating' => (int)$r['rating'],
            'wins'   => (int)$r['season_wins'],
        ];
    }
} catch (PDOException $e) {
    error_log('api_leaderboards: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'rated_pvp_unavailable']);
    exit;
}

echo json_encode($out);
