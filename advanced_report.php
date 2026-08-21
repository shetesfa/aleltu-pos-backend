<?php
// advanced_report.php - Advanced Multi-Date Product Sales & Stock Filter/Report
// Admin & Super Admin only. Lets the user pick ANY combination of random dates
// across many different months (or a simple date range), optionally narrow to
// specific products / payment method, and see total sold quantity, revenue,
// and stock registered for that exact set of days. Supports Excel export and
// print-to-PDF.

require_once 'config.php';

// ---------------------------------------------------------------------------
// AUTH
// ---------------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'seller') {
    header("Location: index.php");
    exit();
}

$current_branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
if ($_SESSION['role'] === 'super_admin' && isset($_GET['branch_id']) && intval($_GET['branch_id']) > 0) {
    $current_branch_id = intval($_GET['branch_id']);
}
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Branch list (super admin only)
$all_branches = [];
if ($_SESSION['role'] === 'super_admin') {
    $r = mysqli_query($conn, "SELECT id, place_name FROM places WHERE status='active' ORDER BY place_name");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $all_branches[] = $row;
}

// Products for this branch (used for the filter checklist)
$products = [];
if ($current_branch_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, name FROM products WHERE branch_id = ? AND is_active = 1 ORDER BY name");
    mysqli_stmt_bind_param($stmt, "i", $current_branch_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $products[] = $row;
}

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// FILTER INPUT (Native Ethiopian Calendar First)
// ---------------------------------------------------------------------------
$mode           = ($_GET['mode'] ?? 'range') === 'dates' ? 'dates' : 'range';
$eth_today      = getEthiopianDate();

$eth_months = [
    1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ኅዳር', 4 => 'ታኅሣሥ',
    5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
    9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'
];

if (isset($_GET['eth_year_from']) && isset($_GET['eth_month_from']) && isset($_GET['eth_day_from'])) {
    $eth_year_from  = intval($_GET['eth_year_from']);
    $eth_month_from = intval($_GET['eth_month_from']);
    $eth_day_from   = intval($_GET['eth_day_from']);
    $date_from      = ethiopianToGregorianDate($eth_year_from, $eth_month_from, $eth_day_from);
} elseif (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $date_from      = $_GET['date_from'];
    $eth_f          = getEthiopianDate($date_from);
    $eth_year_from  = $eth_f['year'];
    $eth_month_from = $eth_f['month'];
    $eth_day_from   = $eth_f['day'];
} else {
    // Default to last 7 days in Ethiopian calendar
    $date_from      = date('Y-m-d', strtotime('-6 days'));
    $eth_f          = getEthiopianDate($date_from);
    $eth_year_from  = $eth_f['year'];
    $eth_month_from = $eth_f['month'];
    $eth_day_from   = $eth_f['day'];
}

if (isset($_GET['eth_year_to']) && isset($_GET['eth_month_to']) && isset($_GET['eth_day_to'])) {
    $eth_year_to    = intval($_GET['eth_year_to']);
    $eth_month_to   = intval($_GET['eth_month_to']);
    $eth_day_to     = intval($_GET['eth_day_to']);
    $date_to        = ethiopianToGregorianDate($eth_year_to, $eth_month_to, $eth_day_to);
} elseif (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $date_to        = $_GET['date_to'];
    $eth_t          = getEthiopianDate($date_to);
    $eth_year_to    = $eth_t['year'];
    $eth_month_to   = $eth_t['month'];
    $eth_day_to     = $eth_t['day'];
} else {
    // Default to today
    $date_to        = date('Y-m-d');
    $eth_t          = getEthiopianDate($date_to);
    $eth_year_to    = $eth_t['year'];
    $eth_month_to   = $eth_t['month'];
    $eth_day_to     = $eth_t['day'];
}

$raw_dates      = trim($_GET['dates'] ?? '');
$product_ids_in = trim($_GET['product_ids'] ?? '');
$has_run        = isset($_GET['run']) || isset($_GET['export']);
$export         = $_GET['export'] ?? '';

$selected_product_ids = [];
if ($product_ids_in !== '') {
    $selected_product_ids = array_values(array_filter(array_map('intval', explode(',', $product_ids_in))));
}
$selected_product_names = [];
if (!empty($selected_product_ids)) {
    foreach ($products as $p) {
        if (in_array((int)$p['id'], $selected_product_ids, true)) $selected_product_names[] = $p['name'];
    }
}

// Build the concrete list of target dates
$target_dates = [];
$date_error = '';
if ($has_run) {
    if ($mode === 'dates') {
        $tmp = [];
        foreach (explode(',', $raw_dates) as $d) {
            $d = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $tmp[$d] = true;
        }
        $target_dates = array_keys($tmp);
        sort($target_dates);
        if (empty($target_dates)) $date_error = 'እባክዎ ቢያንስ አንድ ቀን ይምረጡ (Please pick at least one date).';
    } else {
        try {
            $start = new DateTime($date_from);
            $end   = new DateTime($date_to);
        } catch (Exception $e) {
            $start = new DateTime(date('Y-m-d', strtotime('-6 days')));
            $end   = new DateTime();
        }
        if ($end < $start) { $tmp = $start; $start = $end; $end = $tmp; }
        $span = new DateTime($end->format('Y-m-d'));
        $span->modify('+1 day');
        foreach (new DatePeriod($start, new DateInterval('P1D'), $span) as $dt) {
            $target_dates[] = $dt->format('Y-m-d');
            if (count($target_dates) >= 731) break; // hard safety cap (~2 years)
        }
    }
}

// Ethiopian Date Helper Calculations
$eth_start = getEthiopianDate($date_from);
$eth_end   = getEthiopianDate($date_to);
$eth_date_range_display = ($date_from === $date_to) ? $eth_start['formatted'] : ($eth_start['formatted'] . ' እስከ ' . $eth_end['formatted']);

// ---------------------------------------------------------------------------
// QUERY
// ---------------------------------------------------------------------------
$report          = [];   // date => ['products'=>[...], 'sale_total'=>x, 'stock'=>[...], 'stock_total'=>x]
$product_totals  = [];   // product name => ['sold_qty','revenue','stock_qty','tx_count']
$grand_sold_qty  = 0;
$grand_revenue   = 0;
$grand_stock_qty = 0;
$grand_tx_count  = 0;

if ($has_run && $current_branch_id > 0 && !empty($target_dates)) {
    $esc_dates = array_map(function ($d) use ($conn) { return "'" . mysqli_real_escape_string($conn, $d) . "'"; }, $target_dates);
    $date_in = implode(',', $esc_dates);

    $prod_filter_sql  = '';
    $stock_filter_sql = '';
    if (!empty($selected_product_ids)) {
        $ids = implode(',', array_map('intval', $selected_product_ids));
        $prod_filter_sql = " AND ti.product_id IN ($ids)";
    }
    if (!empty($selected_product_names)) {
        $names = implode(',', array_map(function ($n) use ($conn) { return "'" . mysqli_real_escape_string($conn, $n) . "'"; }, $selected_product_names));
        $stock_filter_sql = " AND item_name IN ($names)";
    }

    // ---- Sales (from transaction_items joined to transactions) ----
    $sql = "SELECT DATE(t.transaction_date) d, ti.product_id, ti.product_name,
                   SUM(ti.quantity) qty, SUM(ti.subtotal) revenue, COUNT(DISTINCT t.id) tx_count
            FROM transaction_items ti
            JOIN transactions t ON t.id = ti.transaction_id
            WHERE t.branch_id = $current_branch_id
              AND DATE(t.transaction_date) IN ($date_in)
              $prod_filter_sql
            GROUP BY d, ti.product_id, ti.product_name
            ORDER BY d ASC, ti.product_name ASC";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $d = $row['d'];
            if (!isset($report[$d])) $report[$d] = ['products' => [], 'sale_total' => 0, 'stock' => [], 'stock_total' => 0];
            $report[$d]['products'][] = $row;
            $report[$d]['sale_total'] += (float)$row['revenue'];

            $key = $row['product_name'];
            if (!isset($product_totals[$key])) $product_totals[$key] = ['sold_qty' => 0, 'revenue' => 0, 'stock_qty' => 0, 'tx_count' => 0];
            $product_totals[$key]['sold_qty'] += (float)$row['qty'];
            $product_totals[$key]['revenue']  += (float)$row['revenue'];
            $product_totals[$key]['tx_count'] += (int)$row['tx_count'];

            $grand_sold_qty += (float)$row['qty'];
            $grand_revenue  += (float)$row['revenue'];
            $grand_tx_count += (int)$row['tx_count'];
        }
    }

    // ---- Stock registered (stock_logs; positive rows = stock in, negative = returns/adjustments) ----
    $stock_sql = "SELECT DATE(date_added) d, item_name, SUM(quantity) qty
                  FROM stock_logs
                  WHERE branch_id = $current_branch_id
                    AND DATE(date_added) IN ($date_in)
                    $stock_filter_sql
                  GROUP BY d, item_name
                  ORDER BY d ASC, item_name ASC";
    $sres = mysqli_query($conn, $stock_sql);
    if ($sres) {
        while ($row = mysqli_fetch_assoc($sres)) {
            $d = $row['d'];
            if (!isset($report[$d])) $report[$d] = ['products' => [], 'sale_total' => 0, 'stock' => [], 'stock_total' => 0];
            $report[$d]['stock'][] = $row;
            $report[$d]['stock_total'] += (float)$row['qty'];

            $key = $row['item_name'];
            if (!isset($product_totals[$key])) $product_totals[$key] = ['sold_qty' => 0, 'revenue' => 0, 'stock_qty' => 0, 'tx_count' => 0];
            $product_totals[$key]['stock_qty'] += (float)$row['qty'];

            $grand_stock_qty += (float)$row['qty'];
        }
    }

    ksort($report);
    uasort($product_totals, function ($a, $b) { return $b['sold_qty'] <=> $a['sold_qty']; });

    // -----------------------------------------------------------------------
    // DAILY INVENTORY FLOW (Opening -> Stock In -> Available -> Sold -> Closing)
    // -----------------------------------------------------------------------
    $min_date = !empty($target_dates) ? min($target_dates) : date('Y-m-d');
    $prior_stock = [];
    $prior_stock_sql = "SELECT item_name, SUM(quantity) qty
                        FROM stock_logs
                        WHERE branch_id = $current_branch_id
                          AND DATE(date_added) < '$min_date'
                          $stock_filter_sql
                        GROUP BY item_name";
    $psr = mysqli_query($conn, $prior_stock_sql);
    if ($psr) {
        while ($row = mysqli_fetch_assoc($psr)) {
            $prior_stock[$row['item_name']] = (float)$row['qty'];
        }
    }

    $prior_sales = [];
    $prior_sales_sql = "SELECT ti.product_name, SUM(ti.quantity) qty
                        FROM transaction_items ti
                        JOIN transactions t ON t.id = ti.transaction_id
                        WHERE t.branch_id = $current_branch_id
                          AND DATE(t.transaction_date) < '$min_date'
                          $prod_filter_sql
                        GROUP BY ti.product_name";
    $psar = mysqli_query($conn, $prior_sales_sql);
    if ($psar) {
        while ($row = mysqli_fetch_assoc($psar)) {
            $prior_sales[$row['product_name']] = (float)$row['qty'];
        }
    }

    $daily_flow = [];
    $flow_product_names = array_unique(array_merge(array_keys($product_totals), array_keys($prior_stock), array_keys($prior_sales)));
    if (!empty($selected_product_names)) {
        $flow_product_names = array_intersect($flow_product_names, $selected_product_names);
    }
    $flow_product_names = array_values(array_unique(array_merge($flow_product_names, array_keys($product_totals))));
    sort($flow_product_names);

    foreach ($flow_product_names as $pname) {
        $prior_in = (float)($prior_stock[$pname] ?? 0);
        $prior_out = (float)($prior_sales[$pname] ?? 0);
        $start_balance = $prior_in - $prior_out;
        
        $running_stock = $start_balance;
        $p_in_tot = 0;
        $p_sold_tot = 0;
        $p_rev_tot = 0;
        $p_tx_tot = 0;
        $p_days = [];

        foreach ($target_dates as $d) {
            $d_stock_in = 0;
            if (isset($report[$d]['stock'])) {
                foreach ($report[$d]['stock'] as $stk) {
                    if ($stk['item_name'] === $pname) {
                        $d_stock_in += (float)$stk['qty'];
                    }
                }
            }

            $d_sold = 0;
            $d_rev = 0;
            $d_tx = 0;
            if (isset($report[$d]['products'])) {
                foreach ($report[$d]['products'] as $prod) {
                    if ($prod['product_name'] === $pname) {
                        $d_sold += (float)$prod['qty'];
                        $d_rev  += (float)$prod['revenue'];
                        $d_tx   += (int)$prod['tx_count'];
                    }
                }
            }

            $open = $running_stock;
            $avail = $open + $d_stock_in;
            $close = $avail - $d_sold;
            $running_stock = $close;

            $p_in_tot += $d_stock_in;
            $p_sold_tot += $d_sold;
            $p_rev_tot += $d_rev;
            $p_tx_tot += $d_tx;

            $p_days[$d] = [
                'date' => $d,
                'opening' => $open,
                'stock_in' => $d_stock_in,
                'available' => $avail,
                'sold' => $d_sold,
                'closing' => $close,
                'revenue' => $d_rev,
                'tx_count' => $d_tx,
                'has_activity' => ($d_stock_in != 0 || $d_sold != 0)
            ];
        }

        if ($p_in_tot != 0 || $p_sold_tot != 0 || $start_balance != 0) {
            $daily_flow[$pname] = [
                'start_balance' => $start_balance,
                'end_balance' => $running_stock,
                'total_in' => $p_in_tot,
                'total_sold' => $p_sold_tot,
                'total_rev' => $p_rev_tot,
                'total_tx' => $p_tx_tot,
                'days' => $p_days
            ];
        }
    }
}

$days_with_data = count($report);
$days_selected  = count($target_dates);

// ---------------------------------------------------------------------------
// EXCEL EXPORT (Real .xlsx with PhpSpreadsheet & Royal Spectrum theme)
// ---------------------------------------------------------------------------
if ($export === 'excel' && $has_run) {
    $colCount = 6;
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('በምርት የተጠቃለለ');

    $widths = [24, 16, 18, 18, 18, 14];
    foreach ($widths as $i => $w) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $sheet->getColumnDimension($colLetter)->setWidth($w);
    }

    $dateInfoText = ($mode === 'dates') 
        ? 'የተመረጡ ቀናት (' . $days_selected . ' ቀናት)' 
        : ('የኢትዮጵያ ቀን: ' . $eth_date_range_display . '   |   Gregorian: ' . $date_from . ' - ' . $date_to);
    $nextRow = renderExcelBannerReal($sheet, 'ከፍተኛ የሽያጭ እና ክምችት ሪፖርት (Advanced Report)', $current_branch_name, $dateInfoText, 1, $colCount);

    $headers = ['ምርት (Product)', 'የተሸጠ ብዛት', 'ጠቅላላ ገቢ (ብር)', 'የተመዘገበ ክምችት', 'ቀሪ (Stock - Sold)', 'የሽያጭ ብዛት'];
    foreach ($headers as $i => $label) {
        $sheet->setCellValue([$i + 1, $nextRow], $label);
    }
    styleExcelHeaderRow($sheet, $nextRow, $colCount);
    $row = $nextRow + 1;

    foreach ($product_totals as $name => $t) {
        $sheet->setCellValue([1, $row], $name);
        $sheet->setCellValue([2, $row], (float)$t['sold_qty']);
        $sheet->setCellValue([3, $row], (float)$t['revenue']);
        $sheet->setCellValue([4, $row], (float)$t['stock_qty']);
        $sheet->setCellValue([5, $row], (float)($t['stock_qty'] - $t['sold_qty']));
        $sheet->setCellValue([6, $row], (int)$t['tx_count']);
        
        $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle([3, $row])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle([4, $row])->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle([5, $row])->getNumberFormat()->setFormatCode('#,##0.00');

        styleExcelDataRow($sheet, $row, $colCount, ($row % 2 === 0));
        $row++;
    }

    // Grand total row
    $sheet->setCellValue([1, $row], 'ድምር (TOTAL)');
    $sheet->setCellValue([2, $row], (float)$grand_sold_qty);
    $sheet->setCellValue([3, $row], (float)$grand_revenue);
    $sheet->setCellValue([4, $row], (float)$grand_stock_qty);
    $sheet->setCellValue([5, $row], (float)($grand_stock_qty - $grand_sold_qty));
    $sheet->setCellValue([6, $row], (int)$grand_tx_count);

    for ($c = 1; $c <= $colCount; $c++) {
        $cell = $sheet->getCell([$c, $row]);
        $cell->getStyle()->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
        $cell->getStyle()->getFont()->setBold(true)->getColor()->setRGB('0F172A');
        if ($c >= 2 && $c <= 5) {
            $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }

    // Sheet 2: Daily Inventory & Stock Flow
    if (!empty($daily_flow)) {
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('የዕለት ክምችት ፍሰት');
        
        $colCount2 = 10;
        $widths2 = [18, 14, 22, 18, 18, 18, 18, 18, 18, 12];
        foreach ($widths2 as $i => $w) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet2->getColumnDimension($colLetter)->setWidth($w);
        }

        $nextRow2 = renderExcelBannerReal($sheet2, 'የዕለት ክምችት እና ሽያጭ ሙሉ ፍሰት (Daily Stock Flow)', $current_branch_name, $dateInfoText, 1, $colCount2);

        $headers2 = ['የኢትዮጵያ ቀን', 'ግሪጎሪያን ቀን', 'ምርት (Product)', 'የትላንት ቀሪ (Opening)', 'የዛሬ ስቶክ ገቢ (Stock In)', 'ጠቅላላ የነበረ (Available)', 'የተሸጠ ብዛት (Sold)', 'የቀኑ ቀሪ (Closing)', 'የቀኑ ገቢ (ብር)', 'ግብይቶች'];
        foreach ($headers2 as $i => $label) {
            $sheet2->setCellValue([$i + 1, $nextRow2], $label);
        }
        styleExcelHeaderRow($sheet2, $nextRow2, $colCount2);
        $r2 = $nextRow2 + 1;

        foreach ($daily_flow as $pname => $pdata) {
            foreach ($pdata['days'] as $d => $drow) {
                if (!$drow['has_activity'] && $drow['opening'] == 0 && $drow['closing'] == 0) continue;
                
                $eth_row_date = getEthiopianDate($d);
                $sheet2->setCellValue([1, $r2], $eth_row_date['formatted']);
                $sheet2->setCellValue([2, $r2], $d);
                $sheet2->setCellValue([3, $r2], $pname);
                $sheet2->setCellValue([4, $r2], (float)$drow['opening']);
                $sheet2->setCellValue([5, $r2], (float)$drow['stock_in']);
                $sheet2->setCellValue([6, $r2], (float)$drow['available']);
                $sheet2->setCellValue([7, $r2], (float)$drow['sold']);
                $sheet2->setCellValue([8, $r2], (float)$drow['closing']);
                $sheet2->setCellValue([9, $r2], (float)$drow['revenue']);
                $sheet2->setCellValue([10, $r2], (int)$drow['tx_count']);

                for ($ci = 4; $ci <= 9; $ci++) {
                    $sheet2->getStyle([$ci, $r2])->getNumberFormat()->setFormatCode('#,##0.00');
                }
                styleExcelDataRow($sheet2, $r2, $colCount2, ($r2 % 2 === 0));
                $r2++;
            }
        }
        $spreadsheet->setActiveSheetIndex(0);
    }

    downloadExcelSpreadsheet($spreadsheet, 'advanced_report_' . date('Y-m-d_His'));
}

// Data passed to JS for pre-selecting dates already chosen (survives form re-submit)
$js_preselected_dates = json_encode($mode === 'dates' ? $target_dates : []);
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>የላቀ ማጣሪያ ሪፖርት | Advanced Filter Report</title>
<link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root {
  --primary: #312e81;
  --primary-dark: #1e1b4b;
  --primary-light: #4338ca;
  --accent: #10b981;
  --accent-gold: #f59e0b;
  --danger: #ef4444;
  --bg: #f8fafc;
  --card: #ffffff;
  --text: #0f172a;
  --muted: #64748b;
  --border: #e2e8f0;
}
* { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color: transparent; }
body, input, select, button, textarea {
  font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
}
.fa, .fas, .far, .fal, .fad, .fab, [class*="fa-"] {
  font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "FontAwesome" !important;
  font-style: normal;
}
body {
  background: var(--bg);
  color: var(--text);
  padding: 20px;
  line-height: 1.5;
}
.eth-pill-badge {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 700;
  margin-left: 6px;
  display: inline-block;
}
.badge-range-eth {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12.5px;
  font-weight: 700;
  margin-left: 10px;
  display: inline-block;
}
.preset-btn-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}
.preset-pill {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  color: #334155;
  padding: 6px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
.preset-pill:hover {
  background: var(--primary-light);
  color: #ffffff;
  border-color: var(--primary);
}
.eth-picker-box {
  background: #f8fafc;
  border: 1.5px solid var(--border);
  border-radius: 14px;
  padding: 14px 16px;
  transition: all 0.2s ease;
}
.eth-picker-box:focus-within {
  border-color: var(--primary);
  background: #ffffff;
  box-shadow: 0 4px 16px rgba(49, 46, 129, 0.08);
}
.eth-picker-row {
  display: flex;
  gap: 10px;
  align-items: center;
}
.eth-picker-row > div {
  flex: 1;
}
.picker-sub {
  display: block;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--muted);
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.eth-select {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--text);
  background: #ffffff;
  outline: none;
  cursor: pointer;
  transition: all 0.2s;
}
.eth-select:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
}
.cal-day .cal-day-num {
  font-size: 15px;
  font-weight: 800;
}
.wrap { max-width: 1350px; margin: 0 auto; }

/* Top Navigation Bar */
.top-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #312e81 100%);
  padding: 20px 24px;
  border-radius: 16px;
  margin-bottom: 20px;
  box-shadow: 0 10px 25px rgba(15, 23, 42, 0.2);
  border: 1px solid rgba(255,255,255,0.1);
}
.top-bar h1 {
  font-size: 22px;
  color: #fbbf24;
  display: flex;
  align-items: center;
  gap: 12px;
  font-weight: 700;
}
.branch-badge {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #0f172a;
  padding: 6px 16px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 700;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.back-btn {
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  color: #ffffff;
  padding: 10px 20px;
  border-radius: 10px;
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.3s ease;
  box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
}
.back-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
}

/* Cards & Containers */
.card {
  background: var(--card);
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 20px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
  border: 1px solid var(--border);
  transition: box-shadow 0.3s ease;
}
.card:hover {
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
}
.card h2 {
  font-size: 18px;
  color: var(--primary-dark);
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
}

/* Mode Tabs */
.mode-tabs { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.mode-tab {
  flex: 1;
  min-width: 220px;
  text-align: center;
  padding: 14px 18px;
  border: 2px solid var(--border);
  border-radius: 12px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 700;
  color: var(--muted);
  background: #f8fafc;
  transition: all 0.25s ease;
}
.mode-tab:hover { background: #e0e7ff; color: var(--primary); }
.mode-tab.active {
  border-color: var(--primary-light);
  background: linear-gradient(135deg, #1e1b4b, #312e81);
  color: #fbbf24;
  box-shadow: 0 6px 18px rgba(49, 46, 129, 0.25);
}

.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width: 768px) { .grid2 { grid-template-columns: 1fr; } }

label { font-size: 13px; font-weight: 700; color: #475569; display: block; margin-bottom: 6px; }
input[type=date], select {
  width: 100%;
  padding: 12px 14px;
  border: 2px solid var(--border);
  border-radius: 10px;
  font-size: 14px;
  background: #ffffff;
  color: var(--text);
  transition: border-color 0.2s ease;
  min-height: 46px;
}
input[type=date]:focus, select:focus {
  outline: none;
  border-color: var(--primary-light);
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
}

/* Calendar Styles */
.cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; background: #f1f5f9; padding: 10px 14px; border-radius: 12px; }
.cal-nav button {
  background: linear-gradient(135deg, #312e81, #4338ca);
  color: #fff;
  border: none;
  padding: 8px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  transition: transform 0.2s ease;
}
.cal-nav button:hover { transform: scale(1.03); }
.cal-title { font-weight: 800; color: var(--primary-dark); font-size: 16px; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.cal-dow { text-align: center; font-size: 12px; color: var(--muted); font-weight: 800; padding: 6px 0; background: #f8fafc; border-radius: 6px; }
.cal-day {
  text-align: center;
  padding: 10px 0;
  border-radius: 10px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  border: 1px solid var(--border);
  background: #ffffff;
  user-select: none;
  transition: all 0.2s ease;
}
.cal-day:hover { background: #e0e7ff; color: var(--primary); border-color: var(--primary-light); }
.cal-day.selected {
  background: linear-gradient(135deg, #059669, #10b981) !important;
  color: #ffffff !important;
  font-weight: 800;
  border-color: #059669 !important;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.cal-day.today { border: 2px solid var(--accent-gold); color: var(--accent-gold); font-weight: 800; }
.cal-day.empty { visibility: hidden; }
.cal-actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.cal-actions button {
  background: #f1f5f9;
  border: 1px solid var(--border);
  padding: 9px 15px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  color: #334155;
  transition: all 0.2s ease;
}
.cal-actions button:hover { background: #e2e8f0; color: var(--primary-dark); }
.cal-actions button.danger { color: var(--danger); border-color: #fca5a5; }
.cal-actions button.danger:hover { background: #fee2e2; }

/* Chips & Checkboxes */
.chips {
  display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px;
  max-height: 140px; overflow-y: auto; padding: 12px;
  background: #f8fafc; border-radius: 12px; border: 1px solid var(--border);
}
.chip {
  background: linear-gradient(135deg, #312e81, #4338ca);
  color: #ffffff;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  display: flex; align-items: center; gap: 8px;
  box-shadow: 0 2px 8px rgba(49, 46, 129, 0.2);
}
.chip span.x { cursor: pointer; font-weight: 900; background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 50%; }
.chip span.x:hover { background: var(--danger); color: #fff; }
.chip-empty { color: var(--muted); font-size: 13px; padding: 4px; }
.chip-count { font-size: 13px; font-weight: 700; color: var(--primary); margin-top: 8px; }

.prod-box {
  max-height: 240px; overflow-y: auto; border: 2px solid var(--border); border-radius: 12px; padding: 12px; background: #ffffff;
}
.prod-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; font-size: 14px; border-radius: 8px; transition: background 0.15s; cursor: pointer; }
.prod-item:hover { background: #f1f5f9; }
.prod-item input { width: 18px; height: 18px; accent-color: var(--primary-light); cursor: pointer; }
.prod-tools { display: flex; gap: 10px; margin-bottom: 10px; }
.prod-tools button {
  background: #f1f5f9; border: 1px solid var(--border); padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; color: #475569;
}
.prod-tools button:hover { background: #e2e8f0; color: var(--primary); }

/* Action Buttons */
.btn-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
.btn {
  padding: 14px 24px; border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
  cursor: pointer; display: inline-flex; align-items: center; gap: 10px; text-decoration: none;
  transition: all 0.3s ease; min-height: 48px;
}
.btn-primary {
  background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); color: #ffffff;
  box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4); }
.btn-excel {
  background: linear-gradient(135deg, #059669 0%, #10b981 100%); color: #ffffff;
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
}
.btn-excel:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4); }
.btn-print {
  background: linear-gradient(135deg, #334155 0%, #475569 100%); color: #ffffff;
  box-shadow: 0 4px 14px rgba(51, 65, 85, 0.3);
}
.btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(51, 65, 85, 0.4); }
.btn-clear {
  background: #ffffff; color: var(--danger); border: 2px solid var(--danger);
}
.btn-clear:hover { background: #fee2e2; transform: translateY(-2px); }

.error-box { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }

/* Summary Cards Grid */
.summary-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; }
@media(max-width: 1024px) { .summary-grid { grid-template-columns: repeat(3, 1fr); } }
@media(max-width: 640px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } }

.stat {
  background: linear-gradient(135deg, #312e81, #1e1b4b);
  color: #ffffff;
  border-radius: 14px;
  padding: 18px 14px;
  text-align: center;
  box-shadow: 0 6px 18px rgba(30, 27, 75, 0.2);
  transition: transform 0.3s ease;
}
.stat:hover { transform: translateY(-4px); }
.stat.alt { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 6px 18px rgba(5, 150, 105, 0.2); }
.stat.alt2 { background: linear-gradient(135deg, #d97706, #b45309); box-shadow: 0 6px 18px rgba(217, 119, 6, 0.2); }
.stat .num { font-size: 24px; font-weight: 800; color: #fbbf24; }
.stat.alt .num { color: #ffffff; }
.stat.alt2 .num { color: #fde68a; }
.stat .lbl { font-size: 12px; opacity: 0.95; margin-top: 6px; font-weight: 600; line-height: 1.3; }

/* Table Styling */
table.report-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
table.report-table th {
  background: #1e1b4b;
  color: #fde68a;
  padding: 12px 14px;
  text-align: left;
  position: sticky;
  top: 0;
  font-weight: 700;
  border-bottom: 2px solid #4338ca;
}
table.report-table td { padding: 12px 14px; border-bottom: 1px solid var(--border); }
table.report-table tr:nth-child(even) td { background: #fef9c3; }
table.report-table tr:nth-child(odd) td { background: #e0f2fe; }
table.report-table tr:hover td { background: #e2e8f0; }

.tbl-wrap {
  overflow-x: auto; max-height: 540px; overflow-y: auto; border: 2px solid var(--border); border-radius: 12px;
}
.total-row td {
  background: #f59e0b !important;
  font-weight: 800;
  color: #0f172a !important;
  position: sticky;
  bottom: 0;
  font-size: 14px;
}
.pos { color: #047857; font-weight: 800; }
.neg { color: var(--danger); font-weight: 800; }

/* Flow Table & Badges */
.flow-header-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
  background: #f8fafc;
  padding: 12px 16px;
  border-radius: 12px;
  border: 1px solid var(--border);
}
.flow-pills { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.flow-pill {
  background: #ffffff;
  border: 1.5px solid var(--border);
  color: #334155;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  user-select: none;
}
.flow-pill:hover { background: #e0e7ff; color: var(--primary); border-color: var(--primary-light); }
.flow-pill.active {
  background: linear-gradient(135deg, #1e1b4b, #312e81);
  color: #fbbf24;
  border-color: #1e1b4b;
  box-shadow: 0 4px 10px rgba(30, 27, 75, 0.25);
}
.flow-toggle-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: #475569;
  cursor: pointer;
  background: #fff;
  padding: 6px 12px;
  border-radius: 8px;
  border: 1px solid var(--border);
  user-select: none;
}
.flow-toggle-label input { accent-color: var(--primary-light); width: 16px; height: 16px; cursor: pointer; }

.badge-flow {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 700;
  text-align: right;
  min-width: 60px;
}
.badge-open { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.badge-in { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.badge-avail { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
.badge-sold { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.badge-close { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

.flow-product-section { margin-bottom: 24px; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
.flow-product-head {
  background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
  color: #fbbf24;
  padding: 12px 18px;
  font-size: 15px;
  font-weight: 800;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}
.flow-product-head .flow-meta {
  font-size: 12px;
  font-weight: 600;
  color: #f1f5f9;
  background: rgba(255,255,255,0.15);
  padding: 4px 12px;
  border-radius: 20px;
  display: inline-flex;
  gap: 12px;
}

@media print {
  .no-print { display: none !important; }
  body { background: #fff; padding: 0; }
  .card { box-shadow: none; border: 1px solid #ccc; }
  .tbl-wrap { max-height: none; overflow: visible; }
  .flow-product-section { page-break-inside: avoid; border: 1px solid #ccc; margin-bottom: 15px; }
  .flow-product-head { background: #1e1b4b !important; color: #fff !important; }
}
</style>
</head>
<body>
<div class="wrap">

    <div class="top-bar no-print">
        <h1><i class="fas fa-filter"></i> የላቀ ማጣሪያ ሪፖርት <span style="font-size:13px;color:var(--muted);font-weight:400;">Advanced Product / Stock Filter Report</span></h1>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <?php if ($current_branch_id > 0): ?>
                <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?></span>
            <?php endif; ?>
            <a href="<?php echo $_SESSION['role'] === 'super_admin' ? 'super_admin.php' : 'admin_dashboard.php'; ?>" class="back-btn"><i class="fas fa-arrow-left"></i> ተመለስ</a>
        </div>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="run" value="1">
        <input type="hidden" name="mode" id="modeInput" value="<?php echo htmlspecialchars($mode); ?>">
        <input type="hidden" name="dates" id="datesInput" value="<?php echo htmlspecialchars($mode === 'dates' ? implode(',', $target_dates) : ''); ?>">
        <input type="hidden" name="product_ids" id="productIdsInput" value="<?php echo htmlspecialchars(implode(',', $selected_product_ids)); ?>">

        <div class="card no-print">
            <?php if ($_SESSION['role'] === 'super_admin' && !empty($all_branches)): ?>
            <div style="margin-bottom:16px;">
                <label><i class="fas fa-store"></i> ቅርንጫፍ / Branch</label>
                <select name="branch_id" onchange="document.getElementById('filterForm').submit()">
                    <?php foreach ($all_branches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo ($current_branch_id == $b['id']) ? 'selected' : ''; ?>>🏪 <?php echo htmlspecialchars($b['place_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <h2><i class="fas fa-calendar-alt"></i> ቀናት ይምረጡ / Choose Days</h2>
            <div class="mode-tabs">
                <div class="mode-tab" id="tabRange" onclick="setMode('range')"><i class="fas fa-calendar-week"></i> ተከታታይ ክልል (Date Range)</div>
                <div class="mode-tab" id="tabDates" onclick="setMode('dates')"><i class="fas fa-calendar-check"></i> የተለያዩ ቀናት / ብዙ ወር (Any Random Days, Any Months)</div>
            </div>

            <div id="rangeSection">
                <input type="hidden" name="date_from" id="dateFromHidden" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="date_to" id="dateToHidden" value="<?php echo htmlspecialchars($date_to); ?>">

                <div class="grid2">
                    <div class="eth-picker-box">
                        <label style="font-weight:700; color:var(--primary-dark); margin-bottom:8px; display:flex; align-items:center; justify-content:space-between;">
                            <span><i class="fas fa-calendar-alt" style="color:var(--primary);"></i> ከ (From):</span>
                            <span id="fromEthDisplay" class="eth-pill-badge"><?php echo $eth_start['formatted']; ?></span>
                        </label>
                        <div class="eth-picker-row">
                            <div>
                                <span class="picker-sub">ቀን (Day)</span>
                                <select name="eth_day_from" id="ethDayFrom" class="eth-select" onchange="syncEthRange()">
                                    <?php for ($d = 1; $d <= 30; $d++): ?>
                                        <option value="<?php echo $d; ?>" <?php echo ($eth_day_from == $d) ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <span class="picker-sub">ወር (Month)</span>
                                <select name="eth_month_from" id="ethMonthFrom" class="eth-select" onchange="syncEthRange()">
                                    <?php foreach ($eth_months as $mNum => $mName): ?>
                                        <option value="<?php echo $mNum; ?>" <?php echo ($eth_month_from == $mNum) ? 'selected' : ''; ?>><?php echo $mNum . '. ' . $mName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <span class="picker-sub">ዓ.ም (Year)</span>
                                <select name="eth_year_from" id="ethYearFrom" class="eth-select" onchange="syncEthRange()">
                                    <?php for ($y = $eth_today['year'] - 3; $y <= $eth_today['year'] + 2; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($eth_year_from == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="eth-picker-box">
                        <label style="font-weight:700; color:var(--primary-dark); margin-bottom:8px; display:flex; align-items:center; justify-content:space-between;">
                            <span><i class="fas fa-calendar-check" style="color:var(--accent);"></i> እስከ (To):</span>
                            <span id="toEthDisplay" class="eth-pill-badge"><?php echo $eth_end['formatted']; ?></span>
                        </label>
                        <div class="eth-picker-row">
                            <div>
                                <span class="picker-sub">ቀን (Day)</span>
                                <select name="eth_day_to" id="ethDayTo" class="eth-select" onchange="syncEthRange()">
                                    <?php for ($d = 1; $d <= 30; $d++): ?>
                                        <option value="<?php echo $d; ?>" <?php echo ($eth_day_to == $d) ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <span class="picker-sub">ወር (Month)</span>
                                <select name="eth_month_to" id="ethMonthTo" class="eth-select" onchange="syncEthRange()">
                                    <?php foreach ($eth_months as $mNum => $mName): ?>
                                        <option value="<?php echo $mNum; ?>" <?php echo ($eth_month_to == $mNum) ? 'selected' : ''; ?>><?php echo $mNum . '. ' . $mName; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <span class="picker-sub">ዓ.ም (Year)</span>
                                <select name="eth_year_to" id="ethYearTo" class="eth-select" onchange="syncEthRange()">
                                    <?php for ($y = $eth_today['year'] - 3; $y <= $eth_today['year'] + 2; $y++): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($eth_year_to == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="preset-btn-group">
                    <button type="button" class="preset-pill" onclick="applyEthPreset('today')"><i class="fas fa-sun"></i> ዛሬ (Today)</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('yesterday')"><i class="fas fa-history"></i> ትናንት (Yesterday)</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('last3days')">ያለፉት 3 ቀናት</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('last7days')">ያለፈው 1 ሳምንት</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('last2weeks')">ያለፉት 2 ሳምንታት</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('this_month')"><i class="fas fa-calendar-alt"></i> ይህ ወር (ሙሉ <?php echo $eth_today['month_name']; ?>)</button>
                    <button type="button" class="preset-pill" onclick="applyEthPreset('last_month')">ያለፈው ወር</button>
                </div>
            </div>

            <div id="datesSection" style="display:none;">
                <div class="cal-nav">
                    <button type="button" onclick="ethCalShift(-1)"><i class="fas fa-chevron-left"></i> ያለፈው ወር</button>
                    <div class="cal-title" id="calTitle" style="font-size:17px; font-weight:800; color:var(--primary);"></div>
                    <button type="button" onclick="ethCalShift(1)">የሚቀጥለው ወር <i class="fas fa-chevron-right"></i></button>
                </div>
                <div class="cal-grid" id="calDow"></div>
                <div class="cal-grid" id="calGrid"></div>
                <div class="cal-actions">
                    <button type="button" onclick="selectWholeEthMonth()"><i class="fas fa-calendar-plus"></i> የዚህ ወር ሁሉንም ምረጥ</button>
                    <button type="button" onclick="selectLastNEthDays(3)">የመጨረሻ 3 ቀናት</button>
                    <button type="button" onclick="selectLastNEthDays(7)">የመጨረሻ 7 ቀናት</button>
                    <button type="button" onclick="selectLastNEthDays(30)">የመጨረሻ 30 ቀናት</button>
                    <button type="button" class="danger" onclick="clearDates()"><i class="fas fa-trash"></i> ሁሉንም አጥፋ</button>
                </div>
                <div class="chips" id="chipsBox"></div>
                <div class="chip-count" id="chipCount"></div>
            </div>
        </div>

        <div class="card no-print">
            <h2><i class="fas fa-box-open"></i> ምርቶች / Products <span style="font-size:12px;color:var(--muted);font-weight:400;">(ምንም ካልመረጡ ሁሉም ይታያሉ / leave empty for all)</span></h2>
            <div class="prod-tools">
                <button type="button" onclick="toggleAllProducts(true)">ሁሉንም ምረጥ</button>
                <button type="button" onclick="toggleAllProducts(false)">ሁሉንም አጥፋ</button>
            </div>
            <div class="prod-box">
                <?php foreach ($products as $p): ?>
                <label class="prod-item">
                    <input type="checkbox" class="prodChk" value="<?php echo $p['id']; ?>" <?php echo in_array((int)$p['id'], $selected_product_ids, true) ? 'checked' : ''; ?> onchange="syncProducts()">
                    <?php echo htmlspecialchars($p['name']); ?>
                </label>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                <div class="chip-empty">ምንም ምርት አልተገኘም</div>
                <?php endif; ?>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> ሪፖርት አሳይ / Generate Report</button>
                <?php if ($has_run && !$date_error): ?>
                <button type="submit" name="export" value="excel" class="btn btn-excel"><i class="fas fa-file-excel"></i> Excel Export</button>
                <button type="button" class="btn btn-print" onclick="window.print()"><i class="fas fa-file-pdf"></i> PDF / Print</button>
                <?php endif; ?>
                <a href="advanced_report.php" class="btn btn-clear"><i class="fas fa-redo"></i> Filter Clear / Reset</a>
            </div>
        </div>
    </form>

    <?php if ($date_error): ?>
        <div class="error-box"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($date_error); ?></div>
    <?php endif; ?>

    <?php if ($has_run && !$date_error): ?>

        <div class="card">
            <h2>
                <i class="fas fa-chart-pie"></i> ማጠቃለያ / Summary
                <span class="badge-range-eth"><?php echo htmlspecialchars($eth_date_range_display); ?></span>
            </h2>
            <div class="summary-grid">
                <div class="stat">
                    <div class="num"><?php echo $days_selected; ?></div>
                    <div class="lbl">የተመረጡ ቀናት<br>Selected Days</div>
                </div>
                <div class="stat alt">
                    <div class="num"><?php echo number_format($grand_sold_qty, 2); ?></div>
                    <div class="lbl">ጠቅላላ የተሸጠ ብዛት<br>Total Qty Sold</div>
                </div>
                <div class="stat alt">
                    <div class="num"><?php echo number_format($grand_revenue, 2); ?></div>
                    <div class="lbl">ጠቅላላ ገቢ (ብር)<br>Total Revenue</div>
                </div>
                <div class="stat alt2">
                    <div class="num"><?php echo number_format($grand_stock_qty, 2); ?></div>
                    <div class="lbl">የተመዘገበ ስቶክ<br>Stock Registered</div>
                </div>
                <div class="stat">
                    <div class="num"><?php echo $grand_tx_count; ?></div>
                    <div class="lbl">ግብይቶች<br>Transactions</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-list-ol"></i> በምርት የተጠቃለለ / Summary by Product <span style="font-size:12px;color:var(--muted);font-weight:400;">(across all <?php echo $days_selected; ?> selected days)</span></h2>
            <?php if (empty($product_totals)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><br>ለተመረጡት ቀናት ምንም መረጃ አልተገኘም<br>No data found for the selected days.</div>
            <?php else: ?>
            <div class="tbl-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>ምርት / Product</th>
                            <th>የተሸጠ ብዛት<br>Qty Sold</th>
                            <th>ገቢ<br>Revenue</th>
                            <th>የተመዘገበ ስቶክ<br>Stock In</th>
                            <th>ተመላሽ (ስቶክ - ሽያጭ)<br>Net (Stock - Sold)</th>
                            <th>ግብይቶች<br>Tx</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($product_totals as $name => $t): $net = $t['stock_qty'] - $t['sold_qty']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <td><?php echo number_format($t['sold_qty'], 2); ?></td>
                            <td><?php echo number_format($t['revenue'], 2); ?></td>
                            <td><?php echo number_format($t['stock_qty'], 2); ?></td>
                            <td class="<?php echo $net >= 0 ? 'pos' : 'neg'; ?>"><?php echo number_format($net, 2); ?></td>
                            <td><?php echo $t['tx_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td>ጠቅላላ ድምር / TOTAL</td>
                            <td><?php echo number_format($grand_sold_qty, 2); ?></td>
                            <td><?php echo number_format($grand_revenue, 2); ?></td>
                            <td><?php echo number_format($grand_stock_qty, 2); ?></td>
                            <td class="<?php echo ($grand_stock_qty - $grand_sold_qty) >= 0 ? 'pos' : 'neg'; ?>"><?php echo number_format($grand_stock_qty - $grand_sold_qty, 2); ?></td>
                            <td><?php echo $grand_tx_count; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($daily_flow)): ?>
        <div class="card">
            <h2>
                <i class="fas fa-boxes-packing" style="color:var(--accent-gold);"></i> 
                የዕለት ክምችት እና ሽያጭ ሙሉ ፍሰት / Daily Stock &amp; Sales Flow 
                <span style="font-size:12px;color:var(--muted);font-weight:400;">(የትላንት መነሻ + ስቶክ ገቢ = ጠቅላላ ዝግጁ | ጠቅላላ ዝግጁ - የተሸጠ = የቀኑ ቀሪ)</span>
            </h2>

            <div class="flow-header-bar no-print">
                <div class="flow-pills">
                    <span style="font-size:13px;font-weight:700;color:var(--muted);margin-right:4px;">ምርት ይምረጡ:</span>
                    <?php if (count($daily_flow) > 1): ?>
                        <button type="button" class="flow-pill active" onclick="filterFlowProduct('all', this)"><i class="fas fa-layer-group"></i> ሁሉም ምርቶች (All)</button>
                    <?php endif; ?>
                    <?php 
                    $first_p = true;
                    foreach ($daily_flow as $pname => $pdata): 
                        $isActiveClass = (count($daily_flow) === 1 || ($first_p && count($daily_flow) === 1)) ? 'active' : '';
                    ?>
                        <button type="button" class="flow-pill <?php echo $isActiveClass; ?>" onclick="filterFlowProduct('<?php echo htmlspecialchars(addslashes($pname)); ?>', this)">
                            <i class="fas fa-cube"></i> <?php echo htmlspecialchars($pname); ?>
                        </button>
                    <?php 
                        $first_p = false;
                    endforeach; 
                    ?>
                </div>
                <div>
                    <label class="flow-toggle-label">
                        <input type="checkbox" id="chkActiveOnly" onchange="toggleFlowActiveDays(this.checked)">
                        <span>እንቅስቃሴ ያላቸውን ቀናት ብቻ አሳይ (Active Days Only)</span>
                    </label>
                </div>
            </div>

            <?php 
            $dayNameAmharic = ['Monday'=>'ሰኞ', 'Tuesday'=>'ማክሰኞ', 'Wednesday'=>'ረቡዕ', 'Thursday'=>'ሐሙስ', 'Friday'=>'አርብ', 'Saturday'=>'ቅዳሜ', 'Sunday'=>'እሁድ'];
            foreach ($daily_flow as $pname => $pdata): 
            ?>
            <div class="flow-product-section" data-product="<?php echo htmlspecialchars($pname); ?>">
                <div class="flow-product-head">
                    <div>
                        <i class="fas fa-box-open"></i> <?php echo htmlspecialchars($pname); ?>
                    </div>
                    <div class="flow-meta">
                        <span>📦 መነሻ ስቶክ: <b><?php echo number_format($pdata['start_balance'], 2); ?></b></span>
                        <span>📥 ጠቅላላ የገባ: <b><?php echo number_format($pdata['total_in'], 2); ?></b></span>
                        <span>📤 ጠቅላላ የተሸጠ: <b><?php echo number_format($pdata['total_sold'], 2); ?></b></span>
                        <span>⚖️ የመጨረሻ ቀሪ: <b><?php echo number_format($pdata['end_balance'], 2); ?></b></span>
                        <span>💰 ገቢ: <b><?php echo number_format($pdata['total_rev'], 2); ?> ብር</b></span>
                    </div>
                </div>
                <div class="tbl-wrap" style="max-height: 480px;">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th style="min-width:180px;">ቀን / Date</th>
                                <th style="text-align:right;">📦 የትላንት ቀሪ<br>Opening Stock</th>
                                <th style="text-align:right;">📥 የዛሬ ስቶክ ገቢ (+)<br>Stock In</th>
                                <th style="text-align:right;">📦 ጠቅላላ የነበረ (=)<br>Total Available</th>
                                <th style="text-align:right;">📤 የተሸጠ ብዛት (-)<br>Sold Qty</th>
                                <th style="text-align:right;">⚖️ የቀኑ ቀሪ ስቶክ (=)<br>Closing Stock</th>
                                <th style="text-align:right;">💰 የቀኑ ገቢ (ብር)<br>Daily Revenue</th>
                                <th style="text-align:center;">ግብይቶች<br>Tx</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pdata['days'] as $d => $drow): 
                            $dObj = DateTime::createFromFormat('Y-m-d', $d);
                            $engDay = $dObj ? $dObj->format('l') : '';
                            $amDay = $dayNameAmharic[$engDay] ?? '';
                            $ethDate = getEthiopianDate($d);
                            $rowClass = $drow['has_activity'] ? 'flow-row-active' : 'flow-row-inactive';
                        ?>
                            <tr class="<?php echo $rowClass; ?>" style="<?php echo (!$drow['has_activity'] && $drow['opening'] == 0 && $drow['closing'] == 0) ? 'display:none;' : ''; ?>">
                                <td>
                                    <strong style="color:var(--primary); font-size:13.5px;"><?php echo htmlspecialchars($ethDate['formatted']); ?></strong>
                                    <?php if ($amDay): ?>
                                        <span style="color:var(--accent-gold); font-weight:700; font-size:12px;">(<?php echo $amDay; ?>)</span>
                                    <?php endif; ?>
                                    <div style="font-size:11px; color:var(--muted); margin-top:2px;">Gregorian: <?php echo htmlspecialchars($d); ?></div>
                                </td>
                                <td style="text-align:right;"><span class="badge-flow badge-open"><?php echo number_format($drow['opening'], 2); ?></span></td>
                                <td style="text-align:right;">
                                    <?php if ($drow['stock_in'] > 0): ?>
                                        <span class="badge-flow badge-in">+<?php echo number_format($drow['stock_in'], 2); ?></span>
                                    <?php elseif ($drow['stock_in'] < 0): ?>
                                        <span class="badge-flow badge-sold"><?php echo number_format($drow['stock_in'], 2); ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;"><span class="badge-flow badge-avail"><?php echo number_format($drow['available'], 2); ?></span></td>
                                <td style="text-align:right;">
                                    <?php if ($drow['sold'] > 0): ?>
                                        <span class="badge-flow badge-sold">-<?php echo number_format($drow['sold'], 2); ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;"><span class="badge-flow badge-close"><?php echo number_format($drow['closing'], 2); ?></span></td>
                                <td style="text-align:right;font-weight:700;color:var(--primary);"><?php echo number_format($drow['revenue'], 2); ?></td>
                                <td style="text-align:center;"><?php echo $drow['tx_count'] > 0 ? $drow['tx_count'] : '<span style="color:var(--muted);">-</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td>ጠቅላላ ድምር (TOTAL)</td>
                                <td style="text-align:right;"><?php echo number_format($pdata['start_balance'], 2); ?></td>
                                <td style="text-align:right;"><?php echo number_format($pdata['total_in'], 2); ?></td>
                                <td style="text-align:right;">—</td>
                                <td style="text-align:right;"><?php echo number_format($pdata['total_sold'], 2); ?></td>
                                <td style="text-align:right;"><?php echo number_format($pdata['end_balance'], 2); ?></td>
                                <td style="text-align:right;"><?php echo number_format($pdata['total_rev'], 2); ?></td>
                                <td style="text-align:center;"><?php echo $pdata['total_tx']; ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif (!$has_run): ?>
        <div class="card">
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <div>ከላይ ቀናት፣ ምርቶች እና ማጣሪያዎችን ይምረጡ ከዚያ "ሪፖርት አሳይ" ይጫኑ</div>
                <div style="font-size:13px;">Pick days, products and filters above, then click "Generate Report".</div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
// ---------------- Product checkbox sync ----------------
function syncProducts() {
    const ids = Array.from(document.querySelectorAll('.prodChk:checked')).map(c => c.value);
    document.getElementById('productIdsInput').value = ids.join(',');
}
function toggleAllProducts(state) {
    document.querySelectorAll('.prodChk').forEach(c => c.checked = state);
    syncProducts();
}

// ---------------- Mode tabs ----------------
function setMode(m) {
    document.getElementById('modeInput').value = m;
    document.getElementById('tabRange').classList.toggle('active', m === 'range');
    document.getElementById('tabDates').classList.toggle('active', m === 'dates');
    document.getElementById('rangeSection').style.display = m === 'range' ? 'block' : 'none';
    document.getElementById('datesSection').style.display = m === 'dates' ? 'block' : 'none';
}
setMode('<?php echo $mode; ?>');

// ---------------- Ethiopian Calendar JS Helper ----------------
const ETH_MONTH_NAMES = [
    '', 'መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ',
    'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ',
    'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'
];

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

function gregToEthJS(dateStr) {
    if (!dateStr) return { day: 1, month: 1, year: 2018, formatted: '' };
    const parts = dateStr.split('-');
    const gy = parseInt(parts[0], 10);
    const gm = parseInt(parts[1], 10);
    const gd = parseInt(parts[2], 10);

    const a = Math.floor((14 - gm) / 12);
    const y = gy + 4800 - a;
    const m = gm + 12 * a - 3;
    const jdn = gd + Math.floor((153 * m + 2) / 5) + 365 * y + Math.floor(y / 4) - Math.floor(y / 100) + Math.floor(y / 400) - 32045;

    const eth_epoch = 1723856;
    const r = (jdn - eth_epoch) % 1461;
    const n = (r % 365) + 365 * Math.floor(r / 1460);

    const eth_year = 4 * Math.floor((jdn - eth_epoch) / 1461) + Math.floor(r / 365) - Math.floor(r / 1460);
    let eth_month = Math.floor(n / 30) + 1;
    const eth_day = (n % 30) + 1;
    if (eth_month > 13) eth_month = 13;

    return {
        day: eth_day,
        month: eth_month,
        year: eth_year,
        formatted: `${eth_day} ${ETH_MONTH_NAMES[eth_month]} ${eth_year}`
    };
}

function syncEthRange() {
    const df = parseInt(document.getElementById('ethDayFrom').value, 10);
    const mf = parseInt(document.getElementById('ethMonthFrom').value, 10);
    const yf = parseInt(document.getElementById('ethYearFrom').value, 10);

    const dt = parseInt(document.getElementById('ethDayTo').value, 10);
    const mt = parseInt(document.getElementById('ethMonthTo').value, 10);
    const yt = parseInt(document.getElementById('ethYearTo').value, 10);

    const maxDaysFrom = (mf === 13) ? ((yf % 4 === 3) ? 6 : 5) : 30;
    if (df > maxDaysFrom) document.getElementById('ethDayFrom').value = maxDaysFrom;

    const maxDaysTo = (mt === 13) ? ((yt % 4 === 3) ? 6 : 5) : 30;
    if (dt > maxDaysTo) document.getElementById('ethDayTo').value = maxDaysTo;

    const validDf = Math.min(df, maxDaysFrom);
    const validDt = Math.min(dt, maxDaysTo);

    document.getElementById('fromEthDisplay').textContent = `${validDf} ${ETH_MONTH_NAMES[mf]} ${yf}`;
    document.getElementById('toEthDisplay').textContent = `${validDt} ${ETH_MONTH_NAMES[mt]} ${yt}`;

    document.getElementById('dateFromHidden').value = ethToGregJS(yf, mf, validDf);
    document.getElementById('dateToHidden').value = ethToGregJS(yt, mt, validDt);
}

function setEthDateInputs(fromObj, toObj) {
    document.getElementById('ethDayFrom').value = fromObj.day;
    document.getElementById('ethMonthFrom').value = fromObj.month;
    document.getElementById('ethYearFrom').value = fromObj.year;

    document.getElementById('ethDayTo').value = toObj.day;
    document.getElementById('ethMonthTo').value = toObj.month;
    document.getElementById('ethYearTo').value = toObj.year;

    syncEthRange();
}

function applyEthPreset(preset) {
    const todayGreg = new Date();
    const todayStr = `${todayGreg.getFullYear()}-${String(todayGreg.getMonth()+1).padStart(2,'0')}-${String(todayGreg.getDate()).padStart(2,'0')}`;
    const todayEth = gregToEthJS(todayStr);

    if (preset === 'today') {
        setEthDateInputs(todayEth, todayEth);
    } else if (preset === 'yesterday') {
        const yGreg = new Date();
        yGreg.setDate(todayGreg.getDate() - 1);
        const yStr = `${yGreg.getFullYear()}-${String(yGreg.getMonth()+1).padStart(2,'0')}-${String(yGreg.getDate()).padStart(2,'0')}`;
        const yEth = gregToEthJS(yStr);
        setEthDateInputs(yEth, yEth);
    } else if (preset === 'last3days') {
        const d3Greg = new Date();
        d3Greg.setDate(todayGreg.getDate() - 2);
        const d3Str = `${d3Greg.getFullYear()}-${String(d3Greg.getMonth()+1).padStart(2,'0')}-${String(d3Greg.getDate()).padStart(2,'0')}`;
        setEthDateInputs(gregToEthJS(d3Str), todayEth);
    } else if (preset === 'last7days') {
        const d7Greg = new Date();
        d7Greg.setDate(todayGreg.getDate() - 6);
        const d7Str = `${d7Greg.getFullYear()}-${String(d7Greg.getMonth()+1).padStart(2,'0')}-${String(d7Greg.getDate()).padStart(2,'0')}`;
        setEthDateInputs(gregToEthJS(d7Str), todayEth);
    } else if (preset === 'last2weeks') {
        const d14Greg = new Date();
        d14Greg.setDate(todayGreg.getDate() - 13);
        const d14Str = `${d14Greg.getFullYear()}-${String(d14Greg.getMonth()+1).padStart(2,'0')}-${String(d14Greg.getDate()).padStart(2,'0')}`;
        setEthDateInputs(gregToEthJS(d14Str), todayEth);
    } else if (preset === 'this_month') {
        const monthMax = (todayEth.month === 13) ? ((todayEth.year % 4 === 3) ? 6 : 5) : 30;
        setEthDateInputs(
            { day: 1, month: todayEth.month, year: todayEth.year },
            { day: monthMax, month: todayEth.month, year: todayEth.year }
        );
    } else if (preset === 'last_month') {
        let prevM = todayEth.month - 1;
        let prevY = todayEth.year;
        if (prevM < 1) { prevM = 13; prevY--; }
        const monthMax = (prevM === 13) ? ((prevY % 4 === 3) ? 6 : 5) : 30;
        setEthDateInputs(
            { day: 1, month: prevM, year: prevY },
            { day: monthMax, month: prevM, year: prevY }
        );
    }

    document.getElementById('filterForm').submit();
}

// ---------------- Native Ethiopian Multi-Month Calendar ----------------
let selectedDates = new Set(<?php echo $js_preselected_dates; ?>);

// Current Ethiopian Month & Year in View
const initialEth = gregToEthJS('<?php echo date('Y-m-d'); ?>');
let ethCalYear = initialEth.year;
let ethCalMonth = initialEth.month; // 1 to 13

const ETH_DOW = ['ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'አርብ', 'ቅዳሜ', 'እሁድ']; // Mon-Sun

function renderEthiopianCalendar() {
    document.getElementById('calTitle').innerHTML = `<span>${ETH_MONTH_NAMES[ethCalMonth]} ${ethCalYear}</span>`;
    
    const dowBox = document.getElementById('calDow');
    dowBox.innerHTML = '';
    ETH_DOW.forEach(d => {
        const el = document.createElement('div');
        el.className = 'cal-dow';
        el.textContent = d;
        dowBox.appendChild(el);
    });

    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    const g1Str = ethToGregJS(ethCalYear, ethCalMonth, 1);
    const g1Date = new Date(g1Str + 'T00:00:00');
    let dayOfWeek = g1Date.getDay(); // 0=Sun, 1=Mon, ..., 6=Sat
    let offset = (dayOfWeek === 0) ? 6 : dayOfWeek - 1;

    const totalDays = (ethCalMonth === 13) ? ((ethCalYear % 4 === 3) ? 6 : 5) : 30;
    const todayEth = gregToEthJS('<?php echo date('Y-m-d'); ?>');
    const isCurrentEthMonth = (ethCalYear === todayEth.year && ethCalMonth === todayEth.month);

    for (let i = 0; i < offset; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'cal-day empty';
        grid.appendChild(emptyCell);
    }

    for (let d = 1; d <= totalDays; d++) {
        const gregDateStr = ethToGregJS(ethCalYear, ethCalMonth, d);
        const el = document.createElement('div');
        el.className = 'cal-day';
        if (isCurrentEthMonth && d === todayEth.day) el.classList.add('today');
        if (selectedDates.has(gregDateStr)) el.classList.add('selected');

        el.innerHTML = `<span class="cal-day-num">${d}</span>`;
        el.title = `${d} ${ETH_MONTH_NAMES[ethCalMonth]} ${ethCalYear} (${gregDateStr})`;

        el.onclick = () => {
            if (selectedDates.has(gregDateStr)) selectedDates.delete(gregDateStr);
            else selectedDates.add(gregDateStr);
            renderEthiopianCalendar();
            renderChips();
        };
        grid.appendChild(el);
    }
}

function ethCalShift(dir) {
    ethCalMonth += dir;
    if (ethCalMonth > 13) {
        ethCalMonth = 1;
        ethCalYear++;
    } else if (ethCalMonth < 1) {
        ethCalMonth = 13;
        ethCalYear--;
    }
    renderEthiopianCalendar();
}

function selectWholeEthMonth() {
    const totalDays = (ethCalMonth === 13) ? ((ethCalYear % 4 === 3) ? 6 : 5) : 30;
    for (let d = 1; d <= totalDays; d++) {
        selectedDates.add(ethToGregJS(ethCalYear, ethCalMonth, d));
    }
    renderEthiopianCalendar();
    renderChips();
}

function selectLastNEthDays(n) {
    const todayGreg = new Date();
    for (let i = 0; i < n; i++) {
        const d = new Date();
        d.setDate(todayGreg.getDate() - i);
        const yStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        selectedDates.add(yStr);
    }
    renderEthiopianCalendar();
    renderChips();
}

function clearDates() {
    selectedDates.clear();
    renderEthiopianCalendar();
    renderChips();
}

function removeEthDate(gregStr) {
    selectedDates.delete(gregStr);
    renderEthiopianCalendar();
    renderChips();
}

function renderChips() {
    const box = document.getElementById('chipsBox');
    box.innerHTML = '';
    const sorted = Array.from(selectedDates).sort();
    if (sorted.length === 0) {
        box.innerHTML = '<div class="chip-empty">ምንም ቀን አልተመረጠም / No dates selected yet</div>';
    } else {
        sorted.forEach(d => {
            const eth = gregToEthJS(d);
            const chip = document.createElement('div');
            chip.className = 'chip';
            chip.innerHTML = `<strong>${eth.formatted}</strong> <span class="x" onclick="removeEthDate('${d}')">&times;</span>`;
            box.appendChild(chip);
        });
    }
    document.getElementById('chipCount').textContent = sorted.length + ' ቀን(ናት) ተመርጠዋል / days selected';
    document.getElementById('datesInput').value = sorted.join(',');
}

renderEthiopianCalendar();
renderChips();
syncEthRange();

// ---------------- Daily Flow Product & Active filter ----------------
function filterFlowProduct(pname, btn) {
    document.querySelectorAll('.flow-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const sections = document.querySelectorAll('.flow-product-section');
    sections.forEach(sec => {
        if (pname === 'all' || sec.getAttribute('data-product') === pname) {
            sec.style.display = 'block';
        } else {
            sec.style.display = 'none';
        }
    });
}

function toggleFlowActiveDays(activeOnly) {
    const inactiveRows = document.querySelectorAll('.flow-row-inactive');
    inactiveRows.forEach(row => {
        row.style.display = activeOnly ? 'none' : '';
    });
}

// Guard: if dates-mode selected with nothing picked, warn before submit
document.getElementById('filterForm').addEventListener('submit', function (e) {
    if (document.getElementById('modeInput').value === 'dates' && selectedDates.size === 0) {
        e.preventDefault();
        alert('እባክዎ ቢያንስ አንድ ቀን ይምረጡ / Please select at least one date first.');
    }
});
</script>
</body>
</html>
