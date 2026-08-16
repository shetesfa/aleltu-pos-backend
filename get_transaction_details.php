<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

if (!$conn) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid transaction ID']));
}

$transaction_id = intval($_GET['id']);

// Get transaction details
$transaction_query = "SELECT *, DATE_FORMAT(transaction_date, '%Y-%m-%d %H:%i:%s') as raw_date
                      FROM transactions WHERE id = $transaction_id";
$transaction_result = mysqli_query($conn, $transaction_query);

if (!$transaction_result || mysqli_num_rows($transaction_result) == 0) {
    die(json_encode(['success' => false, 'message' => 'Transaction not found']));
}

$transaction = mysqli_fetch_assoc($transaction_result);

// Convert raw_date to Ethiopian date for the receipt
function gregorian_to_ethiopian_receipt($gregorian_datetime) {
    try {
        $date = new DateTime($gregorian_datetime, new DateTimeZone('UTC'));

        $year  = (int)$date->format('Y');
        $month = (int)$date->format('m');
        $day   = (int)$date->format('d');
        $hour  = (int)$date->format('H');
        $minute= (int)$date->format('i');

        $ethiopian_months = [
            "መስከረም","ጥቅምት","ህዳር","ታህሳስ","ጥር","የካቲት",
            "መጋቢት","ሚያዝያ","ግንቦት","ሰኔ","ሐምሌ","ነሐሴ","ጳጉሜ"
        ];

        $ethiopian_year = $year - 8;
        $is_leap = (($year % 4 == 0) && ($year % 100 != 0)) || ($year % 400 == 0);
        $new_year_day = $is_leap ? 12 : 11;

        if ($month > 9 || ($month == 9 && $day >= $new_year_day)) {
            $ethiopian_year++;
        }

        $new_year_date = new DateTime("$year-09-{$new_year_day}", new DateTimeZone('UTC'));
        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $new_year_date = new DateTime(($year - 1) . "-09-{$new_year_day}", new DateTimeZone('UTC'));
        }

        $diff = $date->diff($new_year_date);
        $days  = $diff->days;
        $eth_month = floor($days / 30) + 1;
        $eth_day   = ($days % 30) + 1;

        if ($eth_month > 13) { $eth_month = 13; }

        // Ethiopian time = UTC+3
        $eth_hour = $hour + 3;
        if ($eth_hour >= 24) $eth_hour -= 24;

        $hour_12 = $eth_hour % 12;
        $hour_12 = $hour_12 == 0 ? 12 : $hour_12;
        $am_pm   = $eth_hour < 12 ? 'ጥዋት' : 'ከሰዓት';

        return [
            'day'        => $eth_day,
            'month_name' => $ethiopian_months[$eth_month - 1] ?? '',
            'year'       => $ethiopian_year,
            'time_12h'   => sprintf("%d:%02d %s", $hour_12, $minute, $am_pm)
        ];
    } catch (Exception $e) {
        return ['day' => '?', 'month_name' => '?', 'year' => '?', 'time_12h' => '?'];
    }
}

$eth_date = gregorian_to_ethiopian_receipt($transaction['raw_date']);

// Get transaction items with name fallback resolution
$items_query  = "SELECT ti.*, 
                  COALESCE(
                    IF(p.name != '' AND p.name IS NOT NULL, p.name, NULL),
                    IF(si.item_name != '' AND si.item_name IS NOT NULL, si.item_name, NULL),
                    IF(ti.product_name != '0' AND ti.product_name != '', ti.product_name, NULL),
                    'ምርት'
                  ) as resolved_product_name
                 FROM transaction_items ti
                 LEFT JOIN products p ON (ti.product_id = p.id AND p.id > 0)
                 LEFT JOIN seller_inventory si ON (ti.product_id = si.product_id OR ti.product_id = si.id)
                 WHERE ti.transaction_id = $transaction_id";
$items_result = mysqli_query($conn, $items_query);
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $p_name = (!empty($item['product_name']) && $item['product_name'] !== '0') 
        ? $item['product_name'] 
        : $item['resolved_product_name'];

    $items[] = [
        'id'           => (int)$item['id'],
        'product_name' => $p_name,
        'quantity'     => (float)$item['quantity'],
        'unit_price'   => (float)$item['unit_price'],
        'subtotal'     => (float)$item['subtotal']
    ];
}

echo json_encode([
    'success' => true,
    'transaction' => [
        'id'             => (int)$transaction['id'],
        'date'           => $transaction['raw_date'],
        'seller_name'    => $transaction['seller_name'],
        'total_amount'   => (float)$transaction['total_amount'],
        'paid_amount'    => (float)$transaction['paid_amount'],
        'change_amount'  => (float)$transaction['change_amount'],
        'payment_method' => $transaction['payment_method']
    ],
    'eth_date' => $eth_date,
    'items'    => $items
], JSON_UNESCAPED_UNICODE);

mysqli_close($conn);
?>