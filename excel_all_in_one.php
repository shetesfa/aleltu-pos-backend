<?php
// excel_all_in_one.php - Excel-like table with messaging system (OPTIMIZED)
session_start();
require_once 'config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'seller';
$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "User";

// Restrict to admin and super_admin only
if ($user_role != 'admin' && $user_role != 'super_admin') {
    die("Access denied. Only administrators can access this page.");
}

// Get branch info
$branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$branch_name = getCurrentBranchName($conn, $branch_id);

// If super admin selected a different branch via GET
if ($user_role == 'super_admin' && isset($_GET['branch_id']) && $_GET['branch_id'] > 0) {
    $branch_id = intval($_GET['branch_id']);
    $branch_result = mysqli_query($conn, "SELECT place_name FROM places WHERE id = $branch_id");
    if ($branch_row = mysqli_fetch_assoc($branch_result)) {
        $branch_name = $branch_row['place_name'];
    }
}

// Database connection using PDO
try {
    $host = 'localhost';
    $db_name = 'aleltu';
    $username = 'root';
    $password = '';
    
    $db = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create messages table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS admin_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT NOT NULL,
        from_user_name VARCHAR(100) NOT NULL,
        to_branch_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT DEFAULT 0,
        read_by INT DEFAULT NULL,
        read_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (to_branch_id, is_read)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Create excel_data table if not exists
    $tableExists = $db->query("SHOW TABLES LIKE 'excel_data'")->rowCount() > 0;
    
    if (!$tableExists) {
        $db->exec("CREATE TABLE excel_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `row_number` INT NOT NULL,
            branch_id INT NOT NULL DEFAULT 1,
            item_name TEXT,
            quantity VARCHAR(50),
            buying_price VARCHAR(50),
            total_cost VARCHAR(50),
            selling_price VARCHAR(50),
            profit_per_unit VARCHAR(50),
            total_selling VARCHAR(50),
            total_profit VARCHAR(50),
            transaction_date VARCHAR(50),
            created_by VARCHAR(100),
            updated_by VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_row_branch (`row_number`, branch_id),
            KEY idx_branch_id (branch_id)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    
    // Check if branch has rows
    $count = $db->prepare("SELECT COUNT(*) FROM excel_data WHERE branch_id = ?");
    $count->execute([$branch_id]);
    $row_count = $count->fetchColumn();
    
    // If no rows for this branch, create 2000 empty rows (only 20 at a time to avoid timeout)
    if ($row_count == 0) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO excel_data 
            (`row_number`, branch_id, item_name, quantity, buying_price, total_cost, selling_price, profit_per_unit, total_selling, total_profit, transaction_date, created_by) 
            VALUES (?, ?, '', '', '', '', '', '', '', '', '', ?)");
        
        // Insert in batches of 500 to avoid memory issues
        for ($batch = 0; $batch < 4; $batch++) {
            $start = $batch * 500 + 1;
            $end = ($batch + 1) * 500;
            for ($i = $start; $i <= $end; $i++) {
                $stmt->execute([$i, $branch_id, $current_user]);
            }
        }
        $db->commit();
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// ========== CACHED PROFIT CALCULATION ==========
$cache_file = sys_get_temp_dir() . '/profit_cache_' . $branch_id . '.txt';
$cache_time = 300; // 5 minutes cache

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
    $cached = unserialize(file_get_contents($cache_file));
    $totalExcelProfit = $cached['profit'];
    $totalPermWithdrawals = $cached['perm'];
    $netProfit = $cached['net'];
} else {
    // Calculate fresh - OPTIMIZED queries
    $profitStmt = $db->prepare("SELECT SUM(CAST(total_profit AS DECIMAL(10,2))) as total_profit FROM excel_data WHERE branch_id = ? AND total_profit IS NOT NULL AND total_profit != ''");
    $profitStmt->execute([$branch_id]);
    $profitData = $profitStmt->fetch(PDO::FETCH_ASSOC);
    $totalExcelProfit = $profitData['total_profit'] ?? 0;
    
    $permQuery = mysqli_query($conn, "SELECT SUM(amount) as total_perm FROM daily_withdrawals WHERE branch_id = $branch_id AND withdrawal_type = 'ቋሚ'");
    $permData = mysqli_fetch_assoc($permQuery);
    $totalPermWithdrawals = $permData['total_perm'] ?? 0;
    $netProfit = $totalExcelProfit - $totalPermWithdrawals;
    
    // Save to cache
    file_put_contents($cache_file, serialize([
        'profit' => $totalExcelProfit,
        'perm' => $totalPermWithdrawals,
        'net' => $netProfit
    ]));
}

// ========== HANDLE AJAX ACTIONS ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Send message (Super Admin only)
    if ($_POST['action'] == 'send_message' && $user_role == 'super_admin') {
        $to_branch_id = intval($_POST['branch_id']);
        $message = trim($_POST['message']);
        
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message is empty']);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO admin_messages (from_user_id, from_user_name, to_branch_id, message) VALUES (?, ?, ?, ?)");
        $success = $stmt->execute([$user_id, $current_user, $to_branch_id, $message]);
        
        echo json_encode(['success' => $success]);
        exit;
    }
    
    // Mark message as seen
    if ($_POST['action'] == 'mark_seen') {
        $message_id = intval($_POST['message_id']);
        
        $stmt = $db->prepare("UPDATE admin_messages SET is_read = 1, read_by = ?, read_at = NOW() WHERE id = ? AND to_branch_id = ?");
        $success = $stmt->execute([$user_id, $message_id, $branch_id]);
        
        echo json_encode(['success' => $success]);
        exit;
    }
    
    // Save cell - OPTIMIZED
    if ($_POST['action'] == 'save_cell') {
        $row_id = intval($_POST['row_id']);
        $field = $_POST['field'];
        $value = $_POST['value'];
        
        $allowed_fields = ['item_name', 'quantity', 'buying_price', 'total_cost', 'selling_price', 
                          'profit_per_unit', 'total_selling', 'total_profit', 'transaction_date'];
        
        if (!in_array($field, $allowed_fields)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
            exit;
        }
        
        // Wrap field name in backticks
        $stmt = $db->prepare("UPDATE excel_data SET `$field` = ?, updated_by = ?, updated_at = NOW() WHERE `row_number` = ? AND branch_id = ?");
        $success = $stmt->execute([$value, $current_user, $row_id, $branch_id]);
        
        // Clear cache when data changes
        if ($success && file_exists($cache_file)) {
            unlink($cache_file);
        }
        
        echo json_encode(['success' => $success]);
        exit;
    }
    
    // Clear all data for this branch
    if ($_POST['action'] == 'clear_all') {
        if ($user_role == 'super_admin' && isset($_POST['branch_id'])) {
            $clear_branch = intval($_POST['branch_id']);
        } else {
            $clear_branch = $branch_id;
        }
        
        $stmt = $db->prepare("UPDATE excel_data SET 
            item_name = '', quantity = '', buying_price = '', total_cost = '', 
            selling_price = '', profit_per_unit = '', total_selling = '', 
            total_profit = '', transaction_date = '', updated_by = ? 
            WHERE branch_id = ?");
        $success = $stmt->execute([$current_user, $clear_branch]);
        
        // Clear cache
        if ($success && file_exists($cache_file)) {
            unlink($cache_file);
        }
        
        echo json_encode(['success' => $success]);
        exit;
    }
    
    // Get profit stats with cache
    if ($_POST['action'] == 'get_profit_stats') {
        $branch = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : $branch_id;
        $cache_file_local = sys_get_temp_dir() . '/profit_cache_' . $branch . '.txt';
        
        // Check cache first
        if (file_exists($cache_file_local) && (time() - filemtime($cache_file_local)) < 60) {
            $cached_local = unserialize(file_get_contents($cache_file_local));
            echo json_encode([
                'success' => true,
                'total_profit' => number_format($cached_local['profit'], 2),
                'perm_withdrawals' => number_format($cached_local['perm'], 2),
                'net_profit' => number_format($cached_local['net'], 2),
                'net_profit_raw' => $cached_local['net']
            ]);
            exit;
        }
        
        // Calculate fresh
        $profitStmt = $db->prepare("SELECT SUM(CAST(total_profit AS DECIMAL(10,2))) as total_profit FROM excel_data WHERE branch_id = ? AND total_profit IS NOT NULL AND total_profit != ''");
        $profitStmt->execute([$branch]);
        $profitData = $profitStmt->fetch(PDO::FETCH_ASSOC);
        $totalProfit = $profitData['total_profit'] ?? 0;
        
        global $conn;
        $permQuery = mysqli_query($conn, "SELECT SUM(amount) as total_perm FROM daily_withdrawals WHERE branch_id = $branch AND withdrawal_type = 'ቋሚ'");
        $permData = mysqli_fetch_assoc($permQuery);
        $totalPerm = $permData['total_perm'] ?? 0;
        
        $netProfitCalc = $totalProfit - $totalPerm;
        
        // Save to cache
        file_put_contents($cache_file_local, serialize([
            'profit' => $totalProfit,
            'perm' => $totalPerm,
            'net' => $netProfitCalc
        ]));
        
        echo json_encode([
            'success' => true,
            'total_profit' => number_format($totalProfit, 2),
            'perm_withdrawals' => number_format($totalPerm, 2),
            'net_profit' => number_format($netProfitCalc, 2),
            'net_profit_raw' => $netProfitCalc
        ]);
        exit;
    }
}

// ========== GET UNREAD MESSAGES ==========
$unread_messages = [];
if ($user_role == 'super_admin') {
    $stmt = $db->query("SELECT m.*, p.place_name as branch_name 
                        FROM admin_messages m 
                        LEFT JOIN places p ON m.to_branch_id = p.id 
                        WHERE m.is_read = 0 
                        ORDER BY m.created_at DESC 
                        LIMIT 50");
    $unread_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("SELECT * FROM admin_messages WHERE to_branch_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$branch_id]);
    $unread_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========== GET DATA FOR CURRENT BRANCH (OPTIMIZED) ==========
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$start_row = ($page - 1) * 50 + 1;
$end_row = $start_row + 49;

// Get data for this specific branch only - OPTIMIZED query
$stmt = $db->prepare("SELECT * FROM excel_data WHERE branch_id = ? AND `row_number` BETWEEN ? AND ? ORDER BY `row_number` ASC");
$stmt->execute([$branch_id, $start_row, $end_row]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total rows with data for this branch only - OPTIMIZED with index
$count_stmt = $db->prepare("SELECT COUNT(*) FROM excel_data WHERE branch_id = ? AND item_name != ''");
$count_stmt->execute([$branch_id]);
$filled_rows = $count_stmt->fetchColumn();

// Get all branches for super admin
$branches = [];
if ($user_role == 'super_admin') {
    $branches_result = mysqli_query($conn, "SELECT id, place_name FROM places WHERE status = 'active' ORDER BY place_name");
    while ($b = mysqli_fetch_assoc($branches_result)) {
        $branches[] = $b;
    }
}

// Build row map for display
$row_map = [];
foreach ($data as $d) {
    $row_map[$d['row_number']] = $d;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>Excel Table - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', 'Nyala', 'Abyssinica SIL', Arial, sans-serif;
        }
        
        body {
            background: #2c3e50;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --header-bg: linear-gradient(135deg, #667eea, #764ba2);
            --toolbar-bg: #34495e;
            --btn-bg: #2c3e50;
            --btn-hover: #1a2632;
            --btn-success: #27ae60;
            --btn-success-hover: #229954;
            --btn-danger: #c0392b;
            --btn-danger-hover: #a93226;
            --cell-bg: white;
            --cell-readonly: #f5f5f5;
            --cell-focus: #e8f0fe;
            --border-color: #dadce0;
            --text-dark: #202124;
            --text-light: #5f6368;
            --message-bg: #fff3cd;
            --message-border: #ffc107;
            --message-text: #856404;
            --profit-positive: #27ae60;
            --profit-negative: #e74c3c;
        }
        
        /* ── Header: stacked on mobile ── */
        .header {
            background: var(--header-bg);
            color: white;
            padding: 12px 15px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .header h1 {
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .branch-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
        
        .role-badge {
            background: <?php echo ($user_role == 'super_admin') ? '#e74c3c' : '#f39c12'; ?>;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* ── Profit cards: stacked on mobile ── */
        .profit-cards {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 10px 12px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
        }
        
        .profit-card {
            width: 100%;
            background: white;
            border-radius: 12px;
            padding: 12px 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.2s;
        }
        
        .profit-card:hover {
            transform: translateY(-2px);
        }
        
        .profit-icon {
            width: 40px;
            height: 40px;
            min-width: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }
        
        .profit-icon.gross { background: linear-gradient(135deg, #3498db, #2980b9); }
        .profit-icon.perm { background: linear-gradient(135deg, #e67e22, #d35400); }
        .profit-icon.net { background: linear-gradient(135deg, #27ae60, #229954); }
        
        .profit-content { flex: 1; }
        .profit-label { font-size: 0.75rem; color: #7f8c8d; text-transform: uppercase; margin-bottom: 3px; }
        .profit-value { font-size: 1.3rem; font-weight: 700; color: #2c3e50; }
        .profit-card.net .profit-value { color: #27ae60; }
        .profit-card.net.negative .profit-value { color: #e74c3c; }
        .profit-sub { font-size: 0.7rem; color: #95a5a6; margin-top: 2px; }
        
        .refresh-btn {
            background: rgba(0,0,0,0.1);
            border: none;
            color: #2c3e50;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s;
            min-height: 44px;
        }
        
        .refresh-btn:hover { background: rgba(0,0,0,0.2); }
        
        /* ── Message popup: column on mobile ── */
        .message-popup {
            background: var(--message-bg);
            border-left: 4px solid var(--message-border);
            color: var(--message-text);
            padding: 12px;
            margin: 10px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .message-content { flex: 1; }
        .message-sender { font-weight: 600; font-size: 0.85rem; }
        .message-text { margin-top: 4px; font-size: 0.9rem; }
        .message-time { font-size: 0.7rem; opacity: 0.8; margin-top: 3px; }
        
        .btn-seen {
            background: var(--btn-success);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            width: 100%;
            min-height: 44px;
        }
        
        .btn-seen:hover { background: var(--btn-success-hover); }
        
        /* ── Toolbar: full touch targets on mobile ── */
        .toolbar {
            background: var(--toolbar-bg);
            padding: 10px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
        }
        
        .btn {
            background: var(--btn-bg);
            color: white;
            border: none;
            padding: 10px 14px;
            font-size: 0.85rem;
            cursor: pointer;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: background 0.3s;
            min-height: 44px;
        }
        
        .btn:hover { background: var(--btn-hover); }
        .btn-success { background: var(--btn-success); }
        .btn-success:hover { background: var(--btn-success-hover); }
        .btn-danger { background: #c0392b; }
        .btn-danger:hover { background: #a93226; }
        
        .branch-selector {
            background: var(--btn-bg);
            color: white;
            border: 1px solid #1a2632;
            padding: 10px;
            border-radius: 4px;
            font-size: 0.85rem;
            width: 100%;
            margin-left: 0;
            min-height: 44px;
        }
        
        /* ── Formula bar: column on mobile ── */
        .formula-bar {
            background: #ecf0f1;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
        }
        
        .cell-address {
            background: white;
            border: 1px solid #bdc3c7;
            padding: 8px 10px;
            min-width: 60px;
            font-size: 0.85rem;
            font-weight: bold;
            text-align: center;
            border-radius: 3px;
            min-height: 40px;
        }
        
        .formula-input {
            flex: 1;
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #bdc3c7;
            border-radius: 3px;
            font-size: 0.9rem;
            min-height: 44px;
        }
        
        .formula-input:focus {
            outline: 2px solid var(--primary);
            border-color: transparent;
        }
        
        .table-container {
            flex: 1;
            overflow: auto;
            background: white;
            max-height: calc(100vh - 380px);
        }
        
        .excel-table {
            border-collapse: collapse;
            min-width: 1000px;
            width: 100%;
            font-size: 0.85rem;
        }
        
        .excel-table th {
            background: #f1f3f4;
            color: var(--text-dark);
            font-weight: 600;
            padding: 8px 4px;
            border: 1px solid var(--border-color);
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .excel-table th div {
            font-size: 0.7rem;
            color: var(--text-light);
            font-weight: normal;
            margin-top: 2px;
        }
        
        .excel-table td:first-child {
            background: #f1f3f4;
            text-align: center;
            font-weight: 500;
            position: sticky;
            left: 0;
            z-index: 5;
        }
        
        .excel-table td {
            border: 1px solid var(--border-color);
            padding: 0;
        }
        
        .cell-input {
            width: 100%;
            height: 100%;
            border: none;
            padding: 6px 8px;
            font-size: 0.85rem;
            background: transparent;
            outline: none;
            min-height: 36px;
        }
        
        .cell-input:focus {
            background: var(--cell-focus);
            outline: 2px solid var(--primary);
            outline-offset: -1px;
        }
        
        .cell-input[readonly] {
            background: var(--cell-readonly);
            color: var(--text-dark);
        }
        
        .pagination {
            background: var(--toolbar-bg);
            padding: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .pagination .btn { padding: 8px 14px; min-height: 44px; }
        .page-info { color: white; font-size: 0.9rem; }
        
        .status-bar {
            background: var(--primary);
            color: white;
            padding: 8px 15px;
            font-size: 0.85rem;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        @media print {
            .header, .toolbar, .formula-bar, .message-popup, 
            .pagination, .status-bar, .btn, .branch-selector,
            .cell-address, .formula-input, .profit-cards { display: none !important; }
            body { background: white; padding: 0; margin: 0; }
            .table-container { max-height: none; overflow: visible; }
            .excel-table { min-width: 100%; }
            .excel-table th { background: #f1f3f4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        
        /* ── Progressive enhancement: ≥ 600px ── */
        @media (min-width: 600px) {
            .profit-cards { flex-direction: row; padding: 10px 15px; gap: 15px; }
            .profit-card { flex: 1; width: auto; padding: 15px 20px; }
            .profit-icon { width: 45px; height: 45px; min-width: 45px; font-size: 1.5rem; }
            .profit-label { font-size: 0.8rem; }
            .profit-value { font-size: 1.5rem; }
            .toolbar { justify-content: flex-start; }
            .formula-bar { flex-direction: row; }
            .formula-input { width: auto; }
            .message-popup { flex-direction: row; align-items: center; }
            .btn-seen { width: auto; }
        }

        /* ── Progressive enhancement: ≥ 900px ── */
        @media (min-width: 900px) {
            .header { flex-direction: row; justify-content: space-between; align-items: center; }
            .header h1 { font-size: 1.2rem; }
            .branch-selector { margin-left: auto; width: auto; }
        }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--btn-success);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 9999;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .fa-spin { animation: fa-spin 1s infinite linear; }
        @keyframes fa-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="header">
        <h1>
            <i class="fas fa-table"></i> አሌልቱ - Excel ሰንጠረዥ
            <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></span>
            <span class="role-badge"><?php echo ($user_role == 'super_admin') ? 'ሱፐር አድሚን' : 'አድሚን'; ?></span>
        </h1>
        <div>
            <i class="fas fa-user"></i> <?php echo htmlspecialchars($current_user); ?>
            <button class="btn" onclick="window.location.href='admin_dashboard.php'" style="margin-left: 10px;">
                <i class="fas fa-arrow-left"></i> ተመለስ
            </button>
        </div>
    </div>
    
    <!-- Profit Summary Cards -->
    <div class="profit-cards">
        <div class="profit-card">
            <div class="profit-icon gross"><i class="fas fa-chart-line"></i></div>
            <div class="profit-content">
                <div class="profit-label">ጠቅላላ ትርፍ (Excel)</div>
                <div class="profit-value" id="grossProfit"><?php echo number_format($totalExcelProfit, 2); ?></div>
                <div class="profit-sub">ETB</div>
            </div>
        </div>
        
        <div class="profit-card">
            <div class="profit-icon perm"><i class="fas fa-minus-circle"></i></div>
            <div class="profit-content">
                <div class="profit-label">ቋሚ ወጪዎች</div>
                <div class="profit-value" id="permWithdrawals">- <?php echo number_format($totalPermWithdrawals, 2); ?></div>
                <div class="profit-sub">ETB</div>
            </div>
        </div>
        
        <div class="profit-card net <?php echo $netProfit < 0 ? 'negative' : ''; ?>" id="netProfitCard">
            <div class="profit-icon net"><i class="fas fa-wallet"></i></div>
            <div class="profit-content">
                <div class="profit-label">ትርፍ (ቋሚ ወጪ ከተቀነሰ በኋላ)</div>
                <div class="profit-value" id="netProfit"><?php echo number_format($netProfit, 2); ?></div>
                <div class="profit-sub">ETB</div>
            </div>
            <button class="refresh-btn" onclick="refreshProfitStats()" id="refreshProfitBtn">
                <i class="fas fa-sync-alt"></i> አድስ
            </button>
        </div>
    </div>
    
    <!-- Messages -->
    <?php foreach ($unread_messages as $message): ?>
    <div class="message-popup" id="message_<?php echo $message['id']; ?>">
        <div class="message-content">
            <div class="message-sender">
                <i class="fas fa-envelope"></i> 
                ከ: <?php echo htmlspecialchars($message['from_user_name']); ?>
                <?php if ($user_role == 'super_admin'): ?>
                <span style="margin-left: 10px;">ለ: <?php echo htmlspecialchars($message['branch_name'] ?? 'ቅርንጫፍ'); ?></span>
                <?php endif; ?>
            </div>
            <div class="message-text"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
            <div class="message-time"><?php echo date('Y-m-d H:i', strtotime($message['created_at'])); ?></div>
        </div>
        <button class="btn-seen" onclick="markAsSeen(<?php echo $message['id']; ?>)">
            <i class="fas fa-check-circle"></i> ማየት
        </button>
    </div>
    <?php endforeach; ?>
    
    <!-- New Message for Super Admin -->
    <?php if ($user_role == 'super_admin'): ?>
    <div class="message-popup" style="background: #d1ecf1; border-left-color: #17a2b8; color: #0c5460;">
        <div class="message-content">
            <div class="message-sender"><i class="fas fa-edit"></i> አዲስ መልእክት ጻፍ</div>
            <textarea id="newMessage" style="width: 100%; padding: 8px; margin-top: 5px; border: 1px solid #17a2b8; border-radius: 4px;" rows="2" placeholder="መልእክት ይጻፉ..."></textarea>
        </div>
        <div style="display: flex; gap: 5px; margin-top: 5px;">
            <select id="messageBranch" class="branch-selector" style="background: white; color: black; width: auto;">
                <option value="0">ሁሉም ቅርንጫፎች</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['place_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-seen" onclick="sendMessage()" style="background: #17a2b8;">
                <i class="fas fa-paper-plane"></i> ላክ
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="toolbar">
        <button class="btn btn-success" onclick="saveWorkbook()"><i class="fas fa-save"></i> አስቀምጥ</button>
        <button class="btn" onclick="calculateCurrentPageOnly()"><i class="fas fa-calculator"></i> Calculate</button>
        <button class="btn" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
        <button class="btn btn-danger" onclick="clearAllData()"><i class="fas fa-trash"></i> Clear</button>
        
        <?php if ($user_role == 'super_admin' && !empty($branches)): ?>
        <select class="branch-selector" onchange="changeBranch(this.value)">
            <option value="">ምረጥ</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?php echo $b['id']; ?>" <?php echo ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($b['place_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    
    <div class="formula-bar">
        <div class="cell-address" id="cellAddress">A1</div>
        <input type="text" class="formula-input" id="formulaInput" placeholder="= Enter formula">
        <button class="btn" onclick="applyFormula()">ተግብር</button>
    </div>
    
    <div class="table-container" id="tableContainer">
        <table class="excel-table" id="excelTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th style="min-width: 150px;">የእቃው አይነት<div>Item Name</div></th>
                    <th style="width: 80px;">ብዛት<div>Qty</div></th>
                    <th style="width: 100px;">የተገዛበት<div>Buy Price</div></th>
                    <th style="width: 100px;">ጠቅላላ ወጪ<div>Total Cost</div></th>
                    <th style="width: 100px;">የተሸጠበት<div>Sell Price</div></th>
                    <th style="width: 100px;">የአንዱ ትርፍ<div>Profit/Unit</div></th>
                    <th style="width: 100px;">ጠቅላላ ሽያጭ<div>Total Sell</div></th>
                    <th style="width: 100px;">ትርፍ<div>Profit</div></th>
                    <th style="width: 100px;">ቀን<div>Date</div></th>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = $start_row; $i <= $end_row; $i++): 
                    $rowData = $row_map[$i] ?? ['item_name' => '', 'quantity' => '', 'buying_price' => '', 'total_cost' => '', 
                               'selling_price' => '', 'profit_per_unit' => '', 'total_selling' => '', 
                               'total_profit' => '', 'transaction_date' => ''];
                ?>
                <tr data-row="<?php echo $i; ?>">
                    <td><?php echo $i; ?></td>
                    <td><input type="text" class="cell-input" value="<?php echo htmlspecialchars($rowData['item_name']); ?>" data-field="item_name" data-row="<?php echo $i; ?>" onchange="saveCell(this)" onblur="saveCell(this)" onfocus="cellFocused('B<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" value="<?php echo htmlspecialchars($rowData['quantity']); ?>" data-field="quantity" data-row="<?php echo $i; ?>" onchange="saveCell(this); calculateRow(<?php echo $i; ?>)" onblur="saveCell(this)" onfocus="cellFocused('C<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" value="<?php echo htmlspecialchars($rowData['buying_price']); ?>" data-field="buying_price" data-row="<?php echo $i; ?>" onchange="saveCell(this); calculateRow(<?php echo $i; ?>)" onblur="saveCell(this)" onfocus="cellFocused('D<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" readonly value="<?php echo htmlspecialchars($rowData['total_cost']); ?>" data-field="total_cost" data-row="<?php echo $i; ?>" id="total_cost_<?php echo $i; ?>" onfocus="cellFocused('E<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" value="<?php echo htmlspecialchars($rowData['selling_price']); ?>" data-field="selling_price" data-row="<?php echo $i; ?>" onchange="saveCell(this); calculateRow(<?php echo $i; ?>)" onblur="saveCell(this)" onfocus="cellFocused('F<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" readonly value="<?php echo htmlspecialchars($rowData['profit_per_unit']); ?>" data-field="profit_per_unit" data-row="<?php echo $i; ?>" id="profit_unit_<?php echo $i; ?>" onfocus="cellFocused('G<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" readonly value="<?php echo htmlspecialchars($rowData['total_selling']); ?>" data-field="total_selling" data-row="<?php echo $i; ?>" id="total_selling_<?php echo $i; ?>" onfocus="cellFocused('H<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" readonly value="<?php echo htmlspecialchars($rowData['total_profit']); ?>" data-field="total_profit" data-row="<?php echo $i; ?>" id="total_profit_<?php echo $i; ?>" onfocus="cellFocused('I<?php echo $i; ?>', this)"></td>
                    <td><input type="text" class="cell-input" value="<?php echo htmlspecialchars($rowData['transaction_date']); ?>" data-field="transaction_date" data-row="<?php echo $i; ?>" onchange="saveCell(this)" onblur="saveCell(this)" onfocus="cellFocused('J<?php echo $i; ?>', this)" placeholder="YYYY-MM-DD"></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
    
    <div class="pagination">
        <?php if ($page > 1): ?>
        <button class="btn" onclick="goToPage(<?php echo $page - 1; ?>)"><i class="fas fa-chevron-left"></i> ቀዳሚ</button>
        <?php endif; ?>
        
        <span class="page-info">ገጽ <?php echo $page; ?> ከ 40 (ረድፍ <?php echo $start_row; ?> - <?php echo $end_row; ?>)</span>
        
        <?php if ($end_row < 2000): ?>
        <button class="btn" onclick="goToPage(<?php echo $page + 1; ?>)">ቀጣይ <i class="fas fa-chevron-right"></i></button>
        <?php endif; ?>
        
        <button class="btn btn-success" onclick="goToPage(40)">መጨረሻ</button>
    </div>
    
    <div class="status-bar">
        <span id="status">ዝግጁ</span>
        <span id="currentCell">A1</span>
        <span>ቅርንጫፍ: <?php echo htmlspecialchars($branch_name); ?></span>
        <span><?php echo $filled_rows; ?>/2000 ረድፎች ተሞልተዋል</span>
    </div>
    
    <script>
        let currentCell = 'A1';
        let currentInput = null;
        let autoSaveTimer = null;
        let profitUpdateTimer = null;
        
        // Calculate only ONE row (not all 50)
        function calculateRow(row) {
            const qty = parseFloat(document.querySelector(`tr[data-row="${row}"] input[data-field="quantity"]`).value) || 0;
            const buy = parseFloat(document.querySelector(`tr[data-row="${row}"] input[data-field="buying_price"]`).value) || 0;
            const sell = parseFloat(document.querySelector(`tr[data-row="${row}"] input[data-field="selling_price"]`).value) || 0;
            
            const totalCost = qty * buy;
            const profitUnit = sell - buy;
            const totalSell = qty * sell;
            const totalProfit = totalSell - totalCost;
            
            document.getElementById(`total_cost_${row}`).value = totalCost ? totalCost.toFixed(2) : '';
            document.getElementById(`profit_unit_${row}`).value = profitUnit ? profitUnit.toFixed(2) : '';
            document.getElementById(`total_selling_${row}`).value = totalSell ? totalSell.toFixed(2) : '';
            document.getElementById(`total_profit_${row}`).value = totalProfit ? totalProfit.toFixed(2) : '';
            
            saveField(row, 'total_cost', document.getElementById(`total_cost_${row}`).value);
            saveField(row, 'profit_per_unit', document.getElementById(`profit_unit_${row}`).value);
            saveField(row, 'total_selling', document.getElementById(`total_selling_${row}`).value);
            saveField(row, 'total_profit', document.getElementById(`total_profit_${row}`).value);
            
            refreshAfterProfitChange();
        }
        
        // Calculate ONLY current page (50 rows) - MUCH FASTER
        function calculateCurrentPageOnly() {
            document.getElementById('status').textContent = 'በማስላት ላይ...';
            const rows = document.querySelectorAll('tbody tr');
            let count = 0;
            rows.forEach(row => {
                const rowNum = parseInt(row.dataset.row);
                calculateRow(rowNum);
                count++;
            });
            document.getElementById('status').textContent = `${count} ረድፎች ተሰልተዋል`;
            showToast(`${count} ረድፎች ተሰልተዋል`);
            setTimeout(() => {
                document.getElementById('status').textContent = 'ዝግጁ';
            }, 1500);
            refreshProfitStats();
        }
        
        function saveCell(input) {
            const row = input.dataset.row;
            const field = input.dataset.field;
            const value = input.value;
            saveField(row, field, value);
        }
        
        function saveField(row, field, value) {
            const formData = new FormData();
            formData.append('action', 'save_cell');
            formData.append('row_id', row);
            formData.append('field', field);
            formData.append('value', value);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('status').textContent = 'ተቀምጧል';
                    clearTimeout(autoSaveTimer);
                    autoSaveTimer = setTimeout(() => {
                        document.getElementById('status').textContent = 'ዝግጁ';
                    }, 1000);
                }
            })
            .catch(error => console.error('Save error:', error));
        }
        
        function saveWorkbook() {
            document.getElementById('status').textContent = 'Saving...';
            const inputs = document.querySelectorAll('.cell-input:not([readonly])');
            let saved = 0;
            
            inputs.forEach(input => {
                if (input.value !== input.defaultValue) {
                    saveCell(input);
                    saved++;
                }
            });
            
            setTimeout(() => {
                document.getElementById('status').textContent = 'ሁሉም ተቀምጧል';
                showToast(`${saved} ረድፎች ተቀምጠዋል`);
                refreshProfitStats();
            }, 500);
        }
        
        function refreshProfitStats() {
            const btn = document.getElementById('refreshProfitBtn');
            const icon = btn.querySelector('i');
            icon.classList.add('fa-spin');
            btn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'get_profit_stats');
            formData.append('branch_id', '<?php echo $branch_id; ?>');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('grossProfit').textContent = data.total_profit;
                    document.getElementById('permWithdrawals').textContent = '- ' + data.perm_withdrawals;
                    document.getElementById('netProfit').textContent = data.net_profit;
                    
                    const netCard = document.getElementById('netProfitCard');
                    if (parseFloat(data.net_profit_raw) < 0) {
                        netCard.classList.add('negative');
                    } else {
                        netCard.classList.remove('negative');
                    }
                }
            })
            .finally(() => {
                icon.classList.remove('fa-spin');
                btn.disabled = false;
            });
        }
        
        function refreshAfterProfitChange() {
            if (profitUpdateTimer) clearTimeout(profitUpdateTimer);
            profitUpdateTimer = setTimeout(() => refreshProfitStats(), 1000);
        }
        
        function cellFocused(cellRef, input) {
            currentCell = cellRef;
            currentInput = input;
            document.getElementById('cellAddress').textContent = cellRef;
            document.getElementById('currentCell').textContent = cellRef;
            document.getElementById('formulaInput').value = input.value;
        }
        
        function applyFormula() {
            if (!currentInput) return;
            const formula = document.getElementById('formulaInput').value;
            currentInput.value = formula;
            if (formula.startsWith('=')) {
                const row = currentInput.dataset.row;
                calculateRow(row);
            }
            saveCell(currentInput);
        }
        
        function markAsSeen(messageId) {
            const formData = new FormData();
            formData.append('action', 'mark_seen');
            formData.append('message_id', messageId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById(`message_${messageId}`).remove();
                    showToast('መልእክት ታይቷል');
                }
            });
        }
        
        <?php if ($user_role == 'super_admin'): ?>
        function sendMessage() {
            const message = document.getElementById('newMessage').value.trim();
            const branchId = document.getElementById('messageBranch').value;
            
            if (!message) {
                alert('እባክዎ መልእክት ይጻፉ');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('branch_id', branchId || '0');
            formData.append('message', message);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('newMessage').value = '';
                    showToast('መልእክት ተልኳል');
                }
            });
        }
        <?php endif; ?>
        
        function clearAllData() {
            if (!confirm('እርግጠኛ ነዎት? ሁሉም data ይሰረዛል!')) return;
            
            const formData = new FormData();
            formData.append('action', 'clear_all');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function exportToExcel() {
            const table = document.getElementById('excelTable');
            let excelContent = `<html><head><meta charset="UTF-8"><title>Excel Export - <?php echo htmlspecialchars($branch_name); ?></title></head><body>
                <h2>የእቃዎች ዝርዝር - <?php echo htmlspecialchars($branch_name); ?></h2>
                <p>የተፈጠረበት ቀን: ${new Date().toLocaleString()}</p>
                <table border="1" cellpadding="5" cellspacing="0">`;
            
            const headers = ['#', 'የእቃው አይነት', 'ብዛት', 'የተገዛበት', 'ጠቅላላ ወጪ', 'የተሸጠበት', 'የአንዱ ትርፍ', 'ጠቅላላ ሽያጭ', 'ትርፍ', 'ቀን'];
            excelContent += '<thead><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>';
            
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                excelContent += '<tr>';
                const cells = row.querySelectorAll('td');
                cells.forEach(cell => {
                    const input = cell.querySelector('input');
                    const value = input ? input.value : cell.textContent.trim();
                    excelContent += `<td>${value || ''}</td>`;
                });
                excelContent += '</tr>';
            });
            
            excelContent += '</tbody></table></body></html>';
            
            const blob = new Blob([excelContent], { type: 'application/vnd.ms-excel' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `excel_branch_<?php echo $branch_id; ?>_${new Date().toISOString().slice(0,10)}.xlsx`;
            link.click();
            URL.revokeObjectURL(link.href);
            showToast('Excel File Downloaded');
        }
        
        function goToPage(page) {
            let url = 'excel_all_in_one.php?page=' + page;
            <?php if ($user_role == 'super_admin' && isset($_GET['branch_id'])): ?>
            url += '&branch_id=<?php echo intval($_GET['branch_id']); ?>';
            <?php endif; ?>
            window.location.href = url;
        }
        
        function changeBranch(branchId) {
            if (branchId) window.location.href = 'excel_all_in_one.php?branch_id=' + branchId + '&page=1';
        }
        
        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (!currentInput) return;
            
            const row = parseInt(currentInput.closest('tr').dataset.row);
            const field = currentInput.dataset.field;
            const fields = ['item_name', 'quantity', 'buying_price', 'total_cost', 'selling_price', 
                           'profit_per_unit', 'total_selling', 'total_profit', 'transaction_date'];
            
            let index = fields.indexOf(field);
            
            if (e.key === 'Enter') {
                e.preventDefault();
                if (row < <?php echo $end_row; ?>) {
                    const next = document.querySelector(`tr[data-row="${row + 1}"] input[data-field="${field}"]`);
                    if (next) next.focus();
                }
            }
            
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                if (index < fields.length - 1) {
                    const next = document.querySelector(`tr[data-row="${row}"] input[data-field="${fields[index + 1]}"]`);
                    if (next) next.focus();
                } else if (row < <?php echo $end_row; ?>) {
                    const next = document.querySelector(`tr[data-row="${row + 1}"] input[data-field="item_name"]`);
                    if (next) next.focus();
                }
            }
            
            if (e.key === 'Tab' && e.shiftKey) {
                e.preventDefault();
                if (index > 0) {
                    const prev = document.querySelector(`tr[data-row="${row}"] input[data-field="${fields[index - 1]}"]`);
                    if (prev) prev.focus();
                } else if (row > <?php echo $start_row; ?>) {
                    const prev = document.querySelector(`tr[data-row="${row - 1}"] input[data-field="transaction_date"]`);
                    if (prev) prev.focus();
                }
            }
            
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                saveWorkbook();
            }
            
            if (e.key === 'r' || e.key === 'R') {
                refreshProfitStats();
            }
        });
        
        // Auto-save every 60 seconds (reduced from 30)
        setInterval(saveWorkbook, 60000);
        
        // Refresh profit every 60 seconds
        setInterval(refreshProfitStats, 60000);
        
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('status').textContent = 'ዝግጁ';
            // DO NOT auto-calculate all rows - only calculate when needed
        });
    </script>
</body>
</html>
<?php 
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>