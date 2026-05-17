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

// ─── Languages ──────────────────────────────────────────────────────────────

if (!function_exists('language_label')) {
    /**
     * Human label for a language code. Built-in map for common codes (no file
     * load — the switcher would otherwise have to require every lang array);
     * unknown codes fall back to the upper-cased code.
     */
    function language_label(string $code): string
    {
        static $names = [
            'en' => 'English',   'es' => 'Español',     'fr' => 'Français',
            'de' => 'Deutsch',   'pt' => 'Português',   'pt_br' => 'Português (BR)',
            'it' => 'Italiano',  'ru' => 'Русский',     'pl' => 'Polski',
            'tr' => 'Türkçe',    'nl' => 'Nederlands',  'sv' => 'Svenska',
            'cs' => 'Čeština',   'ro' => 'Română',      'hu' => 'Magyar',
            'el' => 'Ελληνικά',  'uk' => 'Українська',  'zh' => '中文',
            'ja' => '日本語',     'ko' => '한국어',       'ar' => 'العربية',
        ];
        return $names[strtolower($code)] ?? strtoupper($code);
    }
}

if (!function_exists('languages_available')) {
    /**
     * Every lang/<code>.php on disk → [code => label], EN first then sorted.
     * Filesystem-only (no DB) so it matches lang.php's discovery exactly.
     */
    function languages_available(): array
    {
        $codes = [];
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $f) {
            $c = strtolower(basename($f, '.php'));
            if (preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2,8})?$/', $c)) {
                $codes[$c] = true;
            }
        }
        $codes['en'] = true;               // EN always present (the fallback)
        $codes = array_keys($codes);
        sort($codes);
        $codes = array_values(array_diff($codes, ['en']));
        array_unshift($codes, 'en');       // EN first
        $out = [];
        foreach ($codes as $c) {
            $out[$c] = language_label($c);
        }
        return $out;
    }
}

if (!function_exists('languages_disabled')) {
    /**
     * Codes the admin has disabled (site_settings 'languages'). EN can never
     * be disabled. Safe/empty when no DB or unset.
     */
    function languages_disabled(?PDO $pdo): array
    {
        $cfg = site_setting($pdo, 'languages', null);
        $dis = (is_array($cfg) && isset($cfg['disabled']) && is_array($cfg['disabled']))
            ? array_map('strtolower', $cfg['disabled'])
            : [];
        return array_values(array_filter(array_unique($dis), fn($c) => $c !== 'en'));
    }
}

if (!function_exists('languages_enabled')) {
    /**
     * [code => label] of languages actually offered to visitors = available
     * minus disabled. EN always included and first.
     */
    function languages_enabled(?PDO $pdo): array
    {
        $disabled = languages_disabled($pdo);
        $out = [];
        foreach (languages_available() as $code => $label) {
            if ($code === 'en' || !in_array($code, $disabled, true)) {
                $out[$code] = $label;
            }
        }
        return $out;
    }
}

// ─── Theme & branding ───────────────────────────────────────────────────────
//
// The shipped look lives in assets/css/style.css :root (--accent etc.) and is
// the fallback. An admin override is stored in site_settings['theme'] and
// injected as a tiny <style> in the header — so config.php / the stylesheet
// stay the bootstrap and a customized accent/logo survives ZIP updates the
// same way avatars do. Uploaded branding files go under /uploads/branding/
// (preserved by the updater, never overwritten).

if (!defined('THEME_ACCENT_DEFAULT')) {
    define('THEME_ACCENT_DEFAULT', '#c89b3c'); // WoW gold (mirrors style.css)
}

if (!function_exists('theme_default')) {
    /** Full theme shape with shipped defaults (empty override = use stylesheet). */
    function theme_default(): array
    {
        return [
            'accent'         => THEME_ACCENT_DEFAULT,
            'bg_dark'        => '',   // '' = keep stylesheet value
            'bg_card'        => '',
            'text'           => '',
            'preset'         => '',   // last applied preset name (UI hint only)
            'custom_css'     => '',
            'custom_css_on'  => 0,
            'logo_main'      => '',   // hero/main logo  (web path under /uploads/branding/)
            'logo_top'       => '',   // navbar top-left logo
            'favicon'        => '',
            'header_bg'      => '',   // homepage hero background (image or video)
            'header_bg_kind' => '',   // 'image' | 'video'
        ];
    }
}

if (!function_exists('theme_hex_ok')) {
    /** True for a strict #rrggbb colour. */
    function theme_hex_ok(string $s): bool
    {
        return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $s);
    }
}

if (!function_exists('theme_darken')) {
    /**
     * Multiply each RGB channel by $f (<1 darkens). Used to derive
     * --accent-dim from the chosen accent so one input themes everything
     * (default factor ≈ the shipped #c89b3c → #8c6a23 ratio).
     */
    function theme_darken(string $hex, float $f = 0.66): string
    {
        if (!theme_hex_ok($hex)) return $hex;
        $r = (int)round(hexdec(substr($hex, 1, 2)) * $f);
        $g = (int)round(hexdec(substr($hex, 3, 2)) * $f);
        $b = (int)round(hexdec(substr($hex, 5, 2)) * $f);
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}

if (!function_exists('theme_rgba')) {
    /** "#rrggbb" + alpha → "rgba(r, g, b, a)" (for --accent-glow). */
    function theme_rgba(string $hex, float $a = 0.55): string
    {
        if (!theme_hex_ok($hex)) $hex = THEME_ACCENT_DEFAULT;
        return sprintf(
            'rgba(%d, %d, %d, %s)',
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
            rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.')
        );
    }
}

if (!function_exists('theme_sanitize_css')) {
    /**
     * Defang the advanced custom-CSS escape hatch. It's GM 9+ only and
     * rendered site-wide, so we still strip the breakout / script vectors:
     * any angle bracket (kills `</style><script>`), backslash (CSS unicode
     * escapes like `\3c`), and the `@import` / `expression()` / `javascript:`
     * payloads. Length-capped. url() is left intact (admin-controlled, can't
     * execute JS) so a bg image still works.
     */
    function theme_sanitize_css(string $css): string
    {
        $css = mb_substr($css, 0, 20000);
        $css = str_replace(['<', '>', '\\'], '', $css);
        $css = preg_replace('/@import\b/i', '', $css);
        $css = preg_replace('/expression\s*\(/i', 'blocked(', $css);
        $css = preg_replace('/javascript\s*:/i', 'blocked:', $css);
        return trim((string)$css);
    }
}

if (!function_exists('theme_brand_path_ok')) {
    /**
     * A stored branding path must be exactly one of our slot files under
     * /uploads/branding/ AND exist on disk (so a deleted file silently
     * falls back to the shipped default instead of 404-ing).
     */
    function theme_brand_path_ok(string $path): bool
    {
        if (!preg_match('#^/uploads/branding/[a-z0-9_]+\.[a-z0-9]+$#', $path)) {
            return false;
        }
        return is_file(__DIR__ . '/..' . $path);
    }
}

if (!function_exists('theme_get')) {
    /**
     * Normalised theme (always the full shape). Invalid/empty fields fall
     * back to the shipped default, so a bad stored value can never break the
     * site — it just reverts that one knob.
     */
    function theme_get(?PDO $pdo): array
    {
        $def    = theme_default();
        $stored = site_setting($pdo, 'theme', null);
        if (!is_array($stored)) {
            return $def;
        }
        $t = $def;

        $acc = strtolower(trim((string)($stored['accent'] ?? '')));
        if (theme_hex_ok($acc)) $t['accent'] = $acc;

        foreach (['bg_dark', 'bg_card', 'text'] as $k) {
            $v = strtolower(trim((string)($stored[$k] ?? '')));
            if (theme_hex_ok($v)) $t[$k] = $v;
        }

        $t['preset']        = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($stored['preset'] ?? '')));
        $t['custom_css_on'] = !empty($stored['custom_css_on']) ? 1 : 0;
        $t['custom_css']    = theme_sanitize_css((string)($stored['custom_css'] ?? ''));

        foreach (['logo_main', 'logo_top', 'favicon', 'header_bg'] as $k) {
            $p = (string)($stored[$k] ?? '');
            if ($p !== '' && theme_brand_path_ok($p)) $t[$k] = $p;
        }
        $hk = (string)($stored['header_bg_kind'] ?? '');
        $t['header_bg_kind'] = in_array($hk, ['image', 'video'], true) ? $hk : '';
        if ($t['header_bg'] === '') $t['header_bg_kind'] = '';

        return $t;
    }
}

if (!function_exists('theme_is_default')) {
    /** True when nothing visual is overridden — lets callers skip all output. */
    function theme_is_default(array $t): bool
    {
        return strtolower($t['accent']) === THEME_ACCENT_DEFAULT
            && $t['bg_dark'] === '' && $t['bg_card'] === '' && $t['text'] === ''
            && !($t['custom_css_on'] && $t['custom_css'] !== '');
    }
}

if (!function_exists('theme_css')) {
    /**
     * The CSS injected into the <head> from header.php. Returns '' on a
     * stock theme so a non-customized install ships byte-identical markup
     * (zero overhead, zero risk). $t = a theme_get() array.
     */
    function theme_css(array $t): string
    {
        if (theme_is_default($t)) return '';
        $acc  = theme_hex_ok($t['accent']) ? $t['accent'] : THEME_ACCENT_DEFAULT;
        $vars = [
            '--accent: ' . $acc,
            '--accent-dim: ' . theme_darken($acc),
            '--accent-glow: ' . theme_rgba($acc, 0.55),
        ];
        if (theme_hex_ok($t['bg_dark'])) $vars[] = '--bg-dark: ' . $t['bg_dark'];
        if (theme_hex_ok($t['bg_card'])) {
            $vars[] = '--bg-card: ' . $t['bg_card'];
            $vars[] = '--bg-card2: ' . theme_darken($t['bg_card'], 1.25); // slightly lighter sibling
        }
        if (theme_hex_ok($t['text']))    $vars[] = '--text: ' . $t['text'];

        $out = ':root{' . implode(';', $vars) . '}';
        if ($t['custom_css_on'] && $t['custom_css'] !== '') {
            $out .= "\n" . $t['custom_css'];
        }
        return $out;
    }
}

if (!function_exists('theme_asset_url')) {
    /**
     * Resolve a branding slot to a URL: the admin override (cache-busted by
     * mtime) when present & on disk, else the shipped $default.
     */
    function theme_asset_url(array $t, string $key, string $default): string
    {
        $p = $t[$key] ?? '';
        if ($p !== '' && theme_brand_path_ok($p)) {
            $m = @filemtime(__DIR__ . '/..' . $p) ?: time();
            return $p . '?v=' . $m;
        }
        return $default;
    }
}
