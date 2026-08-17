<?php
session_start();
require_once 'config.php';
if(!isset($_SESSION['role']) || $_SESSION['role'] == 'seller') {
 header("Location: seller_pos.php");
    exit();
}
// Load read-only flag and block if user is read-only
loadReadOnlyFlag($conn, $_SESSION['user_id']);
if (isReadOnly()) {
    header("Location: admin_dashboard.php?msg=readonly_access");
    exit();
}
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$current_username = $_SESSION['username'] ?? '';
// Get branch info
$user_branch = getUserBranch($conn, $user_id);
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);
$all_branches = [];
if ($user_role == 'super_admin') {
 $branches_query = "SELECT * FROM places WHERE status = 'active' ORDER BY place_name";
    $branches_result = mysqli_query($conn, $branches_query);
    if ($branches_result) {
        while($branch = mysqli_fetch_assoc($branches_result)) {
  $all_branches[] = $branch;
        }
    }
}
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        die('Invalid or expired request. Please go back and try again.');
    }
    $username        = trim($_POST['username'] ?? '');
    $full_name       = mysqli_real_escape_string($conn, trim($_POST['full_name'] ?? ''));
    $allowed_roles   = ['admin', 'manager', 'cashier', 'seller', 'boss', 'super_admin'];
    $role            = in_array($_POST['role'] ?? '', $allowed_roles, true) ? $_POST['role'] : 'seller';
    
    // Determine which branch to assign
    if ($user_role == 'super_admin' && isset($_POST['branch_id']) && !empty($_POST['branch_id'])) {
        $assign_branch_id = intval($_POST['branch_id']);
 } else {
        $assign_branch_id = $current_branch_id;
    }
    
    $created_by = $_SESSION['user_id'] ?? 0;
    
    // Generate clean 6-digit PIN for new user
    $default_password_plain = (string)random_int(100000, 999999);
    $default_password = password_hash($default_password_plain, PASSWORD_DEFAULT);
    
    if(empty($username) || empty($full_name)) {
        $error = "ሁሉም መረጃዎች መሞላት አለባቸው!";
    } else {
        // Duplicate username check — prepared statement
        $chk = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($chk, 's', $username);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $dup = mysqli_stmt_num_rows($chk);
        mysqli_stmt_close($chk);
 
        if($dup > 0) {
            $error = "ይህ የተጠቃሚ ስም አስቀድሞ ተመዝግቧል!";
 } else {
            // INSERT — prepared statement
            $ins = mysqli_prepare($conn,
                "INSERT INTO users (username, password, full_name, role, created_by, branch_id, password_changed)
                 VALUES (?, ?, ?, ?, ?, ?, 0)");
            mysqli_stmt_bind_param($ins, 'ssssii', $username, $default_password, $full_name, $role, $created_by, $assign_branch_id);
  
            if(mysqli_stmt_execute($ins)) {
                mysqli_stmt_close($ins);
                $success = "✅ ተጠቃሚ በተሳካ ሁኔታ ተመዝግቧል!<br><div style='margin-top:10px;padding:12px;background:#e8f8f5;border:2px dashed #27ae60;border-radius:10px;text-align:center;'>የተጠቃሚ ስም: <strong>" . htmlspecialchars($username) . "</strong><br>ባለ 6-አሃዝ የይለፍ ቃል (PIN): <strong style='font-size:24px;letter-spacing:4px;color:#27ae60;display:inline-block;margin:6px 0;'>" . htmlspecialchars($default_password_plain) . "</strong><br><small style='color:#555;'>ይህንን ባለ 6-አሃዝ የይለፍ ቃል ገልብጠው ለተጠቃሚው ይስጡት (ሲገባ እንዲቀይረው ይጠየቃል)</small></div>";
                $_POST = [];
 } else {
                mysqli_stmt_close($ins);
                $error = "ስህተት: ተጠቃሚው አልተመዘገበም። እባክዎ እንደገና ይሞክሩ።";
 }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
 <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>አዲስ ተጠቃሚ መዝግብ - <?php echo htmlspecialchars($current_branch_name); ?></title>
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
            padding: 15px;
  display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 650px;
 margin: 0 auto;
        }
        .back-btn-container {
            margin-bottom: 15px;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            backdrop-filter: blur(5px);
            transition: all 0.3s;
        }
        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }
        .branch-header {
            background: white;
            padding: 18px 25px;
            border-radius: 12px 12px 0 0;
  display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            gap: 15px;
            flex-wrap: wrap;
}
 .branch-info {
display: flex;
            align-items: center;
            gap: 12px;
 }
        .branch-icon {
            width: 44px;
            height: 44px;
            background: #eef2ff;
            color: #667eea;
            border-radius: 10px;
   display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
 }
        .branch-details h2 {
            font-size: 17px;
            color: #2c3e50;
}
.branch-details p {
            font-size: 13px;
            color: #7f8c8d;
        }
        .user-role-badge {
            background: #e8f5e9;
            color: #27ae60;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
 align-items: center;
            gap: 6px;
}
.register-card {
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .card-header {
            background: #fafbfc;
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }
        .card-header h1 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 5px;
            display: flex;
  align-items: center;
            justify-content: center;
 gap: 10px;
}
 .card-header p {
            font-size: 13px;
            color: #7f8c8d;
}
 .form-container {
            padding: 25px;
        }
 .form-group {
            margin-bottom: 18px;
}
 .form-group label {
  display: block;
            margin-bottom: 7px;
            color: #34495e;
            font-size: 14px;
            font-weight: 600;
}
.form-group label i {
            color: #667eea;
            margin-right: 5px;
}
  .form-group input, .form-group select {
width: 100%;
            padding: 12px 15px;
            border: 1.5px solid #dce2e6;
            border-radius: 8px;
  font-size: 15px;
 outline: none;
            transition: all 0.3s;
}
 .form-group input:focus, .form-group select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
 }
        .branch-display {
 display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
  }
        .branch-display-info .branch-display-label {
            font-size: 12px;
            color: #64748b;
}
        .branch-display-info .branch-display-name {
            font-size: 15px;
 font-weight: 600;
            color: #1e293b;
  }
        .branch-display-info .branch-display-id {
            font-size: 12px;
            color: #94a3b8;
        }
        .error {
            background: #fdf2f2;
            color: #e74c3c;
            padding: 14px;
            border-radius: 8px;
            border-left: 4px solid #e74c3c;

 margin-bottom: 18px;
            font-size: 14px;
        }
        .success {
            background: #f0fdf4;
            color: #166534;
            padding: 14px;
            border-radius: 8px;
            border-left: 4px solid #22c55e;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.5;
}
        .submit-btn {
 width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
 color: white;
    border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
 }
        .submit-btn:hover {
            opacity: 0.95;
            transform: translateY(-2px);
 }
        .role-info {
            background: #f8fafc;
            padding: 18px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid #e2e8f0;
            font-size: 13px;
}
        .role-info strong {
            display: block;
            margin-bottom: 8px;
            color: #334155;
  }
        .role-info ul {
            margin-left: 18px;
            color: #64748b;
        }
        .role-info li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Back Button -->
        <div class="back-btn-container">
            <a href="manage_users.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> ወደ ተጠቃሚ መቆጣጠሪያ ተመለስ
            </a>
        </div>
        
        <!-- Branch Header -->
        <div class="branch-header">
            <div class="branch-info">
                <div class="branch-icon">
                    <i class="fas fa-store"></i>
  </div>
                <div class="branch-details">
                    <h2><?php echo htmlspecialchars($current_branch_name); ?></h2>
                    <p><i class="fas fa-map-marker-alt"></i> ቅርንጫፍ ኮድ: <?php echo $current_branch_id; ?></p>
                </div>
            </div>
            <div class="user-role-badge">
                <i class="fas <?php echo $user_role == 'super_admin' ? 'fa-crown' : 'fa-user-shield'; ?>"></i>
                <?php echo $user_role == 'super_admin' ? 'ሱፐር አስተዳዳሪ' : 'አስተዳዳሪ'; ?>
            </div>
        </div>
        
        <!-- Main Register Card -->
        <div class="register-card">
            <div class="card-header">
                <h1>
                    <i class="fas fa-user-plus"></i> አዲስ ተጠቃሚ መዝግብ
                </h1>
                <p>እባክዎ የሚከተሉትን መረጃዎች ያስገቡ</p>
            </div>
            
            <div class="form-container">
                <!-- Password Info Note -->
                <div style="background:#eef6fc;border-left:4px solid #3498db;padding:12px 16px;border-radius:8px;margin-bottom:18px;color:#2c3e50;font-size:13.5px;line-height:1.5;">
                    <i class="fas fa-key" style="color:#3498db;font-size:15px;"></i> 
                    <strong>ባለ 6-አሃዝ የይለፍ ቃል፦</strong> አዲስ ተጠቃሚ ሲመዘገብ System <strong>ባለ 6-አሃዝ የሚስጥር ቁጥር (PIN)</strong> ይሰጣል። ከተመዘገበ በኋላ ቁጥሩን ገልብጠው ለተጠቃሚው ይሰጡታል።
 </div>
                
                <?php if($error): ?>
                    <div class="error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error; ?>


  </div>
                <?php endif; ?>
                
                <?php if($success): ?>
                    <div class="success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success; ?>

  </div>
                <?php endif; ?>
                
                <form method="POST" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?php echo getCsrfToken(); ?>">
                    <input type="hidden" name="register_user" value="1">
                    
                    <!-- Branch Selector / Display -->
                    <div class="form-group">
                        <label><i class="fas fa-store"></i> የሚመዘገብበት ቅርንጫፍ</label>
                        <?php if($user_role == 'super_admin' && !empty($all_branches)): ?>
                            <select name="branch_id" class="form-control" required id="branchSelect">
                                <option value="">-- ቅርንጫፍ ይምረጡ --</option>
                                <?php foreach($all_branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php echo ($branch['id'] == $current_branch_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch['place_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <div class="branch-display">
                                <i class="fas fa-store" style="color:#667eea;font-size:18px;"></i>
                                <div class="branch-display-info">
                                    <div class="branch-display-name"><?php echo htmlspecialchars($current_branch_name); ?></div>
                                    <div class="branch-display-id">ቅርንጫፍ ኮድ: <?php echo $current_branch_id; ?></div>
                                </div>
                            </div>
                            <input type="hidden" name="branch_id" id="branchSelect" value="<?php echo $current_branch_id; ?>">
                        <?php endif; ?>
 </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> የተጠቃሚ ስም (Username)</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required placeholder="ለምሳሌ: abebe12">
 </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-signature"></i> ሙሉ ስም (Full Name)</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required placeholder="ሙሉ ስም ያስገቡ">
 </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> ሚና (Role)</label>
                        <select name="role" required>
                            <option value="seller" <?php echo ($_POST['role'] ?? '') == 'seller' ? 'selected' : ''; ?>>ሻጭ (Seller)</option>
                            <option value="admin" <?php echo ($_POST['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>አስተዳዳሪ (Admin)</option>
                            <?php if($user_role == 'super_admin'): ?>
                                <option value="super_admin" <?php echo ($_POST['role'] ?? '') == 'super_admin' ? 'selected' : ''; ?>>ሱፐር አስተዳዳሪ (Super Admin)</option>
                            <?php endif; ?>
    </select>
                    </div>
                    
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-user-plus"></i> ተጠቃሚውን መዝግብ
                    </button>
                </form>
                
                <!-- Role Information -->
                <div class="role-info">
                    <strong><i class="fas fa-info-circle"></i> የሚናዎች መረጃ</strong>
                    <ul>
                        <li><strong>ሻጭ:</strong> መሸጥ እና የራሳቸውን ሪፖርት ማየት ይችላሉ</li>
                        <li><strong>አስተዳዳሪ:</strong> ሙሉ የሻጭ ፍቃድ እና የቅርንጫፋቸውን ዳታ ማየት ይችላሉ</li>
                        <?php if($user_role == 'super_admin'): ?>
                            <li><strong>ሱፐር አስተዳዳሪ:</strong> ሁሉንም ቅርንጫፎች እና ተጠቃሚዎች መቆጣጠር ይችላሉ</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
 </div>
    </div>
    <script>
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const fullname = document.querySelector('input[name="full_name"]').value.trim();
            const branchSelect = document.getElementById('branchSelect');
            
            if (username.length < 3) {
                e.preventDefault();
                alert('የተጠቃሚ ስም ቢያንስ 3 Digits/ፊደላት መሆን አለበት!');
                return false;
            }
            
            if (fullname.length < 3) {
                e.preventDefault();
                alert('ሙሉ ስም ቢያንስ 3 Digits/ፊደላት መሆን አለበት!');
                return false;
            }
            
            if (branchSelect && branchSelect.value === '') {
                e.preventDefault();
                alert('እባክዎ ቅርንጫፍ ይምረጡ!');
                return false;
            }
        });
 </script>
</body>
</html>
<?php mysqli_close($conn); ?>
























