<?php

/**
 * auth.php
 * Authentication helpers: password hashing and IP detection.
 */

/**
 * Hashes the password according to the required WoW MoP format.
 *
 * @param string $username The username (will be uppercased).
 * @param string $password The password (will be uppercased).
 * @return string The SHA1 hash in uppercase.
 */
function sha_password(string $username, string $password): string
{
    return strtoupper(sha1(strtoupper($username) . ':' . strtoupper($password)));
}

/**
 * Get the user's real IP address, considering proxies.
 *
 * @return string|false The IP address or false on failure.
 */
function get_user_ip()
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    // Fallback to REMOTE_ADDR if no public IP found (e.g., local environment)
    return $_SERVER['REMOTE_ADDR'] ?? false;
}
