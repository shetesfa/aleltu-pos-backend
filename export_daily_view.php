<?php
// Daily report view - clean table view with exact money
session_start();
require_once 'config.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

// Get branch info
$user_branch = getUserBranch($conn, $user_id);
$branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$branch_name = getCurrentBranchName($conn, $branch_id);

// ========== ETHIOPIAN CALENDAR FUNCTIONS - FIXED FOR 2018 ==========
function gregorian_to_ethiopian($gregorian_date) {
    try {
        // Use Africa/Addis_Ababa timezone for correct local time
        $date = new DateTime($gregorian_date, new DateTimeZone('Africa/Addis_Ababa'));
        
        $year = (int)$date->format('Y');
        $month = (int)$date->format('m');
        $day = (int)$date->format('d');
        $hour = (int)$date->format('H');
        $minute = (int)$date->format('i');
        
        // Ethiopian months
        $ethiopian_months = [
            "መስከረም", "ጥቅምት", "ህዳር", "ታህሳስ", "ጥር", "የካቲት",
            "መጋቢት", "ሚያዝያ", "ግንቦት", "ሰኔ", "ሐምሌ", "ነሐሴ", "ጳጉሜ"
        ];
        
        // Ethiopian year calculation
        // For dates before September 11, Ethiopian year = Gregorian year - 8
        // For dates on or after September 11, Ethiopian year = Gregorian year - 7
        $ethiopian_year = $year - 8;
        
        // Check if we're after Ethiopian New Year (September 11/12)
        $is_leap_year = (($year % 4 == 0) && ($year % 100 != 0)) || ($year % 400 == 0);
        $new_year_day = $is_leap_year ? 12 : 11;
        
        if ($month > 9 || ($month == 9 && $day >= $new_year_day)) {
            $ethiopian_year = $year - 7;
        }
        
        // Create Ethiopian New Year date
        $new_year_date = new DateTime("$year-09-{$new_year_day}", new DateTimeZone('Africa/Addis_Ababa'));
        
        // If current date is before New Year, use previous year's New Year
        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $new_year_date = new DateTime(($year - 1) . "-09-{$new_year_day}", new DateTimeZone('Africa/Addis_Ababa'));
        }
        
        // Calculate days difference
        $diff = $date->diff($new_year_date);
        $days_from_new_year = $diff->days;
        
        // Calculate Ethiopian month and day
        $ethiopian_month = floor($days_from_new_year / 30) + 1;
        $ethiopian_day = ($days_from_new_year % 30) + 1;
        
        // Handle Pagume (13th month)
        if ($ethiopian_month == 13) {
            $max_pagume_days = ($ethiopian_year % 4 == 3) ? 6 : 5;
            $ethiopian_day = min($ethiopian_day, $max_pagume_days);
        }
        
        // Ensure month is within range (1-13)
        if ($ethiopian_month > 13) {
            $ethiopian_month = 13;
        }
        
        return [
            'year' => $ethiopian_year,
            'month' => $ethiopian_month,
            'month_name' => $ethiopian_months[$ethiopian_month - 1] ?? '',
            'day' => $ethiopian_day,
            'full_date' => sprintf("%d-%02d-%02d", $ethiopian_year, $ethiopian_month, $ethiopian_day),
            'time' => sprintf("%02d:%02d", $hour, $minute)
        ];
    } catch (Exception $e) {
        // Fallback for 2026-02-28 (Gregorian) = 2018-06-21 (Ethiopian)
        return [
            'year' => 2018,
            'month' => 6,
            'month_name' => 'የካቲት',
            'day' => 21,
            'full_date' => '2018-06-21',
            'time' => date('H:i')
        ];
    }
}

function ethiopian_to_gregorian($ethiopian_date) {
    try {
        list($year, $month, $day) = explode('-', $ethiopian_date);
        $year = (int)$year;
        $month = (int)$month;
        $day = (int)$day;
        
        // Ethiopian to Gregorian year conversion
        // If month is before Meskerem (month 1), use year+7, otherwise year+8
        $gregorian_year = $year + 7;
        
        // Ethiopian New Year is September 11/12 in Gregorian
        $is_eth_leap = ($year % 4 == 3);
        $new_year_day = $is_eth_leap ? 12 : 11;
        
        // Create Gregorian date starting from Ethiopian New Year
        $gregorian_date = new DateTime("$gregorian_year-09-$new_year_day", new DateTimeZone('Africa/Addis_Ababa'));
        
        // Add months and days
        $days_to_add = (($month - 1) * 30) + ($day - 1);
        if ($days_to_add > 0) {
            $gregorian_date->modify("+{$days_to_add} days");
        }
        
        return $gregorian_date->format('Y-m-d');
    } catch (Exception $e) {
        // For 2018-06-21 (Ethiopian) = 2026-02-28 (Gregorian)
        return '2026-02-28';
    }
}

// ========== GET CURRENT ETHIOPIAN DATE - FIXED ==========
// Get current time in Ethiopia
$current_ethiopian_time = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$current_gregorian_for_eth = $current_ethiopian_time->format('Y-m-d H:i:s');
$current_ethiopian = gregorian_to_ethiopian($current_gregorian_for_eth);

// ========== HANDLE DATE SELECTION ==========
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_ethiopian['full_date'];

// Parse selected date
list($sel_year, $sel_month, $sel_day) = explode('-', $selected_date);
$sel_year = (int)$sel_year;
$sel_month = (int)$sel_month;
$sel_day = (int)$sel_day;

// Calculate previous day
$prev_day = $sel_day - 1;
$prev_month = $sel_month;
$prev_year = $sel_year;

if ($prev_day < 1) {
    $prev_month--;
    if ($prev_month < 1) {
        $prev_month = 13;
        $prev_year--;
    }
    $days_in_prev_month = ($prev_month == 13) ? (($prev_year % 4 == 3) ? 6 : 5) : 30;
    $prev_day = $days_in_prev_month;
}
$prev_date = sprintf("%d-%02d-%02d", $prev_year, $prev_month, $prev_day);

// Calculate next day
$days_in_current_month = ($sel_month == 13) ? (($sel_year % 4 == 3) ? 6 : 5) : 30;
$next_day = $sel_day + 1;
$next_month = $sel_month;
$next_year = $sel_year;

if ($next_day > $days_in_current_month) {
    $next_month++;
    $next_day = 1;
    if ($next_month > 13) {
        $next_month = 1;
        $next_year++;
    }
}
$next_date = sprintf("%d-%02d-%02d", $next_year, $next_month, $next_day);

// Month names
$ethiopian_months = [
    1 => "መስከረም", 2 => "ጥቅምት", 3 => "ህዳር", 4 => "ታህሳስ",
    5 => "ጥር", 6 => "የካቲት", 7 => "መጋቢት", 8 => "ሚያዝያ",
    9 => "ግንቦት", 10 => "ሰኔ", 11 => "ሐምሌ", 12 => "ነሐሴ", 13 => "ጳጉሜ"
];

// ========== GET DATE RANGE FOR SELECTED DAY WITH BRANCH FILTER ==========
$start_greg = ethiopian_to_gregorian($selected_date) . ' 00:00:00';
$end_greg = ethiopian_to_gregorian($selected_date) . ' 23:59:59';

// ========== FETCH ALL TRANSACTIONS FOR SELECTED DAY WITH BRANCH FILTER ==========
$query = "SELECT 
            t.id,
            t.transaction_date,
            t.payment_method,
            t.seller_name,
            ti.product_name,
            ti.quantity,
            ti.unit_price,
            ti.subtotal
          FROM transactions t
          INNER JOIN transaction_items ti ON t.id = ti.transaction_id
          WHERE t.transaction_date BETWEEN '$start_greg' AND '$end_greg'
          AND t.branch_id = $branch_id
          ORDER BY t.transaction_date ASC";

$result = mysqli_query($conn, $query);

$all_rows = [];
$daily_total = 0;
$product_summary = [];

while ($row = mysqli_fetch_assoc($result)) {
    $eth = gregorian_to_ethiopian($row['transaction_date']);
    $row['eth_time'] = $eth['time'];
    $all_rows[] = $row;
    $daily_total += $row['subtotal'];

    // Aggregate by product name for the day
    $pname = $row['product_name'];
    if (!isset($product_summary[$pname])) {
        $product_summary[$pname] = [
            'total_qty' => 0,
            'total_amount' => 0
        ];
    }
    $product_summary[$pname]['total_qty'] += $row['quantity'];
    $product_summary[$pname]['total_amount'] += $row['subtotal'];
}

// Handle Excel download
if (isset($_GET['export_excel'])) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="transactions_branch_' . $branch_id . '_' . $selected_date . '.xlsx"');
    
    echo renderExcelWorkbookHeader('የዕለታዊ ግብይቶች ሪፖርት');
    echo renderExcelModernCss();
    echo '</head>';
    echo '<body>';
    echo '<table border="1">';
    echo renderExcelBannerHeader('የዕለታዊ ግብይቶች ሪፖርት', $branch_name, 'ቀን: ' . $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year, 8);
    echo '<tr bgcolor="#312E81">';
    echo '<th>ደረሰኝ #</th>';
    echo '<th>ሰዓት</th>';
    echo '<th>ሻጭ</th>';
    echo '<th>ክፍያ</th>';
    echo '<th>ዕቃ</th>';
    echo '<th>ብዛት</th>';
    echo '<th>ዋጋ</th>';
    echo '<th>ጠቅላላ</th>';
    echo '</tr>';
    
    foreach ($all_rows as $row) {
        echo '<tr>';
        echo '<td>#' . str_pad($row['id'], 6, '0', STR_PAD_LEFT) . '</td>';
        echo '<td>' . $row['eth_time'] . '</td>';
        echo '<td>' . $row['seller_name'] . '</td>';
        echo '<td>' . $row['payment_method'] . '</td>';
        echo '<td>' . $row['product_name'] . '</td>';
        echo '<td>' . number_format($row['quantity'], 2) . '</td>';
        echo '<td>' . number_format($row['unit_price'], 2) . '</td>';
        echo '<td>' . number_format($row['subtotal'], 2) . '</td>';
        echo '</tr>';
    }
    
    // Daily total
    echo '<tr><td colspan="7" align="right"><strong>የዕለቱ ድምር:</strong></td><td><strong>' . number_format($daily_total, 2) . '</strong></td></tr>';
    
    echo '</table>';
    echo '</body></html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/jpg" href="image\photo_2026-01-12_07-44-10.jpg">
    <title>ዕለታዊ ግብይቶች - <?php echo htmlspecialchars($branch_name); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Tahoma, Arial, sans-serif;
            margin: 0;
            padding: 10px;
            background: #F1F5F9;
            color: #0F172A;
            font-size: 14px;
            -webkit-tap-highlight-color: transparent;
        }

        /* Mobile First Branch Header */
        .branch-header {
            background: linear-gradient(135deg, #1E1B4B 0%, #312E81 50%, #4338CA 100%);
            color: white;
            padding: 14px 16px;
            margin-bottom: 12px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(30, 27, 75, 0.15);
        }
        @media(min-width: 600px) {
            .branch-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
            }
        }
        .branch-name {
            font-size: 17px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #FBBF24;
        }

        /* Mobile Stats Grid */
        .mobile-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }
        .stat-card {
            background: white;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .stat-label { font-size: 11px; font-weight: 700; color: #64748B; text-transform: uppercase; }
        .stat-val { font-size: 16px; font-weight: 800; color: #1E1B4B; }

        /* Mobile First Navigation Bar */
        .nav {
            margin-bottom: 12px;
            padding: 10px;
            background: white;
            border: 1px solid #E2E8F0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: stretch;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }
        @media(min-width: 768px) {
            .nav {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 10px 16px;
            }
        }
        .nav > div {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            width: 100%;
        }
        @media(min-width: 768px) {
            .nav > div { width: auto; }
        }
        .nav a, .nav button {
            padding: 10px 14px;
            min-height: 44px;
            background: #2563EB;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex: 1;
            min-width: 100px;
            text-align: center;
        }
        @media(min-width: 600px) {
            .nav a, .nav button {
                flex: initial;
            }
        }
        .nav a:hover, .nav button:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }
        .nav .date {
            font-size: 16px;
            font-weight: 800;
            color: #1E1B4B;
            text-align: center;
            padding: 8px 12px;
            background: #F8FAFC;
            border-radius: 8px;
            border: 1px solid #E2E8F0;
        }
        .today-btn {
            background: #10B981 !important;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        @media(min-width: 900px) {
            table {
                display: table;
                white-space: normal;
            }
        }
        th {
            background: #1E1B4B;
            color: #FBBF24;
            padding: 12px 10px;
            text-align: left;
            font-weight: 800;
            border-bottom: 2px solid #312E81;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #E2E8F0;
        }
        tr:nth-child(even) {
            background: #F8FAFC;
        }
        tr:hover {
            background: #EEF2FF;
        }
        .total-row {
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .branch-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
        }
        @media(min-width: 600px) {
            .branch-badge {
                padding: 4px 10px;
                font-size: 14px;
            }
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 3px;
            font-size: 14px;
        }
        @media print {
            .nav, .branch-header, .info-box, .today-btn, .date-modal-overlay { display: none; }
            body { margin: 0; padding: 10px; }
            th { background: #4CAF50 !important; color: white !important; }
            table { display: table !important; white-space: normal !important; overflow-x: visible !important; }
        }

        /* Date Modal Styles */
        .date-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .date-modal-overlay.active {
            display: flex;
        }
        .date-modal-content {
            background: white;
            width: 92%;
            max-width: 480px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            overflow: hidden;
            animation: modalSlide 0.2s ease-out;
        }
        @keyframes modalSlide {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .date-modal-header {
            background: #1E1B4B;
            color: white;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .date-modal-header h3 { margin: 0; font-size: 16px; color: #FBBF24; }
        .date-modal-close {
            background: none; border: none; color: white; font-size: 24px; cursor: pointer; line-height: 1;
        }
        .date-modal-body { padding: 20px; }
        .quick-chip {
            background: #F1F5F9; border: 1px solid #CBD5E1; color: #1E293B;
            padding: 9px 10px; border-radius: 6px; font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; text-align: center;
        }
        .quick-chip:hover {
            background: #2196F3; color: white; border-color: #2196F3;
        }

        /* Full Month Calendar Widget CSS */
        .calendar-widget {
            background: #ffffff;
            border-radius: 8px;
            padding: 5px;
        }
        .cal-nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 8px;
        }
        .cal-nav-btn {
            background: #1E1B4B;
            color: #FBBF24;
            border: none;
            padding: 7px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 13px;
        }
        .cal-nav-btn:hover {
            background: #312E81;
        }
        .cal-select {
            padding: 6px 8px;
            font-size: 13px;
            border: 1px solid #CBD5E1;
            border-radius: 6px;
            font-weight: bold;
            color: #1E293B;
        }
        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            color: #475569;
            margin-bottom: 8px;
            background: #F1F5F9;
            padding: 6px 0;
            border-radius: 6px;
        }
        .cal-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-day-cell {
            height: 38px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            color: #1E293B;
            transition: all 0.15s ease-in-out;
        }
        .cal-day-cell:hover {
            background: #2196F3;
            border-color: #2196F3;
            color: #ffffff;
            transform: scale(1.06);
        }
        .cal-day-cell.other-month {
            opacity: 0.35;
            background: #F1F5F9;
        }
        .cal-day-cell.today {
            background: #10B981 !important;
            color: #ffffff !important;
            border-color: #059669 !important;
            font-weight: 800;
        }
        .cal-day-cell.active-selected {
            background: #FBBF24 !important;
            color: #1E1B4B !important;
            border-color: #D97706 !important;
            font-weight: 800;
            box-shadow: 0 0 0 2px #FBBF24;
        }
    </style>
</head>
<body>

<!-- Branch Header -->
<div class="branch-header">
    <div class="branch-name">
        <i class="fas fa-store"></i> ቅርንጫፍ: <?php echo htmlspecialchars($branch_name); ?>
    </div>
    <div class="branch-badge">
        <i class="fas fa-calendar-alt"></i> ዛሬ: <?php echo $current_ethiopian['day'] . ' ' . $current_ethiopian['month_name'] . ' ' . $current_ethiopian['year']; ?>
    </div>
</div>

<!-- Simple Navigation -->
<div class="nav">
    <a href="?date=<?php echo $prev_date; ?>">← ትናንት</a>
    <span class="date" onclick="openDateModal()" style="cursor:pointer;" title="ቀን ለመቀየር ይጫኑ">
        <?php echo $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year; ?> <i class="fas fa-calendar-alt" style="font-size:14px;color:#2196F3;margin-left:4px;"></i>
    </span>
    <div>
        <button onclick="openDateModal()" style="background:#9c27b0;color:white;border:none;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:bold;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-calendar-alt"></i> 📅 ቀን ምረጥ (Choose Date)
        </button>
        <?php if ($selected_date != $current_ethiopian['full_date']): ?>
        <a href="?date=<?php echo $current_ethiopian['full_date']; ?>" class="today-btn">📅 ዛሬ (<?php echo $current_ethiopian['day'] . ' ' . $current_ethiopian['month_name']; ?>)</a>
        <?php endif; ?>
        <a href="?date=<?php echo $next_date; ?>">ነገ →</a>
        <button onclick="window.print()">🖨️ ማተም</button>
        <a href="?export_excel=1&date=<?php echo $selected_date; ?>">📊 ኤክሴል</a>
        <a href="history.php">🔙 ተመለስ</a>
    </div>
</div>

<!-- Mobile Summary Stats Cards Bar -->
<div class="mobile-stats-grid">
    <div class="stat-card" onclick="openProductSummaryModal()" style="cursor:pointer; background:#EFF6FF; border-color:#BFDBFE;" title="የዕቃዎችን ዝርዝር ለማየት ይጫኑ">
        <span class="stat-label" style="color:#1D4ED8;"><i class="fas fa-shopping-bag" style="color:#2563EB;"></i> ዕቃዎች (Items) <i class="fas fa-search" style="font-size:10px; color:#2563EB; margin-left:4px;"></i></span>
        <span class="stat-val" style="color:#1E40AF;"><?php echo count($all_rows); ?> <small style="font-size:11px; font-weight:normal; color:#2563EB; text-decoration:underline;">(ዝርዝር &rarr;)</small></span>
    </div>
    <div class="stat-card">
        <span class="stat-label"><i class="fas fa-coins" style="color:#10B981;"></i> ጠቅላላ (Total ETB)</span>
        <span class="stat-val" style="color:#10B981;"><?php echo number_format($daily_total, 2); ?></span>
    </div>
    <div class="stat-card">
        <span class="stat-label"><i class="fas fa-calendar" style="color:#7C3AED;"></i> ቀን (Date)</span>
        <span class="stat-val" style="font-size:13px; color:#475569;"><?php echo $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year; ?></span>
    </div>
</div>

<!-- Info message when viewing non-today date -->
<?php if ($selected_date != $current_ethiopian['full_date']): ?>
<div class="info-box">
    <i class="fas fa-info-circle"></i> 
    እየተመለከቱ ያሉት የቀን: <strong><?php echo $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year; ?></strong> ግብይቶች ነው። 
    ወደ ዛሬ ግብይቶች ለመመለስ "ዛሬ" የሚለውን ቁልፍ ይጫኑ።
</div>
<?php endif; ?>

<!-- Selected Day Transactions -->
<table>
    <thead>
        <tr>
            <th>ደረሰኝ #</th>
            <th>ሰዓት</th>
            <th>ሻጭ</th>
            <th>ክፍያ</th>
            <th>ዕቃ</th>
            <th class="text-right">ብዛት</th>
            <th class="text-right">ዋጋ</th>
            <th class="text-right">ጠቅላላ</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($all_rows)): ?>
        <tr>
            <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                በዚህ ቀን ምንም ግብይት የለም
                <br>
                <small style="font-size: 12px;"><?php echo $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year; ?></small>
            </td>
        </tr>
        <?php else: ?>
            <?php foreach ($all_rows as $row): ?>
            <tr>
                <td><strong>#<?php echo str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                <td><?php echo $row['eth_time']; ?></td>
                <td><?php echo htmlspecialchars($row['seller_name']); ?></td>
                <td><?php echo $row['payment_method']; ?></td>
                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                <td class="text-right"><?php echo number_format($row['quantity'], 2); ?></td>
                <td class="text-right"><?php echo number_format($row['unit_price'], 2); ?></td>
                <td class="text-right"><strong><?php echo number_format($row['subtotal'], 2); ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="7" class="text-right"><strong>የዕለቱ ጠቅላላ ድምር:</strong></td>
                <td class="text-right"><strong><?php echo number_format($daily_total, 2); ?></strong></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Date Selection Modal Popup (Full Ethiopian Month Calendar Grid) -->
<div id="dateModal" class="date-modal-overlay" onclick="if(event.target===this) closeDateModal()">
    <div class="date-modal-content">
        <div class="date-modal-header">
            <h3><i class="fas fa-calendar-alt"></i> የኢትዮጵያ ወርና ቀን መምረጫ (Ethiopian Calendar)</h3>
            <button class="date-modal-close" onclick="closeDateModal()">&times;</button>
        </div>
        <div class="date-modal-body">
            <!-- Full Ethiopian Month Calendar Widget -->
            <div class="calendar-widget">
                <div class="cal-nav-bar">
                    <button class="cal-nav-btn" onclick="prevCalMonth()">&larr; ባለፈው ወር</button>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <select id="calMonthSelect" class="cal-select" onchange="onEthCalSelectChange()">
                            <option value="1">መስከረም (Meskerem - 1)</option>
                            <option value="2">ጥቅምት (Tikimt - 2)</option>
                            <option value="3">ኅዳር (Hidar - 3)</option>
                            <option value="4">ታኅሣሥ (Tahsas - 4)</option>
                            <option value="5">ጥር (Tir - 5)</option>
                            <option value="6">የካቲት (Yekatit - 6)</option>
                            <option value="7">መጋቢት (Megabit - 7)</option>
                            <option value="8">ሚያዝያ (Miazia - 8)</option>
                            <option value="9">ግንቦት (Ginbot - 9)</option>
                            <option value="10">ሰኔ (Sene - 10)</option>
                            <option value="11">ሐምሌ (Hamle - 11)</option>
                            <option value="12">ነሐሴ (Nehase - 12)</option>
                            <option value="13">ጳጉሜ (Pagume - 13)</option>
                        </select>
                        <select id="calYearSelect" class="cal-select" onchange="onEthCalSelectChange()">
                            <?php 
                            $curr_eth_year = $current_ethiopian['year'];
                            for ($y = $curr_eth_year - 5; $y <= $curr_eth_year + 2; $y++): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?> ዓ.ም</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button class="cal-nav-btn" onclick="nextCalMonth()">ቀጣይ ወር &rarr;</button>
                </div>

                <div id="calDaysGrid" class="cal-days-grid" style="margin-top:10px;">
                    <!-- Ethiopian Month Days (1 to 30) populated by JS -->
                </div>
            </div>

            <!-- Direct Date Format Backup -->
            <div style="margin-top: 15px; padding-top: 12px; border-top: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                <span style="font-size: 13px; font-weight: bold; color: #475569;">የኢትዮጵያ ቀን (ዓ/ወ/ቀ):</span>
                <input type="text" id="ethDatePickerInput" value="<?php echo $selected_date; ?>" placeholder="YYYY-MM-DD" style="padding:6px 10px; font-size:14px; border:1px solid #CBD5E1; border-radius:6px; width:130px; text-align:center;">
                <button onclick="jumpToEthInputDate()" style="background:#2196F3; color:white; border:none; padding:6px 14px; font-size:13px; font-weight:bold; border-radius:6px; cursor:pointer;">
                    ክፈት
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script>
let ethCalYear = <?php echo $sel_year; ?>;
let ethCalMonth = <?php echo $sel_month; ?>; // 1 to 13
let ethSelectedDateStr = '<?php echo $selected_date; ?>';
let ethTodayStr = '<?php echo $current_ethiopian['full_date']; ?>';

function openDateModal() {
    const modal = document.getElementById('dateModal');
    if (modal) {
        modal.classList.add('active');
        renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
    }
}

function closeDateModal() {
    const modal = document.getElementById('dateModal');
    if (modal) modal.classList.remove('active');
}

function renderEthiopianMonthCalendar(ethYear, ethMonth) {
    const grid = document.getElementById('calDaysGrid');
    const monthSelect = document.getElementById('calMonthSelect');
    const yearSelect = document.getElementById('calYearSelect');
    if (!grid) return;
    
    grid.innerHTML = '';
    
    if (monthSelect) monthSelect.value = ethMonth;
    if (yearSelect) yearSelect.value = ethYear;
    
    let totalDays = 30;
    if (ethMonth === 13) {
        totalDays = (ethYear % 4 === 3) ? 6 : 5;
    }
    
    for (let d = 1; d <= totalDays; d++) {
        const dateStr = sprintfEthDate(ethYear, ethMonth, d);
        
        const cell = document.createElement('div');
        cell.className = 'cal-day-cell';
        if (dateStr === ethTodayStr) cell.classList.add('today');
        if (dateStr === ethSelectedDateStr) cell.classList.add('active-selected');
        
        cell.innerText = d;
        cell.title = 'የኢትዮጵያ ቀን: ' + dateStr;
        cell.onclick = function() { navigateToEthDate(dateStr); };
        grid.appendChild(cell);
    }
}

function sprintfEthDate(y, m, d) {
    const mm = String(m).padStart(2, '0');
    const dd = String(d).padStart(2, '0');
    return `${y}-${mm}-${dd}`;
}

function prevCalMonth() {
    ethCalMonth--;
    if (ethCalMonth < 1) {
        ethCalMonth = 13;
        ethCalYear--;
    }
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function nextCalMonth() {
    ethCalMonth++;
    if (ethCalMonth > 13) {
        ethCalMonth = 1;
        ethCalYear++;
    }
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function onEthCalSelectChange() {
    ethCalMonth = parseInt(document.getElementById('calMonthSelect').value);
    ethCalYear = parseInt(document.getElementById('calYearSelect').value);
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function navigateToEthDate(dateStr) {
    if (dateStr) {
        window.location.href = 'export_daily_view.php?date=' + encodeURIComponent(dateStr);
    }
}

function jumpToEthInputDate() {
    const val = document.getElementById('ethDatePickerInput').value.trim();
    if (val) {
        navigateToEthDate(val);
    }
}
</script>

<!-- Product Sales Summary Modal Popup -->
<div id="productSummaryModal" class="date-modal-overlay" onclick="if(event.target===this) closeProductSummaryModal()">
    <div class="date-modal-content">
        <div class="date-modal-header" style="background:#1E1B4B;">
            <h3><i class="fas fa-boxes"></i> የዕለቱ የዕቃዎች ሽያጭ ዝርዝር (Product Summary)</h3>
            <button class="date-modal-close" onclick="closeProductSummaryModal()">&times;</button>
        </div>
        <div class="date-modal-body" style="padding:15px;">
            <div style="margin-bottom:12px; font-weight:bold; font-size:14px; color:#1E293B; display:flex; justify-content:space-between; align-items:center; background:#F8FAFC; padding:10px 12px; border-radius:8px; border:1px solid #E2E8F0;">
                <span>📅 ቀን: <strong><?php echo $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year; ?></strong></span>
                <span style="color:#2563EB; font-weight:800;">ዓይነት: <?php echo count($product_summary); ?> ዕቃዎች</span>
            </div>
            
            <div style="max-height:380px; overflow-y:auto; border:1px solid #E2E8F0; border-radius:8px; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                <table style="width:100%; border-collapse:collapse; margin:0; box-shadow:none; font-size:13px;">
                    <thead>
                        <tr style="background:#312E81; color:#FBBF24;">
                            <th style="padding:10px; text-align:left;">የዕቃው ስም (Item Name)</th>
                            <th style="padding:10px; text-align:right;">የተሸጠው ብዛት</th>
                            <th style="padding:10px; text-align:right;">ጠቅላላ ዋጋ (ETB)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($product_summary)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center; padding:30px; color:#94A3B8;">በዚህ ቀን ምንም ዕቃ አልተሸጠም</td>
                        </tr>
                        <?php else: ?>
                            <?php 
                            $grand_qty = 0;
                            $grand_amt = 0;
                            foreach ($product_summary as $pname => $pdata): 
                                $grand_qty += $pdata['total_qty'];
                                $grand_amt += $pdata['total_amount'];
                            ?>
                            <tr style="border-bottom:1px solid #E2E8F0;">
                                <td style="padding:10px; font-weight:700; color:#1E293B;">
                                    <i class="fas fa-cube" style="color:#2563EB; margin-right:6px;"></i>
                                    <?php echo htmlspecialchars($pname); ?>
                                </td>
                                <td style="padding:10px; text-align:right; font-weight:800; color:#0F172A;">
                                    <?php echo number_format($pdata['total_qty'], 2); ?>
                                </td>
                                <td style="padding:10px; text-align:right; font-weight:800; color:#10B981;">
                                    <?php echo number_format($pdata['total_amount'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#FEF3C7; font-weight:800;">
                                <td style="padding:12px; color:#78350F;">ጠቅላላ ድምር (Grand Total):</td>
                                <td style="padding:12px; text-align:right; color:#78350F; font-size:14px;"><?php echo number_format($grand_qty, 2); ?></td>
                                <td style="padding:12px; text-align:right; color:#10B981; font-size:15px;"><?php echo number_format($grand_amt, 2); ?> ETB</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function openProductSummaryModal() {
    const modal = document.getElementById('productSummaryModal');
    if (modal) modal.classList.add('active');
}
function closeProductSummaryModal() {
    const modal = document.getElementById('productSummaryModal');
    if (modal) modal.classList.remove('active');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDateModal();
        closeProductSummaryModal();
    }
});

// Auto-refresh only if viewing today
<?php if ($selected_date == $current_ethiopian['full_date']): ?>
console.log('Auto-refresh enabled for today\'s view');
setTimeout(() => {
    location.reload();
}, 30000);
<?php endif; ?>
</script>

</body>
</html>
<?php 
mysqli_close($conn); 
?>
