<?php
/**
 * manage_users.php - Premium Mobile-First User Management for Aleltu POS
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
date_default_timezone_set('Africa/Addis_Ababa');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user_id   = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';
$current_username  = $_SESSION['username'] ?? '';

// Load read-only flag for this user
loadReadOnlyFlag($conn, $current_user_id);

// Protected Owners (Cannot be deleted or modified)
$protected_user_ids = [1002, 1016];

// Branch Detection
if ($current_user_role === 'super_admin') {
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
        mysqli_stmt_close($branch_name_stmt);
    } else {
        $current_branch_id = 0;
        $current_branch_name = 'ሁሉም ቅርንጫፎች';
    }
    mysqli_stmt_close($branch_stmt);
}

// Build Users Query
if ($current_user_role === 'super_admin') {
    $users_query = "SELECT u.*, p.place_name as branch_name 
                    FROM users u 
                    LEFT JOIN places p ON u.branch_id = p.id 
                    WHERE u.is_active = 1 AND u.id != 3 
                    ORDER BY u.branch_id, u.role, u.username";
    $users_result = mysqli_query($conn, $users_query);
} else {
    $users_query = "SELECT u.*, p.place_name as branch_name 
                    FROM users u 
                    LEFT JOIN places p ON u.branch_id = p.id 
                    WHERE u.is_active = 1 AND u.id != 3 AND u.branch_id = ? AND u.role != 'super_admin' 
                    ORDER BY u.role, u.username";
    $users_stmt = mysqli_prepare($conn, $users_query);
    mysqli_stmt_bind_param($users_stmt, 'i', $current_branch_id);
    mysqli_stmt_execute($users_stmt);
    $users_result = mysqli_stmt_get_result($users_stmt);
    mysqli_stmt_close($users_stmt);
}

if (!$users_result) {
    error_log('manage_users.php: ' . mysqli_error($conn));
    die('Unable to load users. Please try again.');
}

// Handle User Actions (POST + CSRF)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        header("Location: manage_users.php?msg=invalid_request");
        exit();
    }
    if (isReadOnly()) {
        header("Location: manage_users.php?msg=readonly");
        exit();
    }
    $action  = $_POST['action'];
    $user_id = intval($_POST['id']);

    if (in_array($user_id, $protected_user_ids) || $user_id == 3) {
        header("Location: manage_users.php?msg=protected");
        exit();
    }

    $chk = mysqli_prepare($conn, "SELECT id, role, branch_id FROM users WHERE id = ? AND is_active = 1");
    mysqli_stmt_bind_param($chk, 'i', $user_id);
    mysqli_stmt_execute($chk);
    $chk_result = mysqli_stmt_get_result($chk);
    mysqli_stmt_close($chk);

    if (mysqli_num_rows($chk_result) > 0) {
        $target_user = mysqli_fetch_assoc($chk_result);

        $has_permission = false;
        if ($current_user_role === 'super_admin') {
            if ($target_user['id'] != $current_user_id) $has_permission = true;
        } else {
            if ($target_user['branch_id'] == $current_branch_id &&
                $target_user['role'] === 'seller' &&
                $target_user['id'] != $current_user_id) $has_permission = true;
        }

        if (!$has_permission) {
            header("Location: manage_users.php?msg=no_permission");
            exit();
        }

        if ($action === 'delete') {
            $s = mysqli_prepare($conn, "UPDATE users SET is_active = 0 WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=deleted"); exit(); }
            mysqli_stmt_close($s);
        }

        if ($action === 'upgrade' && $current_user_role === 'super_admin' && $target_user['role'] === 'seller') {
            $s = mysqli_prepare($conn, "UPDATE users SET role = 'admin' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=upgraded"); exit(); }
            mysqli_stmt_close($s);
        }

        if ($action === 'downgrade' && $current_user_role === 'super_admin' && $target_user['role'] === 'admin') {
            $s = mysqli_prepare($conn, "UPDATE users SET role = 'seller' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'i', $user_id);
            if (mysqli_stmt_execute($s)) { mysqli_stmt_close($s); header("Location: manage_users.php?msg=downgraded"); exit(); }
            mysqli_stmt_close($s);
        }
    }

    header("Location: manage_users.php?msg=error");
    exit();
}

// Compute Statistics & Collect Users Array
$all_users = [];
$total_users = 0;
$total_admins = 0;
$total_sellers = 0;
$total_super_admins = 0;

if ($users_result) {
    while ($user = mysqli_fetch_assoc($users_result)) {
        $all_users[] = $user;
        $total_users++;
        if ($user['role'] === 'super_admin') $total_super_admins++;
        elseif ($user['role'] === 'admin') $total_admins++;
        elseif ($user['role'] === 'seller') $total_sellers++;
    }
}

$today_eth = getEthiopianDate();
$today_display = $today_eth['formatted'];
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4361ee">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>የተጠቃሚዎች አስተዳደር - <?php echo htmlspecialchars($current_branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3730a3;
            --primary-light: #e0e7ff;
            --success: #10b981;
            --success-light: #dcfce7;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --purple: #8b5cf6;
            --purple-light: #ede9fe;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --shadow-card: 0 2px 12px rgba(15, 23, 42, 0.06);
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --radius-full: 9999px;
            --touch-height: 44px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body, input, select, button, textarea {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        .fa, .fas, .far, .fal, .fad, .fab, [class*="fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "FontAwesome" !important;
            font-style: normal;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-dark);
            min-height: 100vh;
            padding: 12px;
            padding-bottom: 85px; /* space for mobile action bar */
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* ─── MOBILE-FIRST APP BAR ─── */
        .app-bar {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px 16px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .app-bar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .brand-text h1 {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .brand-text .sub {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }

        .app-bar-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: var(--touch-height);
            height: var(--touch-height);
            border-radius: var(--radius-md);
            background: var(--bg-page);
            border: 1px solid var(--border-light);
            color: var(--text-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
        }
        .btn-icon:hover { background: #e2e8f0; }

        /* ─── MOBILE STATS CAROUSEL / GRID ─── */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 12px 14px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-card);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .stat-card.total .stat-icon { background: var(--primary-light); color: var(--primary); }
        .stat-card.admin .stat-icon { background: var(--warning-light); color: var(--warning); }
        .stat-card.seller .stat-icon { background: var(--success-light); color: var(--success); }
        .stat-card.super .stat-icon { background: var(--purple-light); color: var(--purple); }

        .stat-info h4 {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-info .val {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.1;
        }

        /* ─── MOBILE SEARCH & FILTER BAR ─── */
        .search-filter-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 12px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            margin-bottom: 14px;
        }

        .search-input-wrap {
            position: relative;
            margin-bottom: 10px;
        }

        .search-input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .search-input {
            width: 100%;
            height: var(--touch-height);
            border-radius: var(--radius-md);
            border: 1.5px solid var(--border-light);
            background: var(--bg-page);
            padding: 0 14px 0 36px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }
        .search-input:focus {
            background: white;
            border-color: var(--primary);
        }

        .filter-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 2px;
        }
        .filter-scroll::-webkit-scrollbar { display: none; }

        .filter-chip {
            padding: 6px 12px;
            border-radius: var(--radius-full);
            background: var(--bg-page);
            border: 1px solid var(--border-light);
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .filter-chip.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* ─── ALERTS ─── */
        .alert {
            padding: 12px 14px;
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

        /* ─── MOBILE USER CARDS LIST ─── */
        .user-cards-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .user-touch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform 0.15s ease;
        }

        .user-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .user-info-group {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .avatar-circle {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            background: var(--primary-light);
            color: var(--primary);
            font-size: 17px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .user-names {
            min-width: 0;
        }

        .user-names .fullname {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-names .username {
            font-size: 12px;
            color: var(--text-muted);
        }

        .user-card-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Role Badges */
        .role-badge {
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .role-super_admin { background: var(--danger-light); color: #b91c1c; }
        .role-admin { background: var(--warning-light); color: #b45309; }
        .role-seller { background: var(--success-light); color: #15803d; }

        .branch-tag {
            background: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Mobile Action Buttons Grid */
        .user-card-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 6px;
        }

        .touch-btn {
            height: 38px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.15s;
            text-decoration: none;
        }

        .touch-btn-pwd { background: var(--purple-light); color: #6d28d9; }
        .touch-btn-pwd:active { background: #ddd6fe; }

        .touch-btn-up { background: var(--success-light); color: #15803d; }
        .touch-btn-up:active { background: #bbf7d0; }

        .touch-btn-down { background: var(--warning-light); color: #b45309; }
        .touch-btn-down:active { background: #fde68a; }

        .touch-btn-del { background: var(--danger-light); color: #b91c1c; }
        .touch-btn-del:active { background: #fecaca; }

        .protected-banner {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            color: #b45309;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* ─── STICKY MOBILE BOTTOM BAR ─── */
        .mobile-bottom-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 10px 16px;
            box-shadow: 0 -4px 16px rgba(0,0,0,0.08);
            border-top: 1px solid var(--border-light);
            display: flex;
            gap: 10px;
            z-index: 90;
        }

        .btn-floating {
            flex: 1;
            height: 46px;
            background: var(--success);
            color: white;
            border-radius: var(--radius-lg);
            font-size: 14.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-floating:active { transform: scale(0.98); }

        /* ─── DESKTOP TABLE VIEW (HIDDEN ON MOBILE) ─── */
        .desktop-table-view {
            display: none;
        }

        /* ─── MODAL (MOBILE-FIRST BOTTOM-SHEET / CENTER POPUP) ─── */
        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            align-items: flex-end; /* Bottom sheet on mobile */
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }

        .modal-sheet {
            background: white;
            width: 100%;
            max-width: 440px;
            border-radius: 20px 20px 0 0;
            padding: 24px 20px 32px 20px;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.2);
            animation: slideUpMobile 0.25s ease-out;
            position: relative;
        }

        @keyframes slideUpMobile {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .modal-drag-handle {
            width: 40px;
            height: 4px;
            background: #cbd5e1;
            border-radius: 2px;
            margin: 0 auto 16px auto;
        }

        .modal-close-btn {
            position: absolute;
            right: 16px;
            top: 16px;
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: var(--radius-full);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .pin-box {
            background: #f8fafc;
            border: 2px dashed #3b82f6;
            border-radius: var(--radius-lg);
            padding: 16px;
            text-align: center;
            margin: 14px 0;
        }

        .pin-number {
            font-size: 34px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: 8px;
            font-family: monospace;
        }

        .btn-copy {
            width: 100%;
            height: 46px;
            border-radius: var(--radius-md);
            background: var(--primary);
            color: white;
            font-size: 15px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-copy.copied { background: var(--success); }

        /* ─── DESKTOP ENHANCEMENTS (>= 768px) ─── */
        @media (min-width: 768px) {
            body {
                padding: 24px;
                padding-bottom: 24px;
            }

            .app-bar {
                padding: 18px 24px;
                margin-bottom: 20px;
            }
            .brand-text h1 { font-size: 20px; }

            .stats-container {
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                margin-bottom: 20px;
            }
            .stat-card { padding: 18px 20px; }
            .stat-icon { width: 44px; height: 44px; font-size: 19px; }
            .stat-info .val { font-size: 24px; }

            .search-filter-card {
                padding: 16px 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 20px;
            }
            .search-input-wrap { margin-bottom: 0; flex: 1; max-width: 380px; }

            /* Switch from Mobile Cards to Desktop Table */
            .user-cards-list { display: none; }
            .desktop-table-view {
                display: block;
                background: white;
                border-radius: var(--radius-lg);
                border: 1px solid var(--border-light);
                box-shadow: var(--shadow-card);
                overflow: hidden;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13.5px;
            }

            thead th {
                background: #f8fafc;
                color: var(--text-muted);
                font-weight: 700;
                padding: 14px 18px;
                text-align: left;
                border-bottom: 2px solid var(--border-light);
                font-size: 12.5px;
                text-transform: uppercase;
            }

            tbody td {
                padding: 14px 18px;
                border-bottom: 1px solid var(--border-light);
                vertical-align: middle;
            }

            tbody tr:hover { background-color: #f8fafc; }

            .mobile-bottom-bar { display: none; }

            /* Desktop Modal centered */
            .modal-overlay { align-items: center; }
            .modal-sheet { border-radius: var(--radius-xl); }
            .modal-drag-handle { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- ─── APP BAR ─── -->
        <header class="app-bar">
            <div class="app-bar-brand">
                <div class="brand-icon"><i class="fas fa-users-gear"></i></div>
                <div class="brand-text">
                    <h1>የተጠቃሚዎች አስተዳደር</h1>
                    <div class="sub">
                        <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?></span>
                        <span>•</span>
                        <span><?php echo $today_display; ?></span>
                    </div>
                </div>
            </div>

            <div class="app-bar-actions">
                <a href="<?php echo ($current_user_role === 'super_admin') ? 'super_admin.php' : 'admin_dashboard.php'; ?>" class="btn-icon" title="ተመለስ">
                    <i class="fas fa-arrow-left"></i>
                </a>
            </div>
        </header>

        <!-- ─── NOTIFICATION ALERTS ─── -->
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'deleted'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው በተሳካ ሁኔታ ተሰርዟል!</div>
            <?php elseif ($_GET['msg'] === 'upgraded'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው ወደ አስተዳዳሪነት ከፍ ብሏል!</div>
            <?php elseif ($_GET['msg'] === 'downgraded'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> ተጠቃሚው ወደ ሻጭነት ዝቅ ብሏል!</div>
            <?php elseif ($_GET['msg'] === 'protected'): ?>
                <div class="alert alert-warning"><i class="fas fa-shield-alt"></i> ይህ ዋና ተጠቃሚ ነው! መሰረዝ ወይም መቀየር አይቻልም።</div>
            <?php elseif ($_GET['msg'] === 'no_permission'): ?>
                <div class="alert alert-error"><i class="fas fa-ban"></i> ይህን እርምጃ ለመውሰድ ፈቃድ የለዎትም!</div>
            <?php elseif ($_GET['msg'] === 'readonly'): ?>
                <div class="alert alert-warning"><i class="fas fa-lock"></i> 🔒 Read Only Permission: እርምጃ መውሰድ አይቻልም።</div>
            <?php elseif ($_GET['msg'] === 'invalid_request'): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> ልክ ያልሆነ ጥያቄ።</div>
            <?php else: ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> ስህተት ተከስቷል!</div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ─── STATS SUMMARY ─── -->
        <div class="stats-container">
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h4>ጠቅላላ</h4>
                    <div class="val"><?php echo $total_users; ?></div>
                </div>
            </div>

            <?php if ($total_super_admins > 0): ?>
            <div class="stat-card super">
                <div class="stat-icon"><i class="fas fa-crown"></i></div>
                <div class="stat-info">
                    <h4>ዋና Admin</h4>
                    <div class="val"><?php echo $total_super_admins; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="stat-card admin">
                <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                <div class="stat-info">
                    <h4>አስተዳዳሪ</h4>
                    <div class="val"><?php echo $total_admins; ?></div>
                </div>
            </div>

            <div class="stat-card seller">
                <div class="stat-icon"><i class="fas fa-cash-register"></i></div>
                <div class="stat-info">
                    <h4>ሻጮች</h4>
                    <div class="val"><?php echo $total_sellers; ?></div>
                </div>
            </div>
        </div>

        <!-- ─── SEARCH & FILTER TOOLBAR ─── -->
        <div class="search-filter-card">
            <div class="search-input-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="በስም ወይም በዩዘርኔም ፈልግ..." oninput="handleSearch()">
            </div>

            <div class="filter-scroll">
                <button class="filter-chip active" onclick="filterByRole('all', this)">
                    ሁሉም (<?php echo $total_users; ?>)
                </button>
                <?php if ($total_super_admins > 0): ?>
                <button class="filter-chip" onclick="filterByRole('super_admin', this)">
                    ዋና Admin (<?php echo $total_super_admins; ?>)
                </button>
                <?php endif; ?>
                <button class="filter-chip" onclick="filterByRole('admin', this)">
                    አስተዳዳሪ (<?php echo $total_admins; ?>)
                </button>
                <button class="filter-chip" onclick="filterByRole('seller', this)">
                    ሻጭ (<?php echo $total_sellers; ?>)
                </button>
            </div>
        </div>

        <!-- ─── 1. MOBILE TOUCH CARDS LIST (MOBILE VIEW) ─── -->
        <div class="user-cards-list" id="mobileCardsContainer">
            <?php if (empty($all_users)): ?>
                <div style="text-align: center; padding: 40px 10px; color: var(--text-muted);">
                    <i class="fas fa-users-slash" style="font-size: 36px; opacity: 0.4; margin-bottom: 8px;"></i>
                    <p>ምንም data አልተገኘም</p>
                </div>
            <?php else: ?>
                <?php foreach ($all_users as $user): 
                    $initial = mb_substr($user['full_name'] ?: $user['username'], 0, 1, 'UTF-8');
                    $is_protected = in_array($user['id'], $protected_user_ids) || $user['id'] == 3;
                    $search_meta = strtolower($user['full_name'] . ' ' . $user['username'] . ' ' . ($user['branch_name'] ?? ''));
                ?>
                <div class="user-touch-card user-item-card" data-role="<?php echo htmlspecialchars($user['role']); ?>" data-search="<?php echo htmlspecialchars($search_meta); ?>">
                    <div class="user-card-top">
                        <div class="user-info-group">
                            <div class="avatar-circle"><?php echo strtoupper($initial); ?></div>
                            <div class="user-names">
                                <div class="fullname"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></div>
                                <div class="username">@<?php echo htmlspecialchars($user['username']); ?></div>
                            </div>
                        </div>

                        <span class="role-badge role-<?php echo htmlspecialchars($user['role']); ?>">
                            <?php 
                                if ($user['role'] === 'super_admin') echo 'ዋና Admin';
                                elseif ($user['role'] === 'admin') echo 'አስተዳዳሪ';
                                else echo 'ሻጭ';
                            ?>
                        </span>
                    </div>

                    <div class="user-card-meta">
                        <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($user['branch_name'] ?? 'ዋና መስሪያ ቤት'); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                    </div>

                    <?php if (!$is_protected && !isReadOnly()): ?>
                        <div class="user-card-actions">
                            <?php if ($current_user_role === 'super_admin'): ?>
                                <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['full_name'] ?: $user['username'], ENT_QUOTES); ?>')" class="touch-btn touch-btn-pwd">
                                    <i class="fas fa-key"></i> የይለፍ ቃል
                                </button>
                                <?php if ($user['role'] === 'seller'): ?>
                                    <button onclick="upgradeUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-up">
                                        <i class="fas fa-arrow-up"></i> ከፍ አድርግ
                                    </button>
                                <?php elseif ($user['role'] === 'admin'): ?>
                                    <button onclick="downgradeUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-down">
                                        <i class="fas fa-arrow-down"></i> ዝቅ አድርግ
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($user['id'] != $current_user_id): ?>
                                <button onclick="deleteUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-del">
                                    <i class="fas fa-trash"></i> ሰርዝ
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($is_protected): ?>
                        <div class="protected-banner">
                            <i class="fas fa-shield-halved"></i> ዋና ተጠቃሚ (መሰረዝ አይቻልም)
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ─── 2. DESKTOP TABLE VIEW (TABLET & DESKTOP) ─── -->
        <div class="desktop-table-view">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ተጠቃሚ</th>
                        <th>ሚና</th>
                        <?php if ($current_user_role === 'super_admin'): ?>
                            <th>ቅርንጫፍ</th>
                        <?php endif; ?>
                        <th>የተመዘገበበት ቀን</th>
                        <th style="text-align: center;">እርምጃዎች</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($all_users as $user): 
                        $initial = mb_substr($user['full_name'] ?: $user['username'], 0, 1, 'UTF-8');
                        $is_protected = in_array($user['id'], $protected_user_ids) || $user['id'] == 3;
                        $search_meta = strtolower($user['full_name'] . ' ' . $user['username'] . ' ' . ($user['branch_name'] ?? ''));
                    ?>
                    <tr class="user-item-row" data-role="<?php echo htmlspecialchars($user['role']); ?>" data-search="<?php echo htmlspecialchars($search_meta); ?>">
                        <td><?php echo $idx++; ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="avatar-circle" style="width: 36px; height: 36px; font-size: 14px;"><?php echo strtoupper($initial); ?></div>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-dark);"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">@<?php echo htmlspecialchars($user['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="role-badge role-<?php echo htmlspecialchars($user['role']); ?>">
                                <?php 
                                    if ($user['role'] === 'super_admin') echo 'ዋና Admin';
                                    elseif ($user['role'] === 'admin') echo 'አስተዳዳሪ';
                                    else echo 'ሻጭ';
                                ?>
                            </span>
                        </td>
                        <?php if ($current_user_role === 'super_admin'): ?>
                            <td>
                                <span class="branch-tag">
                                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($user['branch_name'] ?? 'ዋና መስሪያ ቤት'); ?>
                                </span>
                            </td>
                        <?php endif; ?>
                        <td style="color: var(--text-muted); font-size: 13px;">
                            <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                                <?php if ($current_user_role === 'super_admin'): ?>
                                    <button onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['full_name'] ?: $user['username'], ENT_QUOTES); ?>')" class="touch-btn touch-btn-pwd" style="padding: 0 10px;">
                                        <i class="fas fa-key"></i> የይለፍ ቃል
                                    </button>
                                <?php endif; ?>

                                <?php if (!$is_protected && !isReadOnly()): ?>
                                    <?php if ($current_user_role === 'super_admin'): ?>
                                        <?php if ($user['role'] === 'seller'): ?>
                                            <button onclick="upgradeUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-up" style="padding: 0 10px;">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                        <?php elseif ($user['role'] === 'admin'): ?>
                                            <button onclick="downgradeUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-down" style="padding: 0 10px;">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($user['id'] != $current_user_id): ?>
                                        <button onclick="deleteUser(<?php echo $user['id']; ?>)" class="touch-btn touch-btn-del" style="padding: 0 10px;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php elseif ($is_protected): ?>
                                    <span style="font-size: 12px; color: var(--warning); font-weight: 700;">
                                        <i class="fas fa-shield-alt"></i> ዋና
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ─── MOBILE STICKY BOTTOM ACTION BAR ─── -->
    <?php if (!isReadOnly()): ?>
    <div class="mobile-bottom-bar">
        <a href="register_user.php" class="btn-floating">
            <i class="fas fa-user-plus"></i> አዲስ ተጠቃሚ መዝግብ
        </a>
    </div>
    <?php endif; ?>

    <!-- ─── MOBILE-FIRST 6-DIGIT PIN MODAL / BOTTOM SHEET ─── -->
    <div id="pinModal" class="modal-overlay">
        <div class="modal-sheet">
            <div class="modal-drag-handle"></div>
            <button class="modal-close-btn" onclick="closePinModal()">&times;</button>
            
            <h3 style="font-size: 17px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-key" style="color: var(--primary);"></i> አዲስ ባለ 6-አሃዝ የይለፍ ቃል
            </h3>

            <div style="font-size: 13px; color: var(--text-muted); margin-top: 8px;" id="pinModalUser">
                ተጠቃሚ:
            </div>
            <div style="font-size: 16px; font-weight: 700; color: var(--text-dark);" id="pinModalFullName">
                -
            </div>

            <div class="pin-box">
                <div class="pin-number" id="pinDisplay">******</div>
            </div>

            <button class="btn-copy" id="copyBtn" onclick="copyPinToClipboard()">
                <i class="fas fa-copy"></i> Copy
            </button>

            <p style="text-align: center; margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                <i class="fas fa-shield-alt" style="color: var(--success);"></i> ይህንን ባለ 6-አሃዝ የይለፍ ቃል ለተጠቃሚው ይስጡት።
            </p>
        </div>
    </div>

    <script>
        const _csrf = '<?php echo getCsrfToken(); ?>';
        let currentPin = '';
        let selectedRole = 'all';

        // Fast Live Search
        function handleSearch() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.user-item-card');
            const rows = document.querySelectorAll('.user-item-row');

            const filterItem = (el) => {
                const role = el.getAttribute('data-role');
                const search = el.getAttribute('data-search');
                const matchRole = (selectedRole === 'all' || role === selectedRole);
                const matchQuery = (query === '' || search.includes(query));
                el.style.display = (matchRole && matchQuery) ? '' : 'none';
            };

            cards.forEach(filterItem);
            rows.forEach(filterItem);
        }

        function filterByRole(role, btn) {
            selectedRole = role;
            document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            handleSearch();
        }

        function submitAction(action, id) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'manage_users.php';
            [['action', action], ['id', id], ['csrf_token', _csrf]].forEach(([k, v]) => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = k;
                i.value = v;
                f.appendChild(i);
            });
            document.body.appendChild(f);
            f.submit();
        }

        function deleteUser(id) {
            if (confirm('እርግጠኛ ነዎት ይህን ተጠቃሚ መሰረዝ ይፈልጋሉ?\n\nይህ እርምጃ cannot be undone!')) {
                submitAction('delete', id);
            }
        }

        function upgradeUser(id) {
            if (confirm('ይህን ተጠቃሚ ወደ አስተዳዳሪነት ከፍ ማድረግ ይፈልጋሉ?')) {
                submitAction('upgrade', id);
            }
        }

        function downgradeUser(id) {
            if (confirm('ይህን ተጠቃሚ ወደ ሻጭነት ዝቅ ማድረግ ይፈልጋሉ?')) {
                submitAction('downgrade', id);
            }
        }

        function resetPassword(id, username, fullName) {
            if (!confirm('የ "' + fullName + '" የይለፍ ቃል መቀየር ይፈልጋሉ? አዲስ ባለ 6-አሃዝ የይለፍ ቃል ይሰጠዋል።')) {
                return;
            }
            document.getElementById('pinModalUser').innerHTML = '<i class="fas fa-user"></i> ተጠቃሚ: @' + username;
            document.getElementById('pinModalFullName').innerText = fullName;
            document.getElementById('pinDisplay').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            document.getElementById('copyBtn').disabled = true;
            document.getElementById('pinModal').classList.add('show');

            fetch('get_user_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ id: id, csrf_token: _csrf })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    currentPin = data.password;
                    document.getElementById('pinDisplay').innerText = currentPin;
                    document.getElementById('copyBtn').disabled = false;
                } else {
                    document.getElementById('pinDisplay').innerText = 'ስህተት!';
                    document.getElementById('pinDisplay').style.color = 'var(--danger)';
                }
            })
            .catch(() => {
                document.getElementById('pinDisplay').innerText = 'ስህተት!';
                document.getElementById('pinDisplay').style.color = 'var(--danger)';
            });
        }

        function closePinModal() {
            document.getElementById('pinModal').classList.remove('show');
            currentPin = '';
            const btn = document.getElementById('copyBtn');
            btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            btn.className = 'btn-copy';
        }

        function copyPinToClipboard() {
            if (!currentPin) return;
            navigator.clipboard.writeText(currentPin).then(() => {
                const btn = document.getElementById('copyBtn');
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        window.onclick = function(e) {
            const modal = document.getElementById('pinModal');
            if (e.target === modal) closePinModal();
        };

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePinModal();
        });
    </script>
</body>
</html>
<?php 
if ($users_result) mysqli_free_result($users_result);
mysqli_close($conn); 
?>
