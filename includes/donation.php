<?php
/**
 * Ko-fi Donation Helper
 *
 * Automatic Battle Pay (DP) crediting from Ko-fi donations. Ko-fi is the only
 * supported processor by design (free, webhook on the free tier, no merchant
 * KYC). The webhook delivers the real paid amount, so crediting is fully
 * dynamic: DP = floor(amount * eur_to_dp_rate).
 *
 * Attribution: each account gets one reusable code (WL-XXXXXXXX) shown on the
 * /shop page. The donor pastes it into the Ko-fi message; the webhook extracts
 * it to know which account.dp to credit.
 *
 * Idempotency: every processed delivery is recorded in donation_log keyed by a
 * UNIQUE kofi_transaction_id. Ko-fi may re-deliver the same webhook — the
 * duplicate INSERT fails and the credit is skipped (replay protection).
 *
 * All DB objects live in the `auth` database (alongside `account`).
 */

require_once __DIR__ . '/audit.php';

// Unambiguous alphabet for minted codes — no 0/O/1/I/L to avoid donor typos.
const DONATION_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const DONATION_CODE_PREFIX   = 'WL-';
const DONATION_CODE_LEN      = 8;

/**
 * Normalised donation config with safe defaults. Always returns every key.
 */
function donation_config(array $config): array
{
    $d = $config['donation'] ?? [];
    return [
        'token'      => (string)($d['kofi_verification_token'] ?? ''),
        'rate'       => (int)($d['eur_to_dp_rate'] ?? 100),
        'currency'   => (string)($d['currency'] ?? 'EUR'),
        'min_amount' => (float)($d['min_amount'] ?? 0),
        'kofi_url'   => (string)($d['kofi_url'] ?? ''),
    ];
}

/**
 * Donations usable? Feature flag on AND a verification token configured.
 * (A token left at the sample placeholder counts as not configured.)
 */
function donation_enabled(array $config): bool
{
    if (empty($config['features']['donations'])) {
        return false;
    }
    $token = donation_config($config)['token'];
    return $token !== '' && $token !== 'YOUR_KOFI_VERIFICATION_TOKEN_HERE';
}

/**
 * Generate one random attribution code, e.g. "WL-7K9QF2MX".
 */
function donation_make_code(): string
{
    $alpha = DONATION_CODE_ALPHABET;
    $max   = strlen($alpha) - 1;
    $code  = DONATION_CODE_PREFIX;
    for ($i = 0; $i < DONATION_CODE_LEN; $i++) {
        $code .= $alpha[random_int(0, $max)];
    }
    return $code;
}

/**
 * Get (minting once on first call) the reusable code for an account.
 * Returns null only on a hard DB failure.
 */
function donation_get_code(PDO $pdo_auth, int $account_id): ?string
{
    try {
        $sel = $pdo_auth->prepare("SELECT code FROM donation_codes WHERE account_id = :id LIMIT 1");
        $sel->execute(['id' => $account_id]);
        $existing = $sel->fetchColumn();
        if ($existing !== false) {
            return (string)$existing;
        }

        // Mint. Retry on the (rare) unique-code collision; re-check by
        // account_id each loop so a concurrent insert is honoured.
        $ins = $pdo_auth->prepare(
            "INSERT INTO donation_codes (account_id, code) VALUES (:id, :code)"
        );
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $code = donation_make_code();
            try {
                $ins->execute(['id' => $account_id, 'code' => $code]);
                return $code;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Duplicate: either this account already got one (race) or the
                // code collided. Re-read by account_id; return if now present.
                $sel->execute(['id' => $account_id]);
                $now = $sel->fetchColumn();
                if ($now !== false) {
                    return (string)$now;
                }
                // else: code collision — loop and try a fresh code.
            }
        }
        error_log("donation_get_code: exhausted code-mint attempts for account $account_id");
        return null;
    } catch (PDOException $e) {
        error_log("donation_get_code error: " . $e->getMessage());
        return null;
    }
}

/**
 * Extract the first WL-XXXXXXXX code from arbitrary donor text.
 * Accepts any 8 alphanumerics after the prefix (the DB lookup is the real
 * validator) and returns the canonical uppercase form, or null.
 */
function donation_extract_code(?string $message): ?string
{
    if ($message === null || $message === '') {
        return null;
    }
    if (preg_match('/WL-([A-Z0-9]{8})/i', $message, $m)) {
        return strtoupper('WL-' . $m[1]);
    }
    return null;
}

/**
 * Resolve a code to its account. Returns ['id'=>int,'username'=>string] or null.
 */
function donation_account_for_code(PDO $pdo_auth, string $code): ?array
{
    try {
        $stmt = $pdo_auth->prepare(
            "SELECT a.id, a.username
               FROM donation_codes dc
               JOIN account a ON a.id = dc.account_id
              WHERE dc.code = :c
              LIMIT 1"
        );
        $stmt->execute(['c' => strtoupper($code)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return ['id' => (int)$row['id'], 'username' => (string)$row['username']];
    } catch (PDOException $e) {
        error_log("donation_account_for_code error: " . $e->getMessage());
        return null;
    }
}

/**
 * Process one Ko-fi webhook payload (the already-json_decoded `data` array).
 *
 * Returns:
 *   ['status' => 'credited'|'unattributed'|'ignored'|'duplicate'|'rejected',
 *    'dp' => int, 'account_id' => ?int, 'message' => string]
 *
 * Only genuine DB faults throw; all normal outcomes return a status. The
 * caller (the webhook endpoint) always replies 200 to Ko-fi regardless —
 * Ko-fi retries non-200s, and we never want a retry storm for a logged
 * unattributed/ignored donation.
 */
function donation_process_webhook(PDO $pdo_auth, array $config, array $data, ?string $ip = null): array
{
    $cfg = donation_config($config);

    // 1. Authenticate the delivery. A bad/absent token is silently rejected
    //    and NOT written to donation_log (it may be spam or a probe).
    $sent = (string)($data['verification_token'] ?? '');
    if ($cfg['token'] === '' || !hash_equals($cfg['token'], $sent)) {
        return ['status' => 'rejected', 'dp' => 0, 'account_id' => null,
                'message' => 'verification token mismatch'];
    }

    // 2. Need a transaction id to dedupe on.
    $txn = trim((string)($data['kofi_transaction_id'] ?? $data['message_id'] ?? ''));
    if ($txn === '') {
        return ['status' => 'rejected', 'dp' => 0, 'account_id' => null,
                'message' => 'missing kofi_transaction_id'];
    }

    $amount    = (float)($data['amount'] ?? 0);
    $currency  = strtoupper(trim((string)($data['currency'] ?? $cfg['currency']))) ?: $cfg['currency'];
    $kofi_type = substr((string)($data['type'] ?? ''), 0, 32);
    $from_name = substr((string)($data['from_name'] ?? ''), 0, 100);
    $email     = substr((string)($data['email'] ?? ''), 0, 255);
    $msg       = (string)($data['message'] ?? '');

    // 3. Attribution + amount → status/DP.
    $code    = donation_extract_code($msg);
    $account = $code ? donation_account_for_code($pdo_auth, $code) : null;

    if ($amount < $cfg['min_amount']) {
        $status = 'ignored';
        $dp     = 0;
    } elseif ($account === null) {
        $status = 'unattributed';
        $dp     = 0;
    } else {
        $dp = (int)floor($amount * $cfg['rate']);
        if ($dp <= 0) {
            $status = 'ignored';
        } else {
            $status = 'credited';
        }
    }
    $account_id = $account['id'] ?? null;

    // 4. Atomic: log + (if credited) bump account.dp. The UNIQUE
    //    kofi_transaction_id is the replay guard — a duplicate INSERT throws
    //    23000 and we report 'duplicate' without crediting.
    try {
        $pdo_auth->beginTransaction();

        $ins = $pdo_auth->prepare(
            "INSERT INTO donation_log
               (kofi_transaction_id, account_id, username, amount, currency,
                dp_credited, kofi_type, from_name, email, message, status)
             VALUES
               (:txn, :aid, :uname, :amt, :cur,
                :dp, :ktype, :fname, :email, :msg, :status)"
        );
        $ins->execute([
            'txn'    => $txn,
            'aid'    => $account_id,
            'uname'  => $account['username'] ?? null,
            'amt'    => $amount,
            'cur'    => $currency,
            'dp'     => $dp,
            'ktype'  => $kofi_type,
            'fname'  => $from_name,
            'email'  => $email,
            'msg'    => $msg,
            'status' => $status,
        ]);

        if ($status === 'credited') {
            $upd = $pdo_auth->prepare(
                "UPDATE account SET dp = COALESCE(dp, 0) + :amt WHERE id = :id"
            );
            $upd->execute(['amt' => $dp, 'id' => $account_id]);
            if ($upd->rowCount() === 0) {
                // Account vanished between code lookup and credit — downgrade
                // to unattributed rather than crediting a ghost row.
                $fix = $pdo_auth->prepare(
                    "UPDATE donation_log
                        SET status = 'unattributed', dp_credited = 0, account_id = NULL
                      WHERE kofi_transaction_id = :txn"
                );
                $fix->execute(['txn' => $txn]);
                $status     = 'unattributed';
                $dp         = 0;
                $account_id = null;
            }
        }

        $pdo_auth->commit();
    } catch (PDOException $e) {
        if ($pdo_auth->inTransaction()) {
            $pdo_auth->rollBack();
        }
        if ($e->getCode() === '23000') {
            // Already processed — Ko-fi re-delivery. Idempotent no-op.
            return ['status' => 'duplicate', 'dp' => 0,
                    'account_id' => null, 'message' => 'already processed'];
        }
        throw $e;
    }

    // 5. Audit-log credited and unattributed deliveries so GMs see the money
    //    trail (and can manually resolve unattributed ones).
    if ($status === 'credited') {
        log_admin_action(
            $pdo_auth, 0, 'Ko-fi', 'donation_credit',
            'account:' . $account_id,
            "txn={$txn} amount={$amount}{$currency} dp={$dp} from=" . ($from_name ?: '?'),
            $ip
        );
    } elseif ($status === 'unattributed') {
        log_admin_action(
            $pdo_auth, 0, 'Ko-fi', 'donation_unattributed',
            'txn:' . $txn,
            "amount={$amount}{$currency} from=" . ($from_name ?: '?')
                . " — no valid code in message; resolve manually",
            $ip
        );
    }

    return ['status' => $status, 'dp' => $dp,
            'account_id' => $account_id, 'message' => 'ok'];
}

/**
 * Recent successfully-credited donations for an account (newest first),
 * for the user-facing /shop confirmation list.
 */
function donation_recent_for_account(PDO $pdo_auth, int $account_id, int $limit = 5): array
{
    try {
        $limit = max(1, min(50, $limit));
        $stmt  = $pdo_auth->prepare(
            "SELECT amount, currency, dp_credited, created_at
               FROM donation_log
              WHERE account_id = :id AND status = 'credited'
              ORDER BY created_at DESC, id DESC
              LIMIT $limit"
        );
        $stmt->execute(['id' => $account_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("donation_recent_for_account error: " . $e->getMessage());
        return [];
    }
}
