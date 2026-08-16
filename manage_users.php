<?php
// manage_users.php - Manage Users (Admin Only)
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';
$current_username = $_SESSION['username'] ?? '';

// Load read-only flag for this user
loadReadOnlyFlag($conn, $current_user_id);

// ========== PROTECTED USERS (OWNERS - CANNOT BE DELETED) ==========
$protected_user_ids = [1002, 1016]; // Teklu (1002) and Biniyam (1007)

// ========== PROPER BRANCH DETECTION ==========
if ($current_user_role == 'super_admin') {
    $current_branch_id = 0;
    $current_branch_name = 'ሁሉም ቅርንጫፎች';
} else {
    $branch_stmt = mysqli_prepare($conn, "SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($branch_stmt, 'i', $current_user_id);
    mysqli_stmt_execute($branch_stmt);
    $branch_result = mysqli_stmt_get_result($branch_stmt);
    
    if ($branch_result && mysqli_num_rows($branch_result) > 0) {
        $branch_data = mysqli_fetch_assoc($branch_result);
        $current_branch_id = $branch_data['branch_id'];
        
        $branch_name_stmt = mysqli_prepare($conn, "SELECT place_name FROM places WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($branch_name_stmt, 'i', $current_branch_id);
        mysqli_stmt_execute($branch_name_stmt);
        $branch_name_result = mysqli_stmt_get_result($branch_name_stmt);
        if ($branch_name_result && mysqli_num_rows($branch_name_result) > 0) {
            $branch_name_data = mysqli_fetch_assoc($branch_name_result);
            $current_branch_name = $branch_name_data['place_name'];
        } else {
            $current_branch_name = 'ቅርንጫፍ ' . $current_branch_id;
        }
    } else {
        $current_branch_id = 0;
        $current_branch_name = 'ሁሉም ቅርንጫፎች';
    }
}

// Build users query (using places table, NOT locations)
if ($current_user_role == 'super_admin') {
    $users_query = "SELECT u.*, p.place_name as branch_name 
                    FROM users u 
                    LEFT JOIN places p ON u.branch_id = p.id
                    WHERE u.is_active = 1 
                    AND u.id != 3
                    ORDER BY u.branch_id, u.role, u.username";
} else {
    $users_query = "SELECT u.*, p.place_name as branch_name 
                    FROM users u 
                    LEFT JOIN places p ON u.branch_id = p.id
                    WHERE u.is_active = 1 
                    AND u.id != 3
                    AND u.branch_id = ?
                    AND u.role != 'super_admin'
                    ORDER BY u.role, u.username";
}

if ($current_user_role == 'super_admin') {
    $users_result = mysqli_query($conn, $users_query);
} else {
    $users_stmt = mysqli_prepare($conn, $users_query);
    mysqli_stmt_bind_param($users_stmt, 'i', $current_branch_id);
    mysqli_stmt_execute($users_stmt);
    $users_result = mysqli_stmt_get_result($users_stmt);
    mysqli_stmt_close($users_stmt);
}

if(!$users_result) {
    error_log('manage_users.php: ' . mysqli_error($conn));
    die('Unable to load users. Please try again.');
}

// Handle user actions — POST + CSRF required (blocks CSRF via crafted links)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    // Validate CSRF token
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        header("Location: manage_users.php?msg=invalid_request");
        exit();
    }
    // Read-only users cannot perform any write actions
    if (isReadOnly()) {
        header("Location: manage_users.php?msg=readonly");
        exit();
    }
    $action  = $_POST['action'];
    $user_id = intval($_POST['id']);

    // Prevent actions on protected users
    if (in_array($user_id, $protected_user_ids)) {
        header("Location: manage_users.php?msg=protected");
        exit();
    }
    // Prevent actions on id=3 (tesfa account)
    if ($user_id == 3) {
        header("Location: manage_users.php?msg=no_permission");
        exit();
    }

    // Check target user exists — prepared statement
    $chk = mysqli_prepare($conn, "SELECT id, role, branch_id FROM users WHERE id = ? AND is_active = 1");
    mysqli_stmt_bind_param($chk, 'i', $user_id);
    mysqli_stmt_execute($chk);
    $chk_result = mysqli_stmt_get_result($chk);
    mysqli_stmt_close($chk);

    if (mysqli_num_rows($chk_result) > 0) {
        $target_user = mysqli_fetch_assoc($chk_result);

        $has_permission = false;
        if ($current_user_role == 'super_admin') {
            if ($target_user['id'] != $current_user_id) $has_permission = true;
        } else {
            if ($target_user['branch_id'] == $current_branch_id &&
                $target_user['role'] == 'seller' &&
                $target_user['id'] != $current_user_id) $has_permission = true;
        }

        if (!$has_permission) {
            header("Location: manage_users.php?msg=no_permission");
            exit();
        }

        if ($action == 'delete') {
            $s = mysqli_prepare($conn, "UPDATE users SET is_active = 0 WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=deleted"); exit(); }
            mysqli_stmt_close($s);
        }

        if ($action == 'upgrade' && $current_user_role == 'super_admin' && $target_user['role'] == 'seller') {
            $s = mysqli_prepare($conn, "UPDATE users SET role = 'admin' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=upgraded"); exit(); }
            mysqli_stmt_close($s);
        }

        if ($action == 'downgrade' && $current_user_role == 'super_admin' && $target_user['role'] == 'admin') {
            $s = mysqli_prepare($conn, "UPDATE users SET role = 'seller' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=downgraded"); exit(); }
            mysqli_stmt_close($s);
        }
    }

    header("Location: manage_users.php?msg=error");
    exit();
}

// Get statistics
$total_users = mysqli_num_rows($users_result);
$total_admins = 0;
$total_sellers = 0;

if($users_result) {
    mysqli_data_seek($users_result, 0);
    while($user = mysqli_fetch_assoc($users_result)) {
        if($user['role'] == 'admin') $total_admins++;
        if($user['role'] == 'seller') $total_sellers++;
    }
    mysqli_data_seek($users_result, 0);
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>Manage Users - <?php echo htmlspecialchars($current_branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', 'Nyala', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f2f5;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            color: #2c3e50;
            font-size: 24px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: #3498db;
        }
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #95a5a6;
        }
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            border-left: 4px solid #3498db;
        }
        .stat-card.admin { border-left-color: #e67e22; }
        .stat-card.seller { border-left-color: #2ecc71; }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .role-super_admin {
            background: #e74c3c;
            color: white;
        }
        .role-admin {
            background: #e67e22;
            color: white;
        }
        .role-seller {
            background: #2ecc71;
            color: white;
        }
        .action-btns {
            display: flex;
            gap: 5px;
        }
        .action-btn {
            padding: 6px 10px;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .action-btn:hover {
            opacity: 0.9;
        }
        .btn-edit { background: #3498db; }
        .btn-upgrade { background: #27ae60; }
        .btn-downgrade { background: #f39c12; }
        .btn-delete { background: #e74c3c; }
        .btn-password { background: #9b59b6; }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            animation: slideIn 0.3s;
            position: relative;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .close-btn {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }
        .close-btn:hover {
            color: #2c3e50;
        }
        .password-display {
            background: #f8f9fa;
            border: 2px dashed #3498db;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-size: 24px;
            font-family: monospace;
            letter-spacing: 2px;
            margin: 20px 0;
            color: #2c3e50;
            word-break: break-all;
        }
        .copy-btn {
            width: 100%;
            padding: 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.3s;
        }
        .copy-btn:hover {
            background: #2980b9;
        }
        .copy-btn.copied {
            background: #27ae60;
        }
        .user-info {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        .user-name {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
        }
        
        .add-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2ecc71;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        .add-btn:hover {
            background: #27ae60;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-users-cog"></i> የተጠቃሚዎች አስተዳደር</h1>
                <p style="color: #7f8c8d; margin-top: 5px;">
                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?>
                </p>
            </div>
            <div class="header-actions">
                <?php if ($current_user_role == 'super_admin'): ?>
                    <a href="super_admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> ተመለስ</a>
                <?php else: ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> ተመለስ</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if(isset($_GET['msg'])): ?>
            <?php if($_GET['msg'] == 'deleted'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው በተሳካ ሁኔታ ተሰርዟል!</div>
            <?php elseif($_GET['msg'] == 'upgraded'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው ወደ አስተዳዳሪነት ከፍ ብሏል!</div>
            <?php elseif($_GET['msg'] == 'downgraded'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው ወደ ሻጭነት ዝቅ ብሏል!</div>
            <?php elseif($_GET['msg'] == 'protected'): ?>
                <div class="alert alert-warning"><i class="fas fa-shield-alt"></i> ይህ ዋና ተጠቃሚ ነው! መሰረዝ ወይም መቀየር አይቻልም።</div>
            <?php elseif($_GET['msg'] == 'no_permission'): ?>
                <div class="alert alert-error"><i class="fas fa-ban"></i> ይህን እርምጃ ለመውሰድ ፈቃድ የለዎትም!</div>
            <?php elseif($_GET['msg'] == 'readonly'): ?>
                <div class="alert alert-warning"><i class="fas fa-lock"></i> እርስዎ ማንበብ ብቻ ፈቃድ አላቸው። እርምጃ መውሰድ አይቻልም።</div>
            <?php elseif($_GET['msg'] == 'invalid_request'): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ልክ ያልሆነ ጥያቄ። እባክዎ እንደገና ይሞክሩ።</div>
            <?php else: ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ስህተት ተከስቷል! እባክዎ እንደገና ይሞክሩ።</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">ጠቅላላ ተጠቃሚዎች</div>
            </div>
            <div class="stat-card admin">
                <div class="stat-number"><?php echo $total_admins; ?></div>
                <div class="stat-label">አስተዳዳሪዎች</div>
            </div>
            <div class="stat-card seller">
                <div class="stat-number"><?php echo $total_sellers; ?></div>
                <div class="stat-label">ሻጮች</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>ሙሉ ስም</th>
                    <th>የተጠቃሚ ስም</th>
                    <th>ሚና</th>
                    <?php if ($current_user_role == 'super_admin'): ?>
                        <th>ቅርንጫፍ</th>
                    <?php endif; ?>
                    <th>የተመዘገበበት ቀን</th>
                    <th>እርምጃዎች</th>
                </tr>
            </thead>
            <tbody>
                <?php if($users_result && mysqli_num_rows($users_result) > 0): ?>
                    <?php $i = 1; while($user = mysqli_fetch_assoc($users_result)): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                <?php 
                                    if($user['role'] == 'super_admin') echo 'ዋና አስተዳዳሪ';
                                    elseif($user['role'] == 'admin') echo 'አስተዳዳሪ';
                                    else echo 'ሻጭ';
                                ?>
                            </span>
                        </td>
                        <?php if ($current_user_role == 'super_admin'): ?>
                            <td><?php echo htmlspecialchars($user['branch_name'] ?? 'ዋና መስሪያ ቤት'); ?></td>
                        <?php endif; ?>
                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                        <td>
                            <div class="action-btns">
                                <?php if ($current_user_role == 'super_admin'): ?>
                                    <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['full_name'] ?? $user['username'], ENT_QUOTES); ?>')" class="action-btn btn-password" title="የይለፍ ቃል ቀይር">
                                        <i class="fas fa-key"></i> የይለፍ ቃል
                                    </button>
                                <?php endif; ?>
                                
                                <?php if(!in_array($user['id'], $protected_user_ids) && $user['id'] != 3 && !isReadOnly()): ?>
                                    <?php if($current_user_role == 'super_admin'): ?>
                                        <?php if($user['role'] == 'seller'): ?>
                                            <button onclick="upgradeUser(<?php echo $user['id']; ?>)" class="action-btn btn-upgrade" title="ወደ አስተዳዳሪነት ከፍ አድርግ">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                        <?php elseif($user['role'] == 'admin'): ?>
                                            <button onclick="downgradeUser(<?php echo $user['id']; ?>)" class="action-btn btn-downgrade" title="ወደ ሻጭነት ዝቅ አድርግ">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if($user['id'] != $current_user_id): ?>
                                        <button onclick="deleteUser(<?php echo $user['id']; ?>)" class="action-btn btn-delete" title="ሰርዝ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php elseif(in_array($user['id'], $protected_user_ids)): ?>
                                    <span style="color: #f39c12; font-size: 12px;" title="ዋና ተጠቃሚ (መሰረዝ አይቻልም)">
                                        <i class="fas fa-shield-alt"></i> ዋና
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $current_user_role == 'super_admin' ? '7' : '6'; ?>" style="text-align:center;padding:60px;color:#7f8c8d;">
                            <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <h3>ምንም ተጠቃሚ አልተገኘም</h3>
                            <p>አዲስ ተጠቃሚ ለመመዝገብ ከታች ያለውን ቁልፍ ይጫኑ</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if (!isReadOnly()): ?>
        <div style="text-align: center; margin-top: 30px;">
            <a href="register_user.php" class="add-btn">
                <i class="fas fa-user-plus"></i> አዲስ ተጠቃሚ መዝግብ
            </a>
        </div>
        <?php else: ?>
        <div style="text-align: center; margin-top: 30px; padding: 30px; background: #fef3cd; border-radius: 8px; border: 2px solid #ffc107;">
            <i class="fas fa-lock" style="font-size: 32px; color: #856404; margin-bottom: 15px; display: block;"></i>
            <h3 style="color: #856404; margin-bottom: 10px;">🔒 ማንበብ ብቻ ምክንያት</h3>
            <p style="color: #856404; margin: 0;">እርስዎ ማንበብ ብቻ ፈቃድ አላቸው። አዲስ ተጠቃሚ ማመዝገብ ወይም ተጠቃሚዎችን ማቀየር አይቻልም።</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Password Reset Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-key" style="color: #3498db;"></i> አዲስ የይለፍ ቃል
            </h2>
            <div class="user-info" id="modalUserInfo"><i class="fas fa-user"></i> ተጠቃሚ:</div>
            <div class="user-name" id="modalUserName"></div>
            <div class="password-display" id="modalPassword">********</div>
            <button class="copy-btn" id="copyPasswordBtn" onclick="copyPassword()">
                <i class="fas fa-copy"></i> ይለፍ ቃሉን ቅዳ
            </button>
            <p style="text-align: center; margin-top: 15px; color: #7f8c8d; font-size: 12px;">
                <i class="fas fa-shield-alt"></i> ይህን አዲስ የይለፍ ቃል ለተጠቃሚው ይንገሩት
            </p>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let currentPassword = '';
        const _csrf = '<?php echo getCsrfToken(); ?>';

        function submitAction(action, id) {
            const f = document.createElement('form');
            f.method = 'POST'; f.action = 'manage_users.php';
            [['action',action],['id',id],['csrf_token',_csrf]].forEach(([k,v])=>{
                const i=document.createElement('input');i.type='hidden';i.name=k;i.value=v;f.appendChild(i);
            });
            document.body.appendChild(f); f.submit();
        }

        function deleteUser(id) {
            if(confirm('እርግጠኛ ነዎት ይህን ተጠቃሚ መሰረዝ ይፈልጋሉ?\n\nይህ እርምጃ መቀልበስ አይቻልም!')) {
                submitAction('delete', id);
            }
        }

        function upgradeUser(id) {
            if(confirm('ይህን ተጠቃሚ ወደ አስተዳዳሪነት ከፍ ማድረግ ይፈልጋሉ?')) {
                submitAction('upgrade', id);
            }
        }

        function downgradeUser(id) {
            if(confirm('ይህን ተጠቃሚ ወደ ሻጭነት ዝቅ ማድረግ ይፈልጋሉ?')) {
                submitAction('downgrade', id);
            }
        }

        function resetPassword(id, username, fullName) {
            if (!confirm('የ "' + fullName + '" የይለፍ ቃል ወደ 123 ይቀየራል። ይቀጥሉ?')) {
                return;
            }
            currentUserId = id;
            document.getElementById('modalUserInfo').innerHTML = '<i class="fas fa-user"></i> ተጠቃሚ: ' + username;
            document.getElementById('modalUserName').innerHTML = fullName;
            document.getElementById('modalPassword').innerHTML = '<i class="fas fa-spinner fa-spin"></i> በማስጀመር ላይ...';
            document.getElementById('copyPasswordBtn').disabled = true;
            document.getElementById('passwordModal').style.display = 'block';
            
            fetch('get_user_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ id: id, csrf_token: _csrf })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentPassword = data.password;
                        document.getElementById('modalPassword').innerHTML = currentPassword;
                        document.getElementById('copyPasswordBtn').disabled = false;
                    } else {
                        document.getElementById('modalPassword').innerHTML = 'ማስጀመር አልተቻለም';
                        document.getElementById('modalPassword').style.color = '#e74c3c';
                    }
                })
                .catch(error => {
                    document.getElementById('modalPassword').innerHTML = 'ስህተት ተከስቷል';
                    document.getElementById('modalPassword').style.color = '#e74c3c';
                });
        }

        function closeModal() {
            document.getElementById('passwordModal').style.display = 'none';
            currentPassword = '';
            document.getElementById('copyPasswordBtn').disabled = false;
            document.getElementById('copyPasswordBtn').innerHTML = '<i class="fas fa-copy"></i> ይለፍ ቃሉን ቅዳ';
            document.getElementById('copyPasswordBtn').className = 'copy-btn';
        }

        function copyPassword() {
            if (!currentPassword) return;
            navigator.clipboard.writeText(currentPassword).then(() => {
                const btn = document.getElementById('copyPasswordBtn');
                btn.innerHTML = '<i class="fas fa-check"></i> ተቀድቷል!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i> ይለፍ ቃሉን ቅዳ';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                alert('ማስተካከል አልተቻለም');
            });
        }

        window.onclick = function(event) {
            const modal = document.getElementById('passwordModal');
            if (event.target == modal) closeModal();
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>
<?php 
if($users_result) mysqli_free_result($users_result);
mysqli_close($conn); 
?>
