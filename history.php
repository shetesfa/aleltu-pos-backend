<?php
session_start();

// Disable browser caching for history reports
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'config.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

// ========== Get branch information properly ==========
$branch_id   = getCurrentBranchId($conn, $user_id, $user_role);
$branch_name = getCurrentBranchName($conn, $branch_id);

// Validate branch access - for non-superadmin, branch_id must be > 0
if ($user_role != 'super_admin' && ($branch_id <= 0 || $branch_id === null)) {
    die("ERROR: No branch assigned to your account. Please contact administrator.");
}

// If super admin has no branch selected, show error
if ($user_role == 'super_admin' && ($branch_id <= 0 || $branch_id === null)) {
    echo "<!DOCTYPE html><html><head><title>Select Branch</title><style>body{font-family:Arial;text-align:center;padding:50px;}</style></head><body>";
    echo "<h2>⚠️ እባክዎ ቅርንጫፍ ይምረጡ</h2>";
    echo "<p>ሪፖርት ለማየት ከላይ ባለው ቅርንጫፍ መራጫ ቅርንጫፍ ይምረጡ።</p>";
    echo "<a href='super_admin.php' style='display:inline-block;margin-top:20px;padding:10px 20px;background:#3498db;color:white;text-decoration:none;border-radius:5px;'>ወደ ዳሽቦርድ ተመለስ</a>";
    echo "</body></html>";
    exit();
}

/**
 * Ethiopian calendar conversion
 */
function gregorian_to_ethiopian($gregorian_datetime) {
    try {
        if (strlen($gregorian_datetime) == 10) {
            $gregorian_datetime .= ' 00:00:00';
        }

        $date = new DateTime($gregorian_datetime, new DateTimeZone('Africa/Addis_Ababa'));

        $year   = (int)$date->format('Y');
        $month  = (int)$date->format('m');
        $day    = (int)$date->format('d');
        $hour   = (int)$date->format('H');
        $minute = (int)$date->format('i');
        $second = (int)$date->format('s');

        $ethiopian_months = [
            "መስከረም", "ጥቅምት", "ህዳር", "ታህሳስ", "ጥር", "የካቲት",
            "መጋቢት", "ሚያዝያ", "ግንቦት", "ሰኔ", "ሐምሌ", "ነሐሴ", "ጳጉሜ"
        ];

        $is_greg_leap = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $new_year_day = $is_greg_leap ? 12 : 11;

        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $ethiopian_year = $year - 8;
        } else {
            $ethiopian_year = $year - 7;
        }

        $new_year = new DateTime($year . '-09-' . $new_year_day . ' 00:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        if ($date < $new_year) {
            $prev_year         = $year - 1;
            $is_prev_greg_leap = ($prev_year % 4 == 0 && ($prev_year % 100 != 0 || $prev_year % 400 == 0));
            $prev_new_year_day = $is_prev_greg_leap ? 12 : 11;
            $new_year          = new DateTime($prev_year . '-09-' . $prev_new_year_day . ' 00:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        }

        $interval    = $new_year->diff($date);
        $days_diff   = $interval->days;

        $ethiopian_month = floor($days_diff / 30) + 1;
        $ethiopian_day   = ($days_diff % 30) + 1;

        if ($ethiopian_month > 13) {
            $ethiopian_month  = 13;
            $is_eth_leap      = ($ethiopian_year % 4 == 3);
            $max_pagume_days  = $is_eth_leap ? 6 : 5;
            if ($ethiopian_day > $max_pagume_days) {
                $ethiopian_day = $max_pagume_days;
            }
        }

        $hour_12 = $hour % 12;
        $hour_12 = $hour_12 == 0 ? 12 : $hour_12;
        $am_pm   = $hour < 12 ? 'ጥዋት' : 'ከሰዓት';

        return [
            'year'       => $ethiopian_year,
            'month'      => $ethiopian_month,
            'month_name' => $ethiopian_months[$ethiopian_month - 1] ?? '',
            'day'        => $ethiopian_day,
            'full_date'  => sprintf("%d-%02d-%02d", $ethiopian_year, $ethiopian_month, $ethiopian_day),
            'hour'       => $hour,
            'minute'     => $minute,
            'second'     => $second,
            'hour_12'    => $hour_12,
            'am_pm'      => $am_pm,
            'time_12h'   => sprintf("%d:%02d:%02d %s", $hour_12, $minute, $second, $am_pm),
            'time_24h'   => sprintf("%02d:%02d:%02d", $hour, $minute, $second)
        ];
    } catch (Exception $e) {
        return get_current_ethiopian_date();
    }
}

function get_current_ethiopian_date() {
    $ethiopia_time = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    return gregorian_to_ethiopian($ethiopia_time->format('Y-m-d H:i:s'));
}

function getEthiopianDayBoundaries($eth_date) {
    $parts     = explode('-', $eth_date);
    $eth_year  = (int)$parts[0];
    $eth_month = (int)$parts[1];
    $eth_day   = (int)$parts[2];

    $greg_year       = $eth_year + 7;
    $is_greg_leap    = ($greg_year % 4 == 0 && ($greg_year % 100 != 0 || $greg_year % 400 == 0));
    $new_year_day    = $is_greg_leap ? 12 : 11;
    $days_from_start = ($eth_month - 1) * 30 + ($eth_day - 1);

    $start_greg = new DateTime("$greg_year-09-$new_year_day 00:00:00", new DateTimeZone('Africa/Addis_Ababa'));
    if ($days_from_start > 0) {
        $start_greg->modify("+{$days_from_start} days");
    }

    $end_greg = clone $start_greg;
    $end_greg->modify('+1 day');
    $end_greg->modify('-1 second');

    return [
        'start_greg' => $start_greg->format('Y-m-d H:i:s'),
        'end_greg'   => $end_greg->format('Y-m-d H:i:s')
    ];
}

$current_ethiopian = get_current_ethiopian_date();

$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'today';

$start_date_greg = '';
$end_date_greg   = '';
$start_date_eth  = '';
$end_date_eth    = '';

$today_boundaries  = getEthiopianDayBoundaries($current_ethiopian['full_date']);
$today_start_greg  = $today_boundaries['start_greg'];
$today_end_greg    = $today_boundaries['end_greg'];

if ($date_range == 'today') {
    $start_date_greg = $today_start_greg;
    $end_date_greg   = $today_end_greg;
    $start_date_eth  = $current_ethiopian['full_date'];
    $end_date_eth    = $current_ethiopian['full_date'];
} elseif ($date_range == 'yesterday') {
    $yesterday_eth      = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $yesterday_eth->modify('-1 day');
    $yesterday_eth_date = gregorian_to_ethiopian($yesterday_eth->format('Y-m-d H:i:s'));
    $yesterday_boundaries = getEthiopianDayBoundaries($yesterday_eth_date['full_date']);
    $start_date_greg    = $yesterday_boundaries['start_greg'];
    $end_date_greg      = $yesterday_boundaries['end_greg'];
    $start_date_eth     = $yesterday_eth_date['full_date'];
    $end_date_eth       = $yesterday_eth_date['full_date'];
} elseif ($date_range == '3day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-2 days');
    $start_eth         = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries  = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg   = $start_boundaries['start_greg'];
    $start_date_eth    = $start_eth['full_date'];
} elseif ($date_range == '7day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-6 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '14day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-13 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '21day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-20 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '30day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-30 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '60day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-60 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '90day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-90 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '180day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-180 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} elseif ($date_range == '365day') {
    $end_eth        = $current_ethiopian['full_date'];
    $end_boundaries = getEthiopianDayBoundaries($end_eth);
    $end_date_greg  = $end_boundaries['end_greg'];
    $end_date_eth   = $end_eth;
    $start_eth_obj  = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $start_eth_obj->modify('-365 days');
    $start_eth        = gregorian_to_ethiopian($start_eth_obj->format('Y-m-d H:i:s'));
    $start_boundaries = getEthiopianDayBoundaries($start_eth['full_date']);
    $start_date_greg  = $start_boundaries['start_greg'];
    $start_date_eth   = $start_eth['full_date'];
} else {
    $start_date_greg = $today_start_greg;
    $end_date_greg   = $today_end_greg;
    $start_date_eth  = $current_ethiopian['full_date'];
    $end_date_eth    = $current_ethiopian['full_date'];
}

function format_ethiopian_date($ethiopian_date, $include_time = false) {
    if (!$ethiopian_date) return '';
    $parts     = explode(' ', $ethiopian_date);
    $date_part = $parts[0];
    $time_part = isset($parts[1]) ? $parts[1] : '';
    list($year, $month, $day) = explode('-', $date_part);
    $ethiopian_months = [
        "መስከረም", "ጥቅምት", "ህዳር", "ታህሳስ", "ጥር", "የካቲት",
        "መጋቢት", "ሚያዝያ", "ግንቦት", "ሰኔ", "ሐምሌ", "ነሐሴ", "ጳጉሜ"
    ];
    $month_name = $ethiopian_months[(int)$month - 1] ?? '';

    if ($include_time && $time_part) {
        list($hour, $minute) = explode(':', $time_part);
        $hour    = (int)$hour;
        $minute  = (int)$minute;
        $hour_12 = $hour % 12;
        $hour_12 = $hour_12 == 0 ? 12 : $hour_12;
        $am_pm   = $hour < 12 ? 'ጥዋት' : 'ከሰዓት';
        return sprintf("%d %s %d, %d:%02d %s", $day, $month_name, $year, $hour_12, $minute, $am_pm);
    }
    return "$day $month_name $year";
}

$display_start_date = format_ethiopian_date($start_date_eth);
$display_end_date   = format_ethiopian_date($end_date_eth);

// Raw filter values — used only in prepared statements below, never in string SQL
$filter_seller     = isset($_GET['seller'])             ? trim($_GET['seller'])             : '';
$filter_payment    = isset($_GET['payment_method'])     ? trim($_GET['payment_method'])     : '';
$search_item       = isset($_GET['search_item'])        ? trim($_GET['search_item'])        : '';
$search_receipt_id = isset($_GET['search_receipt_id'])  ? intval($_GET['search_receipt_id']) : 0;

$is_ajax  = isset($_GET['ajax']) && $_GET['ajax'] == '1';
$page     = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 30;

// ========== Build WHERE clause — only safe typed/whitelisted values ==========
// Dates come from validated Ethiopian-date logic above (not raw user input).
// branch_id is intval'd. All string filters go through prepared statements.
$where_conditions = [
    "t.transaction_date BETWEEN '$start_date_greg' AND '$end_date_greg'",
    "t.branch_id = $branch_id"
];

if ($filter_seller) {
    $safe_seller = mysqli_real_escape_string($conn, $filter_seller);
    $where_conditions[] = "t.seller_name LIKE '%$safe_seller%'";
}
if ($filter_payment) {
    // Whitelist allowed payment methods to prevent injection
    $allowed_payments = ['cash', 'cbe', 'telebirr', 'abyssinia', 'card', 'bank'];
    if (in_array(strtolower($filter_payment), $allowed_payments, true)) {
        $safe_payment = mysqli_real_escape_string($conn, $filter_payment);
        $where_conditions[] = "t.payment_method = '$safe_payment'";
    } else {
        $filter_payment = ''; // invalid value — ignore
    }
}
if ($search_receipt_id > 0) {
    $where_conditions[] = "t.id = " . intval($search_receipt_id);
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$item_search_condition = '';
if ($search_item) {
    $safe_item = mysqli_real_escape_string($conn, $search_item);
    $item_search_condition = " AND EXISTS (SELECT 1 FROM transaction_items ti WHERE ti.transaction_id = t.id AND ti.product_name LIKE '%$safe_item%')";
}

// ========== Totals query ==========
$totals_query  = "SELECT COUNT(*) as total_transactions, 
                  COALESCE(SUM(t.total_amount), 0) as total_sales
                  FROM transactions t 
                  $where_clause $item_search_condition";
$totals_result = mysqli_query($conn, $totals_query);
if (!$totals_result) { error_log('history.php: ' . mysqli_error($conn)); die('Unable to load data. Please try again.'); }
$totals             = mysqli_fetch_assoc($totals_result);
$total_transactions = $totals['total_transactions'];
$total_sales        = $totals['total_sales'];

// ========== Count query for pagination ==========
$count_query   = "SELECT COUNT(*) as total_records FROM transactions t $where_clause $item_search_condition";
$count_result  = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total_records'];
$total_pages   = ceil($total_records / $per_page);

// Check withdrawals table
if (!isset($_SESSION['table_cache']['daily_withdrawals'])) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'daily_withdrawals'");
    $_SESSION['table_cache']['daily_withdrawals'] = ($chk && mysqli_num_rows($chk) > 0);
}
$withdrawals_table_exists = $_SESSION['table_cache']['daily_withdrawals'];

$todays_withdrawal_count  = 0;
$total_withdrawals_amount = 0;

if ($withdrawals_table_exists) {
    $withdrawals_query  = "SELECT COUNT(*) as withdrawal_count, 
                           COALESCE(SUM(amount), 0) as total_withdrawn
                           FROM daily_withdrawals 
                           WHERE created_at BETWEEN '$start_date_greg' AND '$end_date_greg'
                           AND branch_id = $branch_id";
    $withdrawals_result = mysqli_query($conn, $withdrawals_query);
    if ($withdrawals_result && mysqli_num_rows($withdrawals_result) > 0) {
        $withdrawals_data         = mysqli_fetch_assoc($withdrawals_result);
        $todays_withdrawal_count  = $withdrawals_data['withdrawal_count'] ?? 0;
        $total_withdrawals_amount = $withdrawals_data['total_withdrawn'] ?? 0;
    }
}

// ========== Payment method breakdown ==========
$payment_query  = "SELECT t.payment_method, COUNT(*) as count, SUM(t.total_amount) as amount
                   FROM transactions t 
                   $where_clause $item_search_condition
                   GROUP BY t.payment_method";
$payment_result = mysqli_query($conn, $payment_query);

// ========== Daily sales for chart ==========
$daily_query  = "SELECT DATE(t.transaction_date) as gregorian_day, 
                 COUNT(*) as transactions, 
                 SUM(t.total_amount) as total
                 FROM transactions t 
                 $where_clause $item_search_condition
                 GROUP BY DATE(t.transaction_date) 
                 ORDER BY gregorian_day";
$daily_result = mysqli_query($conn, $daily_query);

$daily_data = [];
while ($day = mysqli_fetch_assoc($daily_result)) {
    $ethiopian_date        = gregorian_to_ethiopian($day['gregorian_day'] . ' 12:00:00');
    $day['ethiopian_day']  = $ethiopian_date['day'] . ' ' . $ethiopian_date['month_name'];
    $daily_data[]          = $day;
}

$total_sales_amount = $total_sales;
$net_balance        = $total_sales_amount - $total_withdrawals_amount;

// AJAX handler for transactions list
if ($is_ajax) {
    header('Content-Type: application/json');

    $offset = ($page - 1) * $per_page;

    $query = "SELECT t.*,
              COALESCE(ti_count.cnt, 0) as item_count,
              t.transaction_date as raw_date
              FROM transactions t
              LEFT JOIN (
                  SELECT transaction_id, COUNT(*) as cnt
                  FROM transaction_items
                  GROUP BY transaction_id
              ) ti_count ON ti_count.transaction_id = t.id
              $where_clause $item_search_condition
              ORDER BY t.id DESC
              LIMIT $offset, $per_page";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit();
    }

    $transactions = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $eth_date              = gregorian_to_ethiopian($row['raw_date']);
        $row['eth_date']       = $eth_date;
        $row['eth_date_display'] = $eth_date['day'] . ' ' . $eth_date['month_name'] . ' ' . $eth_date['year'] . ', ' . $eth_date['time_12h'];
        $row['is_today']       = ($eth_date['full_date'] == $current_ethiopian['full_date']);
        $transactions[]        = $row;
    }

    echo json_encode([
        'success'       => true,
        'transactions'  => $transactions,
        'total_records' => $total_records,
        'total_pages'   => $total_pages,
        'current_page'  => $page,
        'per_page'      => $per_page,
        'summary'       => [
            'total_transactions' => intval($total_transactions),
            'total_sales'        => floatval($total_sales),
            'avg_sale'           => ($total_transactions > 0) ? floatval($total_sales / $total_transactions) : 0,
            'withdrawal_count'   => intval($todays_withdrawal_count),
            'withdrawal_amount'  => floatval($total_withdrawals_amount),
            'net_balance'        => floatval($net_balance)
        ]
    ]);
    mysqli_free_result($result);
    mysqli_close($conn);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/icon.png">
    <title>Sales History - Aleltu POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }

        .branch-selector-top {
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        .branch-selector-top select {
            padding: 10px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 200px;
            background: white;
        }
        .branch-info-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
        }

        .header { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .header h1 { color: #333; font-size: 28px; display: flex; align-items: center; gap: 15px; }
        .header h1 i { color: #667eea; }
        .branch-badge { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 8px 20px; border-radius: 30px; font-size: 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin-left: 15px; }
        .back-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 25px; border-radius: 10px; cursor: pointer; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: transform 0.3s; text-decoration: none; }
        .back-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .summary-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        @media (max-width: 768px) { .summary-cards { grid-template-columns: 1fr; } }
        .card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); transition: transform 0.3s; display: flex; align-items: center; gap: 20px; }
        .card:hover { transform: translateY(-5px); }
        .card-icon { width: 70px; height: 70px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
        .card:nth-child(1) .card-icon { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .card:nth-child(2) .card-icon { background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%); color: white; }
        .card:nth-child(3) .card-icon { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; }
        .card-content { flex: 1; }
        .card h3 { color: #666; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .card .value { font-size: 32px; font-weight: 700; color: #333; margin-bottom: 5px; line-height: 1; }
        .card .subtext { font-size: 14px; color: #888; display: flex; align-items: center; gap: 5px; }
        .filters { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .filters h2 { color: #333; margin-bottom: 20px; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .filter-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 15px; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { display: block; font-weight: 600; color: #555; font-size: 14px; margin-bottom: 8px; }
        .filter-group input, .filter-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #667eea; }
        .date-buttons { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; justify-content: center; }
        .date-btn { padding: 10px 15px; border: 2px solid #ddd; background: white; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #555; transition: all 0.3s; flex: 1; min-width: 100px; text-align: center; white-space: nowrap; }
        .date-btn:hover { border-color: #667eea; color: #667eea; transform: translateY(-2px); }
        .date-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: #667eea; }
        .filter-actions { display: flex; gap: 10px; margin-top: 20px; }
        .filter-btn { padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s; display: flex; align-items: center; gap: 8px; height: 44px; }
        .apply-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .reset-btn { background: #f5f5f5; color: #666; }
        .filter-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .ethiopian-date { background: #fff8e1; border-radius: 8px; padding: 12px 20px; margin-bottom: 15px; text-align: center; border-left: 4px solid #f57c00; font-weight: 600; color: #5d4037; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .ethiopian-date .date-info { display: flex; align-items: center; gap: 10px; }
        .ethiopian-date .time-info { display: flex; align-items: center; gap: 10px; font-family: monospace; background: #5d4037; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px; }
        .chart-container { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .chart-container h2 { color: #333; margin-bottom: 20px; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .chart-wrapper { height: 300px; position: relative; }
        .transactions-section { background: white; border-radius: 15px; padding: 25px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .section-header h2 { color: #333; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .export-btn { background: #4CAF50; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .export-btn:hover { background: #388e3c; transform: translateY(-2px); }
        .transactions-table { width: 100%; border-collapse: collapse; overflow: hidden; border-radius: 10px; }
        .transactions-table thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .transactions-table th { padding: 15px; text-align: left; font-weight: 600; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; }
        .transactions-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.3s; }
        .transactions-table tbody tr:hover { background: #f8f9ff; }
        .transactions-table td { padding: 15px; color: #555; font-size: 14px; }
        .transaction-id { font-weight: 700; color: #667eea; }
        .amount { font-weight: 700; color: #333; font-size: 16px; }
        .payment-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .payment-cash { background: #e8f5e9; color: #2e7d32; }
        .payment-abyssinia { background: #e3f2fd; color: #1565c0; }
        .payment-cbe { background: #f3e5f5; color: #7b1fa2; }
        .payment-telebirr { background: #fff3e0; color: #f57c00; }
        .view-btn { background: #667eea; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600; transition: background 0.3s; display: flex; align-items: center; gap: 5px; }
        .view-btn:hover { background: #5a6fd8; }
        .no-data { text-align: center; padding: 40px; color: #999; font-size: 16px; }
        .no-data i { font-size: 48px; margin-bottom: 15px; color: #ddd; }
        .pagination-container { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 20px; padding: 15px 0; }
        .pagination-btn { padding: 8px 16px; border: 2px solid #ddd; background: white; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; color: #555; transition: all 0.3s; min-width: 40px; text-align: center; }
        .pagination-btn:hover:not(:disabled) { border-color: #667eea; color: #667eea; transform: translateY(-2px); }
        .pagination-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: #667eea; }
        .pagination-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination-info { color: #666; font-size: 14px; padding: 8px 15px; background: #f5f5f5; border-radius: 8px; }
        .payment-methods { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        .payment-methods h2 { color: #333; margin-bottom: 20px; font-size: 20px; display: flex; align-items: center; gap: 10px; }
        .payment-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .payment-item { padding: 15px; border-radius: 10px; background: #f8f9ff; border-left: 4px solid #667eea; }
        .payment-name { font-weight: 600; color: #333; margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        .payment-stats { display: flex; justify-content: space-between; font-size: 14px; color: #666; }
        .balance-summary { background: white; border-radius: 15px; padding: 20px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        @media (max-width: 768px) { .balance-summary { grid-template-columns: 1fr; } }
        .balance-item { text-align: center; padding: 15px; border-radius: 10px; }
        .balance-label { font-size: 14px; color: #666; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .balance-value { font-size: 24px; font-weight: 700; }
        .sales-balance { background: #e8f5e9; color: #2e7d32; }
        .withdrawals-balance { background: #fff3e0; color: #f57c00; }
        .net-balance { background: #e3f2fd; color: #1565c0; }
        .loading { display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* Receipt Modal Styles */
        .edit-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .edit-form {
            background: white;
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .receipt-container {
            background: white;
            padding: 20px;
            max-width: 320px;
            margin: 0 auto;
            font-family: 'Courier New', monospace;
        }
        .receipt-header { text-align: center; padding-bottom: 15px; margin-bottom: 15px; border-bottom: 2px dashed #333; }
        .receipt-header .title { font-size: 24px; font-weight: bold; margin-bottom: 5px; color: #2c3e50; }
        .receipt-header .subtitle { font-size: 16px; color: #555; margin-bottom: 10px; }
        .receipt-header .address { font-size: 12px; color: #777; margin-bottom: 5px; }
        .receipt-info { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px dashed #ddd; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 13px; }
        .info-label { font-weight: bold; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 13px; }
        .items-table th { text-align: left; padding: 8px 0; border-bottom: 2px solid #333; font-weight: bold; }
        .items-table td { padding: 5px 0; border-bottom: 1px solid #eee; }
        .items-table .qty { text-align: center; width: 60px; }
        .items-table .price { text-align: right; width: 80px; }
        .items-table .total { text-align: right; width: 80px; }
        .receipt-totals { padding-top: 10px; border-top: 2px solid #333; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .grand-total { font-size: 18px; font-weight: bold; margin-top: 10px; padding-top: 10px; border-top: 2px dashed #333; }
        .receipt-footer { text-align: center; padding-top: 15px; margin-top: 15px; border-top: 1px dashed #333; font-size: 12px; color: #777; }

        .offline-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff3e0;
            color: #e65100;
            border: 1px solid #ffcc80;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 15px;
            font-size: 13px;
            font-weight: 600;
        }

        @media print {
            body * { visibility: hidden; }
            .receipt-container, .receipt-container * { visibility: visible; }
            .receipt-container { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 20px; }
            .edit-form { box-shadow: none; padding: 0; }
            .no-print { display: none; }
        }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .summary-cards { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 15px; align-items: stretch; padding: 18px; }
            .header h1 { font-size: 20px; flex-wrap: wrap; gap: 8px; }
            .ethiopian-date { flex-direction: column; gap: 10px; text-align: center; padding: 12px; }
            .card { padding: 18px; gap: 14px; }
            .card-icon { width: 55px; height: 55px; font-size: 22px; }
            .card .value { font-size: 24px; }
            .filters, .transactions-section, .chart-container, .payment-methods { padding: 16px; }
            .filter-row { flex-direction: column; }
            .filter-group { min-width: 100%; }
            .filter-actions { width: 100%; }
            .filter-btn { flex: 1; justify-content: center; }
            .date-buttons { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .date-btn { flex: none; min-width: 0; padding: 10px 6px; font-size: 12px; }
            .chart-wrapper { height: 220px; }
            .payment-list { grid-template-columns: 1fr; }
            .section-header { flex-direction: column; align-items: stretch; }
            .section-header > div { flex-wrap: wrap; justify-content: space-between; }

            /* Transactions table -> stacked cards */
            .transactions-table thead { display: none; }
            .transactions-table, .transactions-table tbody, .transactions-table tr, .transactions-table td {
                display: block;
                width: 100%;
            }
            .transactions-table { border-radius: 0; }
            .transactions-table tr {
                background: white;
                border: 1px solid #eee;
                border-radius: 10px;
                margin-bottom: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                overflow: hidden;
            }
            .transactions-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                padding: 10px 14px;
                text-align: right;
                border-bottom: 1px solid #f1f1f1;
            }
            .transactions-table td:last-child { border-bottom: none; }
            .transactions-table td::before {
                content: attr(data-label);
                font-weight: 700;
                color: #888;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                text-align: left;
                flex-shrink: 0;
            }
            .edit-form { padding: 18px; width: 92%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Branch Selector for Super Admin -->
        <?php if ($user_role == 'super_admin'): ?>
        <div class="branch-selector-top">
            <div class="branch-info-badge">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?>
            </div>
            <form method="GET" action="">
                <select name="branch_id" onchange="this.form.submit()">
                    <option value="">-- ቅርንጫፍ ይምረጡ --</option>
                    <?php
                    $all_branches = getAllBranches($conn);
                    foreach ($all_branches as $b):
                    ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($b['place_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="date_range" value="<?php echo $date_range; ?>">
            </form>
            <div>
                <i class="fas fa-info-circle"></i> ሱፐር አድሚን: ቅርንጫፍ ይምረጡ
            </div>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-history"></i> የሽያጭ መዝገብ
                <span class="branch-badge">
                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?>
                </span>
            </h1>
            <button class="back-btn" onclick="window.location.href='seller_pos.php'">
                <i class="fas fa-arrow-left"></i> ወደ መሸጫው ለመመለስ
            </button>
        </div>

        <!-- Ethiopian Date Info -->
        <div class="ethiopian-date">
            <div class="date-info">
                <i class="fas fa-calendar-alt"></i>
                ዛሬ በኢትዮጵያ ዘመን አቆጣጠር:
                <span id="ethiopianDateDisplay">
                    <?php echo $current_ethiopian['day'] . ' ' . $current_ethiopian['month_name'] . ' ' . $current_ethiopian['year']; ?>
                </span>
                <span style="background: #5d4037; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;">
                    ቀን <?php echo $current_ethiopian['day']; ?>
                </span>
            </div>
            <div class="time-info" id="ethiopianTime">
                <i class="fas fa-clock"></i>
                ሰዓት: <span id="ethTimeDisplay"><?php echo $current_ethiopian['time_12h']; ?></span>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="card">
                <div class="card-icon"><i class="fas fa-receipt"></i></div>
                <div class="card-content">
                    <h3>ጠቅላላ ሽያጭ</h3>
                    <div class="value" id="summaryTotalTx"><?php echo number_format($total_transactions); ?></div>
                    <div class="subtext">
                        <i class="fas fa-calendar"></i>
                        <?php echo $display_start_date; ?> - <?php echo $display_end_date; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="card-content">
                    <h3>ጠቅላላ የተገኘው ብር</h3>
                    <div class="value" id="summaryTotalSales"><?php echo number_format($total_sales_amount, 2); ?> ETB</div>
                    <div class="subtext">
                        <i class="fas fa-chart-line"></i>
                        <span id="summaryAvgSales">
                        <?php
                        $avg = ($total_transactions > 0) ? $total_sales_amount / $total_transactions : 0;
                        echo "አማካይ: " . number_format($avg, 2) . " ETB";
                        ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="card-content">
                    <h3>የዕለቱ ገንዘብ ማውጣት</h3>
                    <div class="value" id="summaryWithdrawalCount"><?php echo number_format($todays_withdrawal_count); ?></div>
                    <div class="subtext">
                        <i class="fas fa-calendar-day"></i>
                        <?php echo $display_start_date; ?> - <?php echo $display_end_date; ?>
                        <?php if ($todays_withdrawal_count > 0): ?>
                            <br><span style="color: #f57c00; font-weight: 600;" id="summaryWithdrawalAmount">
                                ጠቅላላ: <?php echo number_format($total_withdrawals_amount, 2); ?> ETB
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Summary -->
        <div class="balance-summary">
            <div class="balance-item sales-balance">
                <div class="balance-label">ጠቅላላ ሽያጭ</div>
                <div class="balance-value" id="balanceTotalSales"><?php echo number_format($total_sales_amount, 2); ?> ETB</div>
            </div>
            <div class="balance-item withdrawals-balance">
                <div class="balance-label">ጠቅላላ የተወጣ</div>
                <div class="balance-value" id="balanceWithdrawn">- <?php echo number_format($total_withdrawals_amount, 2); ?> ETB</div>
            </div>
            <div class="balance-item net-balance">
                <div class="balance-label">ንጹህ ቀሪ ሂሳብ</div>
                <div class="balance-value" id="balanceNet"><?php echo number_format($net_balance, 2); ?> ETB</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <h2><i class="fas fa-filter"></i> መፈለጊያ እና መለያ</h2>
            <form method="GET" action="" id="filterForm">
                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                <div class="date-buttons">
                    <?php
                    $dateRanges = [
                        'today'   => 'ዛሬ',
                        'yesterday' => 'ትላንት',
                        '3day'    => '3 ቀን',
                        '7day'    => '7 ቀን',
                        '14day'   => '2 ሳምንት',
                        '21day'   => '3 ሳምንት',
                        '30day'   => '1 ወር',
                        '60day'   => '2 ወር',
                        '90day'   => '3 ወር',
                        '180day'  => '6 ወር',
                        '365day'  => '1 አመት'
                    ];
                    foreach ($dateRanges as $key => $label) {
                        $active = ($key == $date_range) ? 'active' : '';
                        echo '<button type="button" class="date-btn ' . $active . '" onclick="setDateRange(\'' . $key . '\')">' . $label . '</button>';
                    }
                    ?>
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-receipt"></i> በደረሰኝ ቁጥር ለመፈለግ</label>
                        <input type="number" name="search_receipt_id" value="<?php echo htmlspecialchars($search_receipt_id); ?>" placeholder="የደረሰኝ ቁጥር አስገባ...">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> በስም ለመፈለግ</label>
                        <input type="text" name="search_item" value="<?php echo htmlspecialchars($search_item); ?>" placeholder="ስሙን እዚህ ጋር ይጻፉ...">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-credit-card"></i> የክፍያ መንገዶች</label>
                        <select name="payment_method">
                            <option value="">All Methods</option>
                            <option value="cash"      <?php echo $filter_payment == 'cash'      ? 'selected' : ''; ?>>💵 Cash</option>
                            <option value="abyssinia" <?php echo $filter_payment == 'abyssinia' ? 'selected' : ''; ?>>🏦 Abyssinia Bank</option>
                            <option value="cbe"       <?php echo $filter_payment == 'cbe'       ? 'selected' : ''; ?>>🏦 CBE Bank</option>
                            <option value="telebirr"  <?php echo $filter_payment == 'telebirr'  ? 'selected' : ''; ?>>📱 Telebirr</option>
                        </select>
                    </div>
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> በሻጭ በስም ለመፈለግ</label>
                        <input type="text" name="seller" value="<?php echo htmlspecialchars($filter_seller); ?>" placeholder="Search seller name...">
                    </div>
                </div>

                <input type="hidden" name="date_range" id="date_range_input" value="<?php echo htmlspecialchars($date_range); ?>">

                <div class="filter-actions">
                    <button type="submit" class="filter-btn apply-btn">
                        <i class="fas fa-search"></i> Search & Filter
                    </button>
                    <button type="button" class="filter-btn reset-btn" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset All
                    </button>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="transactions-section">
            <div class="section-header">
                <h2><i class="fas fa-list"></i> Transaction Details</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span style="color: #666; font-size: 14px;">
                        <i class="fas fa-info-circle"></i>
                        Total: <span id="totalRecords"><?php echo $total_records; ?></span> transactions
                    </span>
                    <button class="export-btn" onclick="window.location.href='export_daily_view.php?branch_id=<?php echo $branch_id; ?>&date_range=<?php echo $date_range; ?>&seller=<?php echo urlencode($filter_seller); ?>&payment_method=<?php echo urlencode($filter_payment); ?>&search_item=<?php echo urlencode($search_item); ?>&search_receipt_id=<?php echo urlencode($search_receipt_id); ?>'">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                </div>
            </div>

            <div id="transactionsContainer">
                <div style="text-align: center; padding: 40px;">
                    <div class="loading" style="margin: 0 auto;"></div>
                    <p style="margin-top: 10px; color: #666;">Loading transactions...</p>
                </div>
            </div>

            <div id="paginationContainer" class="pagination-container" style="display: none;">
                <button class="pagination-btn" id="prevBtn" onclick="changePage(currentPage - 1)" disabled>
                    <i class="fas fa-chevron-left"></i> Previous
                </button>
                <div id="pageNumbers" style="display: flex; gap: 5px;"></div>
                <button class="pagination-btn" id="nextBtn" onclick="changePage(currentPage + 1)">
                    Next <i class="fas fa-chevron-right"></i>
                </button>
                <span class="pagination-info">
                    Page <span id="currentPageDisplay">1</span> of <span id="totalPagesDisplay"><?php echo $total_pages; ?></span>
                </span>
            </div>
        </div>

        <!-- Chart Section -->
        <?php if (count($daily_data) > 0): ?>
        <div class="chart-container">
            <h2><i class="fas fa-chart-line"></i> Daily Sales Trend</h2>
            <div class="chart-wrapper">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment Methods -->
        <?php if ($payment_result && mysqli_num_rows($payment_result) > 0): ?>
        <div class="payment-methods">
            <h2><i class="fas fa-credit-card"></i> Payment Methods Breakdown</h2>
            <div class="payment-list">
                <?php while ($payment = mysqli_fetch_assoc($payment_result)): ?>
                <div class="payment-item">
                    <div class="payment-name">
                        <?php
                        $icons = ['cash' => 'fa-money-bill', 'abyssinia' => 'fa-university', 'cbe' => 'fa-university', 'telebirr' => 'fa-mobile-alt'];
                        $icon  = isset($icons[$payment['payment_method']]) ? $icons[$payment['payment_method']] : 'fa-credit-card';
                        ?>
                        <i class="fas <?php echo $icon; ?>"></i>
                        <?php
                        $names = ['cash' => 'Cash', 'abyssinia' => 'Abyssinia Bank', 'cbe' => 'CBE Bank', 'telebirr' => 'Telebirr'];
                        echo $names[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
                        ?>
                    </div>
                    <div class="payment-stats">
                        <span><?php echo $payment['count']; ?> transactions</span>
                        <span><strong><?php echo number_format($payment['amount'], 2); ?> ETB</strong></span>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="edit-modal">
        <div class="edit-form">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="color: #2c3e50; margin: 0; font-family: Arial, sans-serif;">
                    <i class="fas fa-receipt"></i> Transaction Receipt
                </h3>
                <button onclick="closeReceipt()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">×</button>
            </div>
            <div id="receiptContent">
                <!-- Receipt will be loaded here -->
            </div>
            <div style="text-align: center; margin-top: 20px; font-family: Arial, sans-serif;" class="no-print">
                <button onclick="printReceipt()" class="filter-btn apply-btn" style="margin-right: 10px; display: inline-flex;">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
                <button onclick="closeReceipt()" class="filter-btn reset-btn" style="display: inline-flex;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
    let currentPage  = 1;
    let totalPages   = <?php echo $total_pages; ?>;
    let totalRecords = <?php echo $total_records; ?>;
    let perPage      = <?php echo $per_page; ?>;

    const dateRange       = '<?php echo addslashes($date_range); ?>';
    const filterSeller    = '<?php echo addslashes($filter_seller); ?>';
    const filterPayment   = '<?php echo addslashes($filter_payment); ?>';
    const searchItem      = '<?php echo addslashes($search_item); ?>';
    const searchReceiptId = '<?php echo addslashes($search_receipt_id); ?>';
    const branchId        = <?php echo $branch_id; ?>;

    const currentEthiopianDate = '<?php echo $current_ethiopian['full_date']; ?>';

    document.addEventListener('DOMContentLoaded', function() { loadPage(1); });

    function loadPage(page) {
        const container          = document.getElementById('transactionsContainer');
        const paginationContainer = document.getElementById('paginationContainer');

        container.innerHTML = `<div style="text-align: center; padding: 40px;"><div class="loading" style="margin: 0 auto;"></div><p style="margin-top: 10px; color: #666;">Loading transactions...</p></div>`;

        let url = `?ajax=1&date_range=${encodeURIComponent(dateRange)}&page=${page}&branch_id=${branchId}`;
        if (filterSeller)    url += `&seller=${encodeURIComponent(filterSeller)}`;
        if (filterPayment)   url += `&payment_method=${encodeURIComponent(filterPayment)}`;
        if (searchItem)      url += `&search_item=${encodeURIComponent(searchItem)}`;
        if (searchReceiptId) url += `&search_receipt_id=${encodeURIComponent(searchReceiptId)}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentPage  = data.current_page;
                    totalPages   = data.total_pages;
                    totalRecords = data.total_records;

                    document.getElementById('totalRecords').textContent      = totalRecords;
                    document.getElementById('currentPageDisplay').textContent = currentPage;
                    document.getElementById('totalPagesDisplay').textContent  = totalPages;

                    if (data.summary) {
                        const s = data.summary;
                        const fmtNum = (n, decimals = 0) => Number(n).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
                        const elTx = document.getElementById('summaryTotalTx');
                        const elSales = document.getElementById('summaryTotalSales');
                        const elAvg = document.getElementById('summaryAvgSales');
                        const elWCount = document.getElementById('summaryWithdrawalCount');
                        const elBalSales = document.getElementById('balanceTotalSales');
                        const elBalWith = document.getElementById('balanceWithdrawn');
                        const elBalNet = document.getElementById('balanceNet');

                        if (elTx) elTx.textContent = fmtNum(s.total_transactions);
                        if (elSales) elSales.textContent = fmtNum(s.total_sales, 2) + ' ETB';
                        if (elAvg) elAvg.textContent = 'አማካይ: ' + fmtNum(s.avg_sale, 2) + ' ETB';
                        if (elWCount) elWCount.textContent = fmtNum(s.withdrawal_count);
                        if (elBalSales) elBalSales.textContent = fmtNum(s.total_sales, 2) + ' ETB';
                        if (elBalWith) elBalWith.textContent = '- ' + fmtNum(s.withdrawal_amount, 2) + ' ETB';
                        if (elBalNet) elBalNet.textContent = fmtNum(s.net_balance, 2) + ' ETB';
                    }

                    renderTransactionsTable(data.transactions);
                    renderPagination();
                    paginationContainer.style.display = 'flex';
                } else {
                    container.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>Error loading data</h3><p>${data.message || 'An error occurred'}</p></div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Fallback to IndexedDB / offline transactions if online network fails
                if (window.aleltuDB && typeof window.aleltuDB.getProducts === 'function') {
                    loadOfflineHistory();
                } else {
                    container.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>Connection Error</h3><p>Failed to load transactions. Please try again.</p></div>`;
                }
            });
    }

    async function loadOfflineHistory() {
        const container = document.getElementById('transactionsContainer');
        try {
            if (!window.aleltuDB || !window.aleltuDB.db) {
                container.innerHTML = `<div class="no-data"><i class="fas fa-wifi"></i><h3>Connection Error</h3><p>Failed to load transactions. Please try again.</p></div>`;
                return;
            }
            const tx = window.aleltuDB.db.transaction(['sales'], 'readonly');
            const req = tx.objectStore('sales').getAll();
            req.onsuccess = () => {
                const sales = req.result || [];
                if (sales.length === 0) {
                    container.innerHTML = `<div class="no-data"><i class="fas fa-clipboard-list"></i><h3>No Offline Sales Found</h3><p>Sales made without internet will appear here until they sync.</p></div>`;
                    return;
                }
                // newest first, same ordering as the online view
                sales.sort((a, b) => new Date(b.created_locally_at) - new Date(a.created_locally_at));

                let html = `<div class="offline-banner">
                        <i class="fas fa-wifi-slash"></i>
                        Showing offline data — will refresh automatically once internet returns.
                    </div>
                    <div style="overflow-x: auto;"><table class="transactions-table"><thead>
                    <tr><th>Offline ID</th><th>Date</th><th>Seller</th><th>Amount</th><th>Status</th></tr>
                </thead><tbody>`;
                sales.forEach(s => {
                    const statusLabel = (s.status === 'synced') ? 'Synced' : 'Pending Sync';
                    const statusClass = (s.status === 'synced') ? 'payment-cash' : 'payment-badge';
                    html += `<tr>
                        <td class="transaction-id" data-label="Offline ID">${escapeHtml(String(s.sale_uuid).substring(0,8))}...</td>
                        <td data-label="Date & Time">${new Date(s.created_locally_at).toLocaleString()}</td>
                        <td data-label="Seller"><strong>${escapeHtml(s.seller_name || 'Seller')}</strong></td>
                        <td class="amount" data-label="Total Amount">${parseFloat(s.total_amount || 0).toFixed(2)} ETB</td>
                        <td data-label="Status"><span class="payment-badge ${statusClass}"><i class="fas fa-clock"></i> ${statusLabel}</span></td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
                container.innerHTML = html;
                document.getElementById('paginationContainer').style.display = 'none';
            };
            req.onerror = () => {
                container.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>Offline Data Error</h3></div>`;
            };
        } catch (e) {
            container.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>Offline Data Error</h3></div>`;
        }
    }

    function renderTransactionsTable(transactions) {
        const container = document.getElementById('transactionsContainer');
        if (transactions.length === 0) {
            container.innerHTML = `<div class="no-data"><i class="fas fa-clipboard-list"></i><h3>No transactions found</h3><p>No sales records for the selected period.</p></div>`;
            return;
        }

        let html = `<div style="overflow-x: auto;"><table class="transactions-table"><thead>
            <tr><th>Receipt ID</th><th>Ethiopian Date & Time</th><th>Seller</th><th>Items</th><th>Total Amount</th><th>Payment</th><th>Receipt</th></tr>
        </thead><tbody>`;

        transactions.forEach(trans => {
            const ethDate        = trans.eth_date;
            const ethDateDisplay = ethDate.day + ' ' + ethDate.month_name + ' ' + ethDate.year + ', ' + ethDate.time_12h;
            const isToday        = (ethDate.full_date === currentEthiopianDate);
            const rowStyle       = isToday ? 'style="background-color: #f0f9ff;"' : '';

            const paymentNames = { 'cash': 'Cash', 'abyssinia': 'Abyssinia', 'cbe': 'CBE', 'telebirr': 'Telebirr' };
            const paymentIcons = { 'cash': 'fa-money-bill', 'abyssinia': 'fa-university', 'cbe': 'fa-university', 'telebirr': 'fa-mobile-alt' };
            const icon        = paymentIcons[trans.payment_method] || 'fa-credit-card';
            const paymentName = paymentNames[trans.payment_method] || trans.payment_method;
            const paymentClass = 'payment-' + trans.payment_method;

            html += `<tr ${rowStyle}>
                <td class="transaction-id" data-label="Receipt ID">#${String(trans.id).padStart(6, '0')}</td>
                <td data-label="Date & Time">${ethDateDisplay}${isToday ? '<span style="background: #4CAF50; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px;">Today</span>' : ''}</td>
                <td data-label="Seller"><strong>${escapeHtml(trans.seller_name)}</strong></td>
                <td data-label="Items">${trans.item_count} items</td>
                <td class="amount" data-label="Total Amount">${parseFloat(trans.total_amount).toFixed(2)} ETB</td>
                <td data-label="Payment"><span class="payment-badge ${paymentClass}"><i class="fas ${icon}"></i> ${paymentName}</span></td>
                <td data-label="Receipt"><button class="view-btn" onclick="viewReceipt(${trans.id})"><i class="fas fa-receipt"></i> View</button></td>
             </tr>`;
        });

        html += `</tbody></table></div>`;
        container.innerHTML = html;
    }

    function renderPagination() {
        const pageNumbersDiv = document.getElementById('pageNumbers');
        const prevBtn        = document.getElementById('prevBtn');
        const nextBtn        = document.getElementById('nextBtn');

        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;

        let pagesHtml      = '';
        const maxVisible   = 5;
        let startPage      = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage        = Math.min(totalPages, startPage + maxVisible - 1);

        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        if (startPage > 1) {
            pagesHtml += `<button class="pagination-btn" onclick="changePage(1)">1</button>`;
            if (startPage > 2) pagesHtml += `<span style="padding: 8px;">...</span>`;
        }

        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            pagesHtml += `<button class="pagination-btn ${activeClass}" onclick="changePage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) pagesHtml += `<span style="padding: 8px;">...</span>`;
            pagesHtml += `<button class="pagination-btn" onclick="changePage(${totalPages})">${totalPages}</button>`;
        }

        pageNumbersDiv.innerHTML = pagesHtml;
    }

    function changePage(page) {
        if (page < 1 || page > totalPages || page === currentPage) return;
        loadPage(page);
        document.querySelector('.transactions-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    <?php if (count($daily_data) > 0): ?>
    const dailyData = <?php echo json_encode($daily_data); ?>;
    const ctx = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: dailyData.map(day => day.ethiopian_day),
            datasets: [{
                label: 'Daily Sales (ETB)',
                data: dailyData.map(day => day.total),
                backgroundColor: 'rgba(102,126,234,0.1)',
                borderColor: 'rgba(102,126,234,1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgba(102,126,234,1)',
                pointBorderColor: '#fff',
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
                        callback: function(value) { return value.toFixed(2) + ' ETB'; }
                    }
                }
            }
        }
    });
    <?php endif; ?>

    function setDateRange(range) {
        document.getElementById('date_range_input').value = range;
        document.getElementById('filterForm').submit();
    }

    // ========== RECEIPT FUNCTIONS - Using separate API file ==========
    function viewReceipt(id) {
        const modal   = document.getElementById('receiptModal');
        const content = document.getElementById('receiptContent');

        content.innerHTML = `<div style="text-align: center; padding: 20px;">
            <div class="loading" style="margin: 0 auto;"></div>
            <p style="margin-top: 10px; color: #666;">Loading receipt...</p>
        </div>`;
        modal.style.display = 'flex';

        // Using the separate get_transaction_details.php API file
        const url = 'get_transaction_details.php?id=' + id;

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error('HTTP error ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const trans       = data.transaction;
                    const items       = data.items;
                    const eth         = data.eth_date;
                    const totalAmount = trans.total_amount.toFixed(2);

                    const paymentNames = {
                        'cash':      '💵 ካሽ',
                        'abyssinia': '🏦 አቢሲንያ ባንክ',
                        'cbe':       '🏦 ሲቢኢ ባንክ',
                        'telebirr':  '📱 ቴሌብር'
                    };
                    const paymentDisplay = paymentNames[trans.payment_method] || trans.payment_method;

                    let itemsHtml = '';
                    items.forEach(item => {
                        itemsHtml += `<tr>
                            <td>${escapeHtml(item.product_name)}</td>
                            <td class="qty">${item.quantity.toFixed(2)}</td>
                            <td class="price">${item.unit_price.toFixed(2)}</td>
                            <td class="total">${item.subtotal.toFixed(2)}</td>
                        </tr>`;
                    });

                    content.innerHTML = `
                        <div class="receipt-container">
                            <div class="receipt-header">
                                <div class="title">አሌልቱ የእንስሳት ተዋጽኦ</div>
                                <div class="subtitle">Animal Products Supplier</div>
                                <div class="address">Addis Ababa, Ethiopia</div>
                                <div class="address">Phone: +251 996089048</div>
                            </div>
                            <div class="receipt-info">
                                <div class="info-row">
                                    <span class="info-label">የደረሰኝ ቁጥር:</span>
                                    <span>${String(trans.id).padStart(6, '0')}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">ቀን (ኢትዮጵያ):</span>
                                    <span>${eth.day} ${eth.month_name} ${eth.year}, ${eth.time_12h}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">ሻጭ:</span>
                                    <span>${escapeHtml(trans.seller_name)}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">የመክፈያ መንገድ:</span>
                                    <span>${paymentDisplay}</span>
                                </div>
                                ${trans.paid_amount ? `<div class="info-row">
                                    <span class="info-label">የተከፈለ:</span>
                                    <span>${trans.paid_amount.toFixed(2)} ብር</span>
                                </div>` : ''}
                                ${trans.change_amount ? `<div class="info-row">
                                    <span class="info-label">ቀሪ:</span>
                                    <span>${trans.change_amount.toFixed(2)} ብር</span>
                                </div>` : ''}
                            </div>
                            <table class="items-table">
                                <thead>
                                    <tr><th>ዕቃ</th><th class="qty">ብዛት</th><th class="price">ዋጋ</th><th class="total">ጠቅላላ</th></tr>
                                </thead>
                                <tbody>${itemsHtml}</tbody>
                            </table>
                            <div class="receipt-totals">
                                <div class="total-row grand-total">
                                    <span>ጠቅላላ ድምር:</span>
                                    <span>${totalAmount} ብር</span>
                                </div>
                            </div>
                            <div class="receipt-footer">
                                <div>ለንግድዎ እናመሰግናለን!</div>
                                <div>Thank you for your business!</div>
                                <div style="margin-top: 10px; font-size: 11px;">Printed: ${new Date().toLocaleString()}</div>
                            </div>
                        </div>`;
                } else {
                    content.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>${data.message || 'Error loading receipt'}</h3></div>`;
                }
            })
            .catch(error => {
                console.error('Receipt error:', error);
                content.innerHTML = `<div class="no-data"><i class="fas fa-exclamation-triangle"></i><h3>Connection Error</h3><p>${error.message}</p><p>Please refresh and try again.</p></div>`;
            });
    }

    function closeReceipt() {
        document.getElementById('receiptModal').style.display = 'none';
    }

    function printReceipt() {
        const receiptHTML = document.querySelector('.receipt-container');
        if (!receiptHTML) { alert('No receipt to print'); return; }

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`<!DOCTYPE html><html><head><title>Print Receipt</title><style>
            body { font-family: 'Courier New', monospace; padding: 20px; margin: 0; }
            .receipt-container { max-width: 320px; margin: 0 auto; }
            .receipt-header { text-align: center; padding-bottom: 15px; margin-bottom: 15px; border-bottom: 2px dashed #333; }
            .receipt-header .title { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
            .receipt-header .subtitle { font-size: 16px; margin-bottom: 10px; }
            .receipt-header .address { font-size: 12px; margin-bottom: 5px; }
            .receipt-info { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px dashed #ddd; }
            .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 13px; }
            .info-label { font-weight: bold; }
            .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 13px; }
            .items-table th { text-align: left; padding: 8px 0; border-bottom: 2px solid #333; font-weight: bold; }
            .items-table td { padding: 5px 0; border-bottom: 1px solid #eee; }
            .items-table .qty { text-align: center; width: 60px; }
            .items-table .price, .items-table .total { text-align: right; width: 80px; }
            .receipt-totals { padding-top: 10px; border-top: 2px solid #333; }
            .grand-total { font-size: 18px; font-weight: bold; margin-top: 10px; padding-top: 10px; border-top: 2px dashed #333; }
            .receipt-footer { text-align: center; padding-top: 15px; margin-top: 15px; border-top: 1px dashed #333; font-size: 12px; }
            .total-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        </style></head><body>${receiptHTML.outerHTML}</body></html>`);
        printWindow.document.close();
        printWindow.print();
        printWindow.close();
    }

    function resetFilters() {
        window.location.href = 'history.php?branch_id=' + branchId;
    }

    function updateEthiopianTime() {
        const now          = new Date();
        const ethiopianTime = new Date(now.getTime() + (3 * 60 * 60 * 1000));
        let hours          = ethiopianTime.getUTCHours();
        const minutes      = String(ethiopianTime.getUTCMinutes()).padStart(2, '0');
        const seconds      = String(ethiopianTime.getUTCSeconds()).padStart(2, '0');
        const hour12       = hours % 12;
        const displayHour  = hour12 === 0 ? 12 : hour12;
        const ampm         = hours < 12 ? 'ጥዋት' : 'ከሰዓት';
        const timeDisplay  = document.getElementById('ethTimeDisplay');
        if (timeDisplay) timeDisplay.textContent = `${displayHour}:${minutes}:${seconds} ${ampm}`;
    }

    updateEthiopianTime();
    setInterval(updateEthiopianTime, 1000);

    // Close modal when clicking outside
    document.getElementById('receiptModal').addEventListener('click', function(e) {
        if (e.target === this) closeReceipt();
    });
    </script>
</body>
</html>
<?php
if (isset($result) && $result)          mysqli_free_result($result);
if (isset($payment_result) && $payment_result) mysqli_free_result($payment_result);
if (isset($daily_result) && $daily_result)    mysqli_free_result($daily_result);
if (isset($totals_result) && $totals_result)  mysqli_free_result($totals_result);
if (isset($count_result) && $count_result)    mysqli_free_result($count_result);
mysqli_close($conn);
?>
