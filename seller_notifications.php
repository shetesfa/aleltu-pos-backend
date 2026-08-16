<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'seller') {
    header("Location: index.php");
    exit();
}

require_once 'config.php';
$seller_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }

    $notification_action = $_POST['notification_action'] ?? '';
    $notification_id = (int)($_POST['notification_id'] ?? 0);
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);

    if ($notification_action === 'mark_read' && $notification_id > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $notification_id, $seller_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } elseif ($notification_action === 'mark_all_read') {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $seller_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } elseif (in_array($notification_action, ['approve', 'reject'], true) && $transfer_id > 0) {
        mysqli_begin_transaction($conn);
        try {
            $stmt = mysqli_prepare($conn, "SELECT product_name, quantity, unit, branch_id FROM stock_transfers WHERE id = ? AND seller_id = ? AND status = 'pending' FOR UPDATE");
            mysqli_stmt_bind_param($stmt, 'ii', $transfer_id, $seller_id);
            mysqli_stmt_execute($stmt);
            $transfer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$transfer) {
                throw new RuntimeException('Transfer is not available.');
            }

            $status = $notification_action === 'approve' ? 'approved' : 'rejected';
            $stmt = mysqli_prepare($conn, "UPDATE stock_transfers SET status = ?, approved_at = CASE WHEN ? = 'approved' THEN NOW() ELSE approved_at END WHERE id = ? AND seller_id = ? AND status = 'pending'");
            mysqli_stmt_bind_param($stmt, 'ssii', $status, $status, $transfer_id, $seller_id);
            if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
                mysqli_stmt_close($stmt);
                throw new RuntimeException('Transfer update failed.');
            }
            mysqli_stmt_close($stmt);

            if ($notification_action === 'approve') {
                $stmt = mysqli_prepare($conn, "SELECT id FROM seller_inventory WHERE item_name = ? AND branch_id = ? LIMIT 1 FOR UPDATE");
                mysqli_stmt_bind_param($stmt, 'si', $transfer['product_name'], $transfer['branch_id']);
                mysqli_stmt_execute($stmt);
                $inventory = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if ($inventory) {
                    $stmt = mysqli_prepare($conn, "UPDATE seller_inventory SET current_stock = current_stock + ?, unit = ?, last_updated = NOW() WHERE id = ?");
                    mysqli_stmt_bind_param($stmt, 'dsi', $transfer['quantity'], $transfer['unit'], $inventory['id']);
                } else {
                    $shared_seller_id = 0;
                    $stmt = mysqli_prepare($conn, "INSERT INTO seller_inventory (seller_id, item_name, current_stock, unit, branch_id, last_updated) VALUES (?, ?, ?, ?, ?, NOW())");
                    mysqli_stmt_bind_param($stmt, 'isdsi', $shared_seller_id, $transfer['product_name'], $transfer['quantity'], $transfer['unit'], $transfer['branch_id']);
                }
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    throw new RuntimeException('Inventory update failed.');
                }
                mysqli_stmt_close($stmt);
            }
            mysqli_commit($conn);
            $success = $notification_action === 'approve' ? 'Stock approved and added to your inventory.' : 'Stock transfer rejected.';
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('seller_notifications.php transfer action failed: ' . $e->getMessage());
            $error = 'The stock transfer could not be updated.';
        }
    }
}

// Mark as read if requested
if(false && isset($_GET['mark_read'])) {
    $notif_id = mysqli_real_escape_string($conn, $_GET['mark_read']);
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = '$notif_id' AND user_id = '$seller_id'");
}

// Handle stock approval/rejection
if(false && isset($_GET['action']) && isset($_GET['transfer_id'])) {
    $transfer_id = mysqli_real_escape_string($conn, $_GET['transfer_id']);
    $action = $_GET['action'];
    
    if($action == 'approve') {
        // NOTE: stock_transfers has no "to_user_id" column — the receiving
        // seller is identified by its own "seller_id" column.
        $transfer_query = "SELECT * FROM stock_transfers WHERE id = '$transfer_id' AND seller_id = '$seller_id' AND status = 'pending'";
        $transfer_result = mysqli_query($conn, $transfer_query);
        
        if($transfer_result && mysqli_num_rows($transfer_result) > 0) {
            $transfer = mysqli_fetch_assoc($transfer_result);
            $product_name = $transfer['product_name'];
            $quantity = $transfer['quantity'];
            $unit = $transfer['unit'];
            $transfer_branch_id = intval($transfer['branch_id']);
            
            // Update stock_transfers status
            mysqli_query($conn, "UPDATE stock_transfers SET status = 'approved', approved_at = NOW() WHERE id = '$transfer_id'");
            
            // Add to the shared branch+item stock pool.
            // NOTE: seller_inventory uses "item_name" (not "product_name") and
            // has no separate "pending_stock" column — stock is one shared pot
            // per item per branch, the same model used everywhere else in the app.
            $inventory_check = "SELECT id FROM seller_inventory WHERE item_name = '$product_name' AND branch_id = $transfer_branch_id LIMIT 1";
            $inventory_result = mysqli_query($conn, $inventory_check);
            
            if($inventory_result && mysqli_num_rows($inventory_result) > 0) {
                mysqli_query($conn, 
                    "UPDATE seller_inventory 
                     SET current_stock = current_stock + $quantity,
                         unit = '$unit',
                         last_updated = NOW()
                     WHERE item_name = '$product_name' AND branch_id = $transfer_branch_id
                     LIMIT 1"
                );
            } else {
                mysqli_query($conn, 
                    "INSERT INTO seller_inventory (seller_id, item_name, current_stock, unit, branch_id, last_updated) 
                     VALUES (0, '$product_name', $quantity, '$unit', $transfer_branch_id, NOW())"
                );
            }
            
            // NOTE: a confirmation notification back to the sending admin was
            // removed here — stock_transfers does not store the sender's
            // numeric user id (only "transferred_by", a plain text name), so
            // there is no reliable user_id to notify.
            
            $success = "✅ Stock approved and added to your inventory!";
        }
    } elseif($action == 'reject') {
        // NOTE: stock_transfers has no "to_user_id" column — scope the update
        // to this seller so a seller can't reject someone else's transfer.
        mysqli_query($conn, "UPDATE stock_transfers SET status = 'rejected' WHERE id = '$transfer_id' AND seller_id = '$seller_id'");
        
        // Nothing to undo on seller_inventory: under the shared stock-pool
        // model, stock is only ever added on approval (above), never held in
        // a separate "pending" bucket beforehand — so rejecting a transfer
        // never touched seller_inventory in the first place.
        
        $success = "❌ Stock transfer rejected!";
    }
}

// Get notifications
$notifications_query = mysqli_query($conn, 
    "SELECT n.*, st.product_name, st.quantity, st.unit, st.status as transfer_status
     FROM notifications n
     LEFT JOIN stock_transfers st ON n.related_id = st.id
     WHERE n.user_id = '$seller_id'
     ORDER BY n.created_at DESC"
);

// Get unread count
$unread_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = '$seller_id' AND is_read = 0";
$unread_result = mysqli_query($conn, $unread_query);
$unread_count = mysqli_fetch_assoc($unread_result)['count'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Notifications - Aleltu POS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
        }
        
        .container {
            width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .header {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 24px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .notification-badge {
            background: #ef4444;
            color: white;
            font-size: 12px;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 20px;
        }
        
        .back-btn {
            padding: 10px 15px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            min-height: 44px;
        }
        
        .back-btn:hover {
            background: #764ba2;
            transform: translateY(-2px);
        }
        
        .notification-item {
            background: #f8f9ff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
            transition: all 0.3s;
        }
        
        .notification-item.unread {
            border-left: 4px solid #ef4444;
            background: #fff5f5;
        }
        
        .notification-header {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 10px;
        }
        
        .notification-title {
            font-size: 16px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .notification-time {
            color: #666;
            font-size: 12px;
        }
        
        .notification-message {
            color: #4a5568;
            line-height: 1.5;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .stock-info {
            background: #e2e8f0;
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .stock-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .stock-item i {
            color: #667eea;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 15px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
            min-height: 44px;
            font-size: 14px;
        }
        
        .btn-approve {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-reject {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-read {
            background: #6b7280;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .pending { background: #fef3c7; color: #d97706; }
        .approved { background: #dcfce7; color: #16a34a; }
        .rejected { background: #fee2e2; color: #dc2626; }
        
        .empty-state {
            text-align: center;
            padding: 40px 15px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }
        
        .success-message {
            background: #dcfce7;
            border: 2px solid #86efac;
            color: #16a34a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
        }
        
        .mark-all-btn {
            background: #6b7280;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            min-height: 44px;
            font-size: 14px;
        }
        
        @media (min-width: 600px) {
            body { padding: 20px; }
            .container { 
                padding: 30px; 
                border-radius: 20px; 
                box-shadow: 0 20px 40px rgba(0,0,0,0.2); 
            }
            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
            }
            .header h1 { font-size: 32px; gap: 15px; }
            .notification-badge { font-size: 14px; padding: 5px 12px; margin-left: 10px; }
            .header-actions { 
                flex-direction: row; 
                width: auto; 
                gap: 15px;
                align-items: center;
            }
            .mark-all-btn, .back-btn { 
                padding: 12px 25px; 
                justify-content: flex-start;
                font-size: 16px;
            }
            .notification-item { padding: 25px; margin-bottom: 20px; border-radius: 15px; }
            .notification-header { 
                flex-direction: row; 
                justify-content: space-between; 
                align-items: center;
                margin-bottom: 15px;
            }
            .notification-title { font-size: 18px; }
            .notification-time { font-size: 14px; }
            .notification-message { line-height: 1.6; margin-bottom: 15px; font-size: 16px; }
            .stock-info {
                flex-direction: row;
                align-items: center;
                gap: 20px;
                padding: 15px;
                border-radius: 10px;
                margin: 15px 0;
            }
            .stock-item { gap: 10px; font-size: 16px; }
            .action-buttons {
                flex-direction: row;
                gap: 15px;
                margin-top: 20px;
            }
            .btn {
                padding: 12px 25px;
                font-size: 16px;
            }
            .status-badge { padding: 6px 15px; font-size: 12px; }
            .empty-state { padding: 60px 20px; }
            .empty-state i { font-size: 64px; margin-bottom: 20px; }
            .success-message { padding: 15px; border-radius: 10px; margin-bottom: 20px; gap: 15px; font-size: 16px; }
        }
        
        @media (min-width: 900px) {
            .container { max-width: 1000px; }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-bell"></i> My Notifications
                <?php if($unread_count > 0): ?>
                <span class="notification-badge"><?php echo $unread_count; ?> new</span>
                <?php endif; ?>
            </h1>
            <div class="header-actions">
                <?php if($unread_count > 0): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                    <input type="hidden" name="notification_action" value="mark_all_read">
                    <button type="submit" class="mark-all-btn">
                    <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                </form>
                <?php endif; ?>
                <a href="seller_pos.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to POS
                </a>
            </div>
        </div>
        
        <?php if(isset($success)): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
        <?php endif; ?>
        
        <?php if(mysqli_num_rows($notifications_query) > 0): ?>
            <?php while($notification = mysqli_fetch_assoc($notifications_query)): ?>
            <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                <div class="notification-header">
                    <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                    <div class="notification-time">
                        <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?>
                    </div>
                </div>
                
                <div class="notification-message">
                    <?php echo htmlspecialchars($notification['message']); ?>
                </div>
                
                <?php if($notification['type'] == 'stock_transfer'): ?>
                    <?php if($notification['transfer_status'] == 'pending'): ?>
                    <div class="stock-info">
                        <div class="stock-item">
                            <i class="fas fa-box"></i>
                            <span><strong>Product:</strong> <?php echo htmlspecialchars($notification['product_name']); ?></span>
                        </div>
                        <div class="stock-item">
                            <i class="fas fa-balance-scale"></i>
                            <span><strong>Quantity:</strong> <?php echo $notification['quantity']; ?> <?php echo $notification['unit']; ?></span>
                        </div>
                        <div class="stock-item">
                            <span class="status-badge pending">Pending Approval</span>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <form method="POST" style="display:inline" onsubmit="return confirm('Approve this stock transfer?')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                            <input type="hidden" name="notification_action" value="approve">
                            <input type="hidden" name="transfer_id" value="<?php echo (int)$notification['related_id']; ?>">
                            <button type="submit" class="btn btn-approve">
                            <i class="fas fa-check"></i> Approve Stock
                            </button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Reject this stock transfer?')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                            <input type="hidden" name="notification_action" value="reject">
                            <input type="hidden" name="transfer_id" value="<?php echo (int)$notification['related_id']; ?>">
                            <button type="submit" class="btn btn-reject">
                            <i class="fas fa-times"></i> Reject
                            </button>
                        </form>
                        <?php if(!$notification['is_read']): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
                            <input type="hidden" name="notification_action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?php echo (int)$notification['id']; ?>">
                            <button type="submit" class="btn btn-read">
                            <i class="fas fa-check-double"></i> Mark as Read
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php elseif($notification['transfer_status'] == 'approved'): ?>
                    <div class="stock-info">
                        <span class="status-badge approved">Approved ✓</span>
                        <span>Added to your inventory: <?php echo $notification['quantity']; ?> <?php echo $notification['unit']; ?> of <?php echo htmlspecialchars($notification['product_name']); ?></span>
                    </div>
                    <?php elseif($notification['transfer_status'] == 'rejected'): ?>
                    <div class="stock-info">
                        <span class="status-badge rejected">Rejected ✗</span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h2>No Notifications</h2>
                <p>You don't have any notifications yet.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // FIX: Removed auto full-page reload - was causing server overload.
        // Notifications update when user manually reloads or navigates here.
    </script>
</body>
</html>
<?php 
// Handle mark all as read
if(false && isset($_GET['mark_all_read'])) {
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = '$seller_id'");
    header("Location: seller_notifications.php");
    exit();
}
mysqli_close($conn); 
?>
