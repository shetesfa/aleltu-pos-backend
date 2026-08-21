<?php
session_start();
require_once 'config.php';

// Never expose PHP/database details to customers in production.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "User";
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

// Get branch info
$user_branch = getUserBranch($conn, $user_id);
$branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$branch_name = getCurrentBranchName($conn, $branch_id);

// ========== ETHIOPIAN DATE & TIME (JDN Standard) ==========
$eth_today = getEthiopianDate(date('Y-m-d'));
$eth_today_display = $eth_today['formatted'];
$eth_time_display = get_ethiopian_time_display(date('Y-m-d H:i:s'));
$ethiopian_datetime = [
    'date' => $eth_today['year'] . '-' . str_pad($eth_today['month'], 2, '0', STR_PAD_LEFT) . '-' . str_pad($eth_today['day'], 2, '0', STR_PAD_LEFT),
    'time' => $eth_time_display,
    'full_datetime' => $eth_today_display . ' ' . $eth_time_display,
    'year' => $eth_today['year'],
    'month' => $eth_today['month'],
    'day' => $eth_today['day'],
    'month_name' => $eth_today['month_name']
];

// ========== GET ALL PRODUCTS WITH STOCK - FIXED FOR ONLY_FULL_GROUP_BY ==========
// First check if is_active column exists
$check_is_active = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'is_active'");
$is_active_exists = $check_is_active && mysqli_num_rows($check_is_active) > 0;

// Build the WHERE clause based on column existence
$where_clause = "";
if ($is_active_exists) {
    $where_clause = "WHERE p.is_active = 1 AND p.branch_id = $branch_id";
} else {
    $where_clause = "WHERE p.branch_id = $branch_id";
}

// Get products with stock information - FIXED: Use MAX() for non-aggregated columns
$products_query = "SELECT 
    p.id,
    p.name,
    p.unit_price,
    COALESCE(SUM(si.current_stock), 0) as current_stock,
    MAX(si.unit) as unit,
    COUNT(DISTINCT si.seller_id) as seller_count
    FROM products p
    LEFT JOIN seller_inventory si ON p.name = si.item_name AND si.branch_id = $branch_id
    $where_clause
    GROUP BY p.id, p.name, p.unit_price
    ORDER BY p.name";

$products_result = mysqli_query($conn, $products_query);

if (!$products_result) {
    // Fallback query without GROUP BY issues
    $products_query = "SELECT 
        p.id,
        p.name,
        p.unit_price,
        0 as current_stock,
        'pcs' as unit,
        0 as seller_count
        FROM products p
        WHERE p.branch_id = $branch_id
        ORDER BY p.name";
    $products_result = mysqli_query($conn, $products_query);
    
    if (!$products_result) {
        $products_result = false;
        error_log("Failed to fetch products: " . mysqli_error($conn));
    }
}

// Store products for dropdown
$all_products = [];
$seller_stock = []; // For quick lookup
if ($products_result) {
    while($product = mysqli_fetch_assoc($products_result)) {
        $all_products[] = $product;
        $seller_stock[$product['name']] = [
            'stock' => $product['current_stock'],
            'unit' => $product['unit'] ?? 'pcs'
        ];
    }
    mysqli_data_seek($products_result, 0);
}

// ========== HANDLE RETURN SUBMISSION ==========
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_return'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new Exception('Invalid or expired request. Please try again.');
        }
        $product_name = mysqli_real_escape_string($conn, trim($_POST['product_name']));
        $quantity = floatval($_POST['quantity']);
        $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
        
        if (empty($product_name)) {
            throw new Exception("እባክዎ እቃ ይምረጡ");
        }
        
        if ($quantity <= 0) {
            throw new Exception("እባክዎ ትክክለኛ መጠን ያስገቡ");
        }
        
        // Get unit from seller_inventory
        $unit_query = "SELECT MAX(unit) as unit FROM seller_inventory 
                       WHERE item_name = '$product_name' AND branch_id = $branch_id LIMIT 1";
        $unit_result = mysqli_query($conn, $unit_query);
        $unit_row = mysqli_fetch_assoc($unit_result);
        $unit = $unit_row['unit'] ?? 'pcs';

        // FIXED: the old version checked the SUM of stock across all rows for
        // this item/branch ("total_stock >= quantity"), but then subtracted
        // the FULL quantity from only the single largest row. If stock was
        // split across more than one row (e.g. 30 in one row + 20 in
        // another = 50 total), returning 40 would pass the check but then
        // try "30 - 40 = -10" on that one row -> negative stock, silently.
        // Now: lock every row for this item/branch, and deduct across them
        // one by one until the full quantity is accounted for. If the true
        // total isn't enough, the whole return is rejected and nothing
        // changes (same as before the loop starts).
        mysqli_begin_transaction($conn);

        $rows_stmt = mysqli_prepare($conn,
            "SELECT id, current_stock FROM seller_inventory
             WHERE item_name = ? AND branch_id = ? AND current_stock > 0
             ORDER BY current_stock DESC FOR UPDATE");
        mysqli_stmt_bind_param($rows_stmt, "si", $product_name, $branch_id);
        mysqli_stmt_execute($rows_stmt);
        $rows_res = mysqli_stmt_get_result($rows_stmt);
        $inventory_rows = [];
        while ($rr = mysqli_fetch_assoc($rows_res)) $inventory_rows[] = $rr;
        mysqli_stmt_close($rows_stmt);

        if (empty($inventory_rows)) {
            mysqli_rollback($conn);
            throw new Exception("No inventory found for this product");
        }

        $total_available = array_sum(array_column($inventory_rows, 'current_stock'));
        if ($total_available < $quantity) {
            mysqli_rollback($conn);
            throw new Exception("❌ በቂ ክምችት የለም! አጠቃላይ ክምችት: " . number_format($total_available, 2) . " " . $unit);
        }

        $remaining_to_deduct = $quantity;
        foreach ($inventory_rows as $inv_row) {
            if ($remaining_to_deduct <= 0) break;
            $take = min(floatval($inv_row['current_stock']), $remaining_to_deduct);
            $new_row_stock = round(floatval($inv_row['current_stock']) - $take, 2);

            $upd_stmt = mysqli_prepare($conn,
                "UPDATE seller_inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($upd_stmt, "di", $new_row_stock, $inv_row['id']);
            mysqli_stmt_execute($upd_stmt);
            mysqli_stmt_close($upd_stmt);

            $remaining_to_deduct -= $take;
        }
        // Defensive: should be impossible given the check above, but never
        // silently continue with a partially-applied return.
        if ($remaining_to_deduct > 0.001) {
            mysqli_rollback($conn);
            throw new Exception("Stock changed while processing this return. Please try again.");
        }

        // FIXED: stock is now one shared pot (seller_id may be 0 on the
        // row), so looking up a "name" from seller_id no longer makes
        // sense. Record the person actually doing the return right now
        // (already available from their login session) instead.
        $return_seller_id = $user_id;
        $return_seller_name = $current_user;

        // Insert return record
        $gregorian_date = date('Y-m-d H:i:s');
        $ethiopian_date_display = $ethiopian_datetime['month_name'] . ' ' . $ethiopian_datetime['day'] . ', ' . $ethiopian_datetime['year'];
        $insert_query = "INSERT INTO product_returns 
                        (seller_id, seller_name, product_name, quantity, unit, reason, 
                         ethiopian_date, ethiopian_time, gregorian_date, branch_id) 
                        VALUES 
                        ('$return_seller_id', '$return_seller_name', '$product_name', '$quantity', '$unit', 
                         '$reason', '$ethiopian_date_display', '{$ethiopian_datetime['time']}', '$gregorian_date', $branch_id)";
        if (!mysqli_query($conn, $insert_query)) {
            mysqli_rollback($conn);
            throw new Exception("Failed to insert return record: " . mysqli_error($conn));
        }

        // Log in stock_logs (if table exists)
        $check_logs_table = "SHOW TABLES LIKE 'stock_logs'";
        $logs_table_exists = mysqli_query($conn, $check_logs_table);
        if ($logs_table_exists && mysqli_num_rows($logs_table_exists) > 0) {
            $log_query = "INSERT INTO stock_logs 
                         (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes, branch_id) 
                         VALUES 
                         ('$return_seller_id', '$return_seller_name', '$product_name', -$quantity, '$unit', 
                          'return', '$current_user', '$gregorian_date', 'ተመላሽ: $reason', $branch_id)";
            mysqli_query($conn, $log_query);
        }

        mysqli_commit($conn);
        $success_message = "✅ $quantity $unit በትክክል ተመልሷል";
    } catch (Exception $e) {
        if (isset($conn) && mysqli_connect_errno() === 0) {
            mysqli_rollback($conn);
        }
        $error_message = $e->getMessage();
    }
}

// ========== GET TODAY'S RETURNS ==========
$today_returns = [];
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$returns_query = "SELECT r.*, u.full_name as seller_full 
                  FROM product_returns r
                  LEFT JOIN users u ON r.seller_id = u.id
                  WHERE r.gregorian_date BETWEEN '$today_start' AND '$today_end' 
                  AND r.branch_id = $branch_id
                  ORDER BY r.gregorian_date DESC";
$returns_result = mysqli_query($conn, $returns_query);
if ($returns_result) {
    while($return = mysqli_fetch_assoc($returns_result)) {
        $today_returns[] = $return;
    }
}

// ========== GET ALL RETURNS HISTORY ==========
$all_returns = [];
$all_returns_query = "SELECT r.*, u.full_name as seller_full 
                      FROM product_returns r
                      LEFT JOIN users u ON r.seller_id = u.id
                      WHERE r.branch_id = $branch_id
                      ORDER BY r.gregorian_date DESC
                      LIMIT 100";
$all_returns_result = mysqli_query($conn, $all_returns_query);
if ($all_returns_result) {
    while($return = mysqli_fetch_assoc($all_returns_result)) {
        $all_returns[] = $return;
    }
}

// Calculate totals
$total_returns_today = count($today_returns);
$total_quantity_today = array_sum(array_column($today_returns, 'quantity'));
$total_returns_all = count($all_returns);
$total_quantity_all = array_sum(array_column($all_returns, 'quantity'));

// Helper functions
function safe_html($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function format_gregorian_time_12hr($datetime) {
    if (empty($datetime)) return '';
    return date('h:i A', strtotime($datetime));
}

function format_gregorian_datetime_12hr($datetime) {
    if (empty($datetime)) return '';
    return date('M j, Y h:i A', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>እቃ መመለሻ - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ========== MODERN CSS VARIABLES ========== */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body, button, input, select, textarea {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        i.fas, i.far, i.fab, i.fa {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "FontAwesome" !important;
            font-style: normal;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Role Badge */
        .role-badge {
            background: <?php 
                if ($user_role == 'super_admin') echo 'linear-gradient(135deg, #e74c3c, #c0392b)';
                elseif ($user_role == 'admin') echo 'linear-gradient(135deg, #f39c12, #e67e22)';
                else echo 'linear-gradient(135deg, #27ae60, #2ecc71)';
            ?>;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Branch Header */
        .branch-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .branch-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .branch-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .branch-details h2 {
            font-size: 22px;
            margin-bottom: 5px;
        }

        .branch-details p {
            font-size: 14px;
            opacity: 0.9;
        }

        .ethiopian-time {
            background: rgba(0, 0, 0, 0.51);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 8px 15px;
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 2px solid rgba(243, 243, 243, 0.9);
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 1);
        }

        .user-info {
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .logout-btn {
            background: linear-gradient(45deg, var(--danger), #ff6b6b);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(247, 37, 133, 0.3);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            max-width: 800px;
            margin: 25px auto;
        }

        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .stat-icon.today { background: #4361ee; }
        .stat-icon.total { background: #9b59b6; }
        .stat-icon.quantity { background: #f39c12; }
        .stat-icon.items { background: #27ae60; }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-sub {
            font-size: 13px;
            color: #95a5a6;
            margin-top: 5px;
        }

        /* Main Grid */
        .main-grid {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }

        /* Form Panel */
        .form-panel {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 800px;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .panel-title i {
            color: #f39c12;
        }

        .ethiopian-date-display {
            background: #fff8e1;
            border: 2px solid #f39c12;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .ethiopian-date-display i {
            font-size: 24px;
            color: #f39c12;
        }

        .date-info {
            flex: 1;
        }

        .date-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .date-value {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group label i {
            color: #3498db;
            margin-right: 5px;
            width: 20px;
        }

        .form-control, select, input, textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            font-family: 'Segoe UI', sans-serif;
        }

        .form-control:focus, select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23475669' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        /* Stock Info Card */
        .stock-info {
            background: #f0f7ff;
            border: 2px solid #3498db;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stock-info.active {
            display: flex;
        }

        .stock-label {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stock-label i {
            color: #3498db;
        }

        .stock-value {
            font-size: 24px;
            font-weight: 700;
            color: #3498db;
        }

        .stock-unit {
            font-size: 14px;
            color: #7f8c8d;
            margin-left: 4px;
        }

        .stock-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 20px;
            color: #856404;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .stock-warning.show {
            display: flex;
        }

        .stock-warning i {
            color: #f39c12;
            font-size: 18px;
        }

        .btn-return {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-return:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(243, 156, 18, 0.3);
        }

        .btn-return:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* History Panel */
        .history-panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: #7f8c8d;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .tab-btn:hover {
            background: #f0f0f0;
            color: #3498db;
        }

        .tab-btn.active {
            background: #3498db;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Responsive Table */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 10px;
            box-shadow: inset 0 0 0 1px #e2e8f0;
            background: white;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 620px;
        }

        .history-table th {
            text-align: left;
            padding: 12px 14px;
            background: #f8fafc;
            color: #1e293b;
            font-weight: 700;
            font-size: 13px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .history-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 14px;
            vertical-align: middle;
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .history-table tr:hover {
            background: #f8faff;
        }

        .reason-badge {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            color: #475569;
            display: inline-block;
        }

        .time-badge {
            font-size: 12px;
            color: #7f8c8d;
            white-space: nowrap;
        }

        .gregorian-time {
            font-size: 11px;
            color: #95a5a6;
            margin-top: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #bdc3c7;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }

        .top-nav-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            background: rgba(255, 255, 255, 0.95);
            padding: 12px 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .nav-btn {
            padding: 10px 18px;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            min-height: 42px;
        }

        .nav-btn.primary {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
        }

        .nav-btn.secondary {
            background: linear-gradient(135deg, #4b5563, #374151);
        }

        .nav-btn.danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            margin-left: auto;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* ========== COMPREHENSIVE RESPONSIVE MEDIA QUERIES ========== */
        @media (max-width: 992px) {
            body {
                padding: 15px;
            }
            .form-panel, .history-panel {
                padding: 22px;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 12px;
            }

            .container {
                width: 100%;
            }

            .top-nav-bar {
                padding: 10px;
                gap: 8px;
                margin-bottom: 15px;
            }

            .nav-btn {
                flex: 1 1 calc(50% - 6px);
                justify-content: center;
                padding: 10px 12px;
                font-size: 13px;
                min-height: 42px;
            }

            .nav-btn.danger {
                flex: 1 1 100%;
                margin-left: 0;
            }

            .branch-header {
                padding: 15px;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                border-radius: 12px 12px 0 0;
            }

            .branch-info {
                gap: 12px;
            }

            .branch-icon {
                width: 45px;
                height: 45px;
                font-size: 20px;
                flex-shrink: 0;
            }

            .branch-details h2 {
                font-size: 18px;
            }

            .branch-details p {
                font-size: 13px;
            }

            .ethiopian-time {
                align-self: flex-start;
                font-size: 13px;
                padding: 6px 12px;
            }

            .user-info {
                justify-content: space-between;
                font-size: 13px;
                padding: 8px 15px;
            }

            .ethiopian-date-display {
                padding: 12px 15px;
                margin-bottom: 15px;
            }

            .date-value {
                font-size: 16px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin: 15px auto;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-title {
                font-size: 12px;
            }

            .stat-value {
                font-size: 22px;
            }

            .stat-icon {
                width: 34px;
                height: 34px;
                font-size: 15px;
            }

            .form-panel, .history-panel {
                padding: 18px 14px;
                border-radius: 12px;
            }

            .panel-title {
                font-size: 17px;
                margin-bottom: 15px;
                padding-bottom: 10px;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-group label {
                font-size: 14px;
            }

            .form-control, select, input, textarea {
                padding: 12px 14px;
                font-size: 16px; /* 16px prevents iOS Safari auto-zoom */
                border-radius: 8px;
            }

            .btn-return {
                padding: 15px;
                font-size: 16px;
                border-radius: 8px;
            }

            .history-table th, .history-table td {
                padding: 10px 12px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 8px;
            }

            .top-nav-bar {
                padding: 8px;
                gap: 6px;
                border-radius: 10px;
            }

            .nav-btn {
                padding: 8px 10px;
                font-size: 12px;
                min-height: 38px;
                border-radius: 6px;
            }

            .branch-header {
                padding: 12px;
            }

            .branch-details h2 {
                font-size: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                margin: 12px auto;
            }

            .stat-card {
                padding: 10px 12px;
            }

            .stat-value {
                font-size: 18px;
            }

            .stat-sub {
                font-size: 11px;
            }

            .ethiopian-date-display {
                padding: 10px 12px;
                gap: 8px;
            }

            .ethiopian-date-display i {
                font-size: 18px;
            }

            .date-label {
                font-size: 10px;
            }

            .date-value {
                font-size: 14px;
            }

            .form-panel, .history-panel {
                padding: 14px 10px;
                border-radius: 10px;
            }

            .panel-title {
                font-size: 15px;
            }

            .stock-info {
                padding: 10px 12px;
            }

            .stock-value {
                font-size: 18px;
            }

            .stock-warning {
                padding: 10px 12px;
                font-size: 13px;
            }

            .btn-return {
                padding: 13px;
                font-size: 15px;
            }

            .history-table th, .history-table td {
                padding: 8px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Upper Navigation Bar -->
        <div class="top-nav-bar">
            <a href="seller_pos.php<?php echo ($user_role == 'super_admin' && isset($_GET['branch_id'])) ? '?branch_id=' . $_GET['branch_id'] : ''; ?>" class="nav-btn primary">
                <i class="fas fa-store"></i> ወደ መሸጫ ተመለስ
            </a>
            <a href="seller_receive_stock.php<?php echo ($user_role == 'super_admin' && isset($_GET['branch_id'])) ? '?branch_id=' . $_GET['branch_id'] : ''; ?>" class="nav-btn secondary">
                <i class="fas fa-truck-loading"></i> ክምችት መቀበል
            </a>
            <button class="nav-btn secondary" onclick="window.print()">
                <i class="fas fa-print"></i> ማተም
            </button>
            <button class="nav-btn danger" onclick="logout()">
                <i class="fas fa-sign-out-alt"></i> ውጣ
            </button>
        </div>

        <!-- Branch Header -->
        <div class="branch-header">
            <div class="branch-info">
                <div class="branch-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div class="branch-details">
                    <h2><?php echo htmlspecialchars($branch_name); ?></h2>
                    <p><i class="fas fa-map-marker-alt"></i> ቅርንጫፍ ኮድ: <?php echo $branch_id; ?></p>
                </div>
            </div>
            
            <div class="ethiopian-time">
                <i class="fas fa-clock"></i>
                <?php echo $ethiopian_datetime['time']; ?>
            </div>
            
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($current_user); ?></span>
                <span class="role-badge">
                    <?php 
                    if ($user_role == 'super_admin') echo 'ሱፐር አድሚን';
                    elseif ($user_role == 'admin') echo 'አድሚን';
                    else echo 'ሻጭ';
                    ?>
                </span>
            </div>
        </div>

        <!-- Ethiopian Date Display -->
        <div class="ethiopian-date-display" style="max-width: 800px; margin: 0 auto 20px auto;">
            <i class="fas fa-calendar-alt"></i>
            <div class="date-info">
                <div class="date-label">የኢትዮጵያ ቀን</div>
                <div class="date-value"><?php echo $eth_today_display; ?></div>
            </div>
            <div class="date-info" style="text-align: right;">
                <div class="date-label">የኢትዮጵያ ሰዓት</div>
                <div class="date-value"><?php echo $eth_time_display; ?></div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">ጠቅላላ የተመለሱ</span>
                    <div class="stat-icon total"><i class="fas fa-history"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($total_returns_all); ?></div>
                <div class="stat-sub">ጊዜ የተደረገ</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">ጠቅላላ የተመለሰ ብዛት</span>
                    <div class="stat-icon quantity"><i class="fas fa-cubes"></i></div>
                </div>
                <div class="stat-value"><?php echo ($total_quantity_all == (int)$total_quantity_all) ? number_format($total_quantity_all, 0) : number_format($total_quantity_all, 2); ?></div>
                <div class="stat-sub">ጠቅላላ እቃዎች</div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if($success_message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if($error_message): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Main Grid: Return Form -->
        <div class="main-grid">
            <div class="form-panel">
                <div class="panel-title">
                    <i class="fas fa-undo-alt"></i>
                    አዲስ እቃ መመለስ
                </div>

                <form method="POST" id="returnForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <div class="form-group">
                        <label><i class="fas fa-box"></i> እቃ ምረጥ</label>
                        <select name="product_name" id="productSelect" class="form-control" required onchange="updateStockInfo(this)">
                            <option value="">-- እቃ ይምረጡ --</option>
                            <?php foreach($all_products as $product): 
                                $stock_qty = isset($seller_stock[$product['name']]) ? $seller_stock[$product['name']]['stock'] : 0;
                                $unit = isset($seller_stock[$product['name']]) ? $seller_stock[$product['name']]['unit'] : 'pcs';
                                $has_stock = $stock_qty > 0;
                            ?>
                                <option value="<?php echo safe_html($product['name']); ?>" 
                                        data-stock="<?php echo $stock_qty; ?>"
                                        data-unit="<?php echo $unit; ?>"
                                        data-hasstock="<?php echo $has_stock ? 'yes' : 'no'; ?>">
                                    <?php echo safe_html($product['name']); ?> - 
                                    <?php if($has_stock): ?>
                                        (ክምችት: <?php echo $stock_qty; ?> <?php echo $unit; ?>)
                                    <?php else: ?>
                                        (ክምችት የለም)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="stockInfo" class="stock-info">
                        <span class="stock-label">
                            <i class="fas fa-database"></i> አጠቃላይ ክምችት
                        </span>
                        <div>
                            <span class="stock-value" id="currentStock">0</span>
                            <span class="stock-unit" id="stockUnit">ክፍል</span>
                        </div>
                    </div>

                    <div id="stockWarning" class="stock-warning">
                        <i class="fas fa-exclamation-triangle"></i> ይህ ምርት በአሁኑ ጊዜ ክምችት የለውም!
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-weight-hanging"></i> ምን ያህል ትመልሳለህ?</label>
                        <input type="number" name="quantity" id="returnQuantity" step="0.01" min="0.01" class="form-control" required placeholder="ለምሳሌ: 5">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> ምክንያት (አማራጭ)</label>
                        <input type="text" name="reason" class="form-control" placeholder="የመመለሻ ምክንያት / ለምን ይመለሳል?">
                    </div>

                    <button type="submit" name="submit_return" class="btn-return">
                        <i class="fas fa-undo-alt"></i> እቃ መልስ
                    </button>
                </form>
            </div>
        </div>

        <!-- All Returns History -->
        <div class="history-panel">
            <div class="panel-title">
                <i class="fas fa-history"></i>
                የተመለሱ እቃዎች ሙሉ ታሪክ
            </div>

            <?php if(!empty($all_returns)): ?>
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>የኢትዮጵያ ቀን</th>
                                <th>የኢትዮጵያ ሰዓት</th>
                                <th>የእቃ ስም</th>
                                <th>መጠን</th>
                                <th>ምክንያት</th>
                                <th>የላከው ሰው</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach($all_returns as $return): 
                                $eth_date = getEthiopianDate($return['gregorian_date']);
                                $eth_time = get_ethiopian_time_display($return['gregorian_date']);
                                $qty = floatval($return['quantity']);
                                $qty_display = ($qty == (int)$qty) ? number_format($qty, 0) : number_format($qty, 2);
                                $sender_name = !empty($return['seller_full']) ? $return['seller_full'] : (!empty($return['seller_name']) ? $return['seller_name'] : '-');
                            ?>
                            <tr>
                                <td>#<?php echo $counter++; ?></td>
                                <td><strong><?php echo $eth_date['formatted']; ?></strong></td>
                                <td class="time-badge"><?php echo $eth_time; ?></td>
                                <td><strong><?php echo safe_html($return['product_name']); ?></strong></td>
                                <td><span style="background: #eef2ff; color: #4361ee; padding: 4px 10px; border-radius: 6px; font-weight: bold;"><?php echo $qty_display . ' ' . safe_html($return['unit']); ?></span></td>
                                <td>
                                    <?php if(!empty($return['reason'])): ?>
                                        <span class="reason-badge"><?php echo safe_html($return['reason']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><i class="fas fa-user" style="color: #6366f1; margin-right: 5px;"></i><strong><?php echo safe_html($sender_name); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>ምንም የተመለሱ እቃዎች የሉም</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateStockInfo(select) {
            const stockInfo = document.getElementById('stockInfo');
            const stockWarning = document.getElementById('stockWarning');
            const currentStock = document.getElementById('currentStock');
            const stockUnit = document.getElementById('stockUnit');
            const returnQuantity = document.getElementById('returnQuantity');
            
            if (select && select.value) {
                const option = select.options[select.selectedIndex];
                const stock = parseFloat(option.dataset.stock);
                const unit = option.dataset.unit;
                const hasStock = option.dataset.hasstock;
                
                if (currentStock) currentStock.textContent = stock.toFixed(2);
                if (stockUnit) stockUnit.textContent = unit;
                
                if (hasStock === 'yes' && stock > 0) {
                    if (stockInfo) stockInfo.classList.add('active');
                    if (stockWarning) stockWarning.classList.remove('show');
                    if (returnQuantity) {
                        returnQuantity.max = stock;
                        returnQuantity.min = 0.01;
                        returnQuantity.placeholder = `ከፍተኛ: ${stock} ${unit}`;
                    }
                } else {
                    if (stockInfo) stockInfo.classList.remove('active');
                    if (stockWarning) stockWarning.classList.add('show');
                    if (returnQuantity) {
                        returnQuantity.max = '';
                        returnQuantity.min = 0.01;
                        returnQuantity.placeholder = 'ለምሳሌ: 5';
                    }
                }
            } else {
                if (stockInfo) stockInfo.classList.remove('active');
                if (stockWarning) stockWarning.classList.remove('show');
                if (returnQuantity) {
                    returnQuantity.max = '';
                    returnQuantity.placeholder = 'ለምሳሌ: 5';
                }
            }
        }

        document.getElementById('returnForm')?.addEventListener('submit', function(e) {
            const quantity = parseFloat(document.getElementById('returnQuantity')?.value);
            const currentStock = parseFloat(document.getElementById('currentStock')?.textContent);
            const productSelect = document.getElementById('productSelect');
            const stockInfo = document.getElementById('stockInfo');
            
            if (!productSelect || !productSelect.value) {
                e.preventDefault();
                alert('እባክዎ እቃ ይምረጡ');
                return;
            }
            
            if (isNaN(quantity) || quantity <= 0) {
                e.preventDefault();
                alert('እባክዎ ትክክለኛ መጠን ያስገቡ');
                return;
            }
            
            if (stockInfo && stockInfo.classList.contains('active') && !isNaN(currentStock) && quantity > currentStock) {
                e.preventDefault();
                alert(`ሊመልሱት የፈለጉት መጠን ካለዎት ክምችት በላይ ነው!\nአጠቃላይ ክምችት: ${currentStock}`);
            }
        });

        function logout() {
            if (confirm('Logout?')) {
                window.location.href = 'logout.php';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const productSelect = document.getElementById('productSelect');
            if (productSelect && productSelect.value) {
                updateStockInfo(productSelect);
            }
        });
    </script>
</body>
</html>
<?php 
// Close database connection
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
