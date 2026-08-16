<?php
/**
 * ALELTU POS — Device Registration API
 */
session_start();
require_once '../../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['device_uuid'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid device payload']);
    exit();
}

$device_uuid = trim($input['device_uuid']);
$device_name = trim($input['device_name'] ?? 'POS Terminal');
$branch_id   = intval($input['branch_id'] ?? 1);
$app_version = trim($input['app_version'] ?? '1.0.0');
$user_id     = $_SESSION['user_id'] ?? NULL;

// Check existing device status
$stmt = mysqli_prepare($conn, "SELECT id, status FROM devices WHERE device_uuid = ?");
mysqli_stmt_bind_param($stmt, 's', $device_uuid);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$device = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if ($device) {
    if ($device['status'] === 'REVOKED') {
        echo json_encode(['success' => false, 'status' => 'REVOKED', 'message' => 'Device authorization revoked']);
        exit();
    }
    // Update heartbeat
    $upd = mysqli_prepare($conn, "UPDATE devices SET last_seen_at = NOW(), last_sync_at = NOW(), app_version = ?, branch_id = ? WHERE device_uuid = ?");
    mysqli_stmt_bind_param($upd, 'sis', $app_version, $branch_id, $device_uuid);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    echo json_encode(['success' => true, 'status' => $device['status'], 'device_uuid' => $device_uuid]);
} else {
    // Register new device
    $ins = mysqli_prepare($conn, "INSERT INTO devices (device_uuid, device_name, branch_id, status, app_version, registered_by_user_id, last_seen_at, last_sync_at) VALUES (?, ?, ?, 'ACTIVE', ?, ?, NOW(), NOW())");
    mysqli_stmt_bind_param($ins, 'ssisi', $device_uuid, $device_name, $branch_id, $app_version, $user_id);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    echo json_encode(['success' => true, 'status' => 'ACTIVE', 'device_uuid' => $device_uuid]);
}

mysqli_close($conn);
