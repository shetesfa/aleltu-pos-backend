<?php
session_start();
require_once 'config.php';

// Set Ethiopian timezone
date_default_timezone_set('Africa/Addis_Ababa');

if (!$conn) die("Connection failed");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'seller';

// Get branch info for current user
$user_branch = getUserBranch($conn, $user_id);
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// ========== CHECK AND FIX THE DATABASE IF NEEDED ==========
// Check if branch_id column exists in item_edit_history table
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM item_edit_history LIKE 'branch_id'");
$branch_column_exists = mysqli_num_rows($check_column) > 0;

// If column doesn't exist, add it automatically
if (!$branch_column_exists) {
    // Add the branch_id column
    $alter_query = "ALTER TABLE item_edit_history ADD COLUMN branch_id INT DEFAULT 1";
    mysqli_query($conn, $alter_query);
    
    // Update existing records with branch_id from products table
    $update_query = "UPDATE item_edit_history ieh
                     JOIN products p ON ieh.product_id = p.id
                     SET ieh.branch_id = p.branch_id
                     WHERE ieh.branch_id IS NULL OR ieh.branch_id = 1";
    mysqli_query($conn, $update_query);
}

// ========== ETHIOPIAN DATE FUNCTION (for Ethiopian calendar display) ==========
function gregorian_to_ethiopian($gregorian_date) {
    $eth = getEthiopianDate($gregorian_date);
    return [
        'year' => $eth['year'],
        'month' => $eth['month'],
        'month_name' => $eth['month_name'],
        'day' => $eth['day'],
        'full_date' => $eth['formatted']
    ];
}

// ========== FUNCTION TO CONVERT TO 12-HOUR GREGORIAN TIME ==========
function format_gregorian_time_12h($db_datetime) {
    $timestamp = strtotime($db_datetime);
    
    // Get hour in 24-hour format
    $hour24 = (int)date('G', $timestamp);
    $minute = date('i', $timestamp);
    $second = date('s', $timestamp);
    
    // Convert to 12-hour format with AM/PM
    if ($hour24 == 0) {
        $hour12 = 12;
        $ampm = 'AM';
    } elseif ($hour24 < 12) {
        $hour12 = $hour24;
        $ampm = 'AM';
    } elseif ($hour24 == 12) {
        $hour12 = 12;
        $ampm = 'PM';
    } else {
        $hour12 = $hour24 - 12;
        $ampm = 'PM';
    }
    
    return sprintf("%d:%02d:%02d %s", $hour12, $minute, $second, $ampm);
}

// ========== GET CURRENT ETHIOPIAN DATE (for header display) ==========
$current_timestamp = time();
$current_gregorian = date('Y-m-d H:i:s', $current_timestamp);
$current_ethiopian = gregorian_to_ethiopian($current_gregorian);

// Get total count for this branch
$count_query = "SELECT COUNT(*) as total FROM item_edit_history WHERE branch_id = $current_branch_id";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];

// ========== GET EDIT HISTORY FOR CURRENT BRANCH ONLY ==========
$query = "SELECT * FROM item_edit_history 
          WHERE branch_id = $current_branch_id 
          ORDER BY edited_at DESC";
$result = mysqli_query($conn, $query);

// ========== NATIVE PHPSPREADSHEET EXCEL EXPORT (.xlsx) ==========
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('የእቃ ማስተካከያ ታሪክ');

    $colCount = 10;
    $widths = [8, 16, 14, 14, 22, 22, 16, 16, 16, 18];
    foreach ($widths as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->getColumnDimension($colLetter)->setWidth($w);
    }

    $dateBannerText = "የኢትዮጵያ ቀን: " . $current_ethiopian['full_date'] . "   |   Gregorian: " . date('Y-m-d');
    $nextRow = renderExcelBannerReal($sheet, 'የእቃ ማስተካከያ ታሪክ (Item Edit History)', $current_branch_name, $dateBannerText, 1, $colCount);

    $headers = ['#', 'የኢትዮጵያ ቀን', 'የኢትዮጵያ ሰዓት', 'ግሪጎሪያን ሰዓት', 'የነበረው ስም', 'አዲሱ ስም', 'የነበረው ዋጋ (ብር)', 'አዲሱ ዋጋ (ብር)', 'የዋጋ ልዩነት (ብር)', 'የቀየረው ሰው'];
    foreach ($headers as $i => $label) {
        $sheet->setCellValue([$i + 1, $nextRow], $label);
    }
    styleExcelHeaderRow($sheet, $nextRow, $colCount);
    $r = $nextRow + 1;

    $export_res = mysqli_query($conn, $query);
    $count = 1;
    if ($export_res && mysqli_num_rows($export_res) > 0) {
        while ($row = mysqli_fetch_assoc($export_res)) {
            $eth_date = gregorian_to_ethiopian($row['edited_at']);
            $eth_time = get_ethiopian_time_display($row['edited_at']);
            $greg_time = format_gregorian_time_12h($row['edited_at']);
            $diff = (float)$row['new_price'] - (float)$row['old_price'];

            $sheet->setCellValue([1, $r], $count++);
            $sheet->setCellValue([2, $r], $eth_date['full_date']);
            $sheet->setCellValue([3, $r], $eth_time);
            $sheet->setCellValue([4, $r], $greg_time);
            $sheet->setCellValue([5, $r], $row['old_name']);
            $sheet->setCellValue([6, $r], $row['new_name']);
            $sheet->setCellValue([7, $r], (float)$row['old_price']);
            $sheet->setCellValue([8, $r], (float)$row['new_price']);
            $sheet->setCellValue([9, $r], (float)$diff);
            $sheet->setCellValue([10, $r], $row['edited_by']);

            $sheet->getStyle([7, $r])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle([8, $r])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle([9, $r])->getNumberFormat()->setFormatCode('#,##0.00');

            if ($diff > 0) {
                $sheet->getStyle([9, $r])->getFont()->setBold(true)->getColor()->setRGB('059669');
            } elseif ($diff < 0) {
                $sheet->getStyle([9, $r])->getFont()->setBold(true)->getColor()->setRGB('DC2626');
            }

            styleExcelDataRow($sheet, $r, $colCount, ($r % 2 === 0));
            $r++;
        }
    } else {
        $sheet->mergeCells([1, $r, $colCount, $r]);
        $sheet->setCellValue([1, $r], 'ምንም ማስተካከያ አልተገኘም');
        $sheet->getStyle([1, $r])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $r++;
    }

    downloadExcelSpreadsheet($spreadsheet, 'item_edit_history_' . $current_branch_id . '_' . date('Y-m-d'));
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/jpg" href="image\photo_2026-01-12_07-44-10.jpg">
    <title>የእቃ ማስተካከያ ታሪክ - <?php echo htmlspecialchars($current_branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
        }
        @media(min-width: 600px) {
            body { padding: 15px; }
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Branch Header */
        .branch-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        @media(min-width: 600px) {
            .branch-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 20px 30px;
            }
        }
        
        .branch-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        @media(min-width: 600px) {
            .branch-info { gap: 20px; }
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
        @media(min-width: 600px) {
            .branch-icon {
                width: 60px;
                height: 60px;
                font-size: 28px;
            }
        }
        
        .branch-details h2 {
            font-size: 20px;
            margin-bottom: 5px;
        }
        @media(min-width: 600px) {
            .branch-details h2 { font-size: 22px; }
        }
        
        .branch-details p {
            font-size: 13px;
            opacity: 0.9;
        }
        @media(min-width: 600px) {
            .branch-details p { font-size: 14px; }
        }
        
        .user-info {
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            width: 100%;
            justify-content: space-between;
        }
        @media(min-width: 600px) {
            .user-info {
                width: auto;
                font-size: 14px;
                justify-content: flex-start;
            }
        }
        
        .user-info i {
            color: #f1c40f;
        }
        
        .header {
            background: white;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        @media(min-width: 900px) {
            .header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 20px 25px;
                margin: 20px 0 25px;
            }
        }
        
        h1 { 
            color: #2c3e50; 
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        @media(min-width: 600px) {
            h1 { font-size: 24px; gap: 10px; }
        }
        
        .branch-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 0;
            margin-top: 5px;
        }
        @media(min-width: 600px) {
            .branch-badge {
                font-size: 14px;
                margin-left: 10px;
                margin-top: 0;
            }
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            width: 100%;
            justify-content: space-between;
        }
        @media(min-width: 900px) {
            .action-buttons {
                width: auto;
                justify-content: flex-end;
            }
        }
        
        .btn { 
            padding: 12px 15px; 
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
            min-height: 44px;
            flex: 1 1 calc(50% - 5px);
        }
        @media(min-width: 600px) {
            .btn {
                flex: none;
                padding: 10px 20px;
            }
        }
        
        .btn:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            flex-basis: 100%;
        }
        @media(min-width: 600px) {
            .btn-primary {
                flex-basis: auto;
            }
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .ethiopian-date-display {
            background: #fff8e1;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 15px;
            border-left: 4px solid #f57c00;
            font-weight: 600;
            color: #5d4037;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-align: center;
        }
        @media(min-width: 600px) {
            .ethiopian-date-display {
                flex-direction: row;
                justify-content: space-between;
                padding: 12px 20px;
                margin-bottom: 20px;
                text-align: left;
            }
        }
        
        .ethiopian-date-display .date-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .ethiopian-date-display .time-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: monospace;
            background: #5d4037;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .history-table { 
            width: 100%; 
            border-collapse: separate;
            border-spacing: 0;
            background: white; 
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        @media(min-width: 900px) {
            .history-table {
                display: table;
                white-space: normal;
                overflow-x: visible;
            }
        }
        
        .history-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .history-table th { 
            padding: 12px 10px; 
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: white;
            border: none;
        }
        @media(min-width: 600px) {
            .history-table th {
                padding: 15px 12px;
                font-size: 13px;
            }
        }
        
        .ethiopian-date {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 3px;
            font-size: 12px;
        }
        @media(min-width: 600px) {
            .ethiopian-date { font-size: 13px; }
        }
        
        .gregorian-time {
            font-family: monospace;
            background: #f0f0f0;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            color: #555;
            display: inline-block;
        }
        @media(min-width: 600px) {
            .gregorian-time { font-size: 12px; }
        }
        
        .history-table td { 
            padding: 12px 10px; 
            border-bottom: 1px solid #f0f0f0;
            color: #333;
            font-size: 13px;
            transition: background-color 0.2s ease;
        }
        @media(min-width: 600px) {
            .history-table td {
                padding: 14px 12px;
                font-size: 14px;
            }
        }
        
        .history-table tbody tr:hover td {
            background-color: #f8f9ff;
        }
        
        .change-arrow {
            color: #667eea;
            margin: 0 6px;
            font-weight: bold;
        }
        
        .price-diff {
            font-weight: 600;
            margin-left: 6px;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
        }
        @media(min-width: 600px) {
            .price-diff { font-size: 11px; }
        }
        
        .price-increase {
            color: #e74c3c;
            background: rgba(231, 76, 60, 0.1);
        }
        
        .price-decrease {
            color: #27ae60;
            background: rgba(39, 174, 96, 0.1);
        }
        
        .change-badge { 
            padding: 4px 10px; 
            border-radius: 20px; 
            font-size: 10px; 
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
        }
        @media(min-width: 600px) {
            .change-badge { font-size: 11px; }
        }
        
        .name-change { 
            background: #3498db;
            color: white; 
        }
        
        .price-change { 
            background: #2ecc71;
            color: white; 
        }
        
        .both-change { 
            background: #9b59b6;
            color: white; 
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }
        @media(min-width: 600px) {
            .empty-state { padding: 60px; }
        }
        
        .empty-state i {
            font-size: 40px;
            margin-bottom: 10px;
            color: #bdc3c7;
        }
        @media(min-width: 600px) {
            .empty-state i {
                font-size: 48px;
                margin-bottom: 15px;
            }
        }
        
        .empty-state h3 {
            color: #7f8c8d;
            margin-bottom: 10px;
        }
        
        .table-info {
            background: #e8f4fd;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            text-align: center;
            font-size: 13px;
        }
        @media(min-width: 600px) {
            .table-info {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                font-size: 14px;
                text-align: left;
            }
        }

        /* Print styles */
        @media print {
            .branch-header, .header, .btn, .table-info, .ethiopian-date-display {
                display: none !important;
            }
            body {
                background: white;
                padding: 10px;
            }
            .container {
                max-width: 100%;
                margin: 0;
            }
            .history-table {
                box-shadow: none;
                display: table;
                white-space: normal;
            }
            .history-table thead {
                background: #333 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="container">
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
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?php echo htmlspecialchars($user_name); ?></span>
                <span style="background: rgba(255,255,255,0.3); padding: 3px 8px; border-radius: 12px; font-size: 11px;">
                    <?php echo $user_role == 'seller' ? 'ሻጭ' : 'አስተዳዳሪ'; ?>
                </span>
            </div>
        </div>
        
        <!-- Ethiopian Date Display (for header only) -->
        <div class="ethiopian-date-display">
            <div class="date-info">
                <i class="fas fa-calendar-alt"></i>
                ዛሬ በኢትዮጵያ ዘመን አቆጣጠር: 
                <strong><?php echo $current_ethiopian['full_date']; ?></strong>
            </div>
        </div>
        
        <!-- Main Header -->
        <div class="header">
            <h1>
                <i class="fas fa-history"></i> የእቃ ማስተካከያ ታሪክ
                <span class="branch-badge">
                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?>
                </span>
            </h1>
            <div class="action-buttons">
                <a href="?export=excel" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> ኤክሴል (.xlsx)
                </a>
                <button class="btn btn-info" onclick="window.print()">
                    <i class="fas fa-print"></i> ማተም
                </button>
                <a href="seller_pos.php" class="btn btn-primary">
                    <i class="fas fa-store"></i> ወደ መሸጫ ተመለስ
                </a>
            </div>
        </div>
        
        <!-- Table Info -->
        <div class="table-info">
            <span><i class="fas fa-list"></i> ጠቅላላ መዝገቦች: <strong><?php echo $total_records; ?></strong></span>
            <span><i class="fas fa-clock"></i> ሰዓቶች በ12-ሰዓት ግሪጎሪያን ቅርጸት</span>
        </div>
        
        <!-- History Table -->
        <table class="history-table" id="historyTable">
            <thead>
                <tr>
                    <th>ሰዓት (12-ሰዓት ግሪጎሪያን)</th>
                    <th>የምርት መለያ</th>
                    <th>የተቀየረው ስም</th>
                    <th>የተቀየረው ዋጋ</th>
                    <th>የቀየረው ሰው</th>
                    <th>የተቀየረው አይነት</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (mysqli_num_rows($result) === 0) {
                    echo '<tr><td colspan="6">
                        <div class="empty-state">
                            <i class="far fa-clipboard"></i>
                            <h3>ምንም ማስተካከያ አልተገኘም</h3>
                            <p>በዚህ ቅርንጫፍ ውስጥ እስካሁን ምንም ማስተካከያ አልተደረገም</p>
                        </div>
                    </td></tr>';
                }
                
                while($row = mysqli_fetch_assoc($result)) {
                    $change_type = '';
                    $change_text = '';
                    $price_diff_class = '';
                    $price_diff_text = '';
                    
                    // Determine change type
                    if($row['old_name'] != $row['new_name'] && $row['old_price'] != $row['new_price']) {
                        $change_type = 'both-change';
                        $change_text = 'ሁለቱም';
                    } elseif($row['old_name'] != $row['new_name']) {
                        $change_type = 'name-change';
                        $change_text = 'ስም';
                    } else {
                        $change_type = 'price-change';
                        $change_text = 'ዋጋ';
                    }
                    
                    // Calculate price difference
                    if($row['old_price'] != $row['new_price']) {
                        $difference = $row['new_price'] - $row['old_price'];
                        if($difference > 0) {
                            $price_diff_class = 'price-increase';
                            $price_diff_text = '(+' . number_format($difference, 2) . ' ብር)';
                        } else {
                            $price_diff_class = 'price-decrease';
                            $price_diff_text = '(' . number_format($difference, 2) . ' ብር)';
                        }
                    }
                    
                    // Get Ethiopian date (for Ethiopian calendar display)
                    $eth_date = gregorian_to_ethiopian($row['edited_at']);
                    
                    // Get Gregorian 12-hour time (THIS IS WHAT YOU WANT)
                    $gregorian_time_12h = format_gregorian_time_12h($row['edited_at']);
                    
                    echo "<tr>
                        <td>
                            <div class='ethiopian-date'>{$eth_date['full_date']}</div>
                            <div class='gregorian-time'>{$gregorian_time_12h}</div>
                        </td>
                        <td><span style='background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-weight: 600;'>#{$row['product_id']}</span></td>
                        <td>
                            <span style='color: #7f8c8d;'>" . htmlspecialchars($row['old_name']) . "</span>
                            <span class='change-arrow'>→</span>
                            <strong>" . htmlspecialchars($row['new_name']) . "</strong>
                        </td>
                        <td>
                            <span style='color: #7f8c8d;'>" . number_format($row['old_price'], 2) . " ብር</span>
                            <span class='change-arrow'>→</span>
                            <strong>" . number_format($row['new_price'], 2) . " ብር</strong>
                            " . ($price_diff_text ? "<span class='price-diff {$price_diff_class}'>{$price_diff_text}</span>" : "") . "
                        </td>
                        <td>
                            <i class='fas fa-user-edit' style='color: #9b59b6; margin-right: 8px;'></i>
                            " . htmlspecialchars($row['edited_by']) . "
                        </td>
                        <td><span class='change-badge {$change_type}'>{$change_text}</span></td>
                    </tr>";
                }
                ?>
            </tbody>
        </table>
        
        <!-- Branch Info Footer -->
        <div style="margin-top: 20px; text-align: center; color: rgba(255,255,255,0.7); font-size: 13px;">
            <i class="fas fa-store"></i> ቅርንጫፍ: <?php echo htmlspecialchars($current_branch_name); ?> | 
            <i class="fas fa-history"></i> የዚህ ቅርንጫፍ ታሪክ ብቻ እየታየ ነው | 
            <i class="fas fa-clock"></i> ሰዓቶች በ12-ሰዓት ግሪጎሪያን ቅርጸት
        </div>
    </div>
    
    <script>
        // Table row click effect
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.history-table tbody tr');
            
            rows.forEach(row => {
                row.addEventListener('click', function() {
                    this.style.transform = 'scale(0.995)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>