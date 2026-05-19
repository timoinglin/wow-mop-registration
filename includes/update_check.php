<?php
/**
 * Admin "update available" check — ADVISORY ONLY.
 *
 * Locked decision: the web tier never updates itself. This module only
 * *tells* the admin a newer GitHub release exists and prints the
 * one-paste update.ps1 command — it never downloads, overwrites or runs
 * anything.
 *
 * Defensive by design: result is cached in site_settings ('update_check',
 * ≥6h) so GitHub isn't hit per page-load, and ANY failure (offline, API
 * limit, DNS, no PDO) yields behind=false → the admin never breaks over
 * a version check.
 */

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/site_settings.php'; // site_setting(), site_setting_set()

if (!defined('WL_UPDATE_CACHE_TTL')) define('WL_UPDATE_CACHE_TTL', 6 * 3600);
if (!defined('WL_GH_REPO'))          define('WL_GH_REPO', 'timoinglin/wow-mop-registration');

if (!function_exists('wl_installed_version')) {
    /** Tracked WL_VERSION, overridden by an updater-written /VERSION when higher. */
    function wl_installed_version(): string
    {
        $v = defined('WL_VERSION') ? (string)WL_VERSION : '';
        $f = @file_get_contents(__DIR__ . '/../VERSION');
        if ($f !== false) {
            $f = trim($f);
            if ($f !== '' && preg_match('/^v?\d/', $f)) {
                if ($v === '' || version_compare(ltrim($f, 'v'), ltrim($v, 'v'), '>')) {
                    $v = $f;
                }
            }
        }
        return $v;
    }
}

if (!function_exists('wl_http_get_json')) {
    /** Minimal hardened GET → decoded JSON, or null. cURL then stream fallback. */
    function wl_http_get_json(string $url)
    {
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'wow-legends-portal',
                CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
        }
        if (($body === false || $body === '') && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 4, 'header' => "User-Agent: wow-legends-portal\r\nAccept: application/vnd.github+json\r\n"],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($body) || $body === '') return null;
        $j = json_decode($body, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $j : null;
    }
}

if (!function_exists('update_check_get')) {
    /**
     * @return array{installed:string,latest:string,url:string,behind:bool}
     *         behind=true only when both versions parse and installed < latest.
     */
    function update_check_get(?PDO $pdo, array $config): array
    {
        $out = [
            'installed' => wl_installed_version(),
            'latest'    => '',
            'url'        => 'https://github.com/' . WL_GH_REPO . '/releases',
            'behind'     => false,
        ];

        $cache = function_exists('site_setting') ? site_setting($pdo, 'update_check', null) : null;
        $now   = time();
        $fresh = is_array($cache) && isset($cache['at'], $cache['latest'])
                 && ($now - (int)$cache['at']) < WL_UPDATE_CACHE_TTL;

        if ($fresh) {
            $out['latest'] = (string)$cache['latest'];
            if (!empty($cache['url'])) $out['url'] = (string)$cache['url'];
        } else {
            $json = wl_http_get_json('https://api.github.com/repos/' . WL_GH_REPO . '/releases/latest');
            if (is_array($json) && !empty($json['tag_name'])) {
                $out['latest'] = (string)$json['tag_name'];
                if (!empty($json['html_url'])) $out['url'] = (string)$json['html_url'];
                if ($pdo && function_exists('site_setting_set')) {
                    @site_setting_set($pdo, 'update_check',
                        ['at' => $now, 'latest' => $out['latest'], 'url' => $out['url']]);
                }
            } elseif (is_array($cache) && isset($cache['latest'])) {
                // transient failure — a stale answer beats no answer
                $out['latest'] = (string)$cache['latest'];
                if (!empty($cache['url'])) $out['url'] = (string)$cache['url'];
            }
        }

        if ($out['installed'] !== '' && $out['latest'] !== ''
            && preg_match('/^v?\d/', $out['installed']) && preg_match('/^v?\d/', $out['latest'])) {
            $out['behind'] = version_compare(
                ltrim($out['installed'], 'v'),
                ltrim($out['latest'], 'v'),
                '<'
            );
        }
        return $out;
    }
}
