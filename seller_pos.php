<?php
session_start();
require_once 'config.php';

// ========== PERFORMANCE: No long timeout on page load ==========
// set_time_limit(300) REMOVED - it blocks PHP-FPM workers and causes 502 errors.
// Page loading should never take 5 minutes. Timeouts only needed in save_transaction.php.
ini_set('memory_limit', '128M');
// ================================================================

// Error handling - log but don't display to users
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Check connection
if (!$conn) {
    die("Connection failed. Please contact administrator.");
}

// Set charset
mysqli_set_charset($conn, "utf8mb4");

$current_user = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "User";
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'seller';

// Check if user is logged in
if ($user_id == 0) {
    header('Location: index.php');
    exit();
}

// Get branch info using functions from config.php
$branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$branch_name = getCurrentBranchName($conn, $branch_id);

// Validate branch exists
if ($branch_id <= 0 && $user_role != 'super_admin') {
    die("ERROR: No branch assigned. Please contact administrator.");
}

// For super admin, allow branch switching via URL
if ($user_role == 'super_admin' && isset($_GET['branch_id']) && !empty($_GET['branch_id']) && $_GET['branch_id'] > 0) {
    $branch_id = intval($_GET['branch_id']);
    $branch_name = getCurrentBranchName($conn, $branch_id);
    setBranchSession($branch_id, $branch_name);
}

// NON-superadmin users CANNOT change branch
if ($user_role != 'super_admin') {
    if (isset($_SESSION['branch_id']) && $_SESSION['branch_id'] > 0) {
        $branch_id = $_SESSION['branch_id'];
        $branch_name = getCurrentBranchName($conn, $branch_id);
    }
}

// ========== ETHIOPIAN DATE FUNCTION ==========
function get_ethiopian_date_time() {
    try {
        $gregorian_date = date('Y-m-d');
        list($greg_year, $greg_month, $greg_day) = explode('-', $gregorian_date);
        
        $ethiopian_months = [
            1 => ['start' => '09-11', 'name' => 'መስከረም'],
            2 => ['start' => '10-11', 'name' => 'ጥቅምት'],
            3 => ['start' => '11-10', 'name' => 'ኅዳር'],
            4 => ['start' => '12-10', 'name' => 'ታኅሣሥ'],
            5 => ['start' => '01-09', 'name' => 'ጥር'],
            6 => ['start' => '02-08', 'name' => 'የካቲት'],
            7 => ['start' => '03-10', 'name' => 'መጋቢት'],
            8 => ['start' => '04-09', 'name' => 'ሚያዝያ'],
            9 => ['start' => '05-09', 'name' => 'ግንቦት'],
            10 => ['start' => '06-08', 'name' => 'ሰኔ'],
            11 => ['start' => '07-08', 'name' => 'ሐምሌ'],
            12 => ['start' => '08-07', 'name' => 'ነሐሴ'],
            13 => ['start' => '09-06', 'name' => 'ጳጉሜ']
        ];
        
        $ethiopian_year = $greg_year - 8;
        if ($greg_month >= 9 || ($greg_month == 9 && $greg_day >= 11)) {
            $ethiopian_year++;
        }
        
        $current_date = $greg_month . '-' . $greg_day;
        $eth_month = 1;
        $eth_day = 1;
        
        for ($i = 1; $i <= 13; $i++) {
            $month_start = $ethiopian_months[$i]['start'];
            if ($current_date >= $month_start) {
                if ($i == 13) {
                    $next_year_first_month = $ethiopian_months[1]['start'];
                    if ($current_date < $next_year_first_month) {
                        $eth_month = $i;
                        list($next_month, $next_day) = explode('-', $next_year_first_month);
                        $greg_next_date = strtotime($greg_year . '-' . $next_month . '-' . $next_day);
                        $greg_current = strtotime($greg_year . '-' . $greg_month . '-' . $greg_day);
                        $eth_day = (int)(($greg_next_date - $greg_current) / (60 * 60 * 24));
                        break;
                    }
                } else {
                    $next_month_start = $ethiopian_months[$i + 1]['start'];
                    if ($current_date < $next_month_start) {
                        $eth_month = $i;
                        list($start_month, $start_day) = explode('-', $month_start);
                        $greg_start = strtotime($greg_year . '-' . $start_month . '-' . $start_day);
                        $greg_current = strtotime($greg_year . '-' . $greg_month . '-' . $greg_day);
                        $eth_day = (int)(($greg_current - $greg_start) / (60 * 60 * 24)) + 1;
                        break;
                    }
                }
            }
        }
        
        $timestamp = time();
        $ethiopian_timestamp = $timestamp + (3 * 3600);
        $eth_time_12h = date('h:i A', $ethiopian_timestamp);
        
        $eth_date = $ethiopian_year . '-' . str_pad($eth_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($eth_day, 2, '0', STR_PAD_LEFT);
        
        return [
            'date' => $eth_date,
            'time' => $eth_time_12h,
            'full_datetime' => $eth_date . ' ' . $eth_time_12h,
            'year' => $ethiopian_year,
            'month' => $eth_month,
            'day' => $eth_day,
            'month_name' => $ethiopian_months[$eth_month]['name'] ?? ''
        ];
    } catch (Exception $e) {
        error_log("Error in Ethiopian date function: " . $e->getMessage());
        return [
            'date' => date('Y-m-d'),
            'time' => date('h:i A'),
            'full_datetime' => date('Y-m-d H:i:s'),
            'year' => date('Y'),
            'month' => date('m'),
            'day' => date('d'),
            'month_name' => ''
        ];
    }
}

// ========== CREATE EDIT HISTORY TABLE (once per session only) ==========
// FIX: Only run CREATE TABLE once per session, not on every page load.
if (empty($_SESSION['history_table_checked'])) {
    $create_history_table = "CREATE TABLE IF NOT EXISTS `item_edit_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `old_name` varchar(255) NOT NULL,
        `new_name` varchar(255) NOT NULL,
        `old_price` decimal(10,2) NOT NULL,
        `new_price` decimal(10,2) NOT NULL,
        `edited_by` varchar(255) NOT NULL,
        `branch_id` int(11) NOT NULL,
        `edited_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `branch_id` (`branch_id`),
        KEY `edited_at` (`edited_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $create_history_table);
    $_SESSION['history_table_checked'] = true;
}

// ========== HANDLE ADD NEW PRODUCT ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_new_product'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new Exception('Invalid request.');
        }
        $new_product_name = trim($_POST['new_product_name']);
        $new_product_price = floatval($_POST['new_product_price']);
        
        if (empty($new_product_name) || $new_product_price <= 0) {
            $_SESSION['error'] = "Please enter valid product name and price!";
        } else {
            $check_stmt = mysqli_prepare($conn, "SELECT id FROM products WHERE name = ? AND branch_id = ? LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, 'si', $new_product_name, $branch_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if (mysqli_num_rows($check_result) > 0) {
                $_SESSION['error'] = "Product already exists in this branch!";
            } else {
                $insert_product = mysqli_prepare($conn, "INSERT INTO products (name, unit_price, created_at, last_edited_by, is_active, branch_id) VALUES (?, ?, NOW(), ?, 1, ?)");
                mysqli_stmt_bind_param($insert_product, 'sdsi', $new_product_name, $new_product_price, $current_user, $branch_id);
                if (mysqli_stmt_execute($insert_product)) {
                    mysqli_stmt_close($insert_product);
                    // FIXED: seller_id = 0 = shared branch-level stock row, not
                    // owned by whichever seller happened to create the product.
                    $shared_seller_id = 0;
                    $insert_inventory = mysqli_prepare($conn, "INSERT INTO seller_inventory (seller_id, item_name, current_stock, unit, price, branch_id) VALUES (?, ?, 0, 'pcs', ?, ?)");
                    mysqli_stmt_bind_param($insert_inventory, 'isdi', $shared_seller_id, $new_product_name, $new_product_price, $branch_id);
                    mysqli_stmt_execute($insert_inventory);
                    mysqli_stmt_close($insert_inventory);
                    $_SESSION['success'] = "New product added successfully!";
                } else {
                    mysqli_stmt_close($insert_product);
                    throw new Exception("Failed to insert product.");
                }
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?branch_id=" . $branch_id);
        exit();
    } catch (Exception $e) {
        error_log("Error adding product: " . $e->getMessage());
        $_SESSION['error'] = "Error adding product. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?branch_id=" . $branch_id);
        exit();
    }
}

// ========== HANDLE ITEM EDIT ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_item'])) {
    try {
        if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
            throw new Exception('Invalid request.');
        }
        $product_id = intval($_POST['product_id']);
        $new_name = trim($_POST['new_name']);
        $new_price = floatval($_POST['new_price']);
        
        if (empty($new_name) || $new_price <= 0) {
            $_SESSION['error'] = "Please enter valid name and price!";
        } else {
            $old_stmt = mysqli_prepare($conn, "SELECT name, unit_price FROM products WHERE id = ? AND branch_id = ? LIMIT 1");
            mysqli_stmt_bind_param($old_stmt, 'ii', $product_id, $branch_id);
            mysqli_stmt_execute($old_stmt);
            $old_result = mysqli_stmt_get_result($old_stmt);
            $old_data = mysqli_fetch_assoc($old_result);
            mysqli_stmt_close($old_stmt);
            
            if (!$old_data) {
                $_SESSION['error'] = "Product not found!";
            } else {
                $update_query = mysqli_prepare($conn, "UPDATE products SET name = ?, unit_price = ?, last_edited_by = ?, last_edited_at = NOW() WHERE id = ? AND branch_id = ?");
                mysqli_stmt_bind_param($update_query, 'sdsii', $new_name, $new_price, $current_user, $product_id, $branch_id);
                if (mysqli_stmt_execute($update_query)) {
                    mysqli_stmt_close($update_query);
                    $update_inventory = mysqli_prepare($conn, "UPDATE seller_inventory SET item_name = ?, price = ? WHERE item_name = ? AND branch_id = ?");
                    mysqli_stmt_bind_param($update_inventory, 'sdsi', $new_name, $new_price, $old_data['name'], $branch_id);
                    mysqli_stmt_execute($update_inventory);
                    mysqli_stmt_close($update_inventory);

                    $history_query = mysqli_prepare($conn, "INSERT INTO item_edit_history (product_id, old_name, new_name, old_price, new_price, edited_by, branch_id, edited_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    mysqli_stmt_bind_param($history_query, 'issddsi', $product_id, $old_data['name'], $new_name, $old_data['unit_price'], $new_price, $current_user, $branch_id);
                    mysqli_stmt_execute($history_query);
                    mysqli_stmt_close($history_query);
                    
                    $_SESSION['success'] = "Item updated successfully!";
                } else {
                    mysqli_stmt_close($update_query);
                    throw new Exception("Update failed.");
                }
            }
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?branch_id=" . $branch_id);
        exit();
    } catch (Exception $e) {
        error_log("Error editing product: " . $e->getMessage());
        $_SESSION['error'] = "Error updating product. Please try again.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?branch_id=" . $branch_id);
        exit();
    }
}

// ========== GET PRODUCTS - OPTIMIZED QUERY ==========
$products_data = [];

$products_query = "SELECT 
    p.id,
    p.name,
    p.unit_price,
    COALESCE(SUM(si.current_stock), 0) as current_stock,
    COALESCE(MAX(si.unit), 'pcs') as unit
    FROM products p
    LEFT JOIN seller_inventory si ON si.item_name = p.name AND si.branch_id = $branch_id
    WHERE p.branch_id = $branch_id
    AND (p.is_active IS NULL OR p.is_active = 1)
    GROUP BY p.id, p.name, p.unit_price
    ORDER BY p.name";

$products_result = mysqli_query($conn, $products_query);

if ($products_result) {
    while($product = mysqli_fetch_assoc($products_result)) {
        $products_data[] = $product;
    }
    mysqli_free_result($products_result);
}

$ethiopian_datetime = get_ethiopian_date_time();

if (isset($_SESSION['success'])) {
    echo "<script>alert('" . addslashes($_SESSION['success']) . "');</script>";
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo "<script>alert('" . addslashes($_SESSION['error']) . "');</script>";
    unset($_SESSION['error']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <link rel="icon" type="image/jpg" href="image/icon.png">
    <link rel="manifest" href="manifest.json">
    <title>Aleltu POS - <?php echo htmlspecialchars($branch_name); ?></title>
    <!-- Font Awesome: preload so it doesn't block page render -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <style>
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

        html, body {
            height: 100vh;
            overflow: hidden;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--dark);
            font-size: 14px;
        }

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

        .branch-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
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
            margin-right: 15px;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 1);
        }

        .container {
            display: grid;
            grid-template-columns: 320px 1fr 320px;
            grid-template-rows: auto 1fr auto;
            height: 100vh;
            gap: 15px;
            padding: 15px;
            overflow: hidden;
        }

        .top-header {
            grid-column: 1 / -1;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideDown 0.5s ease;
            z-index: 1000;
            flex-wrap: wrap;
            gap: 10px;
        }

        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--dark);
        }

        .user-role-text {
            font-size: 12px;
            color: var(--gray);
            background: var(--gray-light);
            padding: 2px 8px;
            border-radius: 10px;
            display: inline-block;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
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
            cursor: pointer;
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

        .left-sidebar {
            grid-row: 2;
            display: flex;
            flex-direction: column;
            gap: 15px;
            overflow-y: auto;
            padding-right: 5px;
            padding-bottom: 50px;
        }

        .total-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            animation: fadeIn 0.6s ease 0.2s backwards;
            flex-shrink: 0;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .total-box h2 {
            color: var(--gray);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        #total-amount {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .payment-methods {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease 0.3s backwards;
            flex-shrink: 0;
        }

        .payment-methods h3 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }

        .payment-btn {
            padding: 10px;
            border: 2px solid var(--gray-light);
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-weight: 500;
            font-size: 12px;
        }

        .payment-btn:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .payment-btn.active {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        #payment-details {
            padding: 10px;
            background: var(--gray-light);
            border-radius: 8px;
            font-size: 12px;
            color: var(--dark);
            min-height: 35px;
            display: flex;
            align-items: center;
        }

        .finish-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            animation: fadeIn 0.6s ease 0.4s backwards;
            flex-shrink: 0;
        }

        .finish-box h3 {
            color: var(--dark);
            margin-bottom: 15px;
            font-size: 14px;
        }

        #finish-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(45deg, #00b09b, #96c93d);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        #finish-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 176, 155, 0.4);
        }

        #finish-btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .payment-calculator {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease 0.5s backwards;
            flex-shrink: 0;
        }

        .calculator-title {
            color: var(--dark);
            text-align: center;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .calc-total-display {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
        }

        .calc-input-field {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .calc-input-field:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .calc-button-group {
            display: flex;
            gap: 8px;
            margin: 12px 0;
        }

        .calc-calculate-btn, .calc-reset-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .calc-calculate-btn {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
        }

        .calc-calculate-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .calc-reset-btn {
            background: var(--gray-light);
            color: var(--dark);
        }

        .calc-reset-btn:hover {
            background: var(--gray);
            color: white;
            transform: translateY(-2px);
        }

        .calc-result-display {
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 8px;
            border: 2px solid transparent;
            transition: var(--transition);
        }

        .add-product-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease 0.6s backwards;
            flex-shrink: 0;
        }

        .add-product-box h3 {
            color: var(--dark);
            margin-bottom: 12px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-product-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .add-product-form input {
            width: 100%;
            padding: 10px;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            font-size: 13px;
            transition: var(--transition);
        }

        .add-product-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .add-product-btn {
            padding: 10px;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .add-product-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .center {
            grid-row: 2;
            background-color: rgba(255, 255, 255, 0.98);
            background-image: url('image/icon.png');
            background-repeat: no-repeat;
            background-position: center center;
            background-size: 320px auto;
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease 0.8s backwards;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .center h2 {
            color: var(--dark);
            margin-bottom: 15px;
            text-align: center;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .transaction-table-container {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 150px;
        }

        .transaction-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: transparent;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            font-size: 13px;
        }

        .transaction-table th {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            padding: 10px;
            font-weight: 600;
            text-align: left;
            font-size: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .transaction-table td {
            padding: 10px;
            border-bottom: 1px solid var(--gray-light);
            background: rgba(255, 255, 255, 0.94);
        }

        .transaction-table tr:hover td {
            background: rgba(240, 244, 255, 0.98);
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .quantity-controls button {
            min-width: 30px;
            height: 30px;
            border: 2px solid var(--gray-light);
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 12px;
        }

        .quantity-controls button:hover {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .quantity-controls input {
            width: 60px;
            padding: 6px;
            border: 2px solid var(--gray-light);
            border-radius: 6px;
            text-align: center;
            transition: var(--transition);
            font-size: 13px;
        }

        .quantity-controls input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .right-sidebar {
            grid-row: 2;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: fadeIn 0.6s ease 0.9s backwards;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .right-sidebar h2 {
            color: var(--dark);
            margin-bottom: 15px;
            text-align: center;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .products-container {
            flex: 1;
            overflow-y: auto;
            padding-bottom: 90px;
            padding-right: 5px;
        }

        .product-btn {
            width: 100%;
            padding: 10px;
            background: white;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            cursor: pointer;
            text-align: left;
            margin-bottom: 8px;
            transition: var(--transition);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .product-btn:hover {
            border-color: var(--primary);
            transform: translateX(3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .product-btn-disabled {
            opacity: 0.6;
            cursor: not-allowed !important;
            background: #f5f5f5;
        }

        .product-btn-disabled:hover {
            transform: none !important;
            border-color: var(--gray-light) !important;
            box-shadow: none !important;
        }

        .product-btn-disabled .product-name {
            color: #999;
        }

        .product-name {
            font-weight: 500;
            color: var(--dark);
            flex: 1;
            font-size: 12px;
            word-break: break-word;
        }

        .product-price {
            color: var(--gray);
            font-size: 11px;
            margin-left: 5px;
        }

        .product-stock {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            min-width: 50px;
            text-align: center;
            white-space: nowrap;
        }

        .stock-available { background: rgba(76, 201, 240, 0.2); color: #0096c7; }
        .stock-low { background: rgba(248, 150, 30, 0.2); color: #e76f00; }
        .stock-critical { background: rgba(247, 37, 133, 0.2); color: #c9184a; }
        .stock-out { background: rgba(108, 117, 125, 0.2); color: #6c757d; text-decoration: line-through; }

        .edit-icon {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            border: none;
            border-radius: 6px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 10px;
            font-weight: 500;
            transition: var(--transition);
            margin-left: 8px;
            white-space: nowrap;
        }

        .edit-icon:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }

        .footer {
            grid-column: 1 / -1;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 10px 15px;
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(255, 255, 255, 0.2);
            z-index: 100;
        }

        .footer-btn {
            padding: 10px 15px;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
            text-decoration: none;
            font-size: 12px;
            min-width: 140px;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .footer-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }

        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease;
            max-width: 300px;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            border-radius: 3px;
        }

        .mobile-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1600;
            background: #fff;
            box-shadow: 0 -4px 15px rgba(0,0,0,0.15);
        }
        .mobile-bottom-nav button {
            flex: 1;
            background: none;
            border: none;
            padding: 10px 4px 8px;
            font-size: 11px;
            color: #7f8c8d;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .mobile-bottom-nav button i { font-size: 18px; }
        .mobile-bottom-nav button.active { color: var(--primary); font-weight: bold; }

        .shortcut-hint {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 11px;
            z-index: 1000;
            backdrop-filter: blur(5px);
            display: none;
        }

        .stock-warning {
            background-color: #fff3cd !important;
            border-left: 4px solid #ffc107 !important;
        }

        .stock-warning td {
            background-color: #fff3cd !important;
        }

        .edit-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .edit-modal-container {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .edit-modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .edit-modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }

        .edit-modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .edit-modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .edit-modal-body {
            padding: 25px;
        }

        .edit-form-group {
            margin-bottom: 20px;
        }

        .edit-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .edit-form-group label i {
            color: #667eea;
            width: 20px;
        }

        .edit-form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .edit-form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .current-value {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
            padding: 5px 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .edit-modal-footer {
            display: flex;
            gap: 15px;
            padding: 20px 25px;
            border-top: 1px solid #e0e0e0;
            background: #f8f9fa;
            border-radius: 0 0 16px 16px;
        }

        .edit-modal-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .edit-modal-btn-cancel {
            background: #e74c3c;
            color: white;
        }

        .edit-modal-btn-cancel:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .edit-modal-btn-save {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        .edit-modal-btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        @media (max-width: 768px) {
            html, body { overflow: auto; height: auto; }
            .container { display: block; height: auto; overflow: visible; padding: 10px; padding-bottom: 70px; }
            .top-header { flex-direction: column; align-items: stretch; margin-bottom: 10px; }
            .left-sidebar, .right-sidebar { display: none; position: fixed; top: 0; left: 0; width: 100%; height: calc(100vh - 62px); z-index: 1001; padding: 20px; padding-bottom: 30px; overflow-y: auto; border-radius: 0; background: rgba(255,255,255,0.98); }
            .left-sidebar.active, .right-sidebar.active { display: block; }
            .center { grid-row: auto; margin-bottom: 10px; min-height: 300px; overflow: visible; }
            .footer { position: relative; bottom: auto; flex-direction: column; }
            .footer-btn { width: 100%; min-width: auto; }
            .mobile-bottom-nav { display: flex; }
            .shortcut-hint { display: none; }

            /* Kill the entrance fade-in animations on mobile. They were meant
               as a one-time effect for the very first page load, but they were
               replaying every time a panel was switched (display:none -> flex),
               freezing the screen for up to 0.9s on every single tap. */
            .left-sidebar, .center, .right-sidebar,
            .left-sidebar *, .center *, .right-sidebar * {
                animation: none !important;
                backdrop-filter: none !important;
            }
        }

        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="top-header">
        <div class="user-info">
            <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($current_user, 0, 1))); ?></div>
            <div class="user-details">
                <div class="user-name">
                    <?php echo htmlspecialchars($current_user); ?>
                    <span class="role-badge">
                        <?php 
                        if ($user_role == 'super_admin') echo 'ሱፐር አድሚን';
                        elseif ($user_role == 'admin') echo 'አድሚን';
                        else echo 'ሻጭ';
                        ?>
                    </span>
                </div>
                <div class="user-role-text"><?php echo htmlspecialchars($branch_name); ?> ቅርንጫፍ</div>
            </div>
        </div>
        <div class="header-controls">
            <?php if ($user_role == 'super_admin'): ?>
            <div class="branch-selector">
                <i class="fas fa-store"></i>
                <select id="branchSelector" onchange="changeBranch(this.value)">
                    <option value="">ምረጥ</option>
                    <?php 
                    $branches = getAllBranches($conn);
                    if ($branches && is_array($branches)):
                        foreach($branches as $b): 
                    ?>
                    <option value="<?php echo htmlspecialchars($b['id']); ?>" <?php echo ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($b['place_name']); ?>
                    </option>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="branch-badge">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?>
            </div>
            <!-- ════ Real WiFi Signal Widget ════ -->
            <div id="wifiSignalWidget" title="Click to view offline sales queue"
                 onclick="if(typeof openOfflinePopup==='function') openOfflinePopup();"
                 style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;
                        background:rgba(0,0,0,0.45);backdrop-filter:blur(10px);
                        border-radius:20px;padding:5px 13px;
                        border:1px solid rgba(255,255,255,0.2);
                        transition:background .25s;">
                <!-- SVG WiFi bars: 4 bars that light up based on signal strength -->
                <svg id="wifiSVG" viewBox="0 0 28 20" width="28" height="20" style="overflow:visible">
                    <!-- bar 1 = leftmost/lowest = always visible when any signal -->
                    <rect id="wbar1" x="0"  y="14" width="5" height="6"  rx="1.5" fill="#6b7280"/>
                    <!-- bar 2 -->
                    <rect id="wbar2" x="7"  y="9"  width="5" height="11" rx="1.5" fill="#6b7280"/>
                    <!-- bar 3 -->
                    <rect id="wbar3" x="14" y="4"  width="5" height="16" rx="1.5" fill="#6b7280"/>
                    <!-- bar 4 = rightmost/tallest = only full signal -->
                    <rect id="wbar4" x="21" y="0"  width="5" height="20" rx="1.5" fill="#6b7280"/>
                    <!-- Offline X mark (hidden by default) -->
                    <line id="wifiXmark" x1="4" y1="4" x2="24" y2="18" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" style="display:none"/>
                    <line id="wifiXmark2" x1="24" y1="4" x2="4" y2="18" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" style="display:none"/>
                </svg>
                <span id="wifiLabel" style="color:#9ca3af;font-size:12px;font-weight:600;min-width:54px;">Checking…</span>
                <!-- hidden legacy badge kept for backward compat with offline-ux.js -->
                <span id="syncStatusBadge" style="display:none;">🟢 Synced</span>
                <button id="wifiSyncBtn" onclick="event.stopPropagation();if(window.syncEngine)window.syncEngine.triggerSync();"
                        title="Force sync now"
                        style="background:transparent;border:none;color:rgba(255,255,255,0.5);
                               cursor:pointer;padding:0;font-size:13px;line-height:1;">
                    <i class="fas fa-sync-alt" id="wifiSyncIcon"></i>
                </button>
            </div>
            <div class="ethiopian-time" id="ethiopianTime">
                <i class="fas fa-calendar-alt"></i>
                <span id="ethDate"><?php echo htmlspecialchars($ethiopian_datetime['date']); ?></span>
                <span id="ethTime"><?php echo htmlspecialchars($ethiopian_datetime['time']); ?></span>
            </div>
            <button id="pwaInstallBtn" onclick="pwaInstall()" title="Install as App"
                    style="display:none; background:linear-gradient(135deg,#4361ee,#7209b7);
                           color:white; border:none; padding:8px 14px; border-radius:8px;
                           cursor:pointer; font-weight:600; font-size:13px;
                           align-items:center; gap:7px; transition:all .25s;">
                <img src="image/icon-192.png" width="18" height="18"
                     style="border-radius:4px;vertical-align:middle;"
                     onerror="this.style.display='none'"
                     alt=""> Install App
            </button>
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>
    </div>

    <div class="mobile-bottom-nav" id="mobileBottomNav">
        <button type="button" data-view="left" onclick="toggleMobileView('left')">
            <i class="fas fa-receipt"></i> ክፍያ
        </button>
        <button type="button" data-view="center" class="active" onclick="toggleMobileView('center')">
            <i class="fas fa-shopping-cart"></i> ዕቃዎች
        </button>
        <button type="button" data-view="right" onclick="toggleMobileView('right')">
            <i class="fas fa-plus-circle"></i> ምርት ጨምር
        </button>
    </div>
    <div class="shortcut-hint" id="shortcutHint"><i class="fas fa-keyboard"></i> Shortcuts: Ctrl+F = Finish, Ctrl+P = Calculator</div>

    <div class="container">
        <div class="left-sidebar" id="leftSidebar">
            <div class="total-box">
                <h2><i class="fas fa-receipt"></i> ጠቅላላ ድምር</h2>
                <div id="total-amount">0.00 ETB</div>
            </div>
            
            <div class="payment-methods">
                <h3><i class="fas fa-credit-card"></i> የመክፈያ መንገዶች</h3>
                <div class="payment-options">
                    <button class="payment-btn active" onclick="selectPaymentMethod('cash', event)"><i class="fas fa-money-bill-wave"></i> ካሽ</button>
                    <button class="payment-btn" onclick="selectPaymentMethod('telebirr', event)"><i class="fas fa-mobile-alt"></i> ቴሌብር</button>
                    <button class="payment-btn" onclick="selectPaymentMethod('cbe', event)"><i class="fas fa-university"></i> CBE</button>
                    <button class="payment-btn" onclick="selectPaymentMethod('abyssinia', event)"><i class="fas fa-landmark"></i> አቢሲንያ</button>
                </div>
                <div id="payment-details"></div>
            </div>

            <div class="finish-box">
                <h3><i class="fas fa-check-circle"></i> መዝግበው ከጨረሱ ይህን ይጫኑ</h3>
                <button id="finish-btn" onclick="finishTransaction()"><i class="fas fa-check"></i> ሽያጩ ተጠናቋል</button>
            </div>
            
            <div class="payment-calculator">
                <div class="calculator-title"><i class="fas fa-calculator"></i> ቀሪ ለማወቅ</div>
                <div class="calc-total-display">Total: <span id="calc-totalAmount">0.00 ETB</span></div>
                <input type="number" id="calc-amountPaid" class="calc-input-field" placeholder="ከደንበኛ የተቀበሉትን ያስገቡ" min="0" step="0.01">
                <div class="calc-button-group">
                    <button class="calc-calculate-btn" onclick="calcCalculateChange()"><i class="fas fa-calculator"></i> Calculate</button>
                    <button class="calc-reset-btn" onclick="calcResetCalculator()"><i class="fas fa-redo"></i> Reset</button>
                </div>
                <div id="calc-resultDisplay" class="calc-result-display">ቁጥሩን አስገባ</div>
            </div>
            
            <div class="add-product-box">
                <h3><i class="fas fa-plus-circle"></i> አዲስ ምርት ለመመዝገብ</h3>
                <form method="POST" action="" class="add-product-form" id="addProductForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <input type="text" name="new_product_name" placeholder="የእቃው ስም አስገባ" required>
                    <input type="number" name="new_product_price" placeholder="የእቃው ዋጋ አስገባ" step="0.01" min="0" required>
                    <button type="submit" name="add_new_product" class="add-product-btn"><i class="fas fa-plus"></i> ይመዝገብ</button>
                </form>
            </div>
        </div>
        
        <div class="center" id="centerArea">
            <h2><i class="fas fa-shopping-cart"></i> ለመሸጥ የተመረጡ እቃዎች ማሳያ</h2>
            <div class="transaction-table-container">
                <table class="transaction-table" id="transaction-table">
                    <thead>
                        <tr>
                            <th>የእቃው ስም</th>
                            <th>ብዛት</th>
                            <th>የአንዱ ዋጋ</th>
                            <th>እያንዳንዱ ዋጋ</th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        <tr>
                            <td colspan="5" style="text-align:center;padding:120px 20px 40px;color:#475569;background:transparent;border:none;">
                                <div style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;text-shadow:0 1px 4px rgba(255,255,255,0.9);">ምንም እቃ አልተመረጠም</div>
                                <small style="color:#475569;font-size:14px;font-weight:600;text-shadow:0 1px 4px rgba(255,255,255,0.9);">እቃ ለመመዝገብ ከቀኝ በኩል ምርት ይምረጡ</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="right-sidebar" id="rightSidebar">
            <h2><i class="fas fa-store"></i> በቅርንጫፉ ያሉ ምርቶች (<?php echo count($products_data); ?>)</h2>
            <div class="products-container" id="products-container">
                <?php 
                if (count($products_data) > 0):
                    foreach($products_data as $product): 
                        $stock_class = 'stock-available';
                        $stock_text = number_format($product['current_stock'], 1) . ' ' . ($product['unit'] ?? 'pcs');
                        $is_out_of_stock = false;
                        
                        if ($product['current_stock'] <= 0) {
                            $stock_class = 'stock-out';
                            $stock_text = 'ተሽጦ አልቋል';
                            $is_out_of_stock = true;
                        } elseif ($product['current_stock'] <= 5) {
                            $stock_class = 'stock-critical';
                        } elseif ($product['current_stock'] <= 10) {
                            $stock_class = 'stock-low';
                        }
                        
                        $disabled_class = $is_out_of_stock ? 'product-btn-disabled' : '';
                        $onclick_action = $is_out_of_stock ? '' : "addProductToCart({$product['id']}, '" . htmlspecialchars(addslashes($product['name']), ENT_QUOTES) . "', {$product['unit_price']})";
                        $cursor_style = $is_out_of_stock ? 'cursor: not-allowed; opacity: 0.6;' : '';
                ?>
                    <div class="product-btn <?php echo $disabled_class; ?>" 
                         onclick="<?php echo $onclick_action; ?>" 
                         style="<?php echo $cursor_style; ?>"
                         title="<?php echo $is_out_of_stock ? 'ክምችት የለም - መሸጥ አይቻልም' : 'ለመሸጥ ይጫኑ'; ?>">
                        <div class="product-name">
                            <?php echo htmlspecialchars($product['name']); ?>
                            <span class="product-price">- <?php echo number_format($product['unit_price'], 2); ?> ETB</span>
                        </div>
                        <div style="display: flex; align-items: center;">
                            <span class="product-stock <?php echo $stock_class; ?>"><?php echo htmlspecialchars($stock_text); ?></span>
                            <button class="edit-icon" onclick="event.stopPropagation(); openEditModal(<?php echo intval($product['id']); ?>, '<?php echo htmlspecialchars(addslashes($product['name']), ENT_QUOTES); ?>', <?php echo floatval($product['unit_price']); ?>)">ይሻሻል</button>
                        </div>
                    </div>
                <?php 
                    endforeach;
                else:
                ?>
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <div>ምንም ምርት አልተገኘም</div>
                        <small>እባክዎ አዲስ ምርት ይመዝግቡ</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <button class="footer-btn" onclick="window.location.href='history.php'"><i class="fas fa-history"></i> የተሸጠውን ለማየት</button>
            <button class="footer-btn" onclick="window.location.href='edit_history.php'"><i class="fas fa-edit"></i> የተሻሻለውን እቃ ለማየት</button>
            <button class="footer-btn" onclick="window.location.href='seller_receive_stock.php'"><i class="fas fa-truck-loading"></i> የገባ እቃ ለመጻፍ</button>
     
            <!-- <button class="footer-btn" onclick="window.location.href='boss_receive.php'"><i class="fas fa-user-tie"></i> ለባለቤት ገንዘብ</button> -->
            <a href="return_page.php" class="footer-btn"><i class="fas fa-undo-alt"></i> እቃ ለመመለስ</a>
        </div>
    </div>

    <script>
    let transactionItems = [];
    let total = 0;
    let selectedPaymentMethod = 'cash';
    let currentMobileView = 'center';
    let productsData = <?php echo json_encode($products_data); ?>;
    // mysqli returns decimal database columns as text, so without this,
    // current_stock/unit_price could arrive as JS strings instead of
    // numbers. This guarantees they are always real numbers everywhere
    // else in this file.
    productsData = productsData.map(p => ({
        ...p,
        id: parseInt(p.id, 10),
        current_stock: parseFloat(p.current_stock) || 0,
        unit_price: parseFloat(p.unit_price) || 0
    }));
    let userId = <?php echo intval($user_id); ?>;
    let branchId = <?php echo intval($branch_id); ?>;
    let userRole = '<?php echo addslashes($user_role); ?>';
    let csrfToken = '<?php echo getCsrfToken(); ?>';
    let isLoading = false;

    function changeBranch(branchId) {
        if (branchId) {
            window.location.href = 'seller_pos.php?branch_id=' + encodeURIComponent(branchId);
        }
    }

    function updateEthiopianTime() {
        try {
            const now = new Date();
            const ethiopianTime = new Date(now.getTime() + (3 * 60 * 60 * 1000));
            
            const gregYear = now.getFullYear();
            const gregMonth = now.getMonth() + 1;
            const gregDay = now.getDate();
            
            let ethYear = gregYear - 8;
            if (gregMonth >= 9 || (gregMonth == 9 && gregDay >= 11)) ethYear++;
            
            const sept11 = new Date(gregYear, 8, 11);
            if (gregMonth < 9 || (gregMonth == 9 && gregDay < 11)) sept11.setFullYear(gregYear - 1);
            
            const diffDays = Math.floor((now - sept11) / (1000 * 60 * 60 * 24));
            
            let ethMonth, ethDay;
            if (diffDays < 30) { ethMonth = 1; ethDay = diffDays + 1; }
            else if (diffDays < 60) { ethMonth = 2; ethDay = diffDays - 30 + 1; }
            else if (diffDays < 90) { ethMonth = 3; ethDay = diffDays - 60 + 1; }
            else if (diffDays < 120) { ethMonth = 4; ethDay = diffDays - 90 + 1; }
            else if (diffDays < 150) { ethMonth = 5; ethDay = diffDays - 120 + 1; }
            else if (diffDays < 180) { ethMonth = 6; ethDay = diffDays - 150 + 1; }
            else if (diffDays < 210) { ethMonth = 7; ethDay = diffDays - 180 + 1; }
            else if (diffDays < 240) { ethMonth = 8; ethDay = diffDays - 210 + 1; }
            else if (diffDays < 270) { ethMonth = 9; ethDay = diffDays - 240 + 1; }
            else if (diffDays < 300) { ethMonth = 10; ethDay = diffDays - 270 + 1; }
            else if (diffDays < 330) { ethMonth = 11; ethDay = diffDays - 300 + 1; }
            else if (diffDays < 360) { ethMonth = 12; ethDay = diffDays - 330 + 1; }
            else { ethMonth = 13; ethDay = diffDays - 360 + 1; if (ethDay > 6) ethDay = 6; }
            
            const ethDate = ethYear + '-' + ethMonth.toString().padStart(2, '0') + '-' + ethDay.toString().padStart(2, '0');
            
            let hours = ethiopianTime.getUTCHours();
            const minutes = String(ethiopianTime.getUTCMinutes()).padStart(2, '0');
            const seconds = String(ethiopianTime.getUTCSeconds()).padStart(2, '0');
            
            const period = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            
            const ethTimeElement = document.getElementById('ethTime');
            const ethDateElement = document.getElementById('ethDate');
            if (ethTimeElement) ethTimeElement.textContent = `${hours}:${minutes}:${seconds} ${period}`;
            if (ethDateElement) ethDateElement.textContent = ethDate;
        } catch(e) {
            console.error('Error updating Ethiopian time:', e);
        }
    }

    function toggleMobileView(view) {
        const left = document.getElementById('leftSidebar');
        const center = document.getElementById('centerArea');
        const right = document.getElementById('rightSidebar');
        
        if (left) left.classList.remove('active');
        if (center) center.style.display = 'none';
        if (right) right.classList.remove('active');
        
        if (view === 'left' && left) { 
            left.classList.add('active'); 
            currentMobileView = 'left'; 
        } else if (view === 'center' && center) { 
            center.style.display = 'flex'; 
            currentMobileView = 'center'; 
        } else if (view === 'right' && right) { 
            right.classList.add('active'); 
            currentMobileView = 'right'; 
        }

        document.querySelectorAll('#mobileBottomNav button').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-view') === currentMobileView);
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'f') { 
            e.preventDefault(); 
            finishTransaction(); 
        }
        if (e.ctrlKey && e.key === 'p') { 
            e.preventDefault(); 
            const calcInput = document.getElementById('calc-amountPaid');
            if (calcInput) calcInput.focus(); 
        }
        if (e.key === 'Escape' && confirm('Clear all items?')) { 
            transactionItems = []; 
            total = 0; 
            updateTable(); 
            calculateTotal(); 
            showToast('Cart cleared!', 'info'); 
        }
        if (window.innerWidth <= 768) {
            if (e.key === '1') { e.preventDefault(); toggleMobileView('left'); }
            if (e.key === '2') { e.preventDefault(); toggleMobileView('center'); }
            if (e.key === '3') { e.preventDefault(); toggleMobileView('right'); }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        updateEthiopianTime(); 
        setInterval(updateEthiopianTime, 1000);
        updateCalculatorTotal(); 
        updatePaymentDetails(); 
        updateFinishButtonStatus();
        
        const calcAmountPaid = document.getElementById('calc-amountPaid');
        if (calcAmountPaid) {
            calcAmountPaid.addEventListener('input', function() { 
                if (this.value && parseFloat(this.value) > 0) calcCalculateChange(); 
            });
        }
        
        if (window.innerWidth <= 768) { 
            toggleMobileView('center'); 
            const hint = document.getElementById('shortcutHint');
            if (hint) hint.style.display = 'none';
        } else { 
            const left = document.getElementById('leftSidebar');
            const center = document.getElementById('centerArea');
            const right = document.getElementById('rightSidebar');
            if (left) left.style.display = 'flex';
            if (center) center.style.display = 'flex';
            if (right) right.style.display = 'flex';
            const nav = document.getElementById('mobileBottomNav');
            if (nav) nav.style.display = 'none';
        }
        
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const left = document.getElementById('leftSidebar');
                const center = document.getElementById('centerArea');
                const right = document.getElementById('rightSidebar');
                if (left) left.style.display = 'flex';
                if (center) center.style.display = 'flex';
                if (right) right.style.display = 'flex';
                const nav = document.getElementById('mobileBottomNav');
                if (nav) nav.style.display = 'none';
            } else { 
                toggleMobileView('center'); 
                const nav = document.getElementById('mobileBottomNav');
                if (nav) nav.style.display = 'flex';
            }
        });
        
        updateTable();
    });

    function updateCalculatorTotal() { 
        const calcTotal = document.getElementById('calc-totalAmount');
        if (calcTotal) calcTotal.textContent = total.toFixed(2) + ' ETB'; 
    }
    
    function updateFinishButtonStatus() {
        const btn = document.getElementById('finish-btn');
        if (btn) {
            btn.disabled = transactionItems.length === 0;
            btn.innerHTML = transactionItems.length === 0 ? '<i class="fas fa-shopping-cart"></i> መጀመሪያ እቃ መዝግቡ' : '<i class="fas fa-check"></i> ሽያጩ ተጠናቋል';
        }
    }

    function checkStockAvailability(itemName, qtyToAdd) {
        const product = productsData.find(p => p.name === itemName || p.id === itemName);
        if (!product) return { allow: true };
        
        const existingItem = transactionItems.find(item => item.name === itemName || item.id === product.id);
        const currentCartQuantity = existingItem ? existingItem.quantity : 0;
        const totalRequestedQuantity = Math.round((currentCartQuantity + qtyToAdd) * 100) / 100;
        
        // A tiny tolerance so decimal rounding (a well-known JavaScript quirk:
        // e.g. 0.1 + 0.2 is not exactly 0.3) never falsely treats a sale that
        // exactly matches available stock as "over stock".
        const STOCK_EPSILON = 0.005;
        if (product.current_stock < totalRequestedQuantity - STOCK_EPSILON) {
            let availableStock = product.current_stock;
            let msg = `በቂ ክምችት የለም!\n\n`;
            msg += `ያለው ክምችት: ${availableStock} ${product.unit || 'pcs'}\n`;
            msg += `በቅርጫት ውስጥ ያለው: ${currentCartQuantity} ${product.unit || 'pcs'}\n`;
            msg += `ለመጨመር የፈለጉት: ${qtyToAdd} ${product.unit || 'pcs'}\n`;
            msg += `አጠቃላይ የሚፈለገው: ${totalRequestedQuantity} ${product.unit || 'pcs'}\n\n`;
            msg += `ከክምችት በላይ መሸጥ ይፈልጋሉ?`;
            
            return { 
                allow: confirm(msg), 
                message: msg,
                availableStock: availableStock,
                currentCart: currentCartQuantity,
                requestedAdd: qtyToAdd,
                totalRequested: totalRequestedQuantity
            };
        }
        return { allow: true };
    }

    function addProductToCart(id, name, price) {
        const numId = parseInt(id, 10);
        const product = productsData.find(p => p.id === numId || p.name === name);
        if (product && product.current_stock <= 0) {
            showToast('ክምችት የለም: ' + name, 'error');
            return;
        }
        
        const existingItem = transactionItems.find(item => item.id === numId || item.name === name);
        const alreadyInCart = existingItem ? existingItem.quantity : 0;
        const availableStock = product ? product.current_stock : 1;
        const remainingStock = availableStock - alreadyInCart;
        
        // If remaining stock is a fraction (e.g. 0.5kg), add exactly what's available
        // instead of forcing 1 and popping up overstock permission dialogs
        let qty = 1;
        if (remainingStock > 0.005 && remainingStock < 1) {
            qty = Math.round(remainingStock * 100) / 100;
        } else if (availableStock > 0.005 && availableStock < 1 && alreadyInCart === 0) {
            qty = Math.round(availableStock * 100) / 100;
        }
        
        if (qty <= 0 || remainingStock <= 0.005) {
            showToast('ክምችት የለም: ' + name, 'error');
            return;
        }
        
        const stockInfo = checkStockAvailability(name, qty);
        
        if (stockInfo.allow) {
            let existing = transactionItems.find(item => item.id === numId || item.name === name);
            if (existing) {
                existing.quantity = Math.round((existing.quantity + qty) * 100) / 100;
                existing.subtotal = existing.quantity * price;
            } else {
                transactionItems.push({ 
                    id: numId || id, 
                    name, 
                    price, 
                    quantity: qty, 
                    subtotal: qty * price 
                });
            }
            updateTable(); 
            calculateTotal(); 
            showToast('ተጨምሯል: ' + name + ' (' + qty + ')', 'success');
            
            if (window.innerWidth <= 768 && currentMobileView !== 'center') {
                toggleMobileView('center');
            }
        }
    }

    function changeQuantity(i, changeAmount) {
        const item = transactionItems[i];
        if (!item) return;

        let newQuantity = Math.round((item.quantity + changeAmount) * 100) / 100;
        
        if (newQuantity <= 0) {
            removeItem(i);
            return;
        }
        
        if (changeAmount > 0) {
            const stockInfo = checkStockAvailability(item.name, changeAmount);
            if (!stockInfo.allow) {
                return;
            }
        }
        
        transactionItems[i].quantity = newQuantity;
        transactionItems[i].subtotal = newQuantity * transactionItems[i].price;
        updateTable();
        calculateTotal();
    }

    function updateQuantityDirectly(i, val) {
        let q = parseFloat(val);
        if (isNaN(q) || q < 0) return;
        
        if (q <= 0) {
            removeItem(i);
            return;
        }
        
        const item = transactionItems[i];
        const currentQty = item.quantity;
        const changeAmount = q - currentQty;
        
        if (changeAmount > 0) {
            const stockInfo = checkStockAvailability(item.name, changeAmount);
            if (!stockInfo.allow) {
                const inputs = document.querySelectorAll('.quantity-controls input');
                if (inputs[i]) inputs[i].value = currentQty.toFixed(2);
                return;
            }
        }
        
        transactionItems[i].quantity = q;
        transactionItems[i].subtotal = q * transactionItems[i].price;
        updateTable();
        calculateTotal();
    }

    function updateTable() {
        let tbody = document.getElementById('items-body');
        if (!tbody) return;
        
        if (transactionItems.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:120px 20px 40px;color:#475569;background:transparent;border:none;">
                <div style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:6px;text-shadow:0 1px 4px rgba(255,255,255,0.9);">ምንም እቃ አልተመረጠም</div>
                <small style="color:#475569;font-size:14px;font-weight:600;text-shadow:0 1px 4px rgba(255,255,255,0.9);">እቃ ለመመዝገብ ከቀኝ በኩል ምርት ይምረጡ</small>
            </td></tr>`;
        } else {
            tbody.innerHTML = '';
            transactionItems.forEach((item, i) => {
                const product = productsData.find(p => p.name === item.name);
                const isOverStock = product && item.quantity > product.current_stock + 0.005;
                const rowClass = isOverStock ? 'stock-warning' : '';
                
                let row = `<tr class="${rowClass}">`;
                row += `<td><strong>${escapeHtml(item.name)}</strong>`;
                if (isOverStock) {
                    row += `<br><small style="color:#856404;">ክምችት: ${product.current_stock} ${product.unit || 'pcs'}</small>`;
                }
                row += `</td>`;
                row += `<td><div class="quantity-controls">`;
                row += `<button onclick="changeQuantity(${i}, -1)">-1</button>`;
                row += `<button onclick="changeQuantity(${i}, -0.1)">-0.1</button>`;
                row += `<input type="number" value="${item.quantity.toFixed(2)}" step="0.01" min="0.01" onchange="updateQuantityDirectly(${i}, this.value)">`;
                row += `<button onclick="changeQuantity(${i}, 0.1)">+0.1</button>`;
                row += `<button onclick="changeQuantity(${i}, 1)">+1</button>`;
                row += `</div></td>`;
                row += `<td>${item.price.toFixed(2)} ETB</td>`;
                row += `<td><strong>${item.subtotal.toFixed(2)} ETB</strong></td>`;
                row += `<td><button onclick="removeItem(${i})" style="background:linear-gradient(45deg,#f72585,#ff6b6b);color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;"><i class="fas fa-trash"></i> ይሰረዝ</button>`;
                row += `</tr>`;
                
                tbody.innerHTML += row;
            });
        }
        updateFinishButtonStatus();
    }

    function removeItem(i) { 
        transactionItems.splice(i, 1); 
        updateTable(); 
        calculateTotal(); 
        showToast('Item removed', 'info'); 
    }
    
    function calculateTotal() { 
        total = transactionItems.reduce((s, i) => s + i.subtotal, 0); 
        const totalAmount = document.getElementById('total-amount');
        if (totalAmount) totalAmount.textContent = total.toFixed(2) + ' ETB'; 
        updateCalculatorTotal(); 
        let p = document.getElementById('calc-amountPaid'); 
        if (p && p.value && parseFloat(p.value) > 0) calcCalculateChange(); 
    }

    function selectPaymentMethod(m, e) {
        selectedPaymentMethod = m;
        document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('active'));
        if (e && e.target) e.target.classList.add('active');
        updatePaymentDetails();
    }
    
    function updatePaymentDetails() {
        let names = { cash: 'ካሽ', telebirr: 'ቴሌብር', cbe: 'ሲቢኢ', abyssinia: 'አቢሲንያ' };
        const paymentDetails = document.getElementById('payment-details');
        if (paymentDetails) {
            paymentDetails.innerHTML = `<strong>Selected:</strong> ${names[selectedPaymentMethod] || selectedPaymentMethod}`;
        }
    }

    function finishTransaction() {
        if (isLoading) return;
        if (transactionItems.length === 0) { 
            alert('Add items first!'); 
            return; 
        }
        
        for (let item of transactionItems) {
            const product = productsData.find(p => p.name === item.name);
            if (product && item.quantity > product.current_stock + 0.005) {
                if (!confirm(`Warning: ${item.name} - Selling ${item.quantity} but only ${product.current_stock} in stock. Continue anyway?`)) {
                    return;
                }
            }
        }
        
        isLoading = true;
        const btn = document.getElementById('finish-btn'); 
        if (btn) {
            btn.disabled = true; 
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        }
        const items = [...transactionItems];
        
        saveTransactionToDatabase(items).then(() => {
            transactionItems = []; 
            total = 0; 
            updateTable(); 
            calculateTotal(); 
            calcResetCalculator(); 
            refreshProductsList();
            if (btn) {
                btn.disabled = false; 
                btn.innerHTML = '<i class="fas fa-check"></i> ሽያጩ ተጠናቋል'; 
            }
            showToast('Transaction completed!', 'success');
            isLoading = false;
        }).catch(err => {
            console.error(err);
            isLoading = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> ሽያጩ ተጠናቋል';
            }
            const errMsg = err.message || String(err);
            if (errMsg.startsWith('SERVER_BUSY:')) {
                // 502/504: server was busy - warn user that transaction MAY have saved
                showToast('⚠️ Server busy (502). ለ 10 ሰከንድ ጠብቁ ከዛ history.php ላይ ሽያጩ መዝገብ ይፈትሹ. ካልተቀመጠ እንደገና ይሞክሩ.', 'error');
            } else {
                showToast('Transaction failed! ' + errMsg, 'error');
            }
        });
    }

    async function saveTransactionToDatabase(items) {
        let paid = parseFloat(document.getElementById('calc-amountPaid').value) || 0;
        // In this business customers always pay physically — if seller didn't enter a paid amount
        // treat it as exact payment (paid = total, change = 0)
        if (paid <= 0) paid = total;
        let change = Math.max(0, paid - total);
        let deviceUUID = (window.deviceManager ? window.deviceManager.getDeviceUUID() : 'browser-pos');

        // Evaluate offline rule limits before saving
        if (window.offlineRulesEngine) {
            const ruleCtx = {
                branch_id: branchId,
                seller_id: userId,
                device_uuid: deviceUUID,
                current_date: new Date().toISOString()
            };
            for (let it of items) {
                const check = window.offlineRulesEngine.evaluateRule(ruleCtx, {
                    product_id: it.id,
                    requested_qty: it.quantity
                });
                if (!check.allowed) {
                    const productName = it.name || it.product_name || ('Product #' + it.id);
                    const rule = check.effective_rule;
                    const isQtyLimit = rule && parseFloat(rule.max_offline_qty) > 0 && parseInt(rule.allow_offline) === 1;

                    if (isQtyLimit) {
                        // Quantity limit exceeded
                        throw new Error(
                            `"${productName}": ኦፍላይን መሸጥ የሚችሉት ` +
                            `${parseFloat(rule.max_offline_qty)} ብቻ ነው። ` +
                            `(Offline limit for "${productName}" is ${parseFloat(rule.max_offline_qty)} units. ` +
                            `You requested ${it.quantity}. Connect to internet to sell more.)`
                        );
                    } else {
                        // Fully blocked offline
                        throw new Error(
                            `"${productName}" ኦፍላይን ሊሸጥ አይችልም — ` +
                            `ይህ ምርት ያለ ኢንተርኔት ለመሸጥ ተከልክሏል።\n` +
                            `This product is not allowed to be sold offline.\n` +
                            `ኢንተርኔቱን ካገናኙ በኋላ ይሸጡ። (Please connect to the internet first.)`
                        );
                    }
                }
            }
        }


        const salePayload = {
            seller_id: userId,
            seller_name: '<?php echo addslashes($current_user); ?>',
            branch_id: branchId,
            device_uuid: deviceUUID,
            total_amount: total,
            paid_amount: paid,
            change_amount: change,
            payment_method: selectedPaymentMethod,
            items: items
        };

        // 1. Write transaction locally into IndexedDB atomically
        if (window.aleltuDB) {
            await window.aleltuDB.performLocalSale(salePayload);
        }

        // 2. Trigger background sync attempt immediately
        if (window.syncEngine && navigator.onLine) {
            window.syncEngine.triggerSync().catch(err => console.warn('Background sync deferred:', err));
        }

        return true;
    }

    function showToast(m, t) {
        let existing = document.querySelector('.toast-notification'); 
        if (existing) existing.remove();
        let colors = { 
            success: 'linear-gradient(45deg,#00b09b,#96c93d)', 
            error: 'linear-gradient(45deg,#f72585,#ff6b6b)', 
            info: 'linear-gradient(45deg,#4361ee,#7209b7)' 
        };
        let icons = { 
            success: 'fa-check-circle', 
            error: 'fa-exclamation-circle', 
            info: 'fa-info-circle' 
        };
        let toast = document.createElement('div'); 
        toast.className = 'toast-notification';
        toast.innerHTML = `<div style="background:${colors[t]};color:white;padding:15px 25px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);display:flex;align-items:center;gap:10px;"><i class="fas ${icons[t]}"></i><span>${m}</span></div>`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    function refreshProductsList(retryCount) {
        retryCount = retryCount || 0;
        let xhr = new XMLHttpRequest();
        xhr.open("GET", "refresh_products.php?branch_id=" + branchId + "&t=" + Date.now(), true);
        xhr.timeout = 15000;
        xhr.onreadystatechange = function() { 
            if (xhr.readyState === 4) {
                if (xhr.status === 200) { 
                    try { 
                        let p = JSON.parse(xhr.responseText); 
                        if (p && !p.error) {
                            updateProductsDisplay(p); 
                            productsData = p;
                        }
                    } catch(e){
                        console.error('Error parsing product refresh response:', e);
                    }
                } else if (retryCount < 2) {
                    // Retry up to 2 times on server error (502, etc)
                    setTimeout(function() { refreshProductsList(retryCount + 1); }, 3000);
                }
            }
        };
        xhr.onerror = function() {
            if (retryCount < 2) {
                setTimeout(function() { refreshProductsList(retryCount + 1); }, 3000);
            }
        };
        xhr.send();
    }

    function updateProductsDisplay(products) {
        let container = document.querySelector('.products-container');
        if (!container) return;
        
        container.innerHTML = '';
        if (!products || products.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 15px;"></i><div>ምንም ምርት አልተገኘም</div><small>እባክዎ አዲስ ምርት ይመዝግቡ</small></div>';
            return;
        }
        
        products.forEach(p => {
            let sc = p.current_stock <= 0 ? 'stock-out' : (p.current_stock <= 2 ? 'stock-critical' : (p.current_stock <= 5 ? 'stock-low' : 'stock-available'));
            let st = p.current_stock <= 0 ? 'ተሽጦ አልቋል' : p.current_stock.toFixed(1) + ' ' + (p.unit || 'pcs');
            let isOutOfStock = p.current_stock <= 0;
            
            let btn = document.createElement('div'); 
            btn.className = 'product-btn' + (isOutOfStock ? ' product-btn-disabled' : '');
            
            if (isOutOfStock) {
                btn.style.cursor = 'not-allowed';
                btn.style.opacity = '0.6';
                btn.title = 'ክምችት የለም - መሸጥ አይቻልም';
            } else {
                btn.onclick = function() { addProductToCart(p.id, p.name, p.unit_price); };
                btn.title = 'ለመሸጥ ይጫኑ';
            }
            
            btn.innerHTML = `<div class="product-name">${escapeHtml(p.name)} <span class="product-price">- ${p.unit_price.toFixed(2)} ETB</span></div><div style="display:flex;align-items:center;"><span class="product-stock ${sc}">${escapeHtml(st)}</span><button class="edit-icon" onclick="event.stopPropagation(); openEditModal(${p.id}, '${escapeHtml(p.name)}', ${p.unit_price})">ይሻሻል</button></div>`;
            container.appendChild(btn);
        });
        
        const rightHeader = document.querySelector('.right-sidebar h2');
        if (rightHeader) {
            rightHeader.innerHTML = `<i class="fas fa-store"></i> በቅርንጫፉ ያሉ ምርቶች (${products.length})`;
        }
    }

    function calcCalculateChange() {
        let paid = parseFloat(document.getElementById('calc-amountPaid').value);
        let res = document.getElementById('calc-resultDisplay');
        if (!res) return;
        
        if (isNaN(paid) || paid < 0) { 
            res.innerHTML = "Enter valid amount"; 
            res.style.background = '#f8d7da'; 
            res.style.color = '#721c24'; 
            return; 
        }
        let diff = paid - total;
        if (diff < 0) { 
            res.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ቀሪ ${Math.abs(diff).toFixed(2)} ያስጨምራል`; 
            res.style.background = '#fff3cd'; 
            res.style.color = '#856404'; 
        }
        else if (Math.abs(diff) < 0.01) { 
            res.innerHTML = `<i class="fas fa-check-circle"></i> መልስ አያስፈልገውም`; 
            res.style.background = '#d4edda'; 
            res.style.color = '#155724'; 
        }
        else { 
            res.innerHTML = `<i class="fas fa-coins"></i> መልሱ: ${diff.toFixed(2)} ነው`; 
            res.style.background = '#d1ecf1'; 
            res.style.color = '#0c5460'; 
        }
    }
    
    function calcResetCalculator() { 
        const calcAmount = document.getElementById('calc-amountPaid');
        const calcResult = document.getElementById('calc-resultDisplay');
        if (calcAmount) calcAmount.value = ''; 
        if (calcResult) {
            calcResult.innerHTML = "Enter amount"; 
            calcResult.style.background = ''; 
            calcResult.style.color = ''; 
        }
    }
    
    function logout() { 
        if (confirm('Logout?')) window.location.href = 'logout.php'; 
    }
    
    function escapeHtml(t) { 
        if (!t) return '';
        let d = document.createElement('div'); 
        d.textContent = t; 
        return d.innerHTML; 
    }
    
    function openEditModal(id, currentName, currentPrice) {
        const existingModal = document.getElementById('editModalOverlay');
        if (existingModal) existingModal.remove();
        
        const modalHtml = `
            <div id="editModalOverlay" class="edit-modal-overlay">
                <div class="edit-modal-container">
                    <div class="edit-modal-header">
                        <h3><i class="fas fa-edit"></i> የምርት ማሻሻያ</h3>
                        <button class="edit-modal-close" onclick="closeEditModal()">×</button>
                    </div>
                    <div class="edit-modal-body">
                        <div class="edit-form-group">
                            <label><i class="fas fa-tag"></i> የምርት ስም</label>
                            <input type="text" id="editProductName" value="${escapeHtml(currentName)}" placeholder="አዲስ ስም ያስገቡ">
                            <div class="current-value"><i class="fas fa-info-circle"></i> የአሁኑ ስም: ${escapeHtml(currentName)}</div>
                        </div>
                        <div class="edit-form-group">
                            <label><i class="fas fa-money-bill-wave"></i> የምርት ዋጋ (ETB)</label>
                            <input type="number" id="editProductPrice" value="${currentPrice}" step="0.01" min="0" placeholder="አዲስ ዋጋ ያስገቡ">
                            <div class="current-value"><i class="fas fa-info-circle"></i> የአሁኑ ዋጋ: ${currentPrice} ETB</div>
                        </div>
                    </div>
                    <div class="edit-modal-footer">
                        <button class="edit-modal-btn edit-modal-btn-cancel" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> ሰርዝ
                        </button>
                        <button class="edit-modal-btn edit-modal-btn-save" onclick="confirmEdit(${id}, '${escapeHtml(currentName)}', ${currentPrice})">
                            <i class="fas fa-save"></i> አስቀምጥ
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        document.getElementById('editProductName').focus();
    }
    
    function closeEditModal() {
        const modal = document.getElementById('editModalOverlay');
        if (modal) modal.remove();
    }
    
    function confirmEdit(id, oldName, oldPrice) {
        const newName = document.getElementById('editProductName').value.trim();
        const newPrice = parseFloat(document.getElementById('editProductPrice').value);
        
        if (!newName) {
            alert('እባክዎ የምርት ስም ያስገቡ!');
            return;
        }
        
        if (isNaN(newPrice) || newPrice <= 0) {
            alert('እባክዎ ትክክለኛ ዋጋ ያስገቡ!');
            return;
        }
        
        if (newName === oldName && newPrice === oldPrice) {
            alert('ምንም ለውጥ አልተደረገም!');
            closeEditModal();
            return;
        }
        
        let confirmMessage = 'እርግጠኛ ነዎት ማሻሻል ይፈልጋሉ?\n\n';
        confirmMessage += '─────────────────────\n';
        confirmMessage += `ምርት: ${oldName}\n`;
        confirmMessage += `አዲስ ስም: ${newName}\n`;
        confirmMessage += `የአሁኑ ዋጋ: ${oldPrice.toFixed(2)} ETB\n`;
        confirmMessage += `አዲስ ዋጋ: ${newPrice.toFixed(2)} ETB\n`;
        confirmMessage += '─────────────────────\n';
        confirmMessage += 'ማሻሻሉን ማረጋገጥ ይፈልጋሉ?';
        
        if (confirm(confirmMessage)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="product_id" value="${id}">
                <input type="hidden" name="new_name" value="${escapeHtml(newName)}">
                <input type="hidden" name="new_price" value="${newPrice}">
                <input type="hidden" name="edit_item" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('editModalOverlay');
        if (modal && e.target === modal) {
            closeEditModal();
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditModal();
        }
    });
    </script>

    <!-- ALELTU OFFLINE-FIRST JS ENGINES -->
    <script src="assets/js/indexeddb-manager.js"></script>
    <script src="assets/js/offline-rules-engine.js"></script>
    <script src="assets/js/outbox-manager.js"></script>
    <script src="assets/js/sync-engine.js"></script>
    <script src="assets/js/device-manager.js"></script>

    <script>
    // Register PWA Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./service-worker.js')
                .then(reg => console.log('[PWA] Service Worker active:', reg.scope))
                .catch(err => console.warn('[PWA] Service Worker registration deferred:', err));
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // OFFLINE SALES POPUP — Click the sync badge to see what was sold offline
    // ═══════════════════════════════════════════════════════════════════════

    // Inject popup styles once
    (function injectOfflinePopupStyles() {
        if (document.getElementById('__offlinePopupStyles')) return;
        const s = document.createElement('style');
        s.id = '__offlinePopupStyles';
        s.textContent = `
            #offlineSalesOverlay {
                display:none;position:fixed;inset:0;z-index:99998;
                background:rgba(0,0,0,.6);backdrop-filter:blur(4px);
                align-items:center;justify-content:center;
            }
            #offlineSalesOverlay.open { display:flex; }
            #offlineSalesPanel {
                background:#1a1d27;border:1px solid #2a2d3e;border-radius:16px;
                width:min(680px,96vw);max-height:86vh;display:flex;flex-direction:column;
                box-shadow:0 24px 64px rgba(0,0,0,.5);overflow:hidden;
            }
            #offlineSalesPanel .osp-header {
                padding:18px 22px;border-bottom:1px solid #2a2d3e;
                display:flex;align-items:center;justify-content:space-between;
                background:#151821;flex-shrink:0;
            }
            #offlineSalesPanel .osp-title {
                font-size:15px;font-weight:700;color:#e2e8f0;display:flex;align-items:center;gap:8px;
            }
            #offlineSalesPanel .osp-close {
                background:none;border:none;color:#64748b;font-size:20px;cursor:pointer;
                padding:4px 8px;border-radius:6px;transition:color .2s;
            }
            #offlineSalesPanel .osp-close:hover { color:#ef4444; }
            #offlineSalesPanel .osp-stats {
                display:flex;gap:10px;padding:14px 22px;border-bottom:1px solid #2a2d3e;
                flex-shrink:0;flex-wrap:wrap;
            }
            #offlineSalesPanel .osp-stat {
                flex:1;min-width:100px;background:#0f1117;border:1px solid #2a2d3e;
                border-radius:10px;padding:10px 14px;text-align:center;
            }
            #offlineSalesPanel .osp-stat-val { font-size:22px;font-weight:800;color:#e2e8f0; }
            #offlineSalesPanel .osp-stat-lbl { font-size:11px;color:#64748b;margin-top:2px; }
            #offlineSalesPanel .osp-list {
                overflow-y:auto;flex:1;padding:12px 22px;display:flex;flex-direction:column;gap:10px;
            }
            #offlineSalesPanel .osp-list::-webkit-scrollbar { width:5px; }
            #offlineSalesPanel .osp-list::-webkit-scrollbar-track { background:#0f1117; }
            #offlineSalesPanel .osp-list::-webkit-scrollbar-thumb { background:#2a2d3e;border-radius:4px; }
            .osp-sale-card {
                background:#0f1117;border:1px solid #2a2d3e;border-radius:10px;padding:14px;
                cursor:pointer;transition:border-color .2s;
            }
            .osp-sale-card:hover { border-color:#6366f1; }
            .osp-sale-card.expanded .osp-items { display:block; }
            .osp-sale-top {
                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;
            }
            .osp-sale-left { display:flex;align-items:center;gap:10px; }
            .osp-status-dot {
                width:8px;height:8px;border-radius:50%;flex-shrink:0;
            }
            .osp-sale-uuid { font-size:11px;color:#475569;font-family:monospace; }
            .osp-sale-time { font-size:12px;color:#64748b; }
            .osp-sale-right { display:flex;align-items:center;gap:10px; }
            .osp-sale-total { font-size:15px;font-weight:700;color:#e2e8f0; }
            .osp-status-badge {
                font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                letter-spacing:.5px;text-transform:uppercase;
            }
            .osp-status-PENDING  { background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3); }
            .osp-status-SYNCED   { background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3); }
            .osp-status-FAILED   { background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3); }
            .osp-status-CONFLICT { background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.3); }
            .osp-status-SYNCING  { background:rgba(99,102,241,.15);color:#6366f1;border:1px solid rgba(99,102,241,.3); }
            .osp-items {
                display:none;margin-top:12px;padding-top:12px;
                border-top:1px solid #2a2d3e;
            }
            .osp-item-row {
                display:flex;justify-content:space-between;align-items:center;
                padding:5px 0;font-size:13px;color:#94a3b8;
                border-bottom:1px solid #1e2130;
            }
            .osp-item-row:last-child { border-bottom:none; }
            .osp-item-name { flex:1;color:#cbd5e1; }
            .osp-item-qty  { color:#6366f1;font-weight:600;margin:0 12px; }
            .osp-item-sub  { color:#10b981;font-weight:600; }
            .osp-payment   { margin-top:8px;font-size:12px;color:#475569; }
            .osp-payment span { color:#94a3b8; }
            .osp-error-msg {
                margin-top:8px;font-size:11px;color:#ef4444;
                background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);
                border-radius:6px;padding:6px 10px;
            }
            .osp-empty {
                text-align:center;padding:48px 24px;color:#64748b;
            }
            .osp-empty-icon { font-size:40px;margin-bottom:12px; }
            .osp-tabs {
                display:flex;gap:6px;padding:8px 22px;background:#10131b;border-bottom:1px solid #2a2d3e;
            }
            .osp-tab-btn {
                background:transparent;border:1px solid #2a2d3e;color:#94a3b8;
                padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;
                transition:all .2s;
            }
            .osp-tab-btn:hover { color:#e2e8f0;border-color:#6366f1; }
            .osp-tab-btn.active {
                background:#6366f1;color:#fff;border-color:#6366f1;
            }
            #offlineSalesPanel .osp-footer {
                padding:12px 22px;border-top:1px solid #2a2d3e;flex-shrink:0;
                display:flex;gap:8px;justify-content:flex-end;
            }
            .osp-btn {
                padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;
                border:none;cursor:pointer;transition:opacity .2s;
            }
            .osp-btn:hover { opacity:.85; }
            .osp-btn-sync { background:#6366f1;color:#fff; }
            .osp-btn-close { background:#2a2d3e;color:#e2e8f0; }
            #syncStatusBadge { cursor:pointer;user-select:none; }
            #syncStatusBadge:hover { opacity:.8; }
        `;
        document.head.appendChild(s);
    })();

    // Build and inject popup HTML once
    (function injectOfflinePopupHTML() {
        if (document.getElementById('offlineSalesOverlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'offlineSalesOverlay';
        overlay.innerHTML = `
            <div id="offlineSalesPanel">
                <div class="osp-header">
                    <div class="osp-title">📦 ኦፍላይን ሽያጮች — Offline Sales Queue</div>
                    <button class="osp-close" onclick="closeOfflinePopup()" title="Close">✕</button>
                </div>
                <div class="osp-tabs">
                    <button class="osp-tab-btn active" data-filter="all" onclick="setOfflinePopupFilter('all')">📋 ሁሉም (All Offline Sales)</button>
                    <button class="osp-tab-btn" data-filter="today" onclick="setOfflinePopupFilter('today')">📅 የዛሬ ብቻ (Today Only)</button>
                </div>
                <div class="osp-stats" id="ospStats"></div>
                <div class="osp-list" id="ospSalesList"></div>
                <div class="osp-footer">
                    <button class="osp-btn osp-btn-sync" onclick="forceSyncNow()">🔄 አሁን አመሳስል (Sync Now)</button>
                    <button class="osp-btn osp-btn-close" onclick="closeOfflinePopup()">ዝጋ (Close)</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        // Close on overlay click (outside panel)
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeOfflinePopup();
        });
    })();

    // Format ETB currency
    function fmtETB(n) {
        return 'ETB ' + parseFloat(n || 0).toLocaleString('en-ET', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Format time nicely
    function fmtTime(iso) {
        if (!iso) return '—';
        try {
            const d = new Date(iso);
            return d.toLocaleDateString('en-ET', { month: 'short', day: 'numeric' }) +
                   ' ' + d.toLocaleTimeString('en-ET', { hour: '2-digit', minute: '2-digit' });
        } catch(e) { return iso; }
    }

    // Status dot colors
    const STATUS_COLORS = {
        PENDING: '#f59e0b', SYNCED: '#10b981', FAILED: '#ef4444',
        CONFLICT: '#f97316', SYNCING: '#6366f1'
    };

    // Toggle sale card expand/collapse
    window.toggleSaleCard = function(uuid) {
        const card = document.querySelector(`.osp-sale-card[data-uuid="${uuid}"]`);
        if (card) card.classList.toggle('expanded');
    };

    // Open popup and load data
    window.openOfflinePopup = async function() {
        const overlay = document.getElementById('offlineSalesOverlay');
        if (!overlay) return;
        overlay.classList.add('open');
        await refreshOfflinePopup();
    };

    window.closeOfflinePopup = function() {
        const overlay = document.getElementById('offlineSalesOverlay');
        if (overlay) overlay.classList.remove('open');
    };

    window.forceSyncNow = function() {
        if (window.syncEngine) {
            window.syncEngine.forceSyncNow();
            showToast('🔄 Sync started...', 'info');
            setTimeout(refreshOfflinePopup, 2500);
        } else {
            showToast('Sync engine not ready', 'error');
        }
    };

    let _offlinePopupFilter = 'all'; // 'today' or 'all'

    window.setOfflinePopupFilter = function(filter) {
        _offlinePopupFilter = filter;
        document.querySelectorAll('.osp-tab-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.filter === filter);
        });
        refreshOfflinePopup();
    };

    async function refreshOfflinePopup() {
        const listEl  = document.getElementById('ospSalesList');
        const statsEl = document.getElementById('ospStats');
        if (!listEl || !statsEl) return;

        if (!window.aleltuDB) {
            listEl.innerHTML = '<div class="osp-empty"><div class="osp-empty-icon">⚙️</div>ኦፍላይን ዳታቤዝ ዝግጁ አይደለም (Offline engine not ready).</div>';
            return;
        }

        listEl.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><div style="margin-top:8px;">ዳታ በመጫን ላይ... (Loading offline sales...)</div></div>';

        let allSales = [];
        try {
            await window.aleltuDB.ensureReady();
            allSales = await window.aleltuDB.getAllSalesWithItems();
        } catch(e) {
            console.error('[POS] Error loading offline sales:', e);
            allSales = [];
        }

        // Apply filter (Today vs All)
        const todayStr = new Date().toISOString().slice(0, 10);
        const filteredSales = _offlinePopupFilter === 'today'
            ? allSales.filter(s => (s.created_locally_at || '').slice(0, 10) === todayStr)
            : allSales;

        // Stats calculation (on current filtered list)
        const pending  = filteredSales.filter(s => s.status === 'PENDING').length;
        const synced   = filteredSales.filter(s => s.status === 'SYNCED').length;
        const failed   = filteredSales.filter(s => s.status === 'FAILED' || s.status === 'CONFLICT').length;
        const totalETB = filteredSales.filter(s => s.status !== 'FAILED').reduce((a, s) => a + parseFloat(s.total_amount || 0), 0);

        statsEl.innerHTML = `
            <div class="osp-stat" title="ኢንተርኔት ሲቋረጥ የተሸጡ እና ሰርቨር ላይ ያልደረሱ (Offline sales waiting to sync)">
                <div class="osp-stat-val" style="color:#f59e0b">${pending}</div>
                <div class="osp-stat-lbl">⏳ በመጠባበቅ ላይ (Pending)</div>
            </div>
            <div class="osp-stat" title="ሰርቨር ላይ በስኬት የተመዘገቡ (Successfully synced to server)">
                <div class="osp-stat-val" style="color:#10b981">${synced}</div>
                <div class="osp-stat-lbl">✅ የተመሳሰለ (Synced)</div>
            </div>
            <div class="osp-stat" title="ስህተት ያጋጠማቸው ሽያጮች (Failed/Conflict)">
                <div class="osp-stat-val" style="color:#ef4444">${failed}</div>
                <div class="osp-stat-lbl">❌ ያልተሳካ (Failed)</div>
            </div>
            <div class="osp-stat" title="ጠቅላላ የኦፍላይን ሽያጭ ዋጋ (Total Offline Sales Amount)">
                <div class="osp-stat-val" style="color:#6366f1;font-size:16px">${fmtETB(totalETB)}</div>
                <div class="osp-stat-lbl">💰 ጠቅላላ ዋጋ (Total Amount)</div>
            </div>
        `;

        if (filteredSales.length === 0) {
            const emptyMsg = _offlinePopupFilter === 'today' 
                ? 'ዛሬ የተመዘገበ ኦፍላይን ሽያጭ የለም። (No offline sales recorded today.)'
                : 'ምንም የተቀመጠ ኦፍላይን ሽያጭ የለም። ሁሉም ሽያጮች በቀጥታ ሰርቨር ላይ ተመዝግበዋል! (No offline sales in local queue. All sales are up to date with server!)';
            listEl.innerHTML = `
                <div class="osp-empty">
                    <div class="osp-empty-icon">📭</div>
                    <div style="font-size:14px;margin-bottom:4px;font-weight:600;color:#e2e8f0">${emptyMsg}</div>
                    <div style="font-size:12px;color:#64748b;margin-top:6px;">
                        ኢንተርኔት ሲቋረጥ የሚሸጡት ማንኛውም ሽያጭ እዚህ በዝርዝር ይቀመጥና ኢንተርኔት ሲመጣ በራሱ ወደ ሰርቨር ይላካል።
                    </div>
                </div>`;
            return;
        }

        listEl.innerHTML = filteredSales.map(sale => {
            const status = sale.status || 'PENDING';
            const dotColor = STATUS_COLORS[status] || '#64748b';
            const shortUUID = (sale.sale_uuid || '').substring(0, 8).toUpperCase();
            const itemsHtml = (sale.items || []).map(it => `
                <div class="osp-item-row">
                    <div class="osp-item-name">${it.product_name || it.name || '—'}</div>
                    <div class="osp-item-qty">×${parseFloat(it.quantity).toFixed(it.quantity % 1 ? 2 : 0)}</div>
                    <div class="osp-item-sub">${fmtETB(it.subtotal || it.unit_price * it.quantity)}</div>
                </div>`).join('');
            const errorHtml = (status === 'FAILED' || status === 'CONFLICT') && sale.error_message
                ? `<div class="osp-error-msg">⚠️ ${sale.error_message}</div>` : '';
            return `
                <div class="osp-sale-card" data-uuid="${sale.sale_uuid}" onclick="toggleSaleCard('${sale.sale_uuid}')">
                    <div class="osp-sale-top">
                        <div class="osp-sale-left">
                            <div class="osp-status-dot" style="background:${dotColor}"></div>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#e2e8f0">#${shortUUID}</div>
                                <div class="osp-sale-time">${fmtTime(sale.created_locally_at)}</div>
                            </div>
                        </div>
                        <div class="osp-sale-right">
                            <div class="osp-sale-total">${fmtETB(sale.total_amount)}</div>
                            <div class="osp-status-badge osp-status-${status}">${status}</div>
                            <div style="color:#475569;font-size:12px">▼</div>
                        </div>
                    </div>
                    <div class="osp-items">
                        ${itemsHtml || '<div style="color:#475569;font-size:12px">No items recorded</div>'}
                        <div class="osp-payment">💳 የክፍያ መንገድ (Payment): <span>${sale.payment_method || 'cash'}</span>
                            &nbsp;|&nbsp; ጠቅላላ (Total): <span>${fmtETB(sale.total_amount)}</span>
                            ${(sale.change_amount > 0) ? `&nbsp;|&nbsp; መልስ (Change): <span>${fmtETB(sale.change_amount)}</span>` : ''}</div>
                        ${errorHtml}
                    </div>
                </div>`;
        }).join('');
    }

    // ══════════════════════════════════════════════════════════════════
    //  REAL WiFi Signal Engine
    //  Measures actual round-trip ping to the server and updates the
    //  4-bar SVG signal widget every 8 seconds.
    //
    //  Signal levels (bars lit):
    //   4 bars GREEN   → rtt < 300 ms   (excellent)
    //   3 bars GREEN   → rtt 300–799 ms (good)
    //   2 bars AMBER   → rtt 800–1999 ms (slow)
    //   1 bar  AMBER   → rtt ≥ 2000 ms  (very slow)
    //   X mark RED     → navigator.onLine===false OR fetch failed (offline)
    // ══════════════════════════════════════════════════════════════════
    (function WiFiSignalEngine() {
        // ── DOM refs ─────────────────────────────────────────────────
        const bars    = [null,
            document.getElementById('wbar1'),
            document.getElementById('wbar2'),
            document.getElementById('wbar3'),
            document.getElementById('wbar4')
        ];
        const xA      = document.getElementById('wifiXmark');
        const xB      = document.getElementById('wifiXmark2');
        const label   = document.getElementById('wifiLabel');
        const widget  = document.getElementById('wifiSignalWidget');
        const syncIco = document.getElementById('wifiSyncIcon');

        if (!bars[1]) return; // widget not on page

        // ── Colours ──────────────────────────────────────────────────
        const C = {
            good:    '#10b981',   // green
            slow:    '#f59e0b',   // amber
            offline: '#ef4444',   // red
            dim:     '#374151',   // unlit bar
        };

        // ── State ─────────────────────────────────────────────────────
        let _syncing  = false;
        let _pingMs   = -1;   // -1 = unknown, -2 = offline
        let _pingTimer = null;

        // ── Apply a signal level (0–4) to the SVG bars ───────────────
        function applySignal(level) {
            // level: 0=offline, 1-4=bars
            const isOffline = (level === 0);

            // X marks
            if (xA) xA.style.display = isOffline ? '' : 'none';
            if (xB) xB.style.display = isOffline ? '' : 'none';

            for (let i = 1; i <= 4; i++) {
                if (!bars[i]) continue;
                const lit  = !isOffline && i <= level;
                const colr = lit ? (level <= 2 ? C.slow : C.good) : C.dim;
                bars[i].setAttribute('fill', colr);
                bars[i].style.opacity = lit ? '1' : '0.35';
            }

            // Widget border glow
            if (widget) {
                widget.style.borderColor = isOffline
                    ? 'rgba(239,68,68,0.5)'
                    : level <= 2
                        ? 'rgba(245,158,11,0.4)'
                        : 'rgba(16,185,129,0.35)';
            }
        }

        // ── Update label text ─────────────────────────────────────────
        function applyLabel(level, rtt) {
            if (!label) return;
            if (level === 0) {
                label.textContent = 'Offline';
                label.style.color = C.offline;
            } else if (_syncing) {
                label.textContent = 'Syncing…';
                label.style.color = C.slow;
            } else if (level <= 2) {
                label.textContent = rtt > 0 ? `Slow ${rtt}ms` : 'Slow';
                label.style.color = C.slow;
            } else {
                label.textContent = 'Online ✓';
                label.style.color = C.good;
            }
        }

        // ── Map rtt (ms) → signal level 1–4 ──────────────────────────
        function rttToLevel(rtt) {
            if (rtt < 300)  return 4;
            if (rtt < 800)  return 3;
            if (rtt < 2000) return 2;
            return 1;
        }

        // ── Measure real ping latency via tiny fetch ──────────────────
        async function measurePing() {
            if (!navigator.onLine) {
                _pingMs = -2;
                applySignal(0);
                applyLabel(0, 0);
                return;
            }
            try {
                const t0 = performance.now();
                // Fetch a tiny endpoint with cache-busting; any 200/30x OK
                await fetch('index.php?_ping=' + t0, {
                    method: 'HEAD',
                    cache: 'no-store',
                    credentials: 'include',
                    signal: AbortSignal.timeout(5000)
                });
                const rtt = Math.round(performance.now() - t0);
                _pingMs = rtt;
                const level = rttToLevel(rtt);
                applySignal(level);
                applyLabel(level, rtt);
            } catch (e) {
                // Fetch failed → treat as offline
                _pingMs = -2;
                applySignal(0);
                applyLabel(0, 0);
            }
        }

        // ── Sync icon spin ─────────────────────────────────────────────
        function setSyncing(on) {
            _syncing = on;
            if (!syncIco) return;
            if (on) {
                syncIco.style.animation = 'spin360 1s linear infinite';
            } else {
                syncIco.style.animation = '';
            }
            // Refresh label to show/hide "Syncing…"
            const level = _pingMs === -2 ? 0 : (_pingMs < 0 ? 4 : rttToLevel(_pingMs));
            applyLabel(level, _pingMs);
        }

        // Inject spin keyframe if not already present
        if (!document.getElementById('wifiSpinStyle')) {
            const st = document.createElement('style');
            st.id = 'wifiSpinStyle';
            st.textContent = '@keyframes spin360{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
            document.head.appendChild(st);
        }

        // ── Hook into syncEngine status listener ──────────────────────
        function hookSyncEngine() {
            if (window.syncEngine && typeof window.syncEngine.onStatusChange === 'function') {
                window.syncEngine.onStatusChange(function(status) {
                    setSyncing(status.isSyncing || false);
                    // If syncEngine says offline, override signal immediately
                    if (!status.isOnline) {
                        applySignal(0);
                        applyLabel(0, 0);
                    }
                });
            } else {
                setTimeout(hookSyncEngine, 600);
            }
        }
        hookSyncEngine();

        // ── Online / offline browser events ───────────────────────────
        window.addEventListener('offline', () => { applySignal(0); applyLabel(0, 0); });
        window.addEventListener('online',  () => { measurePing(); });

        // ── Start periodic ping ───────────────────────────────────────
        measurePing(); // immediate first check
        _pingTimer = setInterval(measurePing, 8000); // every 8s

    })(); // end WiFiSignalEngine

    // Make the old sync badge clickable (kept for offline-ux.js compat)
    document.addEventListener('DOMContentLoaded', function() {
        const badge = document.getElementById('syncStatusBadge');
        if (badge) {
            badge.title = 'Click to see offline sales queue';
            badge.addEventListener('click', function() {
                if (typeof openOfflinePopup === 'function') openOfflinePopup();
            });
        }
    });



    // Fetch Active Offline Rules for Engine + Issue Offline Token + Refresh Inventory
    window.addEventListener('DOMContentLoaded', () => {
        // Step 1: Register device identity
        if (window.deviceManager) {
            window.deviceManager.registerDevice(branchId);
        }

        // Step 2: Issue offline token (30-day auth for continued offline access)
        if (navigator.onLine && window.deviceManager) {
            const deviceUUID = window.deviceManager.getDeviceUUID();
            fetch('/aleltu/api/auth/issue-offline-token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_uuid: deviceUUID })
            })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    localStorage.setItem('aleltu_offline_token', JSON.stringify({
                        token: d.token, expires_at: d.expires_at
                    }));
                    console.log('[POS] Offline token issued, expires:', d.expires_at);
                }
            })
            .catch(e => console.warn('[POS] Offline token deferred:', e.message));
        }

        // Step 3: Fetch offline rules
        const rulesHeaders = {};
        const savedToken = localStorage.getItem('aleltu_offline_token');
        if (savedToken) {
            try {
                const t = JSON.parse(savedToken);
                if (t.token && window.deviceManager) {
                    rulesHeaders['X-Offline-Token'] = t.token;
                    rulesHeaders['X-Device-UUID'] = window.deviceManager.getDeviceUUID();
                }
            } catch(e) {}
        }
        fetch('/aleltu/api/offline-rules/get.php?branch_id=' + branchId, { headers: rulesHeaders })
            .then(res => res.json())
            .then(data => {
                if (data && data.rules && window.offlineRulesEngine) {
                    window.offlineRulesEngine.setRules(data.rules);
                    console.log('[POS] Offline rules loaded:', data.rules.length);
                }
            })
            .catch(err => console.warn('[POS] Offline rules fetch deferred:', err.message));

        // Step 4: Initial inventory snapshot (incremental)
        if (navigator.onLine && window.syncEngine) {
            window.syncEngine.refreshInventorySnapshot();
        }

        // Step 5: Check storage quota
        if (window.syncEngine) {
            window.syncEngine.checkStorageQuota();
        }
    });

    // ══════════════════════════════════════════════════════════════════
    //  PWA INSTALL — real install prompt with icon, not just a letter
    // ══════════════════════════════════════════════════════════════════
    let _pwaPrompt = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        _pwaPrompt = e;
        const btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.style.display = 'inline-flex';
    });

    window.pwaInstall = async function() {
        if (!_pwaPrompt) {
            // Already installed or browser doesn't support
            showToast('✅ App is already installed!', 'info');
            return;
        }
        _pwaPrompt.prompt();
        const { outcome } = await _pwaPrompt.userChoice;
        _pwaPrompt = null;
        const btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.style.display = 'none';
        if (outcome === 'accepted') {
            showToast('✅ App installed successfully!', 'success');
        }
    };

    window.addEventListener('appinstalled', () => {
        _pwaPrompt = null;
        const btn = document.getElementById('pwaInstallBtn');
        if (btn) btn.style.display = 'none';
        console.log('[PWA] App installed successfully');
    });

    // ── Register Service Worker (with proper error handling) ──────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/aleltu/service-worker.js', { scope: '/aleltu/' })
                .then(reg => {
                    console.log('[SW] Registered, scope:', reg.scope);
                    // Check for updates every 60s
                    setInterval(() => reg.update(), 60000);
                })
                .catch(err => console.warn('[SW] Registration failed:', err.message));
        });
    }
    </script>

    <!-- Enhanced Offline UX: animated banner, pulsing WiFi, queue count, sync toast -->
    <script src="assets/js/offline-ux.js"></script>
</body>
</html>
<?php 
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
