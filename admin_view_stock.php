<?php
/**
 * admin_view_stock.php - Premium Mobile-First Stock Inflow & Inventory Audit Report for Admin & Super Admin
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
date_default_timezone_set('Africa/Addis_Ababa');

// Check authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: index.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$user_role = $_SESSION['role'] ?? 'admin';

// Branch management
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);
$all_branches = ($user_role === 'super_admin') ? getAllBranches($conn) : [];

// Ethiopian Calendar & Time Helper
$today_eth = getEthiopianDate();
$today_display = $today_eth['formatted'];

if (!function_exists('get_ethiopian_time_display')) {
    function get_ethiopian_time_display($gregorian_datetime) {
        if (empty($gregorian_datetime)) return '-';
        $ts = strtotime($gregorian_datetime);
        if (!$ts) return '-';
        
        $dt = new DateTime($gregorian_datetime, new DateTimeZone('Africa/Addis_Ababa'));
        $hour24 = (int)$dt->format('G'); // 0-23
        $minute = $dt->format('i');
        
        // Ethiopian 12-hour calculation (6:00 AM is 12:00 ጥዋት, 7:00 AM is 1:00 ጥዋት)
        $eth_hour = ($hour24 - 6) % 12;
        if ($eth_hour < 0) {
            $eth_hour += 12;
        }
        if ($eth_hour === 0) {
            $eth_hour = 12;
        }
        
        if ($hour24 >= 6 && $hour24 < 12) {
            $period = 'ጥዋት';
        } elseif ($hour24 >= 12 && $hour24 < 18) {
            $period = 'ከሰዓት';
        } elseif ($hour24 >= 18 && $hour24 < 24) {
            $period = 'ማታ';
        } else {
            $period = 'ሌሊት';
        }
        
        return sprintf("%d:%s %s", $eth_hour, $minute, $period);
    }
}

// -------------------------------------------------------------
// FILTER PARAMETERS
// -------------------------------------------------------------
$date_range      = $_GET['date_range'] ?? 'last7days';
$custom_start    = trim($_GET['custom_start'] ?? '');
$custom_end      = trim($_GET['custom_end'] ?? '');
$selected_seller = intval($_GET['seller_id'] ?? 0);
$selected_product= trim($_GET['product_name'] ?? '');
$selected_source = trim($_GET['source'] ?? '');
$search_term     = trim($_GET['search'] ?? '');

// Date Range Calculation
$start_date = '';
$end_date   = '';
$period_text = 'ያለፈው 1 ሳምንት';

$range_map = [
    'today'      => ['days' => 0,   'text' => 'የዛሬ'],
    'yesterday'  => ['days' => 1,   'text' => 'የትላንት'],
    'last3days'  => ['days' => 3,   'text' => 'ያለፉት 3 ቀናት'],
    'last7days'  => ['days' => 7,   'text' => 'ያለፈው 1 ሳምንት'],
    'last2weeks' => ['days' => 14,  'text' => 'ያለፉት 2 ሳምንታት'],
    'last1month' => ['days' => 30,  'text' => 'ያለፈው 1 ወር'],
    'last3months'=> ['days' => 90,  'text' => 'ያለፉት 3 ወራት'],
    'last6months'=> ['days' => 180, 'text' => 'ያለፉት 6 ወራት'],
    'last1year'  => ['days' => 365, 'text' => 'ባለፈው 1 ዓመት'],
    'all'        => ['days' => -1,  'text' => 'የሁሉም ጊዜ'],
    'custom'     => ['days' => 0,   'text' => 'Custom Date']
];

if ($date_range === 'custom' && !empty($custom_start) && !empty($custom_end)) {
    $start_date = $custom_start . ' 00:00:00';
    $end_date   = $custom_end . ' 23:59:59';
    $period_text = "$custom_start እስከ $custom_end";
} elseif ($date_range === 'yesterday') {
    $yest = date('Y-m-d', strtotime('-1 day'));
    $start_date = $yest . ' 00:00:00';
    $end_date   = $yest . ' 23:59:59';
    $period_text = 'ትናንት (' . $yest . ')';
} elseif ($date_range === 'today') {
    $today = date('Y-m-d');
    $start_date = $today . ' 00:00:00';
    $end_date   = $today . ' 23:59:59';
    $period_text = 'ዛሬ (' . $today . ')';
} elseif (isset($range_map[$date_range]) && $range_map[$date_range]['days'] > 0) {
    $days = $range_map[$date_range]['days'];
    $start_date = date('Y-m-d 00:00:00', strtotime("-$days days"));
    $end_date   = date('Y-m-d 23:59:59');
    $period_text = $range_map[$date_range]['text'];
} elseif ($date_range === 'all') {
    $period_text = 'የሁሉም ጊዜ';
}

// -------------------------------------------------------------
// BUILD SQL QUERY WITH PREPARED PARAMETERS
// -------------------------------------------------------------
$where_clauses = [];
$params = [];
$param_types = '';

// Branch Filter
if ($current_branch_id > 0) {
    $where_clauses[] = "s.branch_id = ?";
    $param_types .= 'i';
    $params[] = $current_branch_id;
}

// Date Filter
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "s.date_added BETWEEN ? AND ?";
    $param_types .= 'ss';
    $params[] = $start_date;
    $params[] = $end_date;
}

// Seller Filter
if ($selected_seller > 0) {
    $where_clauses[] = "s.seller_id = ?";
    $param_types .= 'i';
    $params[] = $selected_seller;
}

// Product Filter
if (!empty($selected_product)) {
    $where_clauses[] = "s.item_name = ?";
    $param_types .= 's';
    $params[] = $selected_product;
}

// Source Filter
if (!empty($selected_source)) {
    $where_clauses[] = "s.source = ?";
    $param_types .= 's';
    $params[] = $selected_source;
}

// Search Filter
if (!empty($search_term)) {
    $where_clauses[] = "(s.item_name LIKE ? OR s.notes LIKE ? OR s.seller_name LIKE ? OR s.source LIKE ?)";
    $param_types .= 'ssss';
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// -------------------------------------------------------------
// 1. FETCH AGGREGATED SUMMARY METRICS
// -------------------------------------------------------------
$summary_sql = "
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT s.item_name) as unique_products,
        COALESCE(SUM(CASE WHEN s.quantity > 0 THEN s.quantity ELSE 0 END), 0) as total_inflow,
        COALESCE(SUM(CASE WHEN s.quantity < 0 THEN ABS(s.quantity) ELSE 0 END), 0) as total_outflow
    FROM stock_logs s
    $where_sql
";

$stmt = mysqli_prepare($conn, $summary_sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}
mysqli_stmt_execute($stmt);
$summary_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?? [
    'total_entries' => 0, 'unique_products' => 0, 'total_inflow' => 0, 'total_outflow' => 0
];
mysqli_stmt_close($stmt);

// -------------------------------------------------------------
// 2. FETCH BREAKDOWN BY PRODUCT
// -------------------------------------------------------------
$product_summary_sql = "
    SELECT 
        s.item_name,
        COALESCE(s.unit, 'pcs') as unit,
        COUNT(*) as entry_count,
        COALESCE(SUM(CASE WHEN s.quantity > 0 THEN s.quantity ELSE 0 END), 0) as in_qty,
        COALESCE(SUM(CASE WHEN s.quantity < 0 THEN ABS(s.quantity) ELSE 0 END), 0) as out_qty,
        COALESCE(SUM(s.quantity), 0) as net_qty
    FROM stock_logs s
    $where_sql
    GROUP BY s.item_name, s.unit
    ORDER BY in_qty DESC, s.item_name ASC
";

$stmt = mysqli_prepare($conn, $product_summary_sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}
mysqli_stmt_execute($stmt);
$prod_res = mysqli_stmt_get_result($stmt);
$product_breakdown = [];
while ($row = mysqli_fetch_assoc($prod_res)) {
    $product_breakdown[] = $row;
}
mysqli_stmt_close($stmt);

// -------------------------------------------------------------
// 3. FETCH DETAILED STOCK INFLOW ROWS
// -------------------------------------------------------------
$detail_sql = "
    SELECT 
        s.*,
        COALESCE(u.full_name, s.seller_name, 'Unknown') as seller_full_name,
        COALESCE(p.place_name, 'Branch') as branch_name
    FROM stock_logs s
    LEFT JOIN users u ON s.seller_id = u.id
    LEFT JOIN places p ON s.branch_id = p.id
    $where_sql
    ORDER BY s.date_added DESC
";

$stmt = mysqli_prepare($conn, $detail_sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
}
mysqli_stmt_execute($stmt);
$detail_res = mysqli_stmt_get_result($stmt);
$stock_logs = [];
while ($row = mysqli_fetch_assoc($detail_res)) {
    $stock_logs[] = $row;
}
mysqli_stmt_close($stmt);

// -------------------------------------------------------------
// FETCH DYNAMIC DROPDOWNS (Products, Sellers, Sources)
// -------------------------------------------------------------
$product_conds = ["item_name IS NOT NULL", "item_name != ''"];
if ($current_branch_id > 0) {
    $product_conds[] = "branch_id = $current_branch_id";
}
$product_where = "WHERE " . implode(' AND ', $product_conds);
$distinct_products_res = mysqli_query($conn, "SELECT DISTINCT item_name FROM stock_logs $product_where ORDER BY item_name ASC");
$available_products = [];
if ($distinct_products_res) {
    while ($pr = mysqli_fetch_assoc($distinct_products_res)) {
        if (!empty($pr['item_name'])) $available_products[] = $pr['item_name'];
    }
}

$seller_filter_q = ($current_branch_id > 0) ? "WHERE branch_id = $current_branch_id AND is_active = 1" : "WHERE is_active = 1";
$sellers_res = mysqli_query($conn, "SELECT id, username, full_name FROM users $seller_filter_q ORDER BY full_name ASC");
$available_sellers = [];
if ($sellers_res) {
    while ($sr = mysqli_fetch_assoc($sellers_res)) {
        $available_sellers[] = $sr;
    }
}

// Fetch Real Dynamic Sources from Database (dynamically filtered by selected product)
$source_conds = ["source IS NOT NULL", "source != ''"];
if ($current_branch_id > 0) {
    $source_conds[] = "branch_id = $current_branch_id";
}
if (!empty($selected_product)) {
    $safe_prod = mysqli_real_escape_string($conn, $selected_product);
    $source_conds[] = "item_name = '$safe_prod'";
}
$source_where = "WHERE " . implode(' AND ', $source_conds);
$sources_res = mysqli_query($conn, "SELECT DISTINCT source FROM stock_logs $source_where ORDER BY source ASC");
$available_sources = [];
if ($sources_res) {
    while ($src = mysqli_fetch_assoc($sources_res)) {
        if (!empty($src['source'])) $available_sources[] = $src['source'];
    }
}

function getSourceDisplayName($src) {
    $raw = trim($src);
    $normalized = strtolower($raw);
    $map = [
        'admin'       => 'ከአድሚን (Admin)',
        'purchase'    => 'የተገዛ (Purchase)',
        'return'      => 'ተመላሽ (Return)',
        'legedadi'    => 'ለገዳዲ (Legedadi)',
        'legeadi'     => 'ለገዳዲ (Legedadi)',
        'biruk'       => 'ብሩክ (Biruk)',
        'ብሩክ'        => 'ብሩክ (Biruk)',
        'henok'       => 'ሄኖክ (Henok)',
        'hanok'       => 'ሄኖክ (Henok)',
        'aberham'     => 'አብርሃም (Aberham)',
        'abrham'      => 'አብርሃም (Aberham)',
        'abrham ken'  => 'አብርሃም (Aberham)',
        'abrham ken ' => 'አብርሃም (Aberham)'
    ];
    return $map[$normalized] ?? $map[rtrim($normalized, ' ')] ?? htmlspecialchars($raw);
}

// -------------------------------------------------------------
// EXPORT TO EXCEL HANDLER (Native .xlsx with PhpSpreadsheet)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // ── SHEET 1: SUMMARY BY PRODUCT ──
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('የስቶክ ማጠቃለያ');

    $colCount1 = 7;
    $widths1 = [6, 25, 12, 18, 18, 18, 16];
    foreach ($widths1 as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet1->getColumnDimension($colLetter)->setWidth($w);
    }

    $nextRow = renderExcelBannerReal(
        $sheet1,
        'የስቶክ ገቢ ማጠቃለያ (Stock Inflow Summary)',
        $current_branch_name,
        'የቀን ክልል: ' . $period_text,
        1,
        $colCount1
    );

    $headers1 = ['#', 'የምርት ስም', 'መለኪያ', 'ጠቅላላ የገባ ስቶክ', 'ጠቅላላ ተመላሽ/ቅነሳ', 'የተጣራ ገቢ (Net)', 'የስቶክ ገቢ ዙር'];
    foreach ($headers1 as $i => $label) {
        $sheet1->setCellValue([$i + 1, $nextRow], $label);
    }
    styleExcelHeaderRow($sheet1, $nextRow, $colCount1);
    $dataStartRow1 = $nextRow + 1;
    $row = $dataStartRow1;

    if (!empty($product_breakdown)) {
        $counter = 1;
        foreach ($product_breakdown as $pb) {
            $sheet1->setCellValue([1, $row], $counter++);
            $sheet1->setCellValue([2, $row], $pb['item_name']);
            $sheet1->setCellValue([3, $row], $pb['unit']);
            $sheet1->setCellValue([4, $row], (float)$pb['in_qty']);
            $sheet1->setCellValue([5, $row], (float)$pb['out_qty']);
            $sheet1->setCellValue([6, $row], (float)$pb['net_qty']);
            $sheet1->setCellValue([7, $row], (int)$pb['entry_count']);

            $sheet1->getStyle([4, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet1->getStyle([5, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet1->getStyle([6, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet1->getStyle([7, $row])->getNumberFormat()->setFormatCode('#,##0');

            $sheet1->getStyle([4, $row])->getFont()->setBold(true)->getColor()->setRGB('15803D');
            if ($pb['out_qty'] > 0) {
                $sheet1->getStyle([5, $row])->getFont()->setBold(true)->getColor()->setRGB('DC2626');
            }
            $sheet1->getStyle([6, $row])->getFont()->setBold(true)->getColor()->setRGB('1E293B');

            styleExcelDataRow($sheet1, $row, $colCount1, ($row % 2 === 0));
            $row++;
        }
    } else {
        $sheet1->mergeCells([1, $row, $colCount1, $row]);
        $sheet1->setCellValue([1, $row], 'ምንም data አልተገኘም');
        $sheet1->getStyle([1, $row])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    $sheet1->freezePane([1, $dataStartRow1]);

    // ── SHEET 2: DETAILED STOCK LOGS ──
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('ዝርዝር የስቶክ ማህደር');

    $colCount2 = 10;
    $widths2 = [6, 18, 16, 16, 22, 14, 10, 18, 25, 22];
    foreach ($widths2 as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet2->getColumnDimension($colLetter)->setWidth($w);
    }

    $nextRow2 = renderExcelBannerReal(
        $sheet2,
        'ዝርዝር የስቶክ ገቢ እና እንቅስቃሴ ማህደር (Itemized Inflow Logs)',
        $current_branch_name,
        'የቀን ክልል: ' . $period_text,
        1,
        $colCount2
    );

    $headers2 = ['#', 'ተጠቃሚ/መዝጋቢ', 'የኢትዮጵያ ቀን', 'የኢትዮጵያ ሰዓት', 'የምርት ስም', 'ብዛት', 'መለኪያ', 'ምንጭ', 'ማስታወሻ', 'የተመዘገበበት ቀን (Gregorian)'];
    foreach ($headers2 as $i => $label) {
        $sheet2->setCellValue([$i + 1, $nextRow2], $label);
    }
    styleExcelHeaderRow($sheet2, $nextRow2, $colCount2);
    $dataStartRow2 = $nextRow2 + 1;
    $row2 = $dataStartRow2;

    if (!empty($stock_logs)) {
        $counter = 1;
        foreach ($stock_logs as $log) {
            $eth_log = getEthiopianDate($log['date_added']);
            $eth_time = get_ethiopian_time_display($log['date_added']);
            $is_positive = ($log['quantity'] >= 0);
            $qtyColor = $is_positive ? '15803D' : 'DC2626';

            $sheet2->setCellValue([1, $row2], $counter++);
            $sheet2->setCellValue([2, $row2], $log['seller_full_name']);
            $sheet2->setCellValue([3, $row2], $eth_log['formatted']);
            $sheet2->setCellValue([4, $row2], $eth_time);
            $sheet2->setCellValue([5, $row2], $log['item_name']);
            $sheet2->setCellValue([6, $row2], (float)$log['quantity']);
            $sheet2->setCellValue([7, $row2], $log['unit'] ?? 'pcs');
            $sheet2->setCellValue([8, $row2], getSourceDisplayName($log['source'] ?? '-'));
            $sheet2->setCellValue([9, $row2], $log['notes'] ?? '-');
            $sheet2->setCellValue([10, $row2], date('Y-m-d h:i A', strtotime($log['date_added'])));

            $sheet2->getStyle([6, $row2])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet2->getStyle([6, $row2])->getFont()->setBold(true)->getColor()->setRGB($qtyColor);

            styleExcelDataRow($sheet2, $row2, $colCount2, ($row2 % 2 === 0));
            $row2++;
        }
    } else {
        $sheet2->mergeCells([1, $row2, $colCount2, $row2]);
        $sheet2->setCellValue([1, $row2], 'ምንም data አልተገኘም');
        $sheet2->getStyle([1, $row2])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row2++;
    }

    $sheet2->freezePane([1, $dataStartRow2]);

    // Set first sheet active
    $spreadsheet->setActiveSheetIndex(0);

    downloadExcelSpreadsheet($spreadsheet, 'stock_inflow_report_' . date('Y-m-d'));
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4361ee">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>የስቶክ ገቢ እና እንቅስቃሴ ሪፖርት - አለልቱ ፖስ</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3730a3;
            --primary-light: #e0e7ff;
            --success: #10b981;
            --success-light: #dcfce7;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --purple: #8b5cf6;
            --purple-light: #ede9fe;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --shadow-card: 0 2px 12px rgba(15, 23, 42, 0.06);
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --radius-full: 9999px;
            --touch-height: 44px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body, input, select, button, textarea {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        .fa, .fas, .far, .fal, .fad, .fab, [class*="fa-"] {
            font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "FontAwesome" !important;
            font-style: normal;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-dark);
            min-height: 100vh;
            padding: 12px;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
        }

        /* ─── MOBILE-FIRST APP BAR ─── */
        .app-bar {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px 16px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .app-bar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .brand-text h1 {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .brand-text .sub {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
        }

        .app-bar-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-icon {
            width: var(--touch-height);
            height: var(--touch-height);
            border-radius: var(--radius-md);
            background: var(--bg-page);
            border: 1px solid var(--border-light);
            color: var(--text-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s;
        }
        .btn-icon.excel { background: var(--success-light); color: var(--success); border-color: #bbf7d0; }
        .btn-icon:hover { opacity: 0.9; }

        /* ─── METRIC STATS GRID ─── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 12px 14px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-card);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .stat-card.inflow .stat-icon { background: var(--success-light); color: var(--success); }
        .stat-card.outflow .stat-icon { background: var(--danger-light); color: var(--danger); }
        .stat-card.products .stat-icon { background: var(--primary-light); color: var(--primary); }
        .stat-card.entries .stat-icon { background: var(--purple-light); color: var(--purple); }

        .stat-info h4 {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-info .stat-val {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.1;
        }

        /* ─── FILTER DRAWER CARD ─── */
        .filter-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px;
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border-light);
            margin-bottom: 14px;
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            height: var(--touch-height);
            border-radius: var(--radius-md);
            border: 1.5px solid var(--border-light);
            background: var(--bg-page);
            padding: 0 12px;
            font-size: 13.5px;
            outline: none;
            transition: all 0.2s;
        }
        .form-control:focus {
            background: white;
            border-color: var(--primary);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }

        .btn {
            height: var(--touch-height);
            border-radius: var(--radius-md);
            font-size: 13.5px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; flex: 1; }
        .btn-outline { background: var(--bg-page); border: 1.5px solid var(--border-light); color: var(--text-dark); padding: 0 14px; }

        /* ─── VIEW TABS SWITCHER ─── */
        .tabs-nav {
            display: flex;
            gap: 6px;
            background: #e2e8f0;
            padding: 4px;
            border-radius: var(--radius-lg);
            margin-bottom: 14px;
        }

        .tab-btn {
            flex: 1;
            height: 38px;
            border-radius: var(--radius-md);
            border: none;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            background: transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .tab-btn.active {
            background: white;
            color: var(--text-dark);
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        /* ─── 1. MOBILE PRODUCT SUMMARY CARDS ─── */
        .mobile-product-cards {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .prod-touch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .prod-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .prod-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .prod-badges {
            display: flex;
            gap: 6px;
        }

        .badge-unit {
            background: #f1f5f9;
            color: #475569;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-entries {
            background: var(--purple-light);
            color: #6d28d9;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .prod-card-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            background: #f8fafc;
            padding: 10px;
            border-radius: var(--radius-md);
            text-align: center;
        }

        .grid-col h5 {
            font-size: 10.5px;
            color: var(--text-muted);
            margin-bottom: 2px;
            font-weight: 600;
        }

        .grid-col .val {
            font-size: 13.5px;
            font-weight: 800;
        }
        .val.in { color: var(--success); }
        .val.out { color: var(--danger); }
        .val.net { color: var(--primary); }

        /* ─── 2. MOBILE DETAILED INFLOW LOGS CARDS ─── */
        .mobile-log-cards {
            display: none;
            flex-direction: column;
            gap: 8px;
        }

        .log-touch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .log-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .log-item-name {
            font-size: 14.5px;
            font-weight: 800;
            color: var(--text-dark);
        }

        .qty-pill {
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 800;
        }
        .qty-pill.pos { background: var(--success-light); color: #15803d; }
        .qty-pill.neg { background: var(--danger-light); color: #b91c1c; }

        .log-meta-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: var(--text-muted);
            background: #f8fafc;
            padding: 6px 10px;
            border-radius: var(--radius-md);
        }

        .badge-source {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .log-note {
            font-size: 12px;
            color: #475569;
            background: #fffbeb;
            padding: 6px 10px;
            border-radius: var(--radius-md);
            border: 1px solid #fef3c7;
        }

        /* ─── DESKTOP TABLE VIEWS (HIDDEN ON MOBILE) ─── */
        .desktop-tables-container {
            display: none;
        }

        /* ─── DESKTOP MEDIA QUERY (>= 768px) ─── */
        @media (min-width: 768px) {
            body { padding: 24px; }
            .app-bar { padding: 18px 24px; margin-bottom: 20px; }
            .brand-text h1 { font-size: 20px; }
            .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
            .stat-card { padding: 18px 20px; }
            .stat-icon { width: 44px; height: 44px; font-size: 19px; }
            .stat-info .stat-val { font-size: 22px; }

            .filter-grid {
                grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
                align-items: flex-end;
            }
            .filter-actions { margin-top: 0; }

            .tabs-nav { display: none; }
            .mobile-product-cards { display: none !important; }
            .mobile-log-cards { display: none !important; }

            .desktop-tables-container {
                display: block;
            }

            .card {
                background: white;
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-card);
                border: 1px solid var(--border-light);
                padding: 22px;
                margin-bottom: 24px;
            }

            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 16px;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--border-light);
            }

            .card-title {
                font-size: 16px;
                font-weight: 700;
                color: var(--text-dark);
                display: flex;
                align-items: center;
                gap: 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13.5px;
            }

            thead th {
                background: #f8fafc;
                color: var(--text-muted);
                font-weight: 700;
                padding: 12px 14px;
                text-align: left;
                border-bottom: 2px solid var(--border-light);
                font-size: 12.5px;
                text-transform: uppercase;
            }

            tbody td {
                padding: 12px 14px;
                border-bottom: 1px solid var(--border-light);
                vertical-align: middle;
            }

            tbody tr:hover { background-color: #f8fafc; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- ─── MOBILE APP BAR ─── -->
        <header class="app-bar">
            <div class="app-bar-brand">
                <div class="brand-icon"><i class="fas fa-boxes-stacked"></i></div>
                <div class="brand-text">
                    <h1>የስቶክ ገቢ ሪፖርት</h1>
                    <div class="sub">
                        <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?></span>
                        <span>•</span>
                        <span><?php echo $today_display; ?></span>
                    </div>
                </div>
            </div>

            <div class="app-bar-actions">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn-icon excel" title="Excel አውርድ">
                    <i class="fas fa-file-excel"></i>
                </a>
                <a href="admin_dashboard.php" class="btn-icon" title="ተመለስ">
                    <i class="fas fa-arrow-left"></i>
                </a>
            </div>
        </header>

        <!-- ─── 4 METRIC CARDS (2x2 on Mobile, 4x1 on Desktop) ─── -->
        <div class="stats-grid">
            <div class="stat-card inflow">
                <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="stat-info">
                    <h4>የገባ ስቶክ</h4>
                    <div class="stat-val text-success">+<?php echo number_format($summary_stats['total_inflow'], 2); ?></div>
                </div>
            </div>

            <div class="stat-card outflow">
                <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-info">
                    <h4>ተመላሽ/ቅነሳ</h4>
                    <div class="stat-val text-danger">-<?php echo number_format($summary_stats['total_outflow'], 2); ?></div>
                </div>
            </div>

            <div class="stat-card products">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <div class="stat-info">
                    <h4>ምርቶች</h4>
                    <div class="stat-val"><?php echo number_format($summary_stats['unique_products']); ?></div>
                </div>
            </div>

            <div class="stat-card entries">
                <div class="stat-icon"><i class="fas fa-list-check"></i></div>
                <div class="stat-info">
                    <h4>የገቢ ዙሮች</h4>
                    <div class="stat-val"><?php echo number_format($summary_stats['total_entries']); ?></div>
                </div>
            </div>
        </div>

        <!-- ─── FILTER CARD ─── -->
        <div class="filter-card">
            <div class="filter-header">
                <div class="filter-title"><i class="fas fa-filter" style="color: var(--primary);"></i> ማጣሪያ (Filter)</div>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;"><?php echo htmlspecialchars($period_text); ?></span>
            </div>

            <form method="GET" action="" id="filterForm">
                <?php if ($user_role === 'super_admin' && !empty($all_branches)): ?>
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 4px;"><i class="fas fa-store"></i> ቅርንጫፍ ምረጥ:</label>
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">-- ሁሉም ቅርንጫፎች --</option>
                        <?php foreach ($all_branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($current_branch_id == $b['id']) ? 'selected' : ''; ?>>
                                🏪 <?php echo htmlspecialchars($b['place_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filter-grid">
                    <!-- Date Range -->
                    <div class="form-group">
                        <label>የቀን ክልል</label>
                        <select name="date_range" class="form-control" id="dateRangeSelect" onchange="toggleCustomDates(this.value)">
                            <option value="today" <?php echo $date_range === 'today' ? 'selected' : ''; ?>>ዛሬ (Today)</option>
                            <option value="yesterday" <?php echo $date_range === 'yesterday' ? 'selected' : ''; ?>>ትናንት (Yesterday)</option>
                            <option value="last3days" <?php echo $date_range === 'last3days' ? 'selected' : ''; ?>>ያለፉት 3 ቀናት</option>
                            <option value="last7days" <?php echo $date_range === 'last7days' ? 'selected' : ''; ?>>ያለፈው 1 ሳምንት</option>
                            <option value="last2weeks" <?php echo $date_range === 'last2weeks' ? 'selected' : ''; ?>>ያለፉት 2 ሳምንታት</option>
                            <option value="last1month" <?php echo $date_range === 'last1month' ? 'selected' : ''; ?>>ያለፈው 1 ወር</option>
                            <option value="last3months" <?php echo $date_range === 'last3months' ? 'selected' : ''; ?>>ያለፉት 3 ወራት</option>
                            <option value="last6months" <?php echo $date_range === 'last6months' ? 'selected' : ''; ?>>ያለፉት 6 ወራት</option>
                            <option value="last1year" <?php echo $date_range === 'last1year' ? 'selected' : ''; ?>>ባለፈው 1 ዓመት</option>
                            <option value="all" <?php echo $date_range === 'all' ? 'selected' : ''; ?>>የሁሉም ጊዜ</option>
                            <option value="custom" <?php echo $date_range === 'custom' ? 'selected' : ''; ?>>Custom Date</option>
                        </select>
                    </div>

                    <!-- Custom Date Inputs -->
                    <div class="form-group" id="customStartGroup" style="<?php echo $date_range === 'custom' ? '' : 'display:none;'; ?>">
                        <label>ከቀን</label>
                        <input type="date" name="custom_start" class="form-control" value="<?php echo htmlspecialchars($custom_start); ?>">
                    </div>

                    <div class="form-group" id="customEndGroup" style="<?php echo $date_range === 'custom' ? '' : 'display:none;'; ?>">
                        <label>እስከ ቀን</label>
                        <input type="date" name="custom_end" class="form-control" value="<?php echo htmlspecialchars($custom_end); ?>">
                    </div>

                    <!-- Product Filter -->
                    <div class="form-group">
                        <label>የምርት ስም</label>
                        <select name="product_name" class="form-control" onchange="this.form.submit()">
                            <option value="">-- ሁሉም ምርቶች --</option>
                            <?php foreach ($available_products as $pname): ?>
                                <option value="<?php echo htmlspecialchars($pname); ?>" <?php echo ($selected_product === $pname) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Source Filter -->
                    <div class="form-group">
                        <label>የስቶክ ምንጭ</label>
                        <select name="source" class="form-control">
                            <option value="">-- ሁሉም ምንጮች --</option>
                            <?php foreach ($available_sources as $src): ?>
                                <option value="<?php echo htmlspecialchars($src); ?>" <?php echo ($selected_source === $src) ? 'selected' : ''; ?>>
                                    <?php echo getSourceDisplayName($src); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Search Input -->
                    <div class="form-group">
                        <label>ፈልግ (Search)</label>
                        <input type="text" name="search" class="form-control" placeholder="እቃ / ምንጭ / ማስታወሻ..." value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>

                    <!-- Buttons -->
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> አጣራ
                        </button>
                        <a href="admin_view_stock.php" class="btn btn-outline" title="Reset">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- ─── MOBILE VIEW TABS (SWITCH BETWEEN SUMMARY & DETAILED LOGS) ─── -->
        <div class="tabs-nav">
            <button class="tab-btn active" id="tabBtnSummary" onclick="switchMobileTab('summary')">
                <i class="fas fa-chart-pie"></i> በምርት ማጠቃለያ (<?php echo count($product_breakdown); ?>)
            </button>
            <button class="tab-btn" id="tabBtnLogs" onclick="switchMobileTab('logs')">
                <i class="fas fa-list-ul"></i> ዝርዝር የገቢ ማህደር (<?php echo count($stock_logs); ?>)
            </button>
        </div>

        <!-- ─── 1. MOBILE TOUCH CARDS: SUMMARY BY PRODUCT ─── -->
        <div class="mobile-product-cards" id="mobileSummarySection">
            <?php if (empty($product_breakdown)): ?>
                <div style="text-align: center; padding: 40px 10px; color: var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size: 32px; opacity: 0.4; margin-bottom: 8px;"></i>
                    <p>ምንም data አልተገኘም</p>
                </div>
            <?php else: ?>
                <?php foreach ($product_breakdown as $prod): ?>
                <div class="prod-touch-card">
                    <div class="prod-card-top">
                        <div class="prod-title">
                            <i class="fas fa-box" style="color: var(--primary);"></i>
                            <?php echo htmlspecialchars($prod['item_name']); ?>
                        </div>
                        <div class="prod-badges">
                            <span class="badge-unit"><?php echo htmlspecialchars($prod['unit']); ?></span>
                            <span class="badge-entries"><?php echo number_format($prod['entry_count']); ?> ዙር</span>
                        </div>
                    </div>

                    <div class="prod-card-grid">
                        <div class="grid-col">
                            <h5>የገባ (+)</h5>
                            <div class="val in">+<?php echo number_format($prod['in_qty'], 2); ?></div>
                        </div>
                        <div class="grid-col">
                            <h5>ተመላሽ (-)</h5>
                            <div class="val out"><?php echo $prod['out_qty'] > 0 ? '-' . number_format($prod['out_qty'], 2) : '0'; ?></div>
                        </div>
                        <div class="grid-col">
                            <h5>የተጣራ ገቢ (Net)</h5>
                            <div class="val net"><?php echo number_format($prod['net_qty'], 2); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ─── 2. MOBILE TOUCH CARDS: DETAILED INFLOW LOGS ─── -->
        <div class="mobile-log-cards" id="mobileLogsSection">
            <?php if (empty($stock_logs)): ?>
                <div style="text-align: center; padding: 40px 10px; color: var(--text-muted);">
                    <i class="fas fa-inbox" style="font-size: 32px; opacity: 0.4; margin-bottom: 8px;"></i>
                    <p>ምንም data አልተገኘም</p>
                </div>
            <?php else: ?>
                <?php foreach ($stock_logs as $log): 
                    $eth = getEthiopianDate($log['date_added']);
                    $is_positive = ($log['quantity'] >= 0);
                ?>
                <div class="log-touch-card">
                    <div class="log-top">
                        <div class="log-item-name">
                            <i class="fas fa-cube" style="color: var(--primary);"></i>
                            <?php echo htmlspecialchars($log['item_name']); ?>
                        </div>
                        <div class="qty-pill <?php echo $is_positive ? 'pos' : 'neg'; ?>">
                            <?php echo ($is_positive ? '+' : '') . number_format($log['quantity'], 2); ?> <?php echo htmlspecialchars($log['unit'] ?? 'pcs'); ?>
                        </div>
                    </div>

                    <div class="log-meta-bar">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($log['seller_full_name']); ?></span>
                        <span class="badge-source"><i class="fas fa-truck"></i> <?php echo getSourceDisplayName($log['source'] ?? '-'); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo $eth['formatted']; ?> (<?php echo get_ethiopian_time_display($log['date_added']); ?>)</span>
                    </div>

                    <?php if (!empty($log['notes'])): ?>
                        <div class="log-note">
                            <i class="fas fa-comment-alt"></i> <?php echo htmlspecialchars($log['notes']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ─── 3. DESKTOP TABLES (TABLET & DESKTOP SCREENS) ─── -->
        <div class="desktop-tables-container">
            <!-- 1. Summary by Product Table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-pie" style="color: var(--primary);"></i> 
                        በምርት የተጠቃለለ የስቶክ ገቢ ማጠቃለያ (Summary by Product)
                    </div>
                    <span class="badge-source" style="padding: 4px 10px; border-radius: 4px; font-weight: 700;">
                        <?php echo count($product_breakdown); ?> የተመዘገቡ ምርቶች
                    </span>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>የምርት ስም</th>
                                <th>መለኪያ</th>
                                <th>የገባ ስቶክ (+)</th>
                                <th>ተመላሽ / ቅነሳ (-)</th>
                                <th>የተጣራ ገቢ (Net)</th>
                                <th>የስቶክ ገቢ ዙር ብዛት</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($product_breakdown)): ?>
                                <tr><td colspan="7" style="text-align:center; padding: 30px; color: var(--text-muted);">ምንም data አልተገኘም</td></tr>
                            <?php else: ?>
                                <?php $idx = 1; foreach ($product_breakdown as $prod): ?>
                                <tr>
                                    <td><?php echo $idx++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($prod['item_name']); ?></strong></td>
                                    <td><span class="badge-unit"><?php echo htmlspecialchars($prod['unit']); ?></span></td>
                                    <td style="color: var(--success); font-weight: 700;">+<?php echo number_format($prod['in_qty'], 2); ?></td>
                                    <td style="color: var(--danger); font-weight: 700;"><?php echo $prod['out_qty'] > 0 ? '-' . number_format($prod['out_qty'], 2) : '0'; ?></td>
                                    <td style="font-weight: 800; color: var(--primary);"><?php echo number_format($prod['net_qty'], 2); ?></td>
                                    <td><span class="badge-entries"><?php echo number_format($prod['entry_count']); ?> ዙር</span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2. Detailed Stock Inflow Table -->
            <div class="card" style="margin-top: 24px;">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-list-ul" style="color: var(--primary);"></i> 
                        ዝርዝር የስቶክ ገቢ እና እንቅስቃሴ ማህደር (Itemized Stock Inflow Logs)
                    </div>
                    <span class="badge-source" style="padding: 4px 10px; border-radius: 4px; font-weight: 700;">
                        <?php echo count($stock_logs); ?> መዝገቦች
                    </span>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>ተጠቃሚ / መዝጋቢ</th>
                                <th>የኢትዮጵያ ቀን</th>
                                <th>የኢትዮጵያ ሰዓት</th>
                                <th>የምርት ስም</th>
                                <th>ብዛት</th>
                                <th>መለኪያ</th>
                                <th>ምንጭ</th>
                                <th>ማስታወሻ</th>
                                <th>የተመዘገበበት ቀን (Gregorian)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($stock_logs)): ?>
                                <tr><td colspan="10" style="text-align:center; padding: 40px; color: var(--text-muted);">ምንም data አልተገኘም</td></tr>
                            <?php else: ?>
                                <?php $n = 1; foreach ($stock_logs as $log): 
                                    $eth = getEthiopianDate($log['date_added']);
                                    $is_positive = ($log['quantity'] >= 0);
                                ?>
                                <tr>
                                    <td><?php echo $n++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($log['seller_full_name']); ?></strong></td>
                                    <td><?php echo $eth['formatted']; ?></td>
                                    <td style="color: var(--primary); font-weight: 600;"><?php echo get_ethiopian_time_display($log['date_added']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($log['item_name']); ?></strong></td>
                                    <td style="color: <?php echo $is_positive ? 'var(--success)' : 'var(--danger)'; ?>; font-weight: 800;">
                                        <?php echo ($is_positive ? '+' : '') . number_format($log['quantity'], 2); ?>
                                    </td>
                                    <td><span class="badge-unit"><?php echo htmlspecialchars($log['unit'] ?? 'pcs'); ?></span></td>
                                    <td><span class="badge-source"><?php echo getSourceDisplayName($log['source'] ?? '-'); ?></span></td>
                                    <td><?php echo !empty($log['notes']) ? htmlspecialchars($log['notes']) : '-'; ?></td>
                                    <td style="color: var(--text-muted); font-size: 12.5px;"><?php echo date('M d, Y h:i A', strtotime($log['date_added'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleCustomDates(val) {
            const startG = document.getElementById('customStartGroup');
            const endG = document.getElementById('customEndGroup');
            if (val === 'custom') {
                startG.style.display = 'block';
                endG.style.display = 'block';
            } else {
                startG.style.display = 'none';
                endG.style.display = 'none';
                document.getElementById('filterForm').submit();
            }
        }

        function switchMobileTab(tab) {
            const sumSec = document.getElementById('mobileSummarySection');
            const logSec = document.getElementById('mobileLogsSection');
            const btnSum = document.getElementById('tabBtnSummary');
            const btnLog = document.getElementById('tabBtnLogs');

            if (tab === 'summary') {
                sumSec.style.display = 'flex';
                logSec.style.display = 'none';
                btnSum.classList.add('active');
                btnLog.classList.remove('active');
            } else {
                sumSec.style.display = 'none';
                logSec.style.display = 'flex';
                btnLog.classList.add('active');
                btnSum.classList.remove('active');
            }
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
