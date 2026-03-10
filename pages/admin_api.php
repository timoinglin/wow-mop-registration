<?php
/**
 * Admin API — AJAX endpoint for admin dashboard actions.
 * All actions require GM level >= 9.
 */
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/audit.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$gm_check = $pdo_auth->prepare("SELECT gmlevel FROM account_access WHERE id = :id ORDER BY gmlevel DESC LIMIT 1");
$gm_check->execute(['id' => $_SESSION['user_id']]);
$gm_level = (int)($gm_check->fetchColumn() ?: 0);

if ($gm_level < 9) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['username'] ?? 'Unknown';
$action     = $_GET['action'] ?? $_POST['action'] ?? '';
$ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

try {
    switch ($action) {

        // ─── BAN ACCOUNT ─────────────────────────────────────────────────
        case 'ban':
            $target_id = (int)($_POST['account_id'] ?? 0);
            $reason    = trim($_POST['reason'] ?? 'No reason specified');
            $duration  = (int)($_POST['duration'] ?? -1); // -1 = permanent

            if (!$target_id) { echo json_encode(['error' => 'Missing account_id']); exit; }

            $ban_time = time();
            $unban_time = $duration === -1 ? 0 : $ban_time + $duration;

            // Insert ban
            $stmt = $pdo_auth->prepare(
                "INSERT INTO account_banned (id, bandate, unbandate, bannedby, banreason, active)
                 VALUES (:id, :bandate, :unbandate, :bannedby, :reason, 1)"
            );
            $stmt->execute([
                'id'         => $target_id,
                'bandate'    => $ban_time,
                'unbandate'  => $unban_time,
                'bannedby'   => $admin_name,
                'reason'     => $reason,
            ]);

            // Get username for audit
            $u = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
            $u->execute(['id' => $target_id]);
            $target_name = $u->fetchColumn() ?: "ID:$target_id";

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ban', $target_name, "Reason: $reason, Duration: " . ($duration === -1 ? 'Permanent' : gmdate('H\hi\ms\s', $duration)), $ip);

            echo json_encode(['success' => true, 'message' => "Account $target_name banned"]);
            break;

        // ─── UNBAN ACCOUNT ───────────────────────────────────────────────
        case 'unban':
            $target_id = (int)($_POST['account_id'] ?? 0);
            if (!$target_id) { echo json_encode(['error' => 'Missing account_id']); exit; }

            $stmt = $pdo_auth->prepare("UPDATE account_banned SET active = 0 WHERE id = :id AND active = 1");
            $stmt->execute(['id' => $target_id]);

            $u = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
            $u->execute(['id' => $target_id]);
            $target_name = $u->fetchColumn() ?: "ID:$target_id";

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'unban', $target_name, null, $ip);

            echo json_encode(['success' => true, 'message' => "Account $target_name unbanned"]);
            break;

        // ─── REPLY TO TICKET ─────────────────────────────────────────────
        case 'reply_ticket':
            $ticket_id = (int)($_POST['ticket_id'] ?? 0);
            $reply     = trim($_POST['reply'] ?? '');
            $new_status = $_POST['status'] ?? 'in_progress';

            if (!$ticket_id || empty($reply)) { echo json_encode(['error' => 'Missing ticket_id or reply']); exit; }

            $stmt = $pdo_auth->prepare(
                "UPDATE tickets SET admin_reply = :reply, replied_by = :by, status = :status, updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'reply'  => $reply,
                'by'     => $admin_name,
                'status' => $new_status,
                'id'     => $ticket_id,
            ]);

            // Get ticket info for email notification
            $t = $pdo_auth->prepare("SELECT username, email, subject FROM tickets WHERE id = :id");
            $t->execute(['id' => $ticket_id]);
            $ticket = $t->fetch();

            // Send email notification to user
            if ($ticket && !empty($ticket['email'])) {
                require_once __DIR__ . '/../includes/email.php';
                $server_name = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');
                $inner = "
                    <h2 style='color:#c8a96e;margin-top:0'>📋 Ticket Reply</h2>
                    <p style='color:#8899aa'>Your ticket <strong style='color:#e2e8f0'>\"" . htmlspecialchars($ticket['subject']) . "\"</strong> has received a reply:</p>
                    <div style='background:#1a1a2e;border-left:3px solid #c8a96e;padding:16px;border-radius:6px;white-space:pre-line;color:#e2e8f0'>"
                    . htmlspecialchars($reply) . "</div>
                    <p style='color:#8899aa;margin-top:16px;font-size:13px'>Status: <strong style='color:#c8a96e'>" . ucfirst(str_replace('_', ' ', $new_status)) . "</strong></p>
                ";
                $email_body = email_template($inner, "Ticket reply: " . $ticket['subject']);
                @send_email($ticket['email'], "[$server_name] Ticket Reply: " . $ticket['subject'], $email_body);
            }

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ticket_reply', "Ticket #$ticket_id", "Status: $new_status", $ip);

            echo json_encode(['success' => true, 'message' => "Reply sent to ticket #$ticket_id"]);
            break;

        // ─── UPDATE TICKET STATUS ────────────────────────────────────────
        case 'update_ticket_status':
            $ticket_id  = (int)($_POST['ticket_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';

            if (!$ticket_id || !in_array($new_status, ['open', 'in_progress', 'closed'])) {
                echo json_encode(['error' => 'Invalid parameters']); exit;
            }

            $stmt = $pdo_auth->prepare("UPDATE tickets SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['status' => $new_status, 'id' => $ticket_id]);

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ticket_status', "Ticket #$ticket_id", "New status: $new_status", $ip);

            echo json_encode(['success' => true]);
            break;

        // ─── RESET PASSWORD ──────────────────────────────────────────────
        case 'reset_password':
            $target_id = (int)($_POST['account_id'] ?? 0);
            $new_pass  = trim($_POST['new_password'] ?? '');

            if (!$target_id || empty($new_pass)) { echo json_encode(['error' => 'Missing account_id or new_password']); exit; }

            $u = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
            $u->execute(['id' => $target_id]);
            $target_name = $u->fetchColumn();

            if (!$target_name) { echo json_encode(['error' => 'Account not found']); exit; }

            require_once __DIR__ . '/../includes/auth.php';
            $hash = sha_password($target_name, $new_pass);
            $stmt = $pdo_auth->prepare("UPDATE account SET sha_pass_hash = :hash, v = '', s = '' WHERE id = :id");
            $stmt->execute(['hash' => $hash, 'id' => $target_id]);

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'reset_password', $target_name, null, $ip);

            echo json_encode(['success' => true, 'message' => "Password reset for $target_name"]);
            break;

        // ─── UPDATE ACCOUNT ──────────────────────────────────────────────
        case 'update_account':
            $target_id = (int)($_POST['account_id'] ?? 0);
            $new_email = trim($_POST['email'] ?? '');
            $new_gm    = $_POST['gmlevel'] ?? null;
            $new_dp    = $_POST['dp'] ?? null;

            if (!$target_id) { echo json_encode(['error' => 'Missing account_id']); exit; }

            $u = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
            $u->execute(['id' => $target_id]);
            $target_name = $u->fetchColumn();
            if (!$target_name) { echo json_encode(['error' => 'Account not found']); exit; }

            $changes = [];

            if (!empty($new_email)) {
                $stmt = $pdo_auth->prepare("UPDATE account SET email = :email WHERE id = :id");
                $stmt->execute(['email' => $new_email, 'id' => $target_id]);
                $changes[] = "Email → $new_email";
            }

            if ($new_gm !== null && $new_gm !== '') {
                $gm_int = (int)$new_gm;
                // Delete existing access
                $pdo_auth->prepare("DELETE FROM account_access WHERE id = :id")->execute(['id' => $target_id]);
                if ($gm_int > 0) {
                    $stmt = $pdo_auth->prepare("INSERT INTO account_access (id, gmlevel, RealmID) VALUES (:id, :gm, -1)");
                    $stmt->execute(['id' => $target_id, 'gm' => $gm_int]);
                }
                $changes[] = "GM Level → $gm_int";
            }

            if ($new_dp !== null && $new_dp !== '') {
                if (!preg_match('/^\d+$/', (string)$new_dp)) {
                    echo json_encode(['error' => 'Battle Pay balance must be a non-negative whole number']);
                    exit;
                }

                $dp_int = (int)$new_dp;
                $stmt = $pdo_auth->prepare("UPDATE account SET dp = :dp WHERE id = :id");
                $stmt->execute(['dp' => $dp_int, 'id' => $target_id]);
                $changes[] = "Battle Pay → $dp_int";
            }

            if (!empty($changes)) {
                log_admin_action($pdo_auth, $admin_id, $admin_name, 'update_account', $target_name, implode(', ', $changes), $ip);
            }

            echo json_encode(['success' => true, 'message' => "Account $target_name updated"]);
            break;

        // ─── IP BAN ──────────────────────────────────────────────────────
        case 'ip_ban':
            $ban_ip   = trim($_POST['ip'] ?? '');
            $reason   = trim($_POST['reason'] ?? 'No reason');
            $duration = (int)($_POST['duration'] ?? -1);

            if (empty($ban_ip)) { echo json_encode(['error' => 'Missing IP']); exit; }

            $ban_time = time();
            $unban_time = $duration === -1 ? 0 : $ban_time + $duration;

            $stmt = $pdo_auth->prepare(
                "INSERT INTO ip_banned (ip, bandate, unbandate, bannedby, banreason)
                 VALUES (:ip, :bandate, :unbandate, :bannedby, :reason)"
            );
            $stmt->execute([
                'ip'        => $ban_ip,
                'bandate'   => $ban_time,
                'unbandate' => $unban_time,
                'bannedby'  => $admin_name,
                'reason'    => $reason,
            ]);

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ip_ban', $ban_ip, "Reason: $reason", $ip);

            echo json_encode(['success' => true, 'message' => "IP $ban_ip banned"]);
            break;

        case 'ip_unban':
            $unban_ip = trim($_POST['ip'] ?? '');
            if (empty($unban_ip)) { echo json_encode(['error' => 'Missing IP']); exit; }

            $pdo_auth->prepare("DELETE FROM ip_banned WHERE ip = :ip")->execute(['ip' => $unban_ip]);

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'ip_unban', $unban_ip, null, $ip);

            echo json_encode(['success' => true, 'message' => "IP $unban_ip unbanned"]);
            break;

        // ─── GET IP BANS ─────────────────────────────────────────────────
        case 'get_ip_bans':
            $bans = $pdo_auth->query(
                "SELECT ip, bandate, unbandate, bannedby, banreason FROM ip_banned ORDER BY bandate DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'bans' => $bans]);
            break;

        // ─── CHARACTER LOOKUP ────────────────────────────────────────────
        case 'search_character':
            $q = trim($_GET['q'] ?? '');
            if (empty($q) || !$pdo_chars) { echo json_encode(['success' => true, 'characters' => []]); exit; }

            $stmt = $pdo_chars->prepare(
                "SELECT c.guid, c.name, c.level, c.race, c.class, c.gender, c.zone, c.account,
                        c.totaltime, c.money, c.online,
                        g.name as guild_name
                 FROM characters c
                 LEFT JOIN guild_member gm ON gm.guid = c.guid
                 LEFT JOIN guild g ON g.guildid = gm.guildid
                 WHERE c.name LIKE :q
                 ORDER BY c.level DESC LIMIT 20"
            );
            $stmt->execute(['q' => "%$q%"]);
            $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enrich with account username
            foreach ($chars as &$c) {
                $a = $pdo_auth->prepare("SELECT username FROM account WHERE id = :id");
                $a->execute(['id' => $c['account']]);
                $c['account_name'] = $a->fetchColumn() ?: '?';
            }

            echo json_encode(['success' => true, 'characters' => $chars]);
            break;

        // ─── AUDIT LOG ───────────────────────────────────────────────────
        case 'audit_log':
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $limit = 25;
            $offset = ($page - 1) * $limit;

            $total = (int)$pdo_auth->query("SELECT COUNT(*) FROM admin_audit_log")->fetchColumn();
            $rows  = $pdo_auth->query(
                "SELECT * FROM admin_audit_log ORDER BY created_at DESC LIMIT $limit OFFSET $offset"
            )->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'entries' => $rows, 'total' => $total, 'pages' => ceil($total / $limit), 'page' => $page]);
            break;

        // ─── PEAK PLAYERS ────────────────────────────────────────────────
        case 'peak_players':
            if (!$pdo_chars) { echo json_encode(['success' => true, 'data' => []]); exit; }

            // Try to get online player count snapshots from characters table
            // We'll return the current counts as a baseline
            $online = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters WHERE online = 1")->fetchColumn();
            $total_chars = (int)$pdo_chars->query("SELECT COUNT(*) FROM characters")->fetchColumn();
            $total_accounts = (int)$pdo_auth->query("SELECT COUNT(*) FROM account")->fetchColumn();

            echo json_encode([
                'success' => true,
                'current_online' => $online,
                'total_characters' => $total_chars,
                'total_accounts' => $total_accounts,
            ]);
            break;

        // ─── ALL TICKETS (for admin) ─────────────────────────────────────
        case 'get_tickets':
            $status_filter = $_GET['status'] ?? '';
            $sql = "SELECT * FROM tickets";
            $params = [];
            if ($status_filter && in_array($status_filter, ['open', 'in_progress', 'closed'])) {
                $sql .= " WHERE status = :status";
                $params['status'] = $status_filter;
            }
            $sql .= " ORDER BY created_at DESC LIMIT 100";
            $stmt = $pdo_auth->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── EMAIL BROADCAST ─────────────────────────────────────────────
        case 'broadcast_email':
            $subject = trim($_POST['subject'] ?? '');
            $body    = trim($_POST['body'] ?? '');

            if (empty($subject) || empty($body)) {
                echo json_encode(['error' => 'Subject and body are required']); exit;
            }

            require_once __DIR__ . '/../includes/email.php';
            $server_name = htmlspecialchars($config['realm']['name'] ?? 'WoW Server');

            // Get all accounts with email
            $accounts = $pdo_auth->query("SELECT DISTINCT email FROM account WHERE email != '' AND email IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

            $sent = 0;
            $failed = 0;
            $inner = "<h2 style='color:#c8a96e;margin-top:0'>📢 " . htmlspecialchars($subject) . "</h2>"
                   . "<div style='white-space:pre-line;color:#e2e8f0'>" . htmlspecialchars($body) . "</div>";
            $email_body = email_template($inner, $subject);

            foreach ($accounts as $email) {
                if (@send_email($email, "[$server_name] $subject", $email_body)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            log_admin_action($pdo_auth, $admin_id, $admin_name, 'broadcast_email', null, "Subject: $subject, Sent: $sent, Failed: $failed", $ip);

            echo json_encode(['success' => true, 'message' => "Broadcast sent to $sent accounts ($failed failed)"]);
            break;

        // ─── GET ACCOUNT DETAILS ─────────────────────────────────────────
        case 'get_account':
            $target_id = (int)($_GET['id'] ?? 0);
            if (!$target_id) { echo json_encode(['error' => 'Missing id']); exit; }

            $stmt = $pdo_auth->prepare(
                "SELECT a.id, a.username, a.email, a.joindate, a.last_ip, a.online, a.last_login, a.dp,
                        (SELECT 1 FROM account_banned ab WHERE ab.id=a.id AND ab.active=1 LIMIT 1) as is_banned,
                        (SELECT MAX(gmlevel) FROM account_access aa WHERE aa.id=a.id) as gmlevel
                 FROM account a WHERE a.id = :id"
            );
            $stmt->execute(['id' => $target_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) { echo json_encode(['error' => 'Account not found']); exit; }

            // Get characters if chars DB available
            $chars = [];
            if ($pdo_chars) {
                $cs = $pdo_chars->prepare("SELECT name, level, race, class, online, gender FROM characters WHERE account = :aid ORDER BY level DESC");
                $cs->execute(['aid' => $target_id]);
                $chars = $cs->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'account' => $account, 'characters' => $chars]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
            break;
    }

} catch (PDOException $e) {
    error_log("Admin API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Admin API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
