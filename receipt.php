<?php
session_start();
require_once 'config.php';

if (!$conn) { die("Connection failed"); }

// ---- Auth: must be logged in ----
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { die("Invalid ID"); }

$transaction_id = intval($_GET['id']);
$user_role      = $_SESSION['role'] ?? '';
$current_branch = getCurrentBranchId($conn, $_SESSION['user_id'], $user_role);

// Get transaction (prepared statement — was raw string interpolation)
$trans_stmt = mysqli_prepare($conn,
    "SELECT *, DATE_FORMAT(transaction_date, '%Y-%m-%d %H:%i:%s') as raw_date
     FROM transactions WHERE id = ?");
mysqli_stmt_bind_param($trans_stmt, "i", $transaction_id);
mysqli_stmt_execute($trans_stmt);
$trans_result = mysqli_stmt_get_result($trans_stmt);
if (!$trans_result || mysqli_num_rows($trans_result) == 0) { die("Transaction not found"); }
$transaction = mysqli_fetch_assoc($trans_result);
mysqli_stmt_close($trans_stmt);

// ---- Auth: non-super_admins can only view receipts from their own branch ----
if ($user_role !== 'super_admin' && (int)$transaction['branch_id'] !== (int)$current_branch) {
    die("Access denied. This receipt does not belong to your branch.");
}

// Get items (prepared statement — was raw string interpolation)
$items_stmt = mysqli_prepare($conn, "SELECT * FROM transaction_items WHERE transaction_id = ?");
mysqli_stmt_bind_param($items_stmt, "i", $transaction_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) { $items[] = $item; }
mysqli_stmt_close($items_stmt);

// Ethiopian date conversion
function gregorian_to_ethiopian($gregorian_datetime) {
    try {
        $date = new DateTime($gregorian_datetime, new DateTimeZone('Africa/Addis_Ababa'));
        $year  = (int)$date->format('Y');
        $month = (int)$date->format('m');
        $day   = (int)$date->format('d');
        $hour  = (int)$date->format('H');
        $minute= (int)$date->format('i');

        $ethiopian_months = [
            "መስከረም","ጥቅምት","ህዳር","ታህሳስ","ጥር","የካቲት",
            "መጋቢት","ሚያዝያ","ግንቦት","ሰኔ","ሐምሌ","ነሐሴ","ጳጉሜ"
        ];

        $is_greg_leap = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $new_year_day = $is_greg_leap ? 12 : 11;

        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $ethiopian_year = $year - 8;
        } else {
            $ethiopian_year = $year - 7;
        }

        $new_year = new DateTime($year . '-09-' . $new_year_day . ' 00:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        if ($date < $new_year) {
            $prev_year = $year - 1;
            $is_prev_leap = ($prev_year % 4 == 0 && ($prev_year % 100 != 0 || $prev_year % 400 == 0));
            $new_year = new DateTime($prev_year . '-09-' . ($is_prev_leap ? 12 : 11) . ' 00:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        }

        $days_diff    = $new_year->diff($date)->days;
        $eth_month    = floor($days_diff / 30) + 1;
        $eth_day      = ($days_diff % 30) + 1;
        if ($eth_month > 13) $eth_month = 13;

        $hour_12 = $hour % 12;
        $hour_12 = $hour_12 == 0 ? 12 : $hour_12;
        $am_pm   = $hour < 12 ? 'ጥዋት' : 'ከሰዓት';

        return [
            'day'        => $eth_day,
            'month_name' => $ethiopian_months[$eth_month - 1] ?? '',
            'year'       => $ethiopian_year,
            'time_12h'   => sprintf("%d:%02d %s", $hour_12, $minute, $am_pm)
        ];
    } catch (Exception $e) {
        return ['day'=>'?','month_name'=>'?','year'=>'?','time_12h'=>'?'];
    }
}

$eth = gregorian_to_ethiopian($transaction['raw_date']);

$payment_names = [
    'cash'           => '💵 ካሽ',
    'abyssinia'      => '🏦 አቢሲንያ ባንክ',
    'cbe'            => '🏦 ሲቢኢ ባንክ',
    'telebirr'       => '📱 ቴሌብር',
    'cash-abyssinia' => '💵+🏦 ካሽ + አቢሲንያ',
    'cash-cbe'       => '💵+🏦 ካሽ + ሲቢኢ',
    'cash-telebirr'  => '💵+📱 ካሽ + ቴሌብር'
];
$payment_display = $payment_names[$transaction['payment_method']] ?? ucfirst($transaction['payment_method']);

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <title>Receipt #<?php echo str_pad($transaction['id'], 6, '0', STR_PAD_LEFT); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Ethiopic', 'Nyala', Arial, sans-serif; background: #f0f0f0; display: flex; justify-content: center; padding: 10px; }
        .receipt-container { background: white; padding: 15px; width: 100%; max-width: 100%; box-shadow: 0 2px 15px rgba(0,0,0,0.15); }
        .receipt-header { text-align: center; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 2px dashed #333; }
        .receipt-header .title { font-size: 18px; font-weight: bold; color: #2c3e50; margin-bottom: 4px; }
        .receipt-header .subtitle { font-size: 12px; color: #555; margin-bottom: 6px; }
        .receipt-header .address { font-size: 10px; color: #777; margin-bottom: 3px; }
        .receipt-info { margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px dashed #ddd; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 11px; }
        .info-label { font-weight: bold; color: #333; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 11px; display: block; overflow-x: auto; white-space: nowrap; }
        .items-table th { text-align: left; padding: 6px 0; border-bottom: 2px solid #333; font-weight: bold; }
        .items-table td { padding: 4px 0; border-bottom: 1px solid #eee; }
        .items-table .qty   { text-align: center; min-width: 40px; }
        .items-table .price { text-align: right; min-width: 60px; }
        .items-table .total { text-align: right; min-width: 60px; }
        .receipt-totals { padding-top: 10px; border-top: 2px solid #333; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 12px; }
        .grand-total { font-size: 14px; font-weight: bold; margin-top: 8px; padding-top: 8px; border-top: 2px dashed #333; }
        .receipt-footer { text-align: center; padding-top: 15px; margin-top: 15px; border-top: 1px dashed #333; font-size: 10px; color: #777; }
        .print-btn { display: block; width: 100%; margin-top: 15px; padding: 10px; min-height: 44px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .close-btn { display: block; width: 100%; margin-top: 8px; padding: 10px; min-height: 44px; background: #f5f5f5; color: #666; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        
        @media (min-width: 600px) {
            body { padding: 20px; }
            .receipt-container { padding: 25px; max-width: 320px; }
            .receipt-header { padding-bottom: 15px; margin-bottom: 15px; }
            .receipt-header .title { font-size: 20px; }
            .receipt-header .subtitle { font-size: 13px; }
            .receipt-header .address { font-size: 11px; }
            .receipt-info { margin-bottom: 15px; }
            .info-row { font-size: 12px; }
            .items-table { display: table; white-space: normal; font-size: 12px; }
            .items-table .qty { width: 50px; min-width: auto; }
            .items-table .price { width: 70px; min-width: auto; }
            .items-table .total { width: 70px; min-width: auto; }
            .total-row { font-size: 13px; }
            .grand-total { font-size: 16px; }
            .receipt-footer { font-size: 11px; }
        }

        @media print {
            body { background: white; padding: 0; }
            .print-btn, .close-btn { display: none; }
            .receipt-container { box-shadow: none; max-width: 100%; width: 100%; padding: 0; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <div class="title">አሌልቱ የእንስሳት ተዋጽኦ</div>
            <div class="subtitle">Animal Products Supplier</div>
            <div class="address">Addis Ababa, Ethiopia</div>
            <div class="address">Phone: +251 996089048</div>
        </div>

        <div class="receipt-info">
            <div class="info-row">
                <span class="info-label">የደረሰኝ ቁጥር:</span>
                <span>#<?php echo str_pad($transaction['id'], 6, '0', STR_PAD_LEFT); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">ቀን (ኢትዮጵያ):</span>
                <span><?php echo $eth['day'] . ' ' . $eth['month_name'] . ' ' . $eth['year'] . ', ' . $eth['time_12h']; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">ሻጭ:</span>
                <span><?php echo htmlspecialchars($transaction['seller_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">የመክፈያ መንገድ:</span>
                <span><?php echo $payment_display; ?></span>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>ዕቃ</th>
                    <th class="qty">ብዛት</th>
                    <th class="price">ዋጋ</th>
                    <th class="total">ጠቅላላ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td class="qty"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                    <td class="price"><?php echo number_format((float)$item['unit_price'], 2); ?></td>
                    <td class="total"><?php echo number_format((float)$item['subtotal'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="receipt-totals">
            <div class="total-row">
                <span>ጠቅላላ ድምር:</span>
                <span><?php echo number_format((float)$transaction['total_amount'], 2); ?> ብር</span>
            </div>
            <div class="total-row">
                <span>የተከፈለ:</span>
                <span><?php echo number_format((float)$transaction['paid_amount'], 2); ?> ብር</span>
            </div>
            <div class="total-row grand-total">
                <span>ቀሪ / ልዩነት:</span>
                <span><?php echo number_format((float)$transaction['change_amount'], 2); ?> ብር</span>
            </div>
        </div>

        <div class="receipt-footer">
            <div>ለንግድዎ እናመሰግናለን!</div>
            <div>Thank you for your business!</div>
            <div style="margin-top: 8px; font-size: 10px;">
                <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>

        <button class="print-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <button class="close-btn" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</body>
</html>
