<?php
/**
 * ALELTU — API Auth Middleware
 * Include this at the top of every /api/ endpoint.
 * Validates device_uuid + session OR offline token.
 */
declare(strict_types=1);

if (!defined('ALELTU_CONFIG_LOADED')) {
    require_once dirname(__DIR__, 2) . '/config.php';
}

/**
 * Enforce that the request is authenticated.
 * Accepts:
 *   1) An active PHP session with user_id set.
 *   2) X-Device-UUID header + X-Offline-Token header (offline auth).
 *   3) device_uuid + offline_token in POST body.
 *
 * Sets $GLOBALS['api_user'] on success.
 */
function api_require_auth(): void {
    global $conn;

    // Option 1: Active server session
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        $GLOBALS['api_user'] = [
            'user_id'   => (int)$_SESSION['user_id'],
            'role'      => $_SESSION['role'] ?? 'seller',
            'branch_id' => $_SESSION['branch_id'] ?? 0,
            'auth_mode' => 'session',
        ];
        return;
    }

    // Option 2: Offline token (device presents token issued at login time)
    $device_uuid   = $_SERVER['HTTP_X_DEVICE_UUID'] ?? ($_POST['device_uuid'] ?? ($_GET['device_uuid'] ?? ''));
    $offline_token = $_SERVER['HTTP_X_OFFLINE_TOKEN'] ?? ($_POST['offline_token'] ?? ($_GET['offline_token'] ?? ''));

    if (!empty($device_uuid) && !empty($offline_token)) {
        $token_hash = hash('sha256', $offline_token);
        $stmt = mysqli_prepare($conn,
            "SELECT ost.user_id, ost.branch_id, ost.role
             FROM offline_session_tokens ost
             INNER JOIN devices d ON d.device_uuid = ost.device_uuid AND d.status = 'ACTIVE'
             WHERE ost.device_uuid = ? AND ost.token_hash = ?
               AND ost.is_revoked = 0 AND ost.expires_at > NOW()
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'ss', $device_uuid, $token_hash);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $GLOBALS['api_user'] = [
                'user_id'   => (int)$row['user_id'],
                'role'      => $row['role'],
                'branch_id' => (int)$row['branch_id'],
                'auth_mode' => 'offline_token',
                'device_uuid' => $device_uuid,
            ];
            return;
        }
    }

    // No valid auth found
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'UNAUTHORIZED',
        'message' => 'Valid session or offline token required.',
    ]);
    exit;
}

/**
 * Enforce minimum role. Roles: super_admin > admin > boss > seller
 */
function api_require_role(string $min_role): void {
    $hierarchy = ['seller' => 1, 'boss' => 2, 'admin' => 3, 'super_admin' => 4];
    $user_role = $GLOBALS['api_user']['role'] ?? 'seller';
    if (($hierarchy[$user_role] ?? 0) < ($hierarchy[$min_role] ?? 0)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'FORBIDDEN',
            'message' => "Role '$min_role' or above required. Your role: '$user_role'.",
        ]);
        exit;
    }
}

/**
 * Rate-limit requests. Returns false if blocked, true if allowed.
 * @param string $identifier  device_uuid or IP
 * @param string $endpoint    endpoint name
 * @param int    $limit       max requests per window
 * @param int    $window_sec  window in seconds
 */
function api_rate_limit(string $identifier, string $endpoint, int $limit = 60, int $window_sec = 60): void {
    global $conn;

    // Check/create record
    $stmt = mysqli_prepare($conn,
        "INSERT INTO api_rate_limits (identifier, endpoint, request_count, window_start)
         VALUES (?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           request_count = IF(
             TIMESTAMPDIFF(SECOND, window_start, NOW()) > ?,
             1,
             request_count + 1
           ),
           window_start = IF(
             TIMESTAMPDIFF(SECOND, window_start, NOW()) > ?,
             NOW(),
             window_start
           ),
           is_blocked = IF(request_count + 1 > ?, 1, 0)"
    );
    mysqli_stmt_bind_param($stmt, 'ssiii', $identifier, $endpoint, $window_sec, $window_sec, $limit);
    mysqli_stmt_execute($stmt);

    // Read current state
    $stmt2 = mysqli_prepare($conn,
        "SELECT request_count, is_blocked FROM api_rate_limits WHERE identifier = ? AND endpoint = ?"
    );
    mysqli_stmt_bind_param($stmt2, 'ss', $identifier, $endpoint);
    mysqli_stmt_execute($stmt2);
    $res = mysqli_stmt_get_result($stmt2);
    $row = mysqli_fetch_assoc($res);

    if ($row && ($row['is_blocked'] || $row['request_count'] > $limit)) {
        http_response_code(429);
        header('Retry-After: ' . $window_sec);
        echo json_encode([
            'success' => false,
            'error'   => 'RATE_LIMIT_EXCEEDED',
            'message' => "Too many requests. Limit: $limit per {$window_sec}s.",
        ]);
        exit;
    }
}

/**
 * Write an audit log entry.
 */
function api_audit(string $action, array $details = [], ?int $user_id = null, ?string $device_uuid = null): void {
    global $conn;
    $uid      = $user_id ?? ($GLOBALS['api_user']['user_id'] ?? null);
    $dev      = $device_uuid ?? ($GLOBALS['api_user']['device_uuid'] ?? null);
    $json     = json_encode($details, JSON_UNESCAPED_UNICODE);
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entity   = 'api';

    $stmt = mysqli_prepare($conn,
        "INSERT INTO audit_logs (user_id, device_uuid, event_type, action, entity, new_value, ip_address, created_at)
         VALUES (?, ?, 'API_EVENT', ?, 'api', ?, ?, NOW())"
    );
    mysqli_stmt_bind_param($stmt, 'issss', $uid, $dev, $action, $json, $ip);
    mysqli_stmt_execute($stmt);
}
