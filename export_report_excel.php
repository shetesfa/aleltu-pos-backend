<?php
session_start();
require_once 'config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Set charset for Amharic
mysqli_set_charset($conn, "utf8mb4");

// Check login
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'super_admin')) {
    header("Location: index.php");
    exit();
}

// Get parameters
$view = $_GET['view'] ?? 'overview';
$period = $_GET['period'] ?? '1month';
$custom_start = $_GET['start_date'] ?? '';
$custom_end = $_GET['end_date'] ?? '';

// FORCE branch validation
$current_branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
if ($current_branch_id <= 0 && $_SESSION['role'] != 'super_admin') {
    die("Export failed: No branch access");
}
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Date ranges based on period
$today = date('Y-m-d');

$period_options = [
    '3days' => ['days' => 3, 'text' => 'ባለፉት 3 ቀናት'],
    '1week' => ['days' => 7, 'text' => 'ባለፉት 1 ሳምንት'],
    '2weeks' => ['days' => 14, 'text' => 'ባለፉት 2 ሳምንታት'],
    '3weeks' => ['days' => 21, 'text' => 'ባለፉት 3 ሳምንታት'],
    '1month' => ['days' => 30, 'text' => 'ባለፉት 1 ወር'],
    '2months' => ['days' => 60, 'text' => 'ባለፉት 2 ወራት'],
    '3months' => ['days' => 90, 'text' => 'ባለፉት 3 ወራት'],
    '6months' => ['days' => 180, 'text' => 'ባለፉት 6 ወራት'],
    '9months' => ['days' => 270, 'text' => 'ባለፉት 9 ወራት'],
    '1year' => ['days' => 365, 'text' => 'ባለፉት 1 አመት']
];

// Set date range
if ($period == 'custom' && !empty($custom_start) && !empty($custom_end)) {
    $date_from = $custom_start;
    $date_to = $custom_end;
    $period_text = "ከ $custom_start እስከ $custom_end";
} elseif (isset($period_options[$period])) {
    $days = $period_options[$period]['days'];
    $date_from = date('Y-m-d', strtotime("-{$days} days"));
    $date_to = $today;
    $period_text = $period_options[$period]['text'];
} else {
    $period = '1month';
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = $today;
    $period_text = 'ባለፉት 1 ወር';
}

$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc = mysqli_real_escape_string($conn, $date_to);

$filename = 'report_' . $view . '_' . date('Y-m-d');

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(mb_substr($period_text . ' - ሪፖርት', 0, 31));

/** Sets header row values + style, and column widths, for the given labels. */
function setupReportTable($sheet, $nextRow, $headers, $widths) {
    foreach ($widths as $i => $w) {
        $colLetter = Coordinate::stringFromColumnIndex($i + 1);
        $sheet->getColumnDimension($colLetter)->setWidth($w);
    }
    foreach ($headers as $i => $label) {
        $sheet->setCellValue([$i + 1, $nextRow], $label);
    }
    styleExcelHeaderRow($sheet, $nextRow, count($headers));
    return $nextRow + 1;
}

/** Writes a centered "no data" message spanning all columns. */
function noDataRow($sheet, $row, $colCount, $text = 'ምንም ውሂብ አልተገኘም') {
    $sheet->mergeCells([1, $row, $colCount, $row]);
    $sheet->setCellValue([1, $row], $text);
    $sheet->getStyle([1, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Generate report based on view
if ($view == 'overview') {
    $colCount = 4;
    $nextRow = renderExcelBannerReal($sheet, 'የቅርብ ጊዜ እንቅስቃሴ', $current_branch_name, 'ጊዜ: ' . $period_text, 1, $colCount);
    $row = setupReportTable($sheet, $nextRow, ['ዓይነት', 'ሻጭ', 'ዝርዝር', 'ቀን'], [12, 18, 30, 18]);

    $query = "SELECT 
        'ሽያጭ' COLLATE utf8mb4_unicode_ci as type,
        seller_name COLLATE utf8mb4_unicode_ci as seller_name,
        CONCAT(total_amount, ' ብር') COLLATE utf8mb4_unicode_ci as details,
        transaction_date as date
        FROM transactions 
        WHERE DATE(transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        UNION ALL
        SELECT 
        'ክምችት' COLLATE utf8mb4_unicode_ci as type,
        seller_name COLLATE utf8mb4_unicode_ci as seller_name,
        CONCAT(quantity, ' ', unit, ' - ', item_name) COLLATE utf8mb4_unicode_ci as details,
        date_added as date
        FROM stock_logs 
        WHERE DATE(date_added) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        UNION ALL
        SELECT 
        'ተመላሽ' COLLATE utf8mb4_unicode_ci as type,
        seller_name COLLATE utf8mb4_unicode_ci as seller_name,
        CONCAT(quantity, ' ', unit, ' - ', product_name) COLLATE utf8mb4_unicode_ci as details,
        gregorian_date as date
        FROM product_returns 
        WHERE DATE(gregorian_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND branch_id = $current_branch_id
        ORDER BY date DESC
        LIMIT 500";

    $result = mysqli_query($conn, $query);
    $hasRows = false;
    if ($result) {
        while ($r = mysqli_fetch_assoc($result)) {
            $hasRows = true;
            $sheet->setCellValue([1, $row], $r['type']);
            $sheet->setCellValue([2, $row], $r['seller_name'] ?? 'ሥርዓት');
            $sheet->setCellValue([3, $row], $r['details']);
            $sheet->setCellValue([4, $row], date('Y-m-d H:i', strtotime($r['date'])));
            styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
            $row++;
        }
    } else {
        noDataRow($sheet, $row, $colCount, 'ስህተት: ' . mysqli_error($conn));
        $row++;
    }
    if (!$hasRows && $result) {
        noDataRow($sheet, $row, $colCount);
        $row++;
    }

} elseif ($view == 'products') {
    $colCount = 6;
    $nextRow = renderExcelBannerReal($sheet, 'ከፍተኛ ሽያጭ ምርቶች', $current_branch_name, 'ጊዜ: ' . $period_text, 1, $colCount);
    $row = setupReportTable($sheet, $nextRow, ['ደረጃ', 'ምርት', 'የተሸጠበት ጊዜ', 'ጠቅላላ ጊዜ', 'ጠቅላላ ገቢ', 'ዋጋ'], [7, 22, 14, 12, 14, 20]);

    $query = "SELECT 
        ti.product_name,
        COUNT(DISTINCT t.id) as times_sold,
        SUM(ti.quantity) as total_quantity,
        SUM(ti.subtotal) as total_revenue,
        AVG(ti.unit_price) as avg_price,
        GROUP_CONCAT(DISTINCT ti.unit_price ORDER BY ti.unit_price SEPARATOR ', ') as all_prices
        FROM transactions t
        JOIN transaction_items ti ON t.id = ti.transaction_id
        WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND t.branch_id = $current_branch_id
        GROUP BY ti.product_name
        ORDER BY total_revenue DESC
        LIMIT 100";

    $result = mysqli_query($conn, $query);
    $rank = 1;
    $hasRows = false;
    while ($r = mysqli_fetch_assoc($result)) {
        $hasRows = true;
        $prices = explode(', ', $r['all_prices']);
        $unique_prices = array_unique($prices);
        $price_display = implode(' → ', array_slice($unique_prices, 0, 3));
        if (count($unique_prices) > 3) $price_display .= ' ...';

        $sheet->setCellValue([1, $row], $rank++);
        $sheet->setCellValue([2, $row], $r['product_name']);
        $sheet->setCellValue([3, $row], $r['times_sold'] . ' ጊዜ ተሸጧል');
        $sheet->setCellValue([4, $row], number_format($r['total_quantity'], 2) . ' ክፍል');
        $sheet->setCellValue([5, $row], number_format($r['total_revenue'], 2) . ' ብር');
        $sheet->setCellValue([6, $row], $price_display . ' ብር');
        styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
        $row++;
    }
    if (!$hasRows) {
        noDataRow($sheet, $row, $colCount);
        $row++;
    }

} elseif ($view == 'product_performance') {
    $colCount = 6;
    $nextRow = renderExcelBannerReal($sheet, 'የምርቶች አፈጻጸም', $current_branch_name, 'ጊዜ: ' . $period_text, 1, $colCount);
    $row = setupReportTable($sheet, $nextRow, ['ምርት', 'የተሸጠው ብዛት', 'የሽያጭ ጊዜ', 'ጠቅላላ ገቢ', 'አማካይ ዋጋ', 'የዋጋ ልዩነት'], [22, 16, 12, 14, 20, 20]);

    $query = "SELECT 
        p.name as product_name,
        si.unit,
        COALESCE(SUM(ti.quantity), 0) as total_sold,
        COUNT(DISTINCT t.id) as times_sold,
        COALESCE(SUM(ti.subtotal), 0) as total_revenue,
        COALESCE(AVG(ti.unit_price), 0) as avg_price,
        COALESCE(MIN(ti.unit_price), 0) as min_price,
        COALESCE(MAX(ti.unit_price), 0) as max_price,
        p.unit_price as base_price
        FROM products p
        LEFT JOIN seller_inventory si ON si.item_name = p.name AND si.branch_id = $current_branch_id
        LEFT JOIN transaction_items ti ON ti.product_name = p.name
        LEFT JOIN transactions t ON ti.transaction_id = t.id 
            AND DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
            AND t.branch_id = $current_branch_id
        GROUP BY p.id, p.name, p.unit_price, si.unit
        HAVING total_sold > 0
        ORDER BY total_revenue DESC";

    $result = mysqli_query($conn, $query);
    $total_sales_all = 0;
    $rowCountResult = mysqli_num_rows($result);

    while ($r = mysqli_fetch_assoc($result)) {
        $price_variation = $r['max_price'] - $r['min_price'];
        $total_sales_all += $r['total_revenue'];

        $avg_price_text = number_format($r['avg_price'], 2) . ' ብር';
        if (!empty($r['base_price']) && $r['base_price'] > 0) {
            $avg_price_text .= ' (የተቀመጠ: ' . number_format($r['base_price'], 2) . ' ብር)';
        }
        $variation_text = $price_variation > 0
            ? number_format($r['min_price'], 2) . ' - ' . number_format($r['max_price'], 2) . ' ብር'
            : 'ቋሚ';

        $sheet->setCellValue([1, $row], $r['product_name']);
        $sheet->setCellValue([2, $row], number_format($r['total_sold'], 2) . ' ' . ($r['unit'] ?? 'ክፍል'));
        $sheet->setCellValue([3, $row], $r['times_sold'] . ' ጊዜ');
        $sheet->setCellValue([4, $row], number_format($r['total_revenue'], 2) . ' ብር');
        $sheet->setCellValue([5, $row], $avg_price_text);
        $sheet->setCellValue([6, $row], $variation_text);
        styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
        $row++;
    }

    if ($rowCountResult > 0) {
        $sheet->setCellValue([1, $row], 'ድምር');
        $sheet->setCellValue([2, $row], $rowCountResult . ' ምርቶች');
        $sheet->setCellValue([3, $row], '-');
        $sheet->setCellValue([4, $row], number_format($total_sales_all, 2) . ' ብር');
        $sheet->setCellValue([5, $row], '-');
        $sheet->setCellValue([6, $row], '-');
        for ($c = 1; $c <= $colCount; $c++) {
            $cell = $sheet->getCell([$c, $row]);
            $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F5A623');
            $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('3B2412');
        }
        $row++;
    } else {
        noDataRow($sheet, $row, $colCount);
        $row++;
    }

} elseif ($view == 'sellers') {
    $colCount = 2;
    $nextRow = renderExcelBannerReal($sheet, 'የሻጮች አፈጻጸም', $current_branch_name, 'ጊዜ: ' . $period_text, 1, $colCount);
    $row = setupReportTable($sheet, $nextRow, ['ሻጭ', 'ጠቅላላ ገቢ'], [22, 18]);

    $query = "SELECT 
        t.seller_name,
        COALESCE(SUM(ti.subtotal), 0) as revenue
        FROM transactions t
        JOIN transaction_items ti ON t.id = ti.transaction_id
        WHERE DATE(t.transaction_date) BETWEEN '$date_from_esc' AND '$date_to_esc'
        AND t.branch_id = $current_branch_id
        GROUP BY t.seller_id, t.seller_name
        ORDER BY revenue DESC";

    $result = mysqli_query($conn, $query);
    $hasRows = false;
    while ($r = mysqli_fetch_assoc($result)) {
        $hasRows = true;
        $sheet->setCellValue([1, $row], $r['seller_name']);
        $sheet->setCellValue([2, $row], number_format($r['revenue'], 2) . ' ብር');
        styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
        $row++;
    }
    if (!$hasRows) {
        noDataRow($sheet, $row, $colCount);
        $row++;
    }
} else {
    $colCount = 1;
    $row = 1;
}

// Summary footer
$row++;
$sheet->mergeCells([1, $row, $colCount, $row]);
$sheet->setCellValue([1, $row], 'ሪፖርት የተዘጋጀበት ቀን: ' . date('Y-m-d H:i:s'));
$row++;
$sheet->mergeCells([1, $row, $colCount, $row]);
$sheet->setCellValue([1, $row], 'ጊዜ: ' . $period_text);
$row++;
$sheet->mergeCells([1, $row, $colCount, $row]);
$sheet->setCellValue([1, $row], 'ቅርንጫፍ: ' . $current_branch_name);

downloadExcelSpreadsheet($spreadsheet, $filename);

mysqli_close($conn);
