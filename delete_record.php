<?php
/**
 * delete_record.php
 * Super-admin-only endpoint that deletes ONE record (transaction / stock_log /
 * product_return / withdrawal) and correctly reverses its effect on
 * seller_inventory.current_stock. Everything happens inside a single DB
 * transaction: if any step fails, nothing is deleted and stock is untouched.
 *
 * Every deletion is permanently recorded in `deletion_audit_log` BEFORE the
 * row is removed, including a full JSON snapshot, so nothing is ever lost
 * even though this is a hard delete.
 */

session_start();
require_once 'config.php';
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---- Auth: super_admin ONLY (not 'admin') ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super admin only.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

if (empty($_SESSION['delete_csrf_token']) || !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['delete_csrf_token'], (string) $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session verification failed. Refresh the page and try again.']);
    exit();
}

$type = $_POST['type'] ?? '';
$id   = intval($_POST['id'] ?? 0);

$allowed_types = ['transaction', 'stock_log', 'product_return', 'withdrawal'];
if (!in_array($type, $allowed_types, true) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid type or id.']);
    exit();
}

$deleted_by_user_id = $_SESSION['user_id'];
$deleted_by_name    = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'Super Admin');
$ip_address          = $_SERVER['REMOTE_ADDR'] ?? null;

/**
 * Adds (or subtracts, if $delta is negative) $delta to the shared
 * seller_inventory pool for ($item_name, $branch_id). Never lets stock
 * go below 0. Locks the row (FOR UPDATE) so this is safe under concurrent
 * requests. If no matching row exists:
 *   - and $delta > 0 (we need to ADD stock back) -> creates a new row
 *   - and $delta <= 0 (we need to SUBTRACT stock) -> nothing to subtract
 *     from; this is logged, not fatal (e.g. item was renamed/removed
 *     since the original sale/stock-in).
 *
 * Returns a human-readable summary string for the audit log.
 */
function adjustSellerInventory($conn, $item_name, $branch_id, $delta, $context_hint = '') {
    $item_name = trim($item_name);
    $delta = round(floatval($delta), 2);
    if ($item_name === '' || intval($branch_id) <= 0) {
        throw new Exception('This record does not contain a valid item and branch for a safe stock reversal.');
    }
    if ($delta == 0) {
        return "No change needed for '{$item_name}'.";
    }

    $stmt = mysqli_prepare($conn,
        "SELECT id, current_stock FROM seller_inventory
         WHERE item_name = ? AND branch_id = ?
         ORDER BY current_stock DESC LIMIT 1 FOR UPDATE");
    mysqli_stmt_bind_param($stmt, "si", $item_name, $branch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($row) {
        $old_stock = floatval($row['current_stock']);
        $new_stock = round($old_stock + $delta, 2);
        if ($new_stock < 0) {
            // BLOCK the delete entirely — never floor to 0 silently.
            // A negative result means some of this stock was already used
            // elsewhere (e.g. sold in a later transaction).
            $short_by = abs($new_stock);
            $hint = $context_hint !== '' ? " {$context_hint}" : '';
            throw new Exception(
                "Cannot delete: '{$item_name}' only has {$old_stock} in stock, but this needs {$short_by} more than that. " .
                "This usually means some of it was already sold or used elsewhere.{$hint} No changes were made."
            );
        }
        $upd = mysqli_prepare($conn,
            "UPDATE seller_inventory SET current_stock = ?, last_updated = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($upd, "di", $new_stock, $row['id']);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        return "{$item_name} (branch {$branch_id}): {$old_stock} -> {$new_stock}";
    }

    // No existing inventory row
    if ($delta > 0) {
        $ins = mysqli_prepare($conn,
            "INSERT INTO seller_inventory (seller_id, branch_id, item_name, current_stock, unit, last_updated)
             VALUES (0, ?, ?, ?, 'pcs', NOW())");
        mysqli_stmt_bind_param($ins, "isd", $branch_id, $item_name, $delta);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
        return "{$item_name} (branch {$branch_id}): no existing inventory row, created new row with {$delta}";
    }

    return "{$item_name} (branch {$branch_id}): NO inventory row found to subtract {$delta} from — skipped, nothing changed.";
}

function insertAuditLog($conn, $record_type, $record_id, $branch_id, $deleted_by_user_id,
                          $deleted_by_name, $stock_summary, $snapshot, $ip_address) {
    $snapshot_json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = mysqli_prepare($conn,
        "INSERT INTO deletion_audit_log
         (record_type, record_id, branch_id, deleted_by_user_id, deleted_by_name,
          stock_reversal_summary, snapshot_json, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "siiissss",
        $record_type, $record_id, $branch_id, $deleted_by_user_id, $deleted_by_name,
        $stock_summary, $snapshot_json, $ip_address);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

mysqli_begin_transaction($conn);

try {
    switch ($type) {

        // ------------------------------------------------------------
        case 'transaction':
            $stmt = mysqli_prepare($conn, "SELECT * FROM transactions WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $tx = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$tx) throw new Exception("Transaction #{$id} not found.");

            $stmt = mysqli_prepare($conn, "SELECT * FROM transaction_items WHERE transaction_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $items_result = mysqli_stmt_get_result($stmt);
            $items = [];
            while ($r = mysqli_fetch_assoc($items_result)) $items[] = $r;
            mysqli_stmt_close($stmt);

            $summary_lines = [];
            foreach ($items as $item) {
                $branch_id = $item['branch_id'] ?: $tx['branch_id'];
                // Restore stock: sold quantity goes back into inventory
                $summary_lines[] = adjustSellerInventory($conn, $item['product_name'], $branch_id, +$item['quantity']);
            }

            $del = mysqli_prepare($conn, "DELETE FROM transaction_items WHERE transaction_id = ?");
            mysqli_stmt_bind_param($del, "i", $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            $del = mysqli_prepare($conn, "DELETE FROM transactions WHERE id = ?");
            mysqli_stmt_bind_param($del, "i", $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            insertAuditLog($conn, 'transaction', $id, $tx['branch_id'], $deleted_by_user_id, $deleted_by_name,
                implode(" | ", $summary_lines), ['transaction' => $tx, 'items' => $items], $ip_address);
            break;

        // ------------------------------------------------------------
        case 'stock_log':
            $stmt = mysqli_prepare($conn, "SELECT * FROM stock_logs WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $log = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$log) throw new Exception("Stock log #{$id} not found.");

            // quantity can be positive (stock received) or negative (return-type log).
            // Subtracting the original quantity correctly reverses either case.
            $summary = adjustSellerInventory($conn, $log['item_name'], $log['branch_id'], -$log['quantity'],
                'Check the Transactions tab for later sales of this item and delete those first, then retry.');

            if ($log['source'] === 'return') {
                $summary .= " [NOTE: this was a return-type log. If a matching entry also exists under Product Returns for the same item/date, delete that separately if you want it removed from that report too — stock is already correctly reversed by this action alone.]";
            }

            $del = mysqli_prepare($conn, "DELETE FROM stock_logs WHERE id = ?");
            mysqli_stmt_bind_param($del, "i", $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            insertAuditLog($conn, 'stock_log', $id, $log['branch_id'], $deleted_by_user_id, $deleted_by_name,
                $summary, $log, $ip_address);
            break;

        // ------------------------------------------------------------
        case 'product_return':
            $stmt = mysqli_prepare($conn, "SELECT * FROM product_returns WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$ret) throw new Exception("Product return #{$id} not found.");

            // A return had removed stock from the shop -> undo it by adding back.
            $summary = adjustSellerInventory($conn, $ret['product_name'], $ret['branch_id'], +$ret['quantity'],
                'Check the Transactions/Stock Logs tabs for later activity on this item.');
            $summary .= " [NOTE: if a matching entry also exists under Stock Logs (source=return) for this same event, that is a separate historical record — delete it too from the Stock Logs tab if you want it gone. Do NOT delete both expecting a second stock change: only this action changed the stock number.]";

            $del = mysqli_prepare($conn, "DELETE FROM product_returns WHERE id = ?");
            mysqli_stmt_bind_param($del, "i", $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            insertAuditLog($conn, 'product_return', $id, $ret['branch_id'], $deleted_by_user_id, $deleted_by_name,
                $summary, $ret, $ip_address);
            break;

        // ------------------------------------------------------------
        case 'withdrawal':
            $stmt = mysqli_prepare($conn, "SELECT * FROM daily_withdrawals WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $wd = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$wd) throw new Exception("Withdrawal #{$id} not found.");

            $del = mysqli_prepare($conn, "DELETE FROM daily_withdrawals WHERE id = ?");
            mysqli_stmt_bind_param($del, "i", $id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);

            insertAuditLog($conn, 'withdrawal', $id, $wd['branch_id'], $deleted_by_user_id, $deleted_by_name,
                "No inventory impact — cash record only.", $wd, $ip_address);
            break;
    }

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'message' => 'Deleted successfully.']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nothing was deleted. Error: ' . $e->getMessage()]);
}
