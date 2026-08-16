<?php
/**
 * ALELTU POS — Inventory Conflict Center
 * Dedicated resolution workspace for offline stock over-sales
 */
session_start();
require_once 'config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'seller';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';

if ($user_id == 0 || !in_array($user_role, ['admin', 'manager', 'super_admin'])) {
    die("Access Denied: Admin authorization required.");
}

$message = '';
$error = '';

// Handle Conflict Resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conflict_id = intval($_POST['conflict_id'] ?? 0);
    $action_type = trim($_POST['resolution_action'] ?? '');
    $notes       = trim($_POST['resolution_notes'] ?? '');

    if ($conflict_id > 0 && !empty($action_type)) {
        // Fetch conflict details
        $stmt = mysqli_prepare($conn, "SELECT * FROM sync_conflicts WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $conflict_id);
        mysqli_stmt_execute($stmt);
        $conf = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($conf) {
            $new_status = 'APPROVED';
            if ($action_type === 'REFUND') $new_status = 'REFUNDED';
            if ($action_type === 'REJECT') $new_status = 'REJECTED';
            if ($action_type === 'ADJUST') $new_status = 'ADJUSTED';

            // 1. Update Conflict Status
            $upd = mysqli_prepare($conn, "UPDATE sync_conflicts SET status = ?, resolution_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'ssii', $new_status, $notes, $user_id, $conflict_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            // 2. Log in System Audit Trail (Requirement #28, #39)
            $audit = mysqli_prepare($conn, "INSERT INTO audit_logs (event_type, user_id, user_name, device_uuid, branch_id, entity, entity_uuid, action, old_value, new_value, reason) VALUES ('CONFLICT_RESOLVED', ?, ?, ?, ?, 'sync_conflicts', ?, ?, ?, ?, ?)");
            $old_val = "Status: " . $conf['status'];
            $new_val = "Status: " . $new_status;
            mysqli_stmt_bind_param($audit, 'isissssss', 
                $user_id, $user_name, $conf['device_uuid'], $conf['branch_id'], 
                $conf['conflict_uuid'], $action_type, $old_val, $new_val, $notes
            );
            mysqli_stmt_execute($audit);
            mysqli_stmt_close($audit);

            $message = "Conflict #{$conflict_id} resolved with action '{$action_type}'.";
        }
    }
}

// Fetch all conflicts requiring review
$conf_res = mysqli_query($conn, "SELECT c.*, p.place_name as branch_name, u.username as seller_username FROM sync_conflicts c LEFT JOIN places p ON c.branch_id = p.id LEFT JOIN users u ON c.seller_id = u.id ORDER BY c.created_at DESC");
$conflicts = [];
if ($conf_res) {
    while ($row = mysqli_fetch_assoc($conf_res)) {
        $conflicts[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aleltu — Inventory Conflict Center</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #1e293b;
            --accent: #8b5cf6;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --border: #334155;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .title h1 { margin: 0; font-size: 24px; color: var(--accent); }
        .title p { margin: 5px 0 0; color: var(--text-muted); font-size: 14px; }
        .btn {
            background: #3b82f6;
            color: white;
            padding: 8px 14px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }
        .btn-success { background: var(--success); }
        .btn-danger { background: var(--danger); }
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        th { background: #0f172a; color: var(--text-muted); }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; }
    </style>
</head>
<body>

    <div class="header">
        <div class="title">
            <h1>⚠️ Inventory Conflict Center</h1>
            <p>Review and resolve offline sales overage conflicts with full audit trail.</p>
        </div>
        <div>
            <a href="admin_dashboard.php" class="btn">Dashboard</a>
            <a href="offline_controller.php" class="btn" style="background:#3b82f6;">Offline Rules</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Offline Inventory Conflicts</h2>
        <table>
            <thead>
                <tr>
                    <th>Conflict UUID</th>
                    <th>Product</th>
                    <th>Req. Qty</th>
                    <th>Server Stock</th>
                    <th>Seller / Branch</th>
                    <th>Sale UUID</th>
                    <th>Status</th>
                    <th>Resolution</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conflicts)): ?>
                    <tr><td colspan="8" style="text-align:center; color:#94a3b8;">🟢 No active conflicts recorded. All offline sales synchronized cleanly.</td></tr>
                <?php else: ?>
                    <?php foreach ($conflicts as $c): ?>
                        <tr>
                            <td><code><?= htmlspecialchars(substr($c['conflict_uuid'], 0, 12)) ?>...</code></td>
                            <td><strong><?= htmlspecialchars($c['product_name']) ?></strong></td>
                            <td style="color:#ef4444; font-weight:bold;"><?= number_format($c['requested_qty'], 2) ?></td>
                            <td><?= number_format($c['available_qty'], 2) ?></td>
                            <td><?= htmlspecialchars($c['seller_username'] ?? 'Seller') ?> (<?= htmlspecialchars($c['branch_name'] ?? 'Branch') ?>)</td>
                            <td><small><?= htmlspecialchars(substr($c['sale_uuid'], 0, 10)) ?>...</small></td>
                            <td>
                                <?php if ($c['status'] === 'REQUIRES_REVIEW'): ?>
                                    <span class="badge badge-warning">REQUIRES REVIEW</span>
                                <?php elseif ($c['status'] === 'APPROVED'): ?>
                                    <span class="badge badge-success">APPROVED</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><?= $c['status'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['status'] === 'REQUIRES_REVIEW'): ?>
                                    <form method="POST" style="display:flex; gap:6px;">
                                        <input type="hidden" name="conflict_id" value="<?= $c['id'] ?>">
                                        <input type="text" name="resolution_notes" placeholder="Reason/Notes" required style="padding:4px 8px; font-size:12px; border-radius:4px; border:1px solid #334155; background:#0f172a; color:white;">
                                        <button type="submit" name="resolution_action" value="APPROVE" class="btn btn-success">Approve</button>
                                        <button type="submit" name="resolution_action" value="REFUND" class="btn btn-danger">Refund</button>
                                    </form>
                                <?php else: ?>
                                    <small style="color:#94a3b8;"><?= htmlspecialchars($c['resolution_notes']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
