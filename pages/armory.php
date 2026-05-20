<?php
/**
 * Public Armory
 *
 * Two modes in one file:
 *   - List/search   →  /armory                (no `char` param)
 *   - Profile       →  /armory/{name}         (rewritten to ?char={name})
 *
 * Pulls character data from the characters DB and renders item icons via the
 * Wowhead tooltip widget — no local item DBC required.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ─── Shared lookups ──────────────────────────────────────────────────────────
$class_colors = [
    1 => '#C79C6E', 2 => '#F58CBA', 3 => '#ABD473', 4 => '#FFF569',
    5 => '#FFFFFF', 6 => '#C41F3B', 7 => '#0070DE', 8 => '#69CCF0',
    9 => '#9482C9', 10 => '#00FF96', 11 => '#FF7D0A',
];
$alliance_races = [1, 3, 4, 7, 11, 22, 25];
$horde_races    = [2, 5, 6, 8, 9, 10, 26];

$equip_slots = [
    0  => $TEXT['armory_slot_head']     ?? 'Head',
    1  => $TEXT['armory_slot_neck']     ?? 'Neck',
    2  => $TEXT['armory_slot_shoulder'] ?? 'Shoulder',
    3  => $TEXT['armory_slot_shirt']    ?? 'Shirt',
    4  => $TEXT['armory_slot_chest']    ?? 'Chest',
    5  => $TEXT['armory_slot_waist']    ?? 'Waist',
    6  => $TEXT['armory_slot_legs']     ?? 'Legs',
    7  => $TEXT['armory_slot_feet']     ?? 'Feet',
    8  => $TEXT['armory_slot_wrist']    ?? 'Wrist',
    9  => $TEXT['armory_slot_hands']    ?? 'Hands',
    10 => $TEXT['armory_slot_finger']   ?? 'Finger',
    11 => $TEXT['armory_slot_finger']   ?? 'Finger',
    12 => $TEXT['armory_slot_trinket']  ?? 'Trinket',
    13 => $TEXT['armory_slot_trinket']  ?? 'Trinket',
    14 => $TEXT['armory_slot_back']     ?? 'Back',
    15 => $TEXT['armory_slot_main_hand']?? 'Main Hand',
    16 => $TEXT['armory_slot_off_hand'] ?? 'Off Hand',
    17 => $TEXT['armory_slot_ranged']   ?? 'Ranged',
    18 => $TEXT['armory_slot_tabard']   ?? 'Tabard',
];

// Visual layout order for the doll
$slot_left  = [0, 1, 2, 14, 4, 3, 8, 9];
$slot_right = [5, 6, 7, 10, 11, 12, 13, 18];
$slot_bot   = [15, 16, 17];

function faction_for_race(int $r, array $a, array $h): string {
    if (in_array($r, $a, true)) return 'alliance';
    if (in_array($r, $h, true)) return 'horde';
    return 'neutral';
}

// MoP class spec hint (best-effort from class id only — fine for v1)
function class_role_label(int $classId): string {
    $roles = [
        1 => 'Warrior', 2 => 'Paladin', 3 => 'Hunter', 4 => 'Rogue',
        5 => 'Priest',  6 => 'Death Knight', 7 => 'Shaman', 8 => 'Mage',
        9 => 'Warlock', 10 => 'Monk',  11 => 'Druid',
    ];
    return $roles[$classId] ?? 'Adventurer';
}

// Wowhead URL — uses MoP Classic so tooltips reflect 5.4.x stats
function wowhead_item_url(int $itemId): string {
    return 'https://www.wowhead.com/mop-classic/item=' . $itemId;
}
function wowhead_spell_url(int $spellId): string {
    return 'https://www.wowhead.com/mop-classic/spell=' . $spellId;
}

// MoP 5.4.8 ChrSpecialization IDs → display name. Fixed, well-known set
// (stored space-separated per talent group in characters.talentTree, e.g.
// "70 0" = Retribution for group 0). English on purpose — same as the
// Wowhead spell tooltips, which are locale-driven anyway.
function mop_spec_name(int $id): ?string {
    static $m = [
        62=>'Arcane',63=>'Fire',64=>'Frost',                       // Mage
        65=>'Holy',66=>'Protection',70=>'Retribution',             // Paladin
        71=>'Arms',72=>'Fury',73=>'Protection',                    // Warrior
        102=>'Balance',103=>'Feral',104=>'Guardian',105=>'Restoration', // Druid
        250=>'Blood',251=>'Frost',252=>'Unholy',                   // Death Knight
        253=>'Beast Mastery',254=>'Marksmanship',255=>'Survival',   // Hunter
        256=>'Discipline',257=>'Holy',258=>'Shadow',               // Priest
        259=>'Assassination',260=>'Combat',261=>'Subtlety',        // Rogue
        262=>'Elemental',263=>'Enhancement',264=>'Restoration',    // Shaman
        265=>'Affliction',266=>'Demonology',267=>'Destruction',    // Warlock
        268=>'Brewmaster',269=>'Windwalker',270=>'Mistweaver',     // Monk
    ];
    return $m[$id] ?? null;
}

// ─── Mode dispatch ───────────────────────────────────────────────────────────
$char_name = isset($_GET['char']) ? trim((string)$_GET['char']) : '';
$is_profile = $char_name !== '';

// Validate name (WoW names: 2-12 chars, letters only — be permissive for safety)
if ($is_profile && (strlen($char_name) > 64 || !preg_match('/^[A-Za-zÀ-ÿ\'\-]{1,64}$/u', $char_name))) {
    $char_name  = '';
    $is_profile = false;
    $invalid_name = true;
}

require_once __DIR__ . '/../includes/site_settings.php';
$page_title = $is_profile
    ? htmlspecialchars($char_name) . ' — ' . ($TEXT['armory'] ?? 'Armory')
    : ($TEXT['armory'] ?? 'Armory') . ' — ' . htmlspecialchars(settings_site_title($pdo_auth ?? null, $config));

// ─── Fetch profile data BEFORE the header so OG meta tags can use it ─────────
$char = null;
if ($is_profile && $pdo_chars) {
    try {
        $stmt = $pdo_chars->prepare(
            "SELECT c.guid, c.account, c.name, c.race, c.class, c.gender,
                    c.level, c.xp, c.money, c.totaltime, c.leveltime,
                    c.zone, c.map, c.online, c.logout_time, c.health,
                    c.power1, c.power2, c.power3, c.power4,
                    c.activespec, c.speccount, c.talentTree,
                    g.guildid, g.name AS guild_name
             FROM characters c
             LEFT JOIN guild_member gm ON gm.guid = c.guid
             LEFT JOIN guild g ON g.guildid = gm.guildid
             WHERE c.name = :name
             LIMIT 1"
        );
        $stmt->execute(['name' => $char_name]);
        $char = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Armory profile query failed: ' . $e->getMessage());
    }
}

// ─── OG / Twitter meta tag values ────────────────────────────────────────────
$_og_realm = settings_get($pdo_auth ?? null, $config)['realm_name'];
if ($is_profile && $char) {
    $og_title       = $char['name'] . ' — ' . sprintf(
        $TEXT['armory_og_profile_title'] ?? 'Level %d %s %s',
        (int)$char['level'],
        get_race_name((int)$char['race']),
        get_class_name((int)$char['class'])
    );
    $og_description = !empty($char['guild_name'])
        ? sprintf(
            $TEXT['armory_og_profile_desc_guild'] ?? '%s of <%s> on %s — view gear, stats and achievements.',
            $char['name'],
            $char['guild_name'],
            $_og_realm
        )
        : sprintf(
            $TEXT['armory_og_profile_desc'] ?? '%s on %s — view gear, stats and achievements.',
            $char['name'],
            $_og_realm
        );
    $og_type = 'profile';
} elseif ($is_profile) {
    // Character not found — still render meaningful OG so shared bad links don't look broken
    $og_title       = ($TEXT['armory_character_not_found'] ?? 'Character not found') . ' — ' . $_og_realm;
    $og_description = $TEXT['armory_subtitle'] ?? 'Search any character on the realm — view gear, stats, achievements and more.';
    $og_type        = 'website';
} else {
    $og_title       = ($TEXT['armory_title'] ?? 'Public Armory') . ' — ' . $_og_realm;
    $og_description = $TEXT['armory_subtitle'] ?? 'Search any character on the realm — view gear, stats, achievements and more.';
    $og_type        = 'website';
}

require_once __DIR__ . '/../templates/header.php';

if (!$pdo_chars) {
    echo '<div class="container" style="padding-top:120px;padding-bottom:3rem"><div class="alert alert-danger">'
        . htmlspecialchars($TEXT['error_db_chars_conn'] ?? 'Character database unavailable.')
        . '</div></div>';
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ============================================================================
// PROFILE MODE
// ============================================================================
if ($is_profile) {
    if (!$char) {
        ?>
        <div class="container" style="padding-top:120px;padding-bottom:3rem">
            <div class="armory-empty text-center">
                <div style="font-size:4rem;opacity:.25"><i class="bi bi-search"></i></div>
                <h2 style="color:var(--accent)"><?= htmlspecialchars($TEXT['armory_character_not_found'] ?? 'Character not found') ?></h2>
                <p style="color:#8899aa"><?= sprintf(htmlspecialchars($TEXT['armory_character_not_found_hint'] ?? 'No character named %s exists on this realm.'), '<strong>' . htmlspecialchars($char_name) . '</strong>') ?></p>
                <a href="/armory" class="btn btn-gold mt-2"><i class="bi bi-arrow-left me-2"></i><?= htmlspecialchars($TEXT['armory_back'] ?? 'Back to Armory') ?></a>
            </div>
        </div>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }

    $cid     = (int)$char['class'];
    $rid     = (int)$char['race'];
    $gid     = (int)$char['gender'];
    $clr     = $class_colors[$cid] ?? 'var(--accent)';
    $faction = faction_for_race($rid, $alliance_races, $horde_races);
    // Faction RGB for portrait halos (matches the hero background tint).
    $fac_rgb = $faction === 'alliance' ? '0,112,222'
             : ($faction === 'horde'   ? '196,31,59' : '139,69,19');

    // Equipped items (bag=0, slot 0..18). Pull item entry per slot.
    $equipped = [];
    try {
        $eq = $pdo_chars->prepare(
            "SELECT ci.slot, ii.itemEntry, ii.enchantments
             FROM character_inventory ci
             JOIN item_instance ii ON ii.guid = ci.item
             WHERE ci.guid = :g AND ci.bag = 0 AND ci.slot BETWEEN 0 AND 18"
        );
        $eq->execute(['g' => $char['guid']]);
        foreach ($eq->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $equipped[(int)$row['slot']] = (int)$row['itemEntry'];
        }
    } catch (PDOException $e) {
        error_log('Armory equipment query failed: ' . $e->getMessage());
    }

    // Stats (best-effort — table layout differs across cores)
    $stats = null;
    try {
        $st = $pdo_chars->prepare("SELECT * FROM character_stats WHERE guid = :g LIMIT 1");
        $st->execute(['g' => $char['guid']]);
        $stats = $st->fetch();
    } catch (PDOException $e) {
        // Table may not exist on this core, silently skip
    }

    // Achievements
    $achievement_count = 0;
    try {
        $ac = $pdo_chars->prepare("SELECT COUNT(*) FROM character_achievement WHERE guid = :g");
        $ac->execute(['g' => $char['guid']]);
        $achievement_count = (int)$ac->fetchColumn();
    } catch (PDOException $e) {}

    // Other characters on the same account
    $siblings = [];
    try {
        $sib = $pdo_chars->prepare(
            "SELECT name, race, class, gender, level
             FROM characters
             WHERE account = :a AND guid != :g
             ORDER BY level DESC, name ASC LIMIT 8"
        );
        $sib->execute(['a' => $char['account'], 'g' => $char['guid']]);
        $siblings = $sib->fetchAll();
    } catch (PDOException $e) {}

    // Talents — MoP stores up to 6 chosen talent spells per talent group
    // (one per tier: levels 15/30/45/60/75/90). spell → Wowhead spell tooltip.
    $talent_groups = [];
    try {
        $tl = $pdo_chars->prepare(
            "SELECT spec, spell FROM character_talent WHERE guid = :g ORDER BY spec, spell"
        );
        $tl->execute(['g' => $char['guid']]);
        foreach ($tl->fetchAll() as $row) {
            $talent_groups[(int)$row['spec']][] = (int)$row['spell'];
        }
    } catch (PDOException $e) {}
    // Specialization id per group from characters.talentTree ("70 0").
    $spec_ids = [];
    foreach (preg_split('/\s+/', trim((string)($char['talentTree'] ?? ''))) as $i => $sid) {
        if ($sid !== '') $spec_ids[$i] = (int)$sid;
    }
    $active_spec = (int)($char['activespec'] ?? 0);

    // ── PvP / Title / Professions — repack-defensive ──────────────────────
    // Each block hides gracefully if its column/table doesn't exist on the
    // server (try/catch per fetch). All read-only.
    require_once __DIR__ . '/../includes/wow_skills.php';
    require_once __DIR__ . '/../includes/wow_titles.php';
    require_once __DIR__ . '/../includes/wow_talents_mop.php';

    $pvp_total_kills = null;
    $pvp_rated       = [];
    $char_title_id   = 0;
    $char_skills     = [];
    $skills_query_ok = false; // true when character_skills was queryable, even if 0 rows
                              // — drives whether the Professions panel renders an empty state
    $char_glyphs     = [];    // talentGroup => [g1..g6]  (0 = empty slot)
    $glyphs_query_ok = false;

    try {
        $stmt = $pdo_chars->prepare("SELECT totalKills FROM characters WHERE guid = :g LIMIT 1");
        $stmt->execute(['g' => (int)$char['guid']]);
        $v = $stmt->fetchColumn();
        if ($v !== false) $pvp_total_kills = (int)$v;
    } catch (PDOException $e) { /* column missing — skip */ }

    try {
        $stmt = $pdo_chars->prepare(
            "SELECT slot, rating, season_wins
             FROM rated_pvp_info
             WHERE guid = :g
               AND season = (SELECT MAX(season) FROM rated_pvp_info)
               AND rating > 0
             ORDER BY slot ASC"
        );
        $stmt->execute(['g' => (int)$char['guid']]);
        $pvp_rated = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table missing — skip */ }

    try {
        $stmt = $pdo_chars->prepare("SELECT chosenTitle FROM characters WHERE guid = :g LIMIT 1");
        $stmt->execute(['g' => (int)$char['guid']]);
        $v = $stmt->fetchColumn();
        if ($v !== false) $char_title_id = (int)$v;
    } catch (PDOException $e) { /* column missing — skip */ }

    try {
        $stmt = $pdo_chars->prepare(
            "SELECT skill, value, max FROM character_skills WHERE guid = :g ORDER BY skill ASC"
        );
        $stmt->execute(['g' => (int)$char['guid']]);
        $char_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $skills_query_ok = true; // table exists — panel will render even with 0 known skills
    } catch (PDOException $e) { /* table missing — skip */ }

    // Glyphs: TC-MoP standard schema is character_glyphs(guid, talentGroup,
    // glyph1..glyph6). Slots 1-3 = major, 4-6 = minor. Per spec (talentGroup).
    try {
        $stmt = $pdo_chars->prepare(
            "SELECT talentGroup, glyph1, glyph2, glyph3, glyph4, glyph5, glyph6
             FROM character_glyphs WHERE guid = :g"
        );
        $stmt->execute(['g' => (int)$char['guid']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $char_glyphs[(int)$r['talentGroup']] = [
                (int)$r['glyph1'], (int)$r['glyph2'], (int)$r['glyph3'],
                (int)$r['glyph4'], (int)$r['glyph5'], (int)$r['glyph6'],
            ];
        }
        $glyphs_query_ok = true;
    } catch (PDOException $e) { /* table missing or different schema — skip */ }

    // Account info (join date) — read-only bit from auth DB
    $account_join = null;
    try {
        $aq = $pdo_auth->prepare("SELECT joindate FROM account WHERE id = :id");
        $aq->execute(['id' => $char['account']]);
        $account_join = $aq->fetchColumn();
    } catch (PDOException $e) {}

    $bg_idx = (($cid + $rid) % 5) + 1;
?>

<style>
.armory-wrap { padding-top: 90px; padding-bottom: 3rem; }

.armory-hero {
    position: relative;
    border-radius: 18px;
    padding: 2.5rem 2rem 2rem;
    margin-bottom: 1.5rem;
    background:
        linear-gradient(135deg, rgba(<?= $faction === 'alliance' ? '0,112,222' : ($faction === 'horde' ? '196,31,59' : '139,69,19') ?>,.30) 0%, rgba(10,10,20,.92) 60%),
        url('/assets/img/wow-bg/4-<?= $bg_idx ?>.webp') center/cover no-repeat;
    border: 1px solid rgba(var(--accent-rgb), .35);
    overflow: hidden;
}
.armory-hero::after {
    content:'';
    position:absolute; inset:0;
    background: linear-gradient(to bottom, transparent 55%, rgba(10,10,20,.85));
    pointer-events:none;
}
.armory-hero-inner { position: relative; z-index: 1; }
.armory-name {
    font-size: clamp(2rem, 4.5vw, 3.4rem);
    font-weight: 800;
    letter-spacing: 1px;
    color: <?= $clr ?>;
    text-shadow: 0 0 22px <?= $clr ?>33, 0 2px 6px rgba(0,0,0,.6);
    line-height: 1.05;
    margin: 0;
}
.armory-sub {
    font-size: 1.05rem;
    color: rgba(255,255,255,.78);
    letter-spacing: .8px;
    margin-top: .35rem;
}
/* ── Shared character portrait (HQ race art) — used in the hero header
   and the equipment centre so the page reads as one piece. ───────────── */
.wl-portrait { position: relative; flex-shrink: 0; }
.wl-portrait::after {                       /* faction-tinted halo */
    content: ''; position: absolute; inset: -14%; border-radius: 50%;
    background: radial-gradient(circle, rgba(<?= $fac_rgb ?>,.45), transparent 70%);
    z-index: 0; pointer-events: none;
}
.wl-portrait .pf {                          /* the race art disc */
    position: relative; z-index: 1; display: block;
    width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
    object-position: center 18%;            /* frame the face, not the chest */
    background: #0a0a0f;
    border: 3px solid <?= $clr ?>;
    box-shadow: 0 0 26px -4px <?= $clr ?>cc, inset 0 0 0 2px rgba(0,0,0,.55);
}
.wl-portrait .pc {                          /* class-icon badge */
    position: absolute; z-index: 2; right: -2px; bottom: -2px;
    border-radius: 50%; background: #0a0a0f;
    border: 2px solid <?= $clr ?>; padding: 2px;
    box-shadow: 0 2px 7px rgba(0,0,0,.7);
}
/* hero header instance */
.armory-avatar { width: 104px; height: 104px; }
.armory-avatar .pc { width: 38px; height: 38px; }
@media (max-width: 575px) { .armory-avatar { width: 84px; height: 84px; } }
.faction-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .35rem .8rem; border-radius: 50px;
    font-size: .78rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
}
.faction-alliance { background: rgba(0,112,222,.18); color: #69ccf0; border: 1px solid rgba(0,112,222,.45); }
.faction-horde    { background: rgba(196,31,59,.18); color: #f87e8a; border: 1px solid rgba(196,31,59,.45); }
.faction-neutral  { background: rgba(var(--accent-rgb), .15); color: var(--accent); border: 1px solid rgba(var(--accent-rgb), .4); }
.online-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .25rem .65rem; border-radius: 50px;
    font-size: .72rem; font-weight: 600; letter-spacing: .6px; text-transform: uppercase;
}
.online-on  { background: rgba(40,167,69,.2); color:#5dd87c; border:1px solid rgba(40,167,69,.45); }
.online-off { background: rgba(255,255,255,.05); color:#8899aa; border:1px solid rgba(255,255,255,.1); }

.armory-quickstats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: .75rem;
    margin-top: 1.5rem;
}
.qstat {
    background: rgba(0,0,0,.35);
    border: 1px solid rgba(var(--accent-rgb), .18);
    border-radius: 10px;
    padding: .7rem .9rem;
}
.qstat .lbl { font-size: .68rem; color:#8899aa; text-transform: uppercase; letter-spacing: 1.2px; }
.qstat .val { font-size: 1.05rem; color:var(--accent); font-weight: 700; margin-top: .1rem; }

/* Panel cards */
.armory-panel {
    background: linear-gradient(145deg, #12121f, #1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 14px;
    padding: 1.4rem 1.6rem;
}
.armory-panel-title {
    font-size: .72rem;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    margin-bottom: 1rem;
    padding-bottom: .55rem;
    border-bottom: 1px solid rgba(var(--btn-bg-rgb), .3);
}

/* Equipment doll */
.gear-grid {
    display: grid;
    grid-template-columns: minmax(0,1fr) auto minmax(0,1fr);
    gap: 1rem;
    align-items: start;
}
.gear-col   { display: flex; flex-direction: column; gap: .55rem; }
.gear-col-r { align-items: flex-end; }
.gear-center {
    width: 150px; min-height: 270px;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column; gap: .7rem; text-align: center;
}
/* equipment-centre instance of .wl-portrait (same look as the hero avatar) */
.gear-avatar { width: 132px; height: 132px; }
.gear-avatar .pc { width: 44px; height: 44px; }
.gear-id-class { font-size: 1.15rem; font-weight: 800; letter-spacing: .5px; color: <?= $clr ?>; }
.gear-id-race  { font-size: .74rem; text-transform: uppercase; letter-spacing: 1.5px; color: #8899aa; margin-top: 1px; }
.gear-id-lvl   { font-size: .72rem; color: #6c7a8c; margin-top: 2px; }

.gear-slot {
    display: flex; align-items: center; gap: .6rem;
    background: rgba(255,255,255,.025);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 10px;
    padding: .35rem .55rem;
    transition: all .2s ease;
    min-width: 0;
}
.gear-col-r .gear-slot { flex-direction: row-reverse; text-align: right; }
.gear-slot:hover { background: rgba(var(--accent-rgb), .06); border-color: rgba(var(--accent-rgb), .35); transform: translateY(-1px); }
.gear-slot.empty { opacity: .5; }
.gear-slot .gear-icon {
    width: 40px; height: 40px; border-radius: 6px; flex-shrink: 0;
    background: #000 url('https://wow.zamimg.com/images/wow/icons/medium/inv_misc_questionmark.jpg') center/cover;
    border: 1px solid rgba(var(--accent-rgb), .35);
    box-shadow: inset 0 0 0 1px rgba(0,0,0,.4);
}
.gear-slot .gear-meta { min-width: 0; flex: 1; }
.gear-slot .gear-slot-lbl {
    font-size: .68rem; color: #6c7a8c; text-transform: uppercase; letter-spacing: 1px;
    margin-bottom: 1px;
}
.gear-slot .gear-item-name {
    font-size: .82rem; color: var(--accent); font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.gear-slot .gear-item-name a { color: inherit; text-decoration: none; }
.gear-slot.empty .gear-item-name { color: #4a5568; font-style: italic; }
.gear-slot.bottom-row .gear-icon { width: 48px; height: 48px; }

.gear-bottom-row {
    display: flex; gap: .55rem; justify-content: center; margin-top: 1rem;
    flex-wrap: wrap;
}
.gear-bottom-row .gear-slot { min-width: 200px; }

/* Stats list */
.stat-list { display: flex; flex-direction: column; gap: .35rem; }
.stat-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: .45rem .15rem; font-size: .88rem;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.stat-row:last-child { border-bottom: none; }
.stat-row .k { color: #8899aa; }
.stat-row .v { color: #e2e8f0; font-weight: 700; font-variant-numeric: tabular-nums; }
.stat-row.primary .v { color: var(--accent); }

/* Sibling chars */
.sib-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .55rem; }
.sib-card {
    background: rgba(255,255,255,.03); border: 1px solid rgba(255,255,255,.07);
    border-radius: 10px; padding: .65rem .8rem;
    display: flex; align-items: center; gap: .6rem; text-decoration: none;
    transition: all .2s ease;
    border-left: 3px solid;
}
.sib-card:hover { background: rgba(var(--accent-rgb), .07); border-color: rgba(var(--accent-rgb), .35); transform: translateX(3px); }
.sib-card img { width: 28px; height: 28px; border-radius: 4px; border: 1px solid rgba(255,255,255,.15); }
.sib-card .sn { font-weight: 700; font-size: .9rem; }
.sib-card .sm { font-size: .7rem; color: #8899aa; }

@media (max-width: 768px) {
    .gear-grid { grid-template-columns: 1fr; }
    .gear-center { display: none; }
    .gear-col-r { align-items: stretch; }
    .gear-col-r .gear-slot { flex-direction: row; text-align: left; }
}
</style>

<div class="container armory-wrap">

    <!-- Back link -->
    <div class="mb-3">
        <a href="/armory" class="text-decoration-none" style="color:#8899aa;font-size:.9rem">
            <i class="bi bi-arrow-left me-1"></i> <?= htmlspecialchars($TEXT['armory_back'] ?? 'Back to Armory') ?>
        </a>
    </div>

    <!-- HERO -->
    <div class="armory-hero">
        <div class="armory-hero-inner">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                <div class="wl-portrait armory-avatar">
                    <img class="pf" src="<?= '/' . get_race_icon_path($rid, $gid) ?>" alt="<?= htmlspecialchars(get_race_name($rid) . ' ' . get_class_name($cid)) ?>">
                    <img class="pc" src="<?= '/' . get_class_icon_path($cid) ?>" alt="<?= htmlspecialchars(get_class_name($cid)) ?>" title="<?= htmlspecialchars(get_class_name($cid)) ?>">
                </div>
                <div>
                    <h1 class="armory-name"><?= htmlspecialchars($char['name']) ?></h1>
                    <div class="armory-sub">
                        Level <?= (int)$char['level'] ?>
                        <?= htmlspecialchars(get_race_name($rid)) ?>
                        <?= htmlspecialchars(get_class_name($cid)) ?>
                        <?php if (!empty($char['guild_name'])): ?>
                            &middot; <i class="bi bi-people-fill"></i>
                            <a href="/guild/<?= rawurlencode($char['guild_name']) ?>" style="color:inherit;text-decoration:none;border-bottom:1px dotted rgba(255,255,255,.25)">&lt;<?= htmlspecialchars($char['guild_name']) ?>&gt;</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
                    <span class="faction-badge faction-<?= $faction ?>">
                        <?php if ($faction === 'alliance'): ?>
                            <i class="bi bi-shield-shaded"></i> <?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?>
                        <?php elseif ($faction === 'horde'): ?>
                            <i class="bi bi-fire"></i> <?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?>
                        <?php else: ?>
                            <i class="bi bi-yin-yang"></i> <?= htmlspecialchars($TEXT['armory_label_neutral'] ?? 'Neutral') ?>
                        <?php endif; ?>
                    </span>
                    <?php if ((int)$char['online'] === 1): ?>
                        <span class="online-pill online-on"><span style="width:8px;height:8px;border-radius:50%;background:#5dd87c;display:inline-block;box-shadow:0 0 8px #5dd87c"></span> <?= htmlspecialchars($TEXT['armory_label_online'] ?? 'Online') ?></span>
                    <?php else: ?>
                        <span class="online-pill online-off"><span style="width:8px;height:8px;border-radius:50%;background:#6c7a8c;display:inline-block"></span> <?= htmlspecialchars($TEXT['armory_label_offline'] ?? 'Offline') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="armory-quickstats">
                <div class="qstat">
                    <div class="lbl"><i class="bi bi-clock"></i> <?= htmlspecialchars($TEXT['armory_qstat_played'] ?? 'Played') ?></div>
                    <div class="val"><?= htmlspecialchars(format_playtime((int)$char['totaltime'])) ?></div>
                </div>
                <div class="qstat">
                    <div class="lbl"><i class="bi bi-coin"></i> <?= htmlspecialchars($TEXT['armory_qstat_gold'] ?? 'Gold') ?></div>
                    <div class="val"><?= htmlspecialchars(format_gold((int)$char['money'])) ?></div>
                </div>
                <div class="qstat">
                    <div class="lbl"><i class="bi bi-trophy"></i> <?= htmlspecialchars($TEXT['armory_qstat_achievements'] ?? 'Achievements') ?></div>
                    <div class="val"><?= number_format($achievement_count) ?></div>
                </div>
                <div class="qstat">
                    <div class="lbl"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($TEXT['armory_qstat_last_zone'] ?? 'Last Zone') ?></div>
                    <div class="val" style="font-size:.95rem"><?= htmlspecialchars($char['zone'] ?: '—') ?></div>
                </div>
                <div class="qstat">
                    <div class="lbl"><i class="bi bi-clock-history"></i> <?= htmlspecialchars($TEXT['armory_qstat_last_seen'] ?? 'Last Seen') ?></div>
                    <div class="val" style="font-size:.95rem">
                        <?php if ((int)$char['online'] === 1): ?>
                            <?= htmlspecialchars($TEXT['armory_online_now_short'] ?? 'Online now') ?>
                        <?php elseif ((int)$char['logout_time'] > 0): ?>
                            <?= date('M d, Y', (int)$char['logout_time']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN GRID -->
    <div class="row g-3">
        <!-- Equipment doll -->
        <div class="col-lg-8">
            <div class="armory-panel">
                <div class="armory-panel-title"><i class="bi bi-shield me-2"></i><?= htmlspecialchars($TEXT['armory_panel_equipment'] ?? 'Equipment') ?></div>

                <?php if (empty($equipped)): ?>
                    <div class="text-center py-4" style="color:#8899aa">
                        <i class="bi bi-bag-x" style="font-size:2.5rem;opacity:.3"></i>
                        <p class="mt-2 mb-0" style="font-size:.9rem"><?= htmlspecialchars($TEXT['armory_no_equipment'] ?? 'No equipment data found.') ?><br><?= htmlspecialchars($TEXT['armory_no_equipment_hint'] ?? 'Equipment is recorded the next time the character logs out.') ?></p>
                    </div>
                <?php else: ?>
                <div class="gear-grid">
                    <!-- Left column -->
                    <div class="gear-col">
                        <?php foreach ($slot_left as $s):
                            $iid = $equipped[$s] ?? null; ?>
                            <div class="gear-slot <?= $iid ? '' : 'empty' ?>">
                                <span class="gear-icon"<?= $iid ? ' data-wh-icon-id="' . (int)$iid . '"' : '' ?>></span>
                                <div class="gear-meta">
                                    <div class="gear-slot-lbl"><?= htmlspecialchars($equip_slots[$s]) ?></div>
                                    <div class="gear-item-name">
                                        <?php if ($iid): ?>
                                            <a href="<?= htmlspecialchars(wowhead_item_url($iid)) ?>" target="_blank" rel="noopener noreferrer">item=<?= (int)$iid ?></a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($TEXT['armory_slot_empty'] ?? 'Empty') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Centre character portrait — HQ race art, class-colour ring,
                         faction halo; matches the hero avatar (.wl-portrait). -->
                    <div class="gear-center">
                        <div class="wl-portrait gear-avatar">
                            <img class="pf" src="<?= '/' . get_race_icon_path($rid, $gid) ?>" alt="<?= htmlspecialchars(get_race_name($rid)) ?>" loading="lazy">
                            <img class="pc" src="<?= '/' . get_class_icon_path($cid) ?>" alt="<?= htmlspecialchars(get_class_name($cid)) ?>" title="<?= htmlspecialchars(get_class_name($cid)) ?>" loading="lazy">
                        </div>
                        <div>
                            <div class="gear-id-class"><?= htmlspecialchars(get_class_name($cid)) ?></div>
                            <div class="gear-id-race"><?= htmlspecialchars(get_race_name($rid)) ?></div>
                            <div class="gear-id-lvl">Lv <?= (int)$char['level'] ?> · <?= htmlspecialchars(class_role_label($cid)) ?></div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div class="gear-col gear-col-r">
                        <?php foreach ($slot_right as $s):
                            $iid = $equipped[$s] ?? null; ?>
                            <div class="gear-slot <?= $iid ? '' : 'empty' ?>">
                                <span class="gear-icon"<?= $iid ? ' data-wh-icon-id="' . (int)$iid . '"' : '' ?>></span>
                                <div class="gear-meta">
                                    <div class="gear-slot-lbl"><?= htmlspecialchars($equip_slots[$s]) ?></div>
                                    <div class="gear-item-name">
                                        <?php if ($iid): ?>
                                            <a href="<?= htmlspecialchars(wowhead_item_url($iid)) ?>" target="_blank" rel="noopener noreferrer">item=<?= (int)$iid ?></a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($TEXT['armory_slot_empty'] ?? 'Empty') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Weapons row -->
                <div class="gear-bottom-row">
                    <?php foreach ($slot_bot as $s):
                        $iid = $equipped[$s] ?? null; ?>
                        <div class="gear-slot bottom-row <?= $iid ? '' : 'empty' ?>">
                            <span class="gear-icon"<?= $iid ? ' data-wh-icon-id="' . (int)$iid . '"' : '' ?>></span>
                            <div class="gear-meta">
                                <div class="gear-slot-lbl"><?= htmlspecialchars($equip_slots[$s]) ?></div>
                                <div class="gear-item-name">
                                    <?php if ($iid): ?>
                                        <a href="<?= htmlspecialchars(wowhead_item_url($iid)) ?>" target="_blank" rel="noopener noreferrer">item=<?= (int)$iid ?></a>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($TEXT['armory_slot_empty'] ?? 'Empty') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats -->
        <div class="col-lg-4">
            <div class="armory-panel mb-3">
                <div class="armory-panel-title"><i class="bi bi-bar-chart-line me-2"></i><?= htmlspecialchars($TEXT['armory_panel_vitals'] ?? 'Vitals') ?></div>
                <div class="stat-list">
                    <div class="stat-row primary">
                        <span class="k"><i class="bi bi-heart-fill" style="color:#dc3545"></i> <?= htmlspecialchars($TEXT['armory_stat_health'] ?? 'Health') ?></span>
                        <span class="v"><?= number_format((int)$char['health']) ?></span>
                    </div>
                    <?php if ((int)$char['power1'] > 0): ?>
                    <div class="stat-row">
                        <span class="k"><i class="bi bi-droplet-fill" style="color:#0070DE"></i> <?= htmlspecialchars($TEXT['armory_stat_power'] ?? 'Power') ?></span>
                        <span class="v"><?= number_format((int)$char['power1']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($stats): ?>
                        <?php
                        $stat_map = [
                            'maxhealth'   => [$TEXT['armory_stat_max_health']   ?? 'Max Health',   'bi-heart',            '#dc3545'],
                            'maxpower1'   => [$TEXT['armory_stat_max_power']    ?? 'Max Power',    'bi-droplet',          '#0070DE'],
                            'strength'    => [$TEXT['armory_stat_strength']     ?? 'Strength',     'bi-hammer',           'var(--accent)'],
                            'agility'     => [$TEXT['armory_stat_agility']      ?? 'Agility',      'bi-lightning-charge', '#abd473'],
                            'stamina'     => [$TEXT['armory_stat_stamina']      ?? 'Stamina',      'bi-shield-shaded',    '#dc3545'],
                            'intellect'   => [$TEXT['armory_stat_intellect']    ?? 'Intellect',    'bi-stars',            '#69ccf0'],
                            'spirit'      => [$TEXT['armory_stat_spirit']       ?? 'Spirit',       'bi-cloud',            '#e2e8f0'],
                            'armor'       => [$TEXT['armory_stat_armor']        ?? 'Armor',        'bi-shield',           '#8899aa'],
                            'attackPower' => [$TEXT['armory_stat_attack_power'] ?? 'Attack Power', 'bi-bullseye',         '#ff7d0a'],
                        ];
                        foreach ($stat_map as $col => $info):
                            if (!isset($stats[$col]) || (int)$stats[$col] <= 0) continue;
                        ?>
                        <div class="stat-row">
                            <span class="k"><i class="bi <?= $info[1] ?>" style="color:<?= $info[2] ?>"></i> <?= htmlspecialchars($info[0]) ?></span>
                            <span class="v"><?= number_format((int)$stats[$col]) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="armory-panel">
                <div class="armory-panel-title"><i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($TEXT['armory_panel_info'] ?? 'Info') ?></div>
                <div class="stat-list">
                    <?php
                    $faction_label_map = [
                        'alliance' => $TEXT['armory_label_alliance'] ?? 'Alliance',
                        'horde'    => $TEXT['armory_label_horde']    ?? 'Horde',
                        'neutral'  => $TEXT['armory_label_neutral']  ?? 'Neutral',
                    ];
                    ?>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_faction'] ?? 'Faction') ?></span>
                        <span class="v"><?= htmlspecialchars($faction_label_map[$faction] ?? $faction) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_race'] ?? 'Race') ?></span>
                        <span class="v"><?= htmlspecialchars(get_race_name($rid)) ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_class'] ?? 'Class') ?></span>
                        <span class="v" style="color:<?= $clr ?>"><?= htmlspecialchars(get_class_name($cid)) ?></span>
                    </div>
                    <?php if (!empty($char['guild_name'])): ?>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_guild'] ?? 'Guild') ?></span>
                        <span class="v"><a href="/guild/<?= rawurlencode($char['guild_name']) ?>" style="color:var(--accent);text-decoration:none">&lt;<?= htmlspecialchars($char['guild_name']) ?>&gt;</a></span>
                    </div>
                    <?php endif; ?>
                    <?php $title_text = $char_title_id > 0 ? wl_title_text($char_title_id) : null; if ($title_text): ?>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_title'] ?? 'Title') ?></span>
                        <span class="v" style="color:var(--accent)"><?= htmlspecialchars($title_text) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_gender'] ?? 'Gender') ?></span>
                        <span class="v"><?= htmlspecialchars($gid === 1 ? ($TEXT['armory_gender_female'] ?? 'Female') : ($TEXT['armory_gender_male'] ?? 'Male')) ?></span>
                    </div>
                    <?php if ($account_join): ?>
                    <div class="stat-row">
                        <span class="k"><?= htmlspecialchars($TEXT['armory_info_account_created'] ?? 'Account Created') ?></span>
                        <span class="v"><?= date('M Y', strtotime($account_join)) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Talents & Specialization — in-game-style 6×3 grid when we have a
         verified MoP map for this class; falls back to the existing chip
         cloud (chosen spells only) when not. -->
    <?php
    $mop_tiers   = wl_mop_talents($cid);
    $use_grid    = wl_mop_talents_have_data($mop_tiers);
    if (!empty($talent_groups) || $use_grid):
    ?>
    <style>
    .tal-spec { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin:.2rem 0 .9rem; }
    .tal-spec .nm { color:var(--accent); font-weight:700; font-size:1rem; }
    .tal-spec .badge-act { background:rgba(105,204,240,.15); border:1px solid rgba(105,204,240,.45); color:#69ccf0; font-size:.65rem; text-transform:uppercase; letter-spacing:.5px; padding:.12rem .55rem; border-radius:10px; }
    .tal-spec .badge-alt { color:#4a5568; font-size:.74rem; }
    .tal-grid { display:flex; flex-wrap:wrap; gap:.55rem; }
    .tal-chip { background:linear-gradient(145deg,#1a1a26,#12121b); border:1px solid rgba(var(--btn-bg-rgb), .35); border-radius:8px; padding:.5rem .75rem; font-size:.85rem; max-width:100%; }
    .tal-chip a { color:#dee2e6; text-decoration:none; }
    .tal-chip a:hover { color:#fff; }
    .tal-group + .tal-group { margin-top:1.1rem; border-top:1px solid rgba(var(--btn-bg-rgb), .2); padding-top:1rem; }
    .tal-empty { color:#8899aa; font-size:.88rem; }

    /* In-game-style 6×3 talent grid (when wl_mop_talents has data for the class) */
    .tal-tiers { display:flex; flex-direction:column; gap:.5rem; }
    .tal-tier  { display:grid; grid-template-columns: 56px repeat(3, minmax(0,1fr)); gap:.5rem; align-items:stretch; }
    .tal-tier-lvl {
        display:flex; align-items:center; justify-content:center;
        background:rgba(var(--accent-rgb), .08);
        border:1px solid rgba(var(--accent-rgb), .2);
        border-radius:8px; color:var(--accent); font-weight:800;
        font-variant-numeric: tabular-nums; font-size:.95rem;
    }
    .tal-cell {
        background:linear-gradient(145deg,#1a1a26,#12121b);
        border:1px solid rgba(var(--btn-bg-rgb), .3);
        border-radius:8px; padding:.55rem .75rem; min-width:0;
        display:flex; align-items:center; gap:.5rem;
        font-size:.85rem; transition: all .15s ease;
        opacity:.55;
    }
    .tal-cell a { color:#cdd5e0; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width:0; }
    .tal-cell.chosen {
        opacity:1;
        background: linear-gradient(145deg, <?= $clr ?>1f, <?= $clr ?>0d);
        border-color: <?= $clr ?>;
        box-shadow: 0 0 18px -6px <?= $clr ?>aa, inset 0 0 0 1px <?= $clr ?>33;
    }
    .tal-cell.chosen a { color:<?= $clr ?>; font-weight:700; }
    .tal-cell.unknown { opacity:.25; }
    @media (max-width: 575px) {
        .tal-tier { grid-template-columns: 1fr; }
        .tal-tier-lvl { padding:.25rem 0; }
    }
    </style>
    <div class="armory-panel mt-3">
        <div class="armory-panel-title"><i class="bi bi-diagram-3 me-2"></i><?= htmlspecialchars($TEXT['armory_panel_talents'] ?? 'Talents') ?></div>
        <?php
        // Active talent group first, then any other group that has talents.
        $tal_order = !empty($talent_groups) ? array_keys($talent_groups) : [$active_spec];
        usort($tal_order, fn($a, $b) => ($a === $active_spec ? -1 : ($b === $active_spec ? 1 : $a - $b)));
        foreach ($tal_order as $g):
            $spells = $talent_groups[$g] ?? [];
            $specNm = mop_spec_name($spec_ids[$g] ?? 0);
            $chosen_set = array_flip(array_map('intval', $spells));
        ?>
        <div class="tal-group">
            <div class="tal-spec">
                <?php if ($specNm !== null): ?>
                    <span class="nm"><?= htmlspecialchars($TEXT['armory_spec_label'] ?? 'Specialization') ?>: <?= htmlspecialchars($specNm) ?></span>
                <?php else: ?>
                    <span class="nm"><?= htmlspecialchars(($TEXT['armory_spec_group'] ?? 'Talent set') . ' ' . ($g + 1)) ?></span>
                <?php endif; ?>
                <?php if ($g === $active_spec): ?>
                    <span class="badge-act"><?= htmlspecialchars($TEXT['armory_spec_active'] ?? 'Active') ?></span>
                <?php else: ?>
                    <span class="badge-alt"><?= htmlspecialchars($TEXT['armory_spec_offspec'] ?? 'Off-spec') ?></span>
                <?php endif; ?>
            </div>

            <?php if ($use_grid): ?>
                <!-- New 6×3 in-game-style grid -->
                <div class="tal-tiers">
                    <?php foreach ($mop_tiers as $tierIdx => $row):
                        $tierLvl = wl_mop_talent_tier_level($tierIdx);
                    ?>
                    <div class="tal-tier" title="<?= sprintf(htmlspecialchars($TEXT['armory_talents_tier'] ?? 'Tier %d (Lv %d)'), $tierIdx + 1, $tierLvl) ?>">
                        <div class="tal-tier-lvl">Lv<br><?= $tierLvl ?></div>
                        <?php foreach ($row as $sp):
                            $sp = (int)$sp;
                            $is_chosen = $sp > 0 && isset($chosen_set[$sp]);
                            $cls = $sp <= 0 ? 'unknown' : ($is_chosen ? 'chosen' : '');
                        ?>
                            <?php if ($sp > 0): ?>
                            <div class="tal-cell <?= $cls ?>">
                                <a href="<?= htmlspecialchars(wowhead_spell_url($sp)) ?>" target="_blank" rel="noopener noreferrer">spell=<?= $sp ?></a>
                            </div>
                            <?php else: ?>
                            <div class="tal-cell unknown" title="<?= htmlspecialchars($TEXT['armory_talents_unknown'] ?? 'No verified spell ID for this slot yet') ?>">
                                <span style="color:#4a5568">—</span>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (empty($spells)): ?>
                <div class="tal-empty"><?= htmlspecialchars($TEXT['armory_no_talents'] ?? 'No talents chosen yet.') ?></div>
            <?php else: ?>
                <!-- Fallback chip cloud (chosen spells only) — used when the class's
                     MoP talent map isn't filled yet. -->
                <div class="tal-grid">
                    <?php foreach ($spells as $sp): ?>
                        <span class="tal-chip"><a href="<?= htmlspecialchars(wowhead_spell_url($sp)) ?>" target="_blank" rel="noopener noreferrer">spell=<?= (int)$sp ?></a></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div style="color:#4a5568;font-size:.76rem;margin-top:.8rem">
            <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['armory_talents_hint'] ?? 'Talent details come from Wowhead (Mists of Pandaria). Heavily customised server spells may not resolve.') ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Glyphs — chosen major + minor per spec, Wowhead-resolved chips, with
         "Unlocked at Lv N" hint for empty unlocked slots. Hidden when the
         character_glyphs table isn't present on this repack. -->
    <?php if ($glyphs_query_ok):
        // MoP unlock thresholds: slots 1-3 (major) = Lv 25/50/75; slots 4-6 (minor) = 25/50/75
        $g_unlock = [25, 50, 75, 25, 50, 75];
        // Active spec first, then any other spec that has a glyph row.
        $g_order = [];
        if (isset($char_glyphs[$active_spec])) $g_order[] = $active_spec;
        foreach ($char_glyphs as $spec => $_g) if ($spec !== $active_spec) $g_order[] = $spec;
        $char_level = (int)$char['level'];
    ?>
    <style>
    .gly-row { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:.55rem; margin-bottom:.55rem; }
    .gly-row.minor { opacity: .92; }
    .gly-slot {
        background: linear-gradient(145deg,#1a1a26,#12121b); border:1px solid rgba(var(--btn-bg-rgb), .35);
        border-radius:8px; padding:.5rem .7rem; font-size:.85rem;
        display:flex; align-items:center; gap:.5rem; min-width:0;
    }
    .gly-slot.empty { opacity:.55; font-style:italic; color:#6c7a8c; }
    .gly-slot a { color:#dee2e6; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .gly-slot a:hover { color:#fff; }
    .gly-tag { font-size:.6rem; text-transform:uppercase; letter-spacing:.5px; color:#6c7a8c; padding:.05rem .35rem; border-radius:4px; border:1px solid rgba(255,255,255,.08); flex-shrink:0; }
    .gly-tag.major { color:var(--accent); border-color:rgba(var(--accent-rgb),.35); }
    .gly-tag.minor { color:#8899aa; }
    .gly-spec { display:flex; align-items:center; gap:.6rem; margin:.2rem 0 .8rem; flex-wrap:wrap; }
    .gly-spec .nm { color:var(--accent); font-weight:700; font-size:1rem; }
    .gly-spec .badge-act { background:rgba(105,204,240,.15); border:1px solid rgba(105,204,240,.45); color:#69ccf0; font-size:.65rem; text-transform:uppercase; letter-spacing:.5px; padding:.12rem .55rem; border-radius:10px; }
    @media (max-width: 575px) { .gly-row { grid-template-columns: 1fr; } }
    </style>
    <div class="armory-panel mt-3">
        <div class="armory-panel-title"><i class="bi bi-gem me-2"></i><?= htmlspecialchars($TEXT['armory_panel_glyphs'] ?? 'Glyphs') ?></div>
        <?php foreach ($g_order as $spec):
            $g = $char_glyphs[$spec];
            $spec_name = mop_spec_name($spec_ids[$spec] ?? 0) ?? sprintf($TEXT['armory_spec_n'] ?? 'Spec %d', $spec + 1);
            $is_active = ($spec === $active_spec);
        ?>
            <div class="gly-spec">
                <span class="nm"><?= htmlspecialchars($spec_name) ?></span>
                <?php if ($is_active): ?><span class="badge-act"><?= htmlspecialchars($TEXT['armory_active_spec'] ?? 'Active') ?></span><?php endif; ?>
            </div>
            <?php foreach ([['major', 0, 1, 2], ['minor', 3, 4, 5]] as $group):
                $kind = array_shift($group); ?>
                <div class="gly-row <?= $kind ?>">
                    <?php foreach ($group as $idx):
                        $sid = (int)$g[$idx];
                        $unlock = $g_unlock[$idx];
                    ?>
                        <?php if ($sid > 0): ?>
                            <div class="gly-slot">
                                <span class="gly-tag <?= $kind ?>"><?= htmlspecialchars(strtoupper($kind === 'major' ? ($TEXT['armory_gly_major'] ?? 'Major') : ($TEXT['armory_gly_minor'] ?? 'Minor'))) ?></span>
                                <a href="<?= 'https://www.wowhead.com/mop-classic/spell=' . (int)$sid ?>" target="_blank" rel="noopener noreferrer" data-wh-rename-link="true">spell=<?= (int)$sid ?></a>
                            </div>
                        <?php elseif ($char_level >= $unlock): ?>
                            <div class="gly-slot empty">
                                <span class="gly-tag <?= $kind ?>"><?= htmlspecialchars(strtoupper($kind === 'major' ? ($TEXT['armory_gly_major'] ?? 'Major') : ($TEXT['armory_gly_minor'] ?? 'Minor'))) ?></span>
                                <span><?= htmlspecialchars($TEXT['armory_gly_empty'] ?? 'Empty') ?></span>
                            </div>
                        <?php else: ?>
                            <div class="gly-slot empty">
                                <span class="gly-tag <?= $kind ?>"><?= htmlspecialchars(strtoupper($kind === 'major' ? ($TEXT['armory_gly_major'] ?? 'Major') : ($TEXT['armory_gly_minor'] ?? 'Minor'))) ?></span>
                                <span><?= sprintf(htmlspecialchars($TEXT['armory_gly_unlock_at'] ?? 'Unlocked at Lv %d'), $unlock) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (empty($g_order)): ?>
            <div style="color:#6c7a8c;font-size:.85rem;padding:.4rem 0"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['armory_gly_none'] ?? 'No glyphs recorded for this character.') ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PvP — total HKs + rated bracket ratings (current season). Hidden
         when there's no data (e.g., rated_pvp_info table absent or no rows). -->
    <?php
    $pvp_has = ($pvp_total_kills !== null) || !empty($pvp_rated);
    if ($pvp_has):
        $bracket_label = [0 => '2v2', 1 => '3v3', 3 => 'Rated BG'];
    ?>
    <style>
    .pvp-grid { display:grid; grid-template-columns: minmax(180px, 1fr) 2fr; gap:1rem; }
    .pvp-card { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.3); border-radius:10px; padding:1rem 1.1rem; }
    .pvp-card .lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:.4rem; }
    .pvp-card .big { font-size:2rem; font-weight:800; color:#f87e8a; line-height:1; font-variant-numeric: tabular-nums; }
    .pvp-tbl { width:100%; border-collapse: collapse; }
    .pvp-tbl th { text-align:left; font-size:.7rem; color:#6c7a8c; text-transform:uppercase; letter-spacing:1px; padding:.35rem .6rem; font-weight:600; }
    .pvp-tbl td { padding:.55rem .6rem; border-top:1px solid rgba(255,255,255,.04); font-size:.92rem; }
    .pvp-tbl tr:first-child td { border-top: none; }
    .pvp-bracket { display:inline-flex; align-items:center; gap:.45rem; }
    .pvp-bracket .pill { padding:.18rem .55rem; border-radius:50px; background: rgba(var(--accent-rgb),.14); color:var(--accent); border:1px solid rgba(var(--accent-rgb),.4); font-size:.72rem; font-weight:700; letter-spacing:.5px; }
    .pvp-rating { color:#dee2e6; font-weight:800; font-variant-numeric: tabular-nums; }
    .pvp-empty  { color:#6c7a8c; font-size:.85rem; padding:.6rem; }
    @media (max-width: 768px) {
        .pvp-grid { grid-template-columns: 1fr; }
    }
    </style>
    <div class="armory-panel mt-3">
        <div class="armory-panel-title"><i class="bi bi-crosshair me-2"></i><?= htmlspecialchars($TEXT['armory_panel_pvp'] ?? 'PvP') ?></div>
        <div class="pvp-grid">
            <div class="pvp-card">
                <div class="lbl"><?= htmlspecialchars($TEXT['armory_pvp_total_hk'] ?? 'Total Honorable Kills') ?></div>
                <div class="big"><?= number_format((int)($pvp_total_kills ?? 0)) ?></div>
            </div>
            <div class="pvp-card">
                <div class="lbl" style="margin-bottom:.55rem"><?= htmlspecialchars($TEXT['armory_pvp_rated'] ?? 'Rated PvP (current season)') ?></div>
                <?php if (!empty($pvp_rated)): ?>
                    <table class="pvp-tbl">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($TEXT['armory_pvp_bracket'] ?? 'Bracket') ?></th>
                                <th><?= htmlspecialchars($TEXT['armory_pvp_rating'] ?? 'Rating') ?></th>
                                <th><?= htmlspecialchars($TEXT['armory_pvp_wins'] ?? 'Wins') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pvp_rated as $row):
                                $slot = (int)$row['slot'];
                                $lbl  = $bracket_label[$slot] ?? ('Slot ' . $slot);
                            ?>
                            <tr>
                                <td><span class="pvp-bracket"><span class="pill"><?= htmlspecialchars($lbl) ?></span></span></td>
                                <td class="pvp-rating"><?= number_format((int)$row['rating']) ?></td>
                                <td><?= number_format((int)$row['season_wins']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="pvp-empty"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($TEXT['armory_pvp_no_rated'] ?? 'No rated PvP recorded for the current season.') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Professions — primary + secondary skills with value/max bars.
         Filtered through wl_skill_name() so we only show known skills
         (no "Skill #1234" noise). Hidden when nothing matches. -->
    <?php
    $prof_primary = []; $prof_secondary = [];
    foreach ($char_skills as $s) {
        $sid = (int)$s['skill'];
        $name = wl_skill_name($sid);
        if ($name === null) continue;
        $row = ['name' => $name, 'value' => (int)$s['value'], 'max' => max(1, (int)$s['max'])];
        if (wl_skill_is_primary($sid)) $prof_primary[]   = $row;
        else                            $prof_secondary[] = $row;
    }
    // Render the panel whenever the table exists (even with 0 known skills) so
    // it sits in the layout consistently — matches how PvP shows at 0 HKs.
    // Only hide it when character_skills isn't queryable on this repack.
    $prof_has = $skills_query_ok;
    if ($prof_has):
    ?>
    <style>
    .prof-grid { display:grid; grid-template-columns: 1fr 1fr; gap:1rem; }
    .prof-col-title { color:#8899aa; font-size:.72rem; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:.6rem; }
    .prof-row { background: rgba(255,255,255,.025); border:1px solid rgba(var(--btn-bg-rgb),.25); border-radius:8px; padding:.55rem .75rem; margin-bottom:.5rem; }
    .prof-row .hd { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:.3rem; font-size:.9rem; }
    .prof-row .nm { color:#dee2e6; font-weight:700; }
    .prof-row .vl { color:var(--accent); font-weight:700; font-variant-numeric: tabular-nums; font-size:.85rem; }
    .prof-bar { height: 6px; border-radius: 999px; background: rgba(255,255,255,.05); overflow: hidden; }
    .prof-bar > i { display:block; height:100%; background: linear-gradient(90deg, var(--accent-dim), var(--accent)); border-radius:999px; }
    @media (max-width: 768px) {
        .prof-grid { grid-template-columns: 1fr; }
    }
    </style>
    <div class="armory-panel mt-3">
        <div class="armory-panel-title"><i class="bi bi-hammer me-2"></i><?= htmlspecialchars($TEXT['armory_panel_professions'] ?? 'Professions') ?></div>
        <div class="prof-grid">
            <div>
                <div class="prof-col-title"><?= htmlspecialchars($TEXT['armory_prof_primary'] ?? 'Primary') ?></div>
                <?php if (!empty($prof_primary)): foreach ($prof_primary as $p):
                    $pct = max(0, min(100, (int)round(($p['value'] / $p['max']) * 100))); ?>
                    <div class="prof-row">
                        <div class="hd"><span class="nm"><?= htmlspecialchars($p['name']) ?></span><span class="vl"><?= (int)$p['value'] ?> / <?= (int)$p['max'] ?></span></div>
                        <div class="prof-bar"><i style="width:<?= $pct ?>%"></i></div>
                    </div>
                <?php endforeach; else: ?>
                    <div style="color:#6c7a8c;font-size:.85rem"><i class="bi bi-dash-circle me-1"></i><?= htmlspecialchars($TEXT['armory_prof_none_primary'] ?? 'No primary professions learned.') ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="prof-col-title"><?= htmlspecialchars($TEXT['armory_prof_secondary'] ?? 'Secondary') ?></div>
                <?php if (!empty($prof_secondary)): foreach ($prof_secondary as $p):
                    $pct = max(0, min(100, (int)round(($p['value'] / $p['max']) * 100))); ?>
                    <div class="prof-row">
                        <div class="hd"><span class="nm"><?= htmlspecialchars($p['name']) ?></span><span class="vl"><?= (int)$p['value'] ?> / <?= (int)$p['max'] ?></span></div>
                        <div class="prof-bar"><i style="width:<?= $pct ?>%"></i></div>
                    </div>
                <?php endforeach; else: ?>
                    <div style="color:#6c7a8c;font-size:.85rem"><i class="bi bi-dash-circle me-1"></i><?= htmlspecialchars($TEXT['armory_prof_none_secondary'] ?? 'No secondary skills.') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Other characters on this account -->
    <?php if (!empty($siblings)): ?>
    <div class="armory-panel mt-3">
        <div class="armory-panel-title"><i class="bi bi-people me-2"></i><?= htmlspecialchars($TEXT['armory_panel_siblings'] ?? 'More Characters on This Account') ?></div>
        <div class="sib-grid">
            <?php foreach ($siblings as $sib):
                $scid = (int)$sib['class'];
                $sclr = $class_colors[$scid] ?? 'var(--accent)';
            ?>
            <a href="/armory/<?= rawurlencode($sib['name']) ?>" class="sib-card" style="border-left-color:<?= $sclr ?>">
                <img src="<?= '/' . get_race_icon_path((int)$sib['race'], (int)$sib['gender']) ?>" alt="">
                <img src="<?= '/' . get_class_icon_path($scid) ?>" alt="">
                <div style="min-width:0">
                    <div class="sn" style="color:<?= $sclr ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($sib['name']) ?></div>
                    <div class="sm">Lv <?= (int)$sib['level'] ?> <?= htmlspecialchars(get_class_name($scid)) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Wowhead tooltips: turns item=ID links into colored item names + hover tooltips -->
<script>
const whTooltips = { colorLinks: true, iconizeLinks: true, renameLinks: true };
</script>
<script src="https://wow.zamimg.com/widgets/power.js"></script>
<script>
// Resolve item icons through Wowhead's JSON endpoint. It serves CORS for any origin.
// We try mop-classic first (dataEnv=12), then fall back to retail. If both fail, the
// styled question-mark placeholder remains — graceful degradation.
(function() {
    const slots = document.querySelectorAll('.gear-icon[data-wh-icon-id]');
    if (!slots.length) return;

    function setIcon(el, iconName) {
        if (!iconName) return;
        el.style.backgroundImage = `url(https://wow.zamimg.com/images/wow/icons/medium/${iconName.toLowerCase()}.jpg)`;
        el.style.backgroundSize  = 'cover';
        el.classList.add('icon-loaded');
    }

    slots.forEach(el => {
        const id = el.getAttribute('data-wh-icon-id');
        if (!id) return;
        // dataEnv=12 = MoP Classic, fall back to retail (no env)
        fetch('https://nether.wowhead.com/tooltip/item/' + id + '?dataEnv=12&locale=0')
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(d => d && d.icon ? setIcon(el, d.icon) : Promise.reject())
            .catch(() => fetch('https://nether.wowhead.com/tooltip/item/' + id)
                .then(r => r.ok ? r.json() : null)
                .then(d => d && d.icon && setIcon(el, d.icon))
                .catch(()=>{}));
    });
})();
</script>
<style>
/* Make the question-mark placeholder look intentional, not broken */
.gear-icon:not(.icon-loaded) {
    background:
        radial-gradient(circle at 35% 30%, rgba(var(--accent-rgb), .18), transparent 60%),
        linear-gradient(135deg, #1a1f2e, #0a0d18) !important;
    position: relative;
}
.gear-icon:not(.icon-loaded)::after {
    content: '?';
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    color: rgba(var(--accent-rgb), .4); font-weight: 700; font-size: 1.1rem;
    font-family: serif;
}
</style>

<?php
    require_once __DIR__ . '/../templates/footer.php';
    exit;
}

// ============================================================================
// LIST / SEARCH MODE
// ============================================================================

$q          = trim((string)($_GET['q'] ?? ''));
$f_class    = isset($_GET['class']) ? (int)$_GET['class'] : 0;
$f_race     = isset($_GET['race'])  ? (int)$_GET['race']  : 0;
$f_faction  = $_GET['faction'] ?? '';
$f_min_lvl  = max(1,  (int)($_GET['min'] ?? 1));
$f_max_lvl  = min(90, (int)($_GET['max'] ?? 90));
$f_sort     = $_GET['sort'] ?? 'level';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 24;

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    if (strlen($q) > 32) $q = substr($q, 0, 32);
    $where[] = 'c.name LIKE :q';
    $params['q'] = $q . '%';
}
if ($f_class) { $where[] = 'c.class = :cls'; $params['cls'] = $f_class; }
if ($f_race)  { $where[] = 'c.race  = :rce'; $params['rce'] = $f_race; }
if ($f_faction === 'alliance' && $alliance_races) {
    $where[] = 'c.race IN (' . implode(',', $alliance_races) . ')';
}
if ($f_faction === 'horde' && $horde_races) {
    $where[] = 'c.race IN (' . implode(',', $horde_races) . ')';
}
$where[]        = 'c.level BETWEEN :minlvl AND :maxlvl';
$params['minlvl'] = $f_min_lvl;
$params['maxlvl'] = $f_max_lvl;

$sort_sql = match ($f_sort) {
    'name'     => 'c.name ASC',
    'recent'   => 'c.logout_time DESC',
    'gold'     => 'c.money DESC',
    'playtime' => 'c.totaltime DESC',
    default    => 'c.level DESC, c.totaltime DESC',
};

// Total
$total = 0;
try {
    $cstmt = $pdo_chars->prepare("SELECT COUNT(*) FROM characters c WHERE " . implode(' AND ', $where));
    $cstmt->execute($params);
    $total = (int)$cstmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Armory count failed: ' . $e->getMessage());
}

$offset    = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total / $per_page));
if ($page > $total_pages) $page = $total_pages;

// Page rows
$rows = [];
try {
    $sql = "SELECT c.guid, c.name, c.race, c.class, c.gender, c.level, c.online,
                   c.totaltime, c.money, c.zone, c.logout_time,
                   g.name AS guild_name
            FROM characters c
            LEFT JOIN guild_member gm ON gm.guid = c.guid
            LEFT JOIN guild g ON g.guildid = gm.guildid
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $sort_sql
            LIMIT $per_page OFFSET " . (int)$offset;
    $rs = $pdo_chars->prepare($sql);
    foreach ($params as $k => $v) {
        $rs->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $rs->execute();
    $rows = $rs->fetchAll();
} catch (PDOException $e) {
    error_log('Armory list query failed: ' . $e->getMessage());
}

// Online totals snapshot
$total_online = 0;
$total_chars  = 0;
try {
    $total_chars  = (int)$pdo_chars->query('SELECT COUNT(*) FROM characters')->fetchColumn();
    $total_online = (int)$pdo_chars->query('SELECT COUNT(*) FROM characters WHERE online = 1')->fetchColumn();
} catch (PDOException $e) {}

// Build query string for pagination
function build_qs(array $overrides = []): string {
    $base = $_GET;
    foreach ($overrides as $k => $v) $base[$k] = $v;
    unset($base['char']);
    return '?' . http_build_query($base);
}
?>

<style>
.armory-wrap { padding-top: 90px; padding-bottom: 3rem; }

.armory-banner {
    position: relative;
    border-radius: 18px;
    padding: 2.4rem 1.8rem 2rem;
    margin-bottom: 1.5rem;
    background:
        linear-gradient(135deg, rgba(var(--btn-bg-rgb), .4), rgba(10,10,20,.92) 65%),
        url('/assets/img/wow-bg/4-2.webp') center/cover no-repeat;
    border: 1px solid rgba(var(--accent-rgb), .35);
    overflow: hidden;
}
.armory-banner h1 {
    font-size: clamp(1.8rem, 3.5vw, 2.6rem);
    font-weight: 800;
    background: linear-gradient(90deg,var(--accent),#fff 60%,var(--accent));
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
    margin: 0;
}
.armory-banner p { color: rgba(255,255,255,.75); margin-bottom: 1.2rem; }
.armory-stats { display: flex; gap: 1.4rem; flex-wrap: wrap; font-size: .85rem; color:#8899aa; }
.armory-stats strong { color:var(--accent); font-weight:700; }

.search-form {
    background: linear-gradient(145deg,#12121f,#1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 14px;
    padding: 1.2rem;
    margin-bottom: 1.4rem;
}
.search-form .form-control, .search-form .form-select {
    background: rgba(0,0,0,.35); color: #e2e8f0;
    border: 1px solid rgba(var(--accent-rgb), .2);
    font-size: .88rem;
}
.search-form .form-control:focus, .search-form .form-select:focus {
    background: rgba(0,0,0,.5);
    border-color: var(--accent);
    box-shadow: 0 0 0 .2rem rgba(var(--accent-rgb), .18);
    color: #fff;
}
.search-form label { font-size: .72rem; text-transform: uppercase; letter-spacing: 1px; color:#8899aa; margin-bottom: .25rem; }

.results-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: .75rem;
}
.result-card {
    display: flex; align-items: center; gap: .75rem;
    background: linear-gradient(145deg,#12121f,#1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-left: 4px solid;
    border-radius: 12px;
    padding: .85rem 1rem;
    text-decoration: none;
    transition: all .2s ease;
}
.result-card:hover {
    background: linear-gradient(145deg,#1a1a2e,#12121f);
    border-color: rgba(var(--accent-rgb), .6);
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(0,0,0,.35);
}
.result-card .icons { display: flex; flex-direction: column; gap: 3px; }
.result-card .icons img { width: 28px; height: 28px; border-radius: 5px; border: 1px solid rgba(255,255,255,.15); }
.result-card .info { flex: 1; min-width: 0; }
.result-card .info .name { font-weight: 700; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.result-card .info .meta { font-size: .76rem; color: #8899aa; margin-top: 1px; }
.result-card .info .extra { font-size: .72rem; color: #6c7a8c; margin-top: 3px; }
.result-card .level-pill {
    background: rgba(var(--btn-bg-rgb), .4); color: var(--accent); font-weight: 700;
    padding: .25rem .55rem; border-radius: 6px; font-size: .78rem;
    flex-shrink: 0;
}
.result-card .online-dot {
    display:inline-block;width:7px;height:7px;border-radius:50%;
    background:#5dd87c;box-shadow:0 0 6px #5dd87c;margin-right:4px;vertical-align: middle;
}

.armory-empty {
    background: linear-gradient(145deg,#12121f,#1a1a2e);
    border: 1px solid rgba(var(--btn-bg-rgb), .3);
    border-radius: 14px;
    padding: 3rem 2rem;
}

.armory-pager { display: flex; justify-content: center; align-items: center; gap: .35rem; margin-top: 1.5rem; flex-wrap: wrap; }
.armory-pager a, .armory-pager span {
    padding: .45rem .85rem; border-radius: 8px;
    background: rgba(255,255,255,.04); border: 1px solid rgba(var(--accent-rgb), .2);
    color: var(--accent); font-size: .85rem; text-decoration: none;
    min-width: 38px; text-align: center;
}
.armory-pager a:hover { background: rgba(var(--accent-rgb), .12); border-color: var(--accent); }
.armory-pager .active { background: linear-gradient(135deg,var(--btn-bg),var(--btn-bg-hover)); color:#fff; border-color:var(--btn-bg-hover); }
.armory-pager .disabled { opacity: .35; pointer-events: none; }
</style>

<div class="container armory-wrap">

    <!-- Banner -->
    <div class="armory-banner">
        <h1><i class="bi bi-search me-2"></i><?= htmlspecialchars($TEXT['armory_title'] ?? 'Public Armory') ?></h1>
        <p><?= htmlspecialchars($TEXT['armory_subtitle'] ?? 'Search any character on the realm — view gear, stats, achievements and more.') ?></p>
        <div class="armory-stats">
            <span><strong><?= number_format($total_chars) ?></strong> <?= htmlspecialchars($TEXT['armory_characters_count'] ?? 'characters') ?></span>
            <span><strong style="color:#5dd87c"><?= number_format($total_online) ?></strong> <?= htmlspecialchars($TEXT['armory_online_now'] ?? 'online now') ?></span>
            <?php if ($q !== ''):
                $results_word = $total === 1
                    ? ($TEXT['armory_results_for']        ?? 'result for')
                    : ($TEXT['armory_results_for_plural'] ?? 'results for');
            ?>
                <span><strong><?= number_format($total) ?></strong> <?= htmlspecialchars($results_word) ?> "<em><?= htmlspecialchars($q) ?></em>"</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Search/Filters -->
    <form class="search-form" method="get" action="/armory">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="q"><?= htmlspecialchars($TEXT['armory_search_label'] ?? 'Search by name') ?></label>
                <input type="text" class="form-control" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars($TEXT['armory_search_placeholder'] ?? 'Type a character name…') ?>" autofocus>
            </div>
            <div class="col-md-2 col-6">
                <label for="class"><?= htmlspecialchars($TEXT['armory_filter_class'] ?? 'Class') ?></label>
                <select class="form-select" id="class" name="class">
                    <option value="0"><?= htmlspecialchars($TEXT['armory_filter_any'] ?? 'Any') ?></option>
                    <?php for ($i = 1; $i <= 11; $i++): ?>
                        <option value="<?= $i ?>" <?= $f_class === $i ? 'selected' : '' ?>><?= htmlspecialchars(get_class_name($i)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label for="race"><?= htmlspecialchars($TEXT['armory_filter_race'] ?? 'Race') ?></label>
                <select class="form-select" id="race" name="race">
                    <option value="0"><?= htmlspecialchars($TEXT['armory_filter_any'] ?? 'Any') ?></option>
                    <?php
                    $race_ids = [1,2,3,4,5,6,7,8,9,10,11,22,24,25,26];
                    foreach ($race_ids as $rid_opt): ?>
                        <option value="<?= $rid_opt ?>" <?= $f_race === $rid_opt ? 'selected' : '' ?>><?= htmlspecialchars(get_race_name($rid_opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label for="faction"><?= htmlspecialchars($TEXT['armory_filter_faction'] ?? 'Faction') ?></label>
                <select class="form-select" id="faction" name="faction">
                    <option value=""><?= htmlspecialchars($TEXT['armory_filter_any'] ?? 'Any') ?></option>
                    <option value="alliance" <?= $f_faction === 'alliance' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?></option>
                    <option value="horde"    <?= $f_faction === 'horde'    ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_label_horde']    ?? 'Horde') ?></option>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label for="sort"><?= htmlspecialchars($TEXT['armory_sort_by'] ?? 'Sort by') ?></label>
                <select class="form-select" id="sort" name="sort">
                    <option value="level"    <?= $f_sort === 'level'    ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_sort_level']    ?? 'Level (high→low)') ?></option>
                    <option value="name"     <?= $f_sort === 'name'     ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_sort_name']     ?? 'Name (A→Z)') ?></option>
                    <option value="recent"   <?= $f_sort === 'recent'   ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_sort_recent']   ?? 'Recently active') ?></option>
                    <option value="gold"     <?= $f_sort === 'gold'     ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_sort_gold']     ?? 'Gold') ?></option>
                    <option value="playtime" <?= $f_sort === 'playtime' ? 'selected' : '' ?>><?= htmlspecialchars($TEXT['armory_sort_playtime'] ?? 'Playtime') ?></option>
                </select>
            </div>

            <div class="col-md-3 col-6">
                <label><?= htmlspecialchars($TEXT['armory_filter_min_level'] ?? 'Min level') ?></label>
                <input type="number" class="form-control" name="min" min="1" max="90" value="<?= (int)$f_min_lvl ?>">
            </div>
            <div class="col-md-3 col-6">
                <label><?= htmlspecialchars($TEXT['armory_filter_max_level'] ?? 'Max level') ?></label>
                <input type="number" class="form-control" name="max" min="1" max="90" value="<?= (int)$f_max_lvl ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-gold flex-grow-1"><i class="bi bi-search me-1"></i> <?= htmlspecialchars($TEXT['armory_search_button'] ?? 'Search') ?></button>
                <a href="/armory" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </div>
    </form>

    <!-- Results -->
    <?php if (empty($rows)): ?>
        <div class="armory-empty text-center">
            <div style="font-size:3.5rem;opacity:.25"><i class="bi bi-people"></i></div>
            <h3 style="color:var(--accent)"><?= htmlspecialchars($TEXT['armory_no_results'] ?? 'No characters found') ?></h3>
            <p style="color:#8899aa"><?= htmlspecialchars($TEXT['armory_no_results_hint'] ?? 'Try widening your filters or searching for a different name.') ?></p>
        </div>
    <?php else: ?>
        <div class="results-grid">
            <?php foreach ($rows as $row):
                $cid = (int)$row['class'];
                $rid = (int)$row['race'];
                $clr = $class_colors[$cid] ?? 'var(--accent)';
                $faction = faction_for_race($rid, $alliance_races, $horde_races);
            ?>
            <a href="/armory/<?= rawurlencode($row['name']) ?>" class="result-card" style="border-left-color:<?= $clr ?>">
                <div class="icons">
                    <img src="<?= '/' . get_race_icon_path($rid, (int)$row['gender']) ?>" alt="">
                    <img src="<?= '/' . get_class_icon_path($cid) ?>" alt="">
                </div>
                <div class="info">
                    <div class="name" style="color:<?= $clr ?>">
                        <?php if ((int)$row['online'] === 1): ?><span class="online-dot"></span><?php endif; ?>
                        <?= htmlspecialchars($row['name']) ?>
                    </div>
                    <div class="meta">
                        <?= htmlspecialchars(get_race_name($rid)) ?> &middot;
                        <?= htmlspecialchars(get_class_name($cid)) ?>
                        <?php if ($faction === 'alliance'): ?> &middot; <span style="color:#69ccf0"><?= htmlspecialchars($TEXT['armory_label_alliance'] ?? 'Alliance') ?></span>
                        <?php elseif ($faction === 'horde'): ?> &middot; <span style="color:#f87e8a"><?= htmlspecialchars($TEXT['armory_label_horde'] ?? 'Horde') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="extra">
                        <?php if (!empty($row['guild_name'])): ?>
                            <i class="bi bi-people-fill"></i> &lt;<?= htmlspecialchars($row['guild_name']) ?>&gt;
                            <?php /* No nested <a> — the entire result-card is already an <a href="/armory/...">.
                                     The /guild/<name> click-through is available on the character profile
                                     hero, the Leaderboards Guilds tab, and Who's Online. */ ?>
                        <?php elseif (!empty($row['zone'])): ?>
                            <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($row['zone']) ?>
                        <?php else: ?>
                            <i class="bi bi-clock-history"></i>
                            <?= ((int)$row['logout_time'] > 0) ? date('M d', (int)$row['logout_time']) : 'Never logged in' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="level-pill">Lv <?= (int)$row['level'] ?></div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="armory-pager">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(build_qs(['page' => 1])) ?>">«</a>
                <a href="<?= htmlspecialchars(build_qs(['page' => $page - 1])) ?>">‹</a>
            <?php else: ?>
                <span class="disabled">«</span>
                <span class="disabled">‹</span>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1) echo '<span>…</span>';
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(build_qs(['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor;
            if ($end < $total_pages) echo '<span>…</span>';
            ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?= htmlspecialchars(build_qs(['page' => $page + 1])) ?>">›</a>
                <a href="<?= htmlspecialchars(build_qs(['page' => $total_pages])) ?>">»</a>
            <?php else: ?>
                <span class="disabled">›</span>
                <span class="disabled">»</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
