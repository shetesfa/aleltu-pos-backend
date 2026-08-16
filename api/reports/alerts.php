<?php
/**
 * ALELTU — Business Alerts Engine
 * GET /api/reports/alerts.php
 * Returns active business alerts: low stock, sync queue, device offline, conflicts.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'UNAUTHORIZED']); exit; }

$branch_id = (int)getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role'] ?? 'seller');
$alerts = [];

// 1. Low stock alerts
$res = mysqli_query($conn, "SELECT si.item_name, si.quantity, si.unit, COALESCE(si.low_stock_alert,5) as threshold
    FROM seller_inventory si WHERE si.branch_id=$branch_id AND si.quantity <= COALESCE(si.low_stock_alert,5) ORDER BY si.quantity ASC LIMIT 20");
while ($r = mysqli_fetch_assoc($res)) {
    $alerts[] = ['type'=>'LOW_STOCK','level'=>$r['quantity']<=0?'CRITICAL':'WARNING',
        'title'=>'Low Stock: '.$r['item_name'],
        'message'=>'Only '.$r['quantity'].' '.$r['unit'].' remaining (threshold: '.$r['threshold'].')',
        'data'=>$r];
}

// 2. Sync conflicts
$res2 = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM sync_conflicts WHERE status='PENDING'");
$r2 = mysqli_fetch_assoc($res2);
if ((int)$r2['cnt'] > 0) {
    $alerts[] = ['type'=>'SYNC_CONFLICTS','level'=>'WARNING',
        'title'  => $r2['cnt'].' Unresolved Sync Conflicts',
        'message'=> 'Review and resolve conflicts in the Conflict Center.',
        'data'   => ['count'=>(int)$r2['cnt']]];
}

// 3. Devices offline > 2 hours
$res3 = mysqli_query($conn, "SELECT device_name, device_uuid, last_seen_at,
    TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) as mins_offline
    FROM devices WHERE branch_id=$branch_id AND status='ACTIVE'
    AND last_seen_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) > 120");
while ($r = mysqli_fetch_assoc($res3)) {
    $h = round($r['mins_offline']/60, 1);
    $alerts[] = ['type'=>'DEVICE_OFFLINE','level'=>'WARNING',
        'title'  => 'Device Offline: '.$r['device_name'],
        'message'=> "Device has been offline for {$h} hours.",
        'data'   => $r];
}

// 4. Large sync queue (failed events)
$res4 = mysqli_query($conn, "SELECT device_uuid, COUNT(*) as cnt FROM sync_events
    WHERE status='FAILED' GROUP BY device_uuid HAVING cnt >= 10");
while ($r = mysqli_fetch_assoc($res4)) {
    $alerts[] = ['type'=>'SYNC_QUEUE_GROWING','level'=>'WARNING',
        'title'  => 'Large Sync Queue',
        'message'=> $r['cnt'].' failed events pending for device '.substr($r['device_uuid'],0,12).'…',
        'data'   => $r];
}

// 5. Zero-stock products with recent sales
$res5 = mysqli_query($conn, "SELECT si.item_name, si.quantity FROM seller_inventory si
    WHERE si.branch_id=$branch_id AND si.quantity <= 0 LIMIT 10");
while ($r = mysqli_fetch_assoc($res5)) {
    $alerts[] = ['type'=>'OUT_OF_STOCK','level'=>'CRITICAL',
        'title'  => 'Out of Stock: '.$r['item_name'],
        'message'=> 'This product has zero remaining stock.',
        'data'   => $r];
}

echo json_encode(['success'=>true,'alerts'=>$alerts,'count'=>count($alerts),'generated_at'=>date('c')]);
