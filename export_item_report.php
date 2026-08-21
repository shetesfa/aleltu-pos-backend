<?php
// export_item_report.php - Native PhpSpreadsheet XLSX Exporter
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Force branch validation
$branch_id = getCurrentBranchId($conn, $user_id, $user_role);
if ($user_role === 'super_admin' && isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0) {
    $branch_id = (int)$_GET['branch_id'];
}
$branch_name = getCurrentBranchName($conn, $branch_id);

// Filters
$item_name = isset($_GET['item']) ? trim($_GET['item']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$where_clauses = ["t.branch_id = $branch_id"];
if (!empty($item_name)) {
    $safe_item = mysqli_real_escape_string($conn, $item_name);
    $where_clauses[] = "ti.product_name LIKE '%$safe_item%'";
}
if (!empty($date_from)) {
    $safe_from = mysqli_real_escape_string($conn, $date_from);
    $where_clauses[] = "DATE(t.transaction_date) >= '$safe_from'";
}
if (!empty($date_to)) {
    $safe_to = mysqli_real_escape_string($conn, $date_to);
    $where_clauses[] = "DATE(t.transaction_date) <= '$safe_to'";
}

$where_sql = implode(' AND ', $where_clauses);

// Query aggregated sales data
$query = "SELECT 
            ti.product_name,
            SUM(ti.quantity) as total_quantity,
            SUM(ti.subtotal) as total_amount,
            COUNT(DISTINCT ti.transaction_id) as transaction_count
          FROM transaction_items ti
          JOIN transactions t ON ti.transaction_id = t.id
          WHERE $where_sql
          GROUP BY ti.product_name
          ORDER BY total_amount DESC";

$result = mysqli_query($conn, $query);

if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('የምርቶች ሽያጭ');

$colCount = 5;
$widths = [8, 30, 20, 16, 22];
foreach ($widths as $i => $w) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet->getColumnDimension($colLetter)->setWidth($w);
}

// Date banner text
$date_info = "ሁሉም ቀናት";
if (!empty($date_from) && !empty($date_to)) {
    $date_info = "ከ $date_from እስከ $date_to";
} elseif (!empty($date_from)) {
    $date_info = "ከ $date_from ጀምሮ";
} elseif (!empty($date_to)) {
    $date_info = "እስከ $date_to ድረስ";
}
if (!empty($item_name)) {
    $date_info .= " | ቃል: '$item_name'";
}

$nextRow = renderExcelBannerReal($sheet, 'የምርቶች ሽያጭ ሪፖርት (Item Sales Report)', $branch_name, 'የቀን ክልል: ' . $date_info, 1, $colCount);

$headers = ['#', 'የምርት ስም (Product Name)', 'የተሸጠ ብዛት (Qty)', 'የግብይት ብዛት', 'ጠቅላላ ሽያጭ (ብር)'];
foreach ($headers as $i => $label) {
    $sheet->setCellValue([$i + 1, $nextRow], $label);
}
styleExcelHeaderRow($sheet, $nextRow, $colCount);
$r = $nextRow + 1;

$grand_qty = 0;
$grand_amount = 0;
$grand_tx_count = 0;
$counter = 1;

if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $qty = (float)($row['total_quantity'] ?? 0);
        $amt = (float)($row['total_amount'] ?? 0);
        $tx = (int)($row['transaction_count'] ?? 0);

        $grand_qty += $qty;
        $grand_amount += $amt;
        $grand_tx_count += $tx;

        $sheet->setCellValue([1, $r], $counter++);
        $sheet->setCellValue([2, $r], $row['product_name']);
        $sheet->setCellValue([3, $r], $qty);
        $sheet->setCellValue([4, $r], $tx);
        $sheet->setCellValue([5, $r], $amt);

        $sheet->getStyle([3, $r])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle([5, $r])->getNumberFormat()->setFormatCode('#,##0.00');

        styleExcelDataRow($sheet, $r, $colCount, ($r % 2 === 0));
        $r++;
    }

    // Grand total row
    $sheet->setCellValue([1, $r], 'ጠቅላላ ድምር (TOTAL)');
    $sheet->setCellValue([3, $r], (float)$grand_qty);
    $sheet->setCellValue([4, $r], (int)$grand_tx_count);
    $sheet->setCellValue([5, $r], (float)$grand_amount);

    for ($c = 1; $c <= $colCount; $c++) {
        $cell = $sheet->getCell([$c, $r]);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
        $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('0F172A');
        if ($c == 3 || $c == 5) {
            $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }
} else {
    $sheet->mergeCells([1, $r, $colCount, $r]);
    $sheet->setCellValue([1, $r], 'ምንም የምርት ሽያጭ መረጃ አልተገኘም');
    $sheet->getStyle([1, $r])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
}

downloadExcelSpreadsheet($spreadsheet, 'item_sales_report_' . $branch_id . '_' . date('Y-m-d'));

