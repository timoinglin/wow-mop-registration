<?php
/**
 * Discord widget — server-side fetch of Discord's public widget.json.
 *
 *   wl_discord_widget_data(string $server_id): ?array
 *     → ['name'=>..., 'instant_invite'=>..., 'presence_count'=>int]
 *     null on any failure (network, 404, widget disabled, malformed).
 *
 *   wl_discord_widget_render(array $config): string
 *     → HTML for the homepage block; empty string when server_id is unset,
 *       the lookup fails, or the widget is disabled on the Discord side.
 *
 * Cached for ~60s in web_site_settings so a homepage hit doesn't hammer
 * Discord. Fail-silent on every error — the widget simply doesn't render.
 */

if (!function_exists('wl_discord_widget_data')) {
    function wl_discord_widget_data(string $server_id): ?array
    {
        if (!preg_match('/^\d{17,20}$/', $server_id)) return null;

        // Cache via web_site_settings (same place the update-available
        // check stashes its 6-hour cache). Key includes the server_id so
        // a change in config invalidates the cached payload.
        $cache_key = "discord_widget_{$server_id}";
        $cached = null;
        try {
            if (isset($GLOBALS['pdo_auth'])) {
                $stmt = $GLOBALS['pdo_auth']->prepare("SELECT v, updated_at FROM web_site_settings WHERE k = :k");
                $stmt->execute(['k' => $cache_key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && (time() - strtotime($row['updated_at'])) < 60) {
                    $cached = json_decode($row['v'], true);
                    if (is_array($cached)) return $cached;
                }
            }
        } catch (PDOException $e) {
            error_log('discord_widget cache read: ' . $e->getMessage());
        }

        $ch = curl_init("https://discord.com/api/guilds/{$server_id}/widget.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'wow-legends-portal',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) return null;

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['name'])) return null;

        $payload = [
            'name'           => (string)$json['name'],
            'instant_invite' => (string)($json['instant_invite'] ?? ''),
            'presence_count' => (int)($json['presence_count'] ?? 0),
        ];

        // Persist to cache (best-effort).
        try {
            if (isset($GLOBALS['pdo_auth'])) {
                $GLOBALS['pdo_auth']->prepare(
                    "REPLACE INTO web_site_settings (k, v) VALUES (:k, :v)"
                )->execute(['k' => $cache_key, 'v' => json_encode($payload)]);
            }
        } catch (PDOException $e) {
            error_log('discord_widget cache write: ' . $e->getMessage());
        }

        return $payload;
    }
}

if (!function_exists('wl_discord_widget_render')) {
    function wl_discord_widget_render(array $config): string
    {
        $server_id = (string)($config['discord']['server_id'] ?? '');
        if ($server_id === '') return '';
        $data = wl_discord_widget_data($server_id);
        if ($data === null) return '';

        global $TEXT;
        $invite = $data['instant_invite'] ?: ($config['social']['discord'] ?? '');
        $name   = htmlspecialchars($data['name']);
        $count  = number_format($data['presence_count']);

        $tpl_online = $TEXT['discord_widget_online'] ?? '%s members online right now';
        $btn_join   = $TEXT['discord_widget_join']   ?? 'Join the Discord';
        $hd_title   = $TEXT['discord_widget_title']  ?? 'Community on Discord';

        ob_start(); ?>
        <section class="discord-widget" aria-label="Discord community">
            <div class="discord-widget-inner">
                <div class="discord-widget-icon">
                    <i class="bi bi-discord"></i>
                </div>
                <div class="discord-widget-meta">
                    <div class="discord-widget-title"><?= $hd_title ?></div>
                    <div class="discord-widget-name"><?= $name ?></div>
                    <div class="discord-widget-count"><?= sprintf(htmlspecialchars($tpl_online), '<span>' . $count . '</span>') ?></div>
                </div>
                <?php if ($invite): ?>
                <a href="<?= htmlspecialchars($invite) ?>" target="_blank" rel="noopener" class="discord-widget-btn">
                    <i class="bi bi-box-arrow-up-right me-2"></i><?= htmlspecialchars($btn_join) ?>
                </a>
                <?php endif; ?>
            </div>
        </section>
        <style>
        .discord-widget { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .discord-widget-inner { display:flex; align-items:center; gap:1.2rem; padding: 1.2rem 1.4rem; background: linear-gradient(135deg, rgba(88,101,242,.15), rgba(88,101,242,.05)); border:1px solid rgba(88,101,242,.4); border-radius:14px; flex-wrap:wrap; }
        .discord-widget-icon { font-size: 2.4rem; color: #5865F2; flex-shrink:0; }
        .discord-widget-meta { flex:1; min-width: 200px; }
        .discord-widget-title { color:#8899aa; font-size:.72rem; text-transform:uppercase; letter-spacing:1.5px; font-weight:600; margin-bottom:.2rem; }
        .discord-widget-name { color:#dee2e6; font-weight:700; font-size:1.1rem; line-height:1.2; margin-bottom:.15rem; }
        .discord-widget-count { color:#5dd87c; font-size:.9rem; font-weight:600; }
        .discord-widget-count span { color:#5dd87c; font-variant-numeric: tabular-nums; }
        .discord-widget-btn { display:inline-flex; align-items:center; padding:.65rem 1.2rem; border-radius:10px; font-weight:700; font-size:.9rem; text-decoration:none; background:#5865F2; color:#fff; transition: background .2s, transform .15s; flex-shrink:0; }
        .discord-widget-btn:hover { background:#4752c4; color:#fff; transform: translateY(-1px); }
        @media (max-width: 560px) {
            .discord-widget-inner { flex-direction: column; text-align: center; }
            .discord-widget-meta { min-width: 0; }
            .discord-widget-btn { width: 100%; justify-content: center; }
        }
        </style>
        <?php
        return ob_get_clean();
    }
}
