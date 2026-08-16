<?php
/**
 * save_sale.php
 * Saves a completed sale (transaction + its items) and subtracts sold
 * quantities from the shared branch stock pool.
 *
 * FIXED (this pass) — this file previously had the most dangerous version
 * of the "silent stock" problem in the whole system:
 *  - A sale could complete successfully even if the stock update failed —
 *    the old code only wrote to the server's private error_log() and kept
 *    going. The seller/admin saw "Transaction saved successfully" while
 *    stock secretly didn't change. Now: if any item's stock can't be
 *    safely updated, the ENTIRE sale is rolled back and rejected.
 *  - GREATEST(0, ...) silently floored oversold stock to 0 with no
 *    warning at all. Now: stock is checked (with a row lock) BEFORE
 *    completing the sale, and if any item doesn't have enough stock, the
 *    whole sale is rejected up front with a clear message — nothing is
 *    saved, no partial sale, no phantom stock loss.
 *  - Switched every query to prepared statements (was building raw SQL
 *    strings with values pasted directly into them).
 */
require_once 'config.php';
mysqli_set_charset($conn, "utf8mb4");
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// The POS uses save_transaction.php. Keeping a second public sale writer
// creates a bypass risk and makes future data fixes harder to maintain.
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'This endpoint is retired. Please use the current POS page.']);
exit();

$user_id_for_branch = $_SESSION['user_id'] ?? intval($_POST['seller_id'] ?? 0);
$role_for_branch = $_SESSION['role'] ?? 'seller';
$branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : getCurrentBranchId($conn, $user_id_for_branch, $role_for_branch);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$total = floatval($_POST['total'] ?? 0);
$paid = floatval($_POST['paid'] ?? 0);
$change = floatval($_POST['change'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? 'cash');
$seller_id = intval($_POST['seller_id'] ?? 1);
$seller_name = trim($_POST['seller_name'] ?? 'Seller');
$items_json = $_POST['items'] ?? '[]';
$items = json_decode($items_json, true);

if ($total <= 0 || empty($items) || $branch_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction data']);
    exit();
}

mysqli_begin_transaction($conn);

try {
    // ---- PASS 1: lock and validate every item BEFORE saving anything.
    // This guarantees the sale is all-or-nothing: either every item has
    // enough stock and the whole sale goes through, or none of it does.
    $stock_rows = []; // item_name => ['id'=>, 'current_stock'=>]
    foreach ($items as $item) {
        $item_name = trim($item['name'] ?? '');
        $quantity = floatval($item['quantity'] ?? 0);
        if ($item_name === '' || $quantity <= 0) {
            throw new Exception('Invalid item in cart.');
        }

        $stmt = mysqli_prepare($conn,
            "SELECT id, current_stock FROM seller_inventory
             WHERE item_name = ? AND branch_id = ?
             ORDER BY current_stock DESC LIMIT 1 FOR UPDATE");
        mysqli_stmt_bind_param($stmt, "si", $item_name, $branch_id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$row) {
            throw new Exception("'{$item_name}' was not found in this branch's inventory.");
        }
        $current_stock = floatval($row['current_stock']);
        if ($current_stock < $quantity) {
            throw new Exception("Not enough stock for '{$item_name}'. Available: {$current_stock}, needed: {$quantity}.");
        }
        // Track running total in case the same item appears twice in the cart.
        $key = $item_name;
        if (!isset($stock_rows[$key])) {
            $stock_rows[$key] = ['id' => $row['id'], 'remaining' => $current_stock];
        }
        $stock_rows[$key]['remaining'] -= $quantity;
        if ($stock_rows[$key]['remaining'] < 0) {
            throw new Exception("Not enough stock for '{$item_name}' when combining duplicate cart lines.");
        }
    }

    // ---- PASS 2: everything validated — now actually save the sale.
    $ins_tx = mysqli_prepare($conn,
        "INSERT INTO transactions
         (seller_id, seller_name, total_amount, paid_amount, change_amount, payment_method, transaction_date, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
    mysqli_stmt_bind_param($ins_tx, "isdddsi",
        $seller_id, $seller_name, $total, $paid, $change, $payment_method, $branch_id);
    mysqli_stmt_execute($ins_tx);
    mysqli_stmt_close($ins_tx);
    $transaction_id = mysqli_insert_id($conn);

    foreach ($items as $item) {
        $item_id = intval($item['id'] ?? 0);
        $item_name = trim($item['name'] ?? '');
        $quantity = floatval($item['quantity'] ?? 0);
        $price = floatval($item['price'] ?? 0);
        $subtotal = floatval($item['subtotal'] ?? 0);

        $ins_item = mysqli_prepare($conn,
            "INSERT INTO transaction_items
             (transaction_id, product_id, product_name, quantity, unit_price, subtotal, branch_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($ins_item, "iisdddi",
            $transaction_id, $item_id, $item_name, $quantity, $price, $subtotal, $branch_id);
        mysqli_stmt_execute($ins_item);
        mysqli_stmt_close($ins_item);

        $stock_row_id = $stock_rows[$item_name]['id'];
        $upd = mysqli_prepare($conn,
            "UPDATE seller_inventory SET current_stock = current_stock - ?, last_updated = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, "di", $quantity, $stock_row_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'transaction_id' => $transaction_id,
        'message' => 'Transaction saved successfully'
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
