<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

if (!$conn) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// ---- Auth: must be logged in ----
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid transaction ID']));
}

$transaction_id = intval($_GET['id']);
$user_role      = $_SESSION['role'] ?? '';
$current_branch = getCurrentBranchId($conn, $_SESSION['user_id'], $user_role);

// Get transaction details (prepared statement — was raw string interpolation)
$transaction_stmt = mysqli_prepare($conn,
    "SELECT *, DATE_FORMAT(transaction_date, '%Y-%m-%d %H:%i:%s') as raw_date
     FROM transactions WHERE id = ?");
mysqli_stmt_bind_param($transaction_stmt, "i", $transaction_id);
mysqli_stmt_execute($transaction_stmt);
$transaction_result = mysqli_stmt_get_result($transaction_stmt);

if (!$transaction_result || mysqli_num_rows($transaction_result) == 0) {
    die(json_encode(['success' => false, 'message' => 'Transaction not found']));
}

$transaction = mysqli_fetch_assoc($transaction_result);
mysqli_stmt_close($transaction_stmt);

// ---- Auth: non-super_admins can only view their own branch's transactions ----
if ($user_role !== 'super_admin' && (int)$transaction['branch_id'] !== (int)$current_branch) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Access denied']));
}

// Convert raw_date to Ethiopian date for the receipt
function gregorian_to_ethiopian_receipt($gregorian_datetime) {
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

        $ethiopian_year = $year - 8;
        $is_leap = (($year % 4 == 0) && ($year % 100 != 0)) || ($year % 400 == 0);
        $new_year_day = $is_leap ? 12 : 11;

        if ($month > 9 || ($month == 9 && $day >= $new_year_day)) {
            $ethiopian_year++;
        }

        $new_year_date = new DateTime("$year-09-{$new_year_day}", new DateTimeZone('Africa/Addis_Ababa'));
        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $new_year_date = new DateTime(($year - 1) . "-09-{$new_year_day}", new DateTimeZone('Africa/Addis_Ababa'));
        }

        $diff = $date->diff($new_year_date);
        $days  = $diff->days;
        $eth_month = floor($days / 30) + 1;
        $eth_day   = ($days % 30) + 1;

        if ($eth_month > 13) { $eth_month = 13; }

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
        return ['day' => '?', 'month_name' => '?', 'year' => '?', 'time_12h' => '?'];
    }
}

$eth_date = gregorian_to_ethiopian_receipt($transaction['raw_date']);

// Get transaction items with name fallback resolution (prepared statement — was raw string interpolation)
$items_stmt = mysqli_prepare($conn,
    "SELECT ti.*,
       COALESCE(
         NULLIF(NULLIF(ti.product_name, '0'), ''),
         NULLIF(p.name, ''),
         'ምርት'
       ) as resolved_product_name
     FROM transaction_items ti
     LEFT JOIN products p ON (ti.product_id = p.id AND p.id > 0)
     WHERE ti.transaction_id = ?");
mysqli_stmt_bind_param($items_stmt, "i", $transaction_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $p_name = (!empty($item['product_name']) && $item['product_name'] !== '0')
        ? $item['product_name']
        : (!empty($item['resolved_product_name']) ? $item['resolved_product_name'] : 'ምርት');

    $items[] = [
        'id'           => (int)$item['id'],
        'product_name' => $p_name,
        'quantity'     => (float)$item['quantity'],
        'unit_price'   => (float)$item['unit_price'],
        'subtotal'     => (float)$item['subtotal']
    ];
}
mysqli_stmt_close($items_stmt);

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
