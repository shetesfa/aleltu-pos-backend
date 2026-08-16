<?php
session_start();
require_once 'config.php';

// Set timezone
date_default_timezone_set('Africa/Addis_Ababa');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'export_excel') {

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $branch_id = intval($_POST['branch_id']);
    $branch_name = $_POST['branch_name'];

    // Get withdrawals for the date range
    $query = "SELECT dw.*, u.username as user_username 
              FROM daily_withdrawals dw
              LEFT JOIN users u ON dw.user_id = u.id
              WHERE DATE(dw.created_at) BETWEEN '$start_date' AND '$end_date'
              AND dw.branch_id = $branch_id
              ORDER BY dw.created_at DESC";

    $result = mysqli_query($conn, $query);

    $withdrawals = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $withdrawals[] = $row;
    }

    // Format dates for display
    $start_display = date('F j, Y', strtotime($start_date));
    $end_display = date('F j, Y', strtotime($end_date));
    if ($start_date == $end_date) {
        $date_range_display = $start_display;
    } else {
        $date_range_display = $start_display . ' - ' . $end_display;
    }

    $colCount = 8; // #, Date, Time, Person, Type, Payment, Reason, Amount

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('የወጪ ሪፖርት');

    // Reasonable column widths so the banner/gradient reads well
    $widths = [6, 14, 10, 12, 12, 12, 30, 14];
    foreach ($widths as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->getColumnDimension($colLetter)->setWidth($w);
    }

    // Banner: gradient strip, centered embedded logo, title, branch/date pill
    $nextRow = renderExcelBannerReal(
        $sheet,
        'የወጪ ሪፖርት (Withdrawals Report)',
        $branch_name,
        'የቀን ክልል: ' . $date_range_display,
        1,
        $colCount
    );

    // Table header row
    $headers = ['#', 'የኢትዮጵያ ቀን', 'ሰዓት', 'ሰው', 'የወጪ አይነት', 'ክፍያ አይነት', 'ምክንያት', 'መጠን (ETB)'];
    foreach ($headers as $i => $label) {
        $sheet->setCellValue([$i + 1, $nextRow], $label);
    }
    styleExcelHeaderRow($sheet, $nextRow, $colCount);
    $dataStartRow = $nextRow + 1;
    $row = $dataStartRow;

    if (count($withdrawals) > 0) {
        $counter = 1;
        foreach ($withdrawals as $w) {
            $eth_date = !empty($w['ethiopian_date']) ? $w['ethiopian_date'] : date('Y/m/d', strtotime($w['created_at']));
            $payment_display = ucwords(str_replace('_', ' ', $w['payment_type']));
            $amountColor = ($w['withdrawal_type'] == 'ጊዜያዊ') ? 'B45309' : 'B91C1C';

            $sheet->setCellValue([1, $row], $counter++);
            $sheet->setCellValue([2, $row], $eth_date);
            $sheet->setCellValue([3, $row], date('h:i A', strtotime($w['created_at'])));
            $sheet->setCellValue([4, $row], $w['user_username'] ?? $w['username'] ?? '');
            $sheet->setCellValue([5, $row], $w['withdrawal_type']);
            $sheet->setCellValue([6, $row], $payment_display);
            $sheet->setCellValue([7, $row], substr($w['reason'], 0, 100));
            $sheet->setCellValue([8, $row], (float)$w['amount']);
            $sheet->getStyle([8, $row])->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle([8, $row])->getFont()->setBold(true)->getColor()->setRGB($amountColor);

            styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
            $row++;
        }
    } else {
        $sheet->mergeCells([1, $row, $colCount, $row]);
        $sheet->setCellValue([1, $row], 'ምንም ወጪ አልተገኘም');
        $sheet->getStyle([1, $row])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    // Footer
    $row++;
    $sheet->mergeCells([1, $row, $colCount, $row]);
    $sheet->setCellValue([1, $row], 'ሪፖርት የተዘጋጀበት ቀን: ' . date('Y-m-d H:i:s'));
    $sheet->getStyle([1, $row])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $row++;
    $sheet->mergeCells([1, $row, $colCount, $row]);
    $sheet->setCellValue([1, $row], '© 2018 አሌልቱ የእንስሳት ተዋጽኦ - ሁሉም መብቶች ተጠብቀዋል');
    $sheet->getStyle([1, $row])->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->freezePane([1, $dataStartRow]);

    downloadExcelSpreadsheet($spreadsheet, 'withdrawals_report_' . $start_date . '_to_' . $end_date);

} else {
    header("Location: daily_cashier.php");
    exit();
}

mysqli_close($conn);
