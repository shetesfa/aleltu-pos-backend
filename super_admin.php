<?php
session_start();
require_once 'config.php';
set_time_limit(120); // prevent 504 timeout

if(!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'super_admin' && $_SESSION['role'] != 'admin')) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Helper functions (if not defined in config.php)
if (!function_exists('getAllBranches')) {
    function getAllBranches($conn) {
        $branches = [];
        $result = mysqli_query($conn, "SELECT id, place_name FROM places ORDER BY place_name");
        if ($result) {
            while($row = mysqli_fetch_assoc($result)) {
                $branches[] = $row;
            }
        }
        return $branches;
    }
}

if (!function_exists('getUserBranch')) {
    function getUserBranch($conn, $user_id) {
        $result = mysqli_query($conn, "SELECT u.branch_id, b.place_name as branch_name FROM users u LEFT JOIN places b ON u.branch_id = b.id WHERE u.id = $user_id");
        if ($result && mysqli_num_rows($result) > 0) {
            return mysqli_fetch_assoc($result);
        }
        return null;
    }
}

if (!function_exists('getCurrentBranchName')) {
    function getCurrentBranchName($conn, $branch_id) {
        if (!$branch_id || $branch_id == 'all') return 'All Branches';
        $result = mysqli_query($conn, "SELECT place_name FROM places WHERE id = " . intval($branch_id));
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return $row['place_name'];
        }
        return 'Main Branch';
    }
}

if (!function_exists('setBranchSession')) {
    function setBranchSession($branch_id, $branch_name) {
        $_SESSION['current_branch_id'] = $branch_id;
        $_SESSION['current_branch_name'] = $branch_name;
    }
}

// Initialize branch variables
$current_branch_id = 0;  // 0 means "select a branch"
$current_branch_name = 'Select Branch';

// Get all branches for dropdown
$all_branches = getAllBranches($conn);

// Get branch info based on role
if ($user_role == 'super_admin') {
    // Handle branch selection from GET or SESSION
    if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] > 0) {
        $selected_branch_id = intval($_GET['branch_id']);
        $branch_name = getCurrentBranchName($conn, $selected_branch_id);
        $current_branch_id = $selected_branch_id;
        $current_branch_name = $branch_name;
        setBranchSession($selected_branch_id, $branch_name);
    } elseif (isset($_SESSION['branch_id']) && $_SESSION['branch_id'] > 0) {
        $current_branch_id = $_SESSION['branch_id'];
        $current_branch_name = getCurrentBranchName($conn, $current_branch_id);
    } elseif (isset($_SESSION['selected_branch_id']) && $_SESSION['selected_branch_id'] > 0) {
        $current_branch_id = $_SESSION['selected_branch_id'];
        $current_branch_name = getCurrentBranchName($conn, $current_branch_id);
    } elseif (isset($_SESSION['current_branch_id']) && $_SESSION['current_branch_id'] > 0) {
        $current_branch_id = $_SESSION['current_branch_id'];
        $current_branch_name = getCurrentBranchName($conn, $current_branch_id);
    } elseif (!empty($all_branches)) {
        // Default to first branch
        $current_branch_id = $all_branches[0]['id'];
        $current_branch_name = $all_branches[0]['place_name'];
        setBranchSession($current_branch_id, $current_branch_name);
    }
}else {
    // For admin users, they are tied to a specific branch
    $user_branch = getUserBranch($conn, $user_id);
    if ($user_branch && $user_branch['branch_id']) {
        $current_branch_id = $user_branch['branch_id'];
        $current_branch_name = $user_branch['branch_name'] ?? getCurrentBranchName($conn, $current_branch_id);
    } else {
        $current_branch_id = 'all';
        $current_branch_name = 'All Branches';
    }
}

$excluded_user_ids = [3];

// Build branch filter condition for queries
$branch_filter = '';
if ($user_role == 'super_admin') {
    if ($current_branch_id > 0) {
        $branch_filter = " AND branch_id = " . intval($current_branch_id);
    }
} else {
    // For admin, always filter by their branch
    if ($current_branch_id > 0) {
        $branch_filter = " AND branch_id = " . intval($current_branch_id);
    }
}

// Initialize stats array
$stats = [
    'super_admins' => 1,
    'admins' => 0,
    'sellers' => 0,
    'total_users' => 0,
    'today_sales_count' => 0,
    'today_sales_total' => 0,
    'total_products' => 0,
    'month_sales' => 0
];

// Users stats - OPTIMIZED: 1 query instead of 4 separate queries
$user_exclude_condition = "id NOT IN (" . implode(',', $excluded_user_ids) . ")";
$users_q = mysqli_query($conn, "
    SELECT
        SUM(role = 'super_admin') AS super_admins,
        SUM(role = 'admin')       AS admins,
        SUM(role = 'seller')      AS sellers,
        COUNT(*)                  AS total_users
    FROM users
    WHERE $user_exclude_condition" . $branch_filter);
if ($users_q) {
    $ur = mysqli_fetch_assoc($users_q);
    $stats['super_admins']  = $ur['super_admins']  ?? 1;
    $stats['admins']        = $ur['admins']         ?? 0;
    $stats['sellers']       = $ur['sellers']        ?? 0;
    $stats['total_users']   = $ur['total_users']    ?? 0;
}

// Transactions stats - OPTIMIZED: 1 query using BETWEEN (uses idx_branch_date index)
// instead of 2 queries with DATE() / MONTH() functions that bypass indexes
$today_start  = date('Y-m-d 00:00:00');
$today_end    = date('Y-m-d 23:59:59');
$month_start  = date('Y-m-01 00:00:00');
$month_end    = date('Y-m-t 23:59:59');
$txn_q = mysqli_query($conn, "
    SELECT
        COUNT(CASE WHEN transaction_date BETWEEN '$today_start' AND '$today_end' THEN 1 END)         AS today_count,
        COALESCE(SUM(CASE WHEN transaction_date BETWEEN '$today_start' AND '$today_end' THEN total_amount END), 0) AS today_total,
        COALESCE(SUM(CASE WHEN transaction_date BETWEEN '$month_start' AND '$month_end' THEN total_amount END), 0) AS month_total
    FROM transactions
    WHERE 1=1" . $branch_filter);
if ($txn_q) {
    $tr = mysqli_fetch_assoc($txn_q);
    $stats['today_sales_count'] = $tr['today_count'] ?? 0;
    $stats['today_sales_total'] = $tr['today_total'] ?? 0;
    $stats['month_sales']       = $tr['month_total'] ?? 0;
}

// Products stats
$products_table_exists = true; // table confirmed to exist
if($products_table_exists) {
    // For products, if branch is 'all', show all products, otherwise filter by branch
    $product_branch_filter = '';
    if ($current_branch_id !== 'all') {
        $product_branch_filter = " WHERE branch_id = " . intval($current_branch_id);
    }
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM products" . $product_branch_filter));
    $stats['total_products'] = $r['c'] ?? 0;
}

// Recent activity - users list
$users_table_exists = true; // table confirmed to exist
$transactions_table_exists = true; // table confirmed to exist
$activity_result = false;
if($users_table_exists) {
    $activity_result = mysqli_query($conn, "SELECT id, username, role, created_at FROM users WHERE id NOT IN (" . implode(',', $excluded_user_ids) . ")" . $branch_filter . " ORDER BY created_at DESC LIMIT 8");
}

// Daily sales chart data
$daily_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$daily_totals = [0, 0, 0, 0, 0, 0, 0];
if($transactions_table_exists) {
    $chart_branch_filter = '';
    if ($current_branch_id !== 'all') {
        $chart_branch_filter = " AND branch_id = " . intval($current_branch_id);
    }
    $r = mysqli_query($conn, "SELECT DAYNAME(transaction_date) as d, SUM(total_amount) as t FROM transactions WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $chart_branch_filter . " GROUP BY DAYNAME(transaction_date) ORDER BY FIELD(d, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')");
    if ($r) {
        $map = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4, 'Saturday' => 5, 'Sunday' => 6];
        while($row = mysqli_fetch_assoc($r)) {
            if(isset($map[$row['d']])) $daily_totals[$map[$row['d']]] = floatval($row['t']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>Super Admin Dashboard - Aleltu POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ===================================================
           MOBILE-FIRST CSS — base = phones (< 480px)
           progressively enhanced for tablet & desktop
        =================================================== */
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;}

        body{
            background:linear-gradient(135deg,#0f2027 0%,#203a43 50%,#2c5364 100%);
            min-height:100vh;
            color:#333;
        }

        /* ── Hamburger toggle button (visible on mobile only) ── */
        .menu-toggle{
            display:flex;
            align-items:center;
            justify-content:center;
            position:fixed;
            top:12px;
            left:12px;
            z-index:1200;
            width:44px;
            height:44px;
            background:linear-gradient(135deg,#667eea,#764ba2);
            border:none;
            border-radius:10px;
            cursor:pointer;
            color:white;
            font-size:18px;
            box-shadow:0 4px 15px rgba(102,126,234,0.4);
            transition:all .3s;
        }
        .menu-toggle:hover{transform:scale(1.05);}

        /* ── Overlay behind open sidebar (mobile) ── */
        .sidebar-overlay{
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.55);
            z-index:999;
            backdrop-filter:blur(2px);
        }
        .sidebar-overlay.active{display:block;}

        /* ── Sidebar ── */
        .sidebar{
            width:270px;
            background:rgba(15,23,42,0.98);
            backdrop-filter:blur(12px);
            color:white;
            padding:20px 0;
            position:fixed;
            top:0;
            left:-270px;          /* hidden off-screen on mobile */
            height:100vh;
            border-right:1px solid rgba(255,255,255,0.1);
            z-index:1100;
            overflow-y:auto;
            transition:left .3s cubic-bezier(.4,0,.2,1);
        }
        .sidebar.open{left:0;}

        .sidebar-header{
            padding:20px 20px 25px;
            border-bottom:1px solid rgba(255,255,255,0.1);
            margin-bottom:20px;
        }
        .sidebar-header h1{
            font-size:20px;
            margin-bottom:8px;
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .sidebar-header .badge{
            background:linear-gradient(135deg,#ff6b6b 0%,#ee5a24 100%);
            color:white;
            padding:3px 10px;
            border-radius:12px;
            font-size:10px;
            font-weight:700;
            text-transform:uppercase;
            display:inline-block;
        }

        .user-info{
            display:flex;
            align-items:center;
            gap:12px;
            padding:14px 18px;
            background:rgba(255,255,255,0.05);
            border-radius:12px;
            margin:0 16px 20px;
        }
        .user-avatar{
            width:42px;
            height:42px;
            min-width:42px;
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:17px;
        }
        .user-details h3{font-size:15px;margin-bottom:3px;}
        .user-details p{font-size:11px;color:#94a3b8;}

        .nav-menu{list-style:none;padding:0 12px;margin-bottom:100px;}
        .nav-item{margin-bottom:6px;}
        .nav-link{
            display:flex;
            align-items:center;
            gap:12px;
            padding:13px 14px;
            color:#cbd5e1;
            text-decoration:none;
            border-radius:10px;
            transition:all .3s;
            font-size:14px;
        }
        .nav-link:hover{background:rgba(255,255,255,0.1);color:white;transform:translateX(5px);}
        .nav-link.active{
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            color:white;
            box-shadow:0 5px 15px rgba(102,126,234,0.3);
        }

        .logout-section{
            padding:18px;
            border-top:1px solid rgba(255,255,255,0.1);
            position:absolute;
            bottom:0;
            width:100%;
            background:rgba(15,23,42,0.98);
        }
        .logout-btn{
            display:flex;
            align-items:center;
            justify-content:center;
            gap:10px;
            width:100%;
            padding:12px;
            background:rgba(239,68,68,0.1);
            color:#ef4444;
            border:1px solid rgba(239,68,68,0.2);
            border-radius:10px;
            cursor:pointer;
            font-weight:600;
            font-size:14px;
            transition:all .3s;
        }
        .logout-btn:hover{background:rgba(239,68,68,0.2);transform:translateY(-2px);}

        /* ── Branch selector (inside sidebar) ── */
        .branch-selector{
            background:rgba(0,0,0,0.3);
            border-radius:10px;
            padding:12px 14px;
            margin:0 16px 20px;
            border:1px solid rgba(255,255,255,0.15);
        }
        .branch-selector form{display:flex;flex-direction:column;gap:8px;}
        .branch-selector label{font-size:12px;color:#94a3b8;}
        .branch-selector select{
            width:100%;
            padding:10px 12px;
            border-radius:8px;
            background:white;
            border:1px solid #ddd;
            font-size:14px;
            cursor:pointer;
        }
        .branch-selector select:focus{outline:none;border-color:#6366f1;}

        /* ── Main content ── */
        .main-content{
            padding:70px 14px 20px;  /* top pad clears the hamburger button */
        }

        /* ── Page header ── */
        .main-header{
            margin-bottom:22px;
        }
        .header-title h1{
            color:white;
            font-size:20px;
            margin-bottom:6px;
            line-height:1.3;
        }
        .header-title p{color:#94a3b8;font-size:13px;}
        .branch-badge{
            display:inline-block;
            background:#6366f1;
            color:white;
            padding:4px 12px;
            border-radius:20px;
            font-size:12px;
            margin-left:10px;
        }

        /* ── Stat cards ── */
        .stats-grid{
            display:grid;
            grid-template-columns:1fr 1fr;   /* 2-col on phones */
            gap:12px;
            margin-bottom:22px;
        }
        .stat-card{
            background:rgba(255,255,255,0.96);
            backdrop-filter:blur(10px);
            border-radius:14px;
            padding:18px 14px;
            box-shadow:0 6px 25px rgba(0,0,0,0.12);
            border:1px solid rgba(255,255,255,0.2);
            transition:all .3s;
        }
        .stat-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(0,0,0,0.2);}
        .stat-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
        .stat-title{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.8px;font-weight:600;}
        .stat-icon{
            width:40px;
            height:40px;
            min-width:40px;
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
            color:white;
        }
        .stat-icon.super-admin{background:linear-gradient(135deg,#ff6b6b 0%,#ee5a24 100%);}
        .stat-icon.admin{background:linear-gradient(135deg,#4ecdc4 0%,#44a08d 100%);}
        .stat-icon.seller{background:linear-gradient(135deg,#ffd166 0%,#ffb142 100%);}
        .stat-icon.users{background:linear-gradient(135deg,#06d6a0 0%,#1b9aaa 100%);}
        .stat-icon.sales{background:linear-gradient(135deg,#118ab2 0%,#073b4c 100%);}
        .stat-icon.products{background:linear-gradient(135deg,#7209b7 0%,#560bad 100%);}
        .stat-icon.monthly{background:linear-gradient(135deg,#f15bb5 0%,#9b5de5 100%);}
        .stat-value{font-size:26px;font-weight:800;color:#1e293b;line-height:1;margin-top:4px;}
        .stat-change{display:flex;align-items:center;gap:5px;font-size:11px;color:#64748b;margin-top:6px;}

        /* ── Chart + activity (stacked on mobile) ── */
        .main-grid{
            display:grid;
            grid-template-columns:1fr;
            gap:18px;
            margin-bottom:25px;
        }
        .chart-section,.activity-section{
            background:rgba(255,255,255,0.96);
            backdrop-filter:blur(10px);
            border-radius:14px;
            padding:20px 16px;
            box-shadow:0 6px 25px rgba(0,0,0,0.12);
            border:1px solid rgba(255,255,255,0.2);
        }
        .section-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:18px;
            flex-wrap:wrap;
            gap:8px;
        }
        .section-header h2{color:#1e293b;font-size:17px;display:flex;align-items:center;gap:8px;}
        .section-header a{font-size:13px;color:#6366f1;text-decoration:none;}
        .section-header a:hover{text-decoration:underline;}
        .chart-wrapper{height:220px;position:relative;}

        .activity-list{margin-top:12px;max-height:340px;overflow-y:auto;}
        .activity-item{
            display:flex;
            align-items:center;
            gap:12px;
            padding:13px 10px;
            border-bottom:1px solid #f1f5f9;
            transition:background .3s;
        }
        .activity-item:hover{background:#f8fafc;border-radius:10px;}
        .activity-icon{
            width:38px;
            height:38px;
            min-width:38px;
            border-radius:10px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:white;
            font-size:15px;
        }
        .activity-icon.super_admin{background:#ff6b6b;}
        .activity-icon.admin{background:#3b82f6;}
        .activity-icon.seller{background:#10b981;}
        .activity-content{flex:1;min-width:0;}
        .activity-title{font-weight:600;color:#1e293b;margin-bottom:3px;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .activity-time{font-size:11px;color:#64748b;}
        .activity-badge{padding:3px 8px;border-radius:20px;font-size:10px;font-weight:600;white-space:nowrap;}
        .badge-super_admin{background:#fee2e2;color:#dc2626;}
        .badge-admin{background:#dbeafe;color:#1d4ed8;}
        .badge-seller{background:#dcfce7;color:#16a34a;}
        .no-data{text-align:center;padding:28px;color:#94a3b8;}
        .no-data i{font-size:42px;margin-bottom:12px;opacity:0.5;}

        /* ============================================================
           TABLET  ≥ 600px
        ============================================================ */
        @media(min-width:600px){
            .main-content{padding:70px 20px 25px;}
            .stats-grid{grid-template-columns:repeat(2,1fr);gap:16px;}
            .stat-value{font-size:30px;}
            .header-title h1{font-size:23px;}
            .chart-wrapper{height:260px;}
        }

        /* ============================================================
           LARGE TABLET / SMALL DESKTOP  ≥ 900px
           Sidebar becomes permanently visible
        ============================================================ */
        @media(min-width:900px){
            .menu-toggle{display:none;}
            .sidebar-overlay{display:none !important;}
            .sidebar{
                left:0 !important;
                width:260px;
            }
            .main-content{
                margin-left:260px;
                padding:28px 28px 30px;
            }
            .stats-grid{grid-template-columns:repeat(3,1fr);gap:20px;}
            .stat-value{font-size:34px;}
            .header-title h1{font-size:26px;}
            .branch-selector form{flex-direction:row;align-items:center;}
            .chart-wrapper{height:280px;}
        }

        /* ============================================================
           DESKTOP  ≥ 1200px
        ============================================================ */
        @media(min-width:1200px){
            .stats-grid{grid-template-columns:repeat(4,1fr);}
            .main-grid{grid-template-columns:2fr 1fr;}
            .stat-value{font-size:36px;}
            .header-title h1{font-size:28px;}
            .chart-wrapper{height:300px;}
        }
    </style>
</head>
<body>
<!-- Hamburger toggle -->
<button class="menu-toggle" id="menuToggle" aria-label="Open menu">
    <i class="fas fa-bars"></i>
</button>
<!-- Sidebar overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="dashboard-container">
    <div class="sidebar">
        <div class="sidebar-header">
            <h1><i class="fas fa-crown"></i><span>Super Admin</span></h1>
            <div class="badge">የሁሉም ተቆጣጣሪ</div>
        </div>

        <?php if($_SESSION['role'] == 'super_admin' && !empty($all_branches)): ?>
        <div class="branch-selector">
            <form method="GET" action="">
                <i class="fas fa-store"></i>
                <label>Select Branch:</label>
               <select name="branch_id" onchange="this.form.submit()">
    <option value="">-- Select Branch --</option>
    <?php foreach($all_branches as $branch): ?>
    <option value="<?php echo $branch['id']; ?>" <?php echo ($current_branch_id == $branch['id']) ? 'selected' : ''; ?>>
        🏪 <?php echo htmlspecialchars($branch['place_name']); ?>
    </option>
    <?php endforeach; ?>
</select>
            </form>
        </div>
        <?php endif; ?>
      
        <div class="user-info">
            <div class="user-avatar"><i class="fas fa-user-shield"></i></div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin'); ?></h3>
                <p><?php echo ucfirst(str_replace('_',' ',$_SESSION['role'])); ?></p>
            </div>
        </div>

        <ul class="nav-menu">
            <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="fas fa-boxes"></i><span>ምርት ሽያጭ መቆጣጠሪያ</span></a></li>
            <li class="nav-item"><a href="history.php" class="nav-link"><i class="fas fa-history"></i><span>የሽያጭ ማህደር</span></a></li>
            <li class="nav-item"><a href="seller_pos.php" class="nav-link"><i class="fas fa-store"></i><span>የመሸጫ ገጽ</span></a></li>
            <li class="nav-item"><a href="branch_controller.php" class="nav-link"><i class="fas fa-code-branch"></i><span>Branch Controller</span></a></li>
            <li class="nav-item"><a href="super_delete_manager.php" class="nav-link"><i class="fas fa-user-shield"></i><span>Data Delete Manager</span></a></li>
        </ul>
        <div class="logout-section">
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i><span>Logout</span></button>
        </div>
    </div>

    <div class="main-content">
        <div class="main-header">
            <div class="header-title">
                <h1>
                    <i class="fas fa-chalkboard-user"></i> 
                    የሁሉም ተቆጣጣሪ የፊት ገጽ 
                    <?php if($current_branch_id !== 'all' && $current_branch_id > 0): ?>
                        <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?></span>
                    <?php elseif($current_branch_id === 'all'): ?>
                        <span class="branch-badge" style="background:#10b981;"><i class="fas fa-globe"></i> All Branches</span>
                    <?php endif; ?>
                </h1>
                <p><i class="fas fa-chart-line"></i> የፊት ገጽ እና መቆጣጠሪያ | አጠቃላይ ሪፖርት</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">Super Admins</div><div class="stat-value"><?php echo $stats['super_admins']; ?></div></div>
                    <div class="stat-icon super-admin"><i class="fas fa-crown"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">Admins</div><div class="stat-value"><?php echo $stats['admins']; ?></div></div>
                    <div class="stat-icon admin"><i class="fas fa-user-shield"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">የሻጮች ዝርዝር</div><div class="stat-value"><?php echo $stats['sellers']; ?></div></div>
                    <div class="stat-icon seller"><i class="fas fa-user-tie"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">ጠቅላላ ተጠቃሚዎች</div><div class="stat-value"><?php echo $stats['total_users']; ?></div></div>
                    <div class="stat-icon users"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">የዛሬ ሽያጭ</div><div class="stat-value"><?php echo number_format($stats['today_sales_total'], 2); ?> ETB</div></div>
                    <div class="stat-icon sales"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <?php if($stats['today_sales_count'] > 0): ?>
                <div class="stat-change"><i class="fas fa-receipt"></i> <?php echo $stats['today_sales_count']; ?> transactions</div>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">ጠቅላላ እየተሸቱ ያሉት ምርቶች</div><div class="stat-value"><?php echo $stats['total_products']; ?></div></div>
                    <div class="stat-icon products"><i class="fas fa-boxes"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div><div class="stat-title">ወርሃዊ ሽያጭ</div><div class="stat-value"><?php echo number_format($stats['month_sales'], 2); ?> ETB</div></div>
                    <div class="stat-icon monthly"><i class="fas fa-chart-bar"></i></div>
                </div>
            </div>
        </div>

        <div class="main-grid">
            <div class="chart-section">
                <div class="section-header">
                    <h2><i class="fas fa-chart-line"></i> የሽያጭ ውጣውረድ (ላለፉት 7 ቀን)</h2>
                </div>
                <div class="chart-wrapper">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
            <div class="activity-section">
                <div class="section-header">
                    <h2><i class="fas fa-history"></i> ጠቅላላ ተጠቃሚዎች</h2>
                    <a href="manage_users.php"><i class="fas fa-arrow-right"></i> ሁሉም</a>
                </div>
                <div class="activity-list">
                    <?php if($activity_result && mysqli_num_rows($activity_result) > 0): ?>
                        <?php while($u = mysqli_fetch_assoc($activity_result)): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $u['role']; ?>"><i class="fas fa-user"></i></div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo htmlspecialchars($u['username']); ?></div>
                                <div class="activity-time">Joined <?php echo date('M d, Y', strtotime($u['created_at'])); ?></div>
                            </div>
                            <span class="activity-badge badge-<?php echo $u['role']; ?>"><?php echo ucfirst(str_replace('_',' ', $u['role'])); ?></span>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-users"></i>
                            <p>No recent users found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // ── Sidebar toggle (mobile) ──────────────────────────────────
    const menuToggle    = document.getElementById('menuToggle');
    const sidebar       = document.querySelector('.sidebar');
    const overlay       = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        menuToggle.innerHTML = '<i class="fas fa-times"></i>';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
    }
    menuToggle.addEventListener('click', () => {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);

    // ── Sales Chart ──────────────────────────────────────────────
    const dailyLabels = <?php echo json_encode($daily_labels); ?>;
    const dailyTotals = <?php echo json_encode($daily_totals); ?>;

    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Daily Sales (ETB)',
                data: dailyTotals,
                backgroundColor: 'rgba(99,102,241,0.1)',
                borderColor: 'rgba(99,102,241,1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(99,102,241,1)',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Sales: ' + context.parsed.y.toFixed(2) + ' ETB';
                        }
                    }
                },
                legend: { position: 'top' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toFixed(0) + ' ETB';
                        }
                    }
                }
            }
        }
    });

    function logout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = 'logout.php';
        }
    }
</script>
</body>
</html>
<?php mysqli_close($conn); ?> 