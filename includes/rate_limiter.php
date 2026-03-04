<?php

/**
 * rate_limiter.php
 * File-based brute-force protection for login. No DB table required.
 * Stores per-IP attempt data in cache/rate_limit/ as JSON files.
 */

define('RATE_LIMIT_DIR', __DIR__ . '/../cache/rate_limit/');

function _rl_init(): void
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        mkdir(RATE_LIMIT_DIR, 0755, true);
        // Protect the cache directory from web access
        file_put_contents(RATE_LIMIT_DIR . '.htaccess', "Deny from all\n");
    }
}

function _rl_file(string $ip): string
{
    return RATE_LIMIT_DIR . md5($ip) . '.json';
}

function _rl_read(string $ip): array
{
    $file = _rl_file($ip);
    if (!file_exists($file)) {
        return ['attempts' => 0, 'last_attempt' => 0];
    }
    return json_decode(file_get_contents($file), true) ?: ['attempts' => 0, 'last_attempt' => 0];
}

function _rl_write(string $ip, array $data): void
{
    _rl_init();
    file_put_contents(_rl_file($ip), json_encode($data), LOCK_EX);
}

/**
 * Records a failed login attempt for the given IP.
 */
function record_failed_attempt(PDO $pdo, string $ip): void
{
    $lockout = get_lockout_minutes() * 60;
    $data    = _rl_read($ip);

    // Reset counter if last attempt was outside the lockout window
    if (time() - $data['last_attempt'] > $lockout) {
        $data['attempts'] = 0;
    }

    $data['attempts']++;
    $data['last_attempt'] = time();
    _rl_write($ip, $data);
}

/**
 * Clears all failed attempts for the given IP (on successful login).
 */
function clear_attempts(PDO $pdo, string $ip): void
{
    $file = _rl_file($ip);
    if (file_exists($file)) {
        unlink($file);
    }
}

/**
 * Returns true if the IP is currently locked out.
 */
function is_locked_out(PDO $pdo, string $ip): bool
{
    global $config;
    $max     = (int)($config['security']['max_login_attempts'] ?? 5);
    $lockout = get_lockout_minutes() * 60;
    $data    = _rl_read($ip);

    if ($data['attempts'] < $max) {
        return false;
    }

    // Still within lockout window?
    return (time() - $data['last_attempt']) < $lockout;
}

/**
 * Returns seconds remaining in the lockout, or 0 if not locked.
 */
function lockout_seconds_remaining(PDO $pdo, string $ip): int
{
    $lockout  = get_lockout_minutes() * 60;
    $data     = _rl_read($ip);
    $elapsed  = time() - $data['last_attempt'];
    $remaining = $lockout - $elapsed;
    return max(0, (int)$remaining);
}

function get_lockout_minutes(): int
{
    global $config;
    return (int)($config['security']['lockout_minutes'] ?? 15);
}
