<?php
/**
 * Guild profile — /guild/<name>
 *
 * Read-only. Same defensive pattern as Armory: try the rich query first
 * (info / motd / createdate), fall back to the core columns the
 * leaderboards already proves exist on this repack (guildid, name,
 * level, experience, BankMoney, leaderguid). Members table → mobile
 * cards under 768px. Every optional bit (rank-name table, motd, etc.)
 * hides gracefully when its column/table is missing.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';   // get_race_icon_path, get_race_name, get_class_name, format_gold
require_once __DIR__ . '/../includes/site_settings.php';

// WoW class colours (same map used by Armory / Leaderboards / Online).
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4  => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8  => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$alliance_races = [1, 3, 4, 7, 11, 22, 25];
$horde_races    = [2, 5, 6, 8, 9,  10, 26];

function gld_faction(int $r, array $a, array $h): string {
    if (in_array($r, $a, true)) return 'alliance';
    if (in_array($r, $h, true)) return 'horde';
    return 'neutral';
}

$guild_name_in = isset($_GET['name']) ? trim(rawurldecode((string)$_GET['name'])) : '';
$guild = null;
$members = [];
$ranks   = [];      // rid => name (filled if guild_rank table present)
$db_err  = false;

if ($guild_name_in === '' || !$pdo_chars) {
    $db_err = !$pdo_chars;
} else {
    try {
        // Try the rich query first (with info/motd/createdate).
        $stmt = $pdo_chars->prepare(
            "SELECT g.guildid, g.name, g.level, g.experience, g.BankMoney,
                    g.leaderguid, g.info, g.motd, g.createdate,
                    lc.name AS leader_name, lc.race AS leader_race,
                    lc.class AS leader_class, lc.gender AS leader_gender,
                    lc.online AS leader_online
             FROM guild g
             LEFT JOIN characters lc ON lc.guid = g.leaderguid
             WHERE g.name = :n
             LIMIT 1"
        );
        $stmt->execute(['n' => $guild_name_in]);
        $guild = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        // Repack without info/motd/createdate columns — retry with core only.
        error_log('Guild rich query failed, falling back: ' . $e->getMessage());
        try {
            $stmt = $pdo_chars->prepare(
                "SELECT g.guildid, g.name, g.level, g.experience, g.BankMoney,
                        g.leaderguid,
                        lc.name AS leader_name, lc.race AS leader_race,
                        lc.class AS leader_class, lc.gender AS leader_gender,
                        lc.online AS leader_online
                 FROM guild g
                 LEFT JOIN characters lc ON lc.guid = g.leaderguid
                 WHERE g.name = :n
                 LIMIT 1"
            );
            $stmt->execute(['n' => $guild_name_in]);
            $guild = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e2) {
            error_log('Guild fallback query failed: ' . $e2->getMessage());
            $db_err = true;
        }
    }

    if ($guild) {
        // Members.
        try {
            $stmt = $pdo_chars->prepare(
                "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level,
                        c.online, c.logout_time, gm.rank
                 FROM guild_member gm
                 JOIN characters c ON c.guid = gm.guid
                 WHERE gm.guildid = :gid
                 ORDER BY gm.rank ASC, c.level DESC, c.name ASC
                 LIMIT 1000"
            );
            $stmt->execute(['gid' => (int)$guild['guildid']]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { error_log('Guild members query failed: ' . $e->getMessage()); }

        // Rank names — try guild_rank; if the table/column shape differs we
        // just fall back to "Rank #N".
        try {
            $stmt = $pdo_chars->prepare("SELECT rid, rname FROM guild_rank WHERE guildid = :gid");
            $stmt->execute(['gid' => (int)$guild['guildid']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ranks[(int)$r['rid']] = (string)$r['rname'];
            }
        } catch (PDOException $e) { /* table absent — show Rank #N */ }
    }
}

$total_members  = count($members);
$online_members = 0;
foreach ($members as $m) { if ((int)$m['online'] === 1) $online_members++; }

$leader_faction = $guild && $guild['leader_race'] !== null
    ? gld_faction((int)$guild['leader_race'], $alliance_races, $horde_races)
    : ($members ? gld_faction((int)$members[0]['race'], $alliance_races, $horde_races) : 'neutral');

$page_title = ($guild ? htmlspecialchars($guild['name']) . ' — ' : '')
            . ($TEXT['guild_title'] ?? 'Guild')
            . ' — ' . settings_site_title($pdo_auth ?? null, $config);
$og_title       = $guild ? '<' . $guild['name'] . '> · ' . ($TEXT['guild_og_kicker'] ?? 'Guild')
                         : ($TEXT['guild_not_found'] ?? 'Guild not found');
$og_description = $guild
    ? sprintf($TEXT['guild_og_desc'] ?? 'Level %d guild on %s — %d members. View roster, leader, and stats.',
        (int)$guild['level'],
        settings_get($pdo_auth ?? null, $config)['realm_name'],
        $total_members)
    : ($TEXT['guild_not_found_hint'] ?? 'No guild by that name exists on this realm.');

require_once __DIR__ . '/../templates/header.php';

$fac_rgb = $leader_faction === 'alliance' ? '0,112,222'
         : ($leader_faction === 'horde'   ? '196,31,59' : '139,69,19');
$bg_idx  = rand(1, 5);
?>

<style>
.gld-wrap { padding-top: 90px; padding-bottom: 3rem; }

.gld-hero {
    position: relative;
    border-radius: 18px;
    padding: 2.2rem 2rem 1.8rem;
    margin-bottom: 1.4rem;
    background:
        linear-gradient(135deg, rgba(<?= $fac_rgb ?>,.32) 0%, rgba(10,10,20,.92) 60%),
        url('/assets/img/wow-bg/4-<?= $bg_idx ?>.webp') center/cover no-repeat;
    border: 1px solid rgba(var(--accent-rgb), .35);
    overflow: hidden;
}
.gld-hero::after {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(to bottom, transparent 55%, rgba(10,10,20,.85));
}
.gld-hero-inner { position: relative; z-index: 1; display: flex; gap: 1.3rem; align-items: center; flex-wrap: wrap; }

.gld-crest {
    width: 100px; height: 100px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 50% 38%, rgba(<?= $fac_rgb ?>,.55), rgba(10,10,18,.96) 72%);
    border: 3px solid rgba(<?= $fac_rgb ?>,.85);
    box-shadow: 0 0 26px -4px rgba(<?= $fac_rgb ?>,.7), inset 0 0 14px rgba(0,0,0,.6);
    color: rgba(<?= $fac_rgb ?>,.95);
    font-size: 2.4rem;
}
.gld-name {
    font-size: clamp(1.8rem, 4.5vw, 3rem);
    font-weight: 800; letter-spacing: 1px;
    color: var(--accent);
    text-shadow: 0 0 22px rgba(var(--accent-rgb),.35), 0 2px 6px rgba(0,0,0,.6);
    line-height: 1.05; margin: 0;
}
.gld-sub { color: rgba(255,255,255,.78); font-size: 1.02rem; margin-top: .35rem; letter-spacing: .3px; }

.gld-meta { display: flex; gap: .8rem; flex-wrap: wrap; margin-top: .8rem; }
.gld-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .3rem .8rem; border-radius: 50px;
    font-size: .78rem; font-weight: 700; letter-spacing: .6px;
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); color: #cdd5e0;
}
.gld-pill .lbl { color:#8899aa; text-transform: uppercase; letter-spacing: 1px; font-size: .7rem; font-weight: 600; }
.gld-pill.faction-alliance { background: rgba(0,112,222,.18); color: #69ccf0; border-color: rgba(0,112,222,.45); }
.gld-pill.faction-horde    { background: rgba(196,31,59,.18); color: #f87e8a; border-color: rgba(196,31,59,.45); }
.gld-pill.faction-neutral  { background: rgba(var(--accent-rgb),.15); color: var(--accent); border-color: rgba(var(--accent-rgb),.4); }

.gld-motd {
    margin-top: 1.1rem; padding: .75rem 1rem; border-radius: 10px;
    background: rgba(0,0,0,.35); border-left: 3px solid var(--accent);
    color: rgba(255,255,255,.85); font-style: italic; font-size: .95rem;
}

/* ── Stat strip ───────────────────────────────────────────── */
.gld-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: .8rem; margin-bottom: 1.4rem; }
.gld-stat {
    background: rgba(255,255,255,.025); border: 1px solid rgba(var(--btn-bg-rgb),.3);
    border-radius: 10px; padding: .85rem 1rem;
}
.gld-stat .k { color: #8899aa; font-size: .7rem; text-transform: uppercase; letter-spacing: 1.5px; }
.gld-stat .v { color: #e8e8e8; font-size: 1.25rem; font-weight: 800; margin-top: .25rem; font-variant-numeric: tabular-nums; }
.gld-stat.accent .v { color: var(--accent); }
.gld-stat.green .v  { color: #5dd87c; }

/* ── Members panel ────────────────────────────────────────── */
.gld-panel {
    background: linear-gradient(145deg, rgba(15,15,25,.85), rgba(10,10,18,.85));
    border: 1px solid rgba(var(--accent-rgb), .25);
    border-radius: 14px; padding: 1.1rem 1.2rem 1.4rem;
}
.gld-panel-title {
    color: var(--accent); font-size: .78rem; text-transform: uppercase; letter-spacing: 1.5px;
    font-weight: 700; margin-bottom: .9rem; padding-bottom: .5rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), .2);
}

.gld-tbl { width: 100%; border-collapse: separate; border-spacing: 0 .35rem; }
.gld-tbl thead th { text-align: left; font-size: .7rem; color: #6c7a8c; letter-spacing: 1px; text-transform: uppercase; padding: .3rem .8rem; font-weight: 600; }
.gld-row td {
    background: rgba(255,255,255,.025); border-top: 1px solid rgba(255,255,255,.05);
    border-bottom: 1px solid rgba(255,255,255,.05);
    padding: .55rem .8rem; vertical-align: middle;
}
.gld-row td:first-child { border-left: 1px solid rgba(255,255,255,.05); border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
.gld-row td:last-child  { border-right: 1px solid rgba(255,255,255,.05); border-top-right-radius: 8px; border-bottom-right-radius: 8px; }
.gld-row { cursor: pointer; transition: all .15s ease; }
.gld-row:hover td { background: rgba(var(--accent-rgb),.06); }
.gld-row:hover td:first-child { border-left-color: var(--accent); }

.gld-char { display: flex; align-items: center; gap: .65rem; min-width: 0; }
.gld-char img {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: #0a0a0f; border: 1px solid rgba(var(--accent-rgb),.3);
    object-fit: cover; object-position: center 18%;
}
.gld-name-cell { font-weight: 700; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gld-meta-cell { color: #8899aa; font-size: .8rem; }
.gld-lvl { color: #dee2e6; font-weight: 700; font-variant-numeric: tabular-nums; }
.gld-rank { color: #cdd5e0; font-size: .85rem; }
.gld-rank.leader { color: var(--accent); font-weight: 700; }
.gld-status { display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 600; }
.gld-status .dot { width: 8px; height: 8px; border-radius: 50%; }
.gld-status.on  { color: #5dd87c; }
.gld-status.on .dot  { background: #5dd87c; box-shadow: 0 0 8px #5dd87c; }
.gld-status.off { color: #6c7a8c; }
.gld-status.off .dot { background: #6c7a8c; }

.gld-empty, .gld-notfound {
    text-align: center; padding: 3rem 1rem; border-radius: 12px;
    background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.05); color: #8899aa;
}
.gld-empty .icn, .gld-notfound .icn { font-size: 2.2rem; color: #4a5568; margin-bottom: .6rem; }

/* ── Mobile (cards) ──────────────────────────────────────── */
@media (max-width: 768px) {
    .gld-hero-inner { flex-direction: column; align-items: flex-start; }
    .gld-stats { grid-template-columns: repeat(2, 1fr); }
    .gld-tbl thead { display: none; }
    .gld-tbl, .gld-tbl tbody, .gld-row, .gld-row td { display: block; width: 100%; }
    .gld-row { background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.06);
               border-radius: 10px; margin-bottom: .55rem; padding: .65rem .75rem; }
    .gld-row td { background: transparent !important; border: none !important; padding: .2rem 0; }
    .gld-row td:first-child { padding-bottom: .35rem; }
    .gld-row td.gld-cell-row { display: flex; justify-content: space-between; gap: .5rem; align-items: center; font-size: .82rem; }
}
</style>

<div class="container gld-wrap">

<?php if ($db_err): ?>
    <div class="gld-notfound">
        <div class="icn"><i class="bi bi-exclamation-octagon"></i></div>
        <div><?= htmlspecialchars($TEXT['guild_error'] ?? 'Guild lookup is unavailable right now.') ?></div>
    </div>
<?php elseif (!$guild): ?>
    <div class="gld-notfound">
        <div class="icn"><i class="bi bi-people"></i></div>
        <h2 style="color:var(--accent);font-size:1.4rem;margin:0 0 .5rem"><?= htmlspecialchars($TEXT['guild_not_found'] ?? 'Guild not found') ?></h2>
        <p style="margin:.4rem 0 0;color:#8899aa"><?= sprintf(htmlspecialchars($TEXT['guild_not_found_hint'] ?? 'No guild named %s exists on this realm.'), '<strong>' . htmlspecialchars($guild_name_in) . '</strong>') ?></p>
        <a href="/leaderboards?type=guilds" class="btn btn-gold mt-3"><i class="bi bi-trophy-fill me-2"></i><?= htmlspecialchars($TEXT['guild_back_to_lb'] ?? 'Browse top guilds') ?></a>
    </div>
<?php else: ?>

    <!-- HERO -->
    <header class="gld-hero">
        <div class="gld-hero-inner">
            <div class="gld-crest">
                <?php if ($leader_faction === 'alliance'): ?><i class="bi bi-shield-shaded"></i>
                <?php elseif ($leader_faction === 'horde'): ?><i class="bi bi-fire"></i>
                <?php else: ?><i class="bi bi-yin-yang"></i><?php endif; ?>
            </div>
            <div style="min-width:0;flex:1">
                <h1 class="gld-name">&lt;<?= htmlspecialchars($guild['name']) ?>&gt;</h1>
                <div class="gld-sub">
                    <?= sprintf(htmlspecialchars($TEXT['guild_sub'] ?? 'Level %d guild on %s'),
                        (int)$guild['level'],
                        htmlspecialchars(settings_get($pdo_auth ?? null, $config)['realm_name'])) ?>
                </div>
                <div class="gld-meta">
                    <span class="gld-pill faction-<?= $leader_faction ?>">
                        <?php if ($leader_faction === 'alliance'): ?><i class="bi bi-shield-shaded"></i> <?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
                        <?php elseif ($leader_faction === 'horde'): ?><i class="bi bi-fire"></i> <?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
                        <?php else: ?><i class="bi bi-yin-yang"></i> <?= htmlspecialchars($TEXT['armory_label_neutral'] ?? 'Neutral') ?><?php endif; ?>
                    </span>
                    <?php if (!empty($guild['leader_name'])):
                        $lcid = (int)($guild['leader_class'] ?? 0);
                        $lclr = $class_colors[$lcid] ?? 'var(--accent)';
                    ?>
                    <span class="gld-pill"><span class="lbl"><?= htmlspecialchars($TEXT['guild_leader'] ?? 'Leader') ?></span>
                        <a href="/armory/<?= rawurlencode($guild['leader_name']) ?>" style="color:<?= $lclr ?>;text-decoration:none;font-weight:700"><?= htmlspecialchars($guild['leader_name']) ?></a>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($guild['createdate'])): ?>
                    <span class="gld-pill"><span class="lbl"><?= htmlspecialchars($TEXT['guild_founded'] ?? 'Founded') ?></span>
                        <?= htmlspecialchars(date('M j, Y', (int)$guild['createdate'])) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($guild['motd'])): ?>
                <div class="gld-motd"><i class="bi bi-megaphone-fill me-2" style="color:var(--accent)"></i><?= htmlspecialchars($guild['motd']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- STAT STRIP -->
    <div class="gld-stats">
        <div class="gld-stat accent">
            <div class="k"><?= htmlspecialchars($TEXT['guild_stat_level'] ?? 'Guild Level') ?></div>
            <div class="v"><?= (int)$guild['level'] ?></div>
        </div>
        <div class="gld-stat">
            <div class="k"><?= htmlspecialchars($TEXT['guild_stat_members'] ?? 'Members') ?></div>
            <div class="v"><?= number_format($total_members) ?></div>
        </div>
        <div class="gld-stat green">
            <div class="k"><?= htmlspecialchars($TEXT['guild_stat_online'] ?? 'Online') ?></div>
            <div class="v"><?= number_format($online_members) ?></div>
        </div>
        <div class="gld-stat">
            <div class="k"><?= htmlspecialchars($TEXT['guild_stat_bank'] ?? 'Guild Bank') ?></div>
            <div class="v" style="font-size:1.05rem"><?= htmlspecialchars(format_gold((int)$guild['BankMoney'])) ?></div>
        </div>
    </div>

    <!-- MEMBERS PANEL -->
    <div class="gld-panel">
        <div class="gld-panel-title"><i class="bi bi-people-fill me-2"></i><?= htmlspecialchars($TEXT['guild_panel_members'] ?? 'Members') ?></div>

        <?php if (empty($members)): ?>
            <div class="gld-empty">
                <div class="icn"><i class="bi bi-person-x"></i></div>
                <div><?= htmlspecialchars($TEXT['guild_no_members'] ?? 'No members in this guild.') ?></div>
            </div>
        <?php else: ?>
            <table class="gld-tbl" role="grid">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($TEXT['guild_col_char'] ?? 'Character') ?></th>
                        <th><?= htmlspecialchars($TEXT['guild_col_lvl'] ?? 'Lv') ?></th>
                        <th><?= htmlspecialchars($TEXT['guild_col_class'] ?? 'Race · Class') ?></th>
                        <th><?= htmlspecialchars($TEXT['guild_col_rank'] ?? 'Rank') ?></th>
                        <th><?= htmlspecialchars($TEXT['guild_col_status'] ?? 'Status') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m):
                        $rid = (int)$m['race']; $gid = (int)$m['gender']; $cid = (int)$m['class'];
                        $clr = $class_colors[$cid] ?? 'var(--accent)';
                        $rk  = (int)$m['rank'];
                        $is_leader = ($rk === 0);
                        $rkname = $is_leader
                            ? ($TEXT['guild_rank_leader'] ?? 'Guild Master')
                            : ($ranks[$rk] ?? sprintf($TEXT['guild_rank_fallback'] ?? 'Rank #%d', $rk));
                        $on = ((int)$m['online'] === 1);
                        $url = '/armory/' . rawurlencode($m['name']);
                    ?>
                    <tr class="gld-row" onclick="window.location.href='<?= htmlspecialchars($url, ENT_QUOTES) ?>'">
                        <td>
                            <a class="gld-char" href="<?= htmlspecialchars($url) ?>" style="color:<?= $clr ?>;text-decoration:none" onclick="event.stopPropagation()">
                                <img src="<?= '/' . get_race_icon_path($rid, $gid) ?>" alt="<?= htmlspecialchars(get_race_name($rid)) ?>" loading="lazy">
                                <span class="gld-name-cell"><?= htmlspecialchars($m['name']) ?></span>
                            </a>
                        </td>
                        <td class="gld-cell-row"><span class="gld-lvl"><?= (int)$m['level'] ?></span></td>
                        <td class="gld-cell-row gld-meta-cell"><?= htmlspecialchars(get_race_name($rid)) ?> · <?= htmlspecialchars(get_class_name($cid)) ?></td>
                        <td class="gld-cell-row"><span class="gld-rank <?= $is_leader ? 'leader' : '' ?>"><?= htmlspecialchars($rkname) ?></span></td>
                        <td class="gld-cell-row">
                            <span class="gld-status <?= $on ? 'on' : 'off' ?>"><span class="dot"></span>
                                <?= htmlspecialchars($on ? ($TEXT['armory_label_online'] ?? 'Online') : ($TEXT['armory_label_offline'] ?? 'Offline')) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (!empty($guild['info'])): ?>
    <div class="gld-panel" style="margin-top:1.1rem">
        <div class="gld-panel-title"><i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($TEXT['guild_panel_info'] ?? 'About') ?></div>
        <div style="color:rgba(255,255,255,.78);font-size:.95rem;line-height:1.6;white-space:pre-wrap"><?= htmlspecialchars($guild['info']) ?></div>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
