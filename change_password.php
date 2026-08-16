<?php
// change_password.php - Fixed redirect issue
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'seller';
$is_first_login = isset($_GET['first_login']) && $_GET['first_login'] == '1';

$error = '';
$success = '';
$redirect_url = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        die('Invalid or expired request. Please go back and try again.');
    }
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password)) {
        $error = "እባክዎ የአሁኑን የይለፍ ቃል ያስገቡ!";
    } elseif (empty($new_password)) {
        $error = "እባክዎ አዲስ የይለፍ ቃል ያስገቡ!";
    } elseif (strlen($new_password) < 4) {
        $error = "አዲስ የይለፍ ቃል ቢያንስ 4 ቅምፊወች መሆን አለበት!";
    } elseif ($new_password !== $confirm_password) {
        $error = "አዲስ የይለፍ ቃሉች አይዛመዱም!";
    } else {
        // Get user's current password from database
        $stmt = mysqli_prepare($conn, "SELECT password, role, branch_id FROM users WHERE id = ? AND is_active = 1");
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            
            // Verify current password
            $password_valid = false;
            if ($current_password === $user['password']) {
                $password_valid = true;
            } elseif (function_exists('password_verify') && password_verify($current_password, $user['password'])) {
                $password_valid = true;
            }
            
            if (!$password_valid) {
                $error = "የአሁኑ የይለፍ ቃል ትክክል አይደለም!";
            } else {
                // FIXED: new password was being saved as plaintext — now hashed.
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, password_changed = 1 WHERE id = ?");
                mysqli_stmt_bind_param($update_stmt, 'si', $new_password_hash, $user_id);
                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['password_changed'] = 1;
                    $success = "የይለፍ ቃልዎ በተሳካ ሁኔታ ተቀይሯል!";
                    
                    // Determine redirect URL
                    if ($user['role'] == 'super_admin') {
                        $redirect_url = 'super_admin.php';
                    } elseif ($user['role'] == 'admin') {
                        $redirect_url = 'admin_dashboard.php';
                    } else {
                        $redirect_url = 'seller_pos.php';
                    }
                } else {
                    $error = "ስህተት ተከስቷል: " . mysqli_error($conn);
                }
            }
        } else {
            $error = "ተጠቃሚ አልተገኘም!";
        }
        if ($result) mysqli_free_result($result);
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>የይለፍ ቃል መቀየሪያ - <?php echo htmlspecialchars($user_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: slideIn 0.5s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            text-align: center;
        }
        .header h1 {
            font-size: 22px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .warning-badge {
            background: #f39c12;
            color: #2c3e50;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-top: 10px;
        }
        .form-container {
            padding: 20px;
        }
        .form-container a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            min-height: 44px;
            padding: 5px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        label i {
            color: #667eea;
            width: 20px;
        }
        input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
            min-height: 44px;
        }
        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            min-height: 44px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102,126,234,0.3);
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }
        .alert-success {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }
        .info-text {
            background: #e0e7ff;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #4338ca;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .redirect-message {
            text-align: center;
            margin-top: 15px;
            color: #667eea;
            font-size: 13px;
        }
        @media (min-width: 600px) {
            .header { padding: 30px; }
            .header h1 { font-size: 28px; }
            .form-container { padding: 30px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-key"></i> የይለፍ ቃል መቀየሪያ</h1>
            <p>እባክዎ የይለፍ ቃልዎን ይቀይሩ</p>
            <?php if($is_first_login): ?>
            <div class="warning-badge">
                <i class="fas fa-shield-alt"></i> ለደህንነትዎ እባክዎ ይቀይሩ
            </div>
            <?php endif; ?>
        </div>
        
        <div class="form-container">
            <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
            <?php endif; ?>
            
            <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
            <?php if($redirect_url): ?>
            <div class="redirect-message">
                <i class="fas fa-spinner fa-spin"></i> እባክዎ ይጠብቁ... ወደ ዳሽቦርድ እያዘጋጀ ነው...
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = '<?php echo $redirect_url; ?>';
                }, 2000);
            </script>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if(!$success): ?>
            <div class="info-text">
                <i class="fas fa-info-circle"></i>
                ደህንነቱ የተጠበቀ የይለፍ ቃል ይጠቀሙ። የይለፍ ቃል ቢያንስ 4 ቁምፊዎች መሆን አለበት።
            </div>
            
            <form method="POST" id="passwordForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> የአሁኑ የይለፍ ቃል</label>
                    <input type="password" name="current_password" id="current_password" required placeholder="የአሁኑን የይለፍ ቃል ያስገቡ">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-key"></i> አዲስ የይለፍ ቃል</label>
                    <input type="password" name="new_password" id="new_password" required minlength="4" placeholder="አዲስ የይለፍ ቃል (ቢያንስ 4 ቁምፊዎች)">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-check-circle"></i> አዲስ የይለፍ ቃል ድጋሚ</label>
                    <input type="password" name="confirm_password" id="confirm_password" required placeholder="አዲስ የይለፍ ቃል ይድገሙት">
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i> የይለፍ ቃል ቀይር
                </button>
            </form>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="logout.php" style="color: #667eea; text-decoration: none;">
                    <i class="fas fa-sign-out-alt"></i> ዘግተህ ውጣ
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Password match validation
        const newPass = document.getElementById('new_password');
        const confirmPass = document.getElementById('confirm_password');
        
        function validateMatch() {
            if (newPass.value !== confirmPass.value && confirmPass.value !== '') {
                confirmPass.style.borderColor = '#e74c3c';
                confirmPass.style.background = '#fff5f5';
                return false;
            } else {
                confirmPass.style.borderColor = '#27ae60';
                confirmPass.style.background = '#f0fff4';
                return true;
            }
        }
        
        newPass.addEventListener('input', validateMatch);
        confirmPass.addEventListener('input', validateMatch);
        
        // Form validation
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const currentPass = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const btn = document.getElementById('submitBtn');
            
            if (!currentPass) {
                e.preventDefault();
                alert('እባክዎ የአሁኑን የይለፍ ቃል ያስገቡ!');
                return false;
            }
            
            if (!newPassword) {
                e.preventDefault();
                alert('እባክዎ አዲስ የይለፍ ቃል ያስገቡ!');
                return false;
            }
            
            if (newPassword.length < 4) {
                e.preventDefault();
                alert('አዲስ የይለፍ ቃል ቢያንስ 4 ቁምፊዎች መሆን አለበት!');
                return false;
            }
            
            if (!confirmPassword) {
                e.preventDefault();
                alert('እባክዎ አዲስ የይለፍ ቃል ድጋሚ ያስገቡ!');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('አዲስ የይለፍ ቃሎች አይዛመዱም!');
                return false;
            }
            
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> በማስቀመጥ ላይ...';
            }
            
            return true;
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
