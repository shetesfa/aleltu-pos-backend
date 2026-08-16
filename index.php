<?php
// index.php - COMPLETE FIXED VERSION
session_start();
require_once "config.php";

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$error = '';

// If already logged in, skip DB and redirect immediately
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $role = strtolower($_SESSION['role'] ?? '');
    if ($role == 'super_admin') { header('Location: super_admin.php'); exit(); }
    elseif ($role == 'admin')   { header('Location: admin_dashboard.php'); exit(); }
    else                          { header('Location: seller_pos.php'); exit(); }
}

// Process login

// ── Brute-force protection: 25 attempts → 30-minute lockout ──────────────
define('MAX_LOGIN_ATTEMPTS', 25);
define('LOCKOUT_SECONDS', 1800); // 30 minutes
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['lockout_until']  = $_SESSION['lockout_until']  ?? 0;
if ($_SESSION['lockout_until'] > time()) {
    $wait  = ceil(($_SESSION['lockout_until'] - time()) / 60);
    $error = "በጣም ብዙ ሙከራ። {$wait} ደቂቃ ጠብቁ።";
} elseif ($_SESSION['lockout_until'] && $_SESSION['lockout_until'] <= time()) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['lockout_until']  = 0;
}
// ─────────────────────────────────────────────────────────────────────────

if (empty($error) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (!empty($username) && !empty($password)) {
        // Prepared statement — no SQL injection possible on username
        $stmt = mysqli_prepare($conn,
            "SELECT id, username, full_name, password, role, branch_id, is_active, password_changed
             FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);

            // ── Password verification with auto-upgrade to bcrypt ───────────────────
            $password_valid   = false;
            $needs_rehash     = false;

            if (strpos($user['password'], '$2y$') === 0) {
                // Modern bcrypt hash — verify normally
                $password_valid = password_verify($password, $user['password']);
            } elseif ($password === $user['password']) {
                // Plaintext stored — match, then auto-upgrade
                $password_valid = true;
                $needs_rehash   = true;
            } elseif (strlen($user['password']) === 32 && md5($password) === $user['password']) {
                // MD5 hash — match, then auto-upgrade
                $password_valid = true;
                $needs_rehash   = true;
            }

            // Silently upgrade weak password to bcrypt on this login
            if ($password_valid && $needs_rehash) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
                mysqli_stmt_bind_param($upd, 'si', $new_hash, $user['id']);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }
            // ─────────────────────────────────────────────────────────────────────────
            
            if ($password_valid) {
                // Clear brute-force counters on successful login
                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_until']  = 0;

                // Session fixation protection
                session_regenerate_id(true);

                // Get user's branch from database
                $user_branch_id = $user['branch_id'];
                $user_branch_name = '';
                
                if ($user_branch_id > 0) {
                    $bstmt = mysqli_prepare($conn, "SELECT place_name FROM places WHERE id = ? LIMIT 1");
                    mysqli_stmt_bind_param($bstmt, 'i', $user_branch_id);
                    mysqli_stmt_execute($bstmt);
                    $bresult = mysqli_stmt_get_result($bstmt);
                    if ($bresult && mysqli_num_rows($bresult) > 0) {
                        $brow = mysqli_fetch_assoc($bresult);
                        $user_branch_name = $brow['place_name'];
                    }
                    if ($bresult) mysqli_free_result($bresult);
                    mysqli_stmt_close($bstmt);
                }
                
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['branch_id'] = $user_branch_id;
                $_SESSION['branch_name'] = $user_branch_name;
                $_SESSION['login_time'] = time();
                $_SESSION['password_changed'] = isset($user['password_changed']) ? $user['password_changed'] : 1;
                
                mysqli_free_result($result);
                
                $password_changed = isset($user['password_changed']) ? $user['password_changed'] : 1;
                
                if ($password_changed == 0) {
                    header("Location: change_password.php?first_login=1");
                    exit();
                }
                
                // Redirect based on role
                $role = strtolower($user['role']);
                if ($role == 'super_admin') {
                    header("Location: super_admin.php");
                } elseif ($role == 'admin') {
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: seller_pos.php");
                }
                exit();
            } else {
                $error = "የይለፍ ቃሉ ተሳስቷል!";
            }
        } else {
            $error = "ተጠቃሚ አልተገኘም!";
        }
        
        // Count failed attempts
        if ($error) {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION['lockout_until']  = time() + LOCKOUT_SECONDS;
                $_SESSION['login_attempts'] = 0;
                $error = "በጣም ብዙ ሙከራ። 30 ደቂቃ ጠብቁ።";
            }
        }

        if ($result) mysqli_free_result($result);
    } else {
        $error = "እባክዎ የተጠቃሚ ስም እና የይለፍ ቃል ያስገቡ!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/jpg" href="image/icon.png">
    <title>አሌልቱ የእንስሳት ተዋጽኦ - Login</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-container {
            width: 100%;
            max-width: 450px;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: fadeIn 0.6s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .logo-container {
            width: 180px;
            height: 120px;
            margin: 0 auto 20px;
            border-radius: 12px;
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: logoFloat 3s ease-in-out infinite;
        }
        @keyframes logoFloat {
            0%,100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 10px;
        }
        .header h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.3);
        }

        /* ── Login box: starts compact on mobile ── */
        .login-box { padding: 25px; }

        .login-form h2 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 30px;
            font-size: 1.8rem;
        }
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #2c3e50;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #f8f9fa;
            min-height: 44px;
        }
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 4px rgba(52,152,219,0.15);
            transform: translateY(-2px);
        }
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 42px;
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 1.2rem;
            min-height: 44px;
            min-width: 44px;
        }
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            min-height: 44px;
        }
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(52,152,219,0.4);
        }
        .error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            animation: shake 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)}
            25%{transform:translateX(-10px)}
            75%{transform:translateX(10px)}
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #7f8c8d;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }

        /* ── Developer credit: centered on mobile ── */
        .developer-credit {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            right: auto;
            width: 95%;
            justify-content: center;
            background: rgba(0, 0, 0, 0.85);
            color: white;
            padding: 10px 15px;
            border-radius: 50px;
            font-size: 0.75rem;
            z-index: 1000;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .developer-credit i.fa-code { color: #3498db; }
        .developer-credit i.fa-user-cog { color: #f1c40f; }
        .developer-credit a {
            color: white;
            text-decoration: none;
            margin: 0 5px;
            transition: color 0.3s;
        }
        .developer-credit a:hover { color: #3498db; }
        .developer-credit .fa-phone-alt { color: #4CAF50; }
        .developer-credit .fa-telegram { color: #0088cc; }

        /* ── ≥ 481px: restore desktop layout ── */
        @media (min-width: 481px) {
            .login-box { padding: 40px; }
            .developer-credit {
                left: auto;
                right: 20px;
                transform: none;
                width: auto;
                font-size: 0.9rem;
                padding: 12px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="developer-credit">
        <i class="fas fa-code"></i>
        <i class="fas fa-user-cog"></i> Developed by Tesfa
        <a href="tel:0943854325"><i class="fas fa-phone-alt"></i> 0943854325</a>
        <a href="https://t.me/shetesfa" target="_blank"><i class="fab fa-telegram"></i> @shetesfa</a>
    </div>
    
    <div class="login-container">
        <div class="header">
            <div class="logo-container">
                <img src="image/icon.png" alt="Logo">
            </div>
            <h1>አሌልቱ የእንስሳት ተዋጽኦ</h1>
            <p>የሽያጭ ሲስተም መግቢያ</p>
        </div>
        
        <div class="login-box">
            <?php if($error != ''): ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="login-form">
                <h2>መግቢያ</h2>
                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" placeholder="ዩዘርኔም" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" placeholder="ፓስወርድ" id="passwordField" required>
                        <button type="button" class="password-toggle" onclick="togglePassword()">👁️</button>
                    </div>
                    <button type="submit" class="login-btn">ወደውስጥ ለመግባት</button>
                </form>
            </div>
            
            <div class="footer">© 2018 አሌልቱ የእንስሳት ተዋጽኦ</div>
        </div>
    </div>

    <script>
        function togglePassword() {
            var field = document.getElementById('passwordField');
            if (field.type === 'password') {
                field.type = 'text';
            } else {
                field.type = 'password';
            }
        }
        
        // Simple form submit - no complex validation that blocks redirect
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            var btn = this.querySelector('button[type="submit"]');
            if (btn.disabled) {
                e.preventDefault();
                return false;
            }
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> በመግባት ላይ...';
            return true;
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
