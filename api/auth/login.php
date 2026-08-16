<?php
/**
 * ALELTU — REST API Login Endpoint
 * POST /api/auth/login.php
 *
 * Input (JSON or POST):
 *   username    string (required)
 *   password    string (required)
 *   device_uuid string (required)
 *   device_name string (optional)
 *
 * Output:
 *   success     bool
 *   user        object
 *   branch      object
 *   token       string (30-day offline session token)
 *   expires_at  string
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

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$username    = trim($input['username'] ?? '');
$password    = $input['password'] ?? '';
$device_uuid = trim($input['device_uuid'] ?? '');
$device_name = trim($input['device_name'] ?? 'Flutter Device');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'USERNAME_AND_PASSWORD_REQUIRED']);
    exit;
}

if (empty($device_uuid)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'DEVICE_UUID_REQUIRED']);
    exit;
}

$u_stmt = mysqli_prepare($conn, 
    "SELECT id, username, full_name, password, role, branch_id, is_active, password_changed 
     FROM users WHERE username = ? AND is_active = 1 LIMIT 1"
);
mysqli_stmt_bind_param($u_stmt, 's', $username);
mysqli_stmt_execute($u_stmt);
$res = mysqli_stmt_get_result($u_stmt);
$user = mysqli_fetch_assoc($res);
mysqli_stmt_close($u_stmt);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'INVALID_CREDENTIALS']);
    exit;
}

// API authentication accepts only modern password hashes. Legacy accounts
// must first sign in through the web login, which upgrades their hash.
$password_valid = password_verify($password, $user['password']);

if (!$password_valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'INVALID_CREDENTIALS']);
    exit;
}

$branch_id = (int)($user['branch_id'] ?? 1);
$branch_name = 'Main Branch';
$b_stmt = mysqli_prepare($conn, "SELECT place_name FROM places WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($b_stmt, 'i', $branch_id);
mysqli_stmt_execute($b_stmt);
$b_res = mysqli_stmt_get_result($b_stmt);
if ($b_row = mysqli_fetch_assoc($b_res)) {
    $branch_name = $b_row['place_name'];
}
mysqli_stmt_close($b_stmt);

// Auto-register / upsert device
$d_stmt = mysqli_prepare($conn,
    "INSERT INTO devices (device_uuid, device_name, branch_id, status, registered_at, last_seen_at, registered_by_user_id)
     VALUES (?, ?, ?, 'ACTIVE', NOW(), NOW(), ?)
     ON DUPLICATE KEY UPDATE
       device_name = VALUES(device_name),
       branch_id   = VALUES(branch_id),
       last_seen_at = NOW()"
);
mysqli_stmt_bind_param($d_stmt, 'ssii', $device_uuid, $device_name, $branch_id, $user['id']);
mysqli_stmt_execute($d_stmt);
mysqli_stmt_close($d_stmt);

// Issue 30-day offline token
$raw_token  = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $raw_token);
$expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

$t_stmt = mysqli_prepare($conn,
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
$role = $user['role'];
mysqli_stmt_bind_param($t_stmt, 'ississ', $user['id'], $device_uuid, $token_hash, $branch_id, $role, $expires_at);
mysqli_stmt_execute($t_stmt);
mysqli_stmt_close($t_stmt);

// Audit log
api_audit('API_LOGIN_SUCCESS', [
    'username'    => $username,
    'role'        => $role,
    'device_uuid' => $device_uuid,
    'branch_id'   => $branch_id
], (int)$user['id'], $device_uuid);

// Return JSON payload
echo json_encode([
    'success' => true,
    'user' => [
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
        'branch_id' => $branch_id,
        'read_only' => (int)($user['read_only'] ?? 0)
    ],
    'branch' => [
        'id'   => $branch_id,
        'name' => $branch_name
    ],
    'token'       => $raw_token,
    'expires_at'  => $expires_at,
    'device_uuid' => $device_uuid
]);
