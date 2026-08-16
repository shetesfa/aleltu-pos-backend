<?php
session_start();
require_once 'config.php';

// Only admin can run this
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("Access denied!");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $csrf = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    echo '<h2>Restore Pending Stock Transfers</h2>';
    echo '<p>This will reset approved transfers to pending and recreate their notifications.</p>';
    echo '<form method="POST"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit" onclick="return confirm(\'Continue with this maintenance action?\')">Run restoration</button></form>';
    exit();
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

echo "<h2>Restoring Pending Stock Transfers</h2>";

// 1. First, reset all stock_transfers to pending if they were auto-accepted
$reset_query = "UPDATE stock_transfers SET status = 'pending' WHERE status = 'approved'";
if(mysqli_query($conn, $reset_query)) {
    echo "<p>✅ Reset all stock transfers to 'pending' status</p>";
} else {
    echo "<p>❌ Error resetting: " . mysqli_error($conn) . "</p>";
}

// 2. Delete old auto-generated notifications
// NOTE: notifications.type only accepts stock_transfer/system/alert/info —
// 'stock' is not a valid value, so this delete (and the insert below) never
// actually matched/succeeded before. Fixed to the real enum value.
$delete_notifications = "DELETE FROM notifications WHERE type = 'stock_transfer' AND title LIKE '%New Stock Received%'";
if(mysqli_query($conn, $delete_notifications)) {
    echo "<p>✅ Deleted old auto-generated notifications</p>";
} else {
    echo "<p>❌ Error deleting notifications: " . mysqli_error($conn) . "</p>";
}

// 3. Create new notifications for all pending stock transfers
$pending_transfers = "SELECT * FROM stock_transfers WHERE status = 'pending'";
$result = mysqli_query($conn, $pending_transfers);

$notification_count = 0;
while($transfer = mysqli_fetch_assoc($result)) {
    $seller_id = $transfer['seller_id'];
    $transfer_id = $transfer['id'];
    $product_name = $transfer['product_name'];
    $quantity = $transfer['quantity'];
    $unit = isset($transfer['unit']) ? $transfer['unit'] : 'pcs';
    $admin_name = $transfer['transferred_by'];
    
    // Check if notification already exists
    $check_notif = "SELECT id FROM notifications WHERE related_id = '$transfer_id' AND user_id = '$seller_id' AND type = 'stock_transfer'";
    $check_result = mysqli_query($conn, $check_notif);
    
    if(mysqli_num_rows($check_result) == 0) {
        $notification_title = "📦 New Stock Received";
        $notification_message = "You have received $quantity $unit of $product_name from $admin_name. Please approve to add to inventory.";
        
        $insert_notif = "INSERT INTO notifications (user_id, title, message, type, related_id, is_read, created_at) 
                        VALUES ('$seller_id', '$notification_title', '$notification_message', 'stock_transfer', '$transfer_id', 0, NOW())";
        
        if(mysqli_query($conn, $insert_notif)) {
            $notification_count++;
        }
    }
}

echo "<p>✅ Created $notification_count new notifications for pending stock transfers</p>";

// 4. REMOVED: this used to run
//    DELETE FROM seller_inventory WHERE seller_id IN (SELECT seller_id FROM stock_transfers WHERE status = 'pending')
//    which deletes EVERY inventory row (every item, not just the transferred
//    one) for ANY seller who has any pending transfer at all — a real risk of
//    wiping a seller's whole stock by accident. There is also no "auto-added"
//    stock to clean up under the current shared per-branch stock pool model
//    (stock is only added when a transfer is approved, never before), so this
//    step no longer has anything safe or correct to do. It has been removed
//    rather than fixed, since there is no equivalent action that matches how
//    inventory actually works now.
echo "<p>ℹ️ Skipped inventory cleanup step — not needed under the current stock model, and the old version risked deleting a seller's entire inventory by mistake.</p>";

echo "<h3>✅ Restoration complete! All stock transfers are now pending approval.</h3>";
echo "<p><a href='seller_receive_stock.php'>Go back to stock receiving page</a></p>";
?>
