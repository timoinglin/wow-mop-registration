<?php
/**
 * Playtime Rewards — Battle Pay (DP) automatically earned from in-game playtime.
 *
 * Source of truth: SUM(characters.totaltime) — updated by the worldserver,
 * so AFK still counts but login/logout farming gives nothing.
 *
 * State lives in `playtime_rewards` (per-account rolling counters);
 * each successful claim is appended to `playtime_reward_log`.
 */

/**
 * Reads config with sensible defaults; treats missing config as DISABLED.
 */
function pr_config(array $config): array
{
    $cfg = $config['playtime_reward'] ?? [];
    return [
        'enabled'      => !empty($cfg['enabled']),
        'dp_per_hour'  => (int)($cfg['dp_per_hour']  ?? 10),
        'daily_cap_dp' => (int)($cfg['daily_cap_dp'] ?? 50),
    ];
}

/**
 * Returns the total seconds played by all characters on this account.
 */
function pr_total_seconds(int $account_id, ?PDO $pdo_chars): int
{
    if (!$pdo_chars) return 0;
    try {
        $s = $pdo_chars->prepare("SELECT COALESCE(SUM(totaltime), 0) FROM characters WHERE account = :id");
        $s->execute(['id' => $account_id]);
        return (int)$s->fetchColumn();
    } catch (PDOException $e) {
        error_log('pr_total_seconds: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Returns a status snapshot for the dashboard. Auto-creates the row on first
 * call so new users have an accurate baseline (existing playtime is NOT paid out
 * retroactively — they earn from this point forward).
 */
function pr_get_status(int $account_id, PDO $pdo_auth, ?PDO $pdo_chars, array $config): array
{
    $cfg   = pr_config($config);
    $today = date('Y-m-d');

    $current_total = pr_total_seconds($account_id, $pdo_chars);

    // Read existing row, init if missing
    try {
        $sel = $pdo_auth->prepare("SELECT * FROM playtime_rewards WHERE account_id = :id");
        $sel->execute(['id' => $account_id]);
        $r = $sel->fetch();

        if (!$r) {
            // Initialize baseline at current total — past playtime is NOT retroactively rewarded
            $ins = $pdo_auth->prepare(
                "INSERT IGNORE INTO playtime_rewards (account_id, last_total_seconds, today_dp_claimed, today_date, total_paid_dp)
                 VALUES (:id, :ts, 0, :td, 0)"
            );
            $ins->execute(['id' => $account_id, 'ts' => $current_total, 'td' => $today]);
            $r = [
                'last_total_seconds' => $current_total,
                'today_dp_claimed'   => 0,
                'today_date'         => $today,
                'total_paid_dp'      => 0,
                'last_claim_at'      => null,
            ];
        }
    } catch (PDOException $e) {
        error_log('pr_get_status read failed: ' . $e->getMessage());
        $r = [
            'last_total_seconds' => $current_total,
            'today_dp_claimed'   => 0,
            'today_date'         => $today,
            'total_paid_dp'      => 0,
            'last_claim_at'      => null,
        ];
    }

    // If today_date is older than today, treat today_dp_claimed as 0 for display
    $today_already = ($r['today_date'] < $today) ? 0 : (int)$r['today_dp_claimed'];
    $remaining_cap = max(0, $cfg['daily_cap_dp'] - $today_already);

    $delta_seconds = max(0, $current_total - (int)$r['last_total_seconds']);
    $available_hours = intdiv($delta_seconds, 3600);
    $available_dp_uncapped = $available_hours * $cfg['dp_per_hour'];
    $available_dp = min($available_dp_uncapped, $remaining_cap);

    // Time until the next 1h milestone (next DP becomes claimable)
    $seconds_to_next = $available_hours > 0 ? 0 : (3600 - ($delta_seconds % 3600));

    // Time until the daily cap resets (server local midnight)
    $reset_at = strtotime('tomorrow 00:00:00');
    $seconds_to_reset = max(0, $reset_at - time());

    return [
        'enabled'              => $cfg['enabled'],
        'rate_per_hour'        => $cfg['dp_per_hour'],
        'daily_cap_dp'         => $cfg['daily_cap_dp'],
        'total_played_seconds' => $current_total,
        'total_paid_dp'        => (int)$r['total_paid_dp'],
        'available_dp'         => $available_dp,
        'today_claimed_dp'     => $today_already,
        'cap_remaining'        => $remaining_cap,
        'cap_reached'          => $remaining_cap === 0,
        'seconds_to_next_dp'   => $seconds_to_next,
        'seconds_to_reset'     => $seconds_to_reset,
        'last_claim_at'        => $r['last_claim_at'],
    ];
}

/**
 * Atomically claim available Battle Pay. Returns the DP amount claimed.
 * Returns 0 (and does nothing) if feature disabled, account banned, or no DP available.
 *
 * @throws PDOException on DB failure
 */
function pr_claim(int $account_id, PDO $pdo_auth, ?PDO $pdo_chars, array $config): int
{
    $cfg = pr_config($config);
    if (!$cfg['enabled']) return 0;
    if ($cfg['dp_per_hour'] <= 0) return 0;

    // Refuse banned accounts
    try {
        $b = $pdo_auth->prepare("SELECT 1 FROM account_banned WHERE id = :id AND active = 1 LIMIT 1");
        $b->execute(['id' => $account_id]);
        if ($b->fetchColumn()) return 0;
    } catch (PDOException $e) {
        // Table not present? Don't block — log only.
        error_log('pr_claim ban check: ' . $e->getMessage());
    }

    $today = date('Y-m-d');
    $current_total = pr_total_seconds($account_id, $pdo_chars);

    $pdo_auth->beginTransaction();
    try {
        // Ensure row exists (defensive — pr_get_status normally creates it)
        $ins = $pdo_auth->prepare(
            "INSERT IGNORE INTO playtime_rewards (account_id, last_total_seconds, today_dp_claimed, today_date, total_paid_dp)
             VALUES (:id, :ts, 0, :td, 0)"
        );
        $ins->execute(['id' => $account_id, 'ts' => $current_total, 'td' => $today]);

        // Lock the row
        $sel = $pdo_auth->prepare("SELECT * FROM playtime_rewards WHERE account_id = :id FOR UPDATE");
        $sel->execute(['id' => $account_id]);
        $r = $sel->fetch();

        if (!$r) {
            $pdo_auth->commit();
            return 0;
        }

        // Treat past dates' claim count as 0 for today's cap calculation
        $today_already = ($r['today_date'] < $today) ? 0 : (int)$r['today_dp_claimed'];
        $remaining_cap = max(0, $cfg['daily_cap_dp'] - $today_already);

        $delta_seconds = max(0, $current_total - (int)$r['last_total_seconds']);
        $hours_available = intdiv($delta_seconds, 3600);
        if ($hours_available < 1 || $remaining_cap <= 0) {
            // Refresh today_date if needed and exit
            if ($r['today_date'] < $today) {
                $u = $pdo_auth->prepare("UPDATE playtime_rewards SET today_dp_claimed = 0, today_date = :td WHERE account_id = :id");
                $u->execute(['td' => $today, 'id' => $account_id]);
            }
            $pdo_auth->commit();
            return 0;
        }

        $earned_uncapped = $hours_available * $cfg['dp_per_hour'];
        $earned          = min($earned_uncapped, $remaining_cap);

        // Convert capped DP back to whole hours so partial hours roll over to tomorrow
        $hours_paid    = intdiv($earned, $cfg['dp_per_hour']);
        $seconds_paid  = $hours_paid * 3600;

        if ($earned <= 0 || $hours_paid <= 0) {
            $pdo_auth->commit();
            return 0;
        }

        // Update playtime_rewards (advance baseline + bump today's tally)
        $u = $pdo_auth->prepare(
            "UPDATE playtime_rewards
             SET last_total_seconds = last_total_seconds + :s,
                 today_dp_claimed   = :tdc,
                 today_date         = :td,
                 total_paid_dp      = total_paid_dp + :amt,
                 last_claim_at      = NOW()
             WHERE account_id = :id"
        );
        $u->execute([
            's'   => $seconds_paid,
            'tdc' => $today_already + $earned,
            'td'  => $today,
            'amt' => $earned,
            'id'  => $account_id,
        ]);

        // Add to account.dp
        $dp = $pdo_auth->prepare("UPDATE account SET dp = COALESCE(dp, 0) + :amt WHERE id = :id");
        $dp->execute(['amt' => $earned, 'id' => $account_id]);

        // Audit log
        $log = $pdo_auth->prepare(
            "INSERT INTO playtime_reward_log (account_id, dp_amount, seconds_claimed, total_seconds_at_claim)
             VALUES (:id, :dp, :s, :total)"
        );
        $log->execute([
            'id'    => $account_id,
            'dp'    => $earned,
            's'     => $seconds_paid,
            'total' => $current_total,
        ]);

        $pdo_auth->commit();
        return $earned;
    } catch (Exception $e) {
        if ($pdo_auth->inTransaction()) $pdo_auth->rollBack();
        throw $e;
    }
}

/**
 * Returns the most-recent claims (newest first).
 */
function pr_get_history(int $account_id, PDO $pdo_auth, int $limit = 10): array
{
    try {
        $stmt = $pdo_auth->prepare(
            "SELECT dp_amount, seconds_claimed, total_seconds_at_claim, created_at
             FROM playtime_reward_log
             WHERE account_id = :id
             ORDER BY created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':id',  $account_id, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('pr_get_history: ' . $e->getMessage());
        return [];
    }
}

/**
 * Format seconds → "5h 12m" (compact for UI use).
 */
function pr_format_duration(int $seconds): string
{
    if ($seconds <= 0) return '0m';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $out = '';
    if ($h > 0) $out .= $h . 'h ';
    $out .= $m . 'm';
    return trim($out);
}
