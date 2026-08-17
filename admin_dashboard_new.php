<?php
session_start();
require_once 'config.php';
date_default_timezone_set('Africa/Addis_Ababa');

// Exact Ethiopian date with leap year (Pagume 6) support
function get_ethiopian_date_from_gregorian($gregorianDate) {
    if (empty($gregorianDate)) return '';
    try {
        $res = getEthiopianDate($gregorianDate);
        return $res['formatted'];
    } catch (Exception $e) {
        return date('Y-m-d', strtotime($gregorianDate));
    }
}

function formatTimeGregorian12Hour($datetime) {
    try {
        if (empty($datetime)) return '';
        $timestamp = strtotime($datetime);
        if (!$timestamp) return '';
        return date('h:i A', $timestamp);
    } catch (Exception $e) {
        error_log("Error formatting time: " . $e->getMessage());
        return '';
    }
}

function formatDateTimeGregorian12Hour($datetime) {
    try {
        if (empty($datetime)) return '';
        $timestamp = strtotime($datetime);
        if (!$timestamp) return '';
        return date('M j, Y h:i A', $timestamp);
    } catch (Exception $e) {
        error_log("Error formatting datetime: " . $e->getMessage());
        return '';
    }
}

function formatTimeShort($datetime) {
    try {
        if (empty($datetime)) return '';
        $timestamp = strtotime($datetime);
        if (!$timestamp) return '';
        return date('g:i A', $timestamp);
    } catch (Exception $e) {
        error_log("Error formatting time: " . $e->getMessage());
        return '';
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

// Get branch info with error handling
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Get all branches for super admin dropdown
$all_branches = [];
if ($user_role == 'super_admin') {
    $branches_query = "SELECT id, place_name FROM places WHERE status = 'active' ORDER BY place_name";
    $branches_result = mysqli_query($conn, $branches_query);
    if ($branches_result) {
        while($branch = mysqli_fetch_assoc($branches_result)) {
            $all_branches[] = $branch;
        }
    }
}

// Get filter period with validation
$allowed_periods = ['3days', '1week', '2weeks', '3weeks', '1month', '2months', '3months', '6months', '9months', '1year', 'custom'];
$period = isset($_GET['period']) && in_array($_GET['period'], $allowed_periods) ? $_GET['period'] : '1month';

$allowed_views = ['overview', 'products', 'product_performance', 'sellers'];
$view = isset($_GET['view']) && in_array($_GET['view'], $allowed_views) ? $_GET['view'] : 'overview';

$custom_start = isset($_GET['start_date']) ? mysqli_real_escape_string($conn, $_GET['start_date']) : '';
$custom_end = isset($_GET['end_date']) ? mysqli_real_escape_string($conn, $_GET['end_date']) : '';

// Date ranges based on period
$today = date('Y-m-d');

$period_options = [
    '3days' => ['days' => 3, 'text' => 'ባለፉት 3 ቀናት'],
    '1week' => ['days' => 7, 'text' => 'ባለፉት 1 ሳምንት'],
    '2weeks' => ['days' => 14, 'text' => 'ባለፉት 2 ሳምንታት'],
    '3weeks' => ['days' => 21, 'text' => 'ባለፉት 3 ሳምንታት'],
    '1month' => ['days' => 30, 'text' => 'ባለፉት 1 ወር'],
    '2months' => ['days' => 60, 'text' => 'ባለፉት 2 ወራት'],
    '3months' => ['days' => 90, 'text' => 'ባለፉት 3 ወራት'],
    '6months' => ['days' => 180, 'text' => 'ባለፉት 6 ወራት'],
    '9months' => ['days' => 270, 'text' => 'ባለፉት 9 ወራት'],
    '1year' => ['days' => 365, 'text' => 'ባለፉት 1 አመት'],
    'custom' => ['days' => 0, 'text' => 'Custom Date']
];

if ($period == 'custom' && !empty($custom_start) && !empty($custom_end)) {
    $date_from = $custom_start;
    $date_to = $custom_end;
    $period_text = "ከ $custom_start እስከ $custom_end";
    $prev_date_from = date('Y-m-d', strtotime($custom_start . ' -' . max(1, (strtotime($custom_end) - strtotime($custom_start)) / 86400) . ' days'));
    $prev_date_to = $custom_start;
} elseif (isset($period_options[$period])) {
    $days = $period_options[$period]['days'];
    $date_from = date('Y-m-d', strtotime("-{$days} days"));
    $date_to = $today;
    $period_text = $period_options[$period]['text'];
    $prev_date_from = date('Y-m-d', strtotime("-" . ($days * 2) . " days"));
    $prev_date_to = date('Y-m-d', strtotime("-" . ($days + 1) . " days"));
} else {
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = $today;
    $period_text = 'ባለፉት 1 ወር';
    $prev_date_from = date('Y-m-d', strtotime('-60 days'));
    $prev_date_to = date('Y-m-d', strtotime('-31 days'));
}

$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc = mysqli_real_escape_string($conn, $date_to);
$prev_date_from_esc = mysqli_real_escape_string($conn, $prev_date_from);
$prev_date_to_esc = mysqli_real_escape_string($conn, $prev_date_to);

// ========== 1. SUMMARY STATS WITH BRANCH FILTER ==========
$stats = [];

$sales_query = "SELECT 
    COUNT(DISTINCT id) as total_transactions,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(AVG(total_amount), 0) as avg_transaction
    FROM transactions 
    WHERE DATE(transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND branch_id = $current_branch_id";
$sales_result = mysqli_query($conn, $sales_query);

if ($sales_result) {
    $stats['sales'] = mysqli_fetch_assoc($sales_result);
} else {
    $stats['sales'] = ['total_transactions' => 0, 'total_revenue' => 0, 'avg_transaction' => 0];
    error_log("Sales query failed: " . mysqli_error($conn));
}

$prev_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as prev_revenue 
                     FROM transactions 
                     WHERE DATE(transaction_date) BETWEEN '$prev_date_from_esc' AND '$prev_date_to_esc'
                     AND branch_id = $current_branch_id";
$prev_sales_result = mysqli_query($conn, $prev_sales_query);
$prev_revenue = ($prev_sales_result && mysqli_num_rows($prev_sales_result) > 0) ? mysqli_fetch_assoc($prev_sales_result)['prev_revenue'] : 0;

$current_revenue = $stats['sales']['total_revenue'] ?? 0;
if ($prev_revenue > 0) {
    $growth = (($current_revenue - $prev_revenue) / $prev_revenue) * 100;
} else {
    $growth = $current_revenue > 0 ? 100 : 0;
}

$today_query = "SELECT COALESCE(SUM(total_amount), 0) as today_revenue 
                FROM transactions WHERE DATE(transaction_date) = CURDATE() AND branch_id = $current_branch_id";
$today_result = mysqli_query($conn, $today_query);
$stats['today'] = ($today_result && mysqli_num_rows($today_result) > 0) ? mysqli_fetch_assoc($today_result) : ['today_revenue' => 0];

// ========== 2. DAILY TRENDS FOR CHART ==========
$daily_query = "SELECT 
    DATE(transaction_date) as date,
    COUNT(DISTINCT id) as transactions,
    COALESCE(SUM(total_amount), 0) as revenue
    FROM transactions 
    WHERE DATE(transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND branch_id = $current_branch_id
    GROUP BY DATE(transaction_date)
    ORDER BY date";

$daily_result = mysqli_query($conn, $daily_query);
$chart_dates = [];
$chart_revenue = [];
$chart_transactions = [];

if ($daily_result) {
    while ($row = mysqli_fetch_assoc($daily_result)) {
        $chart_dates[] = $row['date'];
        $chart_revenue[] = (float)$row['revenue'];
        $chart_transactions[] = (int)$row['transactions'];
    }
}

// ========== 3. TOP SELLING PRODUCTS ==========
$top_products_query = "SELECT 
    ti.product_name,
    COUNT(DISTINCT t.id) as times_sold,
    COALESCE(SUM(ti.quantity), 0) as total_quantity,
    COALESCE(SUM(ti.subtotal), 0) as total_revenue,
    COALESCE(AVG(ti.unit_price), 0) as avg_selling_price,
    GROUP_CONCAT(DISTINCT ti.unit_price ORDER BY ti.unit_price SEPARATOR ', ') as all_prices
    FROM transactions t
    JOIN transaction_items ti ON t.id = ti.transaction_id
    WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND t.branch_id = $current_branch_id
    GROUP BY ti.product_name
    ORDER BY total_revenue DESC
    LIMIT 10";
$top_products_result = mysqli_query($conn, $top_products_query);

// ========== 4. SELLER PERFORMANCE ==========
$seller_performance_query = "SELECT 
    t.seller_id,
    t.seller_name,
    COALESCE(SUM(ti.subtotal), 0) as revenue
    FROM transactions t
    JOIN transaction_items ti ON t.id = ti.transaction_id
    WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND t.branch_id = $current_branch_id
    GROUP BY t.seller_id, t.seller_name
    ORDER BY revenue DESC";

$seller_performance_result = mysqli_query($conn, $seller_performance_query);

// ========== 5. PRODUCT PERFORMANCE ==========
$product_performance_query = "SELECT 
    ti.product_name,
    MAX(si.unit) as unit,
    MAX(si.price) as base_price,
    COALESCE(SUM(ti.quantity), 0) as total_sold_quantity,
    COUNT(DISTINCT t.id) as times_sold,
    COALESCE(SUM(ti.subtotal), 0) as total_revenue,
    COALESCE(AVG(ti.unit_price), 0) as avg_selling_price,
    COALESCE(MIN(ti.unit_price), 0) as min_selling_price,
    COALESCE(MAX(ti.unit_price), 0) as max_selling_price
    FROM transaction_items ti
    INNER JOIN transactions t ON ti.transaction_id = t.id 
        AND DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND t.branch_id = $current_branch_id
    LEFT JOIN seller_inventory si ON si.item_name = ti.product_name AND si.branch_id = $current_branch_id
    GROUP BY ti.product_name
    HAVING total_sold_quantity > 0
    ORDER BY total_revenue DESC";

$product_performance_result = mysqli_query($conn, $product_performance_query);

if (!$product_performance_result) {
    $product_performance_query = "SELECT 
        ti.product_name,
        '' as unit,
        NULL as base_price,
        COALESCE(SUM(ti.quantity), 0) as total_sold_quantity,
        COUNT(DISTINCT t.id) as times_sold,
        COALESCE(SUM(ti.subtotal), 0) as total_revenue,
        COALESCE(AVG(ti.unit_price), 0) as avg_selling_price,
        COALESCE(MIN(ti.unit_price), 0) as min_selling_price,
        COALESCE(MAX(ti.unit_price), 0) as max_selling_price
        FROM transaction_items ti
        INNER JOIN transactions t ON ti.transaction_id = t.id 
            AND DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
            AND t.branch_id = $current_branch_id
        GROUP BY ti.product_name
        HAVING total_sold_quantity > 0
        ORDER BY total_revenue DESC";
    
    $product_performance_result = mysqli_query($conn, $product_performance_query);
}

// ========== 6. RECENT ACTIVITY ==========
$recent_activity = [];

$recent_sales_query = "SELECT 
    'sale' as type,
    id,
    seller_name,
    CAST(total_amount AS DECIMAL(10,2)) as amount,
    transaction_date as date
    FROM transactions 
    WHERE DATE(transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND branch_id = $current_branch_id
    ORDER BY transaction_date DESC
    LIMIT 10";
$recent_sales_result = mysqli_query($conn, $recent_sales_query);
if ($recent_sales_result) {
    while ($row = mysqli_fetch_assoc($recent_sales_result)) {
        $row['ethiopian_date'] = get_ethiopian_date_from_gregorian($row['date']);
        $row['time_12hr'] = formatTimeGregorian12Hour($row['date']);
        $recent_activity[] = $row;
    }
}

$recent_return_query = "SELECT 
    'return' as type,
    id,
    seller_name,
    quantity,
    unit,
    product_name,
    gregorian_date as date,
    ethiopian_date,
    ethiopian_time
    FROM product_returns 
    WHERE DATE(gregorian_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
    AND branch_id = $current_branch_id
    ORDER BY gregorian_date DESC
    LIMIT 10";
$recent_return_result = mysqli_query($conn, $recent_return_query);
if ($recent_return_result) {
    while ($row = mysqli_fetch_assoc($recent_return_result)) {
        if (empty($row['ethiopian_date'])) {
            $row['ethiopian_date'] = get_ethiopian_date_from_gregorian($row['date']);
        }
        $row['time_12hr'] = formatTimeGregorian12Hour($row['date']);
        $recent_activity[] = $row;
    }
}

usort($recent_activity, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$recent_activity = array_slice($recent_activity, 0, 20);

function safe_html($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function format_with_unit($quantity, $unit) {
    if (empty($unit)) return number_format($quantity, 2) . ' ክፍል';
    
    $unit_map = [
        'kg' => 'ኪግ',
        'l' => 'ሊትር',
        'pcs' => 'ክፍል',
        'piece' => 'ክፍል',
        'm' => 'ሜትር',
        'cm' => 'ሴሜ',
        'g' => 'ግራም'
    ];
    
    $amharic_unit = $unit_map[$unit] ?? $unit;
    return number_format($quantity, 2) . ' ' . $amharic_unit;
}

$current_gregorian_time = date('h:i A');
$current_gregorian_date = date('F j, Y');
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>የሽያጭ ሪፖርት - <?php echo htmlspecialchars($current_branch_name); ?></title>
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Nyala', 'Abyssinica SIL', Tahoma, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ── Top Navigation Bar: stacked on mobile ── */
        .top-nav {
            background: white;
            border-radius: 15px;
            padding: 15px 18px;
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .nav-btn {
            padding: 10px 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            font-size: 14px;
            min-height: 44px;
            flex: 1;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        .nav-btn.back {
            background: linear-gradient(135deg, #6c5ce7, #a363d9);
        }

        .nav-btn.dashboard {
            background: linear-gradient(135deg, #00b894, #55efc4);
            color: #2d3436;
        }

        .branch-selector-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #f0f2f5;
            padding: 8px 12px;
            border-radius: 10px;
            flex-wrap: wrap;
        }

        .branch-selector-container label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }

        .branch-dropdown {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            width: 100%;
            min-height: 44px;
        }

        .branch-dropdown:focus {
            outline: none;
            border-color: #667eea;
        }

        .current-branch-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Header: stacked on mobile ── */
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 18px 20px;
            border-radius: 15px;
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            text-align: center;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .current-time {
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-family: monospace;
        }

        .period-selector {
            display: flex;
            gap: 6px;
            background: rgba(255,255,255,0.15);
            padding: 6px 10px;
            border-radius: 12px;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
        }

        .period-btn {
            padding: 8px 12px;
            border: none;
            background: transparent;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.3s;
            white-space: nowrap;
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .period-btn.active {
            background: white;
            color: #667eea;
            font-weight: 600;
        }

        .period-btn:hover:not(.active) {
            background: rgba(255,255,255,0.2);
        }

        /* Custom Date Filter */
        .custom-date-filter {
            background: white;
            padding: 16px;
            border-radius: 15px;
            margin-bottom: 18px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .custom-date-filter form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .date-input-group {
            width: 100%;
        }

        .date-input-group label {
            display: block;
            font-size: 13px;
            color: #4a5568;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .date-input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            min-height: 44px;
        }

        .date-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .filter-btn {
            width: 100%;
            padding: 12px 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
        }

        /* ── Stats Grid: 1 col on mobile ── */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #667eea15, #764ba215);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .stat-icon i {
            font-size: 20px;
            color: #667eea;
        }

        .stat-label {
            color: #718096;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: #2d3748;
            line-height: 1.2;
        }

        .stat-sub {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 6px;
        }

        .growth-positive {
            color: #48bb78;
            font-weight: 600;
        }

        .growth-negative {
            color: #f56565;
            font-weight: 600;
        }

        /* Chart Card */
        .chart-card {
            background: white;
            padding: 16px;
            border-radius: 15px;
            margin-bottom: 18px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .chart-header h3 {
            color: #2d3748;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .badge-warning { background: #f59e0b; }
        .badge-success { background: #10b981; }
        .badge-info { background: #3b82f6; }

        .chart-container {
            height: 250px;
            position: relative;
        }

        /* View Tabs */
        .view-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            background: white;
            padding: 8px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }

        .view-tab {
            flex: 1;
            padding: 10px 14px;
            border: none;
            background: transparent;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s;
            text-align: center;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .view-tab.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .view-tab i {
            margin-right: 6px;
        }

        /* Action Buttons */
        .header-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 100%;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.3s;
            min-height: 44px;
        }

        .btn-download {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16,185,129,0.3);
        }

        /* Table Sections */
        .table-section {
            background: white;
            padding: 16px;
            border-radius: 15px;
            margin-bottom: 18px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            overflow-x: auto;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .section-header h3 {
            color: #2d3748;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th {
            text-align: left;
            padding: 10px 8px;
            background: #f7fafc;
            color: #4a5568;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            color: #2d3748;
            white-space: nowrap;
        }

        tr:hover td {
            background: #f7fafc;
        }

        .rank {
            width: 26px;
            height: 26px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .type-sale {
            background: #dcfce7;
            color: #166534;
        }

        .type-return {
            background: #fef3c7;
            color: #92400e;
        }

        .text-success {
            color: #10b981;
            font-weight: 600;
        }

        .text-info {
            color: #3b82f6;
            font-weight: 600;
        }

        .summary-row {
            background: #f8fafc;
            font-weight: 600;
            border-top: 2px solid #e2e8f0;
        }

        .time-note {
            font-size: 12px;
            color: #718096;
            margin-top: 10px;
            padding: 12px;
            background: white;
            border-radius: 12px;
            text-align: center;
        }

        /* ── Progressive enhancement: ≥ 600px ── */
        @media (min-width: 600px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stat-value { font-size: 26px; }
            .nav-btn { flex: initial; }
            .branch-dropdown { width: auto; }
            .action-btn { width: auto; }
            .custom-date-filter form { flex-direction: row; align-items: flex-end; }
            .date-input-group { flex: 1; min-width: 150px; }
            .filter-btn { width: auto; }
        }

        /* ── Progressive enhancement: ≥ 768px ── */
        @media (min-width: 768px) {
            body { padding: 20px; }
            .top-nav { flex-direction: row; justify-content: space-between; align-items: center; }
            .branch-selector-container { justify-content: flex-start; }
            .header { flex-direction: row; justify-content: space-between; text-align: left; }
            .header h1 { font-size: 24px; }
            .period-selector { width: auto; }
            .chart-container { height: 300px; }
            .table-section { padding: 20px; }
            table { font-size: 14px; }
            th { padding: 15px 12px; }
            td { padding: 12px; }
        }

        /* ── Progressive enhancement: ≥ 1000px ── */
        @media (min-width: 1000px) {
            .stats-grid { grid-template-columns: repeat(4, 1fr); }
            .stat-value { font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Top Navigation Bar with Back Button and Branch Dropdown -->
        <div class="top-nav">
            <div class="nav-buttons">
                <a href="<?php echo ($user_role == 'super_admin') ? 'super_admin.php' : 'admin_dashboard.php'; ?>" class="nav-btn back">
                    <i class="fas fa-arrow-left"></i> ወደ ዳሽቦርድ ተመለስ
                </a>
                <a href="seller_pos.php" class="nav-btn">
                    <i class="fas fa-shopping-cart"></i> ወደ መሸጫ ገጽ
                </a>
                <a href="history.php" class="nav-btn">
                    <i class="fas fa-history"></i> የሽያጭ ታሪክ
                </a>
                <a href="advanced_report.php" class="nav-btn" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                    <i class="fas fa-filter"></i> ከፍተኛ ሪፖርት (Advanced)
                </a>
            </div>
            
            <?php if ($user_role == 'super_admin' && !empty($all_branches)): ?>
            <div class="branch-selector-container">
                <label><i class="fas fa-store"></i> ቅርንጫፍ ምረጥ:</label>
                <select class="branch-dropdown" onchange="changeBranch(this.value)">
                    <?php foreach($all_branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>" <?php echo ($current_branch_id == $branch['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch['place_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="current-branch-badge">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?>
            </div>
        </div>

        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i> የሽያጭ ሪፖርት
                <span class="current-time"><i class="far fa-clock"></i> <?php echo $current_gregorian_time; ?></span>
            </h1>
            <div class="period-selector">
                <a href="?period=3days&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '3days' ? 'active' : ''; ?>">3 ቀን</a>
                <a href="?period=1week&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '1week' ? 'active' : ''; ?>">1 ሳምንት</a>
                <a href="?period=2weeks&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '2weeks' ? 'active' : ''; ?>">2 ሳምንት</a>
                <a href="?period=1month&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '1month' ? 'active' : ''; ?>">1 ወር</a>
                <a href="?period=3months&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '3months' ? 'active' : ''; ?>">3 ወር</a>
                <a href="?period=6months&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '6months' ? 'active' : ''; ?>">6 ወር</a>
                <a href="?period=1year&view=<?php echo $view; ?>" class="period-btn <?php echo $period == '1year' ? 'active' : ''; ?>">1 አመት</a>
                <a href="?period=custom&view=<?php echo $view; ?>" class="period-btn <?php echo $period == 'custom' ? 'active' : ''; ?>">Custom</a>
            </div>
        </div>

        <!-- Custom Date Filter -->
        <div class="custom-date-filter" id="customDateFilter" style="<?php echo $period == 'custom' ? 'display: block;' : 'display: none;'; ?>">
            <form method="GET" action="">
                <input type="hidden" name="view" value="<?php echo $view; ?>">
                <div class="date-input-group">
                    <label>ከቀን (From)</label>
                    <input type="date" name="start_date" class="date-input" value="<?php echo $custom_start; ?>" required>
                </div>
                <div class="date-input-group">
                    <label>እስከ ቀን (To)</label>
                    <input type="date" name="end_date" class="date-input" value="<?php echo $custom_end; ?>" required>
                </div>
                <button type="submit" name="period" value="custom" class="filter-btn">
                    <i class="fas fa-filter"></i> አጣራ
                </button>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-label">ጠቅላላ ሽያጭ</div>
                <div class="stat-value"><?php echo number_format($current_revenue, 2); ?> ብር</div>
                <div class="stat-sub">
                    <?php echo $period_text; ?>
                    <?php if ($growth != 0): ?>
                        <span class="<?php echo $growth >= 0 ? 'growth-positive' : 'growth-negative'; ?>">
                            <i class="fas fa-<?php echo $growth >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            <?php echo number_format(abs($growth), 1); ?>%
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-sun"></i></div>
                <div class="stat-label">የዛሬ ሽያጭ</div>
                <div class="stat-value"><?php echo number_format($stats['today']['today_revenue'] ?? 0, 2); ?> ብር</div>
                <div class="stat-sub"><?php echo $current_gregorian_date; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-receipt"></i></div>
                <div class="stat-label">ጠቅላላ ግብይቶች</div>
                <div class="stat-value"><?php echo number_format($stats['sales']['total_transactions'] ?? 0); ?></div>
                <div class="stat-sub">አማካይ: <?php echo number_format($stats['sales']['avg_transaction'] ?? 0, 2); ?> ብር</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Active ሻጮች</div>
                <div class="stat-value">
                    <?php 
                    $active_sellers = 0;
                    if ($seller_performance_result) {
                        $active_sellers = mysqli_num_rows($seller_performance_result);
                    }
                    echo $active_sellers;
                    ?>
                </div>
                <div class="stat-sub">በዚህ ጊዜ ውስጥ</div>
            </div>
        </div>

        <!-- Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fas fa-chart-line" style="color: #667eea;"></i> ዕለታዊ ሽያጭ</h3>
                <span class="badge"><?php echo $period_text; ?></span>
            </div>
            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <!-- View Tabs -->
        <div class="view-tabs">
            <a href="?period=<?php echo $period; ?>&view=overview<?php echo $period == 'custom' ? '&start_date='.$custom_start.'&end_date='.$custom_end : ''; ?>" class="view-tab <?php echo $view == 'overview' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> አጠቃላይ እይታ
            </a>
            <a href="?period=<?php echo $period; ?>&view=products<?php echo $period == 'custom' ? '&start_date='.$custom_start.'&end_date='.$custom_end : ''; ?>" class="view-tab <?php echo $view == 'products' ? 'active' : ''; ?>">
                <i class="fas fa-trophy"></i> ምርጥ ምርቶች
            </a>
            <a href="?period=<?php echo $period; ?>&view=product_performance<?php echo $period == 'custom' ? '&start_date='.$custom_start.'&end_date='.$custom_end : ''; ?>" class="view-tab <?php echo $view == 'product_performance' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> የምርቶች አፈጻጸም
            </a>
            <a href="?period=<?php echo $period; ?>&view=sellers<?php echo $period == 'custom' ? '&start_date='.$custom_start.'&end_date='.$custom_end : ''; ?>" class="view-tab <?php echo $view == 'sellers' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> የሻጮች አፈጻጸም
            </a>
        </div>

        <!-- Action Buttons -->
        <div class="header-actions">
            <button class="action-btn btn-download" onclick="downloadReport()">
                <i class="fas fa-download"></i> ሪፖርት አውርድ (Excel)
            </button>
        </div>

        <?php if ($view == 'overview'): ?>
        <!-- Recent Activity -->
        <div class="table-section">
            <div class="section-header">
                <h3><i class="fas fa-history"></i> የቅርብ ጊዜ እንቅስቃሴ</h3>
                <span class="badge badge-info">የቅርብ 20</span>
            </div>
            <div class="time-note" style="margin-bottom: 15px;">
                <i class="fas fa-info-circle"></i> ሰዓቶች በግሪጎሪያን 12 ሰዓት ቅርጸት ይታያሉ
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ዓይነት</th>
                            <th>ሻጭ</th>
                            <th>ዝርዝር</th>
                            <th>ቀን (Date)</th>
                            <th>ሰዓት (Time)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_activity)): ?>
                            <?php foreach ($recent_activity as $activity): ?>
                             <tr>
                                 <td>
                                    <?php if ($activity['type'] == 'sale'): ?>
                                        <span class="type-badge type-sale"><i class="fas fa-shopping-cart"></i> ሽያጭ</span>
                                    <?php else: ?>
                                        <span class="type-badge type-return"><i class="fas fa-undo"></i> ተመላሽ</span>
                                    <?php endif; ?>
                                 </td>
                                 <td><?php echo safe_html($activity['seller_name'] ?? 'ሥርዓት'); ?></td>
                                 <td>
                                    <?php if ($activity['type'] == 'sale'): ?>
                                        <?php echo number_format($activity['amount'], 2); ?> ብር
                                    <?php else: ?>
                                        <?php echo format_with_unit($activity['quantity'], $activity['unit']); ?> - <?php echo safe_html($activity['product_name']); ?> ተመልሷል
                                    <?php endif; ?>
                                 </td>
                                 <td><?php echo safe_html($activity['gregorian_date'] ?? date('Y-m-d', strtotime($activity['date']))); ?></td>
                                 <td><?php echo safe_html($activity['time_12hr'] ?? ''); ?></td>
                             </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                             <tr><td colspan="5" style="text-align: center; padding: 40px;">ምንም እንቅስቃሴ አልተገኘም</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($view == 'products'): ?>
        <!-- Top Products -->
        <div class="table-section">
            <div class="section-header">
                <h3><i class="fas fa-trophy" style="color: #fbbf24;"></i> ከፍተኛ ሽያጭ ምርቶች</h3>
                <span class="badge badge-warning">ከፍተኛ 10</span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ምርት</th>
                            <th>የተሸጠበት ጊዜ</th>
                            <th>ጠቅላላ ብዛት</th>
                            <th>ጠቅላላ ገቢ</th>
                            <th>የዋጋ ክልል</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        if ($top_products_result && mysqli_num_rows($top_products_result) > 0):
                            while ($product = mysqli_fetch_assoc($top_products_result)): 
                        ?>
                            <tr>
                                <td><span class="rank"><?php echo $rank++; ?></span></td>
                                <td><strong><?php echo safe_html($product['product_name']); ?></strong></td>
                                <td><?php echo (int)$product['times_sold']; ?> ጊዜ</td>
                                <td><?php echo (int)$product['total_quantity']; ?> ክፍል</td>
                                <td class="text-success"><?php echo number_format($product['total_revenue'], 2); ?> ብር</td>
                                <td>
                                    <?php 
                                    $prices = explode(', ', $product['all_prices']);
                                    $unique_prices = array_unique($prices);
                                    echo implode(' - ', array_slice($unique_prices, 0, 2));
                                    if (count($unique_prices) > 2) echo ' ...';
                                    ?> ብር
                                  </td>
                            </tr>
                        <?php 
                            endwhile;
                        else: 
                        ?>
                            <tr><td colspan="6" style="text-align: center; padding: 40px;">ምንም data አልተገኘም</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($view == 'product_performance'): ?>
        <!-- Product Performance -->
        <div class="table-section">
            <div class="section-header">
                <h3><i class="fas fa-chart-pie"></i> የምርቶች አፈጻጸም - <?php echo htmlspecialchars($current_branch_name); ?></h3>
                <span class="badge badge-success"><?php echo $period_text; ?></span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ምርት</th>
                            <th>የተሸጠው ብዛት</th>
                            <th>የሽያጭ ጊዜ</th>
                            <th>ጠቅላላ ገቢ</th>
                            <th>አማካይ ዋጋ</th>
                            <th>የዋጋ ልዩነት</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_sales_all = 0;
                        
                        if ($product_performance_result && mysqli_num_rows($product_performance_result) > 0):
                            while ($product = mysqli_fetch_assoc($product_performance_result)):
                                $price_variation = $product['max_selling_price'] - $product['min_selling_price'];
                                $total_sales_all += $product['total_revenue'];
                        ?>
                            <tr>
                                <td><strong><?php echo safe_html($product['product_name']); ?></strong></td>
                                <td><?php echo format_with_unit($product['total_sold_quantity'], $product['unit']); ?></td>
                                <td><?php echo (int)$product['times_sold']; ?> ጊዜ</td>
                                <td class="text-success"><?php echo number_format($product['total_revenue'], 2); ?> ብር</td>
                                <td>
                                    <?php echo number_format($product['avg_selling_price'], 2); ?> ብር
                                    <?php if (!empty($product['base_price']) && $product['base_price'] > 0): ?>
                                        <br><small class="unit-badge">የተቀመጠ: <?php echo number_format($product['base_price'], 2); ?> ብር</small>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <?php if ($price_variation > 0): ?>
                                        <span class="text-info">
                                            <?php echo number_format($product['min_selling_price'], 2); ?> - <?php echo number_format($product['max_selling_price'], 2); ?> ብር
                                        </span>
                                    <?php else: ?>
                                        ቋሚ
                                    <?php endif; ?>
                                 </td>
                            </tr>
                        <?php 
                            endwhile;
                        ?>
                        <tr class="summary-row">
                            <td><strong>ድምር</strong></td>
                            <td><strong><?php echo number_format(mysqli_num_rows($product_performance_result)); ?> ምርቶች</strong></td>
                            <td>-</td>
                            <td><strong><?php echo number_format($total_sales_all, 2); ?> ብር</strong></td>
                            <td>-</td>
                            <td>-</td>
                          </tr>
                        <?php else: ?>
                             <tr><td colspan="6" style="text-align: center; padding: 40px;">በዚህ ጊዜ ውስጥ ምንም የተሸጡ ምርቶች የሉም</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($view == 'sellers'): ?>
        <!-- Seller Performance -->
        <div class="table-section">
            <div class="section-header">
                <h3><i class="fas fa-medal" style="color: #667eea;"></i> የሻጮች አፈጻጸም - <?php echo htmlspecialchars($current_branch_name); ?></h3>
                <span class="badge"><?php echo $period_text; ?></span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ሻጭ</th>
                            <th>ጠቅላላ ገቢ</th>
                        </table>
                    </thead>
                    <tbody>
                        <?php if ($seller_performance_result && mysqli_num_rows($seller_performance_result) > 0): ?>
                            <?php while ($seller = mysqli_fetch_assoc($seller_performance_result)): ?>
                             <tr>
                                <td><strong><?php echo safe_html($seller['seller_name']); ?></strong></td>
                                <td class="text-success"><?php echo number_format($seller['revenue'], 2); ?> ብር</td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                             <tr><td colspan="2" style="text-align: center; padding: 40px;">ምንም data አልተገኘም</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="time-note">
            <i class="fas fa-clock"></i> ሁሉም ሰዓቶች በግሪጎሪያን 12 ሰዓት ቅርጸት ይታያሉ | 
            <i class="fas fa-store"></i> ቅርንጫፍ: <?php echo htmlspecialchars($current_branch_name); ?> |
            <i class="fas fa-user"></i> ተጠቃሚ: <?php echo htmlspecialchars($admin_name); ?>
        </div>
    </div>

    <script>
        // Force scroll to top on page load
        window.onload = function() {
            window.scrollTo(0, 0);
        };
        
        window.onpageshow = function(event) {
            if (event.persisted) {
                window.scrollTo(0, 0);
            }
        };

        // Change branch function for super admin
        function changeBranch(branchId) {
            if (branchId) {
                let url = window.location.pathname + '?branch_id=' + branchId;
                // Preserve current period and view
                const period = '<?php echo $period; ?>';
                const view = '<?php echo $view; ?>';
                url += '&period=' + period + '&view=' + view;
                window.location.href = url;
            }
        }

        <?php if (!empty($chart_dates) && !empty($chart_revenue)): ?>
        // Sales Chart
        const ctx1 = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_dates); ?>,
                datasets: [{
                    label: 'ዕለታዊ ሽያጭ (ብር)',
                    data: <?php echo json_encode($chart_revenue); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: 'white',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'ሽያጭ: ' + new Intl.NumberFormat().format(context.raw) + ' ብር';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat().format(value) + ' ብር';
                            },
                            maxTicksLimit: 6
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            maxTicksLimit: 8,
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php else: ?>
        const chartContainer = document.getElementById('salesChart');
        if (chartContainer && chartContainer.parentElement) {
            chartContainer.parentElement.innerHTML = '<div style="text-align: center; padding: 60px; color: #999;"><i class="fas fa-chart-line" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i><div>ምንም data አልተገኘም</div><small>ለዚህ ጊዜ ክልል ምንም ሽያጭ የለም</small></div>';
        }
        <?php endif; ?>

        // Download Report as Excel
        function downloadReport() {
            const view = '<?php echo $view; ?>';
            const period = '<?php echo $period; ?>';
            const startDate = '<?php echo $custom_start; ?>';
            const endDate = '<?php echo $custom_end; ?>';
            const branchId = '<?php echo $current_branch_id; ?>';
            
            let url = 'export_report_excel.php?view=' + view + '&period=' + period + 
                      (startDate ? '&start_date=' + startDate : '') + 
                      (endDate ? '&end_date=' + endDate : '') +
                      '&branch_id=' + branchId;
            
            window.location.href = url;
        }

        // Auto-refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
<?php 
// Close database connection
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
