<?php
/**
 * preview_delete.php
 * Super-admin-only, READ-ONLY endpoint. Given a record type + id, it looks
 * at the current LIVE stock and calculates exactly what would happen if
 * that record were deleted — WITHOUT deleting or changing anything.
 *
 * The delete manager page calls this first, shows the result in a popup,
 * and only lets the admin proceed with the real delete (delete_record.php)
 * if this preview says it is safe.
 *
 * This file never writes to the database. It is safe to call as many
 * times as needed.
 */

session_start();
require_once 'config.php';
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']);
    exit();
}

$type = $_GET['type'] ?? '';
$id   = intval($_GET['id'] ?? 0);
$allowed_types = ['transaction', 'stock_log', 'product_return', 'withdrawal'];
if (!in_array($type, $allowed_types, true) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid type or id.']);
    exit();
}

/**
 * Looks up the CURRENT live stock for one item+branch (read-only, no lock
 * needed since nothing is being changed here) and works out what it would
 * become after applying $delta. Mirrors the real logic in delete_record.php
 * exactly, so the preview always matches what the real delete will do.
 */
function previewStockChange($conn, $item_name, $branch_id, $delta, $context_note = '') {
    $item_name = trim($item_name);
    $delta = round(floatval($delta), 2);
    $branch_id = intval($branch_id);

    if ($item_name === '' || $branch_id <= 0) {
        return [
            'item_name' => $item_name, 'branch_id' => $branch_id, 'unit' => '',
            'current_stock' => null, 'delta' => $delta, 'new_stock' => null,
            'blocked' => true,
            'reason' => 'This record has no valid item/branch, so stock cannot be safely calculated.',
        ];
    }

    $stmt = mysqli_prepare($conn,
        "SELECT current_stock, unit FROM seller_inventory
         WHERE item_name = ? AND branch_id = ? ORDER BY current_stock DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "si", $item_name, $branch_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        // No stock row exists for this item yet.
        if ($delta > 0) {
            return [
                'item_name' => $item_name, 'branch_id' => $branch_id, 'unit' => '',
                'current_stock' => 0, 'delta' => $delta, 'new_stock' => $delta,
                'blocked' => false,
                'reason' => 'No inventory row exists yet — a new one will be created.',
            ];
        }
        return [
            'item_name' => $item_name, 'branch_id' => $branch_id, 'unit' => '',
            'current_stock' => 0, 'delta' => $delta, 'new_stock' => 0,
            'blocked' => false,
            'reason' => 'No inventory row exists for this item — nothing to subtract from, stock stays at 0.',
        ];
    }

    $current_stock = floatval($row['current_stock']);
    $new_stock = round($current_stock + $delta, 2);
    $blocked = $new_stock < 0;

    $reason = '';
    if ($blocked) {
        $short_by = abs($new_stock);
        $reason = "Blocked: would need {$short_by} more {$row['unit']} than currently exists. " .
                  "This usually means some of this stock was already sold or used in a later action. " .
                  ($context_note !== '' ? $context_note : 'Delete/fix the later action first, then retry.');
    }

    return [
        'item_name' => $item_name, 'branch_id' => $branch_id, 'unit' => $row['unit'],
        'current_stock' => $current_stock, 'delta' => $delta, 'new_stock' => $blocked ? null : $new_stock,
        'blocked' => $blocked, 'reason' => $reason,
    ];
}

$changes = [];
$overall_blocked = false;
$title = '';
$no_stock_impact = false;

try {
    if ($type === 'transaction') {
        $stmt = mysqli_prepare($conn, "SELECT * FROM transactions WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $tx = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$tx) throw new Exception("Transaction #{$id} not found.");

        $stmt = mysqli_prepare($conn, "SELECT * FROM transaction_items WHERE transaction_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) $items[] = $r;
        mysqli_stmt_close($stmt);

        $title = "Transaction #{$id} — {$tx['seller_name']} — " . number_format($tx['total_amount'], 2);
        foreach ($items as $item) {
            $branch_id = $item['branch_id'] ?: $tx['branch_id'];
            // Deleting a sale RESTORES stock (+quantity).
            $changes[] = previewStockChange($conn, $item['product_name'], $branch_id, +$item['quantity']);
        }

    } elseif ($type === 'stock_log') {
        $stmt = mysqli_prepare($conn, "SELECT * FROM stock_logs WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $log = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$log) throw new Exception("Stock log #{$id} not found.");

        $title = "Stock Log #{$id} — {$log['item_name']} — {$log['quantity']} {$log['unit']}";
        // Deleting a stock-in log REMOVES that stock (-quantity).
        $changes[] = previewStockChange(
            $conn, $log['item_name'], $log['branch_id'], -$log['quantity'],
            'Tip: check Transactions for later sales of this item, or delete those first.'
        );

    } elseif ($type === 'product_return') {
        $stmt = mysqli_prepare($conn, "SELECT * FROM product_returns WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$ret) throw new Exception("Product return #{$id} not found.");

        $title = "Product Return #{$id} — {$ret['product_name']} — {$ret['quantity']} {$ret['unit']}";
        // Deleting a return undoes it -> stock goes back down (-quantity).
        $changes[] = previewStockChange(
            $conn, $ret['product_name'], $ret['branch_id'], -$ret['quantity'],
            'Tip: check Transactions/Stock Logs for later activity on this item.'
        );

    } elseif ($type === 'withdrawal') {
        $stmt = mysqli_prepare($conn, "SELECT * FROM daily_withdrawals WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $wd = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$wd) throw new Exception("Withdrawal #{$id} not found.");

        $title = "Withdrawal #{$id} — {$wd['username']} — " . number_format($wd['amount'], 2);
        $no_stock_impact = true;
    }

    foreach ($changes as $c) {
        if (!empty($c['blocked'])) $overall_blocked = true;
    }

    echo json_encode([
        'success' => true,
        'title' => $title,
        'no_stock_impact' => $no_stock_impact,
        'changes' => $changes,
        'overall_blocked' => $overall_blocked,
    ]);

} catch (Exception $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
