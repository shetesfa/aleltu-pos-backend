<?php
session_start();
require_once 'config.php';

// Set Ethiopian timezone
date_default_timezone_set('Africa/Addis_Ababa');

if (!$conn) die("Connection failed");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? '';

// Get branch info
$user_branch = getUserBranch($conn, $user_id);
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// For super admin, allow branch switching via URL
if ($user_role == 'super_admin' && isset($_GET['branch_id']) && !empty($_GET['branch_id'])) {
    $current_branch_id = intval($_GET['branch_id']);
    $current_branch_name = getCurrentBranchName($conn, $current_branch_id);
    setBranchSession($current_branch_id, $current_branch_name);
}

// ========== ACCURATE ETHIOPIAN DATE FUNCTIONS ==========
function getEthiopianDate($gregorian_date = null) {
    if ($gregorian_date === null) {
        $gregorian_date = date('Y-m-d');
    }
    
    list($year, $month, $day) = explode('-', $gregorian_date);
    
    $ethiopian_months = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ህዳር', 4 => 'ታህሳስ',
        5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
        9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'
    ];
    
    // Ethiopian year calculation
    $ethiopian_year = $year - 7;
    if ($month < 9 || ($month == 9 && $day < 11)) {
        $ethiopian_year--;
    }
    
    // Calculate days since Ethiopian New Year (September 11)
    $new_year = mktime(0, 0, 0, 9, 11, $year);
    $current = mktime(0, 0, 0, $month, $day, $year);
    
    if ($current < $new_year) {
        $new_year = mktime(0, 0, 0, 9, 11, $year - 1);
    }
    
    $days_since_new_year = floor(($current - $new_year) / (60 * 60 * 24)) + 1;
    
    // Ethiopian month (each month has 30 days)
    $ethiopian_month = ceil($days_since_new_year / 30);
    if ($ethiopian_month > 13) $ethiopian_month = 13;
    
    $ethiopian_day = $days_since_new_year - (($ethiopian_month - 1) * 30);
    
    return [
        'year' => $ethiopian_year,
        'month' => $ethiopian_month,
        'month_name' => $ethiopian_months[$ethiopian_month],
        'day' => (int)$ethiopian_day,
        'full_date' => $ethiopian_year . ' ' . $ethiopian_months[$ethiopian_month] . ' ' . (int)$ethiopian_day,
        'formatted' => sprintf("%04d-%02d-%02d", $ethiopian_year, $ethiopian_month, $ethiopian_day)
    ];
}

function get_ethiopian_time() {
    $timestamp = time();
    $eth_timestamp = $timestamp + (3 * 3600);
    return date('h:i A', $eth_timestamp);
}

$today_ethiopian = getEthiopianDate();
$current_time = get_ethiopian_time();

// ========== CREATE BOSS TRANSACTIONS TABLE IF NOT EXISTS ==========
$create_table = "CREATE TABLE IF NOT EXISTS boss_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    ethiopian_date VARCHAR(50) NOT NULL,
    gregorian_date DATE NOT NULL,
    cash_amount DECIMAL(10,2) DEFAULT 0,
    cbe_amount DECIMAL(10,2) DEFAULT 0,
    abyssinia_amount DECIMAL(10,2) DEFAULT 0,
    telebirr_amount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2) DEFAULT 0,
    notes TEXT,
    received_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_branch_date (branch_id, ethiopian_date)
)";
mysqli_query($conn, $create_table);

// ========== HANDLE FORM SUBMISSION ==========
$success_message = '';
$error_message = '';
$warning_message = '';

// Check for session messages first (from redirect)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['warning_message'])) {
    $warning_message = $_SESSION['warning_message'];
    unset($_SESSION['warning_message']);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_boss'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $cash = floatval($_POST['cash'] ?? 0);
    $cbe = floatval($_POST['cbe'] ?? 0);
    $abyssinia      = floatval($_POST['abyssinia'] ?? 0);
    $telebirr       = floatval($_POST['telebirr'] ?? 0);
    $notes          = trim($_POST['notes'] ?? '');
    $ethiopian_date = trim($_POST['ethiopian_date'] ?? $today_ethiopian['formatted']);
    $gregorian_date = date('Y-m-d');

    $total = $cash + $cbe + $abyssinia + $telebirr;

    if ($total <= 0) {
        $_SESSION['error_message'] = "እባክዎ ቢያንስ አንድ የክፍያ ዘዴ ያስገቡ";
    } else {
        // Check if entry exists for this date — prepared statement
        $chk = mysqli_prepare($conn, "SELECT id FROM boss_daily WHERE branch_id = ? AND ethiopian_date = ?");
        mysqli_stmt_bind_param($chk, 'is', $current_branch_id, $ethiopian_date);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $exists = mysqli_stmt_num_rows($chk) > 0;
        if ($exists) { mysqli_stmt_bind_result($chk, $existing_id); mysqli_stmt_fetch($chk); }
        mysqli_stmt_close($chk);

        if ($exists) {
            // Update existing entry — prepared statement
            $upd = mysqli_prepare($conn,
                "UPDATE boss_daily SET
                    cash_amount      = cash_amount + ?,
                    cbe_amount       = cbe_amount + ?,
                    abyssinia_amount = abyssinia_amount + ?,
                    telebirr_amount  = telebirr_amount + ?,
                    total_amount     = total_amount + ?,
                    notes            = CONCAT(notes, '\n', ?)
                 WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'dddddsi', $cash, $cbe, $abyssinia, $telebirr, $total, $notes, $existing_id);
            if (mysqli_stmt_execute($upd)) {
                $_SESSION['success_message'] = "✅ ገንዘብ በተሳካ ሁኔታ ተመዝግቧል!";
            } else {
                $_SESSION['error_message'] = "❌ ስህተት ተከስቷል.";
            }
            mysqli_stmt_close($upd);
        } else {
            // Insert new entry — prepared statement
            $ins = mysqli_prepare($conn,
                "INSERT INTO boss_daily
                    (branch_id, ethiopian_date, gregorian_date, cash_amount, cbe_amount, abyssinia_amount, telebirr_amount, total_amount, notes, received_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins, 'issdddddss',
                $current_branch_id, $ethiopian_date, $gregorian_date,
                $cash, $cbe, $abyssinia, $telebirr, $total, $notes, $user_name);
            if (mysqli_stmt_execute($ins)) {
                $_SESSION['success_message'] = "✅ ገንዘብ በተሳካ ሁኔታ ተመዝግቧል!";
            } else {
                $_SESSION['error_message'] = "❌ ስህተት ተከስቷL.";
            }
            mysqli_stmt_close($ins);
    }
    }
    
    // Redirect to prevent form resubmission
    header("Location: boss_receive.php" . ($user_role == 'super_admin' && isset($_GET['branch_id']) ? '?branch_id=' . $_GET['branch_id'] : ''));
    exit();
}

// ========== GET TODAY'S STATISTICS ==========
// Today's sales
$today_sales_query = "SELECT COALESCE(SUM(total_amount), 0) as total_sales 
                      FROM transactions 
                      WHERE DATE(transaction_date) = CURDATE() AND branch_id = $current_branch_id";
$today_sales_result = mysqli_query($conn, $today_sales_query);
$today_sales = mysqli_fetch_assoc($today_sales_result)['total_sales'];

// Today's withdrawals
$today_withdrawals_query = "SELECT COALESCE(SUM(amount), 0) as total_withdrawals 
                            FROM daily_withdrawals 
                            WHERE DATE(created_at) = CURDATE() AND branch_id = $current_branch_id";
$today_withdrawals_result = mysqli_query($conn, $today_withdrawals_query);
$today_withdrawals = mysqli_fetch_assoc($today_withdrawals_result)['total_withdrawals'];

// Today's boss payments
$today_boss_query = "SELECT 
    COALESCE(SUM(cash_amount), 0) as total_cash,
    COALESCE(SUM(cbe_amount), 0) as total_cbe,
    COALESCE(SUM(abyssinia_amount), 0) as total_abyssinia,
    COALESCE(SUM(telebirr_amount), 0) as total_telebirr,
    COALESCE(SUM(total_amount), 0) as total_boss
    FROM boss_daily 
    WHERE gregorian_date = CURDATE() AND branch_id = $current_branch_id";
$today_boss_result = mysqli_query($conn, $today_boss_query);
$today_boss = mysqli_fetch_assoc($today_boss_result);

// Calculate totals
$total_boss_today = $today_boss['total_boss'] ?? 0;
$total_expenses = $today_withdrawals + $total_boss_today;
$remaining = $today_sales - $total_expenses;

// Check warning conditions
if ($total_boss_today > 0) {
    if ($total_boss_today > $today_sales) {
        $warning_message = "⚠️ ማስጠንቀቂያ: ለባለቤት የተሰጠው ገንዘብ ( " . number_format($total_boss_today, 2) . " ETB) ከዛሬ ሽያጭ (" . number_format($today_sales, 2) . " ETB) በላይ ነው!";
        $warning_type = 'excess';
    } elseif ($total_boss_today > ($today_sales * 0.8)) {
        $warning_message = "⚠️ ማስጠንቀቂያ: ለባለቤት የተሰጠው ገንዘብ ከሽያጭ 80% በላይ ነው!";
        $warning_type = 'high';
    }
}

// ========== GET DAILY SALES DATA FOR RESULT CALCULATION ==========
$daily_sales_query = "SELECT DATE(transaction_date) as sale_date, SUM(total_amount) as daily_sales 
                      FROM transactions 
                      WHERE branch_id = $current_branch_id 
                      GROUP BY DATE(transaction_date)";
$daily_sales_result = mysqli_query($conn, $daily_sales_query);
$daily_sales_data = [];
while ($sale = mysqli_fetch_assoc($daily_sales_result)) {
    $daily_sales_data[$sale['sale_date']] = $sale['daily_sales'];
}

// ========== GET RECENT BOSS TRANSACTIONS (GROUPED BY DATE) ==========
$history_query = "SELECT * FROM boss_daily 
                  WHERE branch_id = $current_branch_id 
                  ORDER BY gregorian_date DESC 
                  LIMIT 30";
$history_result = mysqli_query($conn, $history_query);
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>ለባለቤት የሚሰጥ ገንዘብ - <?php echo htmlspecialchars($current_branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            font-size: 20px;
            margin-bottom: 5px;
        }

        @media (min-width: 600px) {
            .branch-details h2 {
                font-size: 22px;
            }
        }

        .branch-details p {
            font-size: 14px;
            opacity: 0.9;
        }

        .branch-selector {
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .branch-selector select {
            background: white;
            border: none;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            outline: none;
            min-height: 44px;
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
            min-height: 44px;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(247, 37, 133, 0.3);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin: 25px 0;
        }

        @media (min-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: 12px;
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

        .stat-icon.sales { background: #27ae60; }
        .stat-icon.expenses { background: #e74c3c; }
        .stat-icon.balance { background: #3498db; }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }

        @media (min-width: 600px) {
            .stat-value {
                font-size: 28px;
            }
        }

        .stat-sub {
            font-size: 13px;
            color: #95a5a6;
            margin-top: 5px;
        }

        /* Warning Alert */
        .warning-alert {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease;
        }

        .warning-alert.excess {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        .warning-alert i {
            font-size: 24px;
        }

        .warning-content {
            flex: 1;
        }

        .warning-title {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .warning-detail {
            font-size: 14px;
        }

        /* Balance Comparison */
        .balance-comparison {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            text-align: center;
            gap: 20px;
        }

        @media (min-width: 600px) {
            .balance-comparison {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                text-align: left;
            }
        }

        .comparison-item {
            flex: 1;
            min-width: 200px;
        }

        .comparison-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .comparison-value {
            font-size: 24px;
            font-weight: 700;
        }

        .comparison-value.positive {
            color: #27ae60;
        }

        .comparison-value.negative {
            color: #e74c3c;
        }

        .comparison-diff {
            font-size: 16px;
            margin-left: 10px;
        }

        /* Payment Stats Grid */
        .payment-stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin: 25px 0;
        }

        @media (min-width: 600px) {
            .payment-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1200px) {
            .payment-stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .payment-stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            text-align: center;
        }

        .payment-stat-card.cash { border-top: 4px solid #27ae60; }
        .payment-stat-card.cbe { border-top: 4px solid #3498db; }
        .payment-stat-card.abyssinia { border-top: 4px solid #9b59b6; }
        .payment-stat-card.telebirr { border-top: 4px solid #f39c12; }

        .payment-stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .payment-stat-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }

        .payment-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
        }

        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        @media (min-width: 1200px) {
            .main-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Form Panel */
        .form-panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
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

        /* Payment Input Grid */
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        @media (min-width: 600px) {
            .payment-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .payment-input-group {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border: 2px solid #e0e0e0;
        }

        .payment-input-group.cash label { color: #27ae60; }
        .payment-input-group.cbe label { color: #3498db; }
        .payment-input-group.abyssinia label { color: #9b59b6; }
        .payment-input-group.telebirr label { color: #f39c12; }

        .payment-input-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .payment-input-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
            min-height: 44px;
        }

        .payment-input-group input:focus {
            outline: none;
            border-color: inherit;
        }

        .total-display {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 18px;
            font-weight: 600;
        }

        .total-amount {
            font-size: 24px;
            color: #f39c12;
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
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            min-height: 44px;
        }

        .form-control:focus {
            outline: none;
            border-color: #3498db;
        }

        .submit-btn {
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
            min-height: 44px;
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(243, 156, 18, 0.3);
        }

        /* History Panel */
        .history-panel {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }

        @media (min-width: 900px) {
            .history-table {
                display: table;
                white-space: normal;
            }
        }

        .history-table th {
            text-align: left;
            padding: 12px;
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid #dee2e6;
        }

        .history-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #ecf0f1;
            color: #2c3e50;
        }

        .history-table tr:hover {
            background: #f8f9ff;
        }

        .payment-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 5px 0;
        }

        .payment-item {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .payment-cash { background: #e8f5e9; color: #2e7d32; }
        .payment-cbe { background: #e3f2fd; color: #1565c0; }
        .payment-abyssinia { background: #f3e5f5; color: #7b1fa2; }
        .payment-telebirr { background: #fff3e0; color: #e65100; }

        .total-cell {
            font-weight: 700;
            color: #e74c3c;
            font-size: 16px;
        }

        /* Result Cell Styles */
        .result-cell {
            font-weight: 700;
            font-size: 16px;
            text-align: center;
        }
        
        .result-positive {
            background: #d4edda;
            color: #155724;
            border-radius: 8px;
            padding: 8px 12px;
            display: inline-block;
            font-weight: 700;
        }
        
        .result-negative {
            background: #f8d7da;
            color: #721c24;
            border-radius: 8px;
            padding: 8px 12px;
            display: inline-block;
            font-weight: 700;
        }
        
        .result-zero {
            background: #e2e3e5;
            color: #383d41;
            border-radius: 8px;
            padding: 8px 12px;
            display: inline-block;
            font-weight: 700;
        }
        
        .result-info {
            font-size: 11px;
            margin-top: 3px;
            opacity: 0.8;
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

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }

        .nav-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .nav-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            min-height: 44px;
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .nav-btn.secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Branch Header -->
        <div class="branch-header">
            <div class="branch-info">
                <div class="branch-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div class="branch-details">
                    <h2><?php echo htmlspecialchars($current_branch_name); ?></h2>
                    <p><i class="fas fa-map-marker-alt"></i> ቅርንጫፍ ኮድ: <?php echo $current_branch_id; ?></p>
                </div>
            </div>
            
            <?php if ($user_role == 'super_admin'): ?>
            <div class="branch-selector">
                <i class="fas fa-store"></i>
                <select onchange="changeBranch(this.value)">
                    <option value="">ምረጥ</option>
                    <?php 
                    $branches = getAllBranches($conn);
                    foreach($branches as $b): 
                    ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo ($current_branch_id == $b['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($b['place_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="ethiopian-time">
                <i class="fas fa-clock"></i>
                <?php echo $current_time; ?>
            </div>
            
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($user_name); ?></span>
                <span class="role-badge">
                    <?php 
                    if ($user_role == 'super_admin') echo 'ሱፐር አድሚን';
                    elseif ($user_role == 'admin') echo 'አድሚን';
                    else echo 'ሻጭ';
                    ?>
                </span>
            </div>
        </div>

        <!-- Main Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">የዛሬ ሽያጭ</span>
                    <div class="stat-icon sales"><i class="fas fa-shopping-cart"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($today_sales, 2); ?> ETB</div>
                <div class="stat-sub">ጠቅላላ የተሸጠ ገንዘብ</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">የዛሬ ወጪ + ለባለቤት</span>
                    <div class="stat-icon expenses"><i class="fas fa-money-bill-wave"></i></div>
                </div>
                <div class="stat-value"><?php echo number_format($total_expenses, 2); ?> ETB</div>
                <div class="stat-sub">
                    ወጪ: <?php echo number_format($today_withdrawals, 2); ?> | 
                    ለባለቤት: <?php echo number_format($total_boss_today, 2); ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">የቀረ ገንዘብ</span>
                    <div class="stat-icon balance"><i class="fas fa-coins"></i></div>
                </div>
                <div class="stat-value <?php echo $remaining >= 0 ? '' : 'negative'; ?>">
                    <?php echo number_format($remaining, 2); ?> ETB
                </div>
                <div class="stat-sub">
                    ከሽያጭ የቀረ
                </div>
            </div>
        </div>

        <!-- Balance Comparison -->
        <div class="balance-comparison">
            <div class="comparison-item">
                <div class="comparison-label">ለባለቤት የተሰጠ</div>
                <div class="comparison-value"><?php echo number_format($total_boss_today, 2); ?> ETB</div>
            </div>
            <div class="comparison-item">
                <div class="comparison-label">ከሽያጭ ጋር ሲነጻጸር</div>
                <?php if ($total_boss_today <= $today_sales): ?>
                    <div class="comparison-value negative">
                        ቀሪ: <?php echo number_format($today_sales - $total_boss_today, 2); ?> ETB
                        <span class="comparison-diff">(ቀይ)</span>
                    </div>
                <?php else: ?>
                    <div class="comparison-value positive">
                        ትርፍ: <?php echo number_format($total_boss_today - $today_sales, 2); ?> ETB
                        <span class="comparison-diff">(አረንጓዴ)</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="comparison-item">
                <div class="comparison-label">ከሽያጭ መቶኛ</div>
                <?php 
                $percentage = $today_sales > 0 ? ($total_boss_today / $today_sales) * 100 : 0;
                $percent_class = $percentage > 100 ? 'positive' : '';
                ?>
                <div class="comparison-value <?php echo $percent_class; ?>">
                    <?php echo number_format($percentage, 1); ?>%
                </div>
            </div>
        </div>

        <!-- Warning Alert -->
        <?php if ($warning_message): ?>
            <div class="warning-alert <?php echo $warning_type ?? ''; ?>">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="warning-content">
                    <div class="warning-title">ማስጠንቀቂያ</div>
                    <div class="warning-detail"><?php echo $warning_message; ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Payment Method Stats -->
        <div class="payment-stats-grid">
            <div class="payment-stat-card cash">
                <div class="payment-stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="payment-stat-label">ካሽ</div>
                <div class="payment-stat-value"><?php echo number_format($today_boss['total_cash'] ?? 0, 2); ?></div>
            </div>
            <div class="payment-stat-card cbe">
                <div class="payment-stat-icon"><i class="fas fa-university"></i></div>
                <div class="payment-stat-label">CBE</div>
                <div class="payment-stat-value"><?php echo number_format($today_boss['total_cbe'] ?? 0, 2); ?></div>
            </div>
            <div class="payment-stat-card abyssinia">
                <div class="payment-stat-icon"><i class="fas fa-landmark"></i></div>
                <div class="payment-stat-label">አቢሲንያ</div>
                <div class="payment-stat-value"><?php echo number_format($today_boss['total_abyssinia'] ?? 0, 2); ?></div>
            </div>
            <div class="payment-stat-card telebirr">
                <div class="payment-stat-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="payment-stat-label">ቴሌብር</div>
                <div class="payment-stat-value"><?php echo number_format($today_boss['total_telebirr'] ?? 0, 2); ?></div>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="main-grid">
            <!-- Left Panel: Form -->
            <div class="form-panel">
                <div class="panel-title">
                    <i class="fas fa-hand-holding-usd"></i>
                    ለባለቤት ገንዘብ መስጠት
                </div>

                <!-- Ethiopian Date Display -->
                <div class="ethiopian-date-display">
                    <i class="fas fa-calendar-alt"></i>
                    <div class="date-info">
                        <div class="date-label">የኢትዮጵያ ቀን</div>
                        <div class="date-value" id="displayEthiopianDate"><?php echo $today_ethiopian['full_date']; ?></div>
                    </div>
                </div>

                <form method="POST" id="bossForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <input type="hidden" name="ethiopian_date" id="ethiopianDate" value="<?php echo $today_ethiopian['formatted']; ?>">
                    
                    <!-- Payment Input Grid -->
                    <div class="payment-grid">
                        <div class="payment-input-group cash">
                            <label><i class="fas fa-money-bill-wave"></i> ካሽ</label>
                            <input type="number" name="cash" id="cash" class="payment-input" step="0.01" min="0" value="0" placeholder="0.00" oninput="calculateTotal()">
                        </div>
                        
                        <div class="payment-input-group cbe">
                            <label><i class="fas fa-university"></i> CBE</label>
                            <input type="number" name="cbe" id="cbe" class="payment-input" step="0.01" min="0" value="0" placeholder="0.00" oninput="calculateTotal()">
                        </div>
                        
                        <div class="payment-input-group abyssinia">
                            <label><i class="fas fa-landmark"></i> አቢሲንያ</label>
                            <input type="number" name="abyssinia" id="abyssinia" class="payment-input" step="0.01" min="0" value="0" placeholder="0.00" oninput="calculateTotal()">
                        </div>
                        
                        <div class="payment-input-group telebirr">
                            <label><i class="fas fa-mobile-alt"></i> ቴሌብር</label>
                            <input type="number" name="telebirr" id="telebirr" class="payment-input" step="0.01" min="0" value="0" placeholder="0.00" oninput="calculateTotal()">
                        </div>
                    </div>

                    <!-- Total Display -->
                    <div class="total-display">
                        <span><i class="fas fa-calculator"></i> ጠቅላላ ድምር</span>
                        <span class="total-amount" id="totalAmount">0.00 ETB</span>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> ማስታወሻ (አማራጭ)</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="ማስታወሻ ካለ ያስገቡ..."></textarea>
                    </div>

                    <button type="submit" name="submit_boss" class="submit-btn">
                        <i class="fas fa-check-circle"></i> ገንዘቡን መዝግብ
                    </button>
                </form>

                <!-- Navigation Buttons -->
                <div class="nav-buttons">
                    <a href="seller_pos.php" class="nav-btn"><i class="fas fa-store"></i> ወደ መሸጫ ተመለስ</a>
                    <a href="daily_cashier.php" class="nav-btn secondary"><i class="fas fa-history"></i> የወጪ ታሪክ</a>
                </div>
            </div>

            <!-- Right Panel: History with RESULT Column -->
            <div class="history-panel">
                <div class="panel-title">
                    <i class="fas fa-history"></i>
                    ለባለቤት የተሰጠ ገንዘብ ታሪክ
                </div>

                <?php if (mysqli_num_rows($history_result) > 0): ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>የኢትዮጵያ ቀን</th>
                                <th>የክፍያ ዝርዝር</th>
                                <th>ጠቅላላ</th>
                                <th>ውጤት</th>
                                <th>የተመዘገበበት</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = mysqli_fetch_assoc($history_result)): 
                                // Format Ethiopian date for display
                                $eth_parts = explode('-', $row['ethiopian_date']);
                                $eth_display = $eth_parts[0] . ' ' . getEthiopianDate($row['gregorian_date'])['month_name'] . ' ' . $eth_parts[2];
                                
                                // Get sales for this date
                                $sale_date = $row['gregorian_date'];
                                $daily_sales = isset($daily_sales_data[$sale_date]) ? $daily_sales_data[$sale_date] : 0;
                                $boss_amount = $row['total_amount'];
                                
                                // Calculate result
                                $result = $daily_sales - $boss_amount;
                                
                                // Determine result class and text
                                if ($result > 0) {
                                    $result_class = 'result-negative';
                                    $result_text = 'ቀሪ: ' . number_format($result, 2);
                                    $result_info = 'ከሽያጭ የቀረ';
                                } elseif ($result < 0) {
                                    $result_class = 'result-positive';
                                    $result_text = 'ትርፍ: ' . number_format(abs($result), 2);
                                    $result_info = 'ከሽያጭ በላይ';
                                } else {
                                    $result_class = 'result-zero';
                                    $result_text = 'ተመጣጣኝ';
                                    $result_info = 'ከሽያጭ ጋር እኩል';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo $eth_display; ?></strong>
                                    <div style="font-size: 11px; color: #7f8c8d;">
                                        <?php echo date('M d, Y', strtotime($row['gregorian_date'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="payment-breakdown">
                                        <?php if ($row['cash_amount'] > 0): ?>
                                            <span class="payment-item payment-cash">
                                                <i class="fas fa-money-bill-wave"></i> <?php echo number_format($row['cash_amount'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['cbe_amount'] > 0): ?>
                                            <span class="payment-item payment-cbe">
                                                <i class="fas fa-university"></i> <?php echo number_format($row['cbe_amount'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['abyssinia_amount'] > 0): ?>
                                            <span class="payment-item payment-abyssinia">
                                                <i class="fas fa-landmark"></i> <?php echo number_format($row['abyssinia_amount'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['telebirr_amount'] > 0): ?>
                                            <span class="payment-item payment-telebirr">
                                                <i class="fas fa-mobile-alt"></i> <?php echo number_format($row['telebirr_amount'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($row['notes'])): ?>
                                        <div style="font-size: 11px; color: #7f8c8d; margin-top: 5px;">
                                            <i class="fas fa-comment"></i> <?php echo htmlspecialchars(substr($row['notes'], 0, 50)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="total-cell"><?php echo number_format($row['total_amount'], 2); ?> ETB</td>
                                <td class="result-cell">
                                    <span class="<?php echo $result_class; ?>">
                                        <?php echo $result_text; ?> ETB
                                    </span>
                                    <div class="result-info">
                                        <?php echo $result_info; ?> | 
                                        ሽያጭ: <?php echo number_format($daily_sales, 2); ?>
                                    </div>
                                </td>
                                <td style="font-size: 12px; color: #7f8c8d;">
                                    <?php echo date('h:i A', strtotime($row['created_at'])); ?>
                                    <div><?php echo htmlspecialchars($row['received_by']); ?></div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>ምንም ገንዘብ አልተመዘገበም</h3>
                        <p>ለባለቤት የተሰጠ ገንዘብ እዚህ ይታያል</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Change branch function for super admin
        function changeBranch(branchId) {
            if (branchId) {
                window.location.href = 'boss_receive.php?branch_id=' + branchId;
            }
        }

        // Calculate total function
        function calculateTotal() {
            const cash = parseFloat(document.getElementById('cash').value) || 0;
            const cbe = parseFloat(document.getElementById('cbe').value) || 0;
            const abyssinia = parseFloat(document.getElementById('abyssinia').value) || 0;
            const telebirr = parseFloat(document.getElementById('telebirr').value) || 0;
            
            const total = cash + cbe + abyssinia + telebirr;
            document.getElementById('totalAmount').textContent = total.toFixed(2) + ' ETB';
        }

        // Form validation
        document.getElementById('bossForm').addEventListener('submit', function(e) {
            const cash = parseFloat(document.getElementById('cash').value) || 0;
            const cbe = parseFloat(document.getElementById('cbe').value) || 0;
            const abyssinia = parseFloat(document.getElementById('abyssinia').value) || 0;
            const telebirr = parseFloat(document.getElementById('telebirr').value) || 0;
            
            const total = cash + cbe + abyssinia + telebirr;
            const todaySales = <?php echo $today_sales; ?>;
            
            if (total <= 0) {
                e.preventDefault();
                alert('እባክዎ ቢያንስ አንድ የክፍያ ዘዴ ያስገቡ');
                return;
            }
            
            let message = 'ለባለቤት ' + total.toFixed(2) + ' ETB መስጠት እርግጠኛ ነዎት?\n\n';
            
            if (total > todaySales) {
                message += '⚠️ ማስጠንቀቂያ: ይህ መጠን ከዛሬ ሽያጭ (' + todaySales.toFixed(2) + ' ETB) በላይ ነው!\n';
                message += 'ትርፍ: ' + (total - todaySales).toFixed(2) + ' ETB (አረንጓዴ)';
            } else {
                message += 'ከሽያጭ ቀሪ: ' + (todaySales - total).toFixed(2) + ' ETB (ቀይ)';
            }
            
            if (!confirm(message)) {
                e.preventDefault();
            }
        });

        // Initialize calculate on page load
        calculateTotal();
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
