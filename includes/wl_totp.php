<?php
/**
 * Tiny self-contained TOTP (RFC 6238) implementation.
 *
 * No Composer dependency — TOTP is just HMAC-SHA1 (built into PHP) plus a
 * 30-second time-counter and a base32-encoded shared secret. Less than
 * 80 LOC. Output is the standard 6-digit code compatible with Google
 * Authenticator / Authy / 1Password / Aegis / Bitwarden / etc.
 *
 *   wl_totp_generate_secret()           — fresh 16-char (80-bit) base32 secret
 *   wl_totp_uri($secret, $user, $issuer) — otpauth:// URI for QR codes
 *   wl_totp_current($secret)            — the 6-digit code right now
 *   wl_totp_verify($secret, $code, $window=1) — check user input, ±$window steps
 */

if (!function_exists('wl_totp_base32_encode')) {
    function wl_totp_base32_encode(string $bin): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $n = strlen($bin); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }
}

if (!function_exists('wl_totp_base32_decode')) {
    function wl_totp_base32_decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($b32 === '') return '';
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $idx = strpos($alphabet, $b32[$i]);
            if ($idx === false) continue;
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }
}

if (!function_exists('wl_totp_generate_secret')) {
    /** 16 base32 chars = 10 random bytes = 80 bits. RFC 4226 minimum is 128 bits but 80 is fine for TOTP. */
    function wl_totp_generate_secret(int $bytes = 10): string
    {
        return wl_totp_base32_encode(random_bytes($bytes));
    }
}

if (!function_exists('wl_totp_hotp')) {
    /** HOTP per RFC 4226. $counter is a 64-bit big-endian unsigned int. */
    function wl_totp_hotp(string $secret_b32, int $counter, int $digits = 6): string
    {
        $key = wl_totp_base32_decode($secret_b32);
        if ($key === '') return str_repeat('0', $digits);
        // 64-bit big-endian counter.
        $bin_counter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin_counter, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $code = ((ord($hash[$offset]) & 0x7f) << 24)
              | ((ord($hash[$offset + 1]) & 0xff) << 16)
              | ((ord($hash[$offset + 2]) & 0xff) << 8)
              |  (ord($hash[$offset + 3]) & 0xff);
        $mod = 10 ** $digits;
        return str_pad((string)($code % $mod), $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('wl_totp_current')) {
    function wl_totp_current(string $secret_b32, ?int $time = null, int $period = 30, int $digits = 6): string
    {
        $t = (int)floor(($time ?? time()) / $period);
        return wl_totp_hotp($secret_b32, $t, $digits);
    }
}

if (!function_exists('wl_totp_verify')) {
    /**
     * Returns true if $code matches the current TOTP, with a ±$window-step
     * tolerance (so a code that just expired or hasn't quite tipped over
     * still passes — accommodates clock drift between the user's phone and
     * the server). Uses a constant-time compare so attempt-timing leaks
     * nothing about the correct code.
     */
    function wl_totp_verify(string $secret_b32, string $code, int $window = 1, ?int $time = null, int $period = 30, int $digits = 6): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== $digits) return false;
        $base = (int)floor(($time ?? time()) / $period);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = wl_totp_hotp($secret_b32, $base + $i, $digits);
            if (hash_equals($candidate, $code)) return true;
        }
        return false;
    }
}

if (!function_exists('wl_totp_uri')) {
    /**
     * Builds the otpauth:// URI scanned by authenticator apps.
     *   otpauth://totp/Issuer:user@example?secret=ABCD&issuer=Issuer
     * RFC: https://github.com/google/google-authenticator/wiki/Key-Uri-Format
     */
    function wl_totp_uri(string $secret_b32, string $account_label, string $issuer): string
    {
        // Encode once: label is "Issuer:account" then URL-escaped; issuer query
        // arg is independently URL-escaped. Encoding the raw values straight
        // into one concat avoids the double-encoding pitfall.
        $label   = rawurlencode($issuer . ':' . $account_label);
        $enc_iss = rawurlencode($issuer);
        $secret  = rawurlencode($secret_b32);
        return "otpauth://totp/{$label}?secret={$secret}&issuer={$enc_iss}&algorithm=SHA1&digits=6&period=30";
    }
}
