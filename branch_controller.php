<?php
session_start();

/* =========================
   DATABASE CONNECTION
========================= */

include 'config.php';

/*
|--------------------------------------------------------------------------
| BRANCH CONTROLLER
|--------------------------------------------------------------------------
| Compatible with OLD places table
|--------------------------------------------------------------------------
*/

/* =========================
   SECURITY
========================= */

if (!isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] != 'super_admin') {
    die("Access Denied!");
}

/* =========================
   FIX AUTO_INCREMENT - Always set to max(id) + 1 (or 1 if empty)
========================= */

function fixAutoIncrement($conn) {
    // Check if table has any records
    $count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM places");
    $count_row = mysqli_fetch_assoc($count_query);
    $total_records = $count_row['total'];
    
    if ($total_records == 0) {
        // No records exist, set auto_increment to 1
        mysqli_query($conn, "ALTER TABLE places AUTO_INCREMENT = 1");
    } else {
        // Get the maximum ID
        $max_query = mysqli_query($conn, "SELECT MAX(id) as max_id FROM places");
        $max_row = mysqli_fetch_assoc($max_query);
        $max_id = $max_row['max_id'];
        
        if ($max_id !== null && $max_id > 0) {
            // Set auto_increment to max_id + 1
            $next_id = $max_id + 1;
            mysqli_query($conn, "ALTER TABLE places AUTO_INCREMENT = $next_id");
        } else {
            // Fallback - set to 1
            mysqli_query($conn, "ALTER TABLE places AUTO_INCREMENT = 1");
        }
    }
}

/* =========================
   ADD BRANCH
========================= */

if (isset($_POST['add_branch'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) { die('Invalid request.'); }
    $place_name = trim($_POST['place_name']);

    // Check duplicate — prepared statement
    $chk = mysqli_prepare($conn, "SELECT id FROM places WHERE LOWER(place_name) = LOWER(?)");
    mysqli_stmt_bind_param($chk, 's', $place_name);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    $dup = mysqli_stmt_num_rows($chk);
    mysqli_stmt_close($chk);

    if ($dup > 0) {
        $msg = "Branch already exists!";
        $msg_type = "error";
    } else {
        fixAutoIncrement($conn);
        $ins = mysqli_prepare($conn, "INSERT INTO places(place_name) VALUES(?)");
        mysqli_stmt_bind_param($ins, 's', $place_name);
        if (mysqli_stmt_execute($ins)) {
            $new_id = mysqli_insert_id($conn);
            $msg = "Branch Added Successfully (ID: $new_id)";
            $msg_type = "success";
        } else {
            $msg = "Failed To Add Branch.";
            $msg_type = "error";
        }
        mysqli_stmt_close($ins);
    }
}

/* =========================
   DELETE BRANCH
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_branch'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) { die('Invalid request.'); }
    $id = intval($_POST['branch_id'] ?? 0);
    if ($id <= 0) { die('Invalid branch.'); }
    
    // First, check if branch has any users
    $check_users = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM users WHERE branch_id = ?");
    mysqli_stmt_bind_param($check_users, 'i', $id);
    mysqli_stmt_execute($check_users);
    $user_count = mysqli_fetch_assoc(mysqli_stmt_get_result($check_users));
    mysqli_stmt_close($check_users);
    
    if ($user_count['total'] > 0) {
        // Cannot delete branch with associated users
        $msg = "Cannot delete branch! This branch has " . $user_count['total'] . " user(s) assigned. Please reassign or delete them first.";
        $msg_type = "error";
    } else {
        mysqli_begin_transaction($conn);
        try {
            foreach (['products', 'excel_data', 'seller_inventory', 'stock_logs', 'transaction_items', 'transactions', 'boss_daily', 'boss_sessions', 'boss_transactions'] as $table) {
                $stmt = mysqli_prepare($conn, "DELETE FROM {$table} WHERE branch_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $id);
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException('Failed to remove associated branch data.');
                }
                mysqli_stmt_close($stmt);
            }
            $delete = mysqli_prepare($conn, "DELETE FROM places WHERE id = ?");
            mysqli_stmt_bind_param($delete, 'i', $id);
            if (!mysqli_stmt_execute($delete)) {
                mysqli_stmt_close($delete);
                throw new RuntimeException('Failed to remove branch.');
            }
            $deleted = mysqli_stmt_affected_rows($delete) === 1;
            mysqli_stmt_close($delete);
            if (!$deleted) { throw new RuntimeException('Branch was not deleted.'); }
            mysqli_commit($conn);
            $msg = "Branch Deleted Successfully";
            $msg_type = "success";
            // Fix auto increment after delete
            fixAutoIncrement($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('branch_controller.php delete failed: ' . $e->getMessage());
            $msg = "Failed To Delete Branch";
            $msg_type = "error";
        }
    }
}

/* =========================
   UPDATE BRANCH
========================= */

if (isset($_POST['update_branch'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) { die('Invalid request.'); }
    $id         = intval($_POST['place_id']);
    $place_name = trim($_POST['place_name']);

    // Check duplicate — prepared statement
    $chk = mysqli_prepare($conn, "SELECT id FROM places WHERE LOWER(place_name) = LOWER(?) AND id != ?");
    mysqli_stmt_bind_param($chk, 'si', $place_name, $id);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    $dup = mysqli_stmt_num_rows($chk);
    mysqli_stmt_close($chk);

    if ($dup > 0) {
        $msg = "Another branch already exists with this name!";
        $msg_type = "error";
    } else {
        $upd = mysqli_prepare($conn, "UPDATE places SET place_name = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, 'si', $place_name, $id);
        if (mysqli_stmt_execute($upd)) {
            $msg = "Branch Updated Successfully";
            $msg_type = "success";
        } else {
            $msg = "Update Failed.";
            $msg_type = "error";
        }
    }
}

// Fix auto_increment on every page load
fixAutoIncrement($conn);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Branch Controller - Aleltu POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ── Mobile-First Base Styles (Phones <= 768px) ── */
        body {
            background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%);
            min-height: 100vh;
            padding: 10px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(10px);
            color: white;
            padding: 10px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.25);
            min-height: 44px;
            width: 100%;
        }
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.28);
        }

        /* Stats Cards: 2-column grid on phone */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stat-info h2 {
            font-size: 22px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 2px;
            line-height: 1.1;
        }

        .stat-info p {
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
        }

        .stat-info small {
            font-size: 10px;
            color: #94a3b8;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            min-width: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
        }

        /* Message Alert */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            font-size: 13px;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Form Section */
        .form-card, .edit-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 15px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .edit-card {
            border: 2px solid #667eea;
        }

        .form-title, .edit-title {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-title i, .edit-title i {
            color: #667eea;
        }

        .branch-form {
            display: flex;
            gap: 10px;
            flex-direction: column;
        }

        .branch-form input {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            min-height: 44px;
        }

        .branch-form input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .branch-form button, .cancel-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
            min-height: 44px;
            text-decoration: none;
        }

        .cancel-btn {
            background: #94a3b8;
        }

        .branch-form button:hover {
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        /* Table Section */
        .table-wrapper {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 14px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }

        table thead {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }

        table th {
            color: white;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            white-space: nowrap;
        }

        table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13px;
            white-space: nowrap;
            vertical-align: middle;
        }

        table tr:hover {
            background: #f8fafc;
        }

        .branch-id {
            background: #e2e8f0;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            display: inline-block;
        }

        .branch-name {
            font-weight: 600;
            color: #1e293b;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            flex-direction: row;
        }

        .btn-edit, .btn-delete {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            min-height: 34px;
            white-space: nowrap;
        }

        .btn-edit { background: #3b82f6; color: white; }
        .btn-edit:hover { background: #2563eb; }

        .btn-delete { background: #ef4444; color: white; }
        .btn-delete:hover { background: #dc2626; }

        .user-badge {
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            background: #dbeafe;
            color: #1d4ed8;
        }

        .empty-badge {
            background: #f1f5f9;
            color: #64748b;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 40px; margin-bottom: 10px; opacity: 0.5; }

        .info-footer {
            margin-top: 15px;
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            padding: 10px;
        }

        /* ── Mobile Stacked Cards (Screens <= 640px) ── */
        @media (max-width: 640px) {
            .table-wrapper {
                overflow-x: visible;
                background: transparent;
                box-shadow: none;
                border-radius: 0;
            }
            table { min-width: 0; background: transparent; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr {
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(10px);
                border-radius: 14px;
                margin-bottom: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
                overflow: hidden;
                border: 1px solid rgba(255, 255, 255, 0.3);
            }
            td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                padding: 10px 14px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 13px;
                min-height: 44px;
                color: #334155;
            }
            td:last-child { border-bottom: none; }
            td::before {
                content: attr(data-label);
                font-weight: 700;
                color: #64748b;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                flex-shrink: 0;
                min-width: 90px;
            }
            td.actions-td {
                flex-direction: column;
                align-items: flex-start;
            }
            td.actions-td::before { margin-bottom: 6px; }
            .action-buttons { flex-direction: row; gap: 8px; width: 100%; }
            .btn-edit, .btn-delete { flex: 1; justify-content: center; min-height: 38px; }
        }

        /* ── Desktop & Tablet Overrides (Screens >= 769px) ── */
        @media (min-width: 769px) {
            body { padding: 24px; }

            .back-btn {
                width: auto;
                padding: 12px 20px;
                font-size: 14px;
                margin-bottom: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px;
                margin-bottom: 25px;
            }
            .stat-card { padding: 20px; }
            .stat-info h2 { font-size: 32px; }
            .stat-icon { width: 52px; height: 52px; min-width: 52px; font-size: 24px; border-radius: 12px; }

            .form-card, .edit-card { padding: 24px; margin-bottom: 25px; }
            .form-title, .edit-title { font-size: 20px; }

            .branch-form {
                flex-direction: row;
                align-items: center;
            }
            .branch-form input {
                flex: 1;
            }
            .branch-form button, .cancel-btn {
                width: auto;
            }

            table th { padding: 14px 18px; font-size: 13px; }
            table td { padding: 14px 18px; font-size: 14px; }
        }
    </style>
</head>
<body>

<div class="container">

    <a href="super_admin.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        Back to Dashboard
    </a>

    <?php
    // Get fresh stats after any operation
    $total_branch_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM places");
    $total_branch = mysqli_fetch_assoc($total_branch_query);
    
    $max_id_query = mysqli_query($conn, "SELECT MAX(id) as max_id FROM places");
    $max_id_row = mysqli_fetch_assoc($max_id_query);
    $highest_id = $max_id_row['max_id'] ?? 0;
    
    // Calculate next ID
    if ($total_branch['total'] == 0) {
        $next_id = 1;
    } else {
        $next_id = $highest_id + 1;
    }

    $total_users_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM users");
    $total_users = mysqli_fetch_assoc($total_users_query);
    ?>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-info">
                <h2><?= $total_branch['total']; ?></h2>
                <p>Total Branches</p>
                <small>Next ID: <?= $next_id; ?></small>
            </div>
            <div class="stat-icon">
                <i class="fas fa-code-branch"></i>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-info">
                <h2><?= $total_users['total']; ?></h2>
                <p>System Users</p>
            </div>
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>

    <!-- Message Display -->
    <?php if(isset($msg)): ?>
        <div class="alert alert-<?= $msg_type; ?>">
            <i class="fas <?= $msg_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
            <span><?= htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Edit Branch Section -->
    <?php if (isset($_GET['edit'])): 
        $id = intval($_GET['edit']);
        $edit_stmt = mysqli_prepare($conn, "SELECT * FROM places WHERE id = ?");
        mysqli_stmt_bind_param($edit_stmt, 'i', $id);
        mysqli_stmt_execute($edit_stmt);
        $edit_data = mysqli_fetch_assoc(mysqli_stmt_get_result($edit_stmt));
        mysqli_stmt_close($edit_stmt);
        if($edit_data):
    ?>
        <div class="edit-card">
            <div class="edit-title">
                <i class="fas fa-edit"></i>
                Edit Branch
            </div>
            <form method="POST" class="branch-form">
                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                <input type="hidden" name="place_id" value="<?= $edit_data['id']; ?>">
                <input type="text" name="place_name" value="<?= htmlspecialchars($edit_data['place_name']); ?>" required placeholder="Branch Name">
                <button type="submit" name="update_branch">
                    <i class="fas fa-save"></i>
                    Update Branch
                </button>
                <a href="branch_controller.php" class="cancel-btn">
                    <i class="fas fa-times"></i>
                    Cancel
                </a>
            </form>
        </div>
    <?php endif; endif; ?>

    <!-- Add Branch Section -->
    <div class="form-card">
        <div class="form-title">
            <i class="fas fa-plus-circle"></i>
            Add New Branch
        </div>
        <form method="POST" class="branch-form">
            <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
            <input type="text" name="place_name" placeholder="Enter branch name (e.g., North Branch, Addis Ababa)" required>
            <button type="submit" name="add_branch">
                <i class="fas fa-plus"></i>
                Add Branch
            </button>
        </form>
        <div style="margin-top: 12px; padding: 8px 12px; background: #f1f5f9; border-radius: 10px; font-size: 12px; color: #475569;">
            <i class="fas fa-info-circle"></i> 
            <?php if($total_branch['total'] == 0): ?>
                The first branch will get ID #1.
            <?php else: ?>
                Next branch will get ID #<?= $next_id; ?>.
            <?php endif; ?>
            IDs start from 1 and increase sequentially.
        </div>
    </div>

    <!-- Branches List Table -->
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Branch Name</th>
                    <th>Users</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $query = mysqli_query($conn, "SELECT * FROM places ORDER BY id ASC");
                
                if(mysqli_num_rows($query) > 0):
                    while($row = mysqli_fetch_assoc($query)):
                        $branch_id = $row['id'];
                        $users_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE branch_id='$branch_id'");
                        $user_total = mysqli_fetch_assoc($users_query);
                ?>
                    <tr>
                        <td data-label="ID"><span class="branch-id">#<?= $row['id']; ?></span></td>
                        <td data-label="Branch Name" class="branch-name"><?= htmlspecialchars($row['place_name']); ?></td>
                        <td data-label="Users">
                            <?php if($user_total['total'] > 0): ?>
                                <span class="user-badge">
                                    <i class="fas fa-user"></i> <?= $user_total['total']; ?> user(s)
                                </span>
                            <?php else: ?>
                                <span class="empty-badge">
                                    <i class="fas fa-user-slash"></i> No users
                                </span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions" class="actions-td">
                            <div class="action-buttons">
                                <a class="btn-edit" href="branch_controller.php?edit=<?= $row['id']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <form method="POST" style="display:inline" onsubmit="return confirmDelete('<?= htmlspecialchars($row['place_name'], ENT_QUOTES); ?>', <?= $user_total['total']; ?>)">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                                    <input type="hidden" name="branch_id" value="<?= (int)$row['id']; ?>">
                                    <button type="submit" name="delete_branch" class="btn-delete">
                                    <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else: 
                ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <i class="fas fa-store-slash"></i>
                                <p>No branches found. Click "Add Branch" to create your first branch.</p>
                                <p style="margin-top: 10px; font-size: 12px;">The first branch will get ID #1</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Info Footer -->
    <div class="info-footer">
        <i class="fas fa-info-circle"></i> 
        Branch IDs are automatically assigned starting from 1. Branches with assigned users cannot be deleted.
    </div>
</div>

<script>
    function confirmDelete(branchName, userCount) {
        if (userCount > 0) {
            alert(`⚠️ Cannot delete branch "${branchName}"!\n\nThis branch has ${userCount} user(s) assigned.\nPlease reassign or delete the users first.`);
            return false;
        }
        return confirm(`Are you sure you want to delete branch "${branchName}"?\n\nThis action cannot be undone.`);
    }
</script>

</body>
</html>
