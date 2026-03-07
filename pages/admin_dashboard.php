<?php
require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// --- Admin Access Check ---
if (!isset($_SESSION['user_id'])) { header('Location: /login'); exit; }
$_gm_check = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$_gm_check->execute(['id' => $_SESSION['user_id']]);
$_gm = (int)($_gm_check->fetchColumn() ?: 0);
if ($_gm < 9) { header('Location: /dashboard'); exit; }
$_SESSION['gm_level'] = $_gm;

require_once __DIR__ . '/../templates/header.php';

$errors     = [];
$admin_data = [];

// ─── Auth stats ──────────────────────────────────────────────────────────────
try {
    $row = $pdo_auth->query(
        "SELECT COUNT(*) as total,
                SUM(joindate >= NOW() - INTERVAL 1 DAY)  as d1,
                SUM(joindate >= NOW() - INTERVAL 7 DAY)  as d7,
                SUM(joindate >= NOW() - INTERVAL 30 DAY) as d30
         FROM account"
    )->fetch();
    $admin_data['total_accounts']   = (int)$row['total'];
    $admin_data['new_24h']          = (int)$row['d1'];
    $admin_data['new_7d']           = (int)$row['d7'];
    $admin_data['new_30d']          = (int)$row['d30'];
    $admin_data['online_accounts']  = (int)$pdo_auth->query("SELECT COUNT(*) FROM account WHERE online = 1")->fetchColumn();
    $admin_data['banned_accounts']  = (int)$pdo_auth->query("SELECT COUNT(DISTINCT id) FROM account_banned WHERE active = 1")->fetchColumn();

    // Registration chart – 14 days
    $reg_rows = $pdo_auth->query(
        "SELECT DATE(joindate) as d, COUNT(*) as n
         FROM account WHERE joindate >= NOW() - INTERVAL 14 DAY
         GROUP BY DATE(joindate) ORDER BY d ASC"
    )->fetchAll();
    $reg_map = [];
    foreach ($reg_rows as $r) $reg_map[$r['d']] = (int)$r['n'];
    $reg_labels = $reg_vals = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $reg_labels[] = date('M d', strtotime($d));
        $reg_vals[]   = $reg_map[$d] ?? 0;
    }

    // Accounts table (real players only for main list, bots separately)
    $all_accounts = $pdo_auth->query(
        "SELECT a.id, a.username, a.email, a.joindate, a.last_ip, a.online,
                (SELECT 1 FROM account_banned ab WHERE ab.id=a.id AND ab.active=1 LIMIT 1) as is_banned,
                (SELECT MAX(gmlevel) FROM account_access aa WHERE aa.id=a.id) as gmlevel
         FROM account a ORDER BY a.joindate DESC LIMIT 500"
    )->fetchAll();

    // Separate bots from real players
    $real_accounts = [];
    $bot_accounts  = [];
    $bot_count     = 0;
    foreach ($all_accounts as $acc) {
        if (preg_match('/^BOT\d+$/i', $acc['username'])) {
            $bot_accounts[] = $acc;
            $bot_count++;
        } else {
            $real_accounts[] = $acc;
        }
    }

    // Recent bans
    $admin_data['recent_bans'] = $pdo_auth->query(
        "SELECT a.username, b.bandate, b.bannedby, b.banreason
         FROM account_banned b JOIN account a ON a.id=b.id
         WHERE b.active=1 ORDER BY b.bandate DESC LIMIT 8"
    )->fetchAll();

} catch (PDOException $e) {
    error_log("Admin DB: " . $e->getMessage());
    $errors[] = 'Error loading account data.';
    $real_accounts = $bot_accounts = [];
    $bot_count = 0;
}

// ─── Characters ──────────────────────────────────────────────────────────────
$class_colors = [
    1=>'#C79C6E',2=>'#F58CBA',3=>'#ABD473',4=>'#FFF569',5=>'#FFFFFF',
    6=>'#C41F3B',7=>'#0070DE',8=>'#69CCF0',9=>'#9482C9',10=>'#00FF96',11=>'#FF7D0A',
];
$admin_data += ['total_characters'=>0,'online_characters'=>0,'recent_characters'=>[],'top_characters'=>[],'class_dist'=>[]];

if ($pdo_chars) {
    try {
        $admin_data['total_characters']  = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters")->fetchColumn();
        $admin_data['online_characters'] = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters WHERE online=1")->fetchColumn();
        $admin_data['recent_characters'] = $pdo_chars->query("SELECT name,level,race,class,logout_time,gender FROM characters ORDER BY logout_time DESC LIMIT 8")->fetchAll();
        $admin_data['top_characters']    = $pdo_chars->query("SELECT name,level,race,class,gender,totaltime FROM characters ORDER BY level DESC,totaltime DESC LIMIT 8")->fetchAll();
        $admin_data['class_dist']        = $pdo_chars->query("SELECT class,COUNT(*) as cnt FROM characters GROUP BY class ORDER BY cnt DESC")->fetchAll();
    } catch (PDOException $e) {
        error_log("Admin Chars DB: " . $e->getMessage());
    }
}

// ─── Server ───────────────────────────────────────────────────────────────────
$db_host    = $config['db']['host']         ?? '127.0.0.1';
$realm_name = $config['realm']['name']      ?? 'WoW Server';
$auth_port  = $config['realm']['auth_port'] ?? 3724;
$world_port = $config['realm']['world_port']?? 8085;
$s_auth     = check_port_status($db_host, $auth_port);
$s_world    = check_port_status($db_host, $world_port);
?>

<style>
.admin-wrap { padding-top: 90px; padding-bottom: 3rem; }

/* Hero */
.admin-hero {
    background: linear-gradient(135deg, rgba(139,69,19,.35), rgba(10,10,20,.92)),
                url('/assets/img/wow-bg/4-1.webp') center/cover no-repeat;
    border: 1px solid rgba(139,69,19,.4); border-radius: 16px;
    padding: 1.8rem 2rem; display: flex; align-items: center;
    justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem;
}
.admin-hero h1 {
    font-size: 1.6rem; font-weight: 700; letter-spacing: 2px;
    background: linear-gradient(90deg, #c8a96e, #fff 60%, #c8a96e);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text; margin: 0;
}
#liveClock { color: #c8a96e; font-size: .95rem; font-weight: 700; letter-spacing: 1px; }

/* Tabs */
.adm-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(139,69,19,.25); padding-bottom: 0; }
.adm-tab {
    padding: .65rem 1.4rem; border-radius: 10px 10px 0 0; font-size: .85rem; font-weight: 600;
    color: #8899aa; background: transparent; border: none; cursor: pointer;
    border: 1px solid transparent; border-bottom: none; transition: all .2s; letter-spacing: .4px;
}
.adm-tab:hover { color: #c8a96e; }
.adm-tab.active { background: linear-gradient(145deg, #1a1a2e, #12121f); color: #c8a96e; border-color: rgba(139,69,19,.3); }
.adm-tab-content { display: none; }
.adm-tab-content.active { display: block; }

/* Stat cards */
.astat-card {
    background: linear-gradient(145deg, #1a1a2e, #16213e);
    border: 1px solid rgba(139,69,19,.3); border-radius: 14px;
    padding: 1.2rem 1.4rem; transition: transform .2s, border-color .2s; height: 100%;
}
.astat-card:hover { transform: translateY(-3px); border-color: rgba(200,169,110,.5); }
.astat-card .val { font-size: 1.9rem; font-weight: 700; color: #c8a96e; line-height: 1; }
.astat-card .lbl { font-size: .7rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px; margin-top: .25rem; }
.astat-card .sub { font-size: .7rem; color: #4a5568; margin-top: .15rem; }

/* Panel */
.admin-panel { background: linear-gradient(145deg, #12121f, #1a1a2e); border: 1px solid rgba(139,69,19,.25); border-radius: 14px; padding: 1.4rem 1.6rem; height: 100%; }
.panel-title { font-size: .7rem; color: #c8a96e; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 1px solid rgba(139,69,19,.2); }

/* Server badges */
.srv-badge { display: flex; align-items: center; gap: .75rem; padding: .7rem .9rem; border-radius: 10px; border: 1px solid rgba(255,255,255,.07); background: rgba(255,255,255,.03); margin-bottom: .6rem; }
.srv-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.on-dot  { background: #28a745; box-shadow: 0 0 8px #28a745; }
.off-dot { background: #dc3545; box-shadow: 0 0 8px #dc3545; }
.srv-name { font-weight: 600; font-size: .88rem; color: #e2e8f0; flex: 1; }
.srv-port { font-size: .72rem; color: #4a5568; }
.srv-st.on  { font-size: .75rem; font-weight: 700; color: #5dd87c; }
.srv-st.off { font-size: .75rem; font-weight: 700; color: #f87e8a; }

/* Accounts Table - IMPROVED */
.acc-filters {
    display: grid; grid-template-columns: 1fr auto auto auto;
    gap: .6rem; align-items: end; margin-bottom: 1rem;
}
@media(max-width:768px) { .acc-filters { grid-template-columns: 1fr 1fr; } }
.acc-filters label { font-size: .68rem; color: #8899aa; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: .25rem; }
.acc-filter-input {
    width: 100%; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
    color: #e2e8f0; border-radius: 8px; padding: .5rem .85rem; font-size: .85rem; outline: none; box-sizing: border-box;
}
.acc-filter-input:focus { border-color: rgba(200,169,110,.4); background: rgba(255,255,255,.09); }
.acc-filter-input option { background: #1a1a2e; }

/* Toggle bot button */
.toggle-bot-btn {
    padding: .45rem 1rem; border-radius: 8px; font-size: .8rem; font-weight: 600; cursor: pointer;
    border: 1px solid rgba(251,191,36,.3); background: rgba(251,191,36,.08); color: #fbbf24;
    transition: all .2s; white-space: nowrap;
}
.toggle-bot-btn:hover { background: rgba(251,191,36,.18); }
.toggle-bot-btn.showing-bots { border-color: rgba(139,69,19,.4); background: rgba(139,69,19,.12); color: #c8a96e; }

/* Accounts table */
.acct-tbl { width: 100%; border-collapse: collapse; font-size: .84rem; }
.acct-tbl thead th {
    color: #c8a96e; font-size: .68rem; text-transform: uppercase; letter-spacing: 1px;
    font-weight: 700; padding: .6rem .8rem; border-bottom: 1px solid rgba(139,69,19,.3);
    background: rgba(139,69,19,.08); white-space: nowrap; text-align: left;
}
.acct-tbl tbody td { padding: .6rem .8rem; border-bottom: 1px solid rgba(255,255,255,.04); vertical-align: middle; color: #c0cce0; }
.acct-tbl tbody tr:last-child td { border-bottom: none; }
.acct-tbl tbody tr:hover td { background: rgba(255,255,255,.03); }

/* Row types */
.row-player { }
.row-bot td { color: #6b7280 !important; }
.row-banned td:first-child { color: #f87e8a !important; }
.row-gm td:first-child { color: #c8a96e !important; }

/* Badges */
.badge-bot    { display: inline-block; font-size: .63rem; font-weight: 700; padding: .1rem .4rem; border-radius: 4px; background: rgba(251,191,36,.1); color: #fbbf24; border: 1px solid rgba(251,191,36,.2); margin-left: 4px; vertical-align: middle; }
.badge-ban    { display: inline-block; font-size: .63rem; font-weight: 700; padding: .1rem .4rem; border-radius: 4px; background: rgba(220,53,69,.15); color: #f87e8a; border: 1px solid rgba(220,53,69,.25); margin-left: 4px; vertical-align: middle; }
.badge-gm     { display: inline-block; font-size: .63rem; font-weight: 700; padding: .1rem .4rem; border-radius: 4px; background: rgba(139,69,19,.25); color: #c8a96e; border: 1px solid rgba(200,169,110,.3); margin-left: 4px; vertical-align: middle; }
.online-pill  { display: inline-flex; align-items: center; gap: 4px; font-size: .78rem; }
.dot          { width: 7px; height: 7px; border-radius: 50%; }
.dot-on       { background: #5dd87c; box-shadow: 0 0 5px #5dd87c; }
.dot-off      { background: #374151; }
.ip-susp      { color: #fbbf24; font-weight: 600; }
.ip-local     { color: #4a5568; }

/* Pagination */
.tbl-pg { display: flex; gap: .3rem; flex-wrap: wrap; margin-top: .75rem; align-items: center; }
.tbl-pg button { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); color: #8899aa; border-radius: 6px; padding: .22rem .65rem; font-size: .78rem; cursor: pointer; transition: all .15s; min-width: 32px; }
.tbl-pg button:hover, .tbl-pg button.active { background: rgba(139,69,19,.3); border-color: rgba(200,169,110,.4); color: #c8a96e; }
.tbl-pg .pg-info { color: #4a5568; font-size: .75rem; margin-left: .5rem; }

/* Char rows */
.char-row { display: flex; align-items: center; gap: .7rem; padding: .5rem 0; border-bottom: 1px solid rgba(255,255,255,.04); }
.char-row:last-child { border-bottom: none; }
.char-row img { width: 22px; height: 22px; border-radius: 3px; border: 1px solid rgba(255,255,255,.1); }
.char-lv { margin-left: auto; background: rgba(139,69,19,.3); color: #c8a96e; font-size: .72rem; font-weight: 700; padding: .1rem .45rem; border-radius: 4px; flex-shrink: 0; }

/* Ban rows */
.ban-row { padding: .5rem 0; border-bottom: 1px solid rgba(255,255,255,.04); }
.ban-row:last-child { border-bottom: none; }


/* Action buttons in accounts table */
.acc-action-btn {
    padding: .2rem .55rem; border-radius: 6px; font-size: .7rem; font-weight: 600;
    border: 1px solid; cursor: pointer; transition: all .15s; white-space: nowrap;
}
.acc-action-btn.view { color: #60a5fa; border-color: rgba(59,130,246,.3); background: rgba(59,130,246,.08); }
.acc-action-btn.view:hover { background: rgba(59,130,246,.2); }
.acc-action-btn.ban { color: #f87e8a; border-color: rgba(220,53,69,.3); background: rgba(220,53,69,.08); }
.acc-action-btn.ban:hover { background: rgba(220,53,69,.2); }
.acc-action-btn.unban { color: #5dd87c; border-color: rgba(40,167,69,.3); background: rgba(40,167,69,.08); }
.acc-action-btn.unban:hover { background: rgba(40,167,69,.2); }

/* Modal */
.admin-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 9998;
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.admin-modal-overlay.show { display: flex; }
.admin-modal {
    background: linear-gradient(145deg, #12121f, #1a1a2e); border: 1px solid rgba(139,69,19,.4);
    border-radius: 16px; max-width: 700px; width: 100%; max-height: 85vh; overflow-y: auto;
    padding: 2rem; animation: slideUp .25s ease;
}
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.admin-modal h3 { color: #c8a96e; font-size: 1.1rem; font-weight: 700; margin: 0 0 1.2rem; }
.modal-row { display: flex; justify-content: space-between; padding: .5rem 0; border-bottom: 1px solid rgba(255,255,255,.05); font-size: .88rem; }
.modal-row .mk { color: #8899aa; } .modal-row .mv { color: #e2e8f0; text-align: right; }
.modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; color: #8899aa; font-size: 1.3rem; cursor: pointer; }

/* Ticket card in admin */
.admin-ticket { background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 1.2rem; margin-bottom: .75rem; transition: all .2s; }
.admin-ticket:hover { border-color: rgba(200,169,110,.3); background: rgba(255,255,255,.05); }
.t-status { display: inline-block; padding: .12rem .5rem; border-radius: 5px; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.t-open { background: rgba(59,130,246,.12); color: #60a5fa; border: 1px solid rgba(59,130,246,.25); }
.t-in_progress { background: rgba(245,158,11,.12); color: #fbbf24; border: 1px solid rgba(245,158,11,.25); }
.t-closed { background: rgba(107,114,128,.12); color: #9ca3af; border: 1px solid rgba(107,114,128,.25); }

/* Audit log */
.audit-row { display: flex; gap: 1rem; padding: .6rem 0; border-bottom: 1px solid rgba(255,255,255,.04); font-size: .84rem; align-items: center; }
.audit-row:last-child { border-bottom: none; }
.audit-action { padding: .12rem .5rem; border-radius: 5px; font-size: .68rem; font-weight: 700; text-transform: uppercase; background: rgba(200,169,110,.12); color: #c8a96e; border: 1px solid rgba(200,169,110,.2); }

/* Tool cards */
.tool-card { background: linear-gradient(145deg, #12121f, #1a1a2e); border: 1px solid rgba(139,69,19,.25); border-radius: 14px; padding: 1.4rem; }
.tool-card .panel-title { margin-bottom: .8rem; }
.tool-input { width: 100%; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); color: #e2e8f0; border-radius: 8px; padding: .55rem .85rem; font-size: .88rem; outline: none; box-sizing: border-box; }
.tool-input:focus { border-color: rgba(200,169,110,.4); }
.tool-btn { padding: .55rem 1.2rem; border-radius: 8px; font-size: .85rem; font-weight: 600; cursor: pointer; border: none; transition: all .2s; }
.tool-btn-primary { background: linear-gradient(135deg, #8B4513, #A0522D); color: #fff; }
.tool-btn-primary:hover { background: linear-gradient(135deg, #A0522D, #c8a96e); transform: translateY(-1px); }
.tool-btn-danger { background: rgba(220,53,69,.15); color: #f87e8a; border: 1px solid rgba(220,53,69,.3); }
.tool-btn-danger:hover { background: rgba(220,53,69,.25); }
.toast-msg { position: fixed; bottom: 2rem; right: 2rem; padding: .8rem 1.5rem; border-radius: 10px; font-size: .88rem; font-weight: 600; z-index: 99999; animation: slideUp .3s ease; }
.toast-success { background: rgba(40,167,69,.9); color: #fff; }
.toast-error { background: rgba(220,53,69,.9); color: #fff; }
</style>

<div class="container admin-wrap">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3" style="border-radius:10px"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<!-- HERO -->
<div class="admin-hero">
    <div>
        <h1><i class="bi bi-shield-lock-fill me-2"></i><?= $TEXT['admin_panel_title'] ?? 'Admin Panel' ?></h1>
        <div style="color:#8899aa;font-size:.82rem;margin-top:.25rem"><?= htmlspecialchars($realm_name) ?> &nbsp;·&nbsp; PHP <?= phpversion() ?></div>
    </div>
    <div class="text-end">
        <div id="liveClock">--:--:--</div>
        <div style="color:#8899aa;font-size:.82rem"><?= date('l, F j, Y') ?></div>
        <a href="/dashboard" style="color:#c8a96e;font-size:.8rem;text-decoration:none"><i class="bi bi-arrow-left-circle me-1"></i>Dashboard</a>
    </div>
</div>

<!-- STAT CARDS -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">👥</div>
            <div class="val"><?= number_format($admin_data['total_accounts']) ?></div>
            <div class="lbl">Total Accounts</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">🟢</div>
            <div class="val" style="color:#5dd87c"><?= $admin_data['online_accounts'] ?></div>
            <div class="lbl">Online Now</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">⚔️</div>
            <div class="val"><?= number_format($admin_data['total_characters']) ?></div>
            <div class="lbl">Characters</div>
            <div class="sub"><?= $admin_data['online_characters'] ?> in-game</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">📈</div>
            <div class="val"><?= $admin_data['new_24h'] ?></div>
            <div class="lbl">New Today</div>
            <div class="sub"><?= $admin_data['new_7d'] ?> this week</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">🤖</div>
            <div class="val" style="color:#fbbf24"><?= number_format($bot_count) ?></div>
            <div class="lbl">PlayerBots</div>
            <div class="sub"><?= count($real_accounts) ?> real players</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="astat-card">
            <div style="font-size:1.5rem;margin-bottom:.3rem">🔨</div>
            <div class="val" style="color:#f87e8a"><?= $admin_data['banned_accounts'] ?></div>
            <div class="lbl">Active Bans</div>
        </div>
    </div>
</div>

<!-- TAB NAV -->
<div class="adm-tabs">
    <button class="adm-tab active" onclick="switchTab('overview',this)"><i class="bi bi-grid me-1"></i>Overview</button>
    <button class="adm-tab" onclick="switchTab('accounts',this)"><i class="bi bi-people me-1"></i>Accounts</button>
    <button class="adm-tab" onclick="switchTab('tickets',this)"><i class="bi bi-ticket-perforated me-1"></i>Tickets</button>
    <button class="adm-tab" onclick="switchTab('audit',this)"><i class="bi bi-journal-text me-1"></i>Audit Log</button>
    <button class="adm-tab" onclick="switchTab('tools',this)"><i class="bi bi-tools me-1"></i>Tools</button>

</div>

<!-- ══════════════════════════════════════════════════════ TAB: OVERVIEW -->
<div class="adm-tab-content active" id="tab-overview">
    <div class="row g-3 mb-3">
        <!-- Server Status -->
        <div class="col-lg-3">
            <div class="admin-panel">
                <div class="panel-title"><i class="bi bi-hdd-network me-2"></i>Server Status</div>
                <div class="srv-badge">
                    <div class="srv-dot <?= $s_auth ? 'on-dot' : 'off-dot' ?>"></div>
                    <span class="srv-name">Auth</span>
                    <span class="srv-port">:<?= $auth_port ?></span>
                    <span class="srv-st <?= $s_auth ? 'on' : 'off' ?>"><?= $s_auth ? 'Online' : 'Offline' ?></span>
                </div>
                <div class="srv-badge">
                    <div class="srv-dot <?= $s_world ? 'on-dot' : 'off-dot' ?>"></div>
                    <span class="srv-name">World</span>
                    <span class="srv-port">:<?= $world_port ?></span>
                    <span class="srv-st <?= $s_world ? 'on' : 'off' ?>"><?= $s_world ? 'Online' : 'Offline' ?></span>
                </div>

                <!-- Class distribution -->
                <?php if (!empty($admin_data['class_dist'])): ?>
                <div class="panel-title mt-3"><i class="bi bi-bar-chart me-2"></i>Class Distribution</div>
                <?php $total_c = max(1, $admin_data['total_characters']);
                foreach ($admin_data['class_dist'] as $cd):
                    $cls = (int)$cd['class']; $pct = round($cd['cnt'] / $total_c * 100); $clr = $class_colors[$cls] ?? '#8899aa';
                ?>
                <div style="margin-bottom:.45rem">
                    <div class="d-flex justify-content-between" style="font-size:.7rem;margin-bottom:2px">
                        <span style="color:<?= $clr ?>"><?= htmlspecialchars(get_class_name($cls)) ?></span>
                        <span style="color:#4a5568"><?= $cd['cnt'] ?></span>
                    </div>
                    <div style="height:4px;background:rgba(255,255,255,.07);border-radius:2px;overflow:hidden">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $clr ?>;border-radius:2px;transition:width 1.2s"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reg chart -->
        <div class="col-lg-5">
            <div class="admin-panel">
                <div class="panel-title"><i class="bi bi-graph-up me-2"></i>Registrations — Last 14 Days</div>
                <canvas id="regChart" height="190"></canvas>
            </div>
        </div>

        <!-- Recent Bans -->
        <div class="col-lg-4">
            <div class="admin-panel">
                <div class="panel-title"><i class="bi bi-slash-circle me-2"></i>Recent Active Bans</div>
                <?php if (!empty($admin_data['recent_bans'])): ?>
                    <?php foreach ($admin_data['recent_bans'] as $ban): ?>
                    <div class="ban-row">
                        <div class="d-flex justify-content-between">
                            <span style="color:#f87e8a;font-weight:700;font-size:.88rem"><?= htmlspecialchars($ban['username']) ?></span>
                            <span style="color:#4a5568;font-size:.7rem"><?= date('M d', strtotime($ban['bandate'])) ?></span>
                        </div>
                        <div style="font-size:.75rem;color:#8899aa"><?= htmlspecialchars($ban['banreason'] ?: '—') ?> <span style="color:#4a5568">by <?= htmlspecialchars($ban['bannedby']) ?></span></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-3" style="color:#4a5568"><i class="bi bi-check-circle" style="font-size:1.5rem"></i><p class="mt-1 mb-0" style="font-size:.85rem">No active bans</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Characters row -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="admin-panel">
                <div class="panel-title"><i class="bi bi-trophy me-2"></i>Top Characters by Level</div>
                <?php foreach ($admin_data['top_characters'] as $c):
                    $cls = (int)$c['class']; $clr = $class_colors[$cls] ?? '#c8a96e';
                ?>
                <div class="char-row">
                    <img src="<?= get_race_icon_path((int)$c['race'], (int)($c['gender']??0)) ?>">
                    <img src="<?= get_class_icon_path($cls) ?>">
                    <div><div style="font-weight:700;font-size:.88rem;color:<?= $clr ?>"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="font-size:.72rem;color:#6b7280"><?= htmlspecialchars(get_class_name($cls)) ?></div></div>
                    <div class="char-lv">Lv <?= (int)$c['level'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="admin-panel">
                <div class="panel-title"><i class="bi bi-clock-history me-2"></i>Recently Active Characters</div>
                <?php foreach ($admin_data['recent_characters'] as $c):
                    $cls = (int)$c['class']; $clr = $class_colors[$cls] ?? '#c8a96e';
                    $ago = $c['logout_time'] ? (time()-(int)$c['logout_time']) : null;
                    $ago_str = $ago === null ? 'Never' : ($ago<60?'just now':($ago<3600?floor($ago/60).'m ago':($ago<86400?floor($ago/3600).'h ago':date('M d',(int)$c['logout_time']))));
                ?>
                <div class="char-row">
                    <img src="<?= get_race_icon_path((int)$c['race'], (int)($c['gender']??0)) ?>">
                    <img src="<?= get_class_icon_path($cls) ?>">
                    <div><div style="font-weight:700;font-size:.88rem;color:<?= $clr ?>"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="font-size:.72rem;color:#6b7280">Lv <?= (int)$c['level'] ?></div></div>
                    <div style="margin-left:auto;font-size:.72rem;color:#4a5568"><?= $ago_str ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div><!-- /tab-overview -->

<!-- ══════════════════════════════════════════════════════ TAB: ACCOUNTS -->
<div class="adm-tab-content" id="tab-accounts">
    <div class="admin-panel" style="height:auto">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="panel-title mb-0"><i class="bi bi-people me-2"></i>Account List</div>
            <div class="d-flex align-items-center gap-2">
                <span style="font-size:.78rem;color:#6b7280" id="acctCountLabel"></span>
                <button class="toggle-bot-btn" id="botToggle" onclick="toggleBots()">
                    🤖 Hide Bots (<?= $bot_count ?>)
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="acc-filters">
            <div>
                <label>Search username / email</label>
                <input type="text" id="acctSearch" class="acc-filter-input" placeholder="Type to search…" oninput="filterAccts()">
            </div>
            <div>
                <label>Status</label>
                <select id="acctOnline" class="acc-filter-input" onchange="filterAccts()">
                    <option value="">All</option>
                    <option value="1">Online</option>
                    <option value="0">Offline</option>
                </select>
            </div>
            <div>
                <label>Banned</label>
                <select id="acctBanned" class="acc-filter-input" onchange="filterAccts()">
                    <option value="">All</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </div>
            <div>
                <label>Type</label>
                <select id="acctType" class="acc-filter-input" onchange="filterAccts()">
                    <option value="">All</option>
                    <option value="player">Players</option>
                    <option value="bot">Bots</option>
                    <option value="gm">GMs</option>
                </select>
            </div>
        </div>

        <div style="overflow-x:auto">
        <table class="acct-tbl" id="acctTable">
            <thead>
                <tr>
                    <th style="width:5%">#</th>
                    <th style="width:18%">Username</th>
                    <th style="width:22%">Email</th>
                    <th style="width:8%">Status</th>
                    <th style="width:12%">Joined</th>
                    <th style="width:13%">Last IP</th>
                    <th style="width:16%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Merge: real players first, then bots
            $merged_accounts = array_merge($real_accounts, $bot_accounts);
            foreach ($merged_accounts as $idx => $acc):
                $is_bot  = preg_match('/^BOT\d+$/i', $acc['username']);
                $is_ban  = (bool)$acc['is_banned'];
                $gmlv    = (int)($acc['gmlevel'] ?? 0);
                $is_gm   = $gmlv >= 1;
                $ip_loc  = ($acc['last_ip'] === '127.0.0.1' || $acc['last_ip'] === '0.0.0.0');
                $ip_susp = !$ip_loc && $is_bot; // bots w/ localhost IPs shown dimly
                $row_cls = $is_bot ? 'row-bot' : ($is_ban ? 'row-banned' : ($is_gm ? 'row-gm' : 'row-player'));
            ?>
            <tr class="<?= $row_cls ?>"
                data-q="<?= strtolower(htmlspecialchars($acc['username'].' '.$acc['email'])) ?>"
                data-online="<?= (int)$acc['online'] ?>"
                data-banned="<?= (int)$is_ban ?>"
                data-type="<?= $is_bot ? 'bot' : ($is_gm ? 'gm' : 'player') ?>">
                <td style="color:#4a5568"><?= $acc['id'] ?></td>
                <td>
                    <?= htmlspecialchars($acc['username']) ?>
                    <?php if ($is_bot): ?><span class="badge-bot">BOT</span><?php endif; ?>
                    <?php if ($is_ban): ?><span class="badge-ban">BANNED</span><?php endif; ?>
                    <?php if ($is_gm):  ?><span class="badge-gm">GM <?= $gmlv ?></span><?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:<?= $is_bot ? '#4a5568' : '#8899aa' ?>"><?= $is_bot ? '—' : htmlspecialchars($acc['email']) ?></td>
                <td>
                    <span class="online-pill">
                        <span class="dot <?= $acc['online'] ? 'dot-on' : 'dot-off' ?>"></span>
                        <span style="color:<?= $acc['online'] ? '#5dd87c' : '#6b7280' ?>;font-size:.78rem"><?= $acc['online'] ? 'Online' : 'Offline' ?></span>
                    </span>
                </td>
                <td style="font-size:.78rem;color:#6b7280"><?= date('M d, Y', strtotime($acc['joindate'])) ?></td>
                <td style="font-family:monospace;font-size:.75rem" class="<?= $ip_loc ? 'ip-local' : '' ?>"><?= htmlspecialchars($acc['last_ip']) ?></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="acc-action-btn view" onclick="event.stopPropagation();viewAccount(<?= $acc['id'] ?>)"><i class="bi bi-eye"></i></button>
                        <?php if (!$is_ban): ?>
                            <button class="acc-action-btn ban" onclick="event.stopPropagation();showBanModal(<?= $acc['id'] ?>,'<?= htmlspecialchars($acc['username'], ENT_QUOTES) ?>')"><i class="bi bi-slash-circle"></i> Ban</button>
                        <?php else: ?>
                            <button class="acc-action-btn unban" onclick="event.stopPropagation();doUnban(<?= $acc['id'] ?>)"><i class="bi bi-check-circle"></i> Unban</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="tbl-pg" id="acctPager"></div>
    </div>
</div><!-- /tab-accounts -->

<!-- ══════════════════════════════════════════════════════ TAB: TICKETS -->
<div class="adm-tab-content" id="tab-tickets">
    <div class="admin-panel" style="height:auto">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="panel-title mb-0"><i class="bi bi-ticket-perforated me-2"></i>Support Tickets</div>
            <div class="d-flex gap-2">
                <select id="ticketFilter" class="acc-filter-input" style="width:auto" onchange="loadTickets()">
                    <option value="">All</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
        </div>
        <div id="ticketList"><div class="text-center py-4" style="color:#4a5568">Loading tickets...</div></div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════ TAB: AUDIT LOG -->
<div class="adm-tab-content" id="tab-audit">
    <div class="admin-panel" style="height:auto">
        <div class="panel-title"><i class="bi bi-journal-text me-2"></i>Admin Audit Log</div>
        <div id="auditList"><div class="text-center py-4" style="color:#4a5568">Loading audit log...</div></div>
        <div class="tbl-pg" id="auditPager"></div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════ TAB: TOOLS -->
<div class="adm-tab-content" id="tab-tools">
    <div class="row g-3">
        <!-- Character Lookup -->
        <div class="col-lg-6">
            <div class="tool-card">
                <div class="panel-title"><i class="bi bi-search me-2"></i>Character Lookup</div>
                <div class="d-flex gap-2 mb-3">
                    <input type="text" id="charSearchInput" class="tool-input" placeholder="Search character name...">
                    <button class="tool-btn tool-btn-primary" onclick="searchCharacter()"><i class="bi bi-search"></i></button>
                </div>
                <div id="charResults"></div>
            </div>
        </div>

        <!-- IP Ban Management -->
        <div class="col-lg-6">
            <div class="tool-card">
                <div class="panel-title"><i class="bi bi-shield-x me-2"></i>IP Ban Management</div>
                <div class="d-flex gap-2 mb-3">
                    <input type="text" id="ipBanInput" class="tool-input" placeholder="IP address (e.g. 192.168.1.1)" style="flex:1">
                    <input type="text" id="ipBanReason" class="tool-input" placeholder="Reason" style="flex:1">
                    <button class="tool-btn tool-btn-danger" onclick="doIpBan()"><i class="bi bi-slash-circle"></i> Ban</button>
                </div>
                <div id="ipBanList"></div>
            </div>
        </div>

        <!-- Peak Players -->
        <div class="col-lg-6">
            <div class="tool-card">
                <div class="panel-title"><i class="bi bi-graph-up-arrow me-2"></i>Server Stats</div>
                <div id="peakPlayersContent"><div class="text-center py-3" style="color:#4a5568">Loading...</div></div>
            </div>
        </div>

        <!-- Email Broadcast -->
        <div class="col-lg-6">
            <div class="tool-card">
                <div class="panel-title"><i class="bi bi-envelope-at me-2"></i>Email Broadcast</div>
                <div class="mb-2">
                    <input type="text" id="broadcastSubject" class="tool-input" placeholder="Email subject...">
                </div>
                <div class="mb-2">
                    <textarea id="broadcastBody" class="tool-input" rows="4" placeholder="Email body..."></textarea>
                </div>
                <button class="tool-btn tool-btn-primary" onclick="sendBroadcast()" id="broadcastBtn">
                    <i class="bi bi-send me-1"></i> Send to All Users
                </button>
                <div style="font-size:.72rem;color:#4a5568;margin-top:.5rem"><i class="bi bi-exclamation-triangle me-1"></i>This will email ALL registered accounts. Use with care.</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════ MODALS ══════════ -->

<!-- Account Detail Modal -->
<div class="admin-modal-overlay" id="accountModal">
    <div class="admin-modal" style="position:relative">
        <button class="modal-close" onclick="closeModal('accountModal')">&times;</button>
        <h3><i class="bi bi-person-badge me-2"></i>Account Details</h3>
        <div id="accountModalBody"><div class="text-center py-3" style="color:#4a5568">Loading...</div></div>
    </div>
</div>

<!-- Ban Modal -->
<div class="admin-modal-overlay" id="banModal">
    <div class="admin-modal" style="position:relative;max-width:500px">
        <button class="modal-close" onclick="closeModal('banModal')">&times;</button>
        <h3><i class="bi bi-slash-circle me-2" style="color:#f87e8a"></i>Ban Account</h3>
        <input type="hidden" id="banAccountId">
        <div style="color:#e2e8f0;margin-bottom:1rem" id="banAccountName"></div>
        <div class="mb-3">
            <label style="font-size:.75rem;color:#8899aa;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:.3rem">Reason</label>
            <input type="text" id="banReason" class="tool-input" placeholder="Ban reason...">
        </div>
        <div class="mb-3">
            <label style="font-size:.75rem;color:#8899aa;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:.3rem">Duration</label>
            <select id="banDuration" class="tool-input">
                <option value="300">5 minutes</option>
                <option value="1800">30 minutes</option>
                <option value="3600">1 hour</option>
                <option value="10800">3 hours</option>
                <option value="21600">6 hours</option>
                <option value="43200">12 hours</option>
                <option value="86400">24 hours</option>
                <option value="259200">3 days</option>
                <option value="604800">7 days</option>
                <option value="2592000">30 days</option>
                <option value="-1">Permanent</option>
            </select>
        </div>
        <button class="tool-btn tool-btn-danger" style="width:100%" onclick="doBan()">
            <i class="bi bi-slash-circle me-1"></i> Confirm Ban
        </button>
    </div>
</div>

<!-- Ticket Reply Modal -->
<div class="admin-modal-overlay" id="ticketReplyModal">
    <div class="admin-modal" style="position:relative;max-width:600px">
        <button class="modal-close" onclick="closeModal('ticketReplyModal')">&times;</button>
        <h3><i class="bi bi-reply me-2"></i>Reply to Ticket #<span id="replyTicketId"></span></h3>
        <div id="replyTicketInfo" style="margin-bottom:1rem"></div>
        <div class="mb-3">
            <label style="font-size:.75rem;color:#8899aa;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:.3rem">Your Reply</label>
            <textarea id="ticketReplyText" class="tool-input" rows="5" placeholder="Type your reply..."></textarea>
        </div>
        <div class="mb-3">
            <label style="font-size:.75rem;color:#8899aa;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:.3rem">Set Status</label>
            <select id="ticketReplyStatus" class="tool-input">
                <option value="in_progress">In Progress</option>
                <option value="closed">Closed</option>
            </select>
        </div>
        <button class="tool-btn tool-btn-primary" style="width:100%" onclick="submitTicketReply()">
            <i class="bi bi-send me-1"></i> Send Reply
        </button>
    </div>
</div>

</div><!-- /container -->

<?php require_once __DIR__ . '/../templates/footer.php'; ?>

<script>
// ── Live clock ────────────────────────────────────────────────────────────────
(function tick(){
    const d = new Date();
    document.getElementById('liveClock').textContent =
        String(d.getHours()).padStart(2,'0')+':'+
        String(d.getMinutes()).padStart(2,'0')+':'+
        String(d.getSeconds()).padStart(2,'0');
    setTimeout(tick, 1000);
})();

// ── Tab switcher ─────────────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.adm-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.adm-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');

    if (name === 'accounts') renderAccts();
    if (name === 'tickets') loadTickets();
    if (name === 'audit') loadAuditLog(1);
    if (name === 'tools') { loadIpBans(); loadPeakPlayers(); }
}

// ── Registration chart ────────────────────────────────────────────────────────
(function(){
    const ctx = document.getElementById('regChart');
    if (!ctx || typeof Chart === 'undefined') return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($reg_labels) ?>,
            datasets: [{
                label: 'Registrations',
                data: <?= json_encode($reg_vals) ?>,
                backgroundColor: 'rgba(139,69,19,0.4)',
                borderColor: '#c8a96e',
                borderWidth: 1, borderRadius: 4,
                hoverBackgroundColor: 'rgba(200,169,110,0.5)'
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero:true, ticks:{ color:'#8899aa', stepSize:1 }, grid:{ color:'rgba(255,255,255,.05)' } },
                x: { ticks:{ color:'#8899aa', font:{size:10} }, grid:{ display:false } }
            }
        }
    });
})();



// ── Accounts Table ────────────────────────────────────────────────────────────
const PAGE_SIZE = 20;
let acctPage = 0;
let showBots = true;

function toggleBots() {
    showBots = !showBots;
    const btn = document.getElementById('botToggle');
    btn.classList.toggle('showing-bots', !showBots);
    btn.textContent = showBots
        ? '🤖 Hide Bots (<?= $bot_count ?>)'
        : '🤖 Show Bots (<?= $bot_count ?>)';
    // Also sync the type filter
    const typeFilter = document.getElementById('acctType');
    if (!showBots) { if (typeFilter.value === '') typeFilter.value = 'player'; }
    else { if (typeFilter.value === 'player') typeFilter.value = ''; }
    acctPage = 0;
    renderAccts();
}

function filterAccts() { acctPage = 0; renderAccts(); }

function visibleAcctRows() {
    const q      = document.getElementById('acctSearch').value.toLowerCase();
    const online = document.getElementById('acctOnline').value;
    const banned = document.getElementById('acctBanned').value;
    const type   = document.getElementById('acctType').value;
    return Array.from(document.querySelectorAll('#acctTable tbody tr')).filter(row => {
        if (!showBots && row.dataset.type === 'bot') return false;
        if (q      && !row.dataset.q.includes(q))       return false;
        if (online !== '' && row.dataset.online !== online) return false;
        if (banned !== '' && row.dataset.banned !== banned) return false;
        if (type   !== '' && row.dataset.type   !== type)   return false;
        return true;
    });
}

function renderAccts() {
    const rows  = visibleAcctRows();
    const total = rows.length;
    const pages = Math.ceil(total / PAGE_SIZE);
    if (acctPage >= pages) acctPage = Math.max(0, pages - 1);
    const start = acctPage * PAGE_SIZE;
    const end   = Math.min(start + PAGE_SIZE, total);
    document.querySelectorAll('#acctTable tbody tr').forEach(r => r.style.display = 'none');
    rows.slice(start, end).forEach(r => r.style.display = '');
    // label
    document.getElementById('acctCountLabel').textContent = total + ' accounts';
    // pager
    const pager = document.getElementById('acctPager');
    pager.innerHTML = '';
    const MAX_BTNS = 10;
    let startPage = Math.max(0, acctPage - Math.floor(MAX_BTNS/2));
    let endPage   = Math.min(pages, startPage + MAX_BTNS);
    if (startPage > 0) {
        const b = document.createElement('button'); b.textContent = '«';
        b.onclick = () => { acctPage = 0; renderAccts(); }; pager.appendChild(b);
    }
    for (let i = startPage; i < endPage; i++) {
        const b = document.createElement('button');
        b.textContent = i + 1;
        if (i === acctPage) b.classList.add('active');
        b.onclick = (function(pg){ return () => { acctPage = pg; renderAccts(); }; })(i);
        pager.appendChild(b);
    }
    if (endPage < pages) {
        const b = document.createElement('button'); b.textContent = '»';
        b.onclick = () => { acctPage = pages - 1; renderAccts(); }; pager.appendChild(b);
    }
    const info = document.createElement('span');
    info.className = 'pg-info'; info.textContent = start+1 + '–' + end + ' of ' + total;
    pager.appendChild(info);
}
renderAccts();

// ── Toast notification ────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = 'toast-msg toast-' + type;
    t.innerHTML = '<i class="bi bi-' + (type==='success'?'check-circle':'x-circle') + ' me-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function openModal(id) { document.getElementById(id).classList.add('show'); }

// Close modal on overlay click
document.querySelectorAll('.admin-modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

// ── API helper ────────────────────────────────────────────────────────────────
async function adminApi(action, data = {}, method = 'POST') {
    const url = method === 'GET'
        ? '/admin_api?action=' + action + '&' + new URLSearchParams(data).toString()
        : '/admin_api?action=' + action;
    const opts = { method };
    if (method === 'POST') {
        const fd = new FormData();
        Object.entries(data).forEach(([k,v]) => fd.append(k,v));
        opts.body = fd;
    }
    const res = await fetch(url, opts);
    return res.json();
}

// ── BAN / UNBAN ───────────────────────────────────────────────────────────────
function showBanModal(id, name) {
    document.getElementById('banAccountId').value = id;
    document.getElementById('banAccountName').textContent = 'Banning: ' + name;
    document.getElementById('banReason').value = '';
    openModal('banModal');
}

async function doBan() {
    const id = document.getElementById('banAccountId').value;
    const reason = document.getElementById('banReason').value || 'No reason specified';
    const duration = document.getElementById('banDuration').value;
    const r = await adminApi('ban', { account_id: id, reason, duration });
    closeModal('banModal');
    if (r.success) { showToast(r.message); setTimeout(() => location.reload(), 1200); }
    else showToast(r.error, 'error');
}

async function doUnban(id) {
    if (!confirm('Unban this account?')) return;
    const r = await adminApi('unban', { account_id: id });
    if (r.success) { showToast(r.message); setTimeout(() => location.reload(), 1200); }
    else showToast(r.error, 'error');
}

// ── ACCOUNT DETAILS ──────────────────────────────────────────────────────────
async function viewAccount(id) {
    openModal('accountModal');
    document.getElementById('accountModalBody').innerHTML = '<div class="text-center py-3" style="color:#4a5568">Loading...</div>';
    const r = await adminApi('get_account', { id }, 'GET');
    if (!r.success) { document.getElementById('accountModalBody').innerHTML = '<p style="color:#f87e8a">Error loading account</p>'; return; }
    const a = r.account;
    const banned = a.is_banned ? '<span class="badge-ban">BANNED</span>' : '<span style="color:#5dd87c">Active</span>';
    const gmBadge = a.gmlevel ? '<span class="badge-gm">GM ' + a.gmlevel + '</span>' : 'Player';
    let html = `
        <div class="modal-row"><span class="mk">ID</span><span class="mv">${a.id}</span></div>
        <div class="modal-row"><span class="mk">Username</span><span class="mv" style="color:#c8a96e;font-weight:700">${a.username}</span></div>
        <div class="modal-row"><span class="mk">Email</span><span class="mv">${a.email || '—'}</span></div>
        <div class="modal-row"><span class="mk">Status</span><span class="mv">${banned}</span></div>
        <div class="modal-row"><span class="mk">Role</span><span class="mv">${gmBadge}</span></div>
        <div class="modal-row"><span class="mk">Joined</span><span class="mv">${a.joindate || '—'}</span></div>
        <div class="modal-row"><span class="mk">Last IP</span><span class="mv" style="font-family:monospace;font-size:.82rem">${a.last_ip || '—'}</span></div>
        <div class="modal-row"><span class="mk">Online</span><span class="mv">${a.online == 1 ? '<span style="color:#5dd87c">● Online</span>' : 'Offline'}</span></div>
    `;
    // Characters
    if (r.characters && r.characters.length > 0) {
        html += '<div style="margin-top:1rem;font-size:.72rem;color:#c8a96e;text-transform:uppercase;letter-spacing:1px;font-weight:700;padding-bottom:.4rem;border-bottom:1px solid rgba(139,69,19,.2)">Characters (' + r.characters.length + ')</div>';
        r.characters.forEach(c => {
            html += '<div style="display:flex;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.85rem">';
            html += '<span style="color:#e2e8f0">' + c.name + '</span>';
            html += '<span style="color:#6b7280">Lv ' + c.level + ' &middot; ' + (c.online==1?'<span style="color:#5dd87c">Online</span>':'Offline') + '</span></div>';
        });
    }
    // Edit section
    html += `
        <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid rgba(139,69,19,.25)">
            <div style="font-size:.72rem;color:#c8a96e;text-transform:uppercase;letter-spacing:1px;font-weight:700;margin-bottom:.8rem">Quick Actions</div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="tool-btn tool-btn-primary" onclick="promptResetPassword(${a.id},'${a.username}')"><i class="bi bi-key me-1"></i>Reset Password</button>
                <button class="tool-btn tool-btn-primary" onclick="promptEditEmail(${a.id},'${a.email || ''}')"><i class="bi bi-envelope me-1"></i>Edit Email</button>
                <button class="tool-btn tool-btn-primary" onclick="promptEditGM(${a.id},${a.gmlevel||0})"><i class="bi bi-shield me-1"></i>Set GM Level</button>
            </div>
        </div>
    `;
    document.getElementById('accountModalBody').innerHTML = html;
}

async function promptResetPassword(id, name) {
    const pw = prompt('Enter new password for ' + name + ':');
    if (!pw) return;
    const r = await adminApi('reset_password', { account_id: id, new_password: pw });
    if (r.success) showToast(r.message); else showToast(r.error, 'error');
}

async function promptEditEmail(id, current) {
    const email = prompt('Enter new email (current: ' + current + '):');
    if (!email) return;
    const r = await adminApi('update_account', { account_id: id, email });
    if (r.success) { showToast(r.message); viewAccount(id); } else showToast(r.error, 'error');
}

async function promptEditGM(id, current) {
    const gm = prompt('Enter new GM level (current: ' + current + ', 0 = player):');
    if (gm === null) return;
    const r = await adminApi('update_account', { account_id: id, gmlevel: gm });
    if (r.success) { showToast(r.message); setTimeout(() => location.reload(), 1200); } else showToast(r.error, 'error');
}

// ── TICKETS ──────────────────────────────────────────────────────────────────
async function loadTickets() {
    const status = document.getElementById('ticketFilter').value;
    const r = await adminApi('get_tickets', { status }, 'GET');
    const el = document.getElementById('ticketList');
    if (!r.success || !r.tickets.length) {
        el.innerHTML = '<div class="text-center py-4" style="color:#4a5568"><i class="bi bi-ticket-perforated" style="font-size:2rem;opacity:.3"></i><p class="mt-2">No tickets found</p></div>';
        return;
    }
    el.innerHTML = r.tickets.map(t => {
        const statusCls = 't-' + t.status;
        const statusLabel = t.status === 'open' ? '● Open' : t.status === 'in_progress' ? '◐ In Progress' : '○ Closed';
        return `
        <div class="admin-ticket">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div>
                    <span class="t-status ${statusCls}">${statusLabel}</span>
                    <span style="color:#4a5568;font-size:.75rem;margin-left:.5rem">#${t.id}</span>
                    <span style="color:#8899aa;font-size:.78rem;margin-left:.5rem"><i class="bi bi-person"></i> ${t.username}</span>
                    <span style="color:#4a5568;font-size:.75rem;margin-left:.3rem">${t.email}</span>
                </div>
                <span style="color:#4a5568;font-size:.75rem;white-space:nowrap">${t.created_at}</span>
            </div>
            <div style="font-weight:600;color:#e2e8f0;font-size:.95rem;margin-bottom:.3rem">${escHtml(t.subject)}</div>
            <div style="color:#8899aa;font-size:.85rem;margin-bottom:.5rem;white-space:pre-line;max-height:100px;overflow:hidden;text-overflow:ellipsis">${escHtml(t.message)}</div>
            ${t.admin_reply ? '<div style="background:rgba(93,216,124,.06);border-left:3px solid #5dd87c;padding:.6rem .8rem;border-radius:0 6px 6px 0;font-size:.85rem;color:#e2e8f0;margin-bottom:.5rem"><strong style="color:#5dd87c;font-size:.72rem">REPLY by ' + escHtml(t.replied_by||'Admin') + ':</strong><br>' + escHtml(t.admin_reply) + '</div>' : ''}
            <div class="d-flex gap-2 mt-2">
                <button class="acc-action-btn view" onclick="openTicketReply(${t.id},'${escAttr(t.subject)}','${escAttr(t.username)}','${escAttr(t.message)}')"><i class="bi bi-reply me-1"></i>Reply</button>
                ${t.status !== 'closed' ? '<button class="acc-action-btn ban" onclick="changeTicketStatus(' + t.id + ',\x27closed\x27)"><i class="bi bi-x-circle me-1"></i>Close</button>' : ''}
                ${t.status === 'closed' ? '<button class="acc-action-btn unban" onclick="changeTicketStatus(' + t.id + ',\x27open\x27)"><i class="bi bi-arrow-counterclockwise me-1"></i>Reopen</button>' : ''}
            </div>
        </div>`;
    }).join('');
}

function escHtml(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }
function escAttr(s) { return escHtml(s).replace(/'/g,"\\'"); }

function openTicketReply(id, subject, username, message) {
    document.getElementById('replyTicketId').textContent = id;
    document.getElementById('replyTicketInfo').innerHTML =
        '<div style="background:rgba(255,255,255,.03);border-radius:8px;padding:.8rem;font-size:.85rem">' +
        '<strong style="color:#c8a96e">' + escHtml(subject) + '</strong><br>' +
        '<span style="color:#8899aa">From: ' + escHtml(username) + '</span><br>' +
        '<div style="color:#c0c8d8;margin-top:.5rem;max-height:120px;overflow:auto;white-space:pre-line">' + escHtml(message) + '</div></div>';
    document.getElementById('ticketReplyText').value = '';
    openModal('ticketReplyModal');
}

async function submitTicketReply() {
    const id = document.getElementById('replyTicketId').textContent;
    const reply = document.getElementById('ticketReplyText').value.trim();
    const status = document.getElementById('ticketReplyStatus').value;
    if (!reply) return;
    const r = await adminApi('reply_ticket', { ticket_id: id, reply, status });
    closeModal('ticketReplyModal');
    if (r.success) { showToast(r.message); loadTickets(); } else showToast(r.error, 'error');
}

async function changeTicketStatus(id, status) {
    const r = await adminApi('update_ticket_status', { ticket_id: id, status });
    if (r.success) { showToast('Ticket updated'); loadTickets(); } else showToast(r.error, 'error');
}

// ── AUDIT LOG ────────────────────────────────────────────────────────────────
let auditPage = 1;
async function loadAuditLog(page) {
    auditPage = page || 1;
    const r = await adminApi('audit_log', { page: auditPage }, 'GET');
    const el = document.getElementById('auditList');
    if (!r.success || !r.entries.length) {
        el.innerHTML = '<div class="text-center py-4" style="color:#4a5568"><i class="bi bi-journal-text" style="font-size:2rem;opacity:.3"></i><p class="mt-2">No audit entries yet</p></div>';
        return;
    }
    el.innerHTML = r.entries.map(e => `
        <div class="audit-row">
            <span style="color:#4a5568;font-size:.72rem;width:130px;flex-shrink:0">${e.created_at}</span>
            <span class="audit-action">${e.action}</span>
            <span style="color:#e2e8f0;font-weight:600;flex:1">${escHtml(e.target||'')}</span>
            <span style="color:#8899aa;font-size:.8rem;flex:1">${escHtml(e.details||'')}</span>
            <span style="color:#4a5568;font-size:.75rem;width:90px;text-align:right">${e.admin_name}</span>
        </div>
    `).join('');
    // Pager
    const pager = document.getElementById('auditPager');
    pager.innerHTML = '';
    for (let i = 1; i <= r.pages; i++) {
        const b = document.createElement('button');
        b.textContent = i; if (i === r.page) b.classList.add('active');
        b.onclick = () => loadAuditLog(i); pager.appendChild(b);
    }
}

// ── CHARACTER LOOKUP ─────────────────────────────────────────────────────────
document.getElementById('charSearchInput')?.addEventListener('keydown', e => { if (e.key === 'Enter') searchCharacter(); });

async function searchCharacter() {
    const q = document.getElementById('charSearchInput').value.trim();
    if (!q) return;
    const r = await adminApi('search_character', { q }, 'GET');
    const el = document.getElementById('charResults');
    if (!r.success || !r.characters.length) {
        el.innerHTML = '<div style="color:#4a5568;font-size:.85rem;padding:.5rem">No characters found</div>';
        return;
    }
    el.innerHTML = '<div style="overflow-x:auto"><table class="acct-tbl"><thead><tr>' +
        '<th>Name</th><th>Level</th><th>Race</th><th>Class</th><th>Account</th><th>Zone</th><th>Online</th></tr></thead><tbody>' +
        r.characters.map(c => `<tr>
            <td style="font-weight:700;color:#e2e8f0">${escHtml(c.name)}</td>
            <td><span class="char-lv" style="margin:0">Lv ${c.level}</span></td>
            <td style="color:#8899aa">${c.race}</td>
            <td style="color:#8899aa">${c.class}</td>
            <td><a href="#" onclick="event.preventDefault();viewAccount(${c.account})" style="color:#60a5fa">${escHtml(c.account_name)}</a></td>
            <td style="color:#6b7280">${c.zone||'—'}</td>
            <td>${c.online==1?'<span style="color:#5dd87c">● Online</span>':'<span style="color:#6b7280">Offline</span>'}</td>
        </tr>`).join('') + '</tbody></table></div>';
}

// ── IP BAN MANAGEMENT ────────────────────────────────────────────────────────
async function loadIpBans() {
    const r = await adminApi('get_ip_bans', {}, 'GET');
    const el = document.getElementById('ipBanList');
    if (!r.success || !r.bans.length) {
        el.innerHTML = '<div style="color:#4a5568;font-size:.85rem;padding:.5rem">No IP bans</div>';
        return;
    }
    el.innerHTML = '<div style="overflow-x:auto;max-height:300px"><table class="acct-tbl"><thead><tr>' +
        '<th>IP</th><th>Reason</th><th>By</th><th>Actions</th></tr></thead><tbody>' +
        r.bans.map(b => `<tr>
            <td style="font-family:monospace;color:#e2e8f0">${escHtml(b.ip)}</td>
            <td style="color:#8899aa;font-size:.82rem">${escHtml(b.banreason||'—')}</td>
            <td style="color:#6b7280">${escHtml(b.bannedby)}</td>
            <td><button class="acc-action-btn unban" onclick="doIpUnban('${escAttr(b.ip)}')"><i class="bi bi-x"></i> Remove</button></td>
        </tr>`).join('') + '</tbody></table></div>';
}

async function doIpBan() {
    const ip = document.getElementById('ipBanInput').value.trim();
    const reason = document.getElementById('ipBanReason').value.trim() || 'No reason';
    if (!ip) return;
    const r = await adminApi('ip_ban', { ip, reason });
    if (r.success) { showToast(r.message); document.getElementById('ipBanInput').value=''; document.getElementById('ipBanReason').value=''; loadIpBans(); }
    else showToast(r.error, 'error');
}

async function doIpUnban(ip) {
    if (!confirm('Remove IP ban for ' + ip + '?')) return;
    const r = await adminApi('ip_unban', { ip });
    if (r.success) { showToast(r.message); loadIpBans(); } else showToast(r.error, 'error');
}

// ── PEAK PLAYERS ─────────────────────────────────────────────────────────────
async function loadPeakPlayers() {
    const r = await adminApi('peak_players', {}, 'GET');
    if (!r.success) return;
    document.getElementById('peakPlayersContent').innerHTML = `
        <div class="row g-2">
            <div class="col-4"><div class="astat-card" style="padding:1rem"><div class="val" style="font-size:1.5rem;color:#5dd87c">${r.current_online}</div><div class="lbl">Online Now</div></div></div>
            <div class="col-4"><div class="astat-card" style="padding:1rem"><div class="val" style="font-size:1.5rem">${r.total_characters}</div><div class="lbl">Total Chars</div></div></div>
            <div class="col-4"><div class="astat-card" style="padding:1rem"><div class="val" style="font-size:1.5rem">${r.total_accounts}</div><div class="lbl">Total Accounts</div></div></div>
        </div>
    `;
}

// ── EMAIL BROADCAST ──────────────────────────────────────────────────────────
async function sendBroadcast() {
    const subject = document.getElementById('broadcastSubject').value.trim();
    const body = document.getElementById('broadcastBody').value.trim();
    if (!subject || !body) return showToast('Subject and body required', 'error');
    if (!confirm('This will send an email to ALL registered accounts. Are you sure?')) return;
    document.getElementById('broadcastBtn').disabled = true;
    document.getElementById('broadcastBtn').innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Sending...';
    const r = await adminApi('broadcast_email', { subject, body });
    document.getElementById('broadcastBtn').disabled = false;
    document.getElementById('broadcastBtn').innerHTML = '<i class="bi bi-send me-1"></i> Send to All Users';
    if (r.success) { showToast(r.message); document.getElementById('broadcastSubject').value=''; document.getElementById('broadcastBody').value=''; }
    else showToast(r.error, 'error');
}
</script>