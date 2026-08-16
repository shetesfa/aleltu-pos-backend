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

// Get ALL branches for Super Admin
$all_branches = [];
if ($user_role == 'super_admin') {
    $branches_query = "SELECT * FROM places WHERE status = 'active' ORDER BY place_name";
    $branches_result = mysqli_query($conn, $branches_query);
    while($branch = mysqli_fetch_assoc($branches_result)) {
        $all_branches[] = $branch;
    }
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_user'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        die('Invalid or expired request. Please go back and try again.');
    }
    $username        = trim($_POST['username'] ?? '');
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    // FIXED: role was inserted into the SQL string with no escaping at all —
    // whitelist it against the actual allowed values (users.role enum) instead.
    $allowed_roles = ['admin', 'manager', 'cashier', 'seller', 'boss', 'super_admin'];
    $role = in_array($_POST['role'] ?? '', $allowed_roles, true) ? $_POST['role'] : 'seller';
    
    // Determine which branch to assign
    if ($user_role == 'super_admin' && isset($_POST['branch_id']) && !empty($_POST['branch_id'])) {
        $assign_branch_id = intval($_POST['branch_id']);
    } else {
        $assign_branch_id = $current_branch_id;
    }
    
    $created_by = $_SESSION['user_id'] ?? 0;
    
    // Default password is '123' for all new users — now stored as a proper
    // hash instead of plaintext. The displayed message to the admin below
    // still says '123' because that's what the new user actually types in
    // to log in — only how it's stored changed.
    $default_password_plain = bin2hex(random_bytes(8));
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
                $success = "ተጠቃሚ በተሳካ ሁኔታ ተመዝግቧል!<br>የይለፍ ቃል: <strong>123</strong><br>ተጠቃሚው በመጀመሪያ ጊዜ ሲገባ የይለፍ ቃሉን እንዲቀይር ይጠየቃል።";
                $_POST = [];
                $success = "User created successfully.<br>Temporary password: <strong>" . htmlspecialchars($default_password_plain, ENT_QUOTES, 'UTF-8') . "</strong><br>The user must change this password at first login.";
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
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
        }
        
        /* Branch Header */
        .branch-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            flex-direction: column;
            text-align: center;
        }
        
        .branch-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .branch-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .branch-details h2 {
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .branch-details p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .user-role-badge {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Back Button */
        .back-btn-container {
            margin-bottom: 20px;
        }
        
        .back-btn {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            min-height: 44px;
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        /* Main Card */
        .register-card {
            background: white;
            border-radius: 0 0 15px 15px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .card-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .card-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        
        .branch-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 25px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 600;
            margin-top: 15px;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .branch-badge i {
            margin-right: 8px;
            color: #f1c40f;
        }
        
        .form-container {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        label i {
            color: #667eea;
            margin-right: 8px;
            width: 18px;
        }
        
        input, select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e8f0fe;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8fafc;
            min-height: 44px;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 5px 15px rgba(102,126,234,0.1);
        }
        
        /* Branch Display */
        .branch-display {
            background: #f0f7ff;
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: #2c3e50;
            flex-direction: column;
            text-align: center;
        }
        
        .branch-display i {
            font-size: 24px;
            color: #667eea;
        }
        
        .branch-display-info {
            flex: 1;
        }
        
        .branch-display-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        
        .branch-display-name {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .branch-display-id {
            font-size: 13px;
            color: #95a5a6;
        }
        
        /* Super Admin Branch Selector */
        .branch-selector {
            margin-top: 10px;
        }
        
        .info-note {
            background: #e8f4fd;
            border-left: 4px solid #3498db;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #2c3e50;
        }
        
        .password-info {
            background: #fff8e1;
            border-left: 4px solid #f39c12;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #856404;
        }
        
        .password-info strong {
            color: #e67e22;
            font-size: 16px;
        }
        
        .error, .success {
            padding: 18px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
            animation: slideIn 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .error {
            background: linear-gradient(135deg, #ff758c 0%, #ff7eb3 100%);
            color: white;
        }
        
        .success {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
            color: white;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .submit-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 44px;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.6);
        }
        
        .submit-btn i {
            font-size: 20px;
        }
        
        .role-info {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
            margin-top: 30px;
            border-left: 5px solid #667eea;
        }
        
        .role-info strong {
            color: #2c3e50;
            font-size: 16px;
            display: block;
            margin-bottom: 15px;
        }
        
        .role-info ul {
            margin-left: 20px;
        }
        
        .role-info li {
            margin-bottom: 10px;
            color: #555;
        }
        
        .role-info li strong {
            display: inline;
            font-size: 14px;
            color: #667eea;
        }
        
        /* Phone-first layout overrides */
        body { padding: 8px; }
        .branch-header { padding: 16px; border-radius: 12px 12px 0 0; gap: 10px; }
        .branch-info { gap: 10px; flex-wrap: wrap; justify-content: center; }
        .branch-icon { width: 42px; height: 42px; font-size: 20px; }
        .branch-details h2 { font-size: 18px; overflow-wrap: anywhere; }
        .user-role-badge { padding: 8px 14px; }
        .back-btn-container { margin-bottom: 12px; }
        .back-btn { width: 100%; padding: 12px 16px; justify-content: center; text-align: center; }
        .register-card { border-radius: 0 0 12px 12px; }
        .card-header { padding: 20px 16px; }
        .card-header h1 { font-size: 1.35rem; gap: 8px; flex-wrap: wrap; }
        .card-header p { font-size: 14px; }
        .branch-badge { padding: 8px 14px; font-size: 13px; max-width: 100%; overflow-wrap: anywhere; }
        .form-container { padding: 16px; }
        .form-group { margin-bottom: 18px; }
        .branch-display { padding: 12px; }
        .error, .success { padding: 14px; margin-bottom: 18px; }
        .submit-btn { padding: 14px; font-size: 16px; }
        .role-info { padding: 16px; margin-top: 20px; }

        /* Responsive */
        @media (min-width: 600px) {
            body { padding: 20px; }
            .branch-header { padding: 20px 30px; border-radius: 15px 15px 0 0; }
            .branch-icon { width: 50px; height: 50px; font-size: 24px; }
            .branch-details h2 { font-size: 20px; }
            .back-btn { width: auto; padding: 12px 25px; }
            .register-card { border-radius: 0 0 15px 15px; }
            .card-header { padding: 30px; }
            .card-header p { font-size: 16px; }
            .branch-badge { padding: 8px 25px; font-size: 15px; }
            .branch-header {
                flex-direction: row;
                justify-content: space-between;
                text-align: left;
            }
            
            .form-container {
                padding: 40px;
            }
            
            .card-header h1 {
                font-size: 2rem;
            }
            
            .branch-display {
                flex-direction: row;
                text-align: left;
            }
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
                <!-- Information Note -->
                <div class="info-note">
                    <i class="fas fa-info-circle"></i> 
                    <strong>ማስታወሻ:</strong> አዲሱ ተጠቃሚ በራስ-ሰር ወደሚከተለው ቅርንጫፍ ይመዘገባል
                </div>
                
                <!-- Password Info Note -->
                <div class="password-info">
                    <i class="fas fa-key"></i> 
                    <strong>የይለፍ ቃል:</strong> ለአዲስ ተጠቃሚ የሚሰጠው የይለፍ ቃል <strong>123</strong> ነው።<br>
                    ተጠቃሚው በመጀመሪያ ጊዜ ሲገባ የይለፍ ቃሉን እንዲቀይር ይጠየቃል።
                </div>
                
                <!-- Branch Display -->
                <div class="form-group">
                    <label><i class="fas fa-store"></i> ቅርንጫፍ</label>
                    
                    <?php if($user_role == 'super_admin' && !empty($all_branches)): ?>
                        <!-- Super Admin: Can choose branch -->
                        <div class="branch-selector">
                            <select name="branch_id" class="form-control" required id="branchSelect">
                                <option value="">ቅርንጫፍ ይምረጡ</option>
                                <?php foreach($all_branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php echo ($branch['id'] == $current_branch_id) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch['place_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <!-- Regular Admin: Show fixed branch -->
                        <div class="branch-display">
                            <i class="fas fa-store"></i>
                            <div class="branch-display-info">
                                <div class="branch-display-label">የሚመዘገብበት ቅርንጫፍ</div>
                                <div class="branch-display-name"><?php echo htmlspecialchars($current_branch_name); ?></div>
                                <div class="branch-display-id">ቅርንጫፍ ኮድ: <?php echo $current_branch_id; ?></div>
                            </div>
                        </div>
                        <input type="hidden" name="branch_id" id="branchSelect" value="<?php echo $current_branch_id; ?>">
                    <?php endif; ?>
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
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> የተጠቃሚ ስም</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username']??''); ?>" required placeholder="ለምሳሌ: john_doe">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-signature"></i> ሙሉ ስም</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name']??''); ?>" required placeholder="ሙሉ ስምዎን ያስገቡ">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> ሚና</label>
                        <select name="role" required>
                            <option value="seller" <?php echo ($_POST['role']??'')=='seller'?'selected':''; ?>>ሻጭ</option>
                            <option value="admin" <?php echo ($_POST['role']??'')=='admin'?'selected':''; ?>>አስተዳዳሪ</option>
                            <?php if($user_role == 'super_admin'): ?>
                                <option value="super_admin" <?php echo ($_POST['role']??'')=='super_admin'?'selected':''; ?>>ሱፐር አስተዳዳሪ</option>
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
                    <p style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd; font-size: 14px; color: #2c3e50;">
                        <i class="fas fa-store" style="color: #667eea;"></i> 
                        <strong>የሚመዘገብበት ቅርንጫፍ:</strong> 
                        <span style="background: #e3f2fd; padding: 3px 10px; border-radius: 20px; margin-left: 5px;">
                            <?php echo htmlspecialchars($current_branch_name); ?>
                        </span>
                    </p>
                    <p style="margin-top: 10px; font-size: 13px; color: #e67e22; background: #fff8e1; padding: 8px; border-radius: 8px;">
                        <i class="fas fa-key"></i>
                        <strong>የይለፍ ቃል:</strong> አዲስ ተጠቃሚ የሚሰጠው የይለፍ ቃል <strong style="font-size: 16px;">123</strong> ነው።
                        ተጠቃሚው በመጀመሪያ ጊዜ ሲገባ የይለፍ ቃሉን መቀየር አለበት።
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const fullname = document.querySelector('input[name="full_name"]').value.trim();
            const role = document.querySelector('select[name="role"]').value;
            const branchSelect = document.getElementById('branchSelect');
            
            if (username.length < 3) {
                e.preventDefault();
                alert('የተጠቃሚ ስም ቢያንስ 3 ቁምፊዎች መሆን አለበት!');
                return false;
            }
            
            if (fullname.length < 3) {
                e.preventDefault();
                alert('ሙሉ ስም ቢያንስ 3 ቁምፊዎች መሆን አለበት!');
                return false;
            }
            
            if (branchSelect && branchSelect.value === '') {
                e.preventDefault();
                alert('እባክዎ ቅርንጫፍ ይምረጡ!');
                return false;
            }
        });
        
        // Auto dismiss success message after 5 seconds
        setTimeout(() => {
            const successMsg = document.querySelector('.success');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
