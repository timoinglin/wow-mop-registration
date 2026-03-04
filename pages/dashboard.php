<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/login_history.php';
require_once __DIR__ . '/../templates/header.php';

// --- Auth ---
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

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
    $stmt = $pdo_auth->prepare("SELECT username, email, joindate, last_ip FROM account WHERE id = :id");
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

} catch (PDOException $e) {
    error_log("Dashboard Account DB Error: " . $e->getMessage());
    $errors[] = $TEXT['error_db'];
}

// --- Characters ---
$error_loading_chars = false;
if ($pdo_chars) {
    try {
        $sql = "SELECT c.guid, c.name, c.race, c.class, c.level, c.zone,
                       c.totaltime, c.logout_time, c.money, c.gender,
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
        $chart_colors_arr[] = $class_colors[(int)$char['class']] ?? '#8B4513';
    }
}

$tickets_enabled = !empty($config['features']['tickets']);
?>

<style>
.dash-hero {
    position: relative;
    padding: 3rem 2rem 2.5rem;
    margin-bottom: 2rem;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(139,69,19,0.35) 0%, rgba(10,10,20,0.85) 60%),
                url('/assets/img/wow-bg/4-1.webp') center/cover no-repeat;
    border: 1px solid rgba(139,69,19,0.4);
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
    background: linear-gradient(90deg, #c8a96e, #fff 60%, #c8a96e);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.dash-hero .hero-sub { font-size: .95rem; color: rgba(200,169,110,.75); letter-spacing: 1px; }

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
.status-gm      { background: rgba(139,69,19,.3);  color: #c8a96e; border: 1px solid rgba(200,169,110,.4); }

.stat-card {
    background: linear-gradient(145deg, #1a1a2e, #16213e);
    border: 1px solid rgba(139,69,19,0.3);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
    transition: transform .2s ease, border-color .2s ease;
    height: 100%;
}
.stat-card:hover { transform: translateY(-3px); border-color: rgba(200,169,110,0.5); }
.stat-card .stat-icon  { font-size: 1.8rem; margin-bottom: .5rem; opacity: .9; }
.stat-card .stat-value { font-size: 1.7rem; font-weight: 700; color: #c8a96e; line-height: 1; }
.stat-card .stat-label { font-size: .78rem; color: #8899aa; text-transform: uppercase; letter-spacing: .8px; margin-top: .25rem; }

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
.action-btn-primary { background: linear-gradient(135deg, #8B4513, #A0522D); color: #fff; }
.action-btn-primary:hover { background: linear-gradient(135deg, #A0522D, #c8a96e); color: #fff; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(139,69,19,.4); }
.action-btn-secondary { background: rgba(255,255,255,0.05); color: #c8a96e; border: 1px solid rgba(200,169,110,0.3); }
.action-btn-secondary:hover { background: rgba(200,169,110,0.12); border-color: rgba(200,169,110,0.6); color: #e8c87e; transform: translateY(-2px); }

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
    border: 1px solid rgba(139,69,19,0.25);
    border-radius: 14px;
    padding: 1.6rem;
    height: 100%;
}
.dash-panel .panel-title {
    font-size: .75rem;
    color: #c8a96e;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    margin-bottom: 1.2rem;
    padding-bottom: .6rem;
    border-bottom: 1px solid rgba(139,69,19,0.3);
}

/* Character cards */
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
}
.char-card:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.15); transform: translateX(3px); }
.char-icons { display: flex; flex-direction: column; gap: 3px; flex-shrink: 0; }
.char-icons img { width: 26px; height: 26px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.15); }
.char-info  { flex: 1; min-width: 0; }
.char-name  { font-weight: 700; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.char-meta  { font-size: .78rem; color: #8899aa; margin-top: 2px; }
.char-stats { text-align: right; flex-shrink: 0; }
.char-level-badge { display: inline-block; background: rgba(139,69,19,.35); color: #c8a96e; font-weight: 700; font-size: .85rem; padding: .2rem .6rem; border-radius: 6px; margin-bottom: 4px; }
.achiev-badge { display: inline-block; background: rgba(255,215,0,.1); color: #ffd700; font-size: .75rem; padding: .15rem .5rem; border-radius: 6px; border: 1px solid rgba(255,215,0,.2); }

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
</style>

<div class="container" style="padding-top: 90px; padding-bottom: 3rem;">

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<!-- HERO -->
<div class="dash-hero mb-4">
    <div class="position-relative">
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
    </div>
</div>

<!-- STAT CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">⏱️</div>
            <div class="stat-value"><?= format_playtime($total_playtime_seconds) ?></div>
            <div class="stat-label"><?= $TEXT['total_playtime'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?= format_gold($total_gold) ?></div>
            <div class="stat-label"><?= $TEXT['total_gold'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">⚔️</div>
            <div class="stat-value"><?= count($characters) ?></div>
            <div class="stat-label"><?= $TEXT['your_characters'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <?php if ($most_played_char): ?>
                <div class="stat-value" style="font-size:1.05rem;color:<?= $class_colors[(int)$most_played_char['class']] ?? '#c8a96e' ?>">
                    <?= htmlspecialchars($most_played_char['name']) ?>
                </div>
            <?php else: ?>
                <div class="stat-value" style="font-size:1rem">—</div>
            <?php endif; ?>
            <div class="stat-label"><?= $TEXT['most_played_char'] ?></div>
        </div>
    </div>
</div>

<!-- MIDDLE ROW -->
<div class="row g-3 mb-4">

    <!-- Account Info -->
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-person-badge me-2"></i><?= $TEXT['account_details'] ?></div>
            <div class="info-row">
                <span class="info-key"><?= $TEXT['username'] ?></span>
                <span class="info-val"><?= htmlspecialchars($user['username']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-key"><?= $TEXT['email'] ?></span>
                <span class="info-val" style="font-size:.8rem"><?= htmlspecialchars($user['email']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-key"><?= $TEXT['join_date'] ?></span>
                <span class="info-val"><?= date('Y-m-d', strtotime($user['joindate'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</div>
            <div class="d-flex flex-column gap-2">
                <a href="/change_password" class="action-btn action-btn-primary">
                    <i class="bi bi-key-fill"></i> <?= $TEXT['change_password'] ?>
                </a>
                <?php if ($tickets_enabled): ?>
                <a href="/tickets" class="action-btn action-btn-secondary">
                    <i class="bi bi-ticket-perforated"></i> <?= $TEXT['submit_ticket'] ?>
                </a>
                <?php endif; ?>
                <?php if ($gm_level >= 9): ?>
                <a href="/admin_dashboard" class="action-btn action-btn-secondary" style="color:#f87171;border-color:rgba(220,53,69,.4)">
                    <i class="bi bi-shield-lock"></i> <?= $TEXT['admin_panel'] ?>
                </a>
                <?php endif; ?>
            </div>
            <?php if ($most_played_char): ?>
            <div class="mt-3 p-2 rounded" style="background:rgba(139,69,19,.12);border:1px solid rgba(139,69,19,.3);font-size:.82rem;color:#c8a96e;">
                <i class="bi bi-star me-1"></i> Most time on
                <strong style="color:<?= $class_colors[(int)$most_played_char['class']] ?? '#c8a96e' ?>">
                    <?= htmlspecialchars($most_played_char['name']) ?>
                </strong>
                (<?= format_playtime((int)$most_played_char['totaltime']) ?>)
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Playtime Chart -->
    <div class="col-lg-4">
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
</div>

<!-- BOTTOM ROW: Login History + Characters -->
<div class="row g-3">

    <!-- Login History -->
    <div class="col-lg-4">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-clock-history me-2"></i>Login History</div>
            <?php if (!empty($login_history)): ?>
                <?php foreach (array_slice($login_history, 0, 5) as $i => $entry): ?>
                <div class="login-row">
                    <span class="login-idx <?= $i === 0 ? 'login-idx-0' : 'login-idx-n' ?>">
                        <?= $i === 0 ? '✓' : ($i + 1) ?>
                    </span>
                    <span class="login-ip"><?= htmlspecialchars($entry['ip']) ?></span>
                    <span class="login-time">
                        <?php
                            $diff = time() - (int)$entry['time'];
                            if ($diff < 60)       echo 'just now';
                            elseif ($diff < 3600)  echo floor($diff/60) . 'm ago';
                            elseif ($diff < 86400) echo floor($diff/3600) . 'h ago';
                            else                   echo date('M d, Y', (int)$entry['time']);
                        ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if ($i === 0): ?>
                    <p class="mt-2 mb-0" style="font-size:.78rem;color:#8899aa;">
                        <i class="bi bi-info-circle me-1"></i> History is recorded from your next login.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-3" style="color:#8899aa">
                    <i class="bi bi-clock" style="font-size:2rem;opacity:.3"></i>
                    <p class="mt-2 mb-0" style="font-size:.85rem">No login history yet.<br>It will appear after your next login.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Characters -->
    <div class="col-lg-8">
        <div class="dash-panel">
            <div class="panel-title"><i class="bi bi-people me-2"></i><?= $TEXT['your_characters'] ?></div>

            <?php if ($error_loading_chars): ?>
                <div class="alert alert-warning py-2"><?= $TEXT['error_loading_characters'] ?></div>
            <?php elseif (!empty($characters)): ?>
                <div class="d-flex flex-column gap-2">
                <?php foreach ($characters as $char):
                    $cls  = (int)$char['class'];
                    $clr  = $class_colors[$cls] ?? '#c8a96e';
                    $guid = (int)$char['guid'];
                    $ach  = $achievement_counts[$guid] ?? null;
                ?>
                    <div class="char-card" style="border-left-color:<?= $clr ?>">
                        <div class="char-icons">
                            <img src="<?= get_race_icon_path((int)$char['race'], (int)$char['gender']) ?>"
                                 title="<?= htmlspecialchars(get_race_name((int)$char['race'])) ?>">
                            <img src="<?= get_class_icon_path($cls) ?>"
                                 title="<?= htmlspecialchars(get_class_name($cls)) ?>">
                        </div>
                        <div class="char-info">
                            <div class="char-name" style="color:<?= $clr ?>"><?= htmlspecialchars($char['name']) ?></div>
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
                                    <span class="achiev-badge">🏆 <?= number_format($ach) ?> achievements</span>
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
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4" style="color:#8899aa">
                    <i class="bi bi-person-dash" style="font-size:2.5rem;opacity:.3"></i>
                    <p class="mt-2"><?= sprintf($TEXT['no_characters_found_realm'], htmlspecialchars($TEXT['realm1_name'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /row -->

<!-- ═══════════════ ACCOUNT SECURITY & VOTE SECTIONS ═══════════════ -->
<div class="row g-3 mt-1">
    <!-- Account Security -->
    <div class="col-lg-6">
        <div class="panel-card" style="background:linear-gradient(145deg,#12121f,#1a1a2e);border:1px solid rgba(139,69,19,.25);border-radius:14px;padding:1.4rem 1.6rem;">
            <div style="font-size:.72rem;color:#c8a96e;text-transform:uppercase;letter-spacing:1.5px;font-weight:700;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid rgba(139,69,19,.2)">
                <i class="bi bi-shield-lock me-2"></i>Account Security
            </div>

            <!-- Account Info -->
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;border-bottom:1px solid rgba(255,255,255,.04)">
                <span style="color:#8899aa">Account ID</span>
                <span style="color:#e2e8f0;font-weight:600"><?= $user_id ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;border-bottom:1px solid rgba(255,255,255,.04)">
                <span style="color:#8899aa">Email</span>
                <span style="color:#e2e8f0"><?= htmlspecialchars($user['email'] ?? '—') ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;border-bottom:1px solid rgba(255,255,255,.04)">
                <span style="color:#8899aa">Joined</span>
                <span style="color:#e2e8f0"><?= $user['joindate'] ? date('M d, Y', strtotime($user['joindate'])) : '—' ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:.4rem 0;font-size:.85rem;border-bottom:1px solid rgba(255,255,255,.04)">
                <span style="color:#8899aa">Current IP</span>
                <span style="color:#5dd87c;font-family:monospace;font-size:.82rem"><?= htmlspecialchars($user['last_ip'] ?? '—') ?></span>
            </div>

            <!-- Recent Login History -->
            <div style="font-size:.68rem;color:#8899aa;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin:1rem 0 .5rem;padding-top:.5rem;border-top:1px solid rgba(139,69,19,.15)">
                <i class="bi bi-clock-history me-1"></i>Recent Login Activity
            </div>
            <?php if (!empty($login_history)): ?>
                <?php foreach (array_slice($login_history, 0, 5) as $idx => $login): ?>
                <div style="display:flex;align-items:center;gap:.5rem;padding:.35rem 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.82rem">
                    <span class="login-idx <?= $idx === 0 ? 'login-idx-0' : 'login-idx-n' ?>"><?= $idx + 1 ?></span>
                    <span class="login-ip"><?= htmlspecialchars($login['ip'] ?? '—') ?></span>
                    <span class="login-time"><?= isset($login['time']) ? date('M d, H:i', strtotime($login['time'])) : '—' ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#4a5568;font-size:.85rem;padding:.5rem 0">No login history available</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Vote & Reward -->
    <div class="col-lg-6">
        <div class="panel-card" style="background:linear-gradient(145deg,#12121f,#1a1a2e);border:1px solid rgba(139,69,19,.25);border-radius:14px;padding:1.4rem 1.6rem;">
            <div style="font-size:.72rem;color:#c8a96e;text-transform:uppercase;letter-spacing:1.5px;font-weight:700;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid rgba(139,69,19,.2)">
                <i class="bi bi-trophy me-2"></i>Vote & Support Us
            </div>

            <?php $vote_sites = $config['vote_sites'] ?? []; ?>
            <?php if (!empty($vote_sites)): ?>
                <?php foreach ($vote_sites as $site): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.8rem;margin-bottom:.5rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:10px;transition:all .2s">
                    <div>
                        <div style="font-weight:600;color:#e2e8f0;font-size:.92rem"><?= htmlspecialchars($site['name'] ?? 'Vote Site') ?></div>
                        <div style="color:#4a5568;font-size:.72rem">Cooldown: <?= $site['cooldown_hours'] ?? 12 ?>h</div>
                    </div>
                    <a href="<?= htmlspecialchars($site['url'] ?? '#') ?>" target="_blank" rel="noopener noreferrer" style="
                        display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;
                        border-radius:8px;font-weight:600;font-size:.82rem;text-decoration:none;
                        background:linear-gradient(135deg,#8B4513,#A0522D);color:#fff;
                        transition:all .2s ease;
                    ">
                        <i class="bi bi-box-arrow-up-right"></i> Vote
                    </a>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center;padding:2rem 1rem">
                    <div style="font-size:2.5rem;opacity:.3;margin-bottom:.5rem">🗳️</div>
                    <p style="color:#8899aa;font-size:.9rem;margin:0">Vote sites coming soon!</p>
                    <p style="color:#4a5568;font-size:.78rem;margin:.3rem 0 0">Vote for our server to earn rewards and help us grow.</p>
                </div>
            <?php endif; ?>

            <!-- Quick Links -->
            <div style="margin-top:1rem;padding-top:.8rem;border-top:1px solid rgba(139,69,19,.15)">
                <div style="font-size:.68rem;color:#8899aa;text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:.5rem">Quick Links</div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($tickets_enabled): ?>
                    <a href="/tickets" class="action-btn action-btn-secondary" style="padding:.5rem 1rem;font-size:.82rem">
                        <i class="bi bi-ticket-perforated"></i> Support Tickets
                    </a>
                    <?php endif; ?>
                    <a href="/tickets?tab=history" class="action-btn action-btn-secondary" style="padding:.5rem 1rem;font-size:.82rem">
                        <i class="bi bi-clock-history"></i> My Tickets
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

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
