<?php
// export_all_transactions.php - Native PhpSpreadsheet XLSX Exporter
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
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$payment_filter = isset($_GET['payment']) ? trim($_GET['payment']) : '';

$where_clauses = ["t.branch_id = $branch_id"];
if (!empty($date_from)) {
    $safe_from = mysqli_real_escape_string($conn, $date_from);
    $where_clauses[] = "DATE(t.transaction_date) >= '$safe_from'";
}
if (!empty($date_to)) {
    $safe_to = mysqli_real_escape_string($conn, $date_to);
    $where_clauses[] = "DATE(t.transaction_date) <= '$safe_to'";
}
if (!empty($payment_filter)) {
    $safe_payment = mysqli_real_escape_string($conn, $payment_filter);
    $where_clauses[] = "t.payment_method = '$safe_payment'";
}

$where_sql = implode(' AND ', $where_clauses);

// Query detailed items
$query = "SELECT 
            t.id as receipt_id,
            t.transaction_date,
            t.payment_method,
            t.seller_name,
            ti.product_name,
            ti.quantity,
            ti.unit_price,
            ti.subtotal
          FROM transactions t
          LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
          WHERE $where_sql
          ORDER BY t.transaction_date DESC, t.id DESC";

$result = mysqli_query($conn, $query);

$all_rows = [];
$product_summary = [];
$grand_total_amount = 0;
$grand_total_qty = 0;

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $eth_date_info = getEthiopianDate($row['transaction_date']);
        $row['eth_date'] = $eth_date_info['formatted'];
        $row['eth_time'] = get_ethiopian_time_display($row['transaction_date']);
        $row['greg_time'] = date('h:i A', strtotime($row['transaction_date']));

        $qty = (float)($row['quantity'] ?? 0);
        $sub = (float)($row['subtotal'] ?? 0);

        $all_rows[] = $row;
        $grand_total_amount += $sub;
        $grand_total_qty += $qty;

        $pname = $row['product_name'] ?? 'ያልታወቀ';
        if (!isset($product_summary[$pname])) {
            $product_summary[$pname] = [
                'total_qty' => 0,
                'total_amount' => 0,
                'tx_count' => 0
            ];
        }
        $product_summary[$pname]['total_qty'] += $qty;
        $product_summary[$pname]['total_amount'] += $sub;
        $product_summary[$pname]['tx_count']++;
    }
}

if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

// Date range display
$date_info = "ሁሉም ግብይቶች";
if (!empty($date_from) && !empty($date_to)) {
    $date_info = "ከ $date_from እስከ $date_to";
} elseif (!empty($date_from)) {
    $date_info = "ከ $date_from ጀምሮ";
} elseif (!empty($date_to)) {
    $date_info = "እስከ $date_to ድረስ";
}

// ========== SHEET 1: TRANSACTIONS LIST ==========
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('የግብይቶች ዝርዝር');

$colCount1 = 10;
$widths1 = [14, 18, 16, 14, 20, 16, 26, 14, 16, 18];
foreach ($widths1 as $i => $w) {
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $sheet1->getColumnDimension($colLetter)->setWidth($w);
}

$nextRow1 = renderExcelBannerReal($sheet1, 'የሽያጭ ግብይቶች ሙሉ ሪፖርት (All Transactions Report)', $branch_name, 'የቀን ክልል: ' . $date_info, 1, $colCount1);

$headers1 = ['ደረሰኝ #', 'የኢትዮጵያ ቀን', 'የኢትዮጵያ ሰዓት', 'ግሪጎሪያን ሰዓት', 'ሻጭ', 'የክፍያ ዘዴ', 'ምርት (Product)', 'ብዛት', 'ነጠላ ዋጋ (ብር)', 'ጠቅላላ ዋጋ (ብር)'];
foreach ($headers1 as $i => $label) {
    $sheet1->setCellValue([$i + 1, $nextRow1], $label);
}
styleExcelHeaderRow($sheet1, $nextRow1, $colCount1);
$r1 = $nextRow1 + 1;

if (!empty($all_rows)) {
    foreach ($all_rows as $row) {
        $sheet1->setCellValue([1, $r1], '#' . str_pad($row['receipt_id'], 6, '0', STR_PAD_LEFT));
        $sheet1->setCellValue([2, $r1], $row['eth_date']);
        $sheet1->setCellValue([3, $r1], $row['eth_time']);
        $sheet1->setCellValue([4, $r1], $row['greg_time']);
        $sheet1->setCellValue([5, $r1], $row['seller_name']);
        $sheet1->setCellValue([6, $r1], ucfirst($row['payment_method'] ?? 'cash'));
        $sheet1->setCellValue([7, $r1], $row['product_name']);
        $sheet1->setCellValue([8, $r1], (float)$row['quantity']);
        $sheet1->setCellValue([9, $r1], (float)$row['unit_price']);
        $sheet1->setCellValue([10, $r1], (float)$row['subtotal']);

        $sheet1->getStyle([8, $r1])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet1->getStyle([9, $r1])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet1->getStyle([10, $r1])->getNumberFormat()->setFormatCode('#,##0.00');

        styleExcelDataRow($sheet1, $r1, $colCount1, ($r1 % 2 === 0));
        $r1++;
    }

    // Grand total row
    $sheet1->setCellValue([1, $r1], 'ጠቅላላ ድምር (TOTAL)');
    $sheet1->setCellValue([8, $r1], (float)$grand_total_qty);
    $sheet1->setCellValue([10, $r1], (float)$grand_total_amount);

    for ($c = 1; $c <= $colCount1; $c++) {
        $cell = $sheet1->getCell([$c, $r1]);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
        $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('0F172A');
        if ($c == 8 || $c == 10) {
            $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }
} else {
    $sheet1->mergeCells([1, $r1, $colCount1, $r1]);
    $sheet1->setCellValue([1, $r1], 'ምንም ግብይት አልተገኘም');
    $sheet1->getStyle([1, $r1])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
}

// ========== SHEET 2: PRODUCT SUMMARY ==========
if (!empty($product_summary)) {
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('የምርት ማጠቃለያ');

    $colCount2 = 4;
    $widths2 = [26, 18, 20, 14];
    foreach ($widths2 as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet2->getColumnDimension($colLetter)->setWidth($w);
    }

    $nextRow2 = renderExcelBannerReal($sheet2, 'የምርት ሽያጭ ማጠቃለያ (Product Summary)', $branch_name, 'የቀን ክልል: ' . $date_info, 1, $colCount2);

    $headers2 = ['ምርት (Product)', 'የተሸጠ ብዛት', 'ጠቅላላ ሽያጭ (ብር)', 'የግብይት ብዛት'];
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

    // Summary total
    $sheet2->setCellValue([1, $r2], 'ድምር (TOTAL)');
    $sheet2->setCellValue([2, $r2], (float)$grand_total_qty);
    $sheet2->setCellValue([3, $r2], (float)$grand_total_amount);
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
downloadExcelSpreadsheet($spreadsheet, 'transactions_report_' . $branch_id . '_' . date('Y-m-d'));

