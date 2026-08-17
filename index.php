<?php
// index.php - COMPLETE FIXED VERSION
session_start();
require_once "config.php";

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$error = '';
$notice = '';
if (isset($_GET['expired'])) {
    $notice = "30 ደቂቃ ስለቆዩ እባክዎ እንደገና ይግቡ / Session expired. Please log in again.";
}

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

if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
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
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#2c3e50">
    <link rel="icon" type="image/jpg" href="image/icon.png">
    <title>አሌልቱ የእንስሳት ተዋጽኦ - Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            min-height: 100vh;
            min-height: 100dvh;
        }
        body {
            font-family: 'Noto Sans Ethiopic', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 16px 12px;
            color: #2c3e50;
        }
        .page-wrapper {
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin: auto 0;
        }
        .login-container {
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 26px 18px;
            text-align: center;
        }
        .logo-container {
            width: 140px;
            height: 95px;
            margin: 0 auto 12px;
            border-radius: 12px;
            overflow: hidden;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            padding: 6px;
            animation: logoFloat 3s ease-in-out infinite;
        }
        @keyframes logoFloat {
            0%,100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        .logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .header h1 {
            font-size: clamp(1.25rem, 5vw, 1.75rem);
            font-weight: 700;
            margin-bottom: 6px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.3);
            line-height: 1.3;
        }
        .header p {
            font-size: 0.9rem;
            color: #ecf0f1;
            font-weight: 500;
        }

        .login-box {
            padding: 24px 20px;
        }
        .login-form h2 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 22px;
            font-size: 1.45rem;
            font-weight: 700;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #2c3e50;
            font-size: 0.92rem;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px; /* 16px prevents iOS zoom on focus */
            transition: all 0.25s;
            background: #f8f9fa;
            min-height: 48px;
            -webkit-appearance: none;
        }
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 4px rgba(52,152,219,0.18);
        }
        .password-toggle {
            position: absolute;
            right: 8px;
            top: 34px;
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 1.2rem;
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .login-btn {
            width: 100%;
            padding: 15px 18px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            margin-top: 10px;
            min-height: 48px;
            box-shadow: 0 4px 15px rgba(52,152,219,0.3);
        }
        .login-btn:hover, .login-btn:active {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(52,152,219,0.45);
        }
        .error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 13px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.25);
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)}
            25%{transform:translateX(-6px)}
            75%{transform:translateX(6px)}
        }
        .footer {
            text-align: center;
            margin-top: 22px;
            color: #7f8c8d;
            font-size: 0.85rem;
            padding-top: 16px;
            border-top: 1px solid #ecf0f1;
        }

        /* ── Developer credit: hidden on phone, visible on computer only ── */
        .developer-credit {
            display: none;
        }

        /* ── Desktop & Computer screens (≥ 768px) ── */
        @media (min-width: 768px) {
            .page-wrapper { max-width: 450px; }
            .header { padding: 30px 20px; }
            .logo-container { width: 160px; height: 110px; }
            .login-box { padding: 35px 30px; }
            .developer-credit {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: rgba(0, 0, 0, 0.82);
                backdrop-filter: blur(8px);
                color: white;
                padding: 10px 18px;
                border-radius: 50px;
                font-size: 0.85rem;
                font-weight: 500;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 1000;
            }
            .developer-credit i.fa-code { color: #3498db; }
            .developer-credit i.fa-user-cog { color: #f1c40f; }
            .developer-credit a {
                color: white;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                transition: color 0.2s;
            }
            .developer-credit a:hover { color: #3498db; }
            .developer-credit .fa-phone-alt { color: #4CAF50; }
            .developer-credit .fa-telegram { color: #0088cc; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="login-container">
            <div class="header">
                <div class="logo-container">
                    <img src="image/icon.png" alt="Logo">
                </div>
                <h1>አሌልቱ የእንስሳት ተዋጽኦ</h1>
                <p>የሽያጭ ሲስተም መግቢያ</p>
            </div>
            
            <div class="login-box">
                <?php if(!empty($notice)): ?>
                    <div style="background:#fff3cd;color:#856404;padding:12px;border-radius:8px;margin-bottom:15px;font-size:13.5px;border-left:4px solid #f59e0b;">
                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars($notice); ?>
                    </div>
                <?php endif; ?>
                <?php if($error != ''): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
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
    </div>

    <!-- Developer credit: Computer/Desktop only, fixed outside wrapper -->
    <div class="developer-credit">
        <i class="fas fa-code"></i>
        <i class="fas fa-user-cog"></i> Developed by Tesfa
        <a href="tel:0943854325"><i class="fas fa-phone-alt"></i> 0943854325</a>
        <a href="https://t.me/shetesfa" target="_blank"><i class="fab fa-telegram"></i> @shetesfa</a>
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
