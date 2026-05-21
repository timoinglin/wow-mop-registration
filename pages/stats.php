<?php
/**
 * Server statistics — public community-pride dashboard.
 *
 *  /stats
 *
 * All queries are read-only against auth + characters DBs. Repack-defensive:
 * any sub-query that throws (missing column / table on this repack) is
 * silently skipped and the dependent section either hides or renders zeros.
 *
 * Caching: none in v1. The queries are COUNT/GROUP-BY scans on indexed
 * columns and finish in milliseconds even on a realm with tens of
 * thousands of characters. If perf becomes an issue, wrap each block in a
 * 5-min site_settings cache (same pattern as the update-available check).
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// ─── Lookup tables (mirror Armory) ───────────────────────────────────────────
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$class_names = [
    1 => $TEXT['class_warrior']      ?? 'Warrior',
    2 => $TEXT['class_paladin']      ?? 'Paladin',
    3 => $TEXT['class_hunter']       ?? 'Hunter',
    4 => $TEXT['class_rogue']        ?? 'Rogue',
    5 => $TEXT['class_priest']       ?? 'Priest',
    6 => $TEXT['class_dk']           ?? 'Death Knight',
    7 => $TEXT['class_shaman']       ?? 'Shaman',
    8 => $TEXT['class_mage']         ?? 'Mage',
    9 => $TEXT['class_warlock']      ?? 'Warlock',
    10 => $TEXT['class_monk']        ?? 'Monk',
    11 => $TEXT['class_druid']       ?? 'Druid',
];
$race_names = [
    1 => 'Human', 2 => 'Orc', 3 => 'Dwarf', 4 => 'Night Elf', 5 => 'Undead',
    6 => 'Tauren', 7 => 'Gnome', 8 => 'Troll', 9 => 'Goblin', 10 => 'Blood Elf',
    11 => 'Draenei', 22 => 'Worgen', 25 => 'Pandaren (A)', 26 => 'Pandaren (H)',
];
$alliance_races = [1, 3, 4, 7, 11, 22, 25];
$horde_races    = [2, 5, 6, 8, 9, 10, 26];

// ─── Hero KPIs ───────────────────────────────────────────────────────────────
// Exclude bot accounts (TrinityCore's wow-bot fork creates "BOT12345" names).
$kpi = ['accounts' => 0, 'characters' => 0, 'online' => 0, 'guilds' => 0];
try {
    $kpi['accounts']   = (int)$pdo_auth->query(
        "SELECT COUNT(*) FROM account WHERE username NOT REGEXP '^BOT[0-9]+$'"
    )->fetchColumn();
} catch (PDOException $e) {}
try {
    $kpi['characters'] = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters")->fetchColumn();
} catch (PDOException $e) {}
try {
    $kpi['online']     = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters WHERE online = 1")->fetchColumn();
} catch (PDOException $e) {}
try {
    $kpi['guilds']     = (int)$pdo_chars->query("SELECT COUNT(*) FROM guild")->fetchColumn();
} catch (PDOException $e) {}

// ─── Faction balance ─────────────────────────────────────────────────────────
$faction_counts = ['alliance' => 0, 'horde' => 0, 'neutral' => 0];
try {
    $rows = $pdo_chars->query("SELECT race, COUNT(*) AS n FROM characters GROUP BY race")->fetchAll();
    foreach ($rows as $r) {
        $rid = (int)$r['race']; $n = (int)$r['n'];
        if     (in_array($rid, $alliance_races, true)) $faction_counts['alliance'] += $n;
        elseif (in_array($rid, $horde_races, true))    $faction_counts['horde']    += $n;
        else                                            $faction_counts['neutral']  += $n;
    }
} catch (PDOException $e) {}
$faction_total = array_sum($faction_counts);
$faction_pct = function ($k) use ($faction_counts, $faction_total) {
    return $faction_total > 0 ? ($faction_counts[$k] / $faction_total * 100) : 0;
};

// ─── Class distribution ──────────────────────────────────────────────────────
$class_counts = [];
try {
    $rows = $pdo_chars->query("SELECT class, COUNT(*) AS n FROM characters GROUP BY class ORDER BY n DESC")->fetchAll();
    foreach ($rows as $r) $class_counts[(int)$r['class']] = (int)$r['n'];
} catch (PDOException $e) {}
$class_max = $class_counts ? max($class_counts) : 1;
$class_total = array_sum($class_counts);

// ─── Race distribution ───────────────────────────────────────────────────────
$race_counts = [];
try {
    $rows = $pdo_chars->query("SELECT race, COUNT(*) AS n FROM characters GROUP BY race ORDER BY n DESC")->fetchAll();
    foreach ($rows as $r) {
        $rid = (int)$r['race'];
        if (!isset($race_names[$rid])) continue; // skip unknown race ids
        $race_counts[$rid] = (int)$r['n'];
    }
} catch (PDOException $e) {}
$race_max = $race_counts ? max($race_counts) : 1;

// ─── Level distribution (buckets of 10, then a separate 90-cap column) ───────
// 1-9, 10-19, ..., 80-89, 90.  Ten buckets total.
$level_buckets = array_fill(0, 10, 0);
try {
    $rows = $pdo_chars->query("SELECT level, COUNT(*) AS n FROM characters GROUP BY level")->fetchAll();
    foreach ($rows as $r) {
        $lvl = (int)$r['level']; $n = (int)$r['n'];
        if ($lvl <= 0) continue;
        if ($lvl >= 90)    $level_buckets[9] += $n;
        else               $level_buckets[(int)floor($lvl / 10)] += $n;
    }
} catch (PDOException $e) {}
$level_max = $level_buckets ? max($level_buckets) : 1;

// ─── Activity — last_login buckets ───────────────────────────────────────────
// characters.logout_time is a unix timestamp; 0 = never logged out (online now
// or freshly-created). We bucket "ever logged in" by recency.
$activity = ['today' => 0, 'week' => 0, 'month' => 0, 'older' => 0];
try {
    $now = time();
    $rows = $pdo_chars->query("SELECT logout_time FROM characters WHERE logout_time > 0")->fetchAll();
    foreach ($rows as $r) {
        $age = $now - (int)$r['logout_time'];
        if      ($age <    86400) $activity['today']++;
        elseif  ($age <   604800) $activity['week']++;
        elseif  ($age <  2592000) $activity['month']++;
        else                       $activity['older']++;
    }
} catch (PDOException $e) {}

// ─── Registrations / day — last 30 days ──────────────────────────────────────
$reg_labels = [];
$reg_vals   = [];
try {
    $reg_rows = $pdo_auth->query(
        "SELECT DATE(joindate) AS d, COUNT(*) AS n
         FROM account
         WHERE joindate >= NOW() - INTERVAL 30 DAY
           AND username NOT REGEXP '^BOT[0-9]+$'
         GROUP BY DATE(joindate) ORDER BY d ASC"
    )->fetchAll();
    $reg_map = [];
    foreach ($reg_rows as $r) $reg_map[$r['d']] = (int)$r['n'];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $reg_labels[] = $d;
        $reg_vals[]   = $reg_map[$d] ?? 0;
    }
} catch (PDOException $e) {}
$reg_max = $reg_vals ? max(max($reg_vals), 1) : 1;
$reg_total = array_sum($reg_vals);

// ─── Render ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.stats-page { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
.stats-header { margin-bottom: 1.5rem; }
.stats-header h1 { color: var(--accent); font-weight: 800; margin: 0 0 .3rem; font-size: 1.85rem; }
.stats-header p  { color: #8899aa; margin: 0; font-size: .95rem; }

/* Hero KPI strip */
.kpi-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:1rem; margin-bottom:1.5rem; }
.kpi-card { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:12px; padding:1rem 1.2rem; }
.kpi-card .lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:.4rem; }
.kpi-card .big { font-size:2rem; font-weight:800; color:var(--accent); line-height:1; font-variant-numeric: tabular-nums; }
.kpi-card .sub { color:#6c7a8c; font-size:.78rem; margin-top:.35rem; }
.kpi-card.online .big { color:#5dd87c; }
@media (max-width: 900px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }

/* Card / panel for each section */
.stats-card { background: rgba(255,255,255,.02); border:1px solid rgba(var(--btn-bg-rgb),.25); border-radius:12px; padding:1.2rem 1.3rem; margin-bottom:1.2rem; }
.stats-card-title { color:var(--accent); font-weight:700; font-size:.78rem; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }

/* Two-column row */
.stats-row { display:grid; grid-template-columns: 1fr 1fr; gap:1.2rem; }
@media (max-width: 900px) { .stats-row { grid-template-columns: 1fr; } }

/* Faction split bar */
.fac-bar { display:flex; height:24px; border-radius:6px; overflow:hidden; border:1px solid rgba(255,255,255,.08); margin-bottom:.7rem; }
.fac-bar > div { display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:.78rem; transition:flex .25s; min-width:0; }
.fac-bar > div.fac-a { background: linear-gradient(180deg, #2a86c8, #1c5d8f); }
.fac-bar > div.fac-h { background: linear-gradient(180deg, #b53030, #8a1a1a); }
.fac-bar > div.fac-n { background: linear-gradient(180deg, #888, #555); }
.fac-legend { display:flex; gap:1rem; flex-wrap:wrap; font-size:.85rem; color:#aab; }
.fac-legend .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:.35rem; vertical-align:middle; }
.fac-legend .alliance .dot { background:#2a86c8; }
.fac-legend .horde    .dot { background:#b53030; }
.fac-legend .neutral  .dot { background:#888; }
.fac-legend .n { color:#dee2e6; font-weight:700; margin-left:.25rem; }

/* Distribution bars (horizontal) */
.dist-list { display:flex; flex-direction:column; gap:.45rem; }
.dist-row  { display:grid; grid-template-columns: 110px 1fr 60px; align-items:center; gap:.7rem; font-size:.86rem; }
.dist-row .nm { color:#dee2e6; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dist-row .bar { height:10px; background: rgba(255,255,255,.05); border-radius:999px; overflow:hidden; }
.dist-row .bar > i { display:block; height:100%; border-radius:999px; transition:width .4s; }
.dist-row .vl { color:#8899aa; text-align:right; font-variant-numeric: tabular-nums; font-size:.82rem; }

/* Level histogram (vertical bars) */
.hist { display:grid; grid-template-columns: repeat(10, 1fr); gap:.4rem; align-items:end; height:160px; padding-bottom:.4rem; border-bottom: 1px solid rgba(255,255,255,.06); }
.hist-col { display:flex; flex-direction:column; align-items:center; height:100%; justify-content:flex-end; gap:.3rem; }
.hist-col .bar { width:100%; background: linear-gradient(180deg, var(--accent), var(--accent-dim, var(--accent))); border-radius:4px 4px 0 0; min-height:2px; transition:height .4s; }
.hist-col .vl  { font-size:.7rem; color:#8899aa; font-variant-numeric: tabular-nums; }
.hist-labels { display:grid; grid-template-columns: repeat(10, 1fr); gap:.4rem; margin-top:.5rem; }
.hist-labels span { text-align:center; font-size:.7rem; color:#6c7a8c; }

/* Activity tiles */
.act-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:.8rem; }
.act-tile { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.25); border-radius:8px; padding:.85rem 1rem; }
.act-tile .lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.2px; }
.act-tile .big { color:var(--accent); font-size:1.45rem; font-weight:800; line-height:1.1; margin-top:.3rem; font-variant-numeric: tabular-nums; }
.act-tile.today .big { color:#5dd87c; }
@media (max-width: 768px) { .act-grid { grid-template-columns: 1fr 1fr; } }

/* Registrations line chart (inline SVG) */
.reg-svg { width:100%; height:160px; display:block; }
.reg-meta { display:flex; justify-content:space-between; color:#8899aa; font-size:.78rem; margin-top:.5rem; }
.reg-meta .total { color:var(--accent); font-weight:700; }
</style>

<div class="stats-page">
    <div class="stats-header">
        <h1><i class="bi bi-bar-chart-line-fill me-2"></i><?= htmlspecialchars($TEXT['stats_title'] ?? 'Server Statistics') ?></h1>
        <p><?= htmlspecialchars($TEXT['stats_subtitle'] ?? 'A live snapshot of the realm — players, factions, classes, and activity.') ?></p>
    </div>

    <!-- KPI strip -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="lbl"><?= htmlspecialchars($TEXT['stats_kpi_accounts'] ?? 'Accounts') ?></div>
            <div class="big"><?= number_format($kpi['accounts']) ?></div>
            <div class="sub"><?= htmlspecialchars($TEXT['stats_kpi_accounts_sub'] ?? 'Real players (bots excluded)') ?></div>
        </div>
        <div class="kpi-card">
            <div class="lbl"><?= htmlspecialchars($TEXT['stats_kpi_characters'] ?? 'Characters') ?></div>
            <div class="big"><?= number_format($kpi['characters']) ?></div>
            <div class="sub"><?= htmlspecialchars($TEXT['stats_kpi_characters_sub'] ?? 'All characters across the realm') ?></div>
        </div>
        <div class="kpi-card online">
            <div class="lbl"><?= htmlspecialchars($TEXT['stats_kpi_online'] ?? 'Online now') ?></div>
            <div class="big"><?= number_format($kpi['online']) ?></div>
            <div class="sub"><a href="/online" style="color:#6c7a8c;text-decoration:none"><?= htmlspecialchars($TEXT['stats_kpi_online_sub'] ?? "See who's online →") ?></a></div>
        </div>
        <div class="kpi-card">
            <div class="lbl"><?= htmlspecialchars($TEXT['stats_kpi_guilds'] ?? 'Guilds') ?></div>
            <div class="big"><?= number_format($kpi['guilds']) ?></div>
            <div class="sub"><a href="/leaderboards?type=guilds" style="color:#6c7a8c;text-decoration:none"><?= htmlspecialchars($TEXT['stats_kpi_guilds_sub'] ?? 'Guild leaderboard →') ?></a></div>
        </div>
    </div>

    <!-- Faction balance + Activity (side by side on desktop) -->
    <div class="stats-row">
        <?php if ($faction_total > 0): ?>
        <div class="stats-card">
            <div class="stats-card-title"><i class="bi bi-shield-shaded"></i><?= htmlspecialchars($TEXT['stats_section_faction'] ?? 'Faction balance') ?></div>
            <?php
                $a_pct = $faction_pct('alliance');
                $h_pct = $faction_pct('horde');
                $n_pct = $faction_pct('neutral');
            ?>
            <div class="fac-bar">
                <div class="fac-a" style="flex: <?= max(0.001, $a_pct) ?>"><?php if ($a_pct >= 8) echo round($a_pct).'%'; ?></div>
                <?php if ($h_pct > 0): ?><div class="fac-h" style="flex: <?= max(0.001, $h_pct) ?>"><?php if ($h_pct >= 8) echo round($h_pct).'%'; ?></div><?php endif; ?>
                <?php if ($n_pct > 0): ?><div class="fac-n" style="flex: <?= max(0.001, $n_pct) ?>"><?php if ($n_pct >= 8) echo round($n_pct).'%'; ?></div><?php endif; ?>
            </div>
            <div class="fac-legend">
                <span class="alliance"><span class="dot"></span><?= htmlspecialchars($TEXT['faction_alliance'] ?? 'Alliance') ?> <span class="n"><?= number_format($faction_counts['alliance']) ?></span></span>
                <span class="horde"><span class="dot"></span><?= htmlspecialchars($TEXT['faction_horde'] ?? 'Horde') ?> <span class="n"><?= number_format($faction_counts['horde']) ?></span></span>
                <?php if ($faction_counts['neutral'] > 0): ?>
                <span class="neutral"><span class="dot"></span><?= htmlspecialchars($TEXT['faction_neutral'] ?? 'Neutral') ?> <span class="n"><?= number_format($faction_counts['neutral']) ?></span></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="stats-card">
            <div class="stats-card-title"><i class="bi bi-activity"></i><?= htmlspecialchars($TEXT['stats_section_activity'] ?? 'Activity') ?></div>
            <div class="act-grid">
                <div class="act-tile today">
                    <div class="lbl"><?= htmlspecialchars($TEXT['stats_act_today'] ?? 'Today') ?></div>
                    <div class="big"><?= number_format($activity['today']) ?></div>
                </div>
                <div class="act-tile">
                    <div class="lbl"><?= htmlspecialchars($TEXT['stats_act_week'] ?? 'This week') ?></div>
                    <div class="big"><?= number_format($activity['week']) ?></div>
                </div>
                <div class="act-tile">
                    <div class="lbl"><?= htmlspecialchars($TEXT['stats_act_month'] ?? 'This month') ?></div>
                    <div class="big"><?= number_format($activity['month']) ?></div>
                </div>
                <div class="act-tile">
                    <div class="lbl"><?= htmlspecialchars($TEXT['stats_act_older'] ?? 'Older') ?></div>
                    <div class="big"><?= number_format($activity['older']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Class distribution + Race distribution -->
    <div class="stats-row">
        <?php if (!empty($class_counts)): ?>
        <div class="stats-card">
            <div class="stats-card-title"><i class="bi bi-people-fill"></i><?= htmlspecialchars($TEXT['stats_section_classes'] ?? 'Class distribution') ?></div>
            <div class="dist-list">
                <?php foreach ($class_counts as $cid => $n):
                    $w   = max(1, (int)round($n / $class_max * 100));
                    $clr = $class_colors[$cid] ?? 'var(--accent)';
                    $nm  = $class_names[$cid] ?? "Class $cid";
                    $pct = $class_total > 0 ? round($n / $class_total * 100, 1) : 0;
                ?>
                <div class="dist-row">
                    <span class="nm" style="color:<?= $clr ?>"><?= htmlspecialchars($nm) ?></span>
                    <div class="bar"><i style="width:<?= $w ?>%;background:<?= $clr ?>"></i></div>
                    <span class="vl"><?= number_format($n) ?> <span style="color:#6c7a8c">(<?= $pct ?>%)</span></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($race_counts)): ?>
        <div class="stats-card">
            <div class="stats-card-title"><i class="bi bi-globe-americas"></i><?= htmlspecialchars($TEXT['stats_section_races'] ?? 'Race distribution') ?></div>
            <div class="dist-list">
                <?php foreach ($race_counts as $rid => $n):
                    $w   = max(1, (int)round($n / $race_max * 100));
                    $clr = in_array($rid, $alliance_races, true) ? '#2a86c8' : (in_array($rid, $horde_races, true) ? '#b53030' : '#888');
                    $nm  = $race_names[$rid] ?? "Race $rid";
                ?>
                <div class="dist-row">
                    <span class="nm"><?= htmlspecialchars($nm) ?></span>
                    <div class="bar"><i style="width:<?= $w ?>%;background:<?= $clr ?>"></i></div>
                    <span class="vl"><?= number_format($n) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Level histogram -->
    <?php if (array_sum($level_buckets) > 0): ?>
    <div class="stats-card">
        <div class="stats-card-title"><i class="bi bi-bar-chart-steps"></i><?= htmlspecialchars($TEXT['stats_section_levels'] ?? 'Level distribution') ?></div>
        <div class="hist">
            <?php
                $bucket_labels = ['1-9','10-19','20-29','30-39','40-49','50-59','60-69','70-79','80-89','90'];
                foreach ($level_buckets as $i => $n):
                    $h = max(2, (int)round($n / $level_max * 100));
            ?>
            <div class="hist-col">
                <span class="vl"><?= number_format($n) ?></span>
                <div class="bar" style="height: <?= $h ?>%"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="hist-labels">
            <?php foreach ($bucket_labels as $lbl): ?>
            <span><?= $lbl ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Registrations / day chart (inline SVG line) -->
    <?php if ($reg_total > 0): ?>
    <div class="stats-card">
        <div class="stats-card-title"><i class="bi bi-person-plus-fill"></i><?= htmlspecialchars($TEXT['stats_section_registrations'] ?? 'New registrations · last 30 days') ?></div>
        <?php
            $n = count($reg_vals);
            $w = 1200; $h = 160; $pad = 8;
            $points = [];
            for ($i = 0; $i < $n; $i++) {
                $x = $pad + ($i / max(1, $n - 1)) * ($w - 2 * $pad);
                $y = ($h - $pad) - ($reg_vals[$i] / $reg_max) * ($h - 2 * $pad);
                $points[] = round($x, 1) . ',' . round($y, 1);
            }
            $line   = implode(' ', $points);
            $polyfill = $line . ' ' . ($w - $pad) . ',' . ($h - $pad) . ' ' . $pad . ',' . ($h - $pad);
        ?>
        <svg class="reg-svg" viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="none" aria-label="Registrations per day for the last 30 days">
            <defs>
                <linearGradient id="reg-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%"   stop-color="var(--accent)" stop-opacity=".35"/>
                    <stop offset="100%" stop-color="var(--accent)" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <polygon points="<?= $polyfill ?>" fill="url(#reg-fill)"/>
            <polyline points="<?= $line ?>" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        </svg>
        <div class="reg-meta">
            <span><?= htmlspecialchars(date('M j', strtotime('-29 days'))) ?> — <?= htmlspecialchars(date('M j')) ?></span>
            <span class="total"><?= number_format($reg_total) ?> <?= htmlspecialchars($TEXT['stats_reg_total'] ?? 'new accounts this month') ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
