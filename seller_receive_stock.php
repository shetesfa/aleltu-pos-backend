<?php
session_start();
require_once 'config.php';
date_default_timezone_set('Africa/Addis_Ababa');

// Set MySQL timezone to match PHP (East Africa Time)
mysqli_query($conn, "SET time_zone = '+03:00'");

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'seller';

// Get branch info based on user role
$user_branch = getUserBranch($conn, $user_id);

// For super admin, allow branch switching via URL
if ($user_role == 'super_admin' && isset($_GET['branch_id']) && !empty($_GET['branch_id'])) {
    $branch_id = intval($_GET['branch_id']);
    $branch_name = getCurrentBranchName($conn, $branch_id);
    setBranchSession($branch_id, $branch_name);
} else {
    $branch_id = getCurrentBranchId($conn, $user_id, $user_role);
    $branch_name = getCurrentBranchName($conn, $branch_id);
}

// ========== ETHIOPIAN DATE FUNCTIONS ==========
if (!function_exists('gregorian_to_ethiopian')) {
function gregorian_to_ethiopian($year_or_date, $month = null, $day = null) {
    if ($month === null && $day === null) {
        $timestamp = is_numeric($year_or_date) ? (int)$year_or_date : strtotime((string)$year_or_date);
        if ($timestamp === false) $timestamp = time();
        $year = (int)date('Y', $timestamp);
        $month = (int)date('n', $timestamp);
        $day = (int)date('j', $timestamp);
    } else {
        $year = (int)$year_or_date;
        $month = (int)$month;
        $day = (int)$day;
    }

    $ethiopian_months = [
        1 => "መስከረም", 2 => "ጥቅምት", 3 => "ህዳር", 4 => "ታህሳስ", 
        5 => "ጥር", 6 => "የካቲት", 7 => "መጋቢት", 8 => "ሚያዝያ", 
        9 => "ግንቦት", 10 => "ሰኔ", 11 => "ሐምሌ", 12 => "ነሐሴ", 13 => "ጳጉሜ"
    ];
    
    $ethiopian_year = $year - 8;
    $is_gregorian_leap = ($year % 4 == 0 && $year % 100 != 0) || ($year % 400 == 0);
    $new_year_day = $is_gregorian_leap ? 12 : 11;
    
    if ($month > 9 || ($month == 9 && $day >= $new_year_day)) {
        $ethiopian_year = $year - 7;
    }
    
    $new_year_gregorian_year = $year;
    if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
        $new_year_gregorian_year = $year - 1;
    }
    
    $is_new_year_leap = ($new_year_gregorian_year % 4 == 0 && $new_year_gregorian_year % 100 != 0) || ($new_year_gregorian_year % 400 == 0);
    $new_year_day_final = $is_new_year_leap ? 12 : 11;
    
    $jd_current = gregoriantojd($month, $day, $year);
    $jd_new_year = gregoriantojd(9, $new_year_day_final, $new_year_gregorian_year);
    $days_since_new_year = $jd_current - $jd_new_year;
    
    $ethiopian_month = floor($days_since_new_year / 30) + 1;
    $ethiopian_day = ($days_since_new_year % 30) + 1;
    
    if ($ethiopian_month == 13) {
        $is_ethiopian_leap = ($ethiopian_year % 4 == 3);
        $max_pagume_days = $is_ethiopian_leap ? 6 : 5;
        
        if ($ethiopian_day > $max_pagume_days) {
            $ethiopian_month = 1;
            $ethiopian_day -= $max_pagume_days;
            $ethiopian_year++;
        }
    }
    
    $ethiopian_month = max(1, min(13, $ethiopian_month));
    
    return [
        'year' => $ethiopian_year,
        'month' => $ethiopian_month,
        'month_name' => $ethiopian_months[$ethiopian_month] ?? "መስከረም",
        'day' => $ethiopian_day,
        'full_date' => sprintf("%04d-%02d-%02d", $ethiopian_year, $ethiopian_month, $ethiopian_day),
        'display_date' => $ethiopian_day . ' ' . ($ethiopian_months[$ethiopian_month] ?? "መስከረም") . ' ' . $ethiopian_year
    ];
}
}

if (!function_exists('get_ethiopian_today')) {
function get_ethiopian_today() {
    return gregorian_to_ethiopian(date('Y'), date('n'), date('j'));
}
}

if (!function_exists('format_ethiopian_date_from_db')) {
function format_ethiopian_date_from_db($db_datetime) {
    if (empty($db_datetime)) return ['display' => ''];
    $timestamp = strtotime($db_datetime);
    $year = (int)date('Y', $timestamp);
    $month = (int)date('n', $timestamp);
    $day = (int)date('j', $timestamp);
    $eth = gregorian_to_ethiopian($year, $month, $day);
    return ['display' => $eth['display_date']];
}
}

if (!function_exists('format_gregorian_time_12hr')) {
function format_gregorian_time_12hr($datetime) {
    if (empty($datetime)) return '';
    $date = new DateTime($datetime, new DateTimeZone('Africa/Addis_Ababa'));
    return $date->format('h:i:s A');
}
}

// Get current Ethiopian date
$current_ethiopian = get_ethiopian_today();
$current_gregorian_time = date('h:i:s A');
$today_display = $current_ethiopian['display_date'];

// Check tables
$check_stock_logs = mysqli_query($conn, "SHOW TABLES LIKE 'stock_logs'");
$stock_logs_exists = $check_stock_logs && mysqli_num_rows($check_stock_logs) > 0;

$check_inventory = mysqli_query($conn, "SHOW TABLES LIKE 'seller_inventory'");
$inventory_exists = $check_inventory && mysqli_num_rows($check_inventory) > 0;

// Get all sellers in this branch
$sellers_in_branch = [];
if ($user_role == 'admin' || $user_role == 'super_admin') {
    $sellers_query = "SELECT id, full_name, username FROM users WHERE role = 'seller' AND branch_id = $branch_id ORDER BY full_name";
    $sellers_result = mysqli_query($conn, $sellers_query);
    if ($sellers_result) {
        while($seller = mysqli_fetch_assoc($sellers_result)) {
            $sellers_in_branch[] = $seller;
        }
    }
}

// ========== AJAX HANDLER FOR LAZY LOADING ==========
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');
    
    $load_type = $_GET['load_type'] ?? 'today';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 30;
    
    $offset = ($page - 1) * $limit;
    
    $response = ['data' => [], 'total' => 0, 'has_more' => false, 'html' => ''];
    
    if ($load_type == 'today') {
        $query = "SELECT sl.*, u.full_name as seller_full_name
                  FROM stock_logs sl
                  LEFT JOIN users u ON sl.seller_id = u.id
                  WHERE sl.branch_id = $branch_id 
                  AND (sl.source != 'return' OR sl.source IS NULL)
                  AND (sl.notes NOT LIKE '%ተመላሽ%' OR sl.notes IS NULL)
                  AND DATE(sl.date_added) = CURDATE()
                  ORDER BY sl.date_added DESC
                  LIMIT $limit";
        $result = mysqli_query($conn, $query);
        
        if ($result) {
            while($row = mysqli_fetch_assoc($result)) {
                $eth_date = format_ethiopian_date_from_db($row['date_added']);
                $row['ethiopian_date'] = $eth_date['display'];
                $row['gregorian_time'] = format_gregorian_time_12hr($row['date_added']);
                if (empty($row['seller_name']) && !empty($row['seller_full_name'])) {
                    $row['seller_name'] = $row['seller_full_name'];
                }
                $response['data'][] = $row;
            }
        }
        
    } elseif ($load_type == 'yesterday') {
        $query = "SELECT sl.*, u.full_name as seller_full_name
                  FROM stock_logs sl
                  LEFT JOIN users u ON sl.seller_id = u.id
                  WHERE sl.branch_id = $branch_id 
                  AND (sl.source != 'return' OR sl.source IS NULL)
                  AND (sl.notes NOT LIKE '%ተመላሽ%' OR sl.notes IS NULL)
                  AND DATE(sl.date_added) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  ORDER BY sl.date_added DESC
                  LIMIT $limit";
        $result = mysqli_query($conn, $query);
        
        if ($result) {
            while($row = mysqli_fetch_assoc($result)) {
                $eth_date = format_ethiopian_date_from_db($row['date_added']);
                $row['ethiopian_date'] = $eth_date['display'];
                $row['gregorian_time'] = format_gregorian_time_12hr($row['date_added']);
                if (empty($row['seller_name']) && !empty($row['seller_full_name'])) {
                    $row['seller_name'] = $row['seller_full_name'];
                }
                $response['data'][] = $row;
            }
        }
        
    } elseif ($load_type == 'all') {
        $count_query = "SELECT COUNT(*) as total 
                        FROM stock_logs sl
                        WHERE sl.branch_id = $branch_id 
                        AND (sl.source != 'return' OR sl.source IS NULL)
                        AND (sl.notes NOT LIKE '%ተመላሽ%' OR sl.notes IS NULL)";
        $count_result = mysqli_query($conn, $count_query);
        if ($count_result) {
            $response['total'] = (int)mysqli_fetch_assoc($count_result)['total'];
            $response['has_more'] = ($offset + $limit) < $response['total'];
        }
        
        $query = "SELECT sl.*, u.full_name as seller_full_name
                  FROM stock_logs sl
                  LEFT JOIN users u ON sl.seller_id = u.id
                  WHERE sl.branch_id = $branch_id 
                  AND (sl.source != 'return' OR sl.source IS NULL)
                  AND (sl.notes NOT LIKE '%ተመላሽ%' OR sl.notes IS NULL)
                  ORDER BY sl.date_added DESC
                  LIMIT $offset, $limit";
        $result = mysqli_query($conn, $query);
        
        if ($result) {
            while($row = mysqli_fetch_assoc($result)) {
                $eth_date = format_ethiopian_date_from_db($row['date_added']);
                $row['ethiopian_date'] = $eth_date['display'];
                $row['gregorian_time'] = format_gregorian_time_12hr($row['date_added']);
                if (empty($row['seller_name']) && !empty($row['seller_full_name'])) {
                    $row['seller_name'] = $row['seller_full_name'];
                }
                $response['data'][] = $row;
            }
        }
    }
    
    echo json_encode($response);
    exit();
}

// ========== HANDLE EXCEL EXPORT ==========
if (isset($_GET['export_excel']) && $_GET['export_excel'] == '1') {
    $date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
    
    $where_clauses = ["sl.branch_id = $branch_id", "(sl.source != 'return' OR sl.source IS NULL)", "(sl.notes NOT LIKE '%ተመላሽ%' OR sl.notes IS NULL)"];
    
    if ($date_filter == 'today') {
        $where_clauses[] = "DATE(sl.date_added) = CURDATE()";
    } elseif ($date_filter == 'yesterday') {
        $where_clauses[] = "DATE(sl.date_added) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($date_filter == 'last7days') {
        $where_clauses[] = "DATE(sl.date_added) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter == 'last30days') {
        $where_clauses[] = "DATE(sl.date_added) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($date_filter == 'custom' && !empty($start_date) && !empty($end_date)) {
        $where_clauses[] = "DATE(sl.date_added) BETWEEN '$start_date' AND '$end_date'";
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    $export_query = "SELECT sl.*, u.full_name as seller_full_name
                    FROM stock_logs sl
                    LEFT JOIN users u ON sl.seller_id = u.id
                    WHERE $where_sql
                    ORDER BY sl.date_added DESC";
    
    $export_result = mysqli_query($conn, $export_query);
    
    if (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stock_receive_report_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    
    echo '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>body{font-family:"Segoe UI",Arial,sans-serif;margin:20px;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} th{background:#4361ee;color:white;}</style>';
    echo '</head><body>';
    echo '<h2>📦 የምርት መቀበያ ሪፖርት</h2>';
    echo '<p>ቅርንጫፍ: ' . htmlspecialchars($branch_name) . ' | ቀን: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<table>';
    echo '<tr><th>#</th><th>ሻጭ</th><th>እቃ</th><th>ብዛት</th><th>መለኪያ</th><th>ምንጭ</th><th>የኢትዮጵያ ቀን</th><th>ሰዓት</th><th>ማስታወሻ</th></tr>';
    
    if ($export_result && mysqli_num_rows($export_result) > 0) {
        $counter = 1;
        while($stock = mysqli_fetch_assoc($export_result)) {
            $eth_date = format_ethiopian_date_from_db($stock['date_added']);
            $eth_date_display = $eth_date ? $eth_date['display'] : '';
            $gregorian_time = format_gregorian_time_12hr($stock['date_added']);
            $seller_display = !empty($stock['seller_full_name']) ? $stock['seller_full_name'] : ($stock['seller_name'] ?? 'ሻጭ');
            
            $source_text = $stock['source'];
            if ($stock['source'] == 'admin') $source_text = 'ከፋርም';
            elseif ($stock['source'] == 'purchase') $source_text = 'የተገዛ';
            
            echo '<tr>';
            echo '<td>' . $counter++ . '</td>';
            echo '<td>' . htmlspecialchars($seller_display) . '</td>';
            echo '<td>' . htmlspecialchars($stock['item_name']) . '</td>';
            echo '<td>' . number_format($stock['quantity'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($stock['unit']) . '</td>';
            echo '<td>' . htmlspecialchars($source_text) . '</td>';
            echo '<td>' . htmlspecialchars($eth_date_display) . '</td>';
            echo '<td>' . htmlspecialchars($gregorian_time) . '</td>';
            echo '<td>' . htmlspecialchars(substr($stock['notes'] ?? '', 0, 50)) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9" style="text-align:center;">ምንም data አልተገኘም</td></tr>';
    }
    
    echo '</table></body></html>';
    exit();
}

// Handle add stock
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_stock'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }
    $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
    $quantity = floatval($_POST['quantity']);
    $unit = mysqli_real_escape_string($conn, $_POST['unit']);
    $source = mysqli_real_escape_string($conn, $_POST['source']);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if (($user_role == 'admin' || $user_role == 'super_admin') && isset($_POST['target_seller_id']) && !empty($_POST['target_seller_id'])) {
        $target_seller_id = intval($_POST['target_seller_id']);
        $target_seller_name = '';
        $seller_name_query = mysqli_query($conn, "SELECT full_name, username FROM users WHERE id = $target_seller_id");
        if ($seller_name_row = mysqli_fetch_assoc($seller_name_query)) {
            $target_seller_name = $seller_name_row['full_name'] ?: $seller_name_row['username'];
        }
    } else {
        $target_seller_id = $user_id;
        $target_seller_name = $user_name;
    }
    
    if ($source == 'other' && !empty($_POST['custom_source'])) {
        $source = mysqli_real_escape_string($conn, $_POST['custom_source']);
    }
    
    $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $eth_year = (int)$now->format('Y');
    $eth_month = (int)$now->format('m');
    $eth_day = (int)$now->format('d');
    $ethiopian_date_info = gregorian_to_ethiopian($eth_year, $eth_month, $eth_day);
    $ethiopian_date = $ethiopian_date_info['full_date'];
    
    $inventory_success = false;
    
    if ($inventory_exists) {
        $check_columns = mysqli_query($conn, "SHOW COLUMNS FROM seller_inventory");
        $item_column = 'item_name';
        $has_seller_id = false;
        $has_branch_id = false;
        
        if ($check_columns) {
            while($col = mysqli_fetch_assoc($check_columns)) {
                if ($col['Field'] == 'seller_id') $has_seller_id = true;
                if ($col['Field'] == 'branch_id') $has_branch_id = true;
                if (in_array($col['Field'], ['item_name', 'name', 'product_name'])) {
                    $item_column = $col['Field'];
                }
            }
        }
        
        // Check existing shared row with prepared statement
        if ($has_branch_id) {
            $stmt_check = mysqli_prepare($conn, "SELECT id FROM seller_inventory WHERE `$item_column` = ? AND branch_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_check, 'si', $item_name, $branch_id);
        } else {
            $stmt_check = mysqli_prepare($conn, "SELECT id FROM seller_inventory WHERE `$item_column` = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_check, 's', $item_name);
        }
        mysqli_stmt_execute($stmt_check);
        $res_check = mysqli_stmt_get_result($stmt_check);
        $existing_row = ($res_check && mysqli_num_rows($res_check) > 0) ? mysqli_fetch_assoc($res_check) : null;
        mysqli_stmt_close($stmt_check);

        if ($existing_row) {
            $existing_id = (int)$existing_row['id'];
            $upd_stmt = mysqli_prepare($conn, "UPDATE seller_inventory SET current_stock = current_stock + ?, unit = ?, last_updated = NOW() WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($upd_stmt, 'dsi', $quantity, $unit, $existing_id);
            $inventory_success = mysqli_stmt_execute($upd_stmt);
            mysqli_stmt_close($upd_stmt);
        } else {
            if ($has_seller_id && $has_branch_id) {
                $ins_stmt = mysqli_prepare($conn, "INSERT INTO seller_inventory (seller_id, `$item_column`, current_stock, unit, last_updated, branch_id) VALUES (0, ?, ?, ?, NOW(), ?)");
                mysqli_stmt_bind_param($ins_stmt, 'sdsi', $item_name, $quantity, $unit, $branch_id);
            } elseif ($has_seller_id) {
                $ins_stmt = mysqli_prepare($conn, "INSERT INTO seller_inventory (seller_id, `$item_column`, current_stock, unit, last_updated) VALUES (0, ?, ?, ?, NOW())");
                mysqli_stmt_bind_param($ins_stmt, 'sds', $item_name, $quantity, $unit);
            } elseif ($has_branch_id) {
                $ins_stmt = mysqli_prepare($conn, "INSERT INTO seller_inventory (`$item_column`, current_stock, unit, last_updated, branch_id) VALUES (?, ?, ?, NOW(), ?)");
                mysqli_stmt_bind_param($ins_stmt, 'sdsi', $item_name, $quantity, $unit, $branch_id);
            } else {
                $ins_stmt = mysqli_prepare($conn, "INSERT INTO seller_inventory (`$item_column`, current_stock, unit, last_updated) VALUES (?, ?, ?, NOW())");
                mysqli_stmt_bind_param($ins_stmt, 'sds', $item_name, $quantity, $unit);
            }
            $inventory_success = mysqli_stmt_execute($ins_stmt);
            mysqli_stmt_close($ins_stmt);
        }
    }
    
    if ($stock_logs_exists && $inventory_success) {
        $check_logs_columns = mysqli_query($conn, "SHOW COLUMNS FROM stock_logs");
        $logs_has_branch = false;
        $logs_has_ethiopian_date = false;
        
        if ($check_logs_columns) {
            while($col = mysqli_fetch_assoc($check_logs_columns)) {
                if ($col['Field'] == 'branch_id') $logs_has_branch = true;
                if ($col['Field'] == 'ethiopian_date') $logs_has_ethiopian_date = true;
            }
        }
        
        if ($logs_has_branch && $logs_has_ethiopian_date) {
            $log_stmt = mysqli_prepare($conn, "INSERT INTO stock_logs (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, ethiopian_date, notes, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
            mysqli_stmt_bind_param($log_stmt, 'issdsssssi', $target_seller_id, $target_seller_name, $item_name, $quantity, $unit, $source, $user_name, $ethiopian_date, $notes, $branch_id);
        } elseif ($logs_has_branch) {
            $log_stmt = mysqli_prepare($conn, "INSERT INTO stock_logs (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
            mysqli_stmt_bind_param($log_stmt, 'issdssssi', $target_seller_id, $target_seller_name, $item_name, $quantity, $unit, $source, $user_name, $notes, $branch_id);
        } else {
            $log_stmt = mysqli_prepare($conn, "INSERT INTO stock_logs (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            mysqli_stmt_bind_param($log_stmt, 'issdssss', $target_seller_id, $target_seller_name, $item_name, $quantity, $unit, $source, $user_name, $notes);
        }
        
        $log_success = mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
        
        if ($log_success) {
            $_SESSION['message'] = "✅ ምርት በተሳካ ሁኔታ ተመዝግቧል!";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "❌ ምርት በመመዝገብ ላይ ስህተት: " . mysqli_error($conn);
            $_SESSION['message_type'] = "danger";
        }
    } elseif (!$stock_logs_exists) {
        $_SESSION['message'] = "⚠️ ምርት ተመዝግቧል ነገር ግን የምዝገባ ሰንጠረዥ አልተገኘም።";
        $_SESSION['message_type'] = "warning";
    }
    
    header("Location: seller_receive_stock.php" . ($user_role == 'super_admin' && isset($_GET['branch_id']) ? '?branch_id=' . $_GET['branch_id'] : ''));
    exit();
}

// Get products for dropdown
$products_list = [];
$products_result = mysqli_query($conn, "SELECT name FROM products WHERE is_active = 1 AND branch_id = $branch_id ORDER BY name LIMIT 100");
if ($products_result) {
    while($product = mysqli_fetch_assoc($products_result)) {
        $products_list[] = $product['name'];
    }
}

// Get counts
$today_count = 0;
$today_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM stock_logs sl WHERE sl.branch_id = $branch_id AND DATE(sl.date_added) = CURDATE()");
if ($today_result) {
    $today_count = mysqli_fetch_assoc($today_result)['total'];
}

$yesterday_count = 0;
$yesterday_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM stock_logs sl WHERE sl.branch_id = $branch_id AND DATE(sl.date_added) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
if ($yesterday_result) {
    $yesterday_count = mysqli_fetch_assoc($yesterday_result)['total'];
}

$total_records = 0;
$total_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM stock_logs sl WHERE sl.branch_id = $branch_id");
if ($total_result) {
    $total_records = mysqli_fetch_assoc($total_result)['total'];
}

if ($inventory_exists) {
    $count_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM seller_inventory WHERE branch_id = $branch_id");
    if ($count_result) {
        $inventory_count = mysqli_fetch_assoc($count_result)['count'] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>ምርት መቀበያ - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --success-dark: #00b894;
            --warning: #f8961e;
            --danger: #f72585;
            --info: #3a86ff;
            --light: #f8f9fa;
            --dark: #212529;
            --gray-200: #e9ecef;
            --gray-600: #6c757d;
            --border-radius: 15px;
            --border-radius-sm: 10px;
            --shadow: 0 8px 30px rgba(0,0,0,0.12);
            --transition: all 0.3s ease;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', 'Nyala', 'Abyssinica SIL', sans-serif; }
        
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            min-height: 100vh; 
            padding: 10px; 
            color: var(--dark); 
        }
        
        .dashboard-container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: white; 
            border-radius: var(--border-radius); 
            box-shadow: 0 15px 50px rgba(0,0,0,0.2); 
            overflow: hidden; 
        }
        
        .dashboard-header { 
            background: <?php 
                if ($user_role == 'super_admin') echo 'linear-gradient(135deg, #e74c3c, #c0392b)';
                elseif ($user_role == 'admin') echo 'linear-gradient(135deg, #f39c12, #e67e22)';
                else echo 'linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%)';
            ?>; 
            color: white; 
            padding: 15px 18px; 
        }
        
        .header-content { 
            display: flex; 
            flex-direction: column;
            align-items: flex-start; 
            gap: 15px; 
        }
        
        .header-title h1 { 
            font-size: 1.2rem; 
            font-weight: 800; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            flex-wrap: wrap;
        }
        
        .role-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 5px;
        }
        
        .ethiopian-date-badge, .branch-badge { 
            background: rgba(255,255,255,0.2); 
            padding: 4px 8px; 
            border-radius: 20px; 
            font-size: 0.7rem; 
            backdrop-filter: blur(10px); 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            margin-top: 5px;
        }
        
        .gregorian-time-badge {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: monospace;
            margin-top: 5px;
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
            color: #333;
            min-height: 44px;
        }
        
        .user-info { 
            background: rgba(255,255,255,0.2); 
            padding: 10px 14px; 
            border-radius: var(--border-radius-sm); 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            width: 100%;
        }
        
        .avatar { 
            width: 40px; 
            height: 40px; 
            min-width: 40px;
            background: linear-gradient(45deg, var(--primary), var(--secondary)); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
            color: white; 
            font-size: 1.1rem; 
        }
        
        .header-actions { 
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap; 
            width: 100%;
        }
        
        .btn { 
            padding: 12px 18px; 
            border-radius: var(--border-radius-sm); 
            font-weight: 600; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center;
            gap: 8px; 
            border: none; 
            cursor: pointer; 
            transition: var(--transition); 
            min-height: 44px;
            flex: 1;
        }
        
        .btn:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 5px 15px rgba(0,0,0,0.2); 
        }
        
        .btn-back { 
            background: rgba(255,255,255,0.9); 
            color: var(--primary); 
        }
        
        .btn-pos { 
            background: linear-gradient(to right, var(--warning), #ff9500); 
            color: white; 
        }
        
        .btn-excel { 
            background: linear-gradient(135deg, #27ae60, #2ecc71); 
            color: white; 
        }
        
        .refresh-btn { 
            background: var(--info); 
            color: white; 
            border: none; 
            padding: 10px 15px; 
            border-radius: 20px; 
            cursor: pointer; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            gap: 5px; 
            min-height: 44px;
        }
        
        .alert { 
            margin: 15px 15px 15px; 
            padding: 12px 16px; 
            border-radius: var(--border-radius-sm); 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            animation: slideDown 0.3s ease; 
        }
        
        .alert-success { background: #d4edda; border: 2px solid #28a745; color: #155724; }
        .alert-danger { background: #f8d7da; border: 2px solid #dc3545; color: #721c24; }
        .alert-warning { background: #fff3cd; border: 2px solid #ffc107; color: #856404; }
        
        @keyframes slideDown { 
            from { transform: translateY(-20px); opacity: 0; } 
            to { transform: translateY(0); opacity: 1; } 
        }
        
        .dashboard-content { 
            padding: 15px; 
            display: grid; 
            grid-template-columns: 1fr; 
            gap: 20px; 
        }
        
        .form-panel, .history-panel { 
            background: white; 
            border-radius: var(--border-radius); 
            padding: 18px; 
            box-shadow: var(--shadow); 
            border: 1px solid var(--gray-200); 
            animation: fadeIn 0.5s ease; 
        }
        
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(20px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        
        .panel-title { 
            font-size: 1.25rem; 
            font-weight: 700; 
            margin-bottom: 20px; 
            padding-bottom: 12px; 
            border-bottom: 2px solid var(--gray-200); 
            display: flex; 
            align-items: center; 
            gap: 10px; 
        }
        
        .form-group { margin-bottom: 18px; }
        
        .form-group label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        
        .form-control { 
            width: 100%; 
            padding: 12px; 
            border: 2px solid var(--gray-200); 
            border-radius: var(--border-radius-sm); 
            font-size: 1rem; 
            transition: var(--transition); 
            min-height: 44px;
        }
        
        .form-control:focus { 
            outline: none; 
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1); 
        }
        
        .quantity-unit-group { 
            display: grid; 
            grid-template-columns: 1fr; 
            gap: 12px; 
        }
        
        .custom-source-group { 
            margin-top: 15px; 
            display: none; 
        }
        
        .custom-source-group.show { display: block; }
        
        .submit-btn { 
            width: 100%; 
            padding: 14px; 
            background: linear-gradient(to right, var(--primary), var(--secondary)); 
            color: white; 
            border: none; 
            border-radius: var(--border-radius-sm); 
            font-size: 1.1rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: var(--transition); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 10px; 
            min-height: 44px;
        }
        
        .submit-btn:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 8px 25px rgba(67,97,238,0.3); 
        }
        
        .history-panel { 
            max-height: 800px; 
            overflow: hidden; 
            display: flex; 
            flex-direction: column; 
        }
        
        .tabs { 
            display: flex; 
            border-bottom: 2px solid var(--gray-200); 
            margin-bottom: 18px; 
            overflow-x: auto;
        }
        
        .tab-btn { 
            flex: 1; 
            padding: 12px 10px; 
            border: none; 
            background: var(--gray-200); 
            font-weight: 600; 
            cursor: pointer; 
            transition: var(--transition); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 6px; 
            min-height: 44px;
            white-space: nowrap;
        }
        
        .tab-btn:hover { background: #dcdfe3; }
        
        .tab-btn.active { 
            background: linear-gradient(135deg, var(--primary), var(--secondary)); 
            color: white; 
        }
        
        .tab-content { 
            display: none; 
            flex: 1; 
            overflow-y: auto; 
            padding-right: 5px; 
        }
        
        .tab-content.active { 
            display: block; 
            animation: fadeIn 0.3s ease; 
        }
        
        .stock-table-container { 
            overflow-y: auto; 
            overflow-x: auto;
            max-height: 600px; 
            border-radius: var(--border-radius-sm); 
            border: 1px solid var(--gray-200); 
        }
        
        .stock-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 0.85rem; 
        }
        
        .stock-table th { 
            background: linear-gradient(135deg, var(--primary), var(--secondary)); 
            color: white; 
            padding: 12px 10px; 
            text-align: left; 
            position: sticky; 
            top: 0; 
            z-index: 10;
            white-space: nowrap;
        }
        
        .stock-table td { 
            padding: 12px 10px; 
            border-bottom: 1px solid var(--gray-200); 
            white-space: nowrap;
        }
        
        .stock-table tr:hover { background: rgba(67,97,238,0.05); }

        /* Phone-first registered-products view: each table row becomes a
           readable card, so staff do not need to scroll sideways. */
        @media (max-width: 599px) {
            .history-panel { padding: 16px 12px; }
            .history-panel .panel-title { font-size: 1.15rem; line-height: 1.35; }
            .tabs { gap: 6px; border: 0; padding-bottom: 4px; }
            .tab-btn { flex: 0 0 auto; border-radius: 9px; padding: 10px 13px; }
            .stock-table-container { max-height: none; overflow: visible; border: 0; background: transparent; }
            .stock-table, .stock-table tbody, .stock-table tr, .stock-table td { display: block; width: 100%; }
            .stock-table thead { display: none; }
            .stock-table tbody { display: grid; gap: 10px; }
            .stock-table tr { background: #fff; border: 1px solid var(--gray-200); border-radius: 12px; padding: 6px 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
            .stock-table td { position: relative; min-height: 39px; padding: 9px 0 9px 43%; text-align: right; white-space: normal; border-bottom: 1px solid var(--gray-200); overflow-wrap: anywhere; }
            .stock-table td::before { position: absolute; left: 0; top: 9px; width: 40%; text-align: left; color: var(--gray-600); font-size: .73rem; font-weight: 700; }
            .stock-table td:nth-child(1)::before { content: 'Seller'; }
            .stock-table td:nth-child(2)::before { content: 'Product'; }
            .stock-table td:nth-child(3)::before { content: 'Quantity'; }
            .stock-table td:nth-child(4)::before { content: 'Unit'; }
            .stock-table td:nth-child(5)::before { content: 'Source'; }
            .stock-table td:nth-child(6)::before { content: 'Date'; }
            .stock-table td:nth-child(7)::before { content: 'Time'; }
            .stock-table td:last-child { border-bottom: 0; }
            .date-title { font-size: .9rem; line-height: 1.45; }
            .load-more-btn { min-height: 48px; font-size: .95rem; }
        }
        
        .load-more-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            transition: var(--transition);
            min-height: 44px;
        }
        
        .load-more-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67,97,238,0.3);
        }
        
        .load-more-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 20px;
            color: var(--primary);
        }
        
        .seller-badge {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .source-badge { 
            padding: 5px 12px; 
            border-radius: 20px; 
            font-size: 0.8rem; 
            font-weight: 600; 
            display: inline-block; 
        }
        
        .source-admin { background: rgba(138,43,226,0.1); color: #8a2be2; }
        .source-purchase { background: rgba(46,204,113,0.1); color: #2ecc71; }
        .source-other { background: rgba(108,117,125,0.1); color: #6c757d; }
        
        .date-title { 
            font-size: 1.1rem; 
            font-weight: 700; 
            margin-bottom: 15px; 
            padding-bottom: 10px; 
            border-bottom: 2px solid var(--gray-200); 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            flex-wrap: wrap;
        }
        
        .today-badge { 
            background: linear-gradient(135deg, var(--success), var(--success-dark)); 
            color: white; 
            padding: 5px 15px; 
            border-radius: 20px; 
        }
        
        .yesterday-badge { 
            background: linear-gradient(135deg, var(--warning), #ff9500); 
            color: white; 
            padding: 5px 15px; 
            border-radius: 20px; 
        }
        
        .ethiopian-date-cell { 
            font-family: 'Nyala', 'Segoe UI', sans-serif;
            font-weight: 600; 
            white-space: nowrap; 
            color: var(--dark);
        }
        
        .gregorian-time-cell { 
            font-family: monospace; 
            font-weight: 600; 
            white-space: nowrap; 
            color: var(--primary);
        }
        
        .empty-state { 
            text-align: center; 
            padding: 40px 15px; 
            color: var(--gray-600); 
        }
        
        .empty-state i { 
            font-size: 2.5rem; 
            margin-bottom: 12px; 
            color: var(--gray-300); 
        }
        
        .warning-box { 
            background: #fff3cd; 
            border: 2px solid #ffc107; 
            border-radius: var(--border-radius-sm); 
            padding: 12px 15px; 
            margin: 0 15px 15px; 
            color: #856404; 
        }
        
        .export-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        .export-modal-content {
            background: white;
            margin: 10% auto;
            width: 94%;
            max-width: 500px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideDown 0.3s ease;
        }
        
        .export-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 2px solid var(--gray-200);
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border-radius: 20px 20px 0 0;
        }
        
        .export-modal-header h3 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .export-modal-close {
            font-size: 26px;
            font-weight: bold;
            cursor: pointer;
            min-width: 44px;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }
        
        .export-modal-body { padding: 20px; }
        
        .export-date-section { margin-bottom: 15px; }
        
        .export-date-section label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .export-select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            min-height: 44px;
        }
        
        .export-custom-date {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed var(--gray-200);
        }
        
        .export-date-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .export-date-group label {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .export-input {
            padding: 10px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            min-height: 44px;
        }
        
        .export-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 15px 20px;
            border-top: 1px solid var(--gray-200);
            background: #f8f9fa;
            border-radius: 0 0 20px 20px;
        }
        
        .export-btn-cancel {
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            min-height: 44px;
        }
        
        .export-btn-download {
            padding: 10px 20px;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
        }
        
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--gray-200); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary), var(--secondary)); border-radius: 4px; }

        /* ── Progressive enhancement: ≥ 600px ── */
        @media (min-width: 600px) {
            body { padding: 20px; }
            .dashboard-header { padding: 25px 30px; }
            .ethiopian-date-badge, .gregorian-time-badge, .branch-badge { font-size: 0.9rem; padding: 8px 15px; margin-top: 0; }
            .user-info { width: auto; }
            .header-actions { width: auto; }
            .btn { flex: initial; }
            .quantity-unit-group { grid-template-columns: 2fr 1fr; }
            .export-custom-date { grid-template-columns: 1fr 1fr; gap: 15px; }
            .form-panel, .history-panel { padding: 30px; }
            .alert { margin: 0 30px 20px; padding: 15px 20px; }
            .warning-box { margin: 0 30px 20px; padding: 15px; }
            .panel-title { font-size: 1.5rem; }
            .stock-table { font-size: 0.9rem; }
            .stock-table td, .stock-table th { padding: 15px; }
        }

        /* ── Progressive enhancement: ≥ 768px ── */
        @media (min-width: 768px) {
            .header-content { flex-direction: row; justify-content: space-between; align-items: center; }
            .header-title h1 { font-size: 1.8rem; }
        }

        /* ── Progressive enhancement: ≥ 1200px ── */
        @media (min-width: 1200px) {
            .dashboard-content { padding: 30px; grid-template-columns: 1fr 1fr; gap: 30px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>
                        <i class="fas fa-truck-loading"></i> የምርት መቀበያ
                        <span class="role-badge">
                            <?php 
                            if ($user_role == 'super_admin') echo 'ሱፐር አድሚን';
                            elseif ($user_role == 'admin') echo 'አድሚን';
                            else echo 'ሻጭ';
                            ?>
                        </span>
                    </h1>
                    <div style="margin-top: 10px;">
                        <span class="ethiopian-date-badge"><i class="fas fa-calendar-alt"></i> <?php echo $today_display; ?> ዓ.ም</span>
                        <span class="gregorian-time-badge" id="liveGregorianTime"><i class="fas fa-clock"></i> <?php echo $current_gregorian_time; ?></span>
                        <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                </div>
                
                <div class="user-info">
                    <div class="avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                    <div>
                        <div style="font-weight:800;"><?php echo htmlspecialchars($user_name); ?></div>
                        <div style="font-size:0.85rem;">የምርት መቀበያ</div>
                    </div>
                </div>
                
                <div class="header-actions">
                    <?php if ($user_role == 'super_admin'): ?>
                    <div class="branch-selector">
                        <i class="fas fa-store"></i>
                        <select onchange="changeBranch(this.value)">
                            <option value="">ምረጥ</option>
                            <?php 
                            $branches = getAllBranches($conn);
                            foreach($branches as $b): 
                            ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['place_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <button class="refresh-btn" onclick="location.reload()"><i class="fas fa-sync-alt"></i> አድስ</button>
                    <button class="btn btn-excel" onclick="openExportModal()"><i class="fas fa-file-excel"></i> ኤክሴል አውርድ</button>
                    <a href="seller_pos.php<?php echo ($user_role == 'super_admin' && isset($_GET['branch_id'])) ? '?branch_id=' . $_GET['branch_id'] : ''; ?>" class="btn btn-pos"><i class="fas fa-shopping-cart"></i> ወደ ሽያጭ</a>
                    <a href="logout.php" class="btn btn-back"><i class="fas fa-sign-out-alt"></i> ውጣ</a>
                </div>
            </div>
        </div>
        
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo ($_SESSION['message_type'] ?? 'success') == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $_SESSION['message']; unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>
        
        <?php if(!$stock_logs_exists || !$inventory_exists): ?>
            <div class="warning-box"><i class="fas fa-exclamation-triangle"></i> <strong>Database Error:</strong> አንዳንድ ሠንጠረዦች አልተገኙም</div>
        <?php endif; ?>
        
        <div class="dashboard-content">
            <div class="form-panel">
                <h2 class="panel-title"><i class="fas fa-plus-circle"></i> አዲስ ምርት መቀበያ</h2>
                <form method="POST" action="" id="stockForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <?php if ($user_role == 'admin' || $user_role == 'super_admin'): ?>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> ለማን ይላካል?</label>
                        <select name="target_seller_id" class="form-control" required>
                            <option value="<?php echo $user_id; ?>">ለራሴ</option>
                            <?php foreach($sellers_in_branch as $seller): ?>
                                <option value="<?php echo $seller['id']; ?>"><?php echo htmlspecialchars($seller['full_name'] ?: $seller['username']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="target_seller_id" value="<?php echo $user_id; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> የእቃው ስም</label>
                        <input type="text" name="item_name" id="item_name" class="form-control" required placeholder="የእቃውን ስም ያስገቡ" list="productList" autocomplete="off">
                        <datalist id="productList">
                            <?php foreach($products_list as $p): ?>
                                <option value="<?php echo htmlspecialchars($p); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-balance-scale"></i> ብዛት እና መለኪያ</label>
                        <div class="quantity-unit-group">
                            <input type="number" name="quantity" id="quantity" class="form-control" step="0.1" min="0.1" required placeholder="ምን ያህል">
                            <select name="unit" id="unit" class="form-control" required>
                                <option value="">መለኪያ ምረጡ</option>
                                <option value="pcs">በፍሬ</option>
                                <option value="kg">በኪሎ</option>
                                <option value="g">ግራም</option>
                                <option value="l">ሊትር</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-truck"></i> ምንጭ</label>
                        <select name="source" id="source" class="form-control" required onchange="toggleCustomSource(this.value)">
                            <option value="">ምንጭ ምረጡ</option>
                            <option value="admin">ከፋርም የተላከ</option>
                            <option value="purchase">የተገዛ</option>
                            <option value="other">ሌላ</option>
                        </select>
                        <div id="customSourceGroup" class="custom-source-group">
                            <input type="text" name="custom_source" id="custom_source" class="form-control" placeholder="እባክዎ ምንጩን ያስገቡ">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> ማስታወሻ</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="ማስታወሻ ካለ ያስገቡ"></textarea>
                    </div>
                    
                    <button type="submit" name="add_stock" class="submit-btn" <?php echo (!$stock_logs_exists || !$inventory_exists) ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle"></i> መዝግብ
                    </button>
                </form>
            </div>
            
            <div class="history-panel">
                <h2 class="panel-title"><i class="fas fa-history"></i> የተመዘገቡ ምርቶች</h2>
                
                <?php if($stock_logs_exists): ?>
                <div class="tabs">
                    <button class="tab-btn active" data-tab="today"><i class="fas fa-calendar-day"></i> ዛሬ (<span id="todayCount"><?php echo $today_count; ?></span>)</button>
                    <button class="tab-btn" data-tab="yesterday"><i class="fas fa-calendar-minus"></i> ትናንት (<span id="yesterdayCount"><?php echo $yesterday_count; ?></span>)</button>
                    <button class="tab-btn" data-tab="all"><i class="fas fa-calendar-alt"></i> ሁሉም (<span id="allCount"><?php echo $total_records; ?></span>)</button>
                </div>
                
                <!-- Today Tab -->
                <div id="todayTab" class="tab-content active">
                    <div class="date-title"><i class="fas fa-calendar-day"></i> የዛሬ ምርቶች <span class="today-badge"><?php echo $today_display; ?></span></div>
                    <div id="todayContainer">
                        <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> በማውጣት ላይ...</div>
                    </div>
                </div>
                
                <!-- Yesterday Tab -->
                <div id="yesterdayTab" class="tab-content">
                    <div class="date-title"><i class="fas fa-calendar-minus"></i> የትናንት ምርቶች <span class="yesterday-badge"><?php echo date('Y-m-d', strtotime('-1 day')); ?></span></div>
                    <div id="yesterdayContainer">
                        <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> በማውጣት ላይ...</div>
                    </div>
                </div>
                
                <!-- All Tab - LAZY LOADING with proper table structure -->
                <div id="allTab" class="tab-content">
                    <div class="date-title"><i class="fas fa-calendar-alt"></i> ሁሉም ምርቶች 
                        <span class="today-badge">ጠቅላላ: <?php echo number_format($total_records); ?> መዝገቦች</span>
                    </div>
                    <div id="allContainer">
                        <div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> በማውጣት ላይ...</div>
                    </div>
                    <div id="loadMoreContainer" style="text-align: center;"></div>
                </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-database"></i><h3>Database Error</h3></div>
                <?php endif; ?>
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
                <div class="export-date-section">
                    <label><i class="fas fa-calendar-alt"></i> የቀን ክልል ምረጡ</label>
                    <select id="exportDateFilter" class="export-select" onchange="toggleExportCustomDate(this.value)">
                        <option value="all">ሁሉም መረጃዎች</option>
                        <option value="today">ዛሬ</option>
                        <option value="yesterday">ትናንት</option>
                        <option value="last7days">ያለፉ 7 ቀናት</option>
                        <option value="last30days">ያለፉ 30 ቀናት</option>
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
            </div>
            <div class="export-modal-footer">
                <button class="export-btn-cancel" onclick="closeExportModal()">ዝጋ</button>
                <button class="export-btn-download" onclick="exportToExcel()"><i class="fas fa-download"></i> ኤክሴል አውርድ</button>
            </div>
        </div>
    </div>
    
    <script>
        let allCurrentPage = 1;
        let allHasMore = true;
        let allLoading = false;
        let allTotalRecords = <?php echo $total_records; ?>;
        
        function changeBranch(branchId) {
            if (branchId) {
                window.location.href = 'seller_receive_stock.php?branch_id=' + branchId;
            }
        }
        
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tab + 'Tab').classList.add('active');
            
            const buttons = document.querySelectorAll('.tab-btn');
            for(let i = 0; i < buttons.length; i++) {
                if(buttons[i].getAttribute('data-tab') === tab) {
                    buttons[i].classList.add('active');
                    break;
                }
            }
            
            // Load data when tab is opened
            if (tab === 'today' && document.getElementById('todayContainer').innerHTML.includes('በማውጣት ላይ')) {
                loadTodayData();
            } else if (tab === 'yesterday' && document.getElementById('yesterdayContainer').innerHTML.includes('በማውጣት ላይ')) {
                loadYesterdayData();
            } else if (tab === 'all' && document.getElementById('allContainer').innerHTML.includes('በማውጣት ላይ')) {
                allCurrentPage = 1;
                allHasMore = true;
                document.getElementById('allContainer').innerHTML = '';
                loadMoreAllData();
            }
        }
        
        function loadTodayData() {
            fetch('?ajax_load=1&load_type=today&branch_id=<?php echo $branch_id; ?>')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('todayContainer');
                    if (data.data && data.data.length > 0) {
                        let html = '<div class="stock-table-container"><table class="stock-table"><thead><tr><th>ሻጭ</th><th>እቃ</th><th>ብዛት</th><th>መለኪያ</th><th>ምንጭ</th><th>የኢትዮጵያ ቀን</th><th>ሰዓት</th></tr></thead><tbody>';
                        data.data.forEach(item => {
                            let sourceText = item.source;
                            if (item.source === 'admin') sourceText = 'ከፋርም';
                            else if (item.source === 'purchase') sourceText = 'የተገዛ';
                            
                            html += `<tr>
                                <td><span class="seller-badge"><i class="fas fa-user"></i> ${escapeHtml(item.seller_name || 'ሻጭ')}</span></td>
                                <td><strong>${escapeHtml(item.item_name)}</strong></td>
                                <td><span style="font-weight:700;color:#00b894;">${item.quantity}</span></td>
                                <td>${escapeHtml(item.unit)}</td>
                                <td><span class="source-badge source-${item.source}">${sourceText}</span></td>
                                <td class="ethiopian-date-cell">${item.ethiopian_date}</td>
                                <td class="gregorian-time-cell">${item.gregorian_time}</td>
                            </tr>`;
                        });
                        html += '</tbody></table></div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="empty-state"><i class="fas fa-calendar-times"></i><h3>ዛሬ ምንም አልተመዘገበም</h3></div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('todayContainer').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>ስህተት ተከስቷል</h3></div>';
                });
        }
        
        function loadYesterdayData() {
            fetch('?ajax_load=1&load_type=yesterday&branch_id=<?php echo $branch_id; ?>')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('yesterdayContainer');
                    if (data.data && data.data.length > 0) {
                        let html = '<div class="stock-table-container"><table class="stock-table"><thead><tr><th>ሻጭ</th><th>እቃ</th><th>ብዛት</th><th>መለኪያ</th><th>ምንጭ</th><th>የኢትዮጵያ ቀን</th><th>ሰዓት</th></tr></thead><tbody>';
                        data.data.forEach(item => {
                            let sourceText = item.source;
                            if (item.source === 'admin') sourceText = 'ከፋርም';
                            else if (item.source === 'purchase') sourceText = 'የተገዛ';
                            
                            html += `<tr>
                                <td><span class="seller-badge"><i class="fas fa-user"></i> ${escapeHtml(item.seller_name || 'ሻጭ')}</span></td>
                                <td><strong>${escapeHtml(item.item_name)}</strong></td>
                                <td><span style="font-weight:700;color:#00b894;">${item.quantity}</span></td>
                                <td>${escapeHtml(item.unit)}</td>
                                <td><span class="source-badge source-${item.source}">${sourceText}</span></td>
                                <td class="ethiopian-date-cell">${item.ethiopian_date}</td>
                                <td class="gregorian-time-cell">${item.gregorian_time}</td>
                            </tr>`;
                        });
                        html += '</tbody></table></div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="empty-state"><i class="fas fa-calendar-times"></i><h3>ትናንት ምንም አልተመዘገበም</h3></div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('yesterdayContainer').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>ስህተት ተከስቷል</h3></div>';
                });
        }
        
        function loadMoreAllData() {
            if (allLoading || !allHasMore) return;
            
            allLoading = true;
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
                loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> በማውጣት ላይ...';
            }
            
            fetch(`?ajax_load=1&load_type=all&page=${allCurrentPage}&branch_id=<?php echo $branch_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('allContainer');
                    allHasMore = data.has_more;
                    
                    if (data.data && data.data.length > 0) {
                        // If this is the first page, create the table with headers
                        if (allCurrentPage === 1) {
                            let tableHtml = '<div class="stock-table-container"><table class="stock-table"><thead>';
                            tableHtml += '<tr><th>ሻጭ</th><th>እቃ</th><th>ብዛት</th><th>መለኪያ</th><th>ምንጭ</th><th>የኢትዮጵያ ቀን</th><th>ሰዓት</th></tr>';
                            tableHtml += '</thead><tbody id="allTableBody">';
                            container.innerHTML = tableHtml;
                        }
                        
                        // Get the tbody and append rows
                        const tbody = document.getElementById('allTableBody');
                        if (!tbody && allCurrentPage === 1) {
                            // Fallback if tbody not found
                            let rowsHtml = '';
                            data.data.forEach(item => {
                                let sourceText = item.source;
                                if (item.source === 'admin') sourceText = 'ከፋርም';
                                else if (item.source === 'purchase') sourceText = 'የተገዛ';
                                
                                rowsHtml += `<tr>
                                    <td><span class="seller-badge"><i class="fas fa-user"></i> ${escapeHtml(item.seller_name || 'ሻጭ')}</span></td>
                                    <td><strong>${escapeHtml(item.item_name)}</strong></td>
                                    <td><span style="font-weight:700;color:#00b894;">${item.quantity}</span></td>
                                    <td>${escapeHtml(item.unit)}</td>
                                    <td><span class="source-badge source-${item.source}">${sourceText}</span></td>
                                    <td class="ethiopian-date-cell">${item.ethiopian_date}</td>
                                    <td class="gregorian-time-cell">${item.gregorian_time}</td>
                                </tr>`;
                            });
                            container.innerHTML = container.innerHTML.replace('</tbody>', rowsHtml + '</tbody>');
                        } else if (tbody) {
                            // Append rows to existing tbody
                            data.data.forEach(item => {
                                let sourceText = item.source;
                                if (item.source === 'admin') sourceText = 'ከፋርም';
                                else if (item.source === 'purchase') sourceText = 'የተገዛ';
                                
                                const row = document.createElement('tr');
                                row.innerHTML = `
                                    <td><span class="seller-badge"><i class="fas fa-user"></i> ${escapeHtml(item.seller_name || 'ሻጭ')}</span></td>
                                    <td><strong>${escapeHtml(item.item_name)}</strong></td>
                                    <td><span style="font-weight:700;color:#00b894;">${item.quantity}</span></td>
                                    <td>${escapeHtml(item.unit)}</td>
                                    <td><span class="source-badge source-${item.source}">${sourceText}</span></td>
                                    <td class="ethiopian-date-cell">${item.ethiopian_date}</td>
                                    <td class="gregorian-time-cell">${item.gregorian_time}</td>
                                `;
                                tbody.appendChild(row);
                            });
                        }
                        
                        allCurrentPage++;
                        
                        // Close table if no more data
                        if (!allHasMore) {
                            const containerHtml = container.innerHTML;
                            if (!containerHtml.includes('</table>')) {
                                container.innerHTML = containerHtml + '</tbody></table></div>';
                            }
                        }
                    }
                    
                    // Update load more button
                    const loadMoreContainer = document.getElementById('loadMoreContainer');
                    const loadedCount = (allCurrentPage - 1) * 30;
                    
                    if (allHasMore) {
                        loadMoreContainer.innerHTML = `<button id="loadMoreBtn" class="load-more-btn" onclick="loadMoreAllData()"><i class="fas fa-plus-circle"></i> ተጨማሪ መዝገቦችን አሳይ (${loadedCount} / ${allTotalRecords})</button>`;
                    } else if (loadedCount > 30) {
                        loadMoreContainer.innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-check-circle"></i><h3>ሁሉም መዝገቦች ታይተዋል (' + allTotalRecords + ' መዝገቦች)</h3></div>';
                    } else if (!allHasMore && allCurrentPage > 1) {
                        loadMoreContainer.innerHTML = '<div class="empty-state" style="padding:20px;"><i class="fas fa-check-circle"></i><h3>ሁሉም መዝገቦች ታይተዋል</h3></div>';
                    }
                    
                    allLoading = false;
                })
                .catch(error => {
                    console.error('Error:', error);
                    allLoading = false;
                    const loadMoreContainer = document.getElementById('loadMoreContainer');
                    loadMoreContainer.innerHTML = '<button class="load-more-btn" onclick="loadMoreAllData()"><i class="fas fa-redo-alt"></i> እንደገና ሞክር</button>';
                });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function toggleCustomSource(source) {
            const customGroup = document.getElementById('customSourceGroup');
            const customInput = document.getElementById('custom_source');
            if (source === 'other') {
                customGroup.classList.add('show');
                customInput.required = true;
            } else {
                customGroup.classList.remove('show');
                customInput.required = false;
            }
        }
        
        document.getElementById('stockForm').addEventListener('submit', function(e) {
            if (parseFloat(document.getElementById('quantity').value) <= 0) {
                e.preventDefault();
                alert('እባክዎ ትክክለኛ ቁጥር ያስገቡ');
            }
        });
        
        function updateGregorianTime() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            const timeElement = document.querySelector('#liveGregorianTime');
            if (timeElement) timeElement.innerHTML = `<i class="fas fa-clock"></i> ${timeString}`;
        }
        setInterval(updateGregorianTime, 1000);
        
        function openExportModal() {
            document.getElementById('exportModal').style.display = 'block';
        }
        
        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }
        
        function toggleExportCustomDate(value) {
            const customDiv = document.getElementById('exportCustomDateRange');
            if (value === 'custom') {
                customDiv.style.display = 'grid';
                const today = new Date().toISOString().split('T')[0];
                const lastMonth = new Date();
                lastMonth.setMonth(lastMonth.getMonth() - 1);
                document.getElementById('exportStartDate').value = lastMonth.toISOString().split('T')[0];
                document.getElementById('exportEndDate').value = today;
            } else {
                customDiv.style.display = 'none';
            }
        }
        
        function exportToExcel() {
            const dateFilter = document.getElementById('exportDateFilter').value;
            let url = 'seller_receive_stock.php?export_excel=1&date_filter=' + dateFilter;
            
            if (dateFilter === 'custom') {
                const startDate = document.getElementById('exportStartDate').value;
                const endDate = document.getElementById('exportEndDate').value;
                if (!startDate || !endDate) {
                    alert('እባክዎ ትክክለኛ ቀን ይምረጡ');
                    return;
                }
                url += '&start_date=' + startDate + '&end_date=' + endDate;
            }
            
            <?php if ($user_role == 'super_admin' && isset($_GET['branch_id'])): ?>
            url += '&branch_id=<?php echo $_GET['branch_id']; ?>';
            <?php endif; ?>
            
            closeExportModal();
            window.open(url, '_blank');
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('exportModal');
            if (event.target == modal) closeExportModal();
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('item_name').focus();
            
            // Set up tab click handlers
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    switchTab(this.getAttribute('data-tab'));
                });
            });
            
            // Load initial data
            loadTodayData();
            loadYesterdayData();
            
            // Start loading all data (first batch)
            allCurrentPage = 1;
            allHasMore = true;
            loadMoreAllData();
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
