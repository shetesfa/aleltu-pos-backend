<?php
// Daily report view - clean table view with exact money & native Ethiopian Calendar
session_start();
require_once 'config.php';

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

// Branches for super admin
$all_branches = ($user_role === 'super_admin') ? getAllBranches($conn) : [];

$branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : getCurrentBranchId($conn, $user_id, $user_role);
if ($branch_id <= 0 && !empty($all_branches)) {
    $branch_id = (int)$all_branches[0]['id'];
}
$branch_name = getCurrentBranchName($conn, $branch_id);

// Ethiopian Months Array
$ethiopian_months = [
    1 => "መስከረም", 2 => "ጥቅምት", 3 => "ኅዳር", 4 => "ታኅሣሥ",
    5 => "ጥር", 6 => "የካቲት", 7 => "መጋቢት", 8 => "ሚያዝያ",
    9 => "ግንቦት", 10 => "ሰኔ", 11 => "ሐምሌ", 12 => "ነሐሴ", 13 => "ጳጉሜ"
];

// Current Ethiopian Date
$current_ethiopian = getEthiopianDate();

// Handle Selected Ethiopian Date (format: YYYY-MM-DD)
$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_GET['date'])) 
    ? trim($_GET['date']) 
    : $current_ethiopian['short'];

list($sel_year, $sel_month, $sel_day) = explode('-', $selected_date);
$sel_year = (int)$sel_year;
$sel_month = (int)$sel_month;
$sel_day = (int)$sel_day;

// Validate Ethiopian month / day boundaries
if ($sel_month < 1) $sel_month = 1;
if ($sel_month > 13) $sel_month = 13;
$days_in_sel_month = ($sel_month == 13) ? (($sel_year % 4 == 3) ? 6 : 5) : 30;
if ($sel_day < 1) $sel_day = 1;
if ($sel_day > $days_in_sel_month) $sel_day = $days_in_sel_month;
$selected_date = sprintf("%04d-%02d-%02d", $sel_year, $sel_month, $sel_day);

// Calculate Previous Ethiopian Day
$prev_day = $sel_day - 1;
$prev_month = $sel_month;
$prev_year = $sel_year;
if ($prev_day < 1) {
    $prev_month--;
    if ($prev_month < 1) {
        $prev_month = 13;
        $prev_year--;
    }
    $days_in_prev_month = ($prev_month == 13) ? (($prev_year % 4 == 3) ? 6 : 5) : 30;
    $prev_day = $days_in_prev_month;
}
$prev_date = sprintf("%04d-%02d-%02d", $prev_year, $prev_month, $prev_day);

// Calculate Next Ethiopian Day
$next_day = $sel_day + 1;
$next_month = $sel_month;
$next_year = $sel_year;
if ($next_day > $days_in_sel_month) {
    $next_month++;
    $next_day = 1;
    if ($next_month > 13) {
        $next_month = 1;
        $next_year++;
    }
}
$next_date = sprintf("%04d-%02d-%02d", $next_year, $next_month, $next_day);

// Convert Selected Ethiopian Date to Gregorian for Querying
$target_greg = ethiopianToGregorianDate($sel_year, $sel_month, $sel_day);
$eth_date_display = $sel_day . ' ' . $ethiopian_months[$sel_month] . ' ' . $sel_year;

// Query transactions for the selected date and branch
$query = "SELECT 
            t.id,
            t.transaction_date,
            t.payment_method,
            t.seller_name,
            ti.product_name,
            ti.quantity,
            ti.unit_price,
            ti.subtotal
          FROM transactions t
          INNER JOIN transaction_items ti ON t.id = ti.transaction_id
          WHERE DATE(t.transaction_date) = '$target_greg'
            AND t.branch_id = $branch_id
          ORDER BY t.transaction_date ASC, t.id ASC";

$result = mysqli_query($conn, $query);

$all_rows = [];
$daily_total = 0;
$total_qty_sold = 0;
$product_summary = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row['eth_time'] = get_ethiopian_time_display($row['transaction_date']);
        $row['greg_time'] = date('h:i A', strtotime($row['transaction_date']));
        $all_rows[] = $row;
        $daily_total += (float)$row['subtotal'];
        $total_qty_sold += (float)$row['quantity'];

        $pname = $row['product_name'];
        if (!isset($product_summary[$pname])) {
            $product_summary[$pname] = [
                'total_qty' => 0,
                'total_amount' => 0,
                'tx_count' => 0
            ];
        }
        $product_summary[$pname]['total_qty'] += (float)$row['quantity'];
        $product_summary[$pname]['total_amount'] += (float)$row['subtotal'];
        $product_summary[$pname]['tx_count']++;
    }
}

// ========== NATIVE PHPSPREADSHEET EXCEL EXPORT (.xlsx) ==========
if (isset($_GET['export_excel'])) {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    
    // Sheet 1: Transactions
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('የዕለቱ ግብይቶች');

    $colCount1 = 9;
    $widths1 = [14, 18, 14, 20, 16, 24, 14, 16, 18];
    foreach ($widths1 as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet1->getColumnDimension($colLetter)->setWidth($w);
    }

    $dateBannerText = "የኢትዮጵያ ቀን: $eth_date_display   |   Gregorian: $target_greg";
    $nextRow1 = renderExcelBannerReal($sheet1, 'የዕለታዊ ግብይቶች ሪፖርት (Daily Transactions)', $branch_name, $dateBannerText, 1, $colCount1);

    $headers1 = ['ደረሰኝ #', 'የኢትዮጵያ ሰዓት', 'ግሪጎሪያን ሰዓት', 'ሻጭ', 'የክፍያ ዘዴ', 'ምርት (Product)', 'ብዛት', 'ነጠላ ዋጋ (ብር)', 'ጠቅላላ ዋጋ (ብር)'];
    foreach ($headers1 as $i => $label) {
        $sheet1->setCellValue([$i + 1, $nextRow1], $label);
    }
    styleExcelHeaderRow($sheet1, $nextRow1, $colCount1);
    $r1 = $nextRow1 + 1;

    foreach ($all_rows as $row) {
        $sheet1->setCellValue([1, $r1], '#' . str_pad($row['id'], 6, '0', STR_PAD_LEFT));
        $sheet1->setCellValue([2, $r1], $row['eth_time']);
        $sheet1->setCellValue([3, $r1], $row['greg_time']);
        $sheet1->setCellValue([4, $r1], $row['seller_name']);
        $sheet1->setCellValue([5, $r1], ucfirst($row['payment_method']));
        $sheet1->setCellValue([6, $r1], $row['product_name']);
        $sheet1->setCellValue([7, $r1], (float)$row['quantity']);
        $sheet1->setCellValue([8, $r1], (float)$row['unit_price']);
        $sheet1->setCellValue([9, $r1], (float)$row['subtotal']);

        $sheet1->getStyle([7, $r1])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet1->getStyle([8, $r1])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet1->getStyle([9, $r1])->getNumberFormat()->setFormatCode('#,##0.00');

        styleExcelDataRow($sheet1, $r1, $colCount1, ($r1 % 2 === 0));
        $r1++;
    }

    // Grand total row for Sheet 1
    $sheet1->setCellValue([1, $r1], 'ጠቅላላ የዕለቱ ድምር (TOTAL)');
    $sheet1->setCellValue([7, $r1], (float)$total_qty_sold);
    $sheet1->setCellValue([9, $r1], (float)$daily_total);

    for ($c = 1; $c <= $colCount1; $c++) {
        $cell = $sheet1->getCell([$c, $r1]);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
        $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('0F172A');
        if ($c == 7 || $c == 9) {
            $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }

    // Sheet 2: Product Sales Summary
    if (!empty($product_summary)) {
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('የምርት ሽያጭ ማጠቃለያ');

        $colCount2 = 4;
        $widths2 = [26, 18, 20, 14];
        foreach ($widths2 as $i => $w) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet2->getColumnDimension($colLetter)->setWidth($w);
        }

        $nextRow2 = renderExcelBannerReal($sheet2, 'የዕለቱ የምርት ሽያጭ ማጠቃለያ (Product Summary)', $branch_name, $dateBannerText, 1, $colCount2);

        $headers2 = ['ምርት (Product)', 'የተሸጠ ብዛት', 'ጠቅላላ ሽያጭ (ብር)', 'ግብይቶች'];
        foreach ($headers2 as $i => $label) {
            $sheet2->setCellValue([$i + 1, $nextRow2], $label);
        }
        styleExcelHeaderRow($sheet2, $nextRow2, $colCount2);
        $r2 = $nextRow2 + 1;

        foreach ($product_summary as $pname => $pdata) {
            $sheet2->setCellValue([1, $r2], $pname);
            $sheet2->setCellValue([2, $r2], (float)$pdata['total_qty']);
            $sheet2->setCellValue([3, $r2], (float)$pdata['total_amount']);
            $sheet2->setCellValue([4, $r2], (int)$pdata['tx_count']);

            $sheet2->getStyle([2, $r2])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet2->getStyle([3, $r2])->getNumberFormat()->setFormatCode('#,##0.00');

            styleExcelDataRow($sheet2, $r2, $colCount2, ($r2 % 2 === 0));
            $r2++;
        }

        // Summary total row
        $sheet2->setCellValue([1, $r2], 'ድምር (TOTAL)');
        $sheet2->setCellValue([2, $r2], (float)$total_qty_sold);
        $sheet2->setCellValue([3, $r2], (float)$daily_total);
        $sheet2->setCellValue([4, $r2], count($all_rows));

        for ($c = 1; $c <= $colCount2; $c++) {
            $cell = $sheet2->getCell([$c, $r2]);
            $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
            $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('0F172A');
            if ($c == 2 || $c == 3) {
                $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }
    }

    $spreadsheet->setActiveSheetIndex(0);
    downloadExcelSpreadsheet($spreadsheet, 'daily_transactions_' . $branch_id . '_' . $selected_date);
    exit;
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>ዕለታዊ ግብይቶች - <?php echo htmlspecialchars($branch_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1E1B4B;
            --primary-light: #312E81;
            --accent: #2563EB;
            --accent-gold: #FBBF24;
            --success: #10B981;
            --bg: #F1F5F9;
            --card: #FFFFFF;
            --text: #0F172A;
            --muted: #64748B;
            --border: #E2E8F0;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body, input, select, button, textarea {
            font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
        }
        .fa, .fas, .far, .fal, .fad, .fab, [class*="fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "FontAwesome" !important;
            font-style: normal;
        }
        body {
            margin: 0;
            padding: 12px;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }
        .wrap { max-width: 1350px; margin: 0 auto; }

        /* Branch Header */
        .branch-header {
            background: linear-gradient(135deg, #1E1B4B 0%, #312E81 50%, #4338CA 100%);
            color: white;
            padding: 16px 20px;
            margin-bottom: 14px;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: 0 6px 18px rgba(30, 27, 75, 0.18);
        }
        @media(min-width: 600px) {
            .branch-header {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
        .branch-name {
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-gold);
        }
        .branch-badge {
            background: rgba(255,255,255,0.15);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.2);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .stat-card {
            background: var(--card);
            padding: 14px 16px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            gap: 4px;
            transition: all 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .stat-label { font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; display: flex; align-items: center; gap: 6px; }
        .stat-val { font-size: 18px; font-weight: 800; color: var(--primary); }

        /* Navigation Bar */
        .nav-bar {
            margin-bottom: 14px;
            padding: 12px 16px;
            background: var(--card);
            border: 1.5px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: stretch;
            border-radius: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        @media(min-width: 800px) {
            .nav-bar {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .nav-btn {
            padding: 9px 14px;
            min-height: 40px;
            background: var(--accent);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .nav-btn:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }
        .nav-btn.today { background: var(--success); }
        .nav-btn.picker { background: #7C3AED; }
        .nav-btn.excel { background: #059669; }
        .nav-btn.print { background: #475569; }
        .nav-btn.back { background: var(--primary-light); }

        .date-display-badge {
            font-size: 16px;
            font-weight: 800;
            color: var(--primary);
            padding: 8px 14px;
            background: #FEF3C7;
            border-radius: 10px;
            border: 1px solid #FDE68A;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        /* Table Styles */
        .tbl-card {
            background: var(--card);
            border-radius: 14px;
            border: 1.5px solid var(--border);
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        .tbl-wrap {
            overflow-x: auto;
            max-height: 650px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }
        th {
            background: var(--primary);
            color: var(--accent-gold);
            padding: 12px 14px;
            font-weight: 800;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid var(--primary-light);
            white-space: nowrap;
        }
        td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        tr:nth-child(even) td {
            background: #F8FAFC;
        }
        tr:hover td {
            background: #EEF2FF;
        }
        .text-right { text-align: right; }
        .total-row td {
            background: #FEF3C7 !important;
            color: #78350F;
            font-weight: 800;
            font-size: 14px;
            border-top: 2px solid #F59E0B;
        }

        .eth-time-badge {
            color: var(--primary);
            font-weight: 800;
            font-size: 13.5px;
        }
        .greg-time-sub {
            font-size: 11px;
            color: var(--muted);
        }

        /* Date Modal Styles */
        .date-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.65);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        .date-modal-overlay.active { display: flex; }
        .date-modal-content {
            background: white;
            width: 92%;
            max-width: 480px;
            border-radius: 16px;
            box-shadow: 0 12px 36px rgba(0,0,0,0.35);
            overflow: hidden;
            animation: modalSlide 0.2s ease-out;
        }
        @keyframes modalSlide {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .date-modal-header {
            background: var(--primary);
            color: white;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .date-modal-header h3 { margin: 0; font-size: 16px; color: var(--accent-gold); display: flex; align-items: center; gap: 8px; }
        .date-modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.35);
            color: #ffffff;
            font-size: 20px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease-in-out;
            padding: 0;
            line-height: 1;
        }
        .date-modal-close:hover {
            background: #EF4444;
            border-color: #EF4444;
            transform: scale(1.08);
        }
        .date-modal-footer {
            padding: 12px 18px;
            background: #F8FAFC;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-btn-close {
            background: #475569;
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            font-size: 13.5px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .modal-btn-close:hover {
            background: #334155;
        }
        .date-modal-body { padding: 20px; }

        .cal-nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 8px;
        }
        .cal-nav-btn {
            background: var(--primary);
            color: var(--accent-gold);
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
        }
        .cal-nav-btn:hover { background: var(--primary-light); }
        .cal-select {
            padding: 8px 10px;
            font-size: 13.5px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-weight: 700;
            color: var(--text);
        }
        .cal-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            text-align: center;
            font-weight: 800;
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 8px;
            background: #F1F5F9;
            padding: 8px 0;
            border-radius: 8px;
        }
        .cal-days-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
        }
        .cal-day-cell {
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #F8FAFC;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 800;
            font-size: 13.5px;
            color: var(--text);
            transition: all 0.15s ease-in-out;
        }
        .cal-day-cell:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
            transform: scale(1.05);
        }
        .cal-day-cell.empty {
            background: transparent;
            border: none;
            cursor: default;
        }
        .cal-day-cell.today {
            background: var(--success) !important;
            color: #ffffff !important;
            border-color: #059669 !important;
        }
        .cal-day-cell.active-selected {
            background: var(--accent-gold) !important;
            color: var(--primary) !important;
            border-color: #D97706 !important;
            box-shadow: 0 0 0 2px #F59E0B;
        }

        @media print {
            .nav-bar, .branch-header, .stat-card small, .date-modal-overlay, .no-print { display: none !important; }
            body { background: #fff; padding: 0; }
            .tbl-card { box-shadow: none; border: 1px solid #ccc; }
            .tbl-wrap { max-height: none; overflow: visible; }
            th { background: #1E1B4B !important; color: #fff !important; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <!-- Branch Header -->
    <div class="branch-header">
        <div class="branch-name">
            <i class="fas fa-store"></i> 
            <?php if ($_SESSION['role'] === 'super_admin' && !empty($all_branches)): ?>
                <form method="GET" style="display:inline; margin:0;" id="branchForm">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    <select name="branch_id" onchange="document.getElementById('branchForm').submit()" style="background:#0F172A; color:#FBBF24; font-weight:800; font-size:16px; padding:6px 12px; border-radius:8px; border:1px solid rgba(255,255,255,0.2); outline:none; cursor:pointer;">
                        <?php foreach ($all_branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($branch_id == $b['id']) ? 'selected' : ''; ?>>
                                🏪 <?php echo htmlspecialchars($b['place_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span>ቅርንጫፍ: <?php echo htmlspecialchars($branch_name); ?></span>
            <?php endif; ?>
        </div>
        <div class="branch-badge">
            <i class="fas fa-calendar-day"></i> ዛሬ: <?php echo $current_ethiopian['formatted']; ?>
        </div>
    </div>

    <!-- Navigation & Date Controls -->
    <div class="nav-bar">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <a href="?branch_id=<?php echo $branch_id; ?>&date=<?php echo $prev_date; ?>" class="nav-btn"><i class="fas fa-chevron-left"></i> ትናንት</a>
            
            <div class="date-display-badge" onclick="openDateModal()" title="ቀን ለመቀየር ይጫኑ">
                <i class="fas fa-calendar-alt" style="color:var(--accent);"></i>
                <span><?php echo $eth_date_display; ?></span>
            </div>

            <a href="?branch_id=<?php echo $branch_id; ?>&date=<?php echo $next_date; ?>" class="nav-btn">ነገ <i class="fas fa-chevron-right"></i></a>
        </div>

        <div class="nav-actions">
            <button type="button" onclick="openDateModal()" class="nav-btn picker">
                <i class="fas fa-calendar-check"></i> ቀን ምረጥ (Pick Date)
            </button>
            <?php if ($selected_date !== $current_ethiopian['short']): ?>
                <a href="?branch_id=<?php echo $branch_id; ?>&date=<?php echo $current_ethiopian['short']; ?>" class="nav-btn today">
                    <i class="fas fa-sun"></i> ዛሬ (Today)
                </a>
            <?php endif; ?>
            <a href="?export_excel=1&branch_id=<?php echo $branch_id; ?>&date=<?php echo $selected_date; ?>" class="nav-btn excel">
                <i class="fas fa-file-excel"></i> Excel (.xlsx)
            </a>
            <button type="button" onclick="window.print()" class="nav-btn print">
                <i class="fas fa-print"></i> Print / PDF
            </button>
            <a href="history.php" class="nav-btn back">
                <i class="fas fa-arrow-left"></i> ተመለስ
            </a>
        </div>
    </div>

    <!-- Summary Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card" onclick="openProductSummaryModal()" style="cursor:pointer; background:#EFF6FF; border-color:#BFDBFE;" title="የዕቃዎችን ዝርዝር ለማየት ይጫኑ">
            <span class="stat-label" style="color:#1D4ED8;"><i class="fas fa-cubes"></i> የተሸጡ ዕቃዎች <i class="fas fa-search" style="font-size:10px; margin-left:4px;"></i></span>
            <span class="stat-val" style="color:#1E40AF;"><?php echo count($all_rows); ?> <small style="font-size:11px; font-weight:normal; color:#2563EB; text-decoration:underline;">(ዝርዝር &rarr;)</small></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fas fa-layer-group" style="color:#059669;"></i> ጠቅላላ ብዛት (Qty)</span>
            <span class="stat-val" style="color:#059669;"><?php echo number_format($total_qty_sold, 2); ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fas fa-coins" style="color:#10B981;"></i> ጠቅላላ ገቢ (Total Revenue)</span>
            <span class="stat-val" style="color:#10B981;"><?php echo number_format($daily_total, 2); ?> <small style="font-size:12px; font-weight:600;">ብር</small></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fas fa-calendar" style="color:#7C3AED;"></i> የተመረጠው ቀን</span>
            <span class="stat-val" style="font-size:14px; color:#475569;"><?php echo $eth_date_display; ?></span>
        </div>
    </div>

    <!-- Selected Day Transactions Table -->
    <div class="tbl-card">
        <div style="padding: 14px 18px; background: var(--primary); color: var(--accent-gold); font-weight: 800; font-size: 15px; display: flex; justify-content: space-between; align-items: center;">
            <span><i class="fas fa-list-check"></i> የዕለቱ ግብይቶች ዝርዝር (Transactions List)</span>
            <span style="font-size: 13px; color: #fff; font-weight: 600;">ድምር: <?php echo count($all_rows); ?> ግብይቶች</span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ደረሰኝ #</th>
                        <th>የኢትዮጵያ ሰዓት</th>
                        <th>ሻጭ</th>
                        <th>የክፍያ ዘዴ</th>
                        <th>ምርት (Product)</th>
                        <th class="text-right">ብዛት</th>
                        <th class="text-right">ነጠላ ዋጋ (ብር)</th>
                        <th class="text-right">ጠቅላላ ዋጋ (ብር)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 45px; color: #94A3B8;">
                            <i class="fas fa-inbox" style="font-size: 44px; margin-bottom: 12px; display: block; color:#CBD5E1;"></i>
                            <b>በዚህ ቀን ምንም ግብይት አልተመዘገበም</b>
                            <div style="font-size: 12px; margin-top: 4px;"><?php echo $eth_date_display; ?> (Gregorian: <?php echo $target_greg; ?>)</div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($all_rows as $row): ?>
                        <tr>
                            <td><strong style="color:var(--accent);">#<?php echo str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                            <td>
                                <div class="eth-time-badge"><?php echo $row['eth_time']; ?></div>
                                <div class="greg-time-sub"><?php echo $row['greg_time']; ?></div>
                            </td>
                            <td><i class="fas fa-user-tag" style="color:var(--muted); font-size:11px; margin-right:4px;"></i><?php echo htmlspecialchars($row['seller_name']); ?></td>
                            <td>
                                <span style="background:#F1F5F9; padding:3px 8px; border-radius:6px; font-weight:700; font-size:12px;">
                                    <?php echo htmlspecialchars(ucfirst($row['payment_method'])); ?>
                                </span>
                            </td>
                            <td><strong style="color:var(--primary);"><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                            <td class="text-right"><?php echo number_format($row['quantity'], 2); ?></td>
                            <td class="text-right"><?php echo number_format($row['unit_price'], 2); ?></td>
                            <td class="text-right"><strong style="color:#059669;"><?php echo number_format($row['subtotal'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="5" class="text-right">ጠቅላላ የዕለቱ ድምር (TOTAL):</td>
                            <td class="text-right"><?php echo number_format($total_qty_sold, 2); ?></td>
                            <td></td>
                            <td class="text-right"><?php echo number_format($daily_total, 2); ?> ብር</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Date Selection Modal (Native Ethiopian Month Calendar Grid) -->
<div id="dateModal" class="date-modal-overlay">
    <div class="date-modal-content">
        <div class="date-modal-header">
            <h3><i class="fas fa-calendar-alt"></i> የኢትዮጵያ ወርና ቀን መምረጫ</h3>
            <button type="button" class="date-modal-close" onclick="closeDateModal()" aria-label="ዝጋ">&times;</button>
        </div>
        <div class="date-modal-body">
            <div class="cal-nav-bar">
                <button type="button" class="cal-nav-btn" onclick="prevCalMonth()">&larr; ባለፈው</button>
                <div style="display:flex; gap:6px; align-items:center;">
                    <select id="calMonthSelect" class="cal-select" onchange="onEthCalSelectChange()">
                        <?php foreach ($ethiopian_months as $mNum => $mName): ?>
                            <option value="<?php echo $mNum; ?>" <?php echo ($sel_month == $mNum) ? 'selected' : ''; ?>>
                                <?php echo $mNum . '. ' . $mName; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="calYearSelect" class="cal-select" onchange="onEthCalSelectChange()">
                        <?php 
                        $curr_eth_year = $current_ethiopian['year'];
                        for ($y = $curr_eth_year - 4; $y <= $curr_eth_year + 2; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($sel_year == $y) ? 'selected' : ''; ?>>
                                <?php echo $y; ?> ዓ.ም
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="button" class="cal-nav-btn" onclick="nextCalMonth()">ቀጣይ &rarr;</button>
            </div>

            <div class="cal-weekdays">
                <div>ሰኞ</div>
                <div>ማክ</div>
                <div>ረቡዕ</div>
                <div>ሐሙስ</div>
                <div>አርብ</div>
                <div>ቅዳሜ</div>
                <div>እሁድ</div>
            </div>

            <div id="calDaysGrid" class="cal-days-grid">
                <!-- Populated dynamically by JS -->
            </div>
        </div>
        <div class="date-modal-footer">
            <button type="button" class="modal-btn-close" onclick="closeDateModal()">
                <i class="fas fa-times"></i> ዝጋ (Close)
            </button>
        </div>
    </div>
</div>

<!-- Product Sales Summary Modal Popup -->
<div id="productSummaryModal" class="date-modal-overlay">
    <div class="date-modal-content">
        <div class="date-modal-header">
            <h3><i class="fas fa-boxes-stacked"></i> የዕለቱ የምርት ሽያጭ ማጠቃለያ</h3>
            <button type="button" class="date-modal-close" onclick="closeProductSummaryModal()" aria-label="ዝጋ">&times;</button>
        </div>
        <div class="date-modal-body" style="padding:16px;">
            <div style="margin-bottom:12px; font-weight:bold; font-size:13.5px; color:var(--text); display:flex; justify-content:space-between; align-items:center; background:#F8FAFC; padding:10px 14px; border-radius:10px; border:1px solid var(--border);">
                <span>📅 ቀን: <strong><?php echo $eth_date_display; ?></strong></span>
                <span style="color:var(--accent); font-weight:800;">ዓይነት: <?php echo count($product_summary); ?> ምርቶች</span>
            </div>
            
            <div style="max-height:380px; overflow-y:auto; border:1px solid var(--border); border-radius:10px;">
                <table style="width:100%; border-collapse:collapse; margin:0; font-size:13px;">
                    <thead>
                        <tr style="background:var(--primary-light); color:var(--accent-gold);">
                            <th style="padding:10px 12px; text-align:left;">ምርት (Product)</th>
                            <th style="padding:10px 12px; text-align:right;">የተሸጠ ብዛት</th>
                            <th style="padding:10px 12px; text-align:right;">ጠቅላላ ዋጋ (ብር)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($product_summary)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center; padding:30px; color:#94A3B8;">በዚህ ቀን ምንም ዕቃ አልተሸጠም</td>
                        </tr>
                        <?php else: ?>
                            <?php 
                            $grand_qty = 0;
                            $grand_amt = 0;
                            foreach ($product_summary as $pname => $pdata): 
                                $grand_qty += $pdata['total_qty'];
                                $grand_amt += $pdata['total_amount'];
                            ?>
                            <tr>
                                <td style="padding:10px 12px; font-weight:700; color:var(--text);">
                                    <i class="fas fa-cube" style="color:var(--accent); margin-right:6px;"></i>
                                    <?php echo htmlspecialchars($pname); ?>
                                </td>
                                <td style="padding:10px 12px; text-align:right; font-weight:800;">
                                    <?php echo number_format($pdata['total_qty'], 2); ?>
                                </td>
                                <td style="padding:10px 12px; text-align:right; font-weight:800; color:#059669;">
                                    <?php echo number_format($pdata['total_amount'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#FEF3C7; font-weight:800;">
                                <td style="padding:12px; color:#78350F;">ጠቅላላ ድምር:</td>
                                <td style="padding:12px; text-align:right; color:#78350F; font-size:14px;"><?php echo number_format($grand_qty, 2); ?></td>
                                <td style="padding:12px; text-align:right; color:#059669; font-size:15px;"><?php echo number_format($grand_amt, 2); ?> ብር</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="date-modal-footer">
            <button type="button" class="modal-btn-close" onclick="closeProductSummaryModal()">
                <i class="fas fa-times"></i> ዝጋ (Close)
            </button>
        </div>
    </div>
</div>

<script>
let ethCalYear = <?php echo $sel_year; ?>;
let ethCalMonth = <?php echo $sel_month; ?>;
let ethSelectedDateStr = '<?php echo $selected_date; ?>';
let ethTodayStr = '<?php echo $current_ethiopian['short']; ?>';
let branchId = <?php echo $branch_id; ?>;

// Ethiopian to Gregorian JS helper for Weekday Alignment
function ethiopianToJDNJS(year, month, day) {
    return (1723856 + 365) + 365 * (year - 1) + Math.floor(year / 4) + 30 * (month - 1) + day - 1;
}

function jdnToGregorianJS(jdn) {
    const l = jdn + 68569;
    const n = Math.floor((4 * l) / 146097);
    const l2 = l - Math.floor((146097 * n + 3) / 4);
    const i = Math.floor((4000 * (l2 + 1)) / 1461001);
    const l3 = l2 - Math.floor((1461 * i) / 4) + 31;
    const j = Math.floor((80 * l3) / 2447);
    const day = l3 - Math.floor((2447 * j) / 80);
    const l4 = Math.floor(j / 11);
    const month = j + 2 - (12 * l4);
    const year = 100 * (n - 49) + i + l4;
    return `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
}

function ethToGregJS(year, month, day) {
    const jdn = ethiopianToJDNJS(year, month, day);
    return jdnToGregorianJS(jdn);
}

function openDateModal() {
    const modal = document.getElementById('dateModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
    }
}

function closeDateModal() {
    const modal = document.getElementById('dateModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function openProductSummaryModal() {
    const modal = document.getElementById('productSummaryModal');
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeProductSummaryModal() {
    const modal = document.getElementById('productSummaryModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function renderEthiopianMonthCalendar(ethYear, ethMonth) {
    const grid = document.getElementById('calDaysGrid');
    const monthSelect = document.getElementById('calMonthSelect');
    const yearSelect = document.getElementById('calYearSelect');
    if (!grid) return;
    
    grid.innerHTML = '';
    if (monthSelect) monthSelect.value = ethMonth;
    if (yearSelect) yearSelect.value = ethYear;
    
    // Day 1 weekday offset (Mon=0..Sun=6)
    const g1Str = ethToGregJS(ethYear, ethMonth, 1);
    const g1Date = new Date(g1Str + 'T00:00:00');
    let dayOfWeek = g1Date.getDay(); // 0=Sun, 1=Mon, ..., 6=Sat
    let offset = (dayOfWeek === 0) ? 6 : dayOfWeek - 1;

    for (let i = 0; i < offset; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'cal-day-cell empty';
        grid.appendChild(emptyCell);
    }

    let totalDays = 30;
    if (ethMonth === 13) {
        totalDays = (ethYear % 4 === 3) ? 6 : 5;
    }
    
    for (let d = 1; d <= totalDays; d++) {
        const dateStr = sprintfEthDate(ethYear, ethMonth, d);
        const cell = document.createElement('div');
        cell.className = 'cal-day-cell';
        if (dateStr === ethTodayStr) cell.classList.add('today');
        if (dateStr === ethSelectedDateStr) cell.classList.add('active-selected');
        
        cell.innerText = d;
        cell.onclick = function() { navigateToEthDate(dateStr); };
        grid.appendChild(cell);
    }
}

function sprintfEthDate(y, m, d) {
    return `${String(y)}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

function prevCalMonth() {
    ethCalMonth--;
    if (ethCalMonth < 1) { ethCalMonth = 13; ethCalYear--; }
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function nextCalMonth() {
    ethCalMonth++;
    if (ethCalMonth > 13) { ethCalMonth = 1; ethCalYear++; }
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function onEthCalSelectChange() {
    ethCalMonth = parseInt(document.getElementById('calMonthSelect').value);
    ethCalYear = parseInt(document.getElementById('calYearSelect').value);
    renderEthiopianMonthCalendar(ethCalYear, ethCalMonth);
}

function navigateToEthDate(dateStr) {
    if (dateStr) {
        window.location.href = 'export_daily_view.php?branch_id=' + branchId + '&date=' + encodeURIComponent(dateStr);
    }
}

// Close on outside backdrop click
document.getElementById('dateModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDateModal();
});
document.getElementById('productSummaryModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeProductSummaryModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDateModal();
        closeProductSummaryModal();
    }
});

// Auto-refresh only when viewing today
<?php if ($selected_date === $current_ethiopian['short']): ?>
setTimeout(() => {
    location.reload();
}, 30000);
<?php endif; ?>
</script>

</body>
</html>
<?php 
mysqli_close($conn); 
?>
