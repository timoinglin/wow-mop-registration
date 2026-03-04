<?php

/**
 * csrf.php
 * CSRF token generation and validation.
 */

/**
 * Generates a CSRF token and stores it in the session.
 *
 * @return string The generated token.
 */
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a given CSRF token against the one stored in the session.
 *
 * @param string|null $token The token to validate.
 * @return bool True if valid, false otherwise.
 */
function validate_csrf_token(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
