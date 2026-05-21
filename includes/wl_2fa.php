<?php
/**
 * 2FA persistence layer — wraps web_account_2fa.
 *
 * Public surface:
 *   wl_2fa_get(pdo, account_id)        → ['secret','enabled','backup_codes','enabled_at'] | null
 *   wl_2fa_is_enabled(pdo, account_id) → bool
 *   wl_2fa_setup_begin(pdo, account_id, secret)  — insert/replace row, enabled=0
 *   wl_2fa_setup_finalize(pdo, account_id, backup_codes_plain) — flip enabled=1, store backup hashes
 *   wl_2fa_disable(pdo, account_id)    — delete row outright
 *   wl_2fa_verify(pdo, account_id, code) → bool   (TOTP or one-shot backup; consumes backup)
 *
 *   wl_2fa_generate_backup_codes(count=8) → array of XXXX-XXXX plain codes (return to user once)
 *
 * Backup codes are stored as sha256 hashes (JSON array of hex strings).
 * Consuming a code removes that hash from the array — single-use.
 */

require_once __DIR__ . '/wl_totp.php';

if (!function_exists('wl_2fa_get')) {
    function wl_2fa_get(PDO $pdo, int $account_id): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT secret, enabled, backup_codes, enabled_at
                 FROM web_account_2fa WHERE account_id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $account_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('wl_2fa_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wl_2fa_is_enabled')) {
    function wl_2fa_is_enabled(PDO $pdo, int $account_id): bool
    {
        $row = wl_2fa_get($pdo, $account_id);
        return $row !== null && (int)$row['enabled'] === 1;
    }
}

if (!function_exists('wl_2fa_setup_begin')) {
    /** Insert (or replace) the row in pending state. New secret. enabled=0. */
    function wl_2fa_setup_begin(PDO $pdo, int $account_id, string $secret_b32): bool
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO web_account_2fa (account_id, secret, enabled, backup_codes, enabled_at)
                 VALUES (:id, :secret, 0, NULL, NULL)
                 ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0, backup_codes = NULL, enabled_at = NULL"
            );
            return $stmt->execute(['id' => $account_id, 'secret' => $secret_b32]);
        } catch (PDOException $e) {
            error_log('wl_2fa_setup_begin: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wl_2fa_setup_finalize')) {
    /** Flips enabled=1, stores backup codes as sha256 hashes. */
    function wl_2fa_setup_finalize(PDO $pdo, int $account_id, array $backup_codes_plain): bool
    {
        $hashes = [];
        foreach ($backup_codes_plain as $c) {
            $hashes[] = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)));
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE web_account_2fa
                 SET enabled = 1, backup_codes = :codes, enabled_at = NOW()
                 WHERE account_id = :id"
            );
            return $stmt->execute(['id' => $account_id, 'codes' => json_encode($hashes)]);
        } catch (PDOException $e) {
            error_log('wl_2fa_setup_finalize: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wl_2fa_disable')) {
    function wl_2fa_disable(PDO $pdo, int $account_id): bool
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM web_account_2fa WHERE account_id = :id");
            return $stmt->execute(['id' => $account_id]);
        } catch (PDOException $e) {
            error_log('wl_2fa_disable: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wl_2fa_regenerate_backup_codes')) {
    /** Issues a fresh set, replacing the existing ones. Returns plain codes for one-time display. */
    function wl_2fa_regenerate_backup_codes(PDO $pdo, int $account_id, int $count = 8): array
    {
        $plain = wl_2fa_generate_backup_codes($count);
        $hashes = array_map(function ($c) {
            return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)));
        }, $plain);
        try {
            $stmt = $pdo->prepare(
                "UPDATE web_account_2fa SET backup_codes = :codes WHERE account_id = :id"
            );
            $stmt->execute(['id' => $account_id, 'codes' => json_encode($hashes)]);
            return $plain;
        } catch (PDOException $e) {
            error_log('wl_2fa_regenerate_backup_codes: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('wl_2fa_generate_backup_codes')) {
    /** XXXX-XXXX uppercase alphanumeric, 8 codes by default. Returned plain for one-time display. */
    function wl_2fa_generate_backup_codes(int $count = 8): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/I/1 ambiguity.
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $a = ''; $b = '';
            for ($j = 0; $j < 4; $j++) {
                $a .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                $b .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = $a . '-' . $b;
        }
        return $codes;
    }
}

if (!function_exists('wl_2fa_verify')) {
    /**
     * Accepts either:
     *   - 6-digit TOTP code (current ± 1 step / 30s tolerance)
     *   - 8-char (XXXX-XXXX) backup code (consumed on success)
     * Returns false on no row / disabled / bad code.
     */
    function wl_2fa_verify(PDO $pdo, int $account_id, string $code): bool
    {
        $row = wl_2fa_get($pdo, $account_id);
        if ($row === null || (int)$row['enabled'] !== 1) return false;

        $clean = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
        // 6 digits → TOTP attempt.
        if (preg_match('/^\d{6}$/', $clean)) {
            return wl_totp_verify($row['secret'], $clean);
        }
        // 8 alphanumeric → backup code attempt.
        if (preg_match('/^[A-Z0-9]{8}$/', $clean)) {
            $h = hash('sha256', $clean);
            $stored = json_decode($row['backup_codes'] ?? '[]', true) ?: [];
            $idx = array_search($h, $stored, true);
            if ($idx === false) return false;
            // Consume — remove this hash from the array.
            array_splice($stored, $idx, 1);
            try {
                $stmt = $pdo->prepare("UPDATE web_account_2fa SET backup_codes = :codes WHERE account_id = :id");
                $stmt->execute(['id' => $account_id, 'codes' => json_encode($stored)]);
            } catch (PDOException $e) {
                error_log('wl_2fa_verify consume backup: ' . $e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }
}

if (!function_exists('wl_2fa_remaining_backup_codes')) {
    function wl_2fa_remaining_backup_codes(PDO $pdo, int $account_id): int
    {
        $row = wl_2fa_get($pdo, $account_id);
        if ($row === null) return 0;
        $stored = json_decode($row['backup_codes'] ?? '[]', true) ?: [];
        return count($stored);
    }
}
