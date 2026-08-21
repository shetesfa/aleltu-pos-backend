<?php
session_start();
require_once 'config.php';

// ─── LOGIN CHECK ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit();
}
set_time_limit(120); // prevent 504 timeout
// ─────────────────────────────────────────────────────────────────────────────

// ===== CRITICAL FIX: Set correct timezone =====
date_default_timezone_set('Africa/Addis_Ababa');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ===== FIX: Force MySQL to use Ethiopia timezone =====
mysqli_query($conn, "SET time_zone = '+03:00'");

// ===== FIX: Get current time in Ethiopia =====
$ethiopia_time = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$current_time_for_db = $ethiopia_time->format('Y-m-d H:i:s');
$current_time_display = $ethiopia_time->format('h:i:s A');

// Get branch info
$user_branch = getUserBranch($conn, $_SESSION['user_id']);
$current_branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// ========== Ethiopian Date Function (for display only) ==========
function get_ethiopian_date($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    $greg_year = (int)date('Y', $timestamp);
    $greg_month = (int)date('n', $timestamp);
    $greg_day = (int)date('j', $timestamp);
    
    $ethiopian_months = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ህዳር', 4 => 'ታህሳስ',
        5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
        9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'
    ];
    
    $is_gregorian_leap = (($greg_year % 4 == 0 && $greg_year % 100 != 0) || ($greg_year % 400 == 0));
    $new_year_day = ($is_gregorian_leap && $greg_month > 9) ? 12 : 11;
    
    if ($greg_month > 9 || ($greg_month == 9 && $greg_day >= $new_year_day)) {
        $ethiopian_year = $greg_year - 7;
    } else {
        $ethiopian_year = $greg_year - 8;
    }
    
    $month_days = [30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 5];
    $is_ethiopian_leap = (($ethiopian_year % 4) == 3);
    if ($is_ethiopian_leap) {
        $month_days[12] = 6;
    }
    
    $new_year_timestamp = mktime(0, 0, 0, 9, $new_year_day, $greg_year);
    if ($timestamp < $new_year_timestamp) {
        $prev_year = $greg_year - 1;
        $prev_year_leap = (($prev_year % 4 == 0 && $prev_year % 100 != 0) || ($prev_year % 400 == 0));
        $prev_new_year_day = ($prev_year_leap) ? 12 : 11;
        $new_year_timestamp = mktime(0, 0, 0, 9, $prev_new_year_day, $prev_year);
    }
    
    $days_since_new_year = floor(($timestamp - $new_year_timestamp) / (60 * 60 * 24));
    $remaining_days = $days_since_new_year;
    $ethiopian_month = 1;
    
    for ($m = 0; $m < 13; $m++) {
        if ($remaining_days < $month_days[$m]) {
            $ethiopian_month = $m + 1;
            $ethiopian_day = $remaining_days + 1;
            break;
        }
        $remaining_days -= $month_days[$m];
    }
    
    return [
        'date' => $ethiopian_year . '/' . sprintf('%02d', $ethiopian_month) . '(' . $ethiopian_months[$ethiopian_month] . ')/' . sprintf('%02d', $ethiopian_day),
        'year' => $ethiopian_year,
        'month' => $ethiopian_month,
        'month_name' => $ethiopian_months[$ethiopian_month],
        'day' => $ethiopian_day
    ];
}

// Get current Ethiopian date for display
$current_ethiopian = get_ethiopian_date();
$current_eth_date = $current_ethiopian['date'];

// Get current Gregorian time for header
$current_gregorian_time = date('h:i:s A');

// Get all users for dropdown - EXCLUDE user with ID 3 (Tesfa) AND same branch
$users_query = mysqli_query($conn, "SELECT id, username FROM users WHERE is_active = 1 AND id != 3 AND branch_id = $current_branch_id");
$users = [];
while($user = mysqli_fetch_assoc($users_query)) {
    $users[] = $user;
}

// Get today's sales total from transactions table with branch filter
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$sales_query = mysqli_query($conn, "
    SELECT SUM(total_amount) as total_sales 
    FROM transactions 
    WHERE transaction_date BETWEEN '$today_start' AND '$today_end'
    AND branch_id = $current_branch_id
");
$sales_data = mysqli_fetch_assoc($sales_query);
$total_sales = $sales_data['total_sales'] ?? 0.00;

// ===== FIXED: Handle withdrawal submission with ALL fields populated =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_withdrawal'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $user_id         = intval($_POST['user_id']);
    $amount          = floatval($_POST['amount']);
    $reason          = trim($_POST['reason']);
    $payment_type    = trim($_POST['payment_type']);
    $withdrawal_type = trim($_POST['withdrawal_type']);

    if ($amount > 0 && $user_id > 0) {
        $user_query = mysqli_prepare($conn, "SELECT username FROM users WHERE id = ?");
        mysqli_stmt_bind_param($user_query, 'i', $user_id);
        mysqli_stmt_execute($user_query);
        $user_res  = mysqli_stmt_get_result($user_query);
        $user_data = mysqli_fetch_assoc($user_res);
        mysqli_stmt_close($user_query);
        $username = $user_data['username'] ?? 'Unknown';

        $now          = date('Y-m-d H:i:s');
        $today_date   = date('Y-m-d');
        $current_time = date('h:i:s A');

        $eth_date_data = get_ethiopian_date(strtotime($now));
        $eth_date_str  = $eth_date_data['date'];

        // Prepared statement — no string injection possible
        $ins = mysqli_prepare($conn,
            "INSERT INTO daily_withdrawals
                (user_id, username, amount, reason, payment_type, withdrawal_type,
                 ethiopian_date, ethiopian_time, gregorian_date, gregorian_time, created_at, branch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($ins, 'isdssssssssi',
            $user_id, $username, $amount, $reason, $payment_type, $withdrawal_type,
            $eth_date_str, $current_time, $today_date, $current_time, $now, $current_branch_id);
        if (mysqli_stmt_execute($ins)) {
            mysqli_stmt_close($ins);
            $_SESSION['success_message'] = "Withdrawal of $amount ETB registered for $username at $current_time";
            header("Location: daily_cashier.php");
            exit();
        } else {
            mysqli_stmt_close($ins);
            $_SESSION['error_message'] = "Error: Could not register withdrawal. Please try again.";
            header("Location: daily_cashier.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Error: Please fill all fields correctly";
        header("Location: daily_cashier.php");
        exit();
    }
}

$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Get today's total withdrawals with branch filter
$today_withdrawals_query = mysqli_query($conn, "
    SELECT SUM(amount) as total_withdrawals 
    FROM daily_withdrawals 
    WHERE DATE(created_at) = CURDATE() AND branch_id = $current_branch_id
");
$today_withdrawals_data = mysqli_fetch_assoc($today_withdrawals_query);
$total_today_withdrawals = $today_withdrawals_data['total_withdrawals'] ?? 0.00;

// Get ALL withdrawals total with branch filter
$all_withdrawals_query = mysqli_query($conn, "
    SELECT SUM(amount) as total_withdrawals 
    FROM daily_withdrawals 
    WHERE branch_id = $current_branch_id
");
$all_withdrawals_data = mysqli_fetch_assoc($all_withdrawals_query);
$total_all_withdrawals = $all_withdrawals_data['total_withdrawals'] ?? 0.00;

$current_balance = $total_sales - $total_today_withdrawals;

// Get ALL withdrawals list with branch filter
$all_withdrawals_list_query = mysqli_query($conn, "
    SELECT dw.*, u.username 
    FROM daily_withdrawals dw
    LEFT JOIN users u ON dw.user_id = u.id
    WHERE dw.branch_id = $current_branch_id
    ORDER BY dw.created_at DESC
    LIMIT 500
"); /* LIMIT 500 prevents loading all-time data at once and causing 504 */

$temp_withdrawals = [];
$perm_withdrawals = [];

if(mysqli_num_rows($all_withdrawals_list_query) > 0) {
    mysqli_data_seek($all_withdrawals_list_query, 0);
    while($withdrawal = mysqli_fetch_assoc($all_withdrawals_list_query)) {
        if($withdrawal['withdrawal_type'] == 'ጊዜያዊ') {
            $temp_withdrawals[] = $withdrawal;
        } else {
            $perm_withdrawals[] = $withdrawal;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>Daily Withdrawal System - ዕለታዊ የገንዘብ ማውጣት</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        /* Phone-first defaults. Wider layouts are enhanced with min-width queries. */
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 8px; }
        @media (min-width: 600px) { body { padding: 20px; } }
        
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; border-radius: 12px; padding: 16px; margin-bottom: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); text-align: center; }
        @media (min-width: 600px) { .header { padding: 25px; margin-bottom: 25px; } }
        
        .header h1 { color: #333; font-size: 20px; display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; overflow-wrap: anywhere; }
        @media (min-width: 600px) { .header h1 { font-size: 28px; gap: 15px; } }
        .header h1 i { color: #667eea; }
        
        .branch-badge { display: inline-block; background: #667eea; color: white; padding: 5px 12px; border-radius: 20px; font-size: 13px; margin-top: 10px; max-width: 100%; overflow-wrap: anywhere; }
        
        .eth-date { background: #fff8e1; border-radius: 10px; padding: 14px; margin-bottom: 16px; border-left: 4px solid #f57c00; display: flex; justify-content: space-between; align-items: center; flex-direction: column; gap: 10px; }
        @media (min-width: 600px) { .eth-date { flex-direction: row; flex-wrap: wrap; margin-bottom: 25px; } }
        
        .date-display { font-size: 15px; font-weight: 700; color: #5d4037; display: flex; align-items: center; justify-content: center; gap: 8px; text-align: center; flex-wrap: wrap; }
        @media (min-width: 600px) { .date-display { font-size: 18px; text-align: left; } }
        
        .time-display { font-family: monospace; font-size: 13px; background: #5d4037; color: white; padding: 8px 12px; border-radius: 20px; display: flex; align-items: center; gap: 8px; }
        @media (min-width: 600px) { .time-display { font-size: 16px; } }
        
        .balance-summary { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 16px; }
        @media (min-width: 768px) { .balance-summary { grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; } }
        
        .balance-card { background: white; border-radius: 12px; padding: 16px; text-align: center; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        @media (min-width: 600px) { .balance-card { padding: 25px; } }
        
        .balance-label { font-size: 13px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        @media (min-width: 600px) { .balance-label { font-size: 14px; } }
        
        .balance-value { font-size: 26px; font-weight: 700; margin-bottom: 5px; }
        @media (min-width: 600px) { .balance-value { font-size: 32px; } }
        
        .sales-value { color: #4CAF50; }
        .withdrawals-value { color: #ff9800; }
        .current-value { color: <?php echo $current_balance >= 0 ? '#2196F3' : '#f44336'; ?>; font-size: 30px; }
        @media (min-width: 600px) { .current-value { font-size: 36px; } }
        
        .main-content { display: grid; grid-template-columns: 1fr; gap: 20px; }
        @media (min-width: 1200px) { .main-content { grid-template-columns: 1fr 2fr; gap: 25px; } }
        
        .withdrawal-form { background: white; border-radius: 12px; padding: 16px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
        @media (min-width: 600px) { .withdrawal-form { padding: 25px; } }
        
        /* Keep each expense list full width so all six columns remain readable on desktop. */
        .transactions-wrapper { display: grid; grid-template-columns: 1fr; gap: 20px; min-width: 0; }
        
        .transactions-list { background: white; border-radius: 12px; padding: 12px; box-shadow: 0 5px 20px rgba(0,0,0,0.08); max-height: 500px; overflow-y: auto; overflow-x: auto; }
        @media (min-width: 600px) { .transactions-list { padding: 20px; max-height: 600px; } }
        
        .form-title, .list-title { color: #333; margin-bottom: 15px; font-size: 16px; display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        @media (min-width: 600px) { .form-title, .list-title { font-size: 18px; margin-bottom: 20px; } }
        
        .list-title.temp { border-bottom-color: #ff9800; color: #e65100; flex-wrap: wrap; }
        .list-title.perm { border-bottom-color: #4CAF50; color: #2e7d32; flex-wrap: wrap; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; color: #555; font-size: 14px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 16px; transition: border-color 0.3s; min-height: 44px; }
        @media (min-width: 600px) { .form-group input, .form-group select, .form-group textarea { font-size: 14px; } }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #667eea; }
        
        .radio-group { display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px; }
        @media (min-width: 600px) { .radio-group { flex-direction: row; gap: 20px; } }
        
        .radio-option { display: flex; align-items: center; gap: 8px; min-height: 44px; }
        .radio-option input[type="radio"] { width: auto; margin-right: 5px; min-height: 20px; min-width: 20px; }
        
        .payment-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 15px; }
        .payment-btn { flex: 1 1 calc(50% - 8px); padding: 10px; border: 2px solid #e0e0e0; background: white; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 5px; min-height: 44px; }
        @media (min-width: 600px) { .payment-btn { flex: 1 1 calc(33.333% - 8px); } }
        @media (min-width: 900px) { .payment-btn { flex: 1 1 calc(25% - 8px); } }
        .payment-btn:hover { border-color: #667eea; background: #f0f3ff; }
        .payment-btn.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; }
        .payment-btn i { font-size: 14px; }
        
        .submit-btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.3s; margin-top: 10px; min-height: 48px; }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); }
        
        .transactions-table { width: 100%; border-collapse: collapse; border-radius: 10px; overflow: hidden; font-size: 12px; min-width: 500px; }
        @media (min-width: 600px) { .transactions-table { font-size: 13px; min-width: 560px; } }
        .transactions-table thead { background: #f5f5f5; color: #333; }
        .transactions-table th { padding: 10px 6px; text-align: left; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        @media (min-width: 600px) { .transactions-table th { padding: 12px 8px; font-size: 12px; } }
        .transactions-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.3s; }
        .transactions-table tbody tr:hover { background: #f8f9ff; }
        .transactions-table td { padding: 10px 6px; color: #555; font-size: 11px; }
        @media (min-width: 600px) { .transactions-table td { padding: 12px 8px; font-size: 12px; } }
        .transactions-table td:nth-child(3), .transactions-table td:nth-child(5) { overflow-wrap: anywhere; }

        /* On phones, render each transaction as a detail card instead of a horizontally scrolling table. */
        @media (max-width: 767px) {
            .transactions-list { overflow-x: hidden; }
            .transactions-table, .transactions-table tbody, .transactions-table tr, .transactions-table td { display: block; width: 100%; min-width: 0; }
            .transactions-table { border-radius: 0; }
            .transactions-table thead { display: none; }
            .transactions-table tbody { display: grid; gap: 10px; }
            .transactions-table tbody tr { background: #fafbff; border: 1px solid #e4e8f5; border-radius: 10px; padding: 6px 12px; }
            .transactions-table td { display: grid; grid-template-columns: minmax(92px, 42%) 1fr; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px solid #edf0f7; font-size: 13px; text-align: right; }
            .transactions-table td:last-child { border-bottom: none; }
            .transactions-table td::before { content: attr(data-label); color: #667085; font-size: 12px; font-weight: 700; text-align: left; }
            .transactions-table td:nth-child(1)::before { content: 'የኢትዮጵያ ቀን'; }
            .transactions-table td:nth-child(2)::before { content: 'ሰዓት'; }
            .transactions-table td:nth-child(3)::before { content: 'ሰው'; }
            .transactions-table td:nth-child(4)::before { content: 'ክፍያ'; }
            .transactions-table td:nth-child(5)::before { content: 'ምክንያት'; }
            .transactions-table td:nth-child(6)::before { content: 'መጠን'; }
            .transactions-table .withdrawal-amount { font-size: 14px; }
        }
        
        .withdrawal-amount { font-weight: 700; color: #ff9800; font-size: 12px; }
        @media (min-width: 600px) { .withdrawal-amount { font-size: 13px; } }
        .payment-type-badge { display: inline-block; padding: 3px 6px; border-radius: 12px; font-size: 9px; font-weight: 600; background: #e3f2fd; color: #1976d2; white-space: nowrap; }
        @media (min-width: 600px) { .payment-type-badge { font-size: 10px; } }
        .time-cell { font-family: monospace; color: #666; font-size: 10px; white-space: nowrap; }
        @media (min-width: 600px) { .time-cell { font-size: 11px; } }
        
        .no-data { text-align: center; padding: 20px 15px; color: #999; font-size: 13px; }
        @media (min-width: 600px) { .no-data { padding: 30px 20px; font-size: 14px; } }
        .no-data i { font-size: 28px; margin-bottom: 10px; color: #ddd; }
        @media (min-width: 600px) { .no-data i { font-size: 36px; } }
        
        .alert-success { background: #e8f5e9; color: #2e7d32; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; border-left: 4px solid #4CAF50; animation: fadeIn 0.5s ease-out; font-size: 14px; }
        @media (min-width: 600px) { .alert-success { padding: 15px 20px; margin-bottom: 20px; font-size: 16px; } }
        .alert-error { background: #ffebee; color: #c62828; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; border-left: 4px solid #f44336; animation: fadeIn 0.5s ease-out; font-size: 14px; }
        @media (min-width: 600px) { .alert-error { padding: 15px 20px; margin-bottom: 20px; font-size: 16px; } }
        
        .nav-buttons { display: flex; gap: 10px; margin-top: 15px; justify-content: center; flex-wrap: wrap; flex-direction: column; }
        @media (min-width: 600px) { .nav-buttons { margin-top: 20px; flex-direction: row; } }
        
        .nav-btn { width: 100%; padding: 12px 20px; border: none; border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; text-decoration: none; min-height: 44px; text-align: center; }
        @media (min-width: 600px) { .nav-btn { width: auto; padding: 12px 25px; justify-content: flex-start; } }
        .history-btn { background: #4CAF50; color: white; }
        .pos-btn { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .excel-btn { background: #27ae60; color: white; }
        .nav-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        
        .type-summary { display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-radius: 8px; font-size: 12px; gap: 5px; align-items: center; }
        @media (min-width: 600px) { .type-summary { flex-direction: row; gap: 0; align-items: stretch; } }
        .type-total { font-weight: 700; }
        .temp-total { color: #e65100; }
        .perm-total { color: #2e7d32; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .gregorian-time { color: #1976d2; font-weight: 600; background: #e3f2fd; padding: 2px 6px; border-radius: 12px; display: inline-block; }
        
        /* Export Modal Styles */
        .export-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); animation: fadeIn 0.3s ease; }
        
        .export-modal-content { background: white; margin: 15% auto; width: 95%; max-width: 550px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: slideDown 0.3s ease; max-height: 80vh; overflow-y: auto; }
        @media (min-width: 600px) { .export-modal-content { margin: 10% auto; width: 90%; max-height: 90vh; } }
        
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .export-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 2px solid #f0f0f0; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border-radius: 20px 20px 0 0; }
        @media (min-width: 600px) { .export-modal-header { padding: 20px 25px; } }
        
        .export-modal-header h3 { font-size: 18px; display: flex; align-items: center; gap: 10px; }
        @media (min-width: 600px) { .export-modal-header h3 { font-size: 20px; } }
        
        .export-modal-close { font-size: 24px; font-weight: bold; cursor: pointer; transition: all 0.3s; padding: 5px; }
        @media (min-width: 600px) { .export-modal-close { font-size: 28px; } }
        .export-modal-close:hover { transform: scale(1.2); }
        
        .export-modal-body { padding: 20px; }
        @media (min-width: 600px) { .export-modal-body { padding: 25px; } }
        
        .export-logo-preview { display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; padding: 15px; background: #f8f9fa; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
        @media (min-width: 600px) { .export-logo-preview { flex-direction: row; gap: 15px; margin-bottom: 25px; } }
        
        .export-logo-preview img { width: 40px; height: 40px; object-fit: contain; border-radius: 10px; }
        @media (min-width: 600px) { .export-logo-preview img { width: 50px; height: 50px; } }
        
        .export-logo-preview span { font-size: 14px; font-weight: 600; color: #2c3e50; text-align: center; }
        @media (min-width: 600px) { .export-logo-preview span { font-size: 16px; text-align: left; } }
        
        .export-date-section { margin-bottom: 20px; }
        .export-date-section label { display: block; margin-bottom: 10px; font-weight: 600; color: #2c3e50; font-size: 14px; }
        
        .export-select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 16px; background: white; cursor: pointer; transition: all 0.3s; min-height: 48px; }
        @media (min-width: 600px) { .export-select { padding: 12px 15px; font-size: 14px; min-height: auto; } }
        .export-select:focus { outline: none; border-color: #667eea; }
        
        .export-custom-date { display: grid; grid-template-columns: 1fr; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e0e0e0; }
        @media (min-width: 600px) { .export-custom-date { grid-template-columns: 1fr 1fr; gap: 15px; } }
        
        .export-date-group { display: flex; flex-direction: column; gap: 5px; }
        .export-date-group label { font-size: 12px; color: #7f8c8d; }
        
        .export-input { padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 16px; min-height: 48px; }
        @media (min-width: 600px) { .export-input { padding: 10px; font-size: 14px; min-height: auto; } }
        .export-input:focus { outline: none; border-color: #667eea; }
        
        .export-info { background: #e3f2fd; padding: 12px 15px; border-radius: 10px; margin-top: 20px; font-size: 12px; color: #1565c0; display: flex; align-items: center; gap: 10px; line-height: 1.4; }
        
        .export-modal-footer { display: flex; flex-direction: column; gap: 10px; padding: 15px 20px; border-top: 1px solid #f0f0f0; background: #f8f9fa; border-radius: 0 0 20px 20px; }
        @media (min-width: 600px) { .export-modal-footer { flex-direction: row; justify-content: flex-end; gap: 15px; padding: 20px 25px; } }
        
        .export-btn-cancel { padding: 12px; background: #e74c3c; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s; min-height: 44px; text-align: center; }
        @media (min-width: 600px) { .export-btn-cancel { padding: 10px 25px; } }
        .export-btn-cancel:hover { background: #c0392b; transform: translateY(-2px); }
        
        .export-btn-download { padding: 12px; background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; min-height: 44px; }
        @media (min-width: 600px) { .export-btn-download { padding: 10px 25px; } }
        .export-btn-download:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3); }
        
        .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid white; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-hand-holding-usd"></i> የዕለት ወጪ መጻፊያ</h1>
            <div class="branch-badge">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?>
            </div>
            <div class="nav-buttons">
                <a href="admin_dashboard.php" class="nav-btn history-btn"><i class="fas fa-history"></i> ለመመለስ</a>
                
                <button class="nav-btn excel-btn" onclick="openExportModal()"><i class="fas fa-file-excel"></i> ኤክሴል አውርድ</button>
            </div>
        </div>

        <div class="eth-date">
            <div class="date-display"><i class="fas fa-calendar-alt"></i> ዛሬ: <?php echo $current_eth_date; ?></div>
            <div class="time-display" id="currentTime">
                <i class="fas fa-clock"></i> ሰዓት: 
                <span id="timeDisplay"><?php echo $current_gregorian_time; ?></span>
            </div>
        </div>

        <?php if(!empty($error_message)): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if(!empty($success_message)): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="balance-summary">
            <div class="balance-card"><div class="balance-label">የዛሬ ጠቅላላ ሽያጭ</div><div class="balance-value sales-value"><?php echo number_format($total_sales, 2); ?> ETB</div></div>
            <div class="balance-card"><div class="balance-label">የዛሬ ጠቅላላ ወጪ</div><div class="balance-value withdrawals-value">- <?php echo number_format($total_today_withdrawals, 2); ?> ETB</div></div>
            <div class="balance-card"><div class="balance-label">ከዛሬ ወጪ ቀሪ</div><div class="balance-value current-value"><?php echo number_format($current_balance, 2); ?> ETB</div></div>
        </div>

        <div class="balance-summary" style="margin-top: -15px; margin-bottom: 25px;">
            <div class="balance-card" style="grid-column: span 3; background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white;">
                <div class="balance-label" style="color: white;">ጠቅላላ የተወጡ ገንዘቦች (ሁሉም ጊዜ)</div>
                <div class="balance-value" style="color: white; font-size: 40px;">- <?php echo number_format($total_all_withdrawals, 2); ?> ETB</div>
            </div>
        </div>

        <div class="main-content">
            <div class="withdrawal-form">
                <h3 class="form-title"><i class="fas fa-money-bill-wave"></i> አዲስ ማውጫ</h3>
                <form method="POST" action="" id="withdrawalForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> በማን ስም እንደሚወጣ ምረጥ</label>
                        <select name="user_id" required>
                            <option value="">Select User</option>
                            <?php foreach($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> የወጪ አይነት</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="withdrawal_type" value="ጊዜያዊ" required> ጊዜያዊ (Temporary)
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="withdrawal_type" value="ቋሚ" required> ቋሚ (Permanent)
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> የክፍያ አይነት</label>
                        <div class="payment-buttons" id="paymentButtons">
                            <button type="button" class="payment-btn" data-value="cash"><i class="fas fa-money-bill-wave"></i> Cash</button>
                            <button type="button" class="payment-btn" data-value="telebirr"><i class="fas fa-mobile-alt"></i> Telebirr</button>
                            <button type="button" class="payment-btn" data-value="cbe"><i class="fas fa-university"></i> CBE</button>
                            <button type="button" class="payment-btn" data-value="abyssinia"><i class="fas fa-university"></i> Abyssinia</button>
                            <button type="button" class="payment-btn" data-value="oromia"><i class="fas fa-university"></i> Oromia Bank</button>
                            <button type="button" class="payment-btn" data-value="telebirr_cash"><i class="fas fa-mobile-alt"></i> Telebirr + Cash</button>
                            <button type="button" class="payment-btn" data-value="cbe_cash"><i class="fas fa-university"></i> CBE + Cash</button>
                            <button type="button" class="payment-btn" data-value="abyssinia_cash"><i class="fas fa-university"></i> Abyssinia + Cash</button>
                            <button type="button" class="payment-btn" data-value="oromia_cash"><i class="fas fa-university"></i> Oromia + Cash</button>
                            <button type="button" class="payment-btn" data-value="telebirr_abyssinia"><i class="fas fa-mobile-alt"></i> Telebirr + Abyssinia</button>
                            <button type="button" class="payment-btn" data-value="telebirr_cbe"><i class="fas fa-mobile-alt"></i> Telebirr + CBE</button>
                            <button type="button" class="payment-btn" data-value="telebirr_oromia"><i class="fas fa-mobile-alt"></i> Telebirr + Oromia</button>
                        </div>
                        <input type="hidden" name="payment_type" id="selectedPayment" value="" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-money-bill"></i> መጠን (ETB)</label>
                        <input type="number" name="amount" step="0.01" min="1" required placeholder="ወጪ የሚደረገውን መጠን አስገቡ">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> ምክንያት</label>
                        <textarea name="reason" placeholder="Enter reason for withdrawal..." required></textarea>
                    </div>
                    <button type="submit" name="add_withdrawal" class="submit-btn"><i class="fas fa-paper-plane"></i> Register Withdrawal</button>
                </form>
            </div>

            <div class="transactions-wrapper">
                <!-- ጊዜያዊ Withdrawals (Left Side) -->
                <div class="transactions-list">
                    <h3 class="list-title temp">
                        <i class="fas fa-clock"></i> ጊዜያዊ ወጪዎች
                        <span style="margin-left: auto; font-size: 12px; background: #ff9800; color: white; padding: 3px 8px; border-radius: 12px;">
                            <?php echo count($temp_withdrawals); ?> entries
                        </span>
                    </h3>
                    
                    <?php 
                    $temp_total = 0;
                    foreach($temp_withdrawals as $w) {
                        $temp_total += $w['amount'];
                    }
                    ?>
                    
                    <div class="type-summary">
                        <span>ጠቅላላ ጊዜያዊ ወጪ:</span>
                        <span class="type-total temp-total">- <?php echo number_format($temp_total, 2); ?> ETB</span>
                    </div>
                    
                    <?php if(!empty($temp_withdrawals)): ?>
                        <table class="transactions-table">
                            <thead>
                                <tr>
                                    <th>የኢትዮጵያ ቀን</th>
                                    <th>ሰዓት</th>
                                    <th>ሰው</th>
                                    <th>ክፍያ</th>
                                    <th>ምክንያት</th>
                                    <th>መጠን</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($temp_withdrawals as $withdrawal): 
                                    $eth_date_data = get_ethiopian_date(strtotime($withdrawal['created_at']));
                                    $eth_date = $eth_date_data['date'];
                                    
                                    // ===== FIXED: Use created_at directly - NO ADDITION/SUBTRACTION =====
                                    // This will show the exact time from database
                                    $display_time = date('h:i:s A', strtotime($withdrawal['created_at']));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($eth_date); ?></td>
                                    <td class="time-cell"><span class="gregorian-time"><?php echo $display_time; ?></span></td>
                                    <td><?php echo htmlspecialchars($withdrawal['username']); ?></td>
                                    <td><span class="payment-type-badge"><?php echo ucwords(str_replace('_', ' ', $withdrawal['payment_type'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($withdrawal['reason']); ?></td>
                                    <td class="withdrawal-amount">- <?php echo number_format($withdrawal['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-clock"></i>
                            <p>ምንም ጊዜያዊ ወጪ የለም</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- ቋሚ Withdrawals (Right Side) -->
                <div class="transactions-list">
                    <h3 class="list-title perm">
                        <i class="fas fa-permanent"></i> ቋሚ ወጪዎች
                        <span style="margin-left: auto; font-size: 12px; background: #4CAF50; color: white; padding: 3px 8px; border-radius: 12px;">
                            <?php echo count($perm_withdrawals); ?> entries
                        </span>
                    </h3>
                    
                    <?php 
                    $perm_total = 0;
                    foreach($perm_withdrawals as $w) {
                        $perm_total += $w['amount'];
                    }
                    ?>
                    
                    <div class="type-summary">
                        <span>ጠቅላላ ቋሚ ወጪ:</span>
                        <span class="type-total perm-total">- <?php echo number_format($perm_total, 2); ?> ETB</span>
                    </div>
                    
                    <?php if(!empty($perm_withdrawals)): ?>
                        <table class="transactions-table">
                            <thead>
                                <tr>
                                    <th>የኢትዮጵያ ቀን</th>
                                    <th>ሰዓት</th>
                                    <th>ሰው</th>
                                    <th>ክፍያ</th>
                                    <th>ምክንያት</th>
                                    <th>መጠን</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($perm_withdrawals as $withdrawal): 
                                    $eth_date_data = get_ethiopian_date(strtotime($withdrawal['created_at']));
                                    $eth_date = $eth_date_data['date'];
                                    
                                    // ===== FIXED: Use created_at directly =====
                                    $display_time = date('h:i:s A', strtotime($withdrawal['created_at']));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($eth_date); ?></td>
                                    <td class="time-cell"><span class="gregorian-time"><?php echo $display_time; ?></span></td>
                                    <td><?php echo htmlspecialchars($withdrawal['username']); ?></td>
                                    <td><span class="payment-type-badge"><?php echo ucwords(str_replace('_', ' ', $withdrawal['payment_type'])); ?></span></td>
                                    <td><?php echo htmlspecialchars($withdrawal['reason']); ?></td>
                                    <td class="withdrawal-amount">- <?php echo number_format($withdrawal['amount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-permanent"></i>
                            <p>ምንም ቋሚ ወጪ የለም</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div id="exportModal" class="export-modal">
        <div class="export-modal-content">
            <div class="export-modal-header">
                <h3><i class="fas fa-file-excel"></i> ኤክሴል ሪፖርት አውርድ</h3>
                <span class="export-modal-close" onclick="closeExportModal()">&times;</span>
            </div>
            
            <div class="export-modal-body">
                <div class="export-logo-preview">
                    <img src="image/photo_2026-01-12_07-44-10.jpg" alt="Logo" id="previewLogo" 
                         onerror="this.src='image/icon.png'; this.onerror=null;">
                    <span>አሌልቱ የእንስሳት ተዋጽኦ</span>
                </div>
                
                <div class="export-date-section">
                    <label><i class="fas fa-calendar-alt"></i> የቀን ክልል ምረጡ</label>
                    <select id="exportDateRange" class="export-select" onchange="toggleExportCustomDate(this.value)">
                        <option value="today">ዛሬ (Today)</option>
                        <option value="yesterday">ትናንት (Yesterday)</option>
                        <option value="last3days">ያለፉ 3 ቀናት</option>
                        <option value="last7days">ያለፉ 7 ቀናት</option>
                        <option value="last2weeks">ያለፉ 2 ሳምንታት</option>
                        <option value="last3weeks">ያለፉ 3 ሳምንታት</option>
                        <option value="last1month">ያለፈ 1 ወር</option>
                        <option value="last3months">ያለፉ 3 ወራት</option>
                        <option value="last6months">ያለፉ 6 ወራት</option>
                        <option value="last9months">ያለፉ 9 ወራት</option>
                        <option value="last1year">ያለፈ 1 አመት</option>
                        <option value="custom">Custom Date</option>
                    </select>
                </div>
                
                <div id="exportCustomDateRange" class="export-custom-date" style="display: none;">
                    <div class="export-date-group">
                        <label>ከ (From):</label>
                        <input type="date" id="exportStartDate" class="export-input">
                    </div>
                    <div class="export-date-group">
                        <label>እስከ (To):</label>
                        <input type="date" id="exportEndDate" class="export-input">
                    </div>
                </div>
                
                <div class="export-info">
                    <i class="fas fa-info-circle"></i> 
                    ሪፖርቱ የወጪ ታሪክ፣ የክፍያ ዝርዝር እና ማጠቃለያ ይዟል
                </div>
            </div>
            
            <div class="export-modal-footer">
                <button class="export-btn-cancel" onclick="closeExportModal()">ዝጋ</button>
                <button class="export-btn-download" id="exportDownloadBtn" onclick="exportToExcelWithDate()">
                    <i class="fas fa-download"></i> ኤክሴል አውርድ
                </button>
            </div>
        </div>
    </div>

    <script>
        // ===== Live time update with correct time =====
        function updateTime() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12;
            
            const timeString = `${hours.toString().padStart(2, '0')}:${minutes}:${seconds} ${ampm}`;
            document.getElementById('timeDisplay').textContent = timeString;
        }
        
        updateTime();
        setInterval(updateTime, 1000);
        
        // Payment button selection
        const paymentButtons = document.querySelectorAll('.payment-btn');
        const selectedPaymentInput = document.getElementById('selectedPayment');
        
        paymentButtons.forEach(button => {
            button.addEventListener('click', function() {
                paymentButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                selectedPaymentInput.value = this.dataset.value;
            });
        });
        
        // Form validation
        document.getElementById('withdrawalForm').addEventListener('submit', function(e) {
            const amountInput = this.querySelector('input[name="amount"]');
            const userSelect = this.querySelector('select[name="user_id"]');
            const withdrawalType = this.querySelector('input[name="withdrawal_type"]:checked');
            const paymentType = document.getElementById('selectedPayment').value;
            
            if (parseFloat(amountInput.value) <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than 0');
                amountInput.focus();
                return false;
            }
            
            if (userSelect.value === '') {
                e.preventDefault();
                alert('Please select a user');
                userSelect.focus();
                return false;
            }
            
            if (!withdrawalType) {
                e.preventDefault();
                alert('Please select withdrawal type (ጊዜያዊ or ቋሚ)');
                return false;
            }
            
            if (!paymentType) {
                e.preventDefault();
                alert('Please select payment type');
                return false;
            }
            
            return true;
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.querySelector('.alert-success');
            if (successMessage) {
                successMessage.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        
        // ========== EXPORT FUNCTIONS ==========
        
        function openExportModal() {
            document.getElementById('exportModal').style.display = 'block';
            // Set default to today
            document.getElementById('exportDateRange').value = 'today';
            document.getElementById('exportCustomDateRange').style.display = 'none';
        }
        
        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }
        
        function toggleExportCustomDate(value) {
            const customDiv = document.getElementById('exportCustomDateRange');
            if (value === 'custom') {
                customDiv.style.display = 'grid';
                // Set default dates
                const today = new Date().toISOString().split('T')[0];
                const lastMonth = new Date();
                lastMonth.setMonth(lastMonth.getMonth() - 1);
                document.getElementById('exportStartDate').value = lastMonth.toISOString().split('T')[0];
                document.getElementById('exportEndDate').value = today;
            } else {
                customDiv.style.display = 'none';
            }
        }
        
        function exportToExcelWithDate() {
            const dateRange = document.getElementById('exportDateRange').value;
            let startDate = '';
            let endDate = '';
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];
            
            // Get dates based on selection
            if (dateRange === 'today') {
                startDate = todayStr;
                endDate = todayStr;
            } else if (dateRange === 'yesterday') {
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                startDate = yesterday.toISOString().split('T')[0];
                endDate = startDate;
            } else if (dateRange === 'last3days') {
                const start = new Date(today);
                start.setDate(start.getDate() - 3);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last7days') {
                const start = new Date(today);
                start.setDate(start.getDate() - 7);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last2weeks') {
                const start = new Date(today);
                start.setDate(start.getDate() - 14);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last3weeks') {
                const start = new Date(today);
                start.setDate(start.getDate() - 21);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last1month') {
                const start = new Date(today);
                start.setMonth(start.getMonth() - 1);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last3months') {
                const start = new Date(today);
                start.setMonth(start.getMonth() - 3);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last6months') {
                const start = new Date(today);
                start.setMonth(start.getMonth() - 6);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last9months') {
                const start = new Date(today);
                start.setMonth(start.getMonth() - 9);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'last1year') {
                const start = new Date(today);
                start.setFullYear(start.getFullYear() - 1);
                startDate = start.toISOString().split('T')[0];
                endDate = todayStr;
            } else if (dateRange === 'custom') {
                startDate = document.getElementById('exportStartDate').value;
                endDate = document.getElementById('exportEndDate').value;
                if (!startDate || !endDate) {
                    alert('እባክዎ ትክክለኛ ቀን ይምረጡ');
                    return;
                }
            }
            
            // Show loading on button
            const btn = document.getElementById('exportDownloadBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="loading-spinner"></span> በማዘጋጀት ላይ...';
            btn.disabled = true;
            
            // Send AJAX request to generate Excel
            const formData = new FormData();
            formData.append('action', 'export_excel');
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            formData.append('branch_id', '<?php echo $current_branch_id; ?>');
            formData.append('branch_name', '<?php echo addslashes($current_branch_name); ?>');
            
            fetch('export_withdrawals_excel.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.blob())
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `withdrawals_report_${startDate}_to_${endDate}.xlsx`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                // Reset button
                btn.innerHTML = originalText;
                btn.disabled = false;
                closeExportModal();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('ሪፖርት ማውረድ አልተቻለም');
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('exportModal');
            if (event.target == modal) {
                closeExportModal();
            }
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
