<?php
/**
 * Leaderboards
 *
 * Multiple ranked categories of top players/guilds. Tab selection via ?type=…
 * Each tab is a server-rendered top-100 ranked list. Bots (account names
 * matching ^BOT\d+$) are excluded from player rankings.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ─── Config ──────────────────────────────────────────────────────────────────
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$alliance_races = [1, 3, 4, 7, 11, 22, 25];
$horde_races    = [2, 5, 6, 8, 9, 10, 26];

function lb_faction(int $r, array $a, array $h): string {
    if (in_array($r, $a, true)) return 'alliance';
    if (in_array($r, $h, true)) return 'horde';
    return 'neutral';
}

// ─── Tabs ────────────────────────────────────────────────────────────────────
$tabs = [
    'level'        => ['icon' => 'bi-bar-chart-fill',   'key' => 'lb_tab_level'],
    'playtime'     => ['icon' => 'bi-clock-history',    'key' => 'lb_tab_playtime'],
    'gold'         => ['icon' => 'bi-coin',             'key' => 'lb_tab_gold'],
    'pvp'          => ['icon' => 'bi-shield-fill',      'key' => 'lb_tab_pvp'],
    'achievements' => ['icon' => 'bi-trophy-fill',      'key' => 'lb_tab_achievements'],
    'guilds'       => ['icon' => 'bi-people-fill',      'key' => 'lb_tab_guilds'],
];

$type = isset($_GET['type']) ? (string)$_GET['type'] : 'level';
if (!isset($tabs[$type])) $type = 'level';

$f_faction = $_GET['faction'] ?? '';
if (!in_array($f_faction, ['alliance', 'horde'], true)) $f_faction = '';

$page_title = ($TEXT['leaderboards'] ?? 'Leaderboards')
    . ' — ' . ($TEXT[$tabs[$type]['key']] ?? ucfirst($type));

// OG tags
$og_title       = ($TEXT['leaderboards'] ?? 'Leaderboards') . ' — ' . ($config['realm']['name'] ?? 'WoW');
$og_description = $TEXT['lb_subtitle'] ?? 'See who is dominating the realm — top players by level, playtime, gold, PvP and more.';
$og_type        = 'website';

// ─── Data fetch ──────────────────────────────────────────────────────────────
$rows  = [];
$error = null;

if ($pdo_chars) {
    $auth_db = $config['db']['name_auth'];

    // Faction filter SQL fragment
    $faction_sql = '';
    if ($f_faction === 'alliance') $faction_sql = ' AND c.race IN (' . implode(',', $alliance_races) . ')';
    if ($f_faction === 'horde')    $faction_sql = ' AND c.race IN (' . implode(',', $horde_races)    . ')';

    try {
        switch ($type) {
            case 'playtime':
                $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level, c.totaltime AS metric
                        FROM characters c
                        JOIN `{$auth_db}`.account a ON a.id = c.account
                        WHERE a.username NOT LIKE 'BOT%'
                              AND c.totaltime > 0
                              {$faction_sql}
                        ORDER BY c.totaltime DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;

            case 'gold':
                $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level, c.money AS metric
                        FROM characters c
                        JOIN `{$auth_db}`.account a ON a.id = c.account
                        WHERE a.username NOT LIKE 'BOT%'
                              AND c.money > 0
                              {$faction_sql}
                        ORDER BY c.money DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;

            case 'pvp':
                $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level, c.totalKills AS metric
                        FROM characters c
                        JOIN `{$auth_db}`.account a ON a.id = c.account
                        WHERE a.username NOT LIKE 'BOT%'
                              AND c.totalKills > 0
                              {$faction_sql}
                        ORDER BY c.totalKills DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;

            case 'achievements':
                $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level,
                               COUNT(ca.achievement) AS metric
                        FROM characters c
                        JOIN `{$auth_db}`.account a ON a.id = c.account
                        LEFT JOIN character_achievement ca ON ca.guid = c.guid
                        WHERE a.username NOT LIKE 'BOT%'
                              {$faction_sql}
                        GROUP BY c.guid, c.name, c.race, c.class, c.gender, c.level
                        HAVING metric > 0
                        ORDER BY metric DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;

            case 'guilds':
                // Guild ranking: by level desc, then experience desc, then member count
                // Faction is derived from the leader's race (or first member's if leader missing).
                $faction_join_filter = '';
                if ($f_faction === 'alliance') {
                    $faction_join_filter = ' AND lc.race IN (' . implode(',', $alliance_races) . ')';
                }
                if ($f_faction === 'horde') {
                    $faction_join_filter = ' AND lc.race IN (' . implode(',', $horde_races) . ')';
                }
                $sql = "SELECT g.guildid, g.name, g.level, g.experience, g.BankMoney,
                               (SELECT COUNT(*) FROM guild_member gm WHERE gm.guildid = g.guildid) AS members,
                               lc.race AS leader_race, lc.name AS leader_name
                        FROM guild g
                        LEFT JOIN characters lc ON lc.guid = g.leaderguid
                        WHERE 1=1 {$faction_join_filter}
                        ORDER BY g.level DESC, g.experience DESC, members DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;

            case 'level':
            default:
                $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level, c.totaltime AS metric
                        FROM characters c
                        JOIN `{$auth_db}`.account a ON a.id = c.account
                        WHERE a.username NOT LIKE 'BOT%'
                              {$faction_sql}
                        ORDER BY c.level DESC, c.totaltime DESC
                        LIMIT 100";
                $rows = $pdo_chars->query($sql)->fetchAll();
                break;
        }
    } catch (PDOException $e) {
        error_log('Leaderboard query failed (' . $type . '): ' . $e->getMessage());
        $error = $TEXT['error_db'] ?? 'Database error.';
    }
} else {
    $error = $TEXT['error_db_chars_conn'] ?? 'Character database unavailable.';
}

require_once __DIR__ . '/../templates/header.php';

// ─── Helpers for rendering ───────────────────────────────────────────────────
function lb_metric_label(string $type, array $TEXT): string {
    $map = [
        'level'        => $TEXT['lb_col_playtime']     ?? 'Playtime',
        'playtime'     => $TEXT['lb_col_playtime']     ?? 'Playtime',
        'gold'         => $TEXT['lb_col_gold']         ?? 'Gold',
        'pvp'          => $TEXT['lb_col_kills']        ?? 'Honorable Kills',
        'achievements' => $TEXT['lb_col_achievements'] ?? 'Achievements',
    ];
    return $map[$type] ?? '';
}

function lb_format_metric(string $type, $value): string {
    $v = (int)$value;
    return match ($type) {
        'playtime', 'level' => format_playtime($v),
        'gold'              => format_gold($v),
        default             => number_format($v),
    };
}

function lb_rank_badge(int $rank): string {
    if ($rank === 1) return '<span class="rank rank-gold">🥇</span>';
    if ($rank === 2) return '<span class="rank rank-silver">🥈</span>';
    if ($rank === 3) return '<span class="rank rank-bronze">🥉</span>';
    return '<span class="rank">' . $rank . '</span>';
}
?>

<style>
.lb-wrap { padding-top: 90px; padding-bottom: 3rem; }

.lb-banner {
    position: relative;
    border-radius: 18px;
    padding: 2.4rem 1.8rem 2rem;
    margin-bottom: 1.4rem;
    background:
        linear-gradient(135deg, rgba(139,69,19,.4), rgba(10,10,20,.92) 65%),
        url('/assets/img/wow-bg/4-3.webp') center/cover no-repeat;
    border: 1px solid rgba(200,169,110,.35);
    overflow: hidden;
}
.lb-banner h1 {
    font-size: clamp(1.8rem, 3.5vw, 2.6rem);
    font-weight: 800;
    background: linear-gradient(90deg,#c8a96e,#fff 60%,#c8a96e);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
    margin: 0 0 .35rem;
}
.lb-banner p { color: rgba(255,255,255,.75); margin: 0 0 .5rem; }

/* Tabs */
.lb-tabs {
    display: flex; gap: .4rem; flex-wrap: wrap;
    margin-bottom: 1.2rem;
    padding: .35rem;
    background: linear-gradient(145deg,#12121f,#1a1a2e);
    border: 1px solid rgba(139,69,19,.25);
    border-radius: 14px;
}
.lb-tab {
    flex: 1 1 auto;
    text-align: center;
    padding: .7rem 1rem;
    border-radius: 10px;
    color: #8899aa;
    text-decoration: none;
    font-size: .88rem;
    font-weight: 600;
    letter-spacing: .3px;
    transition: all .2s ease;
    border: 1px solid transparent;
    white-space: nowrap;
    min-width: 120px;
}
.lb-tab:hover { color: #c8a96e; background: rgba(200,169,110,.08); }
.lb-tab.active {
    background: linear-gradient(135deg, rgba(139,69,19,.4), rgba(139,69,19,.18));
    border-color: rgba(200,169,110,.45);
    color: #c8a96e;
}
.lb-tab i { font-size: 1rem; }

/* Faction filter pills */
.lb-faction-bar { display: flex; gap: .35rem; margin-bottom: 1rem; flex-wrap: wrap; }
.lb-faction-pill {
    padding: .35rem 1rem; border-radius: 50px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(200,169,110,.2);
    color: #8899aa;
    text-decoration: none;
    font-size: .82rem; font-weight: 600;
    transition: all .2s ease;
}
.lb-faction-pill:hover { background: rgba(200,169,110,.1); color: #c8a96e; }
.lb-faction-pill.active {
    background: linear-gradient(135deg,#8B4513,#A0522D);
    border-color: #A0522D;
    color: #fff;
}
.lb-faction-pill.alliance.active { background: linear-gradient(135deg,#0070DE,#1d4ed8); border-color: #1d4ed8; }
.lb-faction-pill.horde.active    { background: linear-gradient(135deg,#C41F3B,#7f1d1d); border-color: #7f1d1d; }

/* Table */
.lb-table-wrap {
    background: linear-gradient(145deg,#12121f,#1a1a2e);
    border: 1px solid rgba(139,69,19,.3);
    border-radius: 14px;
    overflow: hidden;
}
.lb-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.lb-table thead th {
    background: rgba(139,69,19,.12);
    color: #c8a96e;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-size: .7rem;
    font-weight: 700;
    padding: .9rem 1rem;
    border-bottom: 1px solid rgba(200,169,110,.2);
    text-align: left;
}
.lb-table thead th.right { text-align: right; }
.lb-table tbody td {
    padding: .7rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
    vertical-align: middle;
}
.lb-table tbody tr:last-child td { border-bottom: none; }
.lb-table tbody tr:hover td { background: rgba(255,255,255,.025); }
.lb-table tbody tr.top-1 td { background: linear-gradient(90deg, rgba(255,215,0,.08), transparent 60%); }
.lb-table tbody tr.top-2 td { background: linear-gradient(90deg, rgba(192,192,192,.06), transparent 60%); }
.lb-table tbody tr.top-3 td { background: linear-gradient(90deg, rgba(205,127,50,.06), transparent 60%); }

.rank {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: 8px;
    font-weight: 700;
    font-size: .9rem;
    color: #6c7a8c;
    background: rgba(255,255,255,.04);
}
.rank-gold   { background: linear-gradient(135deg, rgba(255,215,0,.25), rgba(255,215,0,.08)); border: 1px solid rgba(255,215,0,.35); font-size: 1.4rem; }
.rank-silver { background: linear-gradient(135deg, rgba(192,192,192,.25), rgba(192,192,192,.08)); border: 1px solid rgba(192,192,192,.35); font-size: 1.4rem; }
.rank-bronze { background: linear-gradient(135deg, rgba(205,127,50,.25), rgba(205,127,50,.08)); border: 1px solid rgba(205,127,50,.35); font-size: 1.4rem; }

.char-cell { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.char-cell img { width: 28px; height: 28px; border-radius: 5px; border: 1px solid rgba(255,255,255,.15); flex-shrink: 0; }
.char-cell .nm { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.char-cell .nm a { color: inherit; text-decoration: none; }
.char-cell .nm a:hover { text-decoration: underline; }
.char-cell .meta { font-size: .72rem; color: #6c7a8c; }

.lvl-pill {
    display: inline-block;
    background: rgba(139,69,19,.35);
    color: #c8a96e;
    font-weight: 700;
    font-size: .78rem;
    padding: .25rem .6rem;
    border-radius: 6px;
}
.metric-val {
    font-weight: 700;
    color: #e8c87e;
    font-variant-numeric: tabular-nums;
    text-align: right;
}
.faction-mini {
    display: inline-flex; align-items: center; gap: .3rem;
    font-size: .75rem; font-weight: 600;
}
.faction-mini.alliance { color: #69ccf0; }
.faction-mini.horde    { color: #f87e8a; }
.faction-mini.neutral  { color: #c8a96e; }

.lb-empty {
    text-align: center;
    padding: 3rem 2rem;
    color: #8899aa;
}
.lb-empty i { font-size: 3rem; opacity: .25; display: block; margin-bottom: .8rem; }

/* Guild rows */
.guild-cell {
    font-weight: 700;
    color: #c8a96e;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.guild-cell .gleader {
    display: block; font-size: .72rem; color: #6c7a8c; font-weight: 400;
}

@media (max-width: 768px) {
    .lb-tab { font-size: .78rem; padding: .55rem .6rem; min-width: auto; }
    .lb-tab span { display: none; }
    .lb-tab i { font-size: 1.1rem; }
    .lb-table thead th, .lb-table tbody td { padding: .55rem .5rem; }
    .char-cell .meta { display: none; }
}
</style>

<div class="container lb-wrap">

    <!-- BANNER -->
    <div class="lb-banner">
        <h1><i class="bi bi-trophy-fill me-2"></i><?= htmlspecialchars($TEXT['leaderboards'] ?? 'Leaderboards') ?></h1>
        <p><?= htmlspecialchars($TEXT['lb_subtitle'] ?? 'See who is dominating the realm — top players by level, playtime, gold, PvP and more.') ?></p>
    </div>

    <!-- TABS -->
    <div class="lb-tabs">
        <?php foreach ($tabs as $tkey => $tab):
            $href = '/leaderboards?type=' . $tkey . ($f_faction ? '&faction=' . $f_faction : '');
        ?>
            <a class="lb-tab <?= $tkey === $type ? 'active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
                <i class="bi <?= $tab['icon'] ?>"></i> <span><?= htmlspecialchars($TEXT[$tab['key']] ?? ucfirst($tkey)) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- FACTION FILTER (hidden for some tabs that don't make sense) -->
    <div class="lb-faction-bar">
        <?php
        $base = '/leaderboards?type=' . $type;
        ?>
        <a class="lb-faction-pill <?= $f_faction === ''         ? 'active' : '' ?>" href="<?= htmlspecialchars($base) ?>">
            <i class="bi bi-globe me-1"></i><?= htmlspecialchars($TEXT['common_all'] ?? 'All') ?>
        </a>
        <a class="lb-faction-pill alliance <?= $f_faction === 'alliance' ? 'active' : '' ?>" href="<?= htmlspecialchars($base . '&faction=alliance') ?>">
            <i class="bi bi-shield-shaded me-1"></i><?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
        </a>
        <a class="lb-faction-pill horde <?= $f_faction === 'horde' ? 'active' : '' ?>" href="<?= htmlspecialchars($base . '&faction=horde') ?>">
            <i class="bi bi-fire me-1"></i><?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
        </a>
    </div>

    <!-- TABLE -->
    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius:10px"><?= htmlspecialchars($error) ?></div>
    <?php elseif (empty($rows)): ?>
        <div class="lb-table-wrap">
            <div class="lb-empty">
                <i class="bi bi-bar-chart"></i>
                <h3 style="color:#c8a96e"><?= htmlspecialchars($TEXT['lb_empty_title'] ?? 'No data yet') ?></h3>
                <p><?= htmlspecialchars($TEXT['lb_empty_hint'] ?? 'Once players start their journey, the leaderboards fill up here.') ?></p>
            </div>
        </div>
    <?php elseif ($type === 'guilds'): ?>
        <div class="lb-table-wrap">
            <div style="overflow-x:auto">
            <table class="lb-table">
                <thead>
                    <tr>
                        <th style="width:80px">#</th>
                        <th><?= htmlspecialchars($TEXT['lb_col_guild']    ?? 'Guild') ?></th>
                        <th style="width:120px"><?= htmlspecialchars($TEXT['armory_info_faction']    ?? 'Faction') ?></th>
                        <th class="right" style="width:90px"><?= htmlspecialchars($TEXT['lb_col_level']    ?? 'Level') ?></th>
                        <th class="right" style="width:120px"><?= htmlspecialchars($TEXT['lb_col_members'] ?? 'Members') ?></th>
                        <th class="right" style="width:160px"><?= htmlspecialchars($TEXT['lb_col_bank']    ?? 'Bank') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $g):
                    $rank = $i + 1;
                    $faction = lb_faction((int)($g['leader_race'] ?? 0), $alliance_races, $horde_races);
                ?>
                    <tr class="<?= $rank <= 3 ? 'top-' . $rank : '' ?>">
                        <td><?= lb_rank_badge($rank) ?></td>
                        <td>
                            <div class="guild-cell">
                                &lt;<?= htmlspecialchars($g['name']) ?>&gt;
                                <?php if (!empty($g['leader_name'])): ?>
                                    <span class="gleader"><?= htmlspecialchars($TEXT['lb_leader'] ?? 'Leader') ?>: <?= htmlspecialchars($g['leader_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="faction-mini <?= $faction ?>">
                                <?php if ($faction === 'alliance'): ?>
                                    <i class="bi bi-shield-shaded"></i> <?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
                                <?php elseif ($faction === 'horde'): ?>
                                    <i class="bi bi-fire"></i> <?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
                                <?php else: ?>
                                    <i class="bi bi-yin-yang"></i> <?= htmlspecialchars($TEXT['armory_label_neutral'] ?? 'Neutral') ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="right"><span class="lvl-pill"><?= (int)$g['level'] ?></span></td>
                        <td class="right metric-val"><?= number_format((int)$g['members']) ?></td>
                        <td class="right metric-val"><?= htmlspecialchars(format_gold((int)$g['BankMoney'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php else: ?>
        <div class="lb-table-wrap">
            <div style="overflow-x:auto">
            <table class="lb-table">
                <thead>
                    <tr>
                        <th style="width:80px">#</th>
                        <th><?= htmlspecialchars($TEXT['lb_col_character']  ?? 'Character') ?></th>
                        <th style="width:80px"><?= htmlspecialchars($TEXT['lb_col_level']  ?? 'Level') ?></th>
                        <th class="right" style="width:180px"><?= htmlspecialchars(lb_metric_label($type, $TEXT)) ?></th>
                        <th style="width:120px"><?= htmlspecialchars($TEXT['armory_info_faction'] ?? 'Faction') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $rank = $i + 1;
                    $cid = (int)$row['class'];
                    $rid = (int)$row['race'];
                    $clr = $class_colors[$cid] ?? '#c8a96e';
                    $faction = lb_faction($rid, $alliance_races, $horde_races);
                ?>
                    <tr class="<?= $rank <= 3 ? 'top-' . $rank : '' ?>">
                        <td><?= lb_rank_badge($rank) ?></td>
                        <td>
                            <div class="char-cell">
                                <img src="<?= '/' . get_race_icon_path($rid, (int)$row['gender']) ?>" alt="">
                                <img src="<?= '/' . get_class_icon_path($cid) ?>" alt="">
                                <div style="min-width:0">
                                    <div class="nm" style="color:<?= $clr ?>">
                                        <a href="/armory/<?= rawurlencode($row['name']) ?>"><?= htmlspecialchars($row['name']) ?></a>
                                    </div>
                                    <div class="meta">
                                        <?= htmlspecialchars(get_race_name($rid)) ?> &middot; <?= htmlspecialchars(get_class_name($cid)) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td><span class="lvl-pill">Lv <?= (int)$row['level'] ?></span></td>
                        <td class="metric-val"><?= htmlspecialchars(lb_format_metric($type, $row['metric'])) ?></td>
                        <td>
                            <span class="faction-mini <?= $faction ?>">
                                <?php if ($faction === 'alliance'): ?>
                                    <i class="bi bi-shield-shaded"></i> <?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
                                <?php elseif ($faction === 'horde'): ?>
                                    <i class="bi bi-fire"></i> <?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
                                <?php else: ?>
                                    <i class="bi bi-yin-yang"></i> <?= htmlspecialchars($TEXT['armory_label_neutral'] ?? 'Neutral') ?>
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if (count($rows) >= 100): ?>
        <div style="text-align:center;margin-top:1rem;font-size:.78rem;color:#6c7a8c">
            <?= htmlspecialchars($TEXT['lb_top100_note'] ?? 'Showing top 100 only.') ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
