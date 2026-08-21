<?php
session_start();
require_once 'config.php';

// Set charset for Amharic
mysqli_set_charset($conn, "utf8mb4");

// Check login
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'super_admin')) {
    header("Location: index.php");
    exit();
}

// Get parameters
$view = $_GET['view'] ?? 'overview';
$period = $_GET['period'] ?? '1month';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';

// Get branch info
$current_branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
if ($_SESSION['role'] === 'super_admin' && isset($_GET['branch_id']) && intval($_GET['branch_id']) > 0) {
    $current_branch_id = intval($_GET['branch_id']);
}
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Date ranges based on period
$today = date('Y-m-d');

// Define period options
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
    '1year' => ['days' => 365, 'text' => 'ባለፉት 1 አመት']
];

// Set date range
if ($period == 'custom' && !empty($custom_start) && !empty($custom_end)) {
    $date_from = $custom_start;
    $date_to = $custom_end;
    $period_text = "ከ $custom_start እስከ $custom_end";
} elseif (isset($period_options[$period])) {
    $days = $period_options[$period]['days'];
    $date_from = date('Y-m-d', strtotime("-{$days} days"));
    $date_to = $today;
    $period_text = $period_options[$period]['text'];
} else {
    $period = '1month';
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = $today;
    $period_text = 'ባለፉት 1 ወር';
}

$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc = mysqli_real_escape_string($conn, $date_to);

// Set filename
$filename = 'report_' . $view . '_' . date('Y-m-d') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for Excel to handle UTF-8 properly
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Generate report based on view
if ($view == 'overview') {
    // Recent Activity Report
    fputcsv($output, ['የቅርብ ጊዜ እንቅስቃሴ', $period_text]);
    fputcsv($output, []);
    fputcsv($output, ['ዓይነት', 'ሻጭ', 'ዝርዝር', 'ቀን']);
    
    $query = "SELECT 
        'ሽያጭ' as type,
        seller_name,
        CONCAT(total_amount, ' ብር') as details,
        transaction_date as date
        FROM transactions 
        WHERE DATE(transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        UNION ALL
        SELECT 
        'ክምችት' as type,
        seller_name,
        CONCAT(quantity, ' ', unit, ' - ', item_name) as details,
        date_added as date
        FROM stock_logs 
        WHERE DATE(date_added) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        UNION ALL
        SELECT 
        'ተመላሽ' as type,
        seller_name,
        CONCAT(quantity, ' ', unit, ' - ', product_name) as details,
        gregorian_date as date
        FROM product_returns 
        WHERE DATE(gregorian_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        ORDER BY date DESC
        LIMIT 100";
    
    $result = mysqli_query($conn, $query);
    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, $row);
    }
}
elseif ($view == 'products') {
    // Top Products Report
    fputcsv($output, ['ከፍተኛ ሽያጭ ምርቶች', $period_text]);
    fputcsv($output, []);
    fputcsv($output, ['ደረጃ', 'ምርት', 'የተሸጠበት ጊዜ', 'ጠቅላላ ብዛት', 'ጠቅላላ ገቢ', 'አማካይ ዋጋ']);
    
    $query = "SELECT 
        ti.product_name,
        COUNT(DISTINCT t.id) as times_sold,
        SUM(ti.quantity) as total_quantity,
        SUM(ti.subtotal) as total_revenue,
        AVG(ti.unit_price) as avg_price
        FROM transactions t
        JOIN transaction_items ti ON t.id = ti.transaction_id
        WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND t.branch_id = $current_branch_id
        GROUP BY ti.product_name
        ORDER BY total_revenue DESC
        LIMIT 50";
    
    $result = mysqli_query($conn, $query);
    $rank = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $rank++,
            $row['product_name'],
            $row['times_sold'] . ' ጊዜ',
            round($row['total_quantity'], 2) . ' ክፍሎች',
            round($row['total_revenue'], 2) . ' ብር',
            round($row['avg_price'], 2) . ' ብር'
        ]);
    }
}
elseif ($view == 'product_performance') {
    // Product Performance Report
    fputcsv($output, ['የምርቶች አፈጻጸም', $period_text]);
    fputcsv($output, []);
    fputcsv($output, ['ምርት', 'የተሸጠው ብዛት', 'የሽያጭ ጊዜ', 'ጠቅላላ ገቢ', 'አማካይ ዋጋ', 'ዝቅተኛ ዋጋ', 'ከፍተኛ ዋጋ', 'የተመለሰ ብዛት', 'የተመላሽ መጠን']);
    
    // Get products with sales
    $query = "SELECT 
        p.name as product_name,
        COALESCE(SUM(ti.quantity), 0) as total_sold,
        COUNT(DISTINCT t.id) as times_sold,
        COALESCE(SUM(ti.subtotal), 0) as total_revenue,
        COALESCE(AVG(ti.unit_price), 0) as avg_price,
        COALESCE(MIN(ti.unit_price), 0) as min_price,
        COALESCE(MAX(ti.unit_price), 0) as max_price
        FROM products p
        LEFT JOIN transaction_items ti ON ti.product_name = p.name
        LEFT JOIN transactions t ON ti.transaction_id = t.id 
            AND DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
            AND t.branch_id = $current_branch_id
        WHERE p.branch_id = $current_branch_id
        GROUP BY p.id, p.name
        HAVING total_sold > 0
        ORDER BY total_revenue DESC";
    
    $result = mysqli_query($conn, $query);
    
    // Get returns data
    $returns_query = "SELECT 
        product_name,
        SUM(quantity) as total_returns,
        unit
        FROM product_returns 
        WHERE DATE(gregorian_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        GROUP BY product_name, unit";
    $returns_result = mysqli_query($conn, $returns_query);
    $returns_data = [];
    while ($row = mysqli_fetch_assoc($returns_result)) {
        $returns_data[$row['product_name']] = [
            'quantity' => $row['total_returns'],
            'unit' => $row['unit']
        ];
    }
    
    while ($row = mysqli_fetch_assoc($result)) {
        $return_qty = $returns_data[$row['product_name']]['quantity'] ?? 0;
        $return_rate = $row['total_sold'] > 0 ? ($return_qty / $row['total_sold']) * 100 : 0;
        
        fputcsv($output, [
            $row['product_name'],
            round($row['total_sold'], 2) . ' ክፍሎች',
            $row['times_sold'] . ' ጊዜ',
            round($row['total_revenue'], 2) . ' ብር',
            round($row['avg_price'], 2) . ' ብር',
            round($row['min_price'], 2) . ' ብር',
            round($row['max_price'], 2) . ' ብር',
            $return_qty > 0 ? round($return_qty, 2) . ' ' . ($returns_data[$row['product_name']]['unit'] ?? 'ክፍል') : 'የለም',
            round($return_rate, 1) . '%'
        ]);
    }
}
elseif ($view == 'sellers') {
    // Seller Performance Report
    fputcsv($output, ['የሻጮች አፈጻጸም', $period_text]);
    fputcsv($output, []);
    fputcsv($output, ['ሻጭ', 'ግብይቶች', 'የተሸጡ እቃዎች', 'ጠቅላላ ገቢ', 'አማካይ ሽያጭ']);
    
    $query = "SELECT 
        t.seller_name,
        COUNT(DISTINCT t.id) as transactions,
        COALESCE(SUM(ti.quantity), 0) as items_sold,
        COALESCE(SUM(ti.subtotal), 0) as revenue
        FROM transactions t
        JOIN transaction_items ti ON t.id = ti.transaction_id
        WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND t.branch_id = $current_branch_id
        GROUP BY t.seller_id, t.seller_name
        ORDER BY revenue DESC";
    
    $result = mysqli_query($conn, $query);
    while ($row = mysqli_fetch_assoc($result)) {
        $avg_sale = $row['transactions'] > 0 ? $row['revenue'] / $row['transactions'] : 0;
        fputcsv($output, [
            $row['seller_name'],
            $row['transactions'],
            round($row['items_sold'], 2) . ' ክፍሎች',
            round($row['revenue'], 2) . ' ብር',
            round($avg_sale, 2) . ' ብር'
        ]);
    }
}

fclose($output);
mysqli_close($conn);
exit();