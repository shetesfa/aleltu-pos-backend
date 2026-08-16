<?php
/**
 * ALELTU POS — Fetch Active Offline Rules API (Hardened)
 * GET /api/offline-rules/get.php?branch_id=N
 */
declare(strict_types=1);
session_start();
require_once '../../config.php';
require_once '../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

api_require_auth();

$branch_id = (int)($_GET['branch_id'] ?? ($GLOBALS['api_user']['branch_id'] ?? 0));
if ($branch_id <= 0) {
    $branch_id = (int)getCurrentBranchId($conn, $GLOBALS['api_user']['user_id'], $GLOBALS['api_user']['role']);
}

$stmt = mysqli_prepare($conn,
    "SELECT id, rule_name, rule_scope, target_id, max_offline_qty, allow_offline,
            priority, start_date, end_date, start_time, end_time, day_of_week, is_holiday, is_active
     FROM offline_rules
     WHERE (branch_id = ? OR branch_id IS NULL) AND is_active = 1
     ORDER BY priority ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rules = [];
while ($row = mysqli_fetch_assoc($res)) {
    $rules[] = [
        'id'              => (int)$row['id'],
        'rule_name'       => $row['rule_name'],
        'rule_scope'      => $row['rule_scope'],
        'target_id'       => $row['target_id'],
        'max_offline_qty' => (float)$row['max_offline_qty'],
        'allow_offline'   => (int)$row['allow_offline'],
        'priority'        => (int)$row['priority'],
        'start_date'      => $row['start_date'],
        'end_date'        => $row['end_date'],
        'start_time'      => $row['start_time'],
        'end_time'        => $row['end_time'],
        'day_of_week'     => $row['day_of_week'],
        'is_holiday'      => (int)$row['is_holiday'],
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'success'    => true,
    'rules'      => $rules,
    'branch_id'  => $branch_id,
    'count'      => count($rules),
    'generated_at' => date('c'),
]);

mysqli_close($conn);
