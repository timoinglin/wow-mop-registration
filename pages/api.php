<?php
/**
 * /api  —  HTML reference for the public JSON endpoints.
 *
 * Same URL space that serves the JSON endpoints serves the docs when
 * the path has no endpoint segment. Public on purpose: community
 * Discord bot devs / streamer overlay builders shouldn't have to log
 * in to read the docs.
 */

require_once __DIR__ . '/../includes/lang.php';
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../templates/header.php';

$base = rtrim($config['site']['base_url'] ?? '', '/');
?>

<style>
.api-page { max-width: 920px; margin: 2rem auto; padding: 0 1rem; }
.api-h1 { color:var(--accent); font-weight:800; font-size:1.8rem; margin:0 0 .4rem; }
.api-sub { color:#8899aa; margin:0 0 1.6rem; font-size:1rem; line-height:1.6; }
.api-section { background: rgba(255,255,255,.02); border:1px solid rgba(var(--btn-bg-rgb),.25); border-radius:12px; padding:1.3rem 1.5rem; margin-bottom:1.2rem; }
.api-endpoint { display:flex; align-items:center; gap:.7rem; margin-bottom:.7rem; flex-wrap:wrap; }
.api-method { background: rgba(93,216,124,.18); color:#5dd87c; padding:.2rem .6rem; border-radius:999px; font-size:.7rem; letter-spacing:1.2px; font-weight:700; }
.api-url { font-family: ui-monospace, "SF Mono", Menlo, monospace; color: var(--accent); font-size:1rem; font-weight:700; word-break: break-all; }
.api-desc { color:#cfd5dc; font-size:.95rem; margin: .3rem 0 .8rem; line-height:1.55; }
.api-params { background: rgba(0,0,0,.25); border:1px solid rgba(var(--btn-bg-rgb),.2); border-radius:8px; padding:.7rem .9rem; font-size:.85rem; margin-bottom:.7rem; }
.api-params .lbl { color:#8899aa; font-size:.7rem; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:.35rem; }
.api-params code { color: var(--accent); }
.api-code { background:#0d1116; border:1px solid rgba(var(--accent-rgb),.2); border-radius:8px; padding:.8rem 1rem; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size:.82rem; color:#cfd5dc; overflow-x:auto; white-space:pre; line-height:1.5; }
.api-code .k { color:#7ec1ff; }   /* JSON key */
.api-code .s { color:#a4d99b; }   /* string */
.api-code .n { color:#ffd96a; }   /* number */
.api-code .c { color:#6c7a8c; font-style:italic; }
.api-toc { background: rgba(255,255,255,.025); border-left: 3px solid var(--accent); padding:.7rem 1rem; border-radius:6px; margin-bottom:1.5rem; }
.api-toc a { color:var(--accent); text-decoration:none; }
.api-toc a:hover { text-decoration:underline; }
.api-policy { color:#8899aa; font-size:.85rem; line-height:1.55; }
.api-policy strong { color:#dee2e6; }
.copy-btn { background: transparent; border:1px solid rgba(var(--accent-rgb),.35); color:var(--accent); font-size:.7rem; padding:.2rem .55rem; border-radius:5px; cursor:pointer; margin-left:auto; }
.copy-btn:hover { background: rgba(var(--accent-rgb),.12); }
</style>

<div class="api-page">
    <h1 class="api-h1"><i class="bi bi-braces-asterisk me-2"></i>Public API</h1>
    <p class="api-sub">JSON endpoints for the realm's public-facing data — current online roster, rated PvP leaderboards, character profiles. Open to any origin (CORS <code>*</code>), no auth, modestly cached. Build a Discord bot, a streamer overlay, an external tracker — all welcome.</p>

    <div class="api-toc">
        <strong style="color:#dee2e6">Endpoints:</strong>
        <a href="#online">/api/online</a> ·
        <a href="#leaderboards">/api/leaderboards/&lt;bracket&gt;</a> ·
        <a href="#character">/api/character/&lt;name&gt;</a> ·
        <a href="#search">/api/search/chars</a>
    </div>

    <!-- ───────────────────────── /api/online ───────────────────────── -->
    <div class="api-section" id="online">
        <div class="api-endpoint">
            <span class="api-method">GET</span>
            <span class="api-url"><?= htmlspecialchars($base) ?>/api/online</span>
        </div>
        <p class="api-desc">Snapshot of all characters currently online. The top-level <code>online_count</code> is the realm-wide total (not the page); <code>characters[]</code> is capped by <code>limit</code> (default 100, max 500), highest level first.</p>
        <div class="api-params">
            <div class="lbl">Query parameters</div>
            <div><code>limit</code> — integer, 1..500, default <code>100</code></div>
        </div>
        <div class="api-code"><span class="c">// example response</span>
{
  <span class="k">"online_count"</span>: <span class="n">42</span>,
  <span class="k">"alliance"</span>: <span class="n">25</span>,
  <span class="k">"horde"</span>: <span class="n">17</span>,
  <span class="k">"characters"</span>: [
    {
      <span class="k">"name"</span>: <span class="s">"Tester"</span>,
      <span class="k">"level"</span>: <span class="n">90</span>,
      <span class="k">"race"</span>: <span class="n">1</span>,
      <span class="k">"class"</span>: <span class="n">4</span>,
      <span class="k">"gender"</span>: <span class="n">0</span>,
      <span class="k">"guild"</span>: <span class="s">"Frost"</span>
    }
  ]
}</div>
    </div>

    <!-- ─────────────────────── /api/leaderboards ─────────────────────── -->
    <div class="api-section" id="leaderboards">
        <div class="api-endpoint">
            <span class="api-method">GET</span>
            <span class="api-url"><?= htmlspecialchars($base) ?>/api/leaderboards/&lt;bracket&gt;</span>
        </div>
        <p class="api-desc">Top-N rated PvP ladder for the bracket, current season. Bots excluded. Returns the current season number in the response so you can attach it to your output.</p>
        <div class="api-params">
            <div class="lbl">Path</div>
            <div><code>bracket</code> — <code>2v2</code> · <code>3v3</code> · <code>rbg</code></div>
            <div class="lbl" style="margin-top:.5rem">Query parameters</div>
            <div><code>limit</code> — integer, 1..100, default <code>20</code></div>
        </div>
        <div class="api-code"><span class="c">// GET /api/leaderboards/3v3?limit=5</span>
{
  <span class="k">"bracket"</span>: <span class="s">"3v3"</span>,
  <span class="k">"season"</span>: <span class="n">14</span>,
  <span class="k">"top"</span>: [
    {
      <span class="k">"rank"</span>: <span class="n">1</span>,
      <span class="k">"name"</span>: <span class="s">"Tester"</span>,
      <span class="k">"level"</span>: <span class="n">90</span>,
      <span class="k">"race"</span>: <span class="n">1</span>,
      <span class="k">"class"</span>: <span class="n">4</span>,
      <span class="k">"gender"</span>: <span class="n">0</span>,
      <span class="k">"rating"</span>: <span class="n">2400</span>,
      <span class="k">"wins"</span>: <span class="n">120</span>
    }
  ]
}</div>
    </div>

    <!-- ─────────────────────── /api/character ─────────────────────── -->
    <div class="api-section" id="character">
        <div class="api-endpoint">
            <span class="api-method">GET</span>
            <span class="api-url"><?= htmlspecialchars($base) ?>/api/character/&lt;name&gt;</span>
        </div>
        <p class="api-desc">Single character profile by name. Returns <code>404 { "error": "not_found" }</code> for unknown names.</p>
        <div class="api-params">
            <div class="lbl">Path</div>
            <div><code>name</code> — character name, 1..12 letters</div>
        </div>
        <div class="api-code"><span class="c">// GET /api/character/Tester</span>
{
  <span class="k">"name"</span>: <span class="s">"Tester"</span>,
  <span class="k">"level"</span>: <span class="n">90</span>,
  <span class="k">"race"</span>: <span class="n">1</span>,
  <span class="k">"class"</span>: <span class="n">4</span>,
  <span class="k">"gender"</span>: <span class="n">0</span>,
  <span class="k">"online"</span>: <span class="n">false</span>,
  <span class="k">"guild"</span>: <span class="s">"Frost"</span>,
  <span class="k">"zone"</span>: <span class="n">5841</span>,
  <span class="k">"honorable_kills"</span>: <span class="n">1234</span>,
  <span class="k">"total_playtime_seconds"</span>: <span class="n">98765</span>,
  <span class="k">"last_logout"</span>: <span class="n">1716000000</span>
}</div>
    </div>

    <!-- ─────────────────────── /api/search/chars ─────────────────────── -->
    <div class="api-section" id="search">
        <div class="api-endpoint">
            <span class="api-method">GET</span>
            <span class="api-url"><?= htmlspecialchars($base) ?>/api/search/chars</span>
        </div>
        <p class="api-desc">Prefix character-name autocomplete (powers the navbar search). Up to 8 matches, alphabetic-only queries, highest level first. Empty <code>[]</code> for empty or invalid queries — never 4xx in normal use.</p>
        <div class="api-params">
            <div class="lbl">Query parameters</div>
            <div><code>q</code> — string, 1..12 letters</div>
        </div>
        <div class="api-code"><span class="c">// GET /api/search/chars?q=Test</span>
[
  { <span class="k">"name"</span>: <span class="s">"Tester"</span>, <span class="k">"class"</span>: <span class="n">4</span>, <span class="k">"race"</span>: <span class="n">1</span>, <span class="k">"gender"</span>: <span class="n">0</span>, <span class="k">"level"</span>: <span class="n">90</span> }
]</div>
    </div>

    <!-- ─────────────────────── ID maps ─────────────────────── -->
    <div class="api-section">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.6rem">ID reference</h2>
        <p class="api-desc"><strong>class:</strong> 1 Warrior · 2 Paladin · 3 Hunter · 4 Rogue · 5 Priest · 6 DK · 7 Shaman · 8 Mage · 9 Warlock · 10 Monk · 11 Druid</p>
        <p class="api-desc"><strong>race:</strong> 1 Human · 2 Orc · 3 Dwarf · 4 Night Elf · 5 Undead · 6 Tauren · 7 Gnome · 8 Troll · 9 Goblin · 10 Blood Elf · 11 Draenei · 22 Worgen · 25 Pandaren (A) · 26 Pandaren (H)</p>
        <p class="api-desc"><strong>gender:</strong> 0 male · 1 female</p>
    </div>

    <!-- ─────────────────────── Policy ─────────────────────── -->
    <div class="api-section api-policy">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.6rem">Policy</h2>
        <p><strong>Read-only.</strong> No write endpoints. No game-economy mutations (AH, mail, gold) — that's a hard locked decision, never coming.</p>
        <p><strong>No auth, no rate limit, no API keys.</strong> Same data the public Armory / Leaderboards / Who's Online pages already render. CORS <code>*</code> so any origin can fetch.</p>
        <p><strong>Cached.</strong> Cache-Control max-age 20–60s on each endpoint. Don't pound it — your bot won't get faster data.</p>
        <p><strong>Best-effort, no SLA.</strong> Endpoints may return <code>503</code> when the characters DB is temporarily unavailable. Always check the HTTP status.</p>
    </div>

    <!-- ─────────────────────── Examples ─────────────────────── -->
    <div class="api-section">
        <h2 style="color:var(--accent);font-size:1.1rem;font-weight:800;margin-bottom:.6rem">Quick examples</h2>
        <p class="api-desc" style="margin-top:0">curl</p>
        <div class="api-code">curl -s <?= htmlspecialchars($base) ?>/api/online | jq <span class="s">'.online_count'</span></div>
        <p class="api-desc" style="margin-top:1rem">JavaScript (Discord.js / browser)</p>
        <div class="api-code">const res = await fetch(<span class="s">'<?= htmlspecialchars($base) ?>/api/leaderboards/3v3?limit=5'</span>);
const data = await res.json();
data.top.forEach(p =&gt; console.log(`#${p.rank} ${p.name} (${p.rating})`));</div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
