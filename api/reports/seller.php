<?php
/**
 * ALELTU — Seller Analytics Report
 * GET /api/reports/seller.php?branch_id=&date_from=&date_to=
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'UNAUTHORIZED']); exit; }

$branch_id  = (int)getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role'] ?? 'seller');
$date_from  = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to    = $_GET['date_to']   ?? date('Y-m-d');

// Per-seller performance
$stmt = mysqli_prepare($conn, "SELECT
    t.seller_id,
    u.full_name as seller_name,
    COUNT(DISTINCT t.id)                   as total_transactions,
    SUM(t.total_amount)                    as total_revenue,
    AVG(t.total_amount)                    as avg_sale_value,
    COUNT(DISTINCT DATE(t.transaction_date)) as days_active,
    SUM(CASE WHEN t.sale_uuid IS NOT NULL THEN 1 ELSE 0 END) as offline_sales,
    MIN(t.transaction_date)                as first_sale,
    MAX(t.transaction_date)                as last_sale
  FROM transactions t
  LEFT JOIN users u ON u.id = t.seller_id
  WHERE t.branch_id = ? AND DATE(t.transaction_date) BETWEEN ? AND ?
  GROUP BY t.seller_id, u.full_name
  ORDER BY total_revenue DESC");
mysqli_stmt_bind_param($stmt, 'iss', $branch_id, $date_from, $date_to);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$sellers = [];
while ($r = mysqli_fetch_assoc($res)) $sellers[] = $r;

// Top products per seller (top 5 overall)
$stmt2 = mysqli_prepare($conn, "SELECT
    ti.product_name,
    SUM(ti.quantity) as total_qty,
    SUM(ti.quantity * ti.unit_price) as total_revenue,
    COUNT(DISTINCT ti.transaction_id) as num_transactions
  FROM transaction_items ti
  JOIN transactions t ON t.id = ti.transaction_id
  WHERE t.branch_id = ? AND DATE(t.transaction_date) BETWEEN ? AND ?
  GROUP BY ti.product_name
  ORDER BY total_revenue DESC LIMIT 10");
mysqli_stmt_bind_param($stmt2, 'iss', $branch_id, $date_from, $date_to);
mysqli_stmt_execute($stmt2);
$res2 = mysqli_stmt_get_result($stmt2);
$top_products = [];
while ($r = mysqli_fetch_assoc($res2)) $top_products[] = $r;

// Daily revenue trend
$stmt3 = mysqli_prepare($conn, "SELECT DATE(t.transaction_date) as sale_date,
    COUNT(*) as num_sales, SUM(t.total_amount) as revenue
  FROM transactions t
  WHERE t.branch_id = ? AND DATE(t.transaction_date) BETWEEN ? AND ?
  GROUP BY DATE(t.transaction_date) ORDER BY sale_date ASC");
mysqli_stmt_bind_param($stmt3, 'iss', $branch_id, $date_from, $date_to);
mysqli_stmt_execute($stmt3);
$res3 = mysqli_stmt_get_result($stmt3);
$daily_trend = [];
while ($r = mysqli_fetch_assoc($res3)) $daily_trend[] = $r;

echo json_encode(['success'=>true,'sellers'=>$sellers,'top_products'=>$top_products,'daily_trend'=>$daily_trend,
    'period'=>['from'=>$date_from,'to'=>$date_to],'branch_id'=>$branch_id]);
