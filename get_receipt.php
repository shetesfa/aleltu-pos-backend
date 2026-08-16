<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'seller';

$branch_id      = getCurrentBranchId($conn, $user_id, $user_role);
$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaction_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
    exit();
}

// Fetch transaction – must belong to current branch
$stmt = mysqli_prepare($conn,
    "SELECT t.*, p.place_name AS branch_name
     FROM transactions t
     LEFT JOIN places p ON t.branch_id = p.id
     WHERE t.id = ? AND t.branch_id = ?"
);
mysqli_stmt_bind_param($stmt, 'ii', $transaction_id, $branch_id);
mysqli_stmt_execute($stmt);
$trans_result = mysqli_stmt_get_result($stmt);

if (!$trans_result || mysqli_num_rows($trans_result) == 0) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found or access denied']);
    exit();
}

$transaction = mysqli_fetch_assoc($trans_result);

// Fetch items
$items_sql    = "SELECT product_name, quantity, unit_price, subtotal 
                 FROM transaction_items 
                 WHERE transaction_id = $transaction_id";
$items_result = mysqli_query($conn, $items_sql);
$items        = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
}

// Ethiopian date conversion (inline so no dependency issues)
function gregorian_to_ethiopian($gregorian_datetime) {
    try {
        if (strlen($gregorian_datetime) == 10) {
            $gregorian_datetime .= ' 00:00:00';
        }
        $date  = new DateTime($gregorian_datetime, new DateTimeZone('Africa/Addis_Ababa'));
        $year  = (int)$date->format('Y');
        $month = (int)$date->format('m');
        $day   = (int)$date->format('d');
        $hour  = (int)$date->format('H');
        $minute = (int)$date->format('i');
        $second = (int)$date->format('s');

        $ethiopian_months = [
            "መስከረም","ጥቅምት","ህዳር","ታህሳስ","ጥር","የካቲት",
            "መጋቢት","ሚያዝያ","ግንቦት","ሰኔ","ሐምሌ","ነሐሴ","ጳጉሜ"
        ];

        $is_greg_leap  = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $new_year_day  = $is_greg_leap ? 12 : 11;

        if ($month < 9 || ($month == 9 && $day < $new_year_day)) {
            $ethiopian_year = $year - 8;
        } else {
            $ethiopian_year = $year - 7;
        }

        $new_year = new DateTime(
            $year . '-09-' . $new_year_day . ' 00:00:00',
            new DateTimeZone('Africa/Addis_Ababa')
        );

        if ($date < $new_year) {
            $prev_year        = $year - 1;
            $is_prev_greg_leap = ($prev_year % 4 == 0 && ($prev_year % 100 != 0 || $prev_year % 400 == 0));
            $prev_new_year_day = $is_prev_greg_leap ? 12 : 11;
            $new_year          = new DateTime(
                $prev_year . '-09-' . $prev_new_year_day . ' 00:00:00',
                new DateTimeZone('Africa/Addis_Ababa')
            );
        }

        $interval       = $new_year->diff($date);
        $days_diff      = $interval->days;
        $ethiopian_month = floor($days_diff / 30) + 1;
        $ethiopian_day   = ($days_diff % 30) + 1;

        if ($ethiopian_month > 13) { $ethiopian_month = 13; }

        $hour_12 = $hour % 12;
        $hour_12 = $hour_12 == 0 ? 12 : $hour_12;
        $am_pm   = $hour < 12 ? 'ጥዋት' : 'ከሰዓት';

        return [
            'year'       => $ethiopian_year,
            'month'      => $ethiopian_month,
            'month_name' => $ethiopian_months[$ethiopian_month - 1] ?? '',
            'day'        => $ethiopian_day,
            'hour'       => $hour,
            'minute'     => $minute,
            'second'     => $second,
            'hour_12'    => $hour_12,
            'am_pm'      => $am_pm,
            'time_12h'   => sprintf("%d:%02d:%02d %s", $hour_12, $minute, $second, $am_pm),
            'time_24h'   => sprintf("%02d:%02d:%02d", $hour, $minute, $second)
        ];
    } catch (Exception $e) {
        return ['year' => 0, 'month' => 1, 'month_name' => '', 'day' => 1,
                'hour' => 0, 'minute' => 0, 'second' => 0,
                'hour_12' => 12, 'am_pm' => 'ጥዋት',
                'time_12h' => '12:00:00 ጥዋት', 'time_24h' => '00:00:00'];
    }
}

$eth_date = gregorian_to_ethiopian($transaction['transaction_date']);

echo json_encode([
    'success'         => true,
    'transaction'     => $transaction,
    'items'           => $items,
    'ethiopian_date'  => $eth_date
]);

mysqli_close($conn);
exit();