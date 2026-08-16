<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}
$branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role'] ?? 'seller');

// FIXED: this used to default to seller_id = 1 when no seller_id was passed,
// but all real stock is kept under the shared seller_id = 0 branch pool (see
// save_transaction.php / seller_receive_stock.php), so the old default always
// returned an empty list. If a specific seller_id is requested, honor it;
// otherwise return the branch's shared inventory.
if (isset($_GET['seller_id'])) {
    $seller_id = intval($_GET['seller_id']);
    if (($_SESSION['role'] ?? '') !== 'super_admin' && $seller_id !== (int)$_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit();
    }
    $stmt = mysqli_prepare($conn, "SELECT * FROM seller_inventory WHERE seller_id = ? AND branch_id = ? ORDER BY item_name");
    mysqli_stmt_bind_param($stmt, 'ii', $seller_id, $branch_id);
} else {
    $stmt = mysqli_prepare($conn, "SELECT * FROM seller_inventory WHERE branch_id = ? ORDER BY item_name");
    mysqli_stmt_bind_param($stmt, 'i', $branch_id);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$inventory = [];
while($row = mysqli_fetch_assoc($result)) {
    $inventory[] = $row;
}
mysqli_free_result($result);
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'inventory' => $inventory
]);

mysqli_close($conn);
?>
