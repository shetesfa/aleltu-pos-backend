<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($branch_id <= 0 && isset($_SESSION['user_id'])) {
    $branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role'] ?? 'seller');
}

if ($branch_id <= 0) {
    echo json_encode(['error' => 'No valid branch ID']);
    exit();
}

// Single efficient query: GROUP BY p.id (which determines p.name and p.unit_price)
// MAX(si.unit) satisfies ONLY_FULL_GROUP_BY without changing results
$products_query = "
    SELECT
        p.id,
        p.name,
        p.unit_price,
        COALESCE(SUM(si.current_stock), 0)  AS current_stock,
        COALESCE(MAX(si.unit), 'pcs')        AS unit
    FROM products p
    LEFT JOIN seller_inventory si
           ON si.item_name = p.name
          AND si.branch_id = $branch_id
    WHERE p.branch_id = $branch_id
      AND (p.is_active IS NULL OR p.is_active = 1)
    GROUP BY p.id, p.name, p.unit_price
    ORDER BY p.name
";

$result = mysqli_query($conn, $products_query);

if (!$result) {
    echo json_encode(['error' => mysqli_error($conn)]);
    exit();
}

$products = [];
while ($row = mysqli_fetch_assoc($result)) {
    $products[] = [
        'id'            => intval($row['id']),
        'name'          => $row['name'],
        'unit_price'    => floatval($row['unit_price']),
        'current_stock' => floatval($row['current_stock']),
        'unit'          => $row['unit'] ?: 'pcs',
    ];
}

mysqli_free_result($result);
echo json_encode($products);
mysqli_close($conn);
?>
