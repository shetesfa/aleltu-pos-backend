<?php
/**
 * save_transaction.php
 * THIS is the real, live file the seller POS page (seller_pos.php) calls
 * to save every sale. (save_sale.php looked similar but is NOT actually
 * called anywhere in this app — this is the one that matters.)
 *
 * FIXED (this pass) — same problem class found here as everywhere else,
 * and this is the most important one to fix since it runs on every sale:
 *  - Used GREATEST(0, current_stock - quantity) to silently floor to 0
 *    with zero warning. A sale could "succeed" while quietly overselling
 *    stock with no record that it happened.
 *  - No check that enough stock existed before saving the sale.
 *  - No row locking, so two sales for the same item at the same moment
 *    could both read "enough stock" and both succeed, going negative.
 * Now: stock for every item is locked and checked BEFORE the sale is
 * saved. If any item doesn't have enough stock, the WHOLE sale is
 * rejected up front — nothing is saved, nothing is partially charged.
 * Request fields, response shape ('success'/'error'/'transaction_id'/
 * 'ethiopian_date') are UNCHANGED so seller_pos.php keeps working exactly
 * as before with no front-end changes needed.
 */
session_start();
require_once 'config.php';

set_time_limit(120);
ini_set('max_execution_time', 120);
mysqli_set_charset($conn, "utf8mb4");
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$user_id      = $_SESSION['user_id'] ?? 0;
$user_role    = $_SESSION['role'] ?? 'seller';
$seller_name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Seller';

if ($user_id == 0) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit();
}

$paid           = floatval($_POST['paid'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? 'cash');
$items_json     = $_POST['items'] ?? '[]';
$items          = json_decode($items_json, true);
$branch_id      = resolveWriteBranchId($conn, intval($_POST['branch_id'] ?? 0));

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Session verification failed. Refresh the page and try again.']);
    exit();
}

if (empty($items) || !is_array($items) || $branch_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transaction data']);
    exit();
}

mysqli_begin_transaction($conn);

try {
    date_default_timezone_set('Africa/Addis_Ababa');
    $transaction_date = date('Y-m-d H:i:s');

    // ---- PASS 1: lock and validate every item BEFORE saving anything.
    // Guarantees the sale is all-or-nothing.
    $stock_rows = []; // item_name => ['id'=>, 'remaining'=>]
    $validated_items = [];
    $server_total = 0.0;
    foreach ($items as $item) {
        $product_id = intval($item['id'] ?? 0);
        $quantity  = floatval($item['quantity'] ?? 0);
        if ($product_id <= 0 || $quantity <= 0) {
            throw new Exception('Invalid item in cart.');
        }

        // Product name and price are always taken from the database, never
        // from browser input that can be edited before submission.
        $product_stmt = mysqli_prepare($conn,
            "SELECT id, name, unit_price FROM products
             WHERE id = ? AND branch_id = ? AND (is_active IS NULL OR is_active = 1)");
        mysqli_stmt_bind_param($product_stmt, 'ii', $product_id, $branch_id);
        mysqli_stmt_execute($product_stmt);
        $product = mysqli_fetch_assoc(mysqli_stmt_get_result($product_stmt));
        mysqli_stmt_close($product_stmt);
        if (!$product || trim((string) $product['name']) === '') {
            throw new Exception('A selected product is unavailable in this branch. Please refresh the product list.');
        }

        $item_name = $product['name'];
        $price = (float) $product['unit_price'];
        if ($price < 0) {
            throw new Exception("Invalid price configured for '{$item_name}'.");
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

        $key = $item_name;
        if (!isset($stock_rows[$key])) {
            $stock_rows[$key] = ['id' => $row['id'], 'remaining' => floatval($row['current_stock'])];
        }
        $stock_rows[$key]['remaining'] -= $quantity;
        if ($stock_rows[$key]['remaining'] < 0) {
            throw new Exception("Not enough stock for '{$item_name}'. Please refresh and check available quantity.");
        }

        $subtotal = round($price * $quantity, 2);
        $server_total += $subtotal;
        $validated_items[] = [
            'id' => (int) $product['id'], 'name' => $item_name,
            'quantity' => $quantity, 'price' => $price, 'subtotal' => $subtotal
        ];
    }

    $total = round($server_total, 2);
    if ($total <= 0) {
        throw new Exception('The calculated sale total is invalid.');
    }
    // In this business, customers always pay the exact price physically.
    // If the seller did not enter a paid amount, treat it as exact payment.
    if ($paid <= 0) {
        $paid = $total;
    }
    $change = max(0, round($paid - $total, 2));

    // ---- PASS 2: everything validated — now actually save the sale.
    $ins_tx = mysqli_prepare($conn,
        "INSERT INTO transactions
         (seller_id, seller_name, total_amount, paid_amount, change_amount, payment_method, transaction_date, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($ins_tx, "isdddssi",
        $user_id, $seller_name, $total, $paid, $change, $payment_method, $transaction_date, $branch_id);
    mysqli_stmt_execute($ins_tx);
    mysqli_stmt_close($ins_tx);
    $transaction_id = mysqli_insert_id($conn);

    foreach ($validated_items as $item) {
        $item_id   = $item['id'];
        $item_name = $item['name'];
        $quantity  = $item['quantity'];
        $price     = $item['price'];
        $subtotal  = $item['subtotal'];

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
        'success'          => true,
        'transaction_id'   => $transaction_id,
        'message'          => 'Transaction saved successfully',
        'ethiopian_date'   => $transaction_date
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

mysqli_close($conn);
