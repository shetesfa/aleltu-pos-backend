<?php
/**
 * ALELTU — Offline Token Issuer
 * POST /api/auth/issue-offline-token.php
 *
 * Called at login time when online. Issues a 30-day offline token
 * that the device stores in IndexedDB. Used as proof of identity
 * for API requests when the PHP session has expired offline.
 *
 * Request body (JSON or POST):
 *   device_uuid  string (required)
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Must have an active server session (can only get token when online)
if (!isset($_SESSION['user_id']) || !$_SESSION['user_id']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'NO_ACTIVE_SESSION']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$device_uuid = trim($input['device_uuid'] ?? '');

if (empty($device_uuid) || strlen($device_uuid) < 10) {
    echo json_encode(['success' => false, 'error' => 'INVALID_DEVICE_UUID']);
    exit;
}

// Verify device is ACTIVE and belongs to this user's branch
$user_id   = (int)$_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'seller';
$branch_id = (int)($GLOBALS['current_branch_id'] ?? $_SESSION['branch_id'] ?? 0);

if ($branch_id === 0) {
    $branch_id = (int)getCurrentBranchId($conn, $user_id, $role);
}

$stmt = mysqli_prepare($conn, 
    "SELECT id FROM devices WHERE device_uuid = ? AND branch_id = ? AND status = 'ACTIVE'"
);
mysqli_stmt_bind_param($stmt, 'si', $device_uuid, $branch_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);

if (mysqli_stmt_num_rows($stmt) === 0) {
    echo json_encode(['success' => false, 'error' => 'DEVICE_NOT_ACTIVE']);
    exit;
}

// Generate a secure random token
$raw_token  = bin2hex(random_bytes(32)); // 64 hex chars
$token_hash = hash('sha256', $raw_token);
$expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

// Upsert offline session token
$stmt2 = mysqli_prepare($conn,
    "INSERT INTO offline_session_tokens 
       (user_id, device_uuid, token_hash, branch_id, role, issued_at, expires_at, is_revoked)
     VALUES (?, ?, ?, ?, ?, NOW(), ?, 0)
     ON DUPLICATE KEY UPDATE
       token_hash  = VALUES(token_hash),
       branch_id   = VALUES(branch_id),
       role        = VALUES(role),
       issued_at   = NOW(),
       expires_at  = VALUES(expires_at),
       is_revoked  = 0"
);
mysqli_stmt_bind_param($stmt2, 'isssss', $user_id, $device_uuid, $token_hash, $branch_id, $role, $expires_at);

if (!mysqli_stmt_execute($stmt2)) {
    echo json_encode(['success' => false, 'error' => 'DB_ERROR', 'message' => mysqli_error($conn)]);
    exit;
}

// Write audit log
api_audit('OFFLINE_TOKEN_ISSUED', [
    'device_uuid' => $device_uuid,
    'expires_at'  => $expires_at,
], $user_id, $device_uuid);

echo json_encode([
    'success'     => true,
    'token'       => $raw_token,   // Only time it's sent in plaintext
    'expires_at'  => $expires_at,
    'device_uuid' => $device_uuid,
    'branch_id'   => $branch_id,
    'role'        => $role,
]);
