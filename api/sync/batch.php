<?php
/**
 * ALELTU POS — Server Batch Synchronization & Idempotency Engine (Hardened)
 * Processes offline event batches with auth, rate limiting, and audit logging.
 */
session_start();
require_once '../../config.php';
require_once '../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);
mysqli_report(MYSQLI_REPORT_OFF);

// Authenticate request
api_require_auth();

// Rate limit: max 120 batch requests per minute per device
$rl_identifier = $GLOBALS['api_user']['device_uuid'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
api_rate_limit($rl_identifier, 'sync/batch', 120, 60);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['events']) || !is_array($data['events'])) {
    echo json_encode([
        'success' => false,
        'code' => 'INVALID_PAYLOAD',
        'message' => 'Expected JSON object with events array'
    ]);
    exit();
}

$device_uuid = trim($data['device_id'] ?? ($GLOBALS['api_user']['device_uuid'] ?? 'unknown-device'));
$events      = $data['events'];
$results     = [];

// Verify device status (REVOKED check)
$device_stmt = mysqli_prepare($conn, "SELECT id, status FROM devices WHERE device_uuid = ?");
if ($device_stmt) {
    mysqli_stmt_bind_param($device_stmt, 's', $device_uuid);
    mysqli_stmt_execute($device_stmt);
    $device_res = mysqli_stmt_get_result($device_stmt);
    if ($dev_row = mysqli_fetch_assoc($device_res)) {
        if ($dev_row['status'] === 'REVOKED') {
            api_audit('SYNC_REJECTED_REVOKED', ['device_uuid' => $device_uuid]);
            echo json_encode([
                'success' => false,
                'code'    => 'DEVICE_REVOKED',
                'message' => 'Device status is REVOKED. Sync rejected.'
            ]);
            exit();
        }
        // Update last_sync_at
        mysqli_query($conn, "UPDATE devices SET last_sync_at = NOW() WHERE device_uuid = '" . mysqli_real_escape_string($conn, $device_uuid) . "'");
    }
    mysqli_stmt_close($device_stmt);
}

foreach ($events as $event) {
    $event_type = trim($event['event_type'] ?? 'SALE');
    $sale_uuid  = trim($event['sale_uuid'] ?? $event['event_uuid'] ?? '');
    $event_uuid = trim($event['event_uuid'] ?? $sale_uuid);
    
    if (empty($sale_uuid) && empty($event_uuid)) {
        $results[] = [
            'event_uuid' => $event_uuid,
            'sale_uuid'  => $sale_uuid,
            'status'     => 'FAILED',
            'code'       => 'MISSING_EVENT_UUID',
            'message'    => 'Event UUID is required for idempotency'
        ];
        continue;
    }

    // Process Stock Adjustment Event
    if ($event_type === 'STOCK_ADJUST') {
        $chk_evt = mysqli_prepare($conn, "SELECT id FROM sync_events WHERE event_uuid = ? AND status = 'SYNCED'");
        mysqli_stmt_bind_param($chk_evt, 's', $event_uuid);
        mysqli_stmt_execute($chk_evt);
        $chk_res = mysqli_stmt_get_result($chk_evt);
        if ($existing_evt = mysqli_fetch_assoc($chk_res)) {
            mysqli_stmt_close($chk_evt);
            $results[] = [
                'event_uuid' => $event_uuid,
                'status'     => 'ALREADY_PROCESSED',
                'message'    => 'Stock adjustment already processed'
            ];
            continue;
        }
        mysqli_stmt_close($chk_evt);

        $inv_id    = intval($event['inventory_id'] ?? 0);
        $new_qty   = floatval($event['new_quantity'] ?? 0);
        $reason    = trim($event['reason'] ?? 'Offline Stock Adjustment');
        $b_id      = intval($event['branch_id'] ?? 1);
        $s_name    = trim($event['seller_name'] ?? 'Seller');
        $s_id      = intval($event['seller_id'] ?? 0);

        mysqli_begin_transaction($conn);
        try {
            $upd_stk = mysqli_prepare($conn, "UPDATE seller_inventory SET current_stock = ?, last_updated = NOW() WHERE id = ? AND branch_id = ?");
            mysqli_stmt_bind_param($upd_stk, 'dii', $new_qty, $inv_id, $b_id);
            mysqli_stmt_execute($upd_stk);
            mysqli_stmt_close($upd_stk);

            $ins_log = mysqli_prepare($conn, "INSERT INTO stock_logs (seller_id, branch_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes) VALUES (?, ?, ?, ?, ?, 'pcs', 'STOCK_ADJUST_OFFLINE_SYNC', ?, NOW(), ?)");
            $item_name_str = trim($event['item_name'] ?? 'Item');
            mysqli_stmt_bind_param($ins_log, 'iissdss', $s_id, $b_id, $s_name, $item_name_str, $new_qty, $s_name, $reason);
            mysqli_stmt_execute($ins_log);
            mysqli_stmt_close($ins_log);

            $sync_log = mysqli_prepare($conn, "INSERT INTO sync_events (event_uuid, sale_uuid, device_uuid, event_type, payload, status) VALUES (?, ?, ?, 'STOCK_ADJUST', ?, 'SYNCED') ON DUPLICATE KEY UPDATE status='SYNCED'");
            $payload_json = json_encode($event);
            mysqli_stmt_bind_param($sync_log, 'ssss', $event_uuid, $event_uuid, $device_uuid, $payload_json);
            mysqli_stmt_execute($sync_log);
            mysqli_stmt_close($sync_log);

            mysqli_commit($conn);

            $results[] = [
                'event_uuid' => $event_uuid,
                'status'     => 'SYNCED',
                'message'    => 'Stock adjustment synchronized successfully'
            ];
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $results[] = [
                'event_uuid' => $event_uuid,
                'status'     => 'FAILED',
                'code'       => 'SERVER_ERROR',
                'message'    => $e->getMessage()
            ];
        }
        continue;
    }

    // REQUIREMENT #9: IDEMPOTENCY CHECK
    // Check if sale_uuid has already been saved on server
    $check_stmt = mysqli_prepare($conn, "SELECT id, transaction_date FROM transactions WHERE sale_uuid = ?");
    mysqli_stmt_bind_param($check_stmt, 's', $sale_uuid);
    mysqli_stmt_execute($check_stmt);
    $check_res = mysqli_stmt_get_result($check_stmt);
    if ($existing_tx = mysqli_fetch_assoc($check_res)) {
        mysqli_stmt_close($check_stmt);
        $results[] = [
            'event_uuid'     => $event_uuid,
            'sale_uuid'      => $sale_uuid,
            'transaction_id' => $existing_tx['id'],
            'status'         => 'ALREADY_PROCESSED',
            'message'        => 'Sale already processed on server'
        ];
        continue;
    }
    mysqli_stmt_close($check_stmt);

    // Process Sale Event
    $seller_id      = intval($event['seller_id'] ?? ($_SESSION['user_id'] ?? 0));
    $seller_name    = trim($event['seller_name'] ?? ($_SESSION['full_name'] ?? 'Seller'));
    $branch_id      = intval($event['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
    $paid_amount    = floatval($event['paid_amount'] ?? 0);
    $payment_method = trim($event['payment_method'] ?? 'cash');
    $created_local  = !empty($event['created_locally_at']) ? date('Y-m-d H:i:s', strtotime($event['created_locally_at'])) : date('Y-m-d H:i:s');
    $items          = $event['items'] ?? [];

    if (empty($items) || !is_array($items) || $branch_id <= 0) {
        $results[] = [
            'event_uuid' => $event_uuid,
            'sale_uuid'  => $sale_uuid,
            'status'     => 'FAILED',
            'code'       => 'INVALID_EVENT_DATA',
            'message'    => 'Empty items list or invalid branch ID'
        ];
        continue;
    }

    // Start DB Transaction for this single sale
    mysqli_begin_transaction($conn);

    try {
        $validated_items = [];
        $server_total = 0.0;
        $conflict_detected = false;
        $conflict_item = null;

        // PASS 1: Validate prices & lock inventory with FOR UPDATE
        foreach ($items as $item) {
            $product_id = intval($item['id'] ?? 0);
            $quantity   = floatval($item['quantity'] ?? 0);
            $item_name  = trim($item['name'] ?? '');

            if ($quantity <= 0) continue;

            // Get authoritative product price from server DB (Requirement #38)
            $p_stmt = mysqli_prepare($conn, "SELECT id, name, unit_price FROM products WHERE (id = ? OR name = ?) AND branch_id = ? AND (is_active IS NULL OR is_active = 1) LIMIT 1");
            mysqli_stmt_bind_param($p_stmt, 'isi', $product_id, $item_name, $branch_id);
            mysqli_stmt_execute($p_stmt);
            $prod_res = mysqli_stmt_get_result($p_stmt);
            $product  = mysqli_fetch_assoc($prod_res);
            mysqli_stmt_close($p_stmt);

            $price = ($product && floatval($product['unit_price']) > 0) ? floatval($product['unit_price']) : floatval($item['price'] ?? 0);
            $item_id = $product ? intval($product['id']) : $product_id;
            $name_to_use = ($product && !empty($product['name'])) ? $product['name'] : (!empty($item_name) ? $item_name : 'Item');

            // Lock inventory row FOR UPDATE (Requirement #17)
            $inv_stmt = mysqli_prepare($conn, "SELECT id, current_stock FROM seller_inventory WHERE (item_name = ? OR id = ?) AND branch_id = ? LIMIT 1 FOR UPDATE");
            mysqli_stmt_bind_param($inv_stmt, 'sii', $name_to_use, $item_id, $branch_id);
            mysqli_stmt_execute($inv_stmt);
            $inv_res = mysqli_stmt_get_result($inv_stmt);
            $inv_row = mysqli_fetch_assoc($inv_res);
            mysqli_stmt_close($inv_stmt);

            $avail_stock = $inv_row ? floatval($inv_row['current_stock']) : 0.0;

            // Check if stock is sufficient
            if ($avail_stock < $quantity) {
                $conflict_detected = true;
                $conflict_item = [
                    'product_id'    => $item_id,
                    'product_name'  => $name_to_use,
                    'requested_qty' => $quantity,
                    'available_qty' => $avail_stock
                ];
                break;
            }

            $subtotal = round($price * $quantity, 2);
            $server_total += $subtotal;
            $validated_items[] = [
                'id'           => $item_id,
                'name'         => $name_to_use,
                'quantity'     => $quantity,
                'price'        => $price,
                'subtotal'     => $subtotal,
                'stock_row_id' => $inv_row['id'] ?? 0
            ];
        }

        // Handle Conflict case
        if ($conflict_detected) {
            mysqli_rollback($conn);

            // Log into sync_conflicts table for Admin review (Requirement #28)
            $conflict_uuid = 'CONF-' . bin2hex(random_bytes(16));
            $conf_stmt = mysqli_prepare($conn, "INSERT INTO sync_conflicts (conflict_uuid, sale_uuid, device_uuid, seller_id, branch_id, product_id, product_name, requested_qty, available_qty, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'REQUIRES_REVIEW')");
            mysqli_stmt_bind_param($conf_stmt, 'sssiiisdd', 
                $conflict_uuid, $sale_uuid, $device_uuid, $seller_id, $branch_id, 
                $conflict_item['product_id'], $conflict_item['product_name'], 
                $conflict_item['requested_qty'], $conflict_item['available_qty']
            );
            mysqli_stmt_execute($conf_stmt);
            mysqli_stmt_close($conf_stmt);

            // Log event status as CONFLICT
            $log_stmt = mysqli_prepare($conn, "INSERT INTO sync_events (event_uuid, sale_uuid, device_uuid, payload, status, error_code, error_message) VALUES (?, ?, ?, ?, 'CONFLICT', 'INSUFFICIENT_STOCK', 'Stock depleted during offline period') ON DUPLICATE KEY UPDATE status='CONFLICT'");
            $payload_json = json_encode($event);
            mysqli_stmt_bind_param($log_stmt, 'ssss', $event_uuid, $sale_uuid, $device_uuid, $payload_json);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);

            $results[] = [
                'event_uuid'    => $event_uuid,
                'sale_uuid'     => $sale_uuid,
                'status'        => 'CONFLICT',
                'conflict_uuid' => $conflict_uuid,
                'message'       => "Stock conflict for '{$conflict_item['product_name']}'. Sent to Conflict Center."
            ];
            continue;
        }

        // Complete Sale Insertion
        $total = round($server_total, 2);
        // Customers pay physically — if paid_amount was not recorded, treat as exact payment
        if ($paid_amount <= 0) {
            $paid_amount = $total;
        }
        $change = max(0, round($paid_amount - $total, 2));
        $now_server = date('Y-m-d H:i:s');

        $ins_tx = mysqli_prepare($conn, "INSERT INTO transactions (sale_uuid, device_uuid, total_amount, paid_amount, change_amount, payment_method, seller_id, seller_name, branch_id, transaction_date, created_locally_at, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($ins_tx, 'ssdddsisisss', 
            $sale_uuid, $device_uuid, $total, $paid_amount, $change, 
            $payment_method, $seller_id, $seller_name, $branch_id, 
            $now_server, $created_local, $now_server
        );
        mysqli_stmt_execute($ins_tx);
        $transaction_id = mysqli_insert_id($conn);
        mysqli_stmt_close($ins_tx);

        foreach ($validated_items as $vItem) {
            // Insert item
            $ins_item = mysqli_prepare($conn, "INSERT INTO transaction_items (transaction_id, event_uuid, product_id, product_name, quantity, unit_price, subtotal, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins_item, 'isiidddi', 
                $transaction_id, $event_uuid, $vItem['id'], $vItem['name'], 
                $vItem['quantity'], $vItem['price'], $vItem['subtotal'], $branch_id
            );
            mysqli_stmt_execute($ins_item);
            mysqli_stmt_close($ins_item);

            // Deduct stock in seller_inventory
            if ($vItem['stock_row_id'] > 0) {
                $upd_stock = mysqli_prepare($conn, "UPDATE seller_inventory SET current_stock = current_stock - ?, last_updated = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($upd_stock, 'di', $vItem['quantity'], $vItem['stock_row_id']);
                mysqli_stmt_execute($upd_stock);
                mysqli_stmt_close($upd_stock);
            }

            // Log stock movement (Requirement #16)
            $notes = "Offline Sync Sale UUID: " . $sale_uuid;
            $ins_log = mysqli_prepare($conn, "INSERT INTO stock_logs (seller_id, branch_id, seller_name, item_name, quantity, unit, source, added_by, date_added, notes) VALUES (?, ?, ?, ?, ?, 'pcs', 'SALE_OFFLINE_SYNC', ?, NOW(), ?)");
            $neg_qty = -1 * $vItem['quantity'];
            mysqli_stmt_bind_param($ins_log, 'iissdss', $seller_id, $branch_id, $seller_name, $vItem['name'], $neg_qty, $seller_name, $notes);
            mysqli_stmt_execute($ins_log);
            mysqli_stmt_close($ins_log);
        }

        // Record sync_events
        $sync_log = mysqli_prepare($conn, "INSERT INTO sync_events (event_uuid, sale_uuid, device_uuid, event_type, payload, status) VALUES (?, ?, ?, 'SALE', ?, 'SYNCED') ON DUPLICATE KEY UPDATE status='SYNCED'");
        $payload_json = json_encode($event);
        mysqli_stmt_bind_param($sync_log, 'ssss', $event_uuid, $sale_uuid, $device_uuid, $payload_json);
        mysqli_stmt_execute($sync_log);
        mysqli_stmt_close($sync_log);

        // Audit log for SALE_SYNCED
        $audit_details = json_encode([
            'sale_uuid'      => $sale_uuid,
            'transaction_id' => $transaction_id,
            'device_uuid'    => $device_uuid,
            'total'          => $event['total'] ?? 0,
        ]);
        $audit_uid = $GLOBALS['api_user']['user_id'] ?? null;
        $audit_stmt = mysqli_prepare($conn,
            "INSERT INTO audit_logs (user_id, device_uuid, event_type, action, entity, entity_uuid, new_value, ip_address, created_at)
             VALUES (?, ?, 'SYNC', 'SALE_SYNCED', 'transaction', ?, ?, ?, NOW())"
        );
        $audit_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        mysqli_stmt_bind_param($audit_stmt, 'issss', $audit_uid, $device_uuid, $sale_uuid, $audit_details, $audit_ip);
        mysqli_stmt_execute($audit_stmt);
        mysqli_stmt_close($audit_stmt);

        mysqli_commit($conn);

        $results[] = [
            'event_uuid'     => $event_uuid,
            'sale_uuid'      => $sale_uuid,
            'transaction_id' => $transaction_id,
            'status'         => 'SYNCED',
            'message'        => 'Sale synchronized successfully'
        ];

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $results[] = [
            'event_uuid' => $event_uuid,
            'sale_uuid'  => $sale_uuid,
            'status'     => 'FAILED',
            'code'       => 'SERVER_ERROR',
            'message'    => $e->getMessage()
        ];
    }
}

// Summary stats
$synced_count   = count(array_filter($results, fn($r) => $r['status'] === 'SYNCED'));
$failed_count   = count(array_filter($results, fn($r) => $r['status'] === 'FAILED'));
$conflict_count = count(array_filter($results, fn($r) => $r['status'] === 'CONFLICT'));
$dupes_count    = count(array_filter($results, fn($r) => ($r['code'] ?? '') === 'ALREADY_PROCESSED'));

echo json_encode([
    'success'  => true,
    'results'  => $results,
    'summary'  => [
        'total'     => count($results),
        'synced'    => $synced_count,
        'failed'    => $failed_count,
        'conflicts' => $conflict_count,
        'duplicates'=> $dupes_count,
    ],
    'server_time' => date('c'),
]);

mysqli_close($conn);
