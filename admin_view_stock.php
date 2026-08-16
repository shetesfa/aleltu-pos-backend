<?php
session_start();
require_once 'config.php';
date_default_timezone_set('Africa/Addis_Ababa');

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$user_role = $_SESSION['role'] ?? '';

// Get branch info
$user_branch = getUserBranch($conn, $user_id);
$current_branch_id = getCurrentBranchId($conn, $user_id, $user_role);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Ethiopian date functions
function gregorian_to_ethiopian($year, $month, $day) {
    $ethiopian_months = [1=>"መስከረም",2=>"ጥቅምት",3=>"ህዳር",4=>"ታህሳስ",5=>"ጥር",6=>"የካቲት",7=>"መጋቢት",8=>"ሚያዝያ",9=>"ግንቦት",10=>"ሰኔ",11=>"ሐምሌ",12=>"ነሐሴ",13=>"ጳጉሜ"];
    $ethiopian_year = $year - 7;
    $new_year_day = 11;
    if (date('L',mktime(0,0,0,1,1,$year)) && $month==9 && $day>=12) $new_year_day=12;
    if ($month<9 || ($month==9 && $day<$new_year_day)) $ethiopian_year--;
    $new_year_gregorian_year = ($month<9 || ($month==9 && $day<$new_year_day)) ? $year-1 : $year;
    $is_new_year_leap = date('L',mktime(0,0,0,1,1,$new_year_gregorian_year));
    $new_year_day_final = $is_new_year_leap ? 12 : 11;
    $jd_current = gregoriantojd($month,$day,$year);
    $jd_new_year = gregoriantojd(9,$new_year_day_final,$new_year_gregorian_year);
    $days_since_new_year = $jd_current - $jd_new_year;
    $ethiopian_month = floor($days_since_new_year/30) + 1;
    $ethiopian_day = ($days_since_new_year%30) + 1;
    if ($ethiopian_month==13) {
        $is_ethiopian_leap = (($ethiopian_year+1)%4)==0;
        if (!$is_ethiopian_leap && $ethiopian_day>5) { $ethiopian_month=1; $ethiopian_day-=5; $ethiopian_year++; }
        elseif ($is_ethiopian_leap && $ethiopian_day>6) { $ethiopian_month=1; $ethiopian_day-=6; $ethiopian_year++; }
    }
    return [
        'year'=>$ethiopian_year,'month'=>$ethiopian_month,
        'month_name'=>$ethiopian_months[$ethiopian_month]??"መስከረም",'day'=>$ethiopian_day,
        'full_date'=>sprintf("%04d-%02d-%02d",$ethiopian_year,$ethiopian_month,$ethiopian_day),
        'display_date'=>$ethiopian_day.' '.($ethiopian_months[$ethiopian_month]??"መስከረም").' '.$ethiopian_year
    ];
}
function get_ethiopian_today() { return gregorian_to_ethiopian(date('Y'),date('n'),date('j')); }
function get_ethiopian_time($t='now') {
    if(!$t) return '';
    $d = new DateTime($t,new DateTimeZone('Africa/Addis_Ababa'));
    $h = (int)$d->format('G'); $m = $d->format('i'); $s = $d->format('s');
    $eh = ($h+18)%24; $dh = ($eh==0||$eh==12)?12:$eh%12;
    $p = $eh<6?'ጥዋት':($eh<12?'ከሰዓት':'ከምሽት');
    return sprintf("%d:%02d:%02d %s",$dh,$m,$s,$p);
}
$today_ethiopian = get_ethiopian_today();
$today_display = $today_ethiopian['display_date'];

// Check tables
$stock_logs_exists = mysqli_num_rows(mysqli_query($conn,"SHOW TABLES LIKE 'stock_logs'"))>0;
$sellers_exists = mysqli_num_rows(mysqli_query($conn,"SHOW TABLES LIKE 'users'"))>0;

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Get date range from filter
    $date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
    $custom_start = isset($_GET['custom_start']) ? $_GET['custom_start'] : '';
    $custom_end = isset($_GET['custom_end']) ? $_GET['custom_end'] : '';
    
    // Build query for export
    $export_query = "SELECT s.*, u.full_name as seller_full_name, u.username as seller_username 
                     FROM stock_logs s 
                     LEFT JOIN users u ON s.seller_id=u.id 
                     WHERE s.branch_id=$current_branch_id";
    
    // Apply date filter
    if ($date_range != 'all' && $date_range != 'custom') {
        $days = [
            'today' => 0,
            'yesterday' => 1,
            'last3days' => 3,
            'last7days' => 7,
            'last2weeks' => 14,
            'last3weeks' => 21,
            'last1month' => 30,
            'last2months' => 60,
            'last3months' => 90,
            'last6months' => 180,
            'last9months' => 270,
            'last1year' => 365
        ];
        
        if (isset($days[$date_range])) {
            $start_date = date('Y-m-d', strtotime("-" . $days[$date_range] . " days"));
            $export_query .= " AND DATE(s.date_added) >= '$start_date'";
        }
    } elseif ($date_range == 'custom' && !empty($custom_start) && !empty($custom_end)) {
        $export_query .= " AND DATE(s.date_added) BETWEEN '$custom_start' AND '$custom_end'";
    }
    
    // Apply seller filter
    $selected_seller = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : 0;
    if ($selected_seller > 0) {
        $export_query .= " AND s.seller_id='$selected_seller'";
    }
    
    // Apply search filter
    $search_term = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
    if (!empty($search_term)) {
        $export_query .= " AND (s.item_name LIKE '%$search_term%' OR s.source LIKE '%$search_term%' OR s.notes LIKE '%$search_term%')";
    }
    
    $export_query .= " ORDER BY s.date_added DESC";
    
    $export_result = mysqli_query($conn, $export_query);
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="stock_transactions_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Create Excel file with HTML table
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: "Segoe UI", sans-serif; }';
    echo 'th { background: #4361ee; color: white; padding: 12px; text-align: left; font-weight: bold; }';
    echo 'td { padding: 10px; border: 1px solid #ddd; }';
    echo 'tr:nth-child(even) { background: #f9f9f9; }';
    echo '.seller-badge { color: #2ecc71; font-weight: bold; }';
    echo '.quantity-badge { font-weight: bold; color: #00b894; }';
    echo '.quantity-negative { font-weight: bold; color: #e74c3c; }';
    echo '.source-admin { color: #8a2be2; }';
    echo '.source-purchase { color: #2ecc71; }';
    echo '.source-return { color: #e74c3c; }';
    echo '.return-row { background-color: #ffebee; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<h2>የሻጮች ምርት ግብይት ታሪክ</h2>';
    echo '<p>የተመረጠ ቀን ክልል: ';
    
    // Display date range info
    if ($date_range == 'custom') {
        echo date('Y-m-d', strtotime($custom_start)) . ' እስከ ' . date('Y-m-d', strtotime($custom_end));
    } elseif ($date_range != 'all') {
        $range_names = [
            'today' => 'ዛሬ',
            'yesterday' => 'ትናንት',
            'last3days' => 'ያለፉ 3 ቀናት',
            'last7days' => 'ያለፉ 7 ቀናት',
            'last2weeks' => 'ያለፉ 2 ሳምንታት',
            'last3weeks' => 'ያለፉ 3 ሳምንታት',
            'last1month' => 'ያለፈ 1 ወር',
            'last2months' => 'ያለፉ 2 ወራት',
            'last3months' => 'ያለፉ 3 ወራት',
            'last6months' => 'ያለፉ 6 ወራት',
            'last9months' => 'ያለፉ 9 ወራት',
            'last1year' => 'ያለፈ 1 ዓመት'
        ];
        echo $range_names[$date_range] ?? 'ሁሉም ቀናት';
    } else {
        echo 'ሁሉም ቀናት';
    }
    
    echo '</p>';
    echo '<table border="1">';
    echo '<tr>';
    echo '<th>#</th>';
    echo '<th>ሻጭ</th>';
    echo '<th>የኢትዮጵያ ቀን</th>';
    echo '<th>ሰዓት</th>';
    echo '<th>እቃ</th>';
    echo '<th>ብዛት</th>';
    echo '<th>መለኪያ</th>';
    echo '<th>ምንጭ</th>';
    echo '<th>ማስታወሻ</th>';
    echo '<th>ግሪጎሪያን ቀን</th>';
    echo '</tr>';
    
    $count = 1;
    while ($row = mysqli_fetch_assoc($export_result)) {
        $sd = date('Y-m-d', strtotime($row['date_added']));
        list($gy, $gm, $gd) = explode('-', $sd);
        $e = gregorian_to_ethiopian(intval($gy), intval($gm), intval($gd));
        $ethiopian_date_display = $e['display_date'];
        $ethiopian_time = get_ethiopian_time($row['date_added']);
        
        // Check if this is a return transaction
        $is_return = ($row['source'] == 'return' || strpos($row['notes'] ?? '', 'ተመላሽ') !== false || $row['quantity'] < 0);
        $row_class = $is_return ? 'return-row' : '';
        $quantity_class = ($row['quantity'] < 0 || $is_return) ? 'quantity-negative' : 'quantity-badge';
        
        echo '<tr class="' . $row_class . '">';
        echo '<td>' . $count++ . '</td>';
        echo '<td class="seller-badge">' . htmlspecialchars($row['seller_full_name'] ?? $row['seller_username'] ?? 'Unknown') . '</td>';
        echo '<td>' . $ethiopian_date_display . '</td>';
        echo '<td>' . $ethiopian_time . '</td>';
        echo '<td><strong>' . htmlspecialchars($row['item_name']) . '</strong></td>';
        echo '<td class="' . $quantity_class . '">' . number_format($row['quantity'], 1) . '</td>';
        echo '<td>' . $row['unit'] . '</td>';
        echo '<td class="source-' . $row['source'] . '">' . 
             ($row['source'] == 'admin' ? 'ከፋርም' : 
             ($row['source'] == 'purchase' ? 'የተገዛ' : 
             ($row['source'] == 'return' ? 'ተመላሽ' : $row['source']))) . '</td>';
        echo '<td>' . (!empty($row['notes']) ? htmlspecialchars($row['notes']) : '-') . '</td>';
        echo '<td>' . date('Y-m-d H:i:s', strtotime($row['date_added'])) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<p>የተፈጠረበት ቀን: ' . date('Y-m-d H:i:s') . '</p>';
    echo '</body>';
    echo '</html>';
    exit();
}

// Get filters
$selected_seller = isset($_GET['seller_id'])?intval($_GET['seller_id']):0;
$selected_date = $_GET['filter_date']??'';
$search_term = isset($_GET['search'])?mysqli_real_escape_string($conn,$_GET['search']):'';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$custom_start = isset($_GET['custom_start']) ? $_GET['custom_start'] : '';
$custom_end = isset($_GET['custom_end']) ? $_GET['custom_end'] : '';

// Get sellers
$sellers = [];
if($sellers_exists) {
    $result = mysqli_query($conn,"SELECT id,username,full_name FROM users WHERE role='seller' AND branch_id=$current_branch_id ORDER BY full_name");
    while($r=mysqli_fetch_assoc($result)) $sellers[]=$r;
}

// Get stock data
$all_stock = []; 

if($stock_logs_exists) {
    $q = "SELECT s.*, u.full_name as seller_full_name, u.username as seller_username 
          FROM stock_logs s 
          LEFT JOIN users u ON s.seller_id=u.id 
          WHERE s.branch_id=$current_branch_id";
    
    if($selected_seller>0) $q .= " AND s.seller_id='$selected_seller'";
    if(!empty($selected_date)) $q .= " AND s.ethiopian_date LIKE '%$selected_date%'";
    if(!empty($search_term)) $q .= " AND (s.item_name LIKE '%$search_term%' OR s.source LIKE '%$search_term%' OR s.notes LIKE '%$search_term%')";
    
    // Apply date range filter
    if ($date_range != 'all' && $date_range != 'custom') {
        $days = [
            'today' => 0,
            'yesterday' => 1,
            'last3days' => 3,
            'last7days' => 7,
            'last2weeks' => 14,
            'last3weeks' => 21,
            'last1month' => 30,
            'last2months' => 60,
            'last3months' => 90,
            'last6months' => 180,
            'last9months' => 270,
            'last1year' => 365
        ];
        
        if (isset($days[$date_range])) {
            $start_date = date('Y-m-d', strtotime("-" . $days[$date_range] . " days"));
            $q .= " AND DATE(s.date_added) >= '$start_date'";
        }
    } elseif ($date_range == 'custom' && !empty($custom_start) && !empty($custom_end)) {
        $q .= " AND DATE(s.date_added) BETWEEN '$custom_start' AND '$custom_end'";
    }
    
    $q .= " ORDER BY s.date_added DESC LIMIT 500";
    $res = mysqli_query($conn,$q);
    
    while($s=mysqli_fetch_assoc($res)) {
        $sd = date('Y-m-d',strtotime($s['date_added']));
        list($gy,$gm,$gd)=explode('-',$sd);
        $e = gregorian_to_ethiopian(intval($gy),intval($gm),intval($gd));
        $s['ethiopian_date_display'] = $e['display_date'];
        $s['ethiopian_time'] = get_ethiopian_time($s['date_added']);
        
        $all_stock[]=$s;
    }
}

// Get date range display name
function getDateRangeName($range) {
    $names = [
        'all' => 'ሁሉም ቀናት',
        'today' => 'ዛሬ',
        'yesterday' => 'ትናንት',
        'last3days' => 'ያለፉ 3 ቀናት',
        'last7days' => 'ያለፉ 7 ቀናት',
        'last2weeks' => 'ያለፉ 2 ሳምንታት',
        'last3weeks' => 'ያለፉ 3 ሳምንታት',
        'last1month' => 'ያለፈ 1 ወር',
        'last2months' => 'ያለፉ 2 ወራት',
        'last3months' => 'ያለፉ 3 ወራት',
        'last6months' => 'ያለፉ 6 ወራት',
        'last9months' => 'ያለፉ 9 ወራት',
        'last1year' => 'ያለፈ 1 ዓመት',
        'custom' => 'ብጁ ቀናት'
    ];
    return $names[$range] ?? 'ሁሉም ቀናት';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="image/photo_2026-01-12_07-44-10.jpg">
    <title>Admin - View Stock Transactions</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --primary:#4361ee;
            --secondary:#7209b7;
            --success:#4cc9f0;
            --success-dark:#00b894;
            --warning:#f8961e;
            --danger:#f72585;
            --info:#3a86ff;
            --admin-color:#8a2be2;
            --seller-color:#2ecc71;
            --return-color:#e74c3c;
            --return-bg:#ffebee;
            --light:#f8f9fa;
            --dark:#212529;
            --gray-200:#e9ecef;
            --gray-600:#6c757d;
            --border-radius:15px;
            --border-radius-sm:10px;
            --shadow:0 8px 30px rgba(0,0,0,0.12);
            --transition:all .3s ease;
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',sans-serif;}
        /* Phone-first defaults. Larger layouts are added below with min-width queries. */
        body{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:8px;color:var(--dark);font-size:14px;}
        .dashboard-container{max-width:1400px;margin:0 auto;background:white;border-radius:12px;box-shadow:0 15px 50px rgba(0,0,0,0.2);overflow:hidden;}
        .dashboard-header{background:linear-gradient(135deg,var(--admin-color) 0%,var(--secondary) 100%);color:white;padding:15px;}
        .header-content{display:flex;flex-direction:column;gap:15px;align-items:flex-start;}
        .header-title{width:100%;}
        .header-title h1{font-size:1.25rem;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap;overflow-wrap:anywhere;}
        .ethiopian-date-badge,.branch-badge{background:rgba(255,255,255,0.2);padding:8px 12px;border-radius:20px;font-size:.8rem;display:inline-flex;align-items:center;gap:8px;backdrop-filter:blur(10px);margin:6px 4px 0 0;max-width:100%;}
        .user-info{background:rgba(255,255,255,0.2);padding:10px 15px;border-radius:var(--border-radius-sm);display:flex;align-items:center;gap:12px;width:100%;}
        .avatar{width:45px;height:45px;background:linear-gradient(45deg,var(--admin-color),var(--secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;color:white;}
        .header-actions{display:grid;grid-template-columns:1fr;gap:8px;width:100%;}
        .btn{padding:12px 16px;border-radius:var(--border-radius-sm);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;cursor:pointer;transition:var(--transition);min-height:44px;width:100%;}
        .btn:hover{transform:translateY(-2px);box-shadow:0 5px 15px rgba(0,0,0,0.2);}
        .btn-back{background:rgba(255,255,255,0.9);color:var(--admin-color);}
        .btn-primary{background:var(--primary);color:white;}
        .btn-success{background:var(--success-dark);color:white;}
        .btn-warning{background:var(--warning);color:white;}
        .btn-info{background:var(--info);color:white;}
        .btn-return{background:var(--return-color);color:white;}
        .refresh-btn{background:var(--info);color:white;border:none;padding:10px 15px;border-radius:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;min-height:44px;width:100%;}
        
        .dashboard-content{padding:15px;}
        .filter-panel{background:white;border-radius:var(--border-radius);padding:15px;margin-bottom:20px;box-shadow:var(--shadow);border:1px solid var(--gray-200);}
        .filter-title{font-size:1.2rem;font-weight:700;margin-bottom:15px;display:flex;align-items:center;gap:10px;}
        .filter-grid{display:grid;grid-template-columns:1fr;gap:15px;align-items:end;}
        .filter-group label{display:block;margin-bottom:8px;font-weight:600;font-size:.9rem;}
        .filter-control{width:100%;padding:12px;border:2px solid var(--gray-200);border-radius:var(--border-radius-sm);transition:var(--transition);min-height:44px;}
        .filter-control:focus{outline:none;border-color:var(--admin-color);}
        .filter-actions{display:grid;grid-template-columns:1fr;gap:10px;}
        .custom-date-range{display:none;grid-template-columns:1fr;gap:10px;margin-top:15px;padding:15px;background:var(--gray-200);border-radius:var(--border-radius-sm);}
        .custom-date-range.show{display:grid;}
        
        .table-panel{background:white;border-radius:var(--border-radius);padding:15px;box-shadow:var(--shadow);border:1px solid var(--gray-200);}
        .table-title{font-size:1.2rem;font-weight:700;margin-bottom:15px;display:flex;flex-direction:column;gap:15px;}
        .export-buttons{display:grid;grid-template-columns:1fr;gap:10px;}
        .stock-table-container{overflow-x:auto;border-radius:var(--border-radius-sm);border:1px solid var(--gray-200);max-height:600px;overflow-y:auto;width:100%;}
        .stock-table{width:100%;border-collapse:collapse;font-size:.85rem;min-width:720px;}
        .stock-table th{background:linear-gradient(135deg,var(--admin-color)0%,var(--secondary)100%);color:white;padding:12px;text-align:left;position:sticky;top:0;}
        .stock-table td{padding:12px;border-bottom:1px solid var(--gray-200);}
        .stock-table tr:hover{background:rgba(138,43,226,0.05);}
        .stock-table tr.return-row {
            background-color: #ffebee !important;
            border-left: 4px solid #e74c3c;
        }
        .stock-table tr.return-row:hover {
            background-color: #ffcdd2 !important;
        }
        .seller-badge{background:rgba(46,204,113,0.1);color:var(--seller-color);padding:5px 12px;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center;gap:5px;}
        .source-badge{padding:5px 12px;border-radius:20px;font-size:.8rem;font-weight:600;display:inline-block;}
        .source-admin{background:rgba(138,43,226,0.1);color:var(--admin-color);}
        .source-purchase{background:rgba(46,204,113,0.1);color:var(--seller-color);}
        .source-return{background:rgba(231,76,60,0.1);color:var(--return-color);}
        .source-other{background:rgba(108,117,125,0.1);color:var(--gray-600);}
        .ethiopian-date,.ethiopian-time{font-family:monospace;}
        .quantity-badge{font-weight:700;color:var(--success-dark);font-size:1rem;}
        .quantity-negative{font-weight:700;color:var(--return-color);font-size:1rem;}
        .stock-table td:nth-child(2),.stock-table td:nth-child(5),.stock-table td:nth-child(9){overflow-wrap:anywhere;}

        /* Phone view: show every transaction as a complete detail card, without horizontal scrolling. */
        @media(max-width:767px){
            .stock-table-container{overflow-x:hidden;max-height:none;border:none;}
            .stock-table,.stock-table tbody,.stock-table tr,.stock-table td{display:block;width:100%;min-width:0;}
            .stock-table{font-size:.9rem;}
            .stock-table thead{display:none;}
            .stock-table tbody{display:grid;gap:12px;}
            .stock-table tr,.stock-table tr.return-row{border:1px solid var(--gray-200);border-left:4px solid var(--admin-color);border-radius:var(--border-radius-sm);padding:7px 12px;background:#fff;}
            .stock-table tr.return-row{border-left-color:var(--return-color);}
            .stock-table td{display:grid;grid-template-columns:minmax(96px,42%) 1fr;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-200);text-align:right;}
            .stock-table td:last-child{border-bottom:none;}
            .stock-table td::before{color:var(--gray-600);font-size:.78rem;font-weight:700;text-align:left;}
            .stock-table td:nth-child(1)::before{content:'#';}
            .stock-table td:nth-child(2)::before{content:'ሻጭ';}
            .stock-table td:nth-child(3)::before{content:'የኢትዮጵያ ቀን';}
            .stock-table td:nth-child(4)::before{content:'ሰዓት';}
            .stock-table td:nth-child(5)::before{content:'እቃ';}
            .stock-table td:nth-child(6)::before{content:'ብዛት';}
            .stock-table td:nth-child(7)::before{content:'መለኪያ';}
            .stock-table td:nth-child(8)::before{content:'ምንጭ';}
            .stock-table td:nth-child(9)::before{content:'ማስታወሻ';}
            .stock-table td:nth-child(10)::before{content:'ግሪጎሪያን ቀን';}
            .seller-badge,.source-badge{justify-self:end;text-align:left;max-width:100%;overflow-wrap:anywhere;}
        }
        .empty-state{text-align:center;padding:40px 20px;color:var(--gray-600);}
        .empty-state i{font-size:3rem;margin-bottom:15px;color:var(--gray-300);}
        .active-filter{background:var(--info);color:white;padding:5px 12px;border-radius:20px;font-size:.75rem;margin:6px 4px 0 0;display:inline-block;max-width:100%;}
        .return-indicator{background:var(--return-color);color:white;padding:3px 10px;border-radius:20px;font-size:.7rem;margin-left:5px;display:inline-flex;align-items:center;gap:3px;}
        ::-webkit-scrollbar{width:8px;height:8px;}
        ::-webkit-scrollbar-track{background:var(--gray-200);border-radius:4px;}
        ::-webkit-scrollbar-thumb{background:linear-gradient(135deg,var(--admin-color),var(--secondary));border-radius:4px;}

        /* Min-width breakpoints for progressive enhancement */
        @media(min-width:600px){
            body{padding:20px;font-size:16px;}
            .dashboard-container{border-radius:var(--border-radius);}
            .dashboard-header{padding:25px 30px;}
            .header-content{flex-direction:row;justify-content:space-between;align-items:center;flex-wrap:wrap;}
            .header-title{width:auto;}
            .header-title h1{font-size:1.8rem;}
            .ethiopian-date-badge,.branch-badge,.active-filter{margin-left:10px;margin-top:0;}
            .user-info{width:auto;}
            .header-actions{width:auto;display:flex;flex-wrap:nowrap;}
            .btn,.refresh-btn{width:auto;}
            
            .dashboard-content{padding:30px;}
            .filter-panel{padding:25px;margin-bottom:30px;}
            .filter-title{font-size:1.3rem;margin-bottom:20px;}
            .filter-grid{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));}
            .filter-actions{display:flex;flex-direction:row;}
            .custom-date-range{grid-template-columns:1fr 1fr;}
            
            .table-panel{padding:25px;}
            .table-title{font-size:1.3rem;flex-direction:row;justify-content:space-between;align-items:center;}
            .export-buttons{display:flex;flex-direction:row;}
            .stock-table th,.stock-table td{padding:15px;}
            .empty-state{padding:80px 20px;}
            .empty-state i{font-size:4rem;margin-bottom:20px;}
        }

        @media(min-width:1200px){
            .stock-table{min-width:1000px;}
        }

        /* Print styles */
        @media print {
            body { background: white; padding: 0; }
            .dashboard-header, .filter-panel, .header-actions, 
            .refresh-btn, .export-buttons, .filter-actions,
            .btn-back, .btn-primary, .btn-warning, .btn-info, .btn-return {
                display: none !important;
            }
            .dashboard-container {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
            }
            .table-panel {
                padding: 0;
                box-shadow: none;
                border: none;
            }
            .stock-table-container {
                max-height: none;
                overflow: visible;
                border: 1px solid #000;
            }
            .stock-table th {
                background: #ddd !important;
                color: #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .stock-table td {
                border: 1px solid #000;
            }
            .stock-table tr.return-row {
                background-color: #ffebee !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <div class="dashboard-header">
        <div class="header-content">
            <div class="header-title">
                <h1><i class="fas fa-exchange-alt"></i> የሻጮች ምርት ግብይት</h1>
                <span class="ethiopian-date-badge"><i class="fas fa-calendar-alt"></i> <?php echo $today_display; ?> ዓ.ም</span>
                <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?></span>
                <?php if($date_range != 'all'): ?>
                <span class="active-filter"><i class="fas fa-filter"></i> <?php echo getDateRangeName($date_range); ?></span>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="avatar"><?php echo strtoupper(substr($user_name,0,1)); ?></div>
                <div><div style="font-weight:800;"><?php echo htmlspecialchars($user_name); ?></div><div style="font-size:.85rem;">አስተዳዳሪ</div></div>
            </div>
            <div class="header-actions">
                <button class="refresh-btn" onclick="refreshData()"><i class="fas fa-sync-alt"></i> አድስ</button>
                <a href="admin_dashboard.php" class="btn btn-back"><i class="fas fa-tachometer-alt"></i> ዳሽቦርድ</a>
                <a href="logout.php" class="btn btn-back"><i class="fas fa-sign-out-alt"></i> ውጣ</a>
            </div>
        </div>
    </div>
    
    <!-- REMOVED ALL ALERT MESSAGES -->
    
    <div class="filter-panel">
        <div class="filter-title"><i class="fas fa-filter"></i> ማጣሪያ</div>
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="filter-group">
                    <label><i class="fas fa-user-tie"></i> ሻጭ ምረጡ</label>
                    <select name="seller_id" class="filter-control" onchange="this.form.submit()">
                        <option value="0">ሁሉም ሻጮች</option>
                        <?php foreach($sellers as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $selected_seller==$s['id']?'selected':''; ?>><?php echo htmlspecialchars($s['full_name']?:$s['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> የቀን ክልል ምረጡ</label>
                    <select name="date_range" id="date_range" class="filter-control" onchange="toggleCustomDate(this.value); this.form.submit()">
                        <option value="all" <?php echo $date_range=='all'?'selected':''; ?>>ሁሉም ቀናት</option>
                        <option value="today" <?php echo $date_range=='today'?'selected':''; ?>>ዛሬ</option>
                        <option value="yesterday" <?php echo $date_range=='yesterday'?'selected':''; ?>>ትናንት</option>
                        <option value="last3days" <?php echo $date_range=='last3days'?'selected':''; ?>>ያለፉ 3 ቀናት</option>
                        <option value="last7days" <?php echo $date_range=='last7days'?'selected':''; ?>>ያለፉ 7 ቀናት</option>
                        <option value="last2weeks" <?php echo $date_range=='last2weeks'?'selected':''; ?>>ያለፉ 2 ሳምንታት</option>
                        <option value="last3weeks" <?php echo $date_range=='last3weeks'?'selected':''; ?>>ያለፉ 3 ሳምንታት</option>
                        <option value="last1month" <?php echo $date_range=='last1month'?'selected':''; ?>>ያለፈ 1 ወር</option>
                        <option value="last2months" <?php echo $date_range=='last2months'?'selected':''; ?>>ያለፉ 2 ወራት</option>
                        <option value="last3months" <?php echo $date_range=='last3months'?'selected':''; ?>>ያለፉ 3 ወራት</option>
                        <option value="last6months" <?php echo $date_range=='last6months'?'selected':''; ?>>ያለፉ 6 ወራት</option>
                        <option value="last9months" <?php echo $date_range=='last9months'?'selected':''; ?>>ያለፉ 9 ወራት</option>
                        <option value="last1year" <?php echo $date_range=='last1year'?'selected':''; ?>>ያለፈ 1 ዓመት</option>
                        <option value="custom" <?php echo $date_range=='custom'?'selected':''; ?>>ብጁ ቀናት</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> ፈልግ</label>
                    <input type="text" name="search" class="filter-control" placeholder="እቃ / ምንጭ / ማስታወሻ ..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> ፈልግ</button>
                    <a href="admin_view_stock.php" class="btn btn-warning"><i class="fas fa-times"></i> አጽዳ</a>
                </div>
            </div>
            
            <!-- Custom Date Range (hidden by default) -->
            <div id="custom_date_container" class="custom-date-range <?php echo $date_range=='custom'?'show':''; ?>">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-plus"></i> ከ</label>
                    <input type="date" name="custom_start" class="filter-control" value="<?php echo $custom_start; ?>" onchange="this.form.submit()">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-minus"></i> እስከ</label>
                    <input type="date" name="custom_end" class="filter-control" value="<?php echo $custom_end; ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>
    </div>
    
    <!-- REMOVED STATS CARDS SECTION COMPLETELY -->
    
    <div class="table-panel">
        <div class="table-title">
            <span><i class="fas fa-list"></i> የምርት ግብይት ታሪክ
                <span style="font-size:.9rem;color:var(--gray-600);">(<?php echo getDateRangeName($date_range); ?>)</span>
                <?php if($date_range == 'custom' && !empty($custom_start) && !empty($custom_end)): ?>
                <span style="font-size:.9rem;color:var(--info);"> (<?php echo $custom_start; ?> እስከ <?php echo $custom_end; ?>)</span>
                <?php endif; ?>
            </span>
            <div class="export-buttons">
                <!-- Export to Excel with current filters -->
                <button class="btn btn-success" onclick="exportWithFilters()"><i class="fas fa-file-excel"></i> ኤክስፖርት ወደ ኤክሴል</button>
                <button class="btn btn-info" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
            </div>
        </div>
        <?php if($stock_logs_exists && !empty($all_stock)): ?>
        <div class="stock-table-container">
            <table class="stock-table" id="stockTable">
                <thead><tr>
                    <th>#</th>
                    <th>ሻጭ</th>
                    <th>የኢትዮጵያ ቀን</th>
                    <th>ሰዓት</th>
                    <th>እቃ</th>
                    <th>ብዛት</th>
                    <th>መለኪያ</th>
                    <th>ምንጭ</th>
                    <th>ማስታወሻ</th>
                    <th>ግሪጎሪያን ቀን</th>
                </tr></thead>
                <tbody>
                <?php $c=1; foreach($all_stock as $s): 
                    // Check if this is a return transaction
                    $is_return = ($s['source'] == 'return' || strpos($s['notes'] ?? '', 'ተመላሽ') !== false || $s['quantity'] < 0);
                    $row_class = $is_return ? 'return-row' : '';
                    $quantity_class = ($s['quantity'] < 0 || $is_return) ? 'quantity-negative' : 'quantity-badge';
                    $source_class = $s['source'] == 'return' ? 'source-return' : ($s['source'] == 'admin' ? 'source-admin' : ($s['source'] == 'purchase' ? 'source-purchase' : 'source-other'));
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><?php echo $c++; ?></td>
                        <td><span class="seller-badge"><i class="fas fa-user"></i> <?php echo htmlspecialchars($s['seller_full_name']??$s['seller_username']??'Unknown'); ?></span></td>
                        <td class="ethiopian-date"><?php echo $s['ethiopian_date_display']; ?></td>
                        <td class="ethiopian-time"><?php echo $s['ethiopian_time']; ?></td>
                        <td><strong><?php echo htmlspecialchars($s['item_name']); ?></strong></td>
                        <td><span class="<?php echo $quantity_class; ?>"><?php echo number_format($s['quantity'],1); ?></span></td>
                        <td><?php echo $s['unit']; ?></td>
                        <td><span class="source-badge <?php echo $source_class; ?>"><i class="fas fa-<?php echo $s['source']=='admin'?'user-tie':($s['source']=='purchase'?'shopping-cart':($s['source']=='return'?'undo-alt':'truck')); ?>"></i> <?php 
                            echo $s['source']=='admin'?'ከፋርም':($s['source']=='purchase'?'የተገዛ':($s['source']=='return'?'ተመላሽ':$s['source'])); 
                        ?></span></td>
                        <td><?php if(!empty($s['notes'])): ?>
                            <span title="<?php echo htmlspecialchars($s['notes']); ?>">
                                <i class="fas fa-comment"></i> 
                                <?php 
                                echo htmlspecialchars($s['notes']); 
                                if(strpos($s['notes'] ?? '', 'ተመላሽ') !== false) {
                                    echo ' <span style="color:#e74c3c;"><i class="fas fa-undo-alt"></i></span>';
                                }
                                ?>
                            </span>
                        <?php else: echo '-'; endif; ?></td>
                        <td><?php echo date('Y-m-d H:i:s', strtotime($s['date_added'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif(!$stock_logs_exists): ?>
            <div class="empty-state"><i class="fas fa-database"></i><h3>የውሂብ ጎታ ችግር</h3></div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <h3>ምንም ግብይት አልተገኘም</h3>
                <?php if($selected_seller || $date_range != 'all' || $search_term): ?>
                <a href="admin_view_stock.php" class="btn btn-primary" style="margin-top:15px;"><i class="fas fa-times"></i> ማጣሪያ አጽዳ</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function refreshData(){ 
        document.querySelector('.refresh-btn').classList.add('refreshing'); 
        setTimeout(()=>location.reload(),500);
    }
    
    function toggleCustomDate(value) {
        const customContainer = document.getElementById('custom_date_container');
        if (value === 'custom') {
            customContainer.classList.add('show');
        } else {
            customContainer.classList.remove('show');
        }
    }
    
    // Export with current filters
    function exportWithFilters() {
        // Get current form values
        const form = document.getElementById('filterForm');
        const sellerId = document.querySelector('select[name="seller_id"]').value;
        const dateRange = document.getElementById('date_range').value;
        const search = document.querySelector('input[name="search"]').value;
        const customStart = document.querySelector('input[name="custom_start"]')?.value || '';
        const customEnd = document.querySelector('input[name="custom_end"]')?.value || '';
        
        // Build export URL
        let exportUrl = 'admin_view_stock.php?export=excel';
        exportUrl += '&seller_id=' + sellerId;
        exportUrl += '&date_range=' + dateRange;
        exportUrl += '&search=' + encodeURIComponent(search);
        
        if (dateRange === 'custom') {
            exportUrl += '&custom_start=' + customStart;
            exportUrl += '&custom_end=' + customEnd;
        }
        
        // Open in new window for download
        window.location.href = exportUrl;
    }
    
    // Auto-submit on search with debounce
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        let timeout;
        searchInput.addEventListener('keyup', function(){
            clearTimeout(timeout);
            timeout = setTimeout(() => document.getElementById('filterForm').submit(), 500);
        });
    }
    
    // Initialize custom date display on page load
    document.addEventListener('DOMContentLoaded', function() {
        const dateRange = document.getElementById('date_range').value;
        toggleCustomDate(dateRange);
    });
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
