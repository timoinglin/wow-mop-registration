<?php
/**
 * Public API — current online roster.
 *
 *   GET /api/online[?limit=N]   →   {
 *     online_count: int,
 *     alliance:     int,
 *     horde:        int,
 *     characters:   [ { name, level, race, class, gender, guild|null }, ... ]
 *   }
 *
 * Read-only. Public on purpose (same data the /online page shows). CORS
 * is wide-open so community Discord bots / streamer overlays can fetch
 * from any origin. limit defaults to 100, capped at 500.
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=20');

$alliance_races = [1, 3, 4, 7, 11, 22, 25];
$horde_races    = [2, 5, 6, 8, 9, 10, 26];

$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min(500, $limit));

$out = ['online_count' => 0, 'alliance' => 0, 'horde' => 0, 'characters' => []];

try {
    $stmt = $pdo_chars->prepare(
        "SELECT c.name, c.level, c.race, c.class, c.gender, g.name AS guild_name
         FROM characters c
         LEFT JOIN guild_member gm ON gm.guid = c.guid
         LEFT JOIN guild g         ON g.guildid = gm.guildid
         WHERE c.online = 1
         ORDER BY c.level DESC, c.name ASC
         LIMIT $limit"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        $race = (int)$r['race'];
        $out['characters'][] = [
            'name'   => $r['name'],
            'level'  => (int)$r['level'],
            'race'   => $race,
            'class'  => (int)$r['class'],
            'gender' => (int)$r['gender'],
            'guild'  => $r['guild_name'] ?: null,
        ];
        if     (in_array($race, $alliance_races, true)) $out['alliance']++;
        elseif (in_array($race, $horde_races,    true)) $out['horde']++;
    }
    // online_count = total across the realm (not just the LIMIT page).
    $out['online_count'] = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters WHERE online = 1")->fetchColumn();
} catch (PDOException $e) {
    error_log('api_online: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'characters_db_unavailable']);
    exit;
}

echo json_encode($out);
