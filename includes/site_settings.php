<?php
/**
 * Site customization settings — generic key → JSON store (auth DB,
 * `site_settings` table).
 *
 * Model (same as the donation-rate override): the DB row, when present, is
 * the admin's override; otherwise the caller's $default (typically derived
 * from config.php / a built-in default) applies. So config.php stays the
 * bootstrap/fallback and is never rewritten, and an admin's customization
 * lives in the DB — surviving updates like avatars/news/forum already do.
 *
 * Secrets + bootstrap (db.*, smtp.*, recaptcha.*, site.base_url, features.*)
 * are deliberately NEVER stored here — file-only in config.php.
 *
 * Graceful by design: a missing table / DB hiccup / null PDO returns the
 * default, so the public site never breaks over a customization read.
 */

if (!function_exists('_site_setting_cache')) {
    /** Shared request cache (by reference) so set() can update what get() sees. */
    function &_site_setting_cache(): array
    {
        static $c = [];
        return $c;
    }
}

if (!function_exists('site_setting')) {
    /**
     * Read an admin-set value. Returns the decoded JSON value, or $default
     * when the key is unset / unreadable. Only HITS are cached — a miss is
     * never cached, so a set() later in the same request is still seen.
     */
    function site_setting(?PDO $pdo, string $key, $default = null)
    {
        $cache = &_site_setting_cache();
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!$pdo) {
            return $default;
        }
        try {
            $stmt = $pdo->prepare("SELECT v FROM site_settings WHERE k = :k LIMIT 1");
            $stmt->execute(['k' => $key]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                return $default; // no row — do NOT cache (set() may follow)
            }
            $decoded = json_decode((string)$raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $default;
            }
            $cache[$key] = $decoded;
            return $decoded;
        } catch (PDOException $e) {
            error_log('site_setting(' . $key . '): ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('site_setting_set')) {
    /**
     * Upsert a key. $value is JSON-encoded. Returns true on success.
     */
    function site_setting_set(PDO $pdo, string $key, $value): bool
    {
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }
            $stmt = $pdo->prepare(
                "INSERT INTO site_settings (k, v) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE v = VALUES(v)"
            );
            $ok = $stmt->execute(['k' => $key, 'v' => $json]);
            if ($ok) {
                $cache = &_site_setting_cache();
                $cache[$key] = $value; // keep same-request reads consistent
            }
            return $ok;
        } catch (PDOException $e) {
            error_log('site_setting_set(' . $key . '): ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('footer_links_default')) {
    /**
     * Built-in footer config used when the admin hasn't customized it.
     * `builtin` = which shipped quick-links show; `custom` = extra
     * admin-added [label,url] rows. Kept here so the resolver, the public
     * footer, and the admin page share one source of truth.
     */
    function footer_links_default(): array
    {
        return [
            'builtin' => ['home' => 1, 'register' => 1, 'login' => 1, 'support' => 1],
            'custom'  => [],
        ];
    }
}

if (!function_exists('footer_links_get')) {
    /**
     * Normalised footer config (always the full shape, even on partial /
     * legacy stored data). Custom rows are sanitised: label trimmed/capped,
     * URL must be http(s) or a site-relative path.
     */
    function footer_links_get(?PDO $pdo): array
    {
        $def    = footer_links_default();
        $stored = site_setting($pdo, 'footer', null);
        if (!is_array($stored)) {
            return $def;
        }

        $builtin = $def['builtin'];
        if (isset($stored['builtin']) && is_array($stored['builtin'])) {
            foreach ($builtin as $k => $_) {
                $builtin[$k] = !empty($stored['builtin'][$k]) ? 1 : 0;
            }
        }

        $custom = [];
        if (isset($stored['custom']) && is_array($stored['custom'])) {
            foreach ($stored['custom'] as $row) {
                if (!is_array($row)) continue;
                $label = trim((string)($row['label'] ?? ''));
                $url   = trim((string)($row['url'] ?? ''));
                if ($label === '' || $url === '') continue;
                if (!footer_link_url_ok($url)) continue;
                $custom[] = ['label' => mb_substr($label, 0, 40), 'url' => $url];
                if (count($custom) >= 12) break;
            }
        }

        return ['builtin' => $builtin, 'custom' => $custom];
    }
}

if (!function_exists('footer_link_url_ok')) {
    /**
     * Allow only absolute http(s) URLs or site-relative paths ("/foo").
     * Blocks javascript:, data:, etc. — an admin-editable footer is an
     * injection surface.
     */
    function footer_link_url_ok(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 300) return false;
        if ($url[0] === '/' && (isset($url[1]) ? $url[1] !== '/' : true)) return true; // "/path", not "//host"
        return (bool)preg_match('#^https?://[^\s]+$#i', $url);
    }
}
