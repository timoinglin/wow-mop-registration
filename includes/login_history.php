<?php

/**
 * login_history.php
 * Tracks the last N logins per user in a flat JSON file.
 * No DB table required — stored in cache/login_history/{user_id}.json
 */

define('LOGIN_HISTORY_DIR', __DIR__ . '/../cache/login_history/');
define('LOGIN_HISTORY_MAX', 10); // keep last 10 entries

function _lh_init(): void
{
    if (!is_dir(LOGIN_HISTORY_DIR)) {
        mkdir(LOGIN_HISTORY_DIR, 0755, true);
        file_put_contents(LOGIN_HISTORY_DIR . '.htaccess', "Deny from all\n");
    }
}

function _lh_file(int $user_id): string
{
    return LOGIN_HISTORY_DIR . $user_id . '.json';
}

/**
 * Record a successful login (call from login.php after session is set).
 */
function record_login(int $user_id, string $ip): void
{
    _lh_init();
    $file    = _lh_file($user_id);
    $history = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];

    array_unshift($history, [
        'ip'   => $ip,
        'time' => time(),
    ]);

    // Trim to max
    $history = array_slice($history, 0, LOGIN_HISTORY_MAX);
    file_put_contents($file, json_encode($history), LOCK_EX);
}

/**
 * Get login history for a user. Returns array of ['ip'=>..., 'time'=>...].
 */
function get_login_history(int $user_id): array
{
    $file = _lh_file($user_id);
    if (!file_exists($file)) {
        return [];
    }
    return json_decode(file_get_contents($file), true) ?: [];
}
