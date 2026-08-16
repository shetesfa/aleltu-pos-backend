<?php
/**
 * decrease_stock.php
 * Subtracts sold quantity from the shared branch stock pool for one item.
 *
 * FIXED (this pass):
 *  - Now uses prepared statements everywhere (was building raw SQL strings
 *    with $_SESSION['full_name'] pasted in directly — an injection risk).
 *  - Now wraps the check + update in one database transaction with a row
 *    lock (FOR UPDATE), so two sales happening at the same instant cannot
 *    both pass the "enough stock?" check and push stock negative.
 *  - Never silently floors to 0 anymore. If, after locking the row, there
 *    truly isn't enough stock, the whole action is rejected with a clear
 *    error and nothing is changed — no more silent GREATEST(0, ...).
 */
require_once 'config.php';
header('Content-Type: application/json');

if (!$conn) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed']));
}

mysqli_set_charset($conn, "utf8mb4");

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

$seller_id = $_SESSION['user_id'];
$seller_name = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Unknown');

$user_branch = getUserBranch($conn, $seller_id);
$branch_id = getCurrentBranchId($conn, $seller_id, $_SESSION['role']);

$item_name = trim($_POST['item_name'] ?? '');
$quantity  = isset($_POST['quantity']) ? floatval($_POST['quantity']) : 0;

if ($item_name === '' || $quantity <= 0 || intval($branch_id) <= 0) {
    die(json_encode(['success' => false, 'error' => 'Invalid item name, quantity, or branch']));
}

mysqli_begin_transaction($conn);

try {
    // Lock the row(s) for this item+branch while we check and update, so
    // a second simultaneous sale can't read the same "before" number.
    $stmt = mysqli_prepare($conn,
        "SELECT id, current_stock FROM seller_inventory
         WHERE item_name = ? AND branch_id = ?
         ORDER BY current_stock DESC LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, "si", $item_name, $branch_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception('Item not found in inventory for this branch.');
    }

    $current_stock = floatval($row['current_stock']);

    if ($current_stock < $quantity) {
        throw new Exception('Insufficient stock. Available: ' . $current_stock);
    }

    $new_stock = round($current_stock - $quantity, 2);

    $upd = mysqli_prepare($conn,
        "UPDATE seller_inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($upd, "di", $new_stock, $row['id']);
    mysqli_stmt_execute($upd);
    $affected = mysqli_stmt_affected_rows($upd);
    mysqli_stmt_close($upd);

    if ($affected === 0) {
        // Should not happen since we hold the row lock, but guard anyway
        // instead of silently reporting success.
        throw new Exception('Stock update did not apply. Please retry.');
    }

    $ethiopian_date = date('Y-m-d');
    $neg_quantity = -$quantity;
    $notes = 'Sale transaction';
    $source = 'sale';
    $log = mysqli_prepare($conn,
        "INSERT INTO stock_logs (seller_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes, branch_id)
         VALUES (?, ?, ?, ?, 'pcs', ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($log, "issdssssi",
        $seller_id, $seller_name, $item_name, $neg_quantity, $source, $seller_name, $ethiopian_date, $notes, $branch_id);
    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'message' => 'Stock decreased successfully', 'new_stock' => $new_stock]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

mysqli_close($conn);
