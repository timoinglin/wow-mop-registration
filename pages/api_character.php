<?php
/**
 * Public API — single character profile.
 *
 *   GET /api/character/<name>
 *
 * →  { name, level, race, class, gender, online, guild, zone,
 *       honorable_kills, total_playtime_seconds, last_logout|null }
 *
 * Returns 404 when the character isn't found. Same scope as the
 * Armory profile, just JSON.
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=30');

$name = trim((string)($_GET['name'] ?? ''));
if ($name === '' || strlen($name) > 12 || !preg_match('/^[A-Za-z]+$/', $name)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_name']);
    exit;
}

try {
    $stmt = $pdo_chars->prepare(
        "SELECT c.name, c.level, c.race, c.class, c.gender, c.online,
                c.zone, c.totaltime, c.totalKills, c.logout_time,
                g.name AS guild_name
         FROM characters c
         LEFT JOIN guild_member gm ON gm.guid = c.guid
         LEFT JOIN guild g         ON g.guildid = gm.guildid
         WHERE c.name = :name LIMIT 1"
    );
    $stmt->execute(['name' => $name]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found']);
        exit;
    }

    echo json_encode([
        'name'                   => $r['name'],
        'level'                  => (int)$r['level'],
        'race'                   => (int)$r['race'],
        'class'                  => (int)$r['class'],
        'gender'                 => (int)$r['gender'],
        'online'                 => (int)$r['online'] === 1,
        'guild'                  => $r['guild_name'] ?: null,
        'zone'                   => (int)$r['zone'],
        'honorable_kills'        => (int)($r['totalKills'] ?? 0),
        'total_playtime_seconds' => (int)$r['totaltime'],
        'last_logout'            => $r['logout_time'] > 0 ? (int)$r['logout_time'] : null,
    ]);
} catch (PDOException $e) {
    error_log('api_character: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'characters_db_unavailable']);
}
