<?php
// get_user_password.php - AJAX endpoint
//
// FIXED (security): this used to return a user's PLAINTEXT password on
// demand — meaning anyone who could reach this endpoint could read every
// password in the system. Now that passwords are stored as one-way hashes,
// the original password can no longer be read back by anyone, including
// this script — that is the whole point of hashing.
//
// Kept the exact same URL and JSON response shape
// ({success, password}) so the existing "view password" button in
// manage_users.php keeps working with zero frontend changes. The behavior
// underneath is now: generate a brand-new random password, hash and store
// it, force the user to change it at next login, and return the NEW
// password once so the admin can hand it to the user. Every click issues a
// fresh password — it is a reset, not a peek.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

$current_user_id   = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';
$requested_user_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if (!in_array($current_user_role, ['super_admin', 'admin'], true)) {
    echo json_encode(['success' => false, 'error' => 'No permission to reset passwords']);
    exit();
}

if (!$requested_user_id) {
    echo json_encode(['success' => false, 'error' => 'No user ID provided']);
    exit();
}

// Get current user's branch
$current_branch_id = getCurrentBranchId($conn, $current_user_id, $current_user_role);

// Get requested user's info to check permissions
$user_stmt = mysqli_prepare($conn, "SELECT id, role, branch_id FROM users WHERE id = ? AND is_active = 1");
mysqli_stmt_bind_param($user_stmt, 'i', $requested_user_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);

if (!$user_result || mysqli_num_rows($user_result) == 0) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit();
}

$target_user = mysqli_fetch_assoc($user_result);
mysqli_free_result($user_result);
mysqli_stmt_close($user_stmt);

// Permission checks (unchanged from before)
$has_permission = false;

if ($current_user_role == 'super_admin') {
    // Super Admin can reset any user's password except their own
    if ($target_user['id'] != $current_user_id) {
        $has_permission = true;
    }
} elseif ($current_user_role == 'admin') {
    // Admin can only reset passwords of users in their branch
    if ($target_user['branch_id'] == $current_branch_id &&
        $target_user['role'] != 'super_admin' &&
        $target_user['id'] != $current_user_id) {
        $has_permission = true;
    }
}

if (!$has_permission) {
    echo json_encode(['success' => false, 'error' => 'No permission to reset this password']);
    exit();
}

// Reset to '123' — same default used for brand-new users in register_user.php.
// password_changed is set to 0 so the user is prompted to set their own
// password the next time they log in (same as a new registration).
$new_plain_password = bin2hex(random_bytes(8));
$new_password_hash = password_hash($new_plain_password, PASSWORD_DEFAULT);

// UPDATE — prepared statement
$upd = mysqli_prepare($conn, "UPDATE users SET password = ?, password_changed = 0 WHERE id = ?");
mysqli_stmt_bind_param($upd, 'si', $new_password_hash, $requested_user_id);
if (!mysqli_stmt_execute($upd)) {
    mysqli_stmt_close($upd);
    echo json_encode(['success' => false, 'error' => 'Failed to reset password']);
    exit();
}
mysqli_stmt_close($upd);

// Log the reset for audit trail
error_log(sprintf('[PASSWORD RESET] super_admin user_id=%d reset password for user_id=%d at %s',
    $current_user_id, $requested_user_id, date('Y-m-d H:i:s')));

// Return the NEW password once — this is the only moment it ever exists in
// plaintext. It is never stored anywhere; only its hash is.
echo json_encode([
    'success' => true,
    'password' => $new_plain_password
]);
?>
