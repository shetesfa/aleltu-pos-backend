<?php
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
        $error = "አዲስ የይለፍ ቃል ቢያንስ 4 Digits መሆን አለበት!";
    } elseif ($new_password !== $confirm_password) {
        $error = "አዲስ የይለፍ ቃሎች አይዛመዱም!";
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
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

                // Update password and set password_changed = 1
                $update_stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, password_changed = 1 WHERE id = ?");
                mysqli_stmt_bind_param($update_stmt, 'si', $new_password_hash, $user_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    $success = "የይለፍ ቃል በተሳካ ሁኔታ ተቀይሯል!";
                    $_SESSION['password_changed'] = 1;

                    // Determine redirect based on role
                    $role = strtolower($user['role'] ?? $user_role);
                    switch ($role) {
                        case 'super_admin':
                            $redirect_url = 'super_admin.php';
                            break;
                        case 'admin':
                            $redirect_url = 'admin_dashboard.php';
                            break;
                        case 'seller':
                            $redirect_url = 'seller_pos.php';
                            break;
                        case 'cashier':
                            $redirect_url = 'daily_cashier.php';
                            break;
                        case 'boss':
                            $redirect_url = 'boss.php';
                            break;
                        default:
                            $redirect_url = 'seller_pos.php';
                            break;
   }
                } else {
                    $error = "የይለፍ ቃል መቀየር አልተቻለም። እባክዎ እንደገና ይሞክሩ።";
     }
                mysqli_stmt_close($update_stmt);
 }
        } else {
            $error = "ተጠቃሚው አልተገኘም!";
   }
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
    <title>የይለፍ ቃል መቀየሪያ - አለልቱ ፖስ</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
 * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
 body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
min-height: 100vh;
 display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .container {
 width: 100%;
            max-width: 480px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            overflow: hidden;
 }
        .header {
            background: #fafbfc;
            padding: 22px;
 text-align: center;
            border-bottom: 1px solid #eee;
  }
        .header h1 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 5px;
            display: flex;
 align-items: center;
            justify-content: center;
            gap: 8px;
}
        .header p {
            color: #7f8c8d;
 font-size: 13px;
}
 .warning-badge {
            display: inline-block;
            background: #fff3cd;
            color: #856404;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 8px;
            border: 1px solid #ffeeba;
 }
        .form-container {
            padding: 22px;
        }
.form-group {
            margin-bottom: 16px;
        }
        .form-group label {
 display: block;
 margin-bottom: 6px;
            color: #34495e;
            font-size: 13.5px;
            font-weight: 600;
        }
        .form-group input {
 width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #dce2e6;
            border-radius: 8px;
 font-size: 15px;
 outline: none;
 transition: all 0.3s;
 }
        .form-group input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
}
 .btn-submit {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
cursor: pointer;
 display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
}
        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-2px);
        }
        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
 font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 8px;
  }
        .alert-error {
            background: #fdf2f2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
  }
        .alert-success {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }
        .info-text {
            background: #eef6fc;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #2c3e50;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-left: 4px solid #3498db;
        }
        .redirect-message {
            text-align: center;
            margin-top: 15px;
            color: #667eea;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-key" style="color: #667eea;"></i> የይለፍ ቃል መቀየሪያ</h1>
            <p>እባክዎ የይለፍ ቃልዎን ይቀይሩ</p>
            <?php if($is_first_login): ?>
            <div class="warning-badge">
                <i class="fas fa-shield-alt"></i> ለመጀመሪያ ጊዜ ስለገቡ እባክዎ አዲስ የይለፍ ቃል ያስገቡ
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
                <i class="fas fa-spinner fa-spin"></i> Please wait... Preparing dashboard...
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = '<?php echo $redirect_url; ?>';
                }, 1500);
            </script>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if(!$success): ?>
            <div class="info-text">
                <i class="fas fa-info-circle" style="color: #3498db;"></i>
                ደህንነቱ የተጠበቀ የይለፍ ቃል ይጠቀሙ። የይለፍ ቃል ቢያንስ 4 Digits/ፊደላት መሆን አለበት።
            </div>
            
            <form method="POST" id="passwordForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> የአሁኑ የይለፍ ቃል</label>
                    <input type="password" name="current_password" id="current_password" required placeholder="የአሁኑን የይለፍ ቃል ያስገቡ">
</div>
                
                <div class="form-group">
                    <label><i class="fas fa-key"></i> አዲስ የይለፍ ቃል</label>
                    <input type="password" name="new_password" id="new_password" required minlength="4" placeholder="አዲስ የይለፍ ቃል (ቢያንስ 4 Digits)">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-check-circle"></i> አዲስ የይለፍ ቃል ድጋሚ</label>
                    <input type="password" name="confirm_password" id="confirm_password" required placeholder="አዲስ የይለፍ ቃል ይድገሙት">
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i> የይለፍ ቃል ቀይር
                </button>
            </form>
            
            <div style="text-align: center; margin-top: 18px;">
                <a href="logout.php" style="color: #667eea; text-decoration: none; font-size: 13px;">
                    <i class="fas fa-sign-out-alt"></i> ዘግተህ ውጣ
                </a>
            </div>
    <?php endif; ?>
        </div>
    </div>
 <script>
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
        
        if (newPass && confirmPass) {
            newPass.addEventListener('input', validateMatch);
            confirmPass.addEventListener('input', validateMatch);
        }
        
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
                alert('አዲስ የይለፍ ቃል ቢያንስ 4 Digits መሆን አለበት!');
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
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
            
            return true;
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>




























