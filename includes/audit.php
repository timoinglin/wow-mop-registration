<?php
/**
 * Audit Log Helper
 * Logs admin actions to the admin_audit_log table.
 */

function log_admin_action(PDO $pdo, int $admin_id, string $admin_name, string $action, ?string $target = null, ?string $details = null, ?string $ip = null): bool
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO admin_audit_log (admin_id, admin_name, action, target, details, ip_address, created_at)
             VALUES (:aid, :aname, :action, :target, :details, :ip, NOW())"
        );
        return $stmt->execute([
            'aid'     => $admin_id,
            'aname'   => $admin_name,
            'action'  => $action,
            'target'  => $target,
            'details' => $details,
            'ip'      => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
        ]);
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}
