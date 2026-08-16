<?php
/**
 * process_return.php
 * Records a product return and reduces the shared branch stock pool.
 *
 * FIXED (this pass):
 *  - mysqli is now set to throw on any query error (mysqli_report STRICT),
 *    so a failed query stops the whole action instead of silently being
 *    ignored while the code carries on as if it worked.
 *  - The stock check and the stock update now happen inside one locked
 *    read (FOR UPDATE) instead of two separate queries, so two returns
 *    filed at the same instant cannot both pass the check and push stock
 *    negative.
 *  - No more silent GREATEST(0, ...) floor. If, after locking the row,
 *    there truly isn't enough stock, the whole return is rejected with a
 *    clear error and nothing is saved.
 *  - Switched to prepared statements throughout (was building raw SQL
 *    strings, including pasting $seller_name straight into the query).
 */
session_start();
require_once 'config.php';

header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

mysqli_set_charset($conn, "utf8mb4");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_branch = getUserBranch($conn, $_SESSION['user_id']);
$branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);

$seller_id = (int) $_SESSION['user_id'];
$product_name = trim($_POST['product_name'] ?? '');
$quantity = floatval($_POST['quantity'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if (!$seller_id || $product_name === '' || $quantity <= 0 || intval($branch_id) <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}

try {
    $stmt = mysqli_prepare($conn, "SELECT full_name, username FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $seller_id);
    mysqli_stmt_execute($stmt);
    $seller_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$seller_row) {
        echo json_encode(['success' => false, 'error' => 'Seller not found']);
        exit();
    }
    $seller_name = $seller_row['full_name'] ?: $seller_row['username'] ?: 'Seller';

    mysqli_begin_transaction($conn);

    // Lock the shared stock row for this item+branch while we check and update.
    $stmt = mysqli_prepare($conn,
        "SELECT id, current_stock, unit FROM seller_inventory
         WHERE item_name = ? AND branch_id = ?
         ORDER BY current_stock DESC LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, "si", $product_name, $branch_id);
    mysqli_stmt_execute($stmt);
    $stock_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$stock_row) {
        throw new Exception('Product not found in inventory for this branch.');
    }

    $current_stock = floatval($stock_row['current_stock']);
    if ($current_stock < $quantity) {
        throw new Exception('Not enough stock. Available: ' . $current_stock . ' ' . $stock_row['unit']);
    }

    $new_stock = round($current_stock - $quantity, 2);

    $upd = mysqli_prepare($conn,
        "UPDATE seller_inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($upd, "di", $new_stock, $stock_row['id']);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    $gregorian_date = date('Y-m-d H:i:s');

    $eth_year = date('Y') - 8;
    if (date('n') >= 9 || (date('n') == 9 && date('j') >= 11)) {
        $eth_year++;
    }
    $ethiopian_date = $eth_year . '-' . str_pad(date('n'), 2, '0', STR_PAD_LEFT) . '-' . str_pad(date('j'), 2, '0', STR_PAD_LEFT);

    $utc_hour = gmdate('G');
    $minute = gmdate('i');
    $second = gmdate('s');
    $eth_hour = ($utc_hour + 3) % 24;
    $period = $eth_hour < 12 ? 'ጥዋት' : 'ከሰዓት';
    $eth_hour_12 = $eth_hour % 12;
    if ($eth_hour_12 == 0) $eth_hour_12 = 12;
    $ethiopian_time = sprintf('%d:%02d:%02d %s', $eth_hour_12, $minute, $second, $period);

    $unit = $stock_row['unit'];

    $ins = mysqli_prepare($conn,
        "INSERT INTO product_returns
         (seller_id, seller_name, product_name, quantity, unit, reason,
          ethiopian_date, ethiopian_time, gregorian_date, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($ins, "issdsssssi",
        $seller_id, $seller_name, $product_name, $quantity, $unit,
        $reason, $ethiopian_date, $ethiopian_time, $gregorian_date, $branch_id);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    $neg_quantity = -$quantity;
    $notes = 'Return: ' . $reason;
    $source = 'return';
    $log = mysqli_prepare($conn,
        "INSERT INTO stock_logs
         (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($log, "issdsssssi",
        $seller_id, $seller_name, $product_name, $neg_quantity, $unit,
        $source, $seller_name, $gregorian_date, $notes, $branch_id);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'message' => "$quantity $unit returned",
        'new_stock' => $new_stock
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

mysqli_close($conn);
