<?php
require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/login_history.php';
require_once __DIR__ . '/../includes/playtime_rewards.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/wl_2fa.php';

// --- Auth ---
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// --- Handle Playtime Reward claim POST (PRG pattern: redirect after) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_playtime') {
    $claimed_amount = 0;
    $claim_failed   = false;
    if (validate_csrf_token($_POST['csrf_token'] ?? null) && !empty($config['playtime_reward']['enabled'])) {
        try {
            $claimed_amount = pr_claim((int)$_SESSION['user_id'], $pdo_auth, $pdo_chars, $config);
        } catch (Exception $e) {
            error_log('Playtime claim error: ' . $e->getMessage());
            $claim_failed = true;
        }
    }
    $params = [];
    if ($claimed_amount > 0) $params['claimed'] = $claimed_amount;
    if ($claim_failed)        $params['claim_error'] = 1;
    header('Location: /dashboard' . ($params ? '?' . http_build_query($params) : '') . '#playtime-reward');
    exit;
}

require_once __DIR__ . '/../templates/header.php';

$user_id = $_SESSION['user_id'];
$user = null;
$account_status = 'Active';
$gm_level = 0;
$characters = [];
$total_playtime_seconds = 0;
$total_gold = 0;
$most_played_char = null;
$errors = [];

// --- Account Info ---
try {
    $stmt = $pdo_auth->prepare("SELECT username, email, joindate, last_ip, dp FROM account WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: /login?error=invalid_user');
        exit;
    }

    $stmt_ban = $pdo_auth->prepare("SELECT 1 FROM account_banned WHERE id = :id AND active = 1");
    $stmt_ban->execute(['id' => $user_id]);
    if ($stmt_ban->fetchColumn()) {
        $account_status = 'Banned';
    }

    $stmt_gm = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
    $stmt_gm->execute(['id' => $user_id]);
    $gm_level = (int)($stmt_gm->fetchColumn() ?: 0);

    $user_avatar = avatar_get($pdo_auth, (int)$user_id);
} catch (PDOException $e) {
    error_log("Dashboard Account DB Error: " . $e->getMessage());
    $errors[] = $TEXT['error_db'];
}

// --- Characters ---
$error_loading_chars = false;
if ($pdo_chars) {
    try {
        $sql = "SELECT c.guid, c.name, c.race, c.class, c.level, c.zone,
                       c.totaltime, c.logout_time, c.money, c.gender, c.online,
                       g.name as guild_name
                FROM characters c
                LEFT JOIN guild_member gm ON gm.guid = c.guid
                LEFT JOIN guild g ON g.guildid = gm.guildid
                WHERE c.account = :aid
                ORDER BY c.level DESC, c.name ASC";
        $s = $pdo_chars->prepare($sql);
        $s->execute(['aid' => $user_id]);
        $characters = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Characters DB Error: " . $e->getMessage());
        $error_loading_chars = true;
    }
} else {
    $error_loading_chars = true;
}

// --- Achievement counts per character ---
$achievement_counts = [];
if ($pdo_chars && !empty($characters)) {
    try {
        $guids = array_column($characters, 'guid');
        $placeholders = implode(',', array_fill(0, count($guids), '?'));
        $s = $pdo_chars->prepare(
            "SELECT guid, COUNT(*) as achiev_count FROM character_achievement WHERE guid IN ($placeholders) GROUP BY guid"
        );
        $s->execute($guids);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $achievement_counts[(int)$row['guid']] = (int)$row['achiev_count'];
        }
    } catch (PDOException $e) {
        // Table may not exist — silently ignore, achievements just won't show
        error_log("Achievement count error: " . $e->getMessage());
    }
}

// --- Aggregate Stats ---
$max_playtime = -1;
foreach ($characters as $char) {
    $pt = (int)$char['totaltime'];
    $total_playtime_seconds += $pt;
    $total_gold += (int)$char['money'];
    if ($pt > $max_playtime) {
        $max_playtime = $pt;
        $most_played_char = $char;
    }
}

// --- Login History ---
$login_history = get_login_history($user_id);

// --- Chart Data ---
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$chart_labels = [];
$chart_data   = [];
$chart_colors_arr = [];
foreach ($characters as $char) {
    if ((int)$char['totaltime'] > 0) {
        $chart_labels[]     = htmlspecialchars($char['name']);
        $chart_data[]       = (int)$char['totaltime'];
        $chart_colors_arr[] = $class_colors[(int)$char['class']] ?? '#8B4513'; // Chart.js canvas — literal, not a CSS var
    }
}

$tickets_enabled = !empty($config['features']['tickets']);

// --- Playtime Reward state ---
$pr_status        = pr_get_status((int)$_SESSION['user_id'], $pdo_auth, $pdo_chars, $config);
$pr_history       = $pr_status['enabled'] ? pr_get_history((int)$_SESSION['user_id'], $pdo_auth, 10) : [];
$pr_just_claimed  = isset($_GET['claimed']) ? max(0, (int)$_GET['claimed']) : 0;
$pr_claim_error   = isset($_GET['claim_error']);

// If the user just claimed, refresh the displayed dp on the stat card without a re-fetch
if ($pr_just_claimed > 0 && isset($user)) {
    $user['dp'] = (int)($user['dp'] ?? 0) + $pr_just_claimed;
}
?>

<style>
.dash-hero {
    position: relative;
    padding: 3rem 2rem 2.5rem;
    margin-bottom: 2rem;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(var(--btn-bg-rgb), 0.35) 0%, rgba(10,10,20,0.85) 60%),
                url('/assets/img/wow-bg/4-1.webp') center/cover no-repeat;
    border: 1px solid rgba(var(--btn-bg-rgb), 0.4);
    overflow: hidden;
}
.dash-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, transparent 60%, rgba(10,10,20,0.8));
    pointer-events: none;
}
.dash-hero .hero-username {
    font-size: 2.2rem;
    font-weight: 700;
    letter-spacing: 2px;
    background: linear-gradient(90deg, var(--accent), #fff 60%, var(--accent));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.dash-hero .hero-sub { font-size: .95rem; color: rgba(var(--accent-rgb), .75); letter-spacing: 1px; }

/* ── Hero avatar ─────────────────────────────────────────────────────────── */
.dash-hero .hero-row {
    display: flex;
    align-items: center;
    gap: 1.6rem;
    flex-wrap: wrap;
}
.avatar-wrap {
    position: relative;
    cursor: pointer;
    transition: transform .15s ease;
    flex-shrink: 0;
}
.avatar-wrap:hover { transform: scale(1.04); }
.avatar-wrap .wl-avatar {
    width: 112px !important;
    height: 112px !important;
    border-width: 3px !important;
    box-shadow: 0 6px 20px rgba(0,0,0,.5), 0 0 0 2px rgba(10,10,15,.85);
}
.avatar-wrap .wl-avatar { font-size: 46px !important; }
.avatar-overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,.55);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: .15rem;
    opacity: 0;
    transition: opacity .15s ease;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    pointer-events: none;
}
.avatar-wrap:hover .avatar-overlay { opacity: 1; }
.avatar-overlay i { font-size: 1.4rem; }

/* Toast strip for upload/remove result */
.avatar-toast {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .9rem;
    border-radius: 30px;
    font-size: .82rem;
    margin-top: .8rem;
    border: 1px solid;
}
.avatar-toast.ok   { background: rgba(46,204,113,.12); color: #5dd87c; border-color: rgba(46,204,113,.4); }
.avatar-toast.warn { background: rgba(231,76,60,.12); color: #f87e8a; border-color: rgba(231,76,60,.4); }

/* ── Avatar upload modal ─────────────────────────────────────────────────── */
.av-modal-back {
    position: fixed; inset: 0; background: rgba(0,0,0,.65);
    z-index: 1060; display: none; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.av-modal-back.show { display: flex; }
.av-modal {
    background: linear-gradient(145deg,#16161f,#0d0d14);
    border: 1px solid rgba(var(--btn-bg-rgb), .4);
    border-radius: 12px;
    padding: 1.75rem;
    width: 90%; max-width: 460px;
    color: #dee2e6;
    box-shadow: 0 30px 80px rgba(0,0,0,.6);
    position: relative;
}
.av-modal h3 {
    color: var(--accent);
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    margin: 0 0 1rem;
}
.av-modal .av-close {
    position: absolute; top: 12px; right: 14px;
    background: transparent; border: 0; color: #8899aa;
    font-size: 1.4rem; cursor: pointer; line-height: 1;
}
.av-modal .av-close:hover { color: #fff; }
.av-modal .av-current { display:flex; align-items:center; gap:1rem; margin-bottom:1.2rem; }
.av-modal .av-hint { color:#8899aa; font-size:.85rem; line-height:1.5; }
.av-modal .av-actions { display:flex; gap:.5rem; flex-wrap: wrap; margin-top: 1.2rem; }
.av-btn {
    padding: .55rem 1.1rem;
    border: 1px solid;
    border-radius: 6px;
    font-size: .88rem;
    cursor: pointer;
    background: transparent;
    color: var(--accent);
    border-color: rgba(var(--accent-rgb), .4);
    transition: all .15s ease;
    font-family: inherit;
}
.av-btn:hover { background:rgba(var(--accent-rgb), .12); color:#fff; border-color:var(--accent); }
.av-btn-primary { background:var(--btn-bg); color:#fff; border-color:var(--btn-bg-hover); }
.av-btn-primary:hover { background:var(--btn-bg-hover); color:#fff; border-color:var(--accent); }
.av-btn-danger { color:#f87e8a; border-color:rgba(231,76,60,.35); }
.av-btn-danger:hover { background:rgba(231,76,60,.1); color:#fff; border-color:#f87e8a; }
.av-modal input[type="file"] {
    width: 100%;
    background: #0a0a0f;
    border: 1px dashed rgba(var(--btn-bg-rgb), .4);
    border-radius: 6px;
    padding: .8rem;
    color: #dee2e6;
    font-size: .85rem;
}
.av-modal input[type="file"]::file-selector-button {
    background: #2a1f10; color: var(--accent); border: 1px solid rgba(var(--btn-bg-rgb), .4);
    border-radius: 4px; padding: .35rem .8rem; cursor: pointer; margin-right: .8rem;
    font-size: .82rem;
}

.status-badge {
    display: inline-block;
    padding: .25rem .75rem;
    border-radius: 50px;
    font-size: .78rem;
    font-weight: 600;
    letter-spacing: .5px;
}
.status-active  { background: rgba(40,167,69,.2);  color: #5dd87c; border: 1px solid rgba(40,167,69,.4); }
.status-banned  { background: rgba(220,53,69,.2);  color: #f87e8a; border: 1px solid rgba(220,53,69,.4); }
.status-gm      { background: rgba(var(--btn-bg-rgb), .3);  color: var(--accent); border: 1px solid rgba(var(--accent-rgb), .4); }

.stat-card {
    background: linear-gradient(145deg, #1a1a2e, #16213e);
    border: 1px solid rgba(var(--btn-bg-rgb), 0.3);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    transition: transform .2s ease, border-color .2s ease;
    height: 100%;
}
.stat-card:hover { transform: translateY(-3px); border-color: rgba(var(--accent-rgb), 0.5); }
.stat-card .stat-icon  { font-size: 1.8rem; margin-bottom: .5rem; opacity: .9; }
.stat-card .stat-value { font-size: 1.7rem; font-weight: 700; color: var(--accent); line-height: 1; }
.stat-card .stat-label { font-size: .78rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px; margin-top: .25rem; }
/* Battle Pay accent — subtle teal glow to differentiate from gold */
.stat-card-bp { border-color: rgba(105,204,240,.25); }
.stat-card-bp:hover { border-color: rgba(105,204,240,.6); box-shadow: 0 6px 20px rgba(105,204,240,.12); }
.stat-card-bp .stat-value { color: #69ccf0; }

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .6rem;
    padding: .85rem 1.5rem;
    border-radius: 10px;
    font-weight: 600;
    font-size: .9rem;
    letter-spacing: .4px;
    transition: all .2s ease;
    border: none;
    text-decoration: none;
}
.action-btn-primary { background: linear-gradient(135deg, var(--btn-bg), var(--btn-bg-hover)); color: #fff; }
.action-btn-primary:hover { background: linear-gradient(135deg, var(--btn-bg-hover), var(--accent)); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(var(--btn-bg-rgb), .4); }
.action-btn-secondary { background: rgba(255,255,255,0.05); color: var(--accent); border: 1px solid rgba(var(--accent-rgb), 0.3); }
.action-btn-secondary:hover { background: rgba(var(--accent-rgb), 0.12); border-color: rgba(var(--accent-rgb), 0.6); color: var(--accent); transform: translateY(-2px); }

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .7rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    font-size: .9rem;
}
.info-row:last-child { border-bottom: none; }
.info-row .info-key { color: #8899aa; font-weight: 500; }
.info-row .info-val { color: #e2e8f0; text-align: right; max-width: 55%; word-break: break-all; }

.dash-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), 0.25);
    border-radius: 14px;
    padding: 1.6rem;
    height: 100%;
}
.dash-panel .panel-title {
    font-size: .75rem;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    margin-bottom: 1.2rem;
    padding-bottom: .6rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), 0.3);
}

/* Character cards (clickable → opens public Armory profile) */
.char-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 12px;
    padding: 1rem 1.2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all .2s ease;
    position: relative;
    overflow: hidden;
    border-left-width: 3px;
    text-decoration: none;
    color: inherit;
}
.char-card:hover { background: rgba(var(--accent-rgb), 0.06); border-color: rgba(var(--accent-rgb), 0.35); transform: translateX(3px); color: inherit; }
.char-online-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #5dd87c;
    box-shadow: 0 0 8px #5dd87c;
    margin-right: 6px;
    vertical-align: middle;
}
.char-icons { display: flex; flex-direction: column; gap: 3px; flex-shrink: 0; }
.char-icons img { width: 26px; height: 26px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.15); }
.char-info  { flex: 1; min-width: 0; }
.char-name  { font-weight: 700; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.char-meta  { font-size: .78rem; color: #8899aa; margin-top: 2px; }
.char-stats { text-align: right; flex-shrink: 0; }
.char-level-badge { display: inline-block; background: rgba(var(--btn-bg-rgb), .35); color: var(--accent); font-weight: 700; font-size: .85rem; padding: .2rem .6rem; border-radius: 6px; margin-bottom: 4px; }
.achiev-badge { display: inline-block; background: rgba(var(--accent-rgb), .1); color: var(--accent); font-size: .75rem; padding: .15rem .5rem; border-radius: 6px; border: 1px solid rgba(var(--accent-rgb), .2); }

/* Login History */
.login-row {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .6rem 0;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    font-size: .85rem;
}
.login-row:first-child { padding-top: 0; }
.login-row:last-child { border-bottom: none; padding-bottom: 0; }
.login-ip   { font-family: monospace; color: #8899aa; background: rgba(255,255,255,.04); padding: .1rem .4rem; border-radius: 4px; font-size: .8rem; }
.login-time { color: #8899aa; margin-left: auto; font-size: .78rem; white-space: nowrap; }
.login-idx  { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; flex-shrink: 0; }
.login-idx-0 { background: rgba(93,216,124,.2); color: #5dd87c; border: 1px solid rgba(93,216,124,.3); }
.login-idx-n { background: rgba(255,255,255,.06); color: #8899aa; }

/* ── Playtime Reward panel ───────────────────────────────────────── */
.pr-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(105,204,240,.25);
    border-radius: 14px;
    padding: 1.6rem 1.8rem;
    position: relative;
    overflow: hidden;
}
.pr-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse at 100% 0%, rgba(105,204,240,.1) 0%, transparent 40%),
        radial-gradient(ellipse at 0% 100%, rgba(93,216,124,.06) 0%, transparent 40%);
    pointer-events: none;
}
.pr-grid { position: relative; display: flex; flex-direction: column; gap: 1.2rem; }

.pr-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .6rem; }
.pr-head-title {
    font-size: .82rem;
    color: #69ccf0;
    text-transform: uppercase;
    letter-spacing: 1.6px;
    font-weight: 700;
}
.pr-head-rate { font-size: .78rem; color: #6c7a8c; }

.pr-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}
@media (max-width: 768px) { .pr-stats { grid-template-columns: 1fr; } }
.pr-stat {
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 10px;
    padding: .8rem 1rem;
}
.pr-stat-lbl {
    font-size: .7rem;
    color: #8899aa;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: .25rem;
}
.pr-stat-val {
    font-size: 1.4rem;
    font-weight: 700;
    color: #e2e8f0;
    font-variant-numeric: tabular-nums;
}
.pr-available { color: #69ccf0; }
.pr-glow {
    text-shadow: 0 0 18px rgba(105,204,240,.6);
    animation: pr-pulse 1.6s ease-in-out infinite;
}
@keyframes pr-pulse {
    0%, 100% { text-shadow: 0 0 18px rgba(105,204,240,.4); }
    50%      { text-shadow: 0 0 28px rgba(105,204,240,.85); }
}

.pr-action { display: flex; justify-content: center; }
.pr-claim-btn {
    border: none;
    background: linear-gradient(135deg, #2c5e7a, #1a4d68);
    border: 1px solid #5b9cb8;
    color: #cfe9f6;
    padding: .9rem 2rem;
    border-radius: 10px;
    font-weight: 700;
    font-size: 1rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    transition: all .25s ease;
    display: inline-flex;
    align-items: center;
    box-shadow: 0 4px 14px rgba(105,204,240,.18);
}
.pr-claim-btn:hover {
    background: linear-gradient(135deg, #5b9cb8, #69ccf0);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(105,204,240,.35);
}
.pr-claim-btn:active { transform: translateY(0); }
.pr-action-msg {
    color: #8899aa;
    font-size: .92rem;
    text-align: center;
    padding: .75rem 1rem;
}

/* Daily cap progress */
.pr-progress-wrap { font-size: .75rem; color: #6c7a8c; }
.pr-progress-track {
    height: 6px;
    background: rgba(255,255,255,.05);
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: .35rem;
}
.pr-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #5b9cb8, #69ccf0, #5dd87c);
    border-radius: 3px;
    transition: width .8s ease;
}
.pr-progress-lbl { text-align: right; }

/* Toast */
.pr-toast {
    border-radius: 10px;
    padding: .8rem 1.2rem;
    font-weight: 600;
    margin-bottom: 1.2rem;
    text-align: center;
    animation: pr-toast-in .35s ease;
}
.pr-toast-success {
    background: rgba(93,216,124,.12);
    border: 1px solid rgba(93,216,124,.4);
    color: #5dd87c;
}
.pr-toast-error {
    background: rgba(220,53,69,.12);
    border: 1px solid rgba(220,53,69,.4);
    color: #f87e8a;
}
@keyframes pr-toast-in {
    from { transform: translateY(-12px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}

/* History */
.pr-history { margin-top: .25rem; }
.pr-history > summary {
    cursor: pointer;
    font-size: .82rem;
    color: #8899aa;
    padding: .5rem 0;
    list-style: none;
    transition: color .2s;
}
.pr-history > summary::-webkit-details-marker { display: none; }
.pr-history > summary::before {
    content: '▸';
    display: inline-block;
    margin-right: .5rem;
    transition: transform .2s;
}
.pr-history[open] > summary::before { transform: rotate(90deg); }
.pr-history > summary:hover { color: var(--accent); }
.pr-history-empty { color: #4a5568; font-size: .85rem; padding: .75rem 0 .25rem; font-style: italic; }
.pr-history-tbl { width: 100%; font-size: .85rem; margin-top: .5rem; }
.pr-history-tbl th {
    text-align: left;
    padding: .5rem .75rem;
    color: #6c7a8c;
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    font-weight: 600;
}
.pr-history-tbl td {
    padding: .5rem .75rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
    color: #c0c8d8;
}
.pr-history-tbl tr:last-child td { border-bottom: none; }
.pr-history-amt { color: #5dd87c; font-weight: 700; }
</style>

<div class="container" style="padding-top: 90px; padding-bottom: 3rem;">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<!-- HERO -->
<div class="dash-hero mb-4" id="avatar">
    <div class="position-relative hero-row">
        <div class="avatar-wrap" onclick="openAvatarModal()" title="<?= htmlspecialchars($TEXT['avatar_change'] ?? 'Change avatar') ?>">
            <?= render_avatar($user['username'] ?? 'A', $user_avatar ?? null, 112) ?>
            <div class="avatar-overlay">
                <i class="bi bi-camera-fill"></i>
                <span><?= htmlspecialchars($TEXT['avatar_change_short'] ?? 'Change') ?></span>
            </div>
        </div>
        <div style="min-width:0;flex:1">
            <div class="hero-username"><?= htmlspecialchars($user['username'] ?? 'Adventurer') ?></div>
            <div class="hero-sub d-flex align-items-center gap-3 mt-1 flex-wrap">
                <span><?= $TEXT['dashboard'] ?></span>
                <?php if ($account_status === 'Banned'): ?>
                    <span class="status-badge status-banned"><i class="bi bi-slash-circle me-1"></i><?= $TEXT['status_banned'] ?></span>
                <?php else: ?>
                    <span class="status-badge status-active"><i class="bi bi-check-circle me-1"></i><?= $TEXT['status_active'] ?></span>
                <?php endif; ?>
                <?php if ($gm_level >= 1): ?>
                    <span class="status-badge status-gm"><i class="bi bi-shield-fill me-1"></i>GM <?= $gm_level ?></span>
                <?php endif; ?>
            </div>
            <?php
            $av_msg = $av_class = '';
            if (isset($_GET['avatar_uploaded'])) { $av_class = 'ok';   $av_msg = $TEXT['avatar_uploaded']   ?? 'Avatar updated.'; }
            elseif (isset($_GET['avatar_removed']))  { $av_class = 'ok';   $av_msg = $TEXT['avatar_removed']    ?? 'Avatar removed.'; }
            elseif (isset($_GET['avatar_error']))    {
                $av_class = 'warn';
                $av_msg = match ($_GET['avatar_error']) {
                    'csrf'    => $TEXT['avatar_err_csrf']  ?? 'Session expired. Please try again.',
                    'no_file' => $TEXT['avatar_err_nofile']?? 'Please choose an image.',
                    'size'    => $TEXT['avatar_err_size']  ?? 'Image too large (max 2 MB).',
                    'type'    => $TEXT['avatar_err_type']  ?? 'Unsupported image type. Use jpg, png, webp, or gif.',
                    'server'  => $TEXT['avatar_err_server']?? 'Something went wrong, please try again.',
                    default   => $TEXT['avatar_err_server']?? 'Something went wrong, please try again.',
                };
            }
            if ($av_msg !== ''): ?>
                <div class="avatar-toast <?= htmlspecialchars($av_class) ?>">
                    <i class="bi bi-<?= $av_class === 'ok' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                    <?= htmlspecialchars($av_msg) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Avatar upload modal -->
<div class="av-modal-back" id="avatarModal" onclick="if(event.target===this)closeAvatarModal()">
    <div class="av-modal">
        <button type="button" class="av-close" onclick="closeAvatarModal()" aria-label="Close">&times;</button>
        <h3><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($TEXT['avatar_modal_title'] ?? 'Your Avatar') ?></h3>

        <div class="av-current">
            <?= render_avatar($user['username'] ?? 'A', $user_avatar ?? null, 72) ?>
            <div>
                <div style="color:var(--accent);font-weight:600"><?= htmlspecialchars($user['username'] ?? '') ?></div>
                <div class="av-hint" style="font-size:.8rem">
                    <?php if ($user_avatar): ?>
                        <?= htmlspecialchars($TEXT['avatar_current'] ?? 'Custom avatar') ?>
                    <?php else: ?>
                        <?= htmlspecialchars($TEXT['avatar_default'] ?? 'Default initials') ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="post" action="/avatar_upload" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
            <input type="hidden" name="action" value="upload">
            <label for="avatarFile" class="av-hint d-block mb-2">
                <?= htmlspecialchars($TEXT['avatar_upload_hint'] ?? 'Pick an image (jpg, png, webp or gif, max 2 MB). It will be cropped to a circle.') ?>
            </label>
            <input id="avatarFile" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required>
            <div class="av-actions">
                <button type="submit" class="av-btn av-btn-primary"><i class="bi bi-upload me-1"></i><?= htmlspecialchars($TEXT['avatar_upload_btn'] ?? 'Upload') ?></button>
                <?php if ($user_avatar): ?>
                    <button type="submit" class="av-btn av-btn-danger" formaction="/avatar_upload" formnovalidate
                            onclick="this.form.elements.action.value='delete';this.form.image.removeAttribute('required');this.form.image.value='';">
                        <i class="bi bi-trash me-1"></i><?= htmlspecialchars($TEXT['avatar_remove_btn'] ?? 'Remove') ?>
                    </button>
                <?php endif; ?>
                <button type="button" class="av-btn" onclick="closeAvatarModal()"><?= htmlspecialchars($TEXT['common_cancel'] ?? 'Cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openAvatarModal()  { document.getElementById('avatarModal').classList.add('show'); document.body.style.overflow='hidden'; }
function closeAvatarModal() { document.getElementById('avatarModal').classList.remove('show'); document.body.style.overflow=''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAvatarModal(); });
</script>

<!-- ═══════════════ DASHBOARD TABS ═══════════════ -->
<style>
.dash-tabs { border-bottom: 1px solid rgba(255,255,255,.08); margin-bottom: 1.4rem; gap:.3rem; flex-wrap:nowrap; overflow-x:auto; }
.dash-tabs .nav-link { color:#8899aa; background:transparent; border:0; border-bottom: 2px solid transparent; border-radius: 0; padding: .65rem 1.1rem; font-weight: 600; font-size: .92rem; white-space:nowrap; }
.dash-tabs .nav-link:hover { color: #dee2e6; }
.dash-tabs .nav-link.active { color: var(--accent); border-bottom-color: var(--accent); background: transparent; }
.dash-tabs .nav-link i { font-size: 1rem; vertical-align: -.05em; }
@media (max-width: 480px) {
    .dash-tabs .nav-link { padding: .6rem .75rem; font-size: .85rem; }
    .dash-tabs .nav-link span.lbl { display: none; }
    .dash-tabs .nav-link i { font-size: 1.1rem; }
}
.tab-pane.fade.show.active { animation: dashTabFade .25s ease-out; }
@keyframes dashTabFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
</style>
<ul class="nav dash-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-overview"   role="tab"><i class="bi bi-grid-1x2-fill me-2"></i><span class="lbl"><?= htmlspecialchars($TEXT['dash_tab_overview'] ?? 'Overview') ?></span></a></li>
    <li class="nav-item"><a class="nav-link"        data-bs-toggle="tab" href="#tab-characters" role="tab"><i class="bi bi-people-fill me-2"></i><span class="lbl"><?= htmlspecialchars($TEXT['dash_tab_characters'] ?? 'Characters') ?></span></a></li>
    <li class="nav-item"><a class="nav-link"        data-bs-toggle="tab" href="#tab-account"    role="tab"><i class="bi bi-shield-lock-fill me-2"></i><span class="lbl"><?= htmlspecialchars($TEXT['dash_tab_account'] ?? 'Account & Security') ?></span></a></li>
</ul>

<div class="tab-content">

<!-- ═══════════════ TAB 1 — OVERVIEW ═══════════════ -->
<div class="tab-pane fade show active" id="tab-overview" role="tabpanel">

<!-- STAT CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="stat-card">
            <div class="stat-icon">⏱️</div>
            <div class="stat-value"><?= format_playtime($total_playtime_seconds) ?></div>
            <div class="stat-label"><?= $TEXT['total_playtime'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?= format_gold($total_gold) ?></div>
            <div class="stat-label"><?= $TEXT['total_gold'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card stat-card-bp">
            <div class="stat-icon">💎</div>
            <div class="stat-value"><?= number_format((int)($user['dp'] ?? 0)) ?></div>
            <div class="stat-label"><?= htmlspecialchars($TEXT['dash_battle_pay'] ?? 'Battle Pay') ?></div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card">
            <div class="stat-icon">⚔️</div>
            <div class="stat-value"><?= count($characters) ?></div>
            <div class="stat-label"><?= $TEXT['your_characters'] ?></div>
        </div>
    </div>
    <div class="col-12 col-md">
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <?php if ($most_played_char): ?>
                <div class="stat-value" style="font-size:1.05rem;color:<?= $class_colors[(int)$most_played_char['class']] ?? 'var(--accent)' ?>">
                    <?= htmlspecialchars($most_played_char['name']) ?>
                </div>
            <?php else: ?>
                <div class="stat-value" style="font-size:1rem">—</div>
            <?php endif; ?>
            <div class="stat-label"><?= $TEXT['most_played_char'] ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════ PLAYTIME REWARD PANEL ═══════════════ -->
<?php if ($pr_status['enabled']): ?>
<section id="playtime-reward" class="pr-panel mb-4">
    <?php if ($pr_just_claimed > 0): ?>
        <div class="pr-toast pr-toast-success">
            <i class="bi bi-check-circle-fill me-2"></i>
            +<?= number_format($pr_just_claimed) ?> <?= htmlspecialchars($TEXT['dash_battle_pay'] ?? 'Battle Pay') ?> <?= htmlspecialchars($TEXT['pr_just_claimed_suffix'] ?? 'claimed!') ?>
        </div>
    <?php elseif ($pr_claim_error): ?>
        <div class="pr-toast pr-toast-error">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= htmlspecialchars($TEXT['error_db'] ?? 'Something went wrong, please try again.') ?>
        </div>
    <?php endif; ?>

    <div class="pr-grid">
        <div class="pr-head">
            <div class="pr-head-title">
                <i class="bi bi-controller me-2"></i><?= htmlspecialchars($TEXT['pr_panel_title'] ?? 'Playtime Reward') ?>
            </div>
            <div class="pr-head-rate">
                <?= sprintf(htmlspecialchars($TEXT['pr_rate_info'] ?? 'Earn %d Battle Pay per hour played · %d daily cap'),
                            (int)$pr_status['rate_per_hour'], (int)$pr_status['daily_cap_dp']) ?>
            </div>
        </div>

        <div class="pr-stats">
            <div class="pr-stat">
                <div class="pr-stat-lbl"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($TEXT['pr_total_played'] ?? 'Total Played') ?></div>
                <div class="pr-stat-val"><?= htmlspecialchars(format_playtime((int)$pr_status['total_played_seconds'])) ?></div>
            </div>
            <div class="pr-stat">
                <div class="pr-stat-lbl"><i class="bi bi-coin me-1"></i><?= htmlspecialchars($TEXT['pr_earned_to_date'] ?? 'Earned to Date') ?></div>
                <div class="pr-stat-val"><?= number_format((int)$pr_status['total_paid_dp']) ?></div>
            </div>
            <div class="pr-stat">
                <div class="pr-stat-lbl"><i class="bi bi-gem me-1"></i><?= htmlspecialchars($TEXT['pr_available_now'] ?? 'Available Now') ?></div>
                <div class="pr-stat-val pr-available <?= $pr_status['available_dp'] > 0 ? 'pr-glow' : '' ?>"><?= number_format((int)$pr_status['available_dp']) ?></div>
            </div>
        </div>

        <div class="pr-action">
            <?php if ($pr_status['available_dp'] > 0): ?>
                <form method="POST" action="/dashboard">
                    <input type="hidden" name="action" value="claim_playtime">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <button type="submit" class="pr-claim-btn">
                        <i class="bi bi-gift-fill me-2"></i>
                        <?= sprintf(htmlspecialchars($TEXT['pr_claim_button'] ?? 'Claim %d Battle Pay'), (int)$pr_status['available_dp']) ?>
                    </button>
                </form>
            <?php elseif ($pr_status['cap_reached']): ?>
                <div class="pr-action-msg">
                    <i class="bi bi-hourglass-split me-2"></i>
                    <?= sprintf(htmlspecialchars($TEXT['pr_daily_cap_reached'] ?? 'Daily cap reached. Resets in %s.'),
                                pr_format_duration((int)$pr_status['seconds_to_reset'])) ?>
                </div>
            <?php elseif ($pr_status['total_played_seconds'] === 0): ?>
                <div class="pr-action-msg">
                    <i class="bi bi-controller me-2"></i>
                    <?= htmlspecialchars($TEXT['pr_no_playtime'] ?? 'Log into the game to start earning Battle Pay.') ?>
                </div>
            <?php else: ?>
                <div class="pr-action-msg">
                    <i class="bi bi-stopwatch me-2"></i>
                    <?= sprintf(htmlspecialchars($TEXT['pr_next_reward'] ?? 'Next reward in %s of play.'),
                                pr_format_duration((int)$pr_status['seconds_to_next_dp'])) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Daily cap progress -->
        <div class="pr-progress-wrap">
            <div class="pr-progress-track">
                <div class="pr-progress-fill" style="width: <?= $pr_status['daily_cap_dp'] > 0 ? min(100, round(($pr_status['today_claimed_dp'] / $pr_status['daily_cap_dp']) * 100)) : 0 ?>%"></div>
            </div>
            <div class="pr-progress-lbl">
                <?= sprintf(htmlspecialchars($TEXT['pr_daily_progress'] ?? '%d / %d daily cap'),
                            (int)$pr_status['today_claimed_dp'], (int)$pr_status['daily_cap_dp']) ?>
            </div>
        </div>

        <!-- History (collapsible) -->
        <details class="pr-history">
            <summary><i class="bi bi-clock-history me-1"></i><?= htmlspecialchars($TEXT['pr_history_title'] ?? 'Recent claims') ?></summary>
            <?php if (empty($pr_history)): ?>
                <div class="pr-history-empty"><?= htmlspecialchars($TEXT['pr_no_history'] ?? 'No claims yet.') ?></div>
            <?php else: ?>
                <table class="pr-history-tbl">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars($TEXT['pr_hist_date'] ?? 'Date') ?></th>
                            <th><?= htmlspecialchars($TEXT['pr_hist_dp'] ?? 'Earned') ?></th>
                            <th><?= htmlspecialchars($TEXT['pr_hist_for'] ?? 'For') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pr_history as $h): ?>
                        <tr>
                            <td><?= date('Y-m-d H:i', strtotime($h['created_at'])) ?></td>
                            <td class="pr-history-amt">+<?= number_format((int)$h['dp_amount']) ?></td>
                            <td><?= htmlspecialchars(pr_format_duration((int)$h['seconds_claimed'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </details>
    </div>
</section>
<?php endif; ?>

<!-- Most-played callout (Overview) — moved out of Quick Actions. -->
<?php if ($most_played_char): ?>
<div class="dash-panel mb-4" style="display:flex;align-items:center;gap:.85rem;background:rgba(var(--btn-bg-rgb), .12);border:1px solid rgba(var(--btn-bg-rgb), .3);">
    <i class="bi bi-star-fill" style="font-size:1.4rem;color:var(--accent)"></i>
    <div style="flex:1;min-width:0">
        <div style="font-size:.7rem;color:#8899aa;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:.15rem">
            <?= htmlspecialchars($TEXT['dash_most_time_on'] ?? 'Most time on') ?>
        </div>
        <a href="/armory/<?= rawurlencode($most_played_char['name']) ?>" style="font-weight:700;text-decoration:none;color:<?= $class_colors[(int)$most_played_char['class']] ?? 'var(--accent)' ?>">
            <?= htmlspecialchars($most_played_char['name']) ?>
        </a>
        <span style="color:#8899aa;font-size:.88rem">· <?= format_playtime((int)$most_played_char['totaltime']) ?></span>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <!-- Playtime distribution chart -->
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-pie-chart me-2"></i><?= $TEXT['playtime_distribution_title'] ?></div>
            <?php if (!empty($chart_data)): ?>
                <canvas id="playtimePieChart"></canvas>
            <?php else: ?>
                <div class="d-flex flex-column align-items-center justify-content-center" style="min-height:160px;color:#8899aa">
                    <i class="bi bi-bar-chart-line" style="font-size:2.5rem;opacity:.3"></i>
                    <p class="mt-2 mb-0" style="font-size:.9rem"><?= $TEXT['no_char_data_found'] ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Vote & Support -->
    <div class="col-lg-6">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-trophy me-2"></i><?= htmlspecialchars($TEXT['dash_vote_support_us'] ?? 'Vote & Support Us') ?></div>
            <?php $vote_sites = function_exists('settings_get') ? settings_get($pdo_auth ?? null, $config)['vote_sites'] : ($config['vote_sites'] ?? []); ?>
            <?php if (!empty($vote_sites)): ?>
                <?php foreach ($vote_sites as $site): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.7rem;margin-bottom:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px">
                    <div>
                        <div style="font-weight:600;color:#e2e8f0;font-size:.92rem"><?= htmlspecialchars($site['name'] ?? 'Vote Site') ?></div>
                        <div style="color:#4a5568;font-size:.72rem"><?= htmlspecialchars($TEXT['dash_cooldown_label'] ?? 'Cooldown') ?>: <?= $site['cooldown_hours'] ?? 12 ?>h</div>
                    </div>
                    <a href="<?= htmlspecialchars($site['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .9rem;border-radius:8px;font-weight:600;font-size:.82rem;text-decoration:none;background:linear-gradient(135deg,var(--btn-bg),var(--btn-bg-hover));color:#fff">
                        <i class="bi bi-box-arrow-up-right"></i> <?= htmlspecialchars($TEXT['dash_vote_button'] ?? 'Vote') ?>
                    </a>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center;padding:1.5rem 1rem">
                    <div style="font-size:2.2rem;opacity:.3;margin-bottom:.4rem">🗳️</div>
                    <p style="color:#8899aa;font-size:.9rem;margin:0"><?= htmlspecialchars($TEXT['dash_vote_coming_soon'] ?? 'Vote sites coming soon!') ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- /tab-overview -->

<!-- ═══════════════ TAB 2 — CHARACTERS ═══════════════ -->
<div class="tab-pane fade" id="tab-characters" role="tabpanel">
    <div class="dash-panel">
        <div class="panel-title"><i class="bi bi-people me-2"></i><?= $TEXT['your_characters'] ?></div>

            <?php if ($error_loading_chars): ?>
                <div class="alert alert-warning py-2"><?= $TEXT['error_loading_characters'] ?></div>
            <?php elseif (!empty($characters)): ?>
                <div class="d-flex flex-column gap-2">
                <?php foreach ($characters as $char):
                    $cls    = (int)$char['class'];
                    $clr    = $class_colors[$cls] ?? 'var(--accent)';
                    $guid   = (int)$char['guid'];
                    $ach    = $achievement_counts[$guid] ?? null;
                    $online = (int)$char['online'] === 1;
                ?>
                    <a class="char-card" href="/armory/<?= rawurlencode($char['name']) ?>" style="border-left-color:<?= $clr ?>" title="<?= htmlspecialchars($TEXT['armory'] ?? 'Armory') ?>: <?= htmlspecialchars($char['name']) ?>">
                        <div class="char-icons">
                            <img src="<?= get_race_icon_path((int)$char['race'], (int)$char['gender']) ?>"
                                 title="<?= htmlspecialchars(get_race_name((int)$char['race'])) ?>">
                            <img src="<?= get_class_icon_path($cls) ?>"
                                 title="<?= htmlspecialchars(get_class_name($cls)) ?>">
                        </div>
                        <div class="char-info">
                            <div class="char-name" style="color:<?= $clr ?>">
                                <?php if ($online): ?><span class="char-online-dot" title="<?= htmlspecialchars($TEXT['status_online'] ?? 'Online') ?>"></span><?php endif; ?>
                                <?= htmlspecialchars($char['name']) ?>
                            </div>
                            <div class="char-meta">
                                <?= htmlspecialchars(get_race_name((int)$char['race'])) ?> &middot;
                                <?= htmlspecialchars(get_class_name($cls)) ?>
                                <?php if (!empty($char['guild_name'])): ?>
                                    &middot; <i class="bi bi-people-fill"></i> <?= htmlspecialchars($char['guild_name']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="char-meta mt-1 d-flex flex-wrap gap-2 align-items-center">
                                <span>⏱ <?= format_playtime((int)$char['totaltime']) ?></span>
                                <span>💰 <?= format_gold((int)$char['money']) ?></span>
                                <?php if ($ach !== null): ?>
                                    <span class="achiev-badge">🏆 <?= number_format($ach) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="char-stats">
                            <div class="char-level-badge">Lv <?= htmlspecialchars($char['level']) ?></div>
                            <div class="char-meta mt-1">
                                <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($char['zone']) ?>
                            </div>
                            <?php if ($char['logout_time']): ?>
                            <div class="char-meta mt-1">
                                <i class="bi bi-clock-history"></i> <?= date('M d', (int)$char['logout_time']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4" style="color:#8899aa">
                    <i class="bi bi-person-dash" style="font-size:2.5rem;opacity:.3"></i>
                    <p class="mt-2"><?= sprintf($TEXT['no_characters_found_realm'], htmlspecialchars($TEXT['realm1_name'])) ?></p>
                </div>
            <?php endif; ?>
    </div>
</div><!-- /tab-characters -->

<!-- ═══════════════ TAB 3 — ACCOUNT & SECURITY ═══════════════ -->
<?php $twofa_on = wl_2fa_is_enabled($pdo_auth, (int)$_SESSION['user_id']); ?>
<div class="tab-pane fade" id="tab-account" role="tabpanel">

    <div class="row g-3 mb-3">
        <!-- Account info -->
        <div class="col-lg-6">
            <div class="dash-panel h-100">
                <div class="panel-title"><i class="bi bi-person-badge me-2"></i><?= $TEXT['account_details'] ?></div>
                <div class="info-row">
                    <span class="info-key"><?= htmlspecialchars($TEXT['dash_account_id'] ?? 'Account ID') ?></span>
                    <span class="info-val"><?= (int)$user_id ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key"><?= $TEXT['username'] ?></span>
                    <span class="info-val"><?= htmlspecialchars($user['username']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key"><?= $TEXT['email'] ?></span>
                    <span class="info-val" style="font-size:.82rem;display:flex;align-items:center;gap:.5rem;justify-content:flex-end">
                        <?= htmlspecialchars($user['email']) ?>
                        <a href="/change_email" class="btn btn-sm btn-outline-secondary" style="font-size:.7rem;padding:.15rem .55rem"><?= htmlspecialchars($TEXT['change_email_short'] ?? 'Change') ?></a>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-key"><?= $TEXT['join_date'] ?></span>
                    <span class="info-val"><?= date('Y-m-d', strtotime($user['joindate'])) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key"><?= htmlspecialchars($TEXT['dash_current_ip'] ?? 'Current IP') ?></span>
                    <span class="info-val" style="color:#5dd87c;font-family:monospace;font-size:.82rem"><?= htmlspecialchars($user['last_ip'] ?? '—') ?></span>
                </div>
            </div>
        </div>

        <!-- Security actions -->
        <div class="col-lg-6">
            <div class="dash-panel h-100">
                <div class="panel-title"><i class="bi bi-shield-lock me-2"></i><?= htmlspecialchars($TEXT['dash_security_title'] ?? 'Security') ?></div>
                <div class="d-flex flex-column gap-2">
                    <a href="/change_password" class="action-btn action-btn-primary">
                        <i class="bi bi-key-fill"></i> <?= $TEXT['change_password'] ?>
                    </a>
                    <a href="/change_email" class="action-btn action-btn-secondary">
                        <i class="bi bi-envelope-at"></i> <?= htmlspecialchars($TEXT['change_email_title'] ?? 'Change email address') ?>
                    </a>
                    <a href="/account_2fa" class="action-btn action-btn-secondary" style="<?= $twofa_on ? 'color:#5dd87c;border-color:rgba(93,216,124,.4)' : '' ?>">
                        <i class="bi <?= $twofa_on ? 'bi-shield-check' : 'bi-shield-plus' ?>"></i>
                        <?php if ($twofa_on): ?>
                            <?= htmlspecialchars($TEXT['twofa_dash_on'] ?? '2FA · enabled') ?>
                        <?php else: ?>
                            <?= htmlspecialchars($TEXT['twofa_dash_off'] ?? 'Enable 2FA') ?>
                        <?php endif; ?>
                    </a>
                    <?php if ($tickets_enabled): ?>
                    <a href="/tickets" class="action-btn action-btn-secondary">
                        <i class="bi bi-ticket-perforated"></i> <?= $TEXT['submit_ticket'] ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Login history -->
    <div class="dash-panel">
        <div class="panel-title"><i class="bi bi-clock-history me-2"></i><?= htmlspecialchars($TEXT['dash_login_history'] ?? 'Login History') ?></div>
        <?php if (!empty($login_history)): ?>
            <?php foreach (array_slice($login_history, 0, 10) as $i => $entry): ?>
            <div class="login-row">
                <span class="login-idx <?= $i === 0 ? 'login-idx-0' : 'login-idx-n' ?>">
                    <?= $i === 0 ? '✓' : ($i + 1) ?>
                </span>
                <span class="login-ip"><?= htmlspecialchars($entry['ip']) ?></span>
                <span class="login-time">
                    <?php
                        $diff = time() - (int)$entry['time'];
                        if ($diff < 60)        echo htmlspecialchars($TEXT['common_just_now']  ?? 'just now');
                        elseif ($diff < 3600)  echo floor($diff/60)   . ' ' . htmlspecialchars($TEXT['common_min_ago']   ?? 'm ago');
                        elseif ($diff < 86400) echo floor($diff/3600) . ' ' . htmlspecialchars($TEXT['common_hours_ago'] ?? 'h ago');
                        else                   echo date('M d, Y', (int)$entry['time']);
                    ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if ($i === 0): ?>
                <p class="mt-2 mb-0" style="font-size:.78rem;color:#8899aa;">
                    <i class="bi bi-info-circle me-1"></i> <?= htmlspecialchars($TEXT['dash_login_history_hint'] ?? 'History is recorded from your next login.') ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-3" style="color:#8899aa">
                <i class="bi bi-clock" style="font-size:2rem;opacity:.3"></i>
                <p class="mt-2 mb-0" style="font-size:.85rem"><?= htmlspecialchars($TEXT['dash_no_login_history'] ?? 'No login history yet.') ?><br><?= htmlspecialchars($TEXT['dash_no_login_history_hint'] ?? 'It will appear after your next login.') ?></p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /tab-account -->

</div><!-- /tab-content -->

<!-- Persist the active tab in the URL hash so reload returns to it. -->
<script>
(function () {
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (hash && document.querySelector('.dash-tabs a[href="#' + hash + '"]')) {
        document.querySelectorAll('.dash-tabs .nav-link').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + hash);
        });
        document.querySelectorAll('.tab-content .tab-pane').forEach(function (p) {
            p.classList.toggle('show', '#' + p.id === '#' + hash);
            p.classList.toggle('active', '#' + p.id === '#' + hash);
        });
    }
    document.querySelectorAll('.dash-tabs .nav-link').forEach(function (a) {
        a.addEventListener('shown.bs.tab', function (ev) {
            history.replaceState(null, '', ev.target.getAttribute('href'));
        });
    });
})();
</script>

</div><!-- /container -->

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<!-- Chart -->
<?php if (!empty($chart_data)): ?>
<script>
(function() {
    const ctx = document.getElementById('playtimePieChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                data:            <?= json_encode($chart_data) ?>,
                backgroundColor: <?= json_encode($chart_colors_arr) ?>,
                borderColor:     'rgba(10,10,20,0.8)',
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: '#8899aa', font: { size: 11 }, padding: 10 }
                },
                tooltip: {
                    callbacks: {
                        label: function(c) {
                            let s = c.parsed;
                            let h = Math.floor(s/3600);
                            let m = Math.floor((s%3600)/60);
                            return c.label + ': ' + (h > 0 ? h+'h ' : '') + m + 'm';
                        }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>
