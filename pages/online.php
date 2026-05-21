<?php
/**
 * Who's Online — live roster of characters currently logged in.
 *
 * Read-only: SELECTs `characters WHERE online = 1` from the characters DB
 * (+ a left join to the guild names). Same defensive pattern as Armory:
 * graceful when the characters DB is absent. Faction filter via ?faction=.
 * Mobile-first responsive (the table collapses into cards on phones).
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';   // get_race_icon_path, get_race_name, get_class_icon_path, get_class_name
require_once __DIR__ . '/../includes/site_settings.php';

// WoW class colours (same map used by Armory / Leaderboards).
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4  => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8  => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$alliance_races = [1, 3, 4, 7, 11, 22, 25]; // Human, Dwarf, NE, Gnome, Draenei, Worgen, Pandaren-A
$horde_races    = [2, 5, 6, 8, 9,  10, 26]; // Orc, UD, Tauren, Troll, Goblin, BE, Pandaren-H

$f_faction = $_GET['faction'] ?? 'all';
if (!in_array($f_faction, ['all', 'alliance', 'horde'], true)) $f_faction = 'all';

$rows = [];
$db_err = false;
if ($pdo_chars) {
    try {
        $where = "WHERE c.online = 1";
        if ($f_faction === 'alliance') {
            $where .= " AND c.race IN (" . implode(',', $alliance_races) . ")";
        } elseif ($f_faction === 'horde') {
            $where .= " AND c.race IN (" . implode(',', $horde_races) . ")";
        }
        $stmt = $pdo_chars->prepare(
            "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level,
                    g.name AS guild_name
             FROM characters c
             LEFT JOIN guild_member gm ON gm.guid = c.guid
             LEFT JOIN guild g         ON g.guildid = gm.guildid
             $where
             ORDER BY c.level DESC, c.name ASC
             LIMIT 500"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Who\'s Online query failed: ' . $e->getMessage());
        $db_err = true;
    }
} else {
    $db_err = true;
}

$total = count($rows);
$capped = $total >= 500;
function ol_faction(int $r, array $a, array $h): string {
    if (in_array($r, $a, true)) return 'alliance';
    if (in_array($r, $h, true)) return 'horde';
    return 'neutral';
}

// Tiny per-faction counts for the filter chips (cheap re-tally from the
// already-fetched 'all' set — only if no faction filter is active).
$count_alli = 0; $count_horde = 0;
if ($f_faction === 'all') {
    foreach ($rows as $r) {
        $fac = ol_faction((int)$r['race'], $alliance_races, $horde_races);
        if ($fac === 'alliance') $count_alli++;
        elseif ($fac === 'horde') $count_horde++;
    }
}

$page_title = ($TEXT['online_title'] ?? "Who's Online") . ' — ' . settings_site_title($pdo_auth ?? null, $config);
$og_title       = ($TEXT['online_og_title'] ?? "Who's Online") . ' · ' . settings_get($pdo_auth ?? null, $config)['realm_name'];
$og_description = $TEXT['online_subtitle'] ?? 'See who is logged in right now on the realm.';

require_once __DIR__ . '/../templates/header.php';
?>

<style>
.ol-wrap { padding-top: 90px; padding-bottom: 3rem; }

.ol-hero {
    border-radius: 16px;
    padding: 1.6rem 1.6rem 1.4rem;
    margin-bottom: 1.4rem;
    background: linear-gradient(135deg, rgba(var(--accent-rgb),.12), rgba(10,10,20,.85));
    border: 1px solid rgba(var(--accent-rgb), .3);
    display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;
}
.ol-title { font-size: 1.7rem; font-weight: 800; color: var(--accent); margin: 0; }
.ol-sub   { color:#9aa7b4; font-size:.9rem; margin-top:.25rem; }
.ol-livecount {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .35rem .75rem; border-radius: 50px;
    background: rgba(40,167,69,.15); color:#5dd87c; border:1px solid rgba(40,167,69,.4);
    font-weight: 700; letter-spacing: .5px;
}
.ol-livecount .dot { width:8px; height:8px; border-radius:50%; background:#5dd87c; box-shadow:0 0 8px #5dd87c; }

.ol-filters { display:flex; gap:.45rem; flex-wrap:wrap; margin-left:auto; }
.ol-chip {
    padding:.4rem .9rem; border-radius:50px; font-size:.8rem; font-weight:600;
    text-decoration:none; border:1px solid rgba(255,255,255,.12);
    color:#cdd5e0; background:rgba(255,255,255,.04);
    transition: all .15s ease;
}
.ol-chip:hover { border-color: rgba(var(--accent-rgb),.4); color:#fff; }
.ol-chip.is-on { background: rgba(var(--accent-rgb),.18); border-color: rgba(var(--accent-rgb),.5); color: var(--accent); }
.ol-chip.is-alli.is-on { background:rgba(0,112,222,.18); border-color:rgba(0,112,222,.5); color:#69ccf0; }
.ol-chip.is-horde.is-on { background:rgba(196,31,59,.18); border-color:rgba(196,31,59,.5); color:#f87e8a; }

.ol-table {
    width:100%; border-collapse: separate; border-spacing: 0 .35rem;
}
.ol-table thead th {
    text-align:left; font-size:.72rem; color:#6c7a8c; letter-spacing:1px;
    text-transform:uppercase; padding:.3rem .8rem; font-weight:600;
}
.ol-row td {
    background: rgba(255,255,255,.025); border-top:1px solid rgba(255,255,255,.05);
    border-bottom:1px solid rgba(255,255,255,.05);
    padding:.65rem .8rem; vertical-align:middle;
}
.ol-row td:first-child { border-left:1px solid rgba(255,255,255,.05); border-top-left-radius:8px; border-bottom-left-radius:8px; }
.ol-row td:last-child  { border-right:1px solid rgba(255,255,255,.05); border-top-right-radius:8px; border-bottom-right-radius:8px; }
.ol-row { cursor: pointer; transition: all .15s ease; }
.ol-row:hover td { background: rgba(var(--accent-rgb),.07); }
.ol-row:hover td:first-child { border-left-color: var(--accent); }

.ol-char { display:flex; align-items:center; gap:.65rem; min-width:0; }
.ol-char img {
    width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
    background:#0a0a0f; border:1px solid rgba(var(--accent-rgb),.3);
    object-fit: cover; object-position: center 18%;
}
.ol-name { font-weight:700; font-size:.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ol-meta { color:#8899aa; font-size:.78rem; }
.ol-lvl  { color:#dee2e6; font-weight:700; font-variant-numeric: tabular-nums; }
.ol-guild { color: rgba(var(--accent-rgb), .85); font-size:.85rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 240px; display:inline-block; }
.ol-faction {
    display: inline-flex; align-items: center; gap: .35rem;
    padding:.18rem .55rem; border-radius:50px;
    font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px;
}
.ol-faction.alliance { background:rgba(0,112,222,.15); color:#69ccf0; border:1px solid rgba(0,112,222,.4); }
.ol-faction.horde    { background:rgba(196,31,59,.15); color:#f87e8a; border:1px solid rgba(196,31,59,.4); }
.ol-faction.neutral  { background:rgba(var(--accent-rgb),.12); color:var(--accent); border:1px solid rgba(var(--accent-rgb),.4); }

.ol-empty, .ol-error {
    text-align:center; padding: 3rem 1rem; border-radius: 12px;
    background: rgba(255,255,255,.025); border:1px solid rgba(255,255,255,.05);
    color:#8899aa;
}
.ol-empty .icn, .ol-error .icn { font-size:2.2rem; color:#4a5568; margin-bottom:.6rem; }

.ol-foot { margin-top:1rem; color:#6c7a8c; font-size:.78rem; text-align:center; }

/* ── Mobile cards (collapse table) ──────────────────────────────── */
@media (max-width: 768px) {
    .ol-hero { flex-direction: column; align-items: flex-start; }
    .ol-filters { margin-left: 0; }
    .ol-table thead { display: none; }
    .ol-table, .ol-table tbody, .ol-row, .ol-row td { display: block; width: 100%; }
    .ol-row { background: rgba(255,255,255,.025); border:1px solid rgba(255,255,255,.06);
              border-radius: 10px; margin-bottom: .55rem; padding: .65rem .75rem; }
    .ol-row td { background: transparent !important; border: none !important; padding: .2rem 0; }
    .ol-row td:first-child { padding-bottom: .35rem; }
    .ol-row td:nth-child(2)::before { content: 'Lv '; color:#6c7a8c; }
    .ol-row td:nth-child(3)::before { content: ''; }
    .ol-row td.ol-cell-row { display:flex; justify-content:space-between; gap:.5rem; align-items:center; font-size:.82rem; }
    .ol-guild { max-width: 60vw; }
}
</style>

<div class="container ol-wrap">

    <header class="ol-hero">
        <div>
            <h1 class="ol-title"><i class="bi bi-broadcast me-2"></i><?= htmlspecialchars($TEXT['online_title'] ?? "Who's Online") ?></h1>
            <div class="ol-sub"><?= htmlspecialchars($TEXT['online_subtitle'] ?? 'Characters logged in right now — click any row to view the Armory profile.') ?></div>
        </div>
        <?php if (!$db_err): ?>
            <span class="ol-livecount"><span class="dot"></span>
                <?= sprintf(htmlspecialchars($TEXT['online_count'] ?? '%d online'), $total) ?>
            </span>
        <?php endif; ?>
        <nav class="ol-filters" aria-label="<?= htmlspecialchars($TEXT['online_filter'] ?? 'Faction filter') ?>">
            <a href="/online" class="ol-chip <?= $f_faction === 'all' ? 'is-on' : '' ?>"><?= htmlspecialchars($TEXT['online_all'] ?? 'All') ?>
                <?php if ($f_faction === 'all' && !$db_err): ?>· <?= (int)$total ?><?php endif; ?>
            </a>
            <a href="/online?faction=alliance" class="ol-chip is-alli <?= $f_faction === 'alliance' ? 'is-on' : '' ?>">
                <i class="bi bi-shield-shaded"></i> <?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
                <?php if ($f_faction === 'all' && !$db_err): ?>· <?= (int)$count_alli ?><?php endif; ?>
            </a>
            <a href="/online?faction=horde" class="ol-chip is-horde <?= $f_faction === 'horde' ? 'is-on' : '' ?>">
                <i class="bi bi-fire"></i> <?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
                <?php if ($f_faction === 'all' && !$db_err): ?>· <?= (int)$count_horde ?><?php endif; ?>
            </a>
        </nav>
    </header>

    <?php if ($db_err): ?>
        <div class="ol-error">
            <div class="icn"><i class="bi bi-exclamation-octagon"></i></div>
            <div><?= htmlspecialchars($TEXT['online_error'] ?? 'Online list is unavailable right now.') ?></div>
        </div>
    <?php elseif ($total === 0): ?>
        <div class="ol-empty">
            <div class="icn"><i class="bi bi-moon-stars"></i></div>
            <div><?= htmlspecialchars($TEXT['online_empty'] ?? 'No characters are online right now.') ?></div>
        </div>
    <?php else: ?>
        <table class="ol-table" role="grid">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($TEXT['online_col_char'] ?? 'Character') ?></th>
                    <th><?= htmlspecialchars($TEXT['online_col_lvl'] ?? 'Lv') ?></th>
                    <th><?= htmlspecialchars($TEXT['online_col_class'] ?? 'Race · Class') ?></th>
                    <th><?= htmlspecialchars($TEXT['online_col_guild'] ?? 'Guild') ?></th>
                    <th><?= htmlspecialchars($TEXT['online_col_faction'] ?? 'Faction') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $rid = (int)$r['race']; $gid = (int)$r['gender']; $cid = (int)$r['class'];
                    $clr = $class_colors[$cid] ?? 'var(--accent)';
                    $fac = ol_faction($rid, $alliance_races, $horde_races);
                    $url = '/armory/' . rawurlencode($r['name']);
                ?>
                <tr class="ol-row" onclick="window.location.href='<?= htmlspecialchars($url, ENT_QUOTES) ?>'">
                    <td>
                        <a class="ol-char" href="<?= htmlspecialchars($url) ?>" style="color:<?= $clr ?>;text-decoration:none" onclick="event.stopPropagation()">
                            <img src="<?= '/' . get_race_icon_path($rid, $gid) ?>" alt="<?= htmlspecialchars(get_race_name($rid)) ?>" loading="lazy">
                            <span class="ol-name"><?= htmlspecialchars($r['name']) ?></span>
                        </a>
                    </td>
                    <td class="ol-cell-row"><span class="ol-lvl"><?= (int)$r['level'] ?></span></td>
                    <td class="ol-cell-row ol-meta"><?= htmlspecialchars(get_race_name($rid)) ?> · <?= htmlspecialchars(get_class_name($cid)) ?></td>
                    <td class="ol-cell-row">
                        <?php if (!empty($r['guild_name'])): ?>
                            <a href="/guild/<?= rawurlencode($r['guild_name']) ?>" class="ol-guild" style="text-decoration:none" onclick="event.stopPropagation()">&lt;<?= htmlspecialchars($r['guild_name']) ?>&gt;</a>
                        <?php else: ?>
                            <span style="color:#4a5568;font-size:.8rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="ol-cell-row">
                        <span class="ol-faction <?= $fac ?>">
                            <?php if ($fac === 'alliance'): ?><i class="bi bi-shield-shaded"></i><?php elseif ($fac === 'horde'): ?><i class="bi bi-fire"></i><?php else: ?><i class="bi bi-yin-yang"></i><?php endif; ?>
                            <?= htmlspecialchars($fac === 'alliance' ? ($TEXT['armory_label_alliance'] ?? 'Alliance') : ($fac === 'horde' ? ($TEXT['armory_label_horde'] ?? 'Horde') : ($TEXT['armory_label_neutral'] ?? 'Neutral'))) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($capped): ?>
            <div class="ol-foot"><?= htmlspecialchars($TEXT['online_capped'] ?? 'Showing the first 500 online characters.') ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
