<?php
session_start();
require_once 'config.php';

// ---- Auth: super_admin ONLY ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
if (empty($_SESSION['delete_csrf_token'])) {
    $_SESSION['delete_csrf_token'] = bin2hex(random_bytes(32));
}

$branch_id_filter = isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? intval($_GET['branch_id']) : 0; // 0 = all branches
$branches = getAllBranches($conn);

$tab       = $_GET['tab'] ?? 'transactions';
$allowed_tabs = ['transactions', 'stock_logs', 'product_returns', 'withdrawals'];
if (!in_array($tab, $allowed_tabs, true)) $tab = 'transactions';

$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 50;
$offset    = ($page - 1) * $per_page;

function buildQueryString($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query($params);
}

// Safe UTF-8 truncation that does NOT depend on the mbstring extension
// (some hosts don't have it enabled; PCRE 'u' mode is core PHP).
function safe_truncate($str, $len = 40) {
    if ($str === null || $str === '') return '';
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) return $str; // non-UTF8 input, just return as-is
    if (count($chars) <= $len) return $str;
    return implode('', array_slice($chars, 0, $len)) . '...';
}

$rows = [];
$total_rows = 0;
$item_map = []; // transaction_id => [items]

if ($tab === 'transactions') {
    $where = [];
    $types = '';
    $vals = [];
    if ($branch_id_filter > 0) { $where[] = "t.branch_id = ?"; $types .= 'i'; $vals[] = $branch_id_filter; }
    if ($search !== '') { $where[] = "t.seller_name LIKE ?"; $types .= 's'; $vals[] = "%$search%"; }
    if ($date_from !== '') { $where[] = "t.transaction_date >= ?"; $types .= 's'; $vals[] = $date_from . ' 00:00:00'; }
    if ($date_to !== '') { $where[] = "t.transaction_date <= ?"; $types .= 's'; $vals[] = $date_to . ' 23:59:59'; }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $count_sql = "SELECT COUNT(*) c FROM transactions t $where_sql";
    $stmt = mysqli_prepare($conn, $count_sql);
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $total_rows = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    $sql = "SELECT t.* FROM transactions t $where_sql ORDER BY t.transaction_date DESC LIMIT ? OFFSET ?";
    $stmt = mysqli_prepare($conn, $sql);
    $types2 = $types . 'ii';
    $vals2 = array_merge($vals, [$per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $types2, ...$vals2);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);

    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_map('intval', $ids));
        $ires = mysqli_query($conn, "SELECT * FROM transaction_items WHERE transaction_id IN ($in)");
        while ($ir = mysqli_fetch_assoc($ires)) {
            $item_map[$ir['transaction_id']][] = $ir;
        }
    }

} elseif ($tab === 'stock_logs') {
    $where = [];
    $types = '';
    $vals = [];
    if ($branch_id_filter > 0) { $where[] = "branch_id = ?"; $types .= 'i'; $vals[] = $branch_id_filter; }
    if ($search !== '') { $where[] = "(item_name LIKE ? OR seller_name LIKE ?)"; $types .= 'ss'; $vals[] = "%$search%"; $vals[] = "%$search%"; }
    if ($date_from !== '') { $where[] = "date_added >= ?"; $types .= 's'; $vals[] = $date_from . ' 00:00:00'; }
    if ($date_to !== '') { $where[] = "date_added <= ?"; $types .= 's'; $vals[] = $date_to . ' 23:59:59'; }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM stock_logs $where_sql");
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $total_rows = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM stock_logs $where_sql ORDER BY date_added DESC LIMIT ? OFFSET ?");
    $types2 = $types . 'ii';
    $vals2 = array_merge($vals, [$per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $types2, ...$vals2);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);

} elseif ($tab === 'product_returns') {
    $where = [];
    $types = '';
    $vals = [];
    if ($branch_id_filter > 0) { $where[] = "branch_id = ?"; $types .= 'i'; $vals[] = $branch_id_filter; }
    if ($search !== '') { $where[] = "(product_name LIKE ? OR seller_name LIKE ?)"; $types .= 'ss'; $vals[] = "%$search%"; $vals[] = "%$search%"; }
    if ($date_from !== '') { $where[] = "gregorian_date >= ?"; $types .= 's'; $vals[] = $date_from . ' 00:00:00'; }
    if ($date_to !== '') { $where[] = "gregorian_date <= ?"; $types .= 's'; $vals[] = $date_to . ' 23:59:59'; }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM product_returns $where_sql");
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $total_rows = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM product_returns $where_sql ORDER BY gregorian_date DESC LIMIT ? OFFSET ?");
    $types2 = $types . 'ii';
    $vals2 = array_merge($vals, [$per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $types2, ...$vals2);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);

} elseif ($tab === 'withdrawals') {
    $where = [];
    $types = '';
    $vals = [];
    if ($branch_id_filter > 0) { $where[] = "branch_id = ?"; $types .= 'i'; $vals[] = $branch_id_filter; }
    if ($search !== '') { $where[] = "(username LIKE ? OR reason LIKE ?)"; $types .= 'ss'; $vals[] = "%$search%"; $vals[] = "%$search%"; }
    if ($date_from !== '') { $where[] = "created_at >= ?"; $types .= 's'; $vals[] = $date_from . ' 00:00:00'; }
    if ($date_to !== '') { $where[] = "created_at <= ?"; $types .= 's'; $vals[] = $date_to . ' 23:59:59'; }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM daily_withdrawals $where_sql");
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $total_rows = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM daily_withdrawals $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $types2 = $types . 'ii';
    $vals2 = array_merge($vals, [$per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $types2, ...$vals2);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
}

$total_pages = max(1, ceil($total_rows / $per_page));
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin - Data Delete Manager</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
* { box-sizing: border-box; margin:0; padding:0; font-family: 'Segoe UI', Tahoma, sans-serif; }
body { background:#f4f5f7; color:#222; }
.header { background: linear-gradient(135deg,#720000,#c0392b); color:#fff; padding:18px 16px 20px; box-shadow:0 2px 10px rgba(83,0,0,.18); }
.header h1 { font-size:18px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.header p { margin-top:6px; font-size:13px; opacity:.9; }
.warning-banner { background:#fff3cd; border:1px solid #ffe08a; color:#7a5b00; padding:12px 16px; font-size:13px; display:flex; gap:10px; align-items:flex-start; line-height:1.45; }
.container { padding:14px 12px 24px; max-width:1300px; margin:0 auto; }

/* Tabs: 2x2 grid on mobile so all 4 tabs including Withdrawals are instantly visible */
.tabs { display:grid; grid-template-columns: repeat(2, 1fr); gap:6px; margin-bottom:14px; }
.tabs a { padding:10px 8px; background:#fff; border-radius:9px; text-decoration:none; color:#444; font-size:12px; font-weight:600; box-shadow:0 1px 3px rgba(0,0,0,.06); white-space:nowrap; min-height:44px; display:inline-flex; align-items:center; justify-content:center; gap:6px; width:100%; }
.tabs a.active { background:#8b0000; color:#fff; }

/* Controls: Mobile-first stacked layout */
.controls { background:#fff; border-radius:10px; padding:14px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.controls form { display:flex; flex-direction:column; gap:10px; }
.controls form > div { width:100%; }
.controls label { font-size:12px; color:#666; display:block; margin-bottom:4px; font-weight:600; }
.controls input, .controls select { width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:6px; font-size:13px; background:#fff; height:42px; }
.controls-btn-group { display:flex; gap:8px; width:100%; margin-top:4px; }
.controls-btn-group button { flex:1; background:#333; color:#fff; border:none; padding:11px 16px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; min-height:42px; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.controls-btn-group button[type="button"] { background:#666; }

/* Table responsive wrapper */
.table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); margin-bottom:16px; }
table { width:100%; border-collapse:collapse; background:#fff; min-width:650px; }
th, td { padding:10px 12px; text-align:left; font-size:13px; border-bottom:1px solid #eee; vertical-align:middle; }
th { background:#fafafa; color:#555; font-weight:600; white-space:nowrap; }
tr:hover { background:#fff8f8; }
.badge { padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; display:inline-block; }
.badge-return { background:#ffe3e3; color:#a10000; }
.badge-normal { background:#e3f5e6; color:#0a7d29; }
.del-btn { background:#dc3545; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; min-height:36px; white-space:nowrap; }
.del-btn:hover { background:#b02a37; }
.del-btn:disabled { background:#ccc; cursor:not-allowed; }
.empty { padding:40px; text-align:center; color:#999; }
.pagination { display:flex; gap:6px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
.pagination a, .pagination span { padding:8px 14px; background:#fff; border-radius:6px; text-decoration:none; color:#444; font-size:13px; box-shadow:0 1px 3px rgba(0,0,0,.06); min-height:38px; display:inline-flex; align-items:center; }
.pagination .current { background:#8b0000; color:#fff; }
.item-list { font-size:11px; color:#777; margin-top:3px; max-width:280px; word-wrap:break-word; }
.back-link { color:#fff; text-decoration:none; font-size:13px; opacity:.9; display:inline-flex; align-items:center; gap:4px; }
.small { font-size:11px; color:#888; }

/* Phone view: records become easy-to-read cards instead of a tiny table. */
@media (max-width: 767px) {
    .header h1 { font-size:17px; }
    .header p { display:flex; flex-direction:column; gap:7px; line-height:1.35; }
    .warning-banner { font-size:12px; }
    .controls { padding:14px 12px; }
    .table-responsive { overflow:visible; background:transparent; box-shadow:none; }
    table, tbody, tr, td { display:block; width:100%; min-width:0; }
    thead { display:none; }
    tbody { display:grid; gap:10px; }
    tr { background:#fff; border-radius:12px; padding:7px 12px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
    tr:hover { background:#fff; }
    td { border-bottom:1px solid #f0f0f0; padding:9px 0 9px 42%; min-height:38px; position:relative; text-align:right; overflow-wrap:anywhere; }
    td::before { content:attr(data-label); position:absolute; left:0; top:9px; width:38%; color:#777; font-weight:600; font-size:11px; text-align:left; }
    td:last-child { border-bottom:0; padding:11px 0 4px; text-align:left; }
    td:last-child::before { display:none; }
    .del-btn { width:100%; min-height:44px; justify-content:center; font-size:13px; }
    .item-list { max-width:none; text-align:right; }
    td.empty { padding:34px 14px; text-align:center; }
    td.empty::before { display:none; }
    .pagination { justify-content:space-between; gap:8px; }
    .pagination a, .pagination span { flex:1; justify-content:center; min-height:44px; }
}

/* ---- Desktop Enhancements (min-width: 768px) ---- */
@media (min-width: 768px) {
    .header { padding:20px 25px; }
    .header h1 { font-size:20px; }
    .container { padding:20px; }
    .controls form { flex-direction:row; flex-wrap:wrap; align-items:flex-end; gap:10px; }
    .controls form > div { width:auto; }
    .controls input, .controls select { width:auto; height:38px; padding:8px 10px; }
    .controls-btn-group { width:auto; margin-top:0; }
    .tabs { display:flex; gap:8px; }
    .tabs a { width:auto; padding:10px 18px; font-size:13px; }
}

/* ---- Delete Preview Modal (mobile-first) ---- */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); display:flex; align-items:flex-end; justify-content:center; z-index:1000; }
.modal-box { background:#fff; width:100%; max-width:480px; border-radius:16px 16px 0 0; max-height:88vh; overflow-y:auto; padding:18px 16px 20px; animation:slideUp .18s ease-out; }
@keyframes slideUp { from { transform:translateY(20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-title { font-size:15px; font-weight:700; color:#222; margin-bottom:4px; padding-right:30px; }
.modal-sub { font-size:12px; color:#888; margin-bottom:14px; }
.modal-close { position:absolute; top:14px; right:16px; background:none; border:none; font-size:18px; color:#999; cursor:pointer; padding:6px; }
.modal-header-wrap { position:relative; }
.modal-loading { text-align:center; padding:30px 10px; color:#888; font-size:13px; }
.modal-loading i { font-size:22px; display:block; margin-bottom:10px; color:#8b0000; }
.change-card { border:1px solid #eee; border-radius:10px; padding:12px; margin-bottom:10px; }
.change-card.blocked { border-color:#f2b8b5; background:#fff5f5; }
.change-card.ok { border-color:#cfe8d3; background:#f6fbf7; }
.change-item-name { font-weight:700; font-size:13px; margin-bottom:8px; color:#222; }
.balance-row { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:13px; }
.balance-block { text-align:center; flex:1; }
.balance-label { font-size:10px; color:#999; text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px; }
.balance-value { font-size:16px; font-weight:700; }
.balance-value.before { color:#444; }
.balance-value.after-ok { color:#0a7d29; }
.balance-value.after-blocked { color:#c0392b; }
.balance-arrow { color:#bbb; font-size:14px; }
.change-reason { font-size:11.5px; color:#a10000; margin-top:8px; line-height:1.4; background:#fff0f0; padding:8px 10px; border-radius:6px; }
.change-reason.info { color:#666; background:#f3f3f3; }
.no-impact-note { font-size:13px; color:#666; background:#f3f3f3; padding:12px; border-radius:8px; }
.modal-actions { display:flex; gap:10px; margin-top:16px; }
.modal-actions button { flex:1; padding:13px; border-radius:10px; border:none; font-size:14px; font-weight:600; cursor:pointer; min-height:44px; }
.btn-cancel { background:#eee; color:#333; }
.btn-confirm-delete { background:#dc3545; color:#fff; }
.btn-confirm-delete:disabled { background:#e2a5ab; cursor:not-allowed; }
.modal-error { padding:20px 10px; text-align:center; color:#c0392b; font-size:13px; }
@media (min-width:560px) {
    .modal-overlay { align-items:center; }
    .modal-box { border-radius:16px; }
}
</style>
</head>
<body>

<div class="header">
    <h1><i class="fas fa-user-shield"></i> Super Admin Delete Manager</h1>
    <p><a href="super_admin.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to dashboard</a> &nbsp;|&nbsp; Logged in as <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin'); ?></p>
</div>

<div class="warning-banner">
    <i class="fas fa-triangle-exclamation"></i>
    Deletions here are permanent and cannot be undone from this page. Stock is automatically adjusted where relevant. Every delete is recorded in the audit log with a full snapshot, who deleted it, and when.
</div>

<div class="container">

    <div class="tabs">
        <a class="<?php echo $tab==='transactions'?'active':''; ?>" href="?<?php echo buildQueryString(['tab'=>'transactions','page'=>1]); ?>"><i class="fas fa-receipt"></i> Transactions</a>
        <a class="<?php echo $tab==='stock_logs'?'active':''; ?>" href="?<?php echo buildQueryString(['tab'=>'stock_logs','page'=>1]); ?>"><i class="fas fa-boxes-stacked"></i> Stock Logs</a>
        <a class="<?php echo $tab==='product_returns'?'active':''; ?>" href="?<?php echo buildQueryString(['tab'=>'product_returns','page'=>1]); ?>"><i class="fas fa-rotate-left"></i> Product Returns</a>
        <a class="<?php echo $tab==='withdrawals'?'active':''; ?>" href="?<?php echo buildQueryString(['tab'=>'withdrawals','page'=>1]); ?>"><i class="fas fa-money-bill-wave"></i> Withdrawals</a>
    </div>

    <div class="controls">
        <form method="GET">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div>
                <label>Branch</label>
                <select name="branch_id">
                    <option value="0">All Branches</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $branch_id_filter==$b['id']?'selected':''; ?>><?php echo htmlspecialchars($b['place_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="seller / item name">
            </div>
            <div>
                <label>From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div>
                <label>To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="controls-btn-group">
                <button type="submit"><i class="fas fa-filter"></i> Filter</button>
                <button type="button" onclick="window.location='?tab=<?php echo $tab; ?>'"><i class="fas fa-rotate"></i> Reset</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table>
        <thead>
        <?php if ($tab === 'transactions'): ?>
            <tr><th>ID</th><th>Date</th><th>Seller</th><th>Branch</th><th>Items</th><th>Total</th><th>Payment</th><th></th></tr>
        <?php elseif ($tab === 'stock_logs'): ?>
            <tr><th>ID</th><th>Date</th><th>Seller</th><th>Item</th><th>Qty</th><th>Source</th><th>Branch</th><th></th></tr>
        <?php elseif ($tab === 'product_returns'): ?>
            <tr><th>ID</th><th>Date</th><th>Seller</th><th>Product</th><th>Qty</th><th>Reason</th><th>Branch</th><th></th></tr>
        <?php else: ?>
            <tr><th>ID</th><th>Date</th><th>User</th><th>Amount</th><th>Reason</th><th>Branch</th><th></th></tr>
        <?php endif; ?>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="empty">No records found.</td></tr>
        <?php else: ?>

            <?php if ($tab === 'transactions'): foreach ($rows as $r):
                $items = $item_map[$r['id']] ?? [];
                $item_summary = implode(', ', array_map(fn($i) => $i['product_name'].' x'.$i['quantity'], $items));
                $confirm_msg = "Delete Transaction #{$r['id']}?\n\nTotal: {$r['total_amount']} | Seller: {$r['seller_name']}\n\nThis will RESTORE stock for:\n" .
                    implode("\n", array_map(fn($i) => "  + {$i['product_name']}: +{$i['quantity']}", $items)) .
                    "\n\nThis cannot be undone.";
            ?>
                <tr>
                    <td data-label="ID">#<?php echo $r['id']; ?></td>
                    <td data-label="Date"><?php echo htmlspecialchars($r['transaction_date']); ?></td>
                    <td data-label="Seller"><?php echo htmlspecialchars($r['seller_name']); ?></td>
                    <td data-label="Branch"><?php echo htmlspecialchars($r['branch_id']); ?></td>
                    <td data-label="Items"><?php echo count($items); ?> item(s)<div class="item-list"><?php echo htmlspecialchars($item_summary); ?></div></td>
                    <td data-label="Total"><?php echo number_format($r['total_amount'],2); ?></td>
                    <td data-label="Payment"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                    <td><button class="del-btn" onclick='openPreviewModal("transaction", <?php echo $r['id']; ?>, this)'><i class="fas fa-trash"></i> Delete</button></td>
                </tr>
            <?php endforeach; ?>

            <?php elseif ($tab === 'stock_logs'): foreach ($rows as $r):
                $confirm_msg = "Delete Stock Log #{$r['id']}?\n\nItem: {$r['item_name']}\nQuantity: {$r['quantity']}\nSource: {$r['source']}\n\nThis will reverse the stock change (subtract {$r['quantity']} from current stock).\n\nThis cannot be undone.";
            ?>
                <tr>
                    <td data-label="ID">#<?php echo $r['id']; ?></td>
                    <td data-label="Date"><?php echo htmlspecialchars($r['date_added']); ?></td>
                    <td data-label="Seller"><?php echo htmlspecialchars($r['seller_name']); ?></td>
                    <td data-label="Item"><?php echo htmlspecialchars($r['item_name']); ?></td>
                    <td data-label="Quantity"><?php echo $r['quantity']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                    <td data-label="Source"><span class="badge <?php echo $r['source']==='return'?'badge-return':'badge-normal'; ?>"><?php echo htmlspecialchars($r['source']); ?></span></td>
                    <td data-label="Branch"><?php echo htmlspecialchars($r['branch_id']); ?></td>
                    <td><button class="del-btn" onclick='openPreviewModal("stock_log", <?php echo $r['id']; ?>, this)'><i class="fas fa-trash"></i> Delete</button></td>
                </tr>
            <?php endforeach; ?>

            <?php elseif ($tab === 'product_returns'): foreach ($rows as $r):
                $confirm_msg = "Delete Product Return #{$r['id']}?\n\nProduct: {$r['product_name']}\nQuantity: {$r['quantity']}\n\nThis will RESTORE {$r['quantity']} back into current stock (undoing the return).\n\nThis cannot be undone.";
            ?>
                <tr>
                    <td data-label="ID">#<?php echo $r['id']; ?></td>
                    <td data-label="Date"><?php echo htmlspecialchars($r['gregorian_date']); ?></td>
                    <td data-label="Seller"><?php echo htmlspecialchars($r['seller_name']); ?></td>
                    <td data-label="Product"><?php echo htmlspecialchars($r['product_name']); ?></td>
                    <td data-label="Quantity"><?php echo $r['quantity']; ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                    <td data-label="Reason" class="small"><?php echo htmlspecialchars(safe_truncate($r['reason'] ?? '', 40)); ?></td>
                    <td data-label="Branch"><?php echo htmlspecialchars($r['branch_id']); ?></td>
                    <td><button class="del-btn" onclick='openPreviewModal("product_return", <?php echo $r['id']; ?>, this)'><i class="fas fa-trash"></i> Delete</button></td>
                </tr>
            <?php endforeach; ?>

            <?php else: foreach ($rows as $r):
                $confirm_msg = "Delete Withdrawal #{$r['id']}?\n\nUser: {$r['username']}\nAmount: {$r['amount']}\n\nNo stock impact. This cannot be undone.";
            ?>
                <tr>
                    <td data-label="ID">#<?php echo $r['id']; ?></td>
                    <td data-label="Date"><?php echo htmlspecialchars($r['created_at']); ?></td>
                    <td data-label="User"><?php echo htmlspecialchars($r['username']); ?></td>
                    <td data-label="Amount"><?php echo number_format($r['amount'],2); ?></td>
                    <td data-label="Reason" class="small"><?php echo htmlspecialchars(safe_truncate($r['reason'] ?? '', 40)); ?></td>
                    <td data-label="Branch"><?php echo htmlspecialchars($r['branch_id']); ?></td>
                    <td><button class="del-btn" onclick='openPreviewModal("withdrawal", <?php echo $r['id']; ?>, this)'><i class="fas fa-trash"></i> Delete</button></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <?php if ($page > 1): ?><a href="?<?php echo buildQueryString(['page'=>$page-1]); ?>">&laquo; Prev</a><?php endif; ?>
        <span class="current"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
        <?php if ($page < $total_pages): ?><a href="?<?php echo buildQueryString(['page'=>$page+1]); ?>">Next &raquo;</a><?php endif; ?>
    </div>

</div>

<!-- Live preview modal (filled in by JS before every delete) -->
<div id="previewOverlay" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-header-wrap">
            <button type="button" class="modal-close" onclick="closePreviewModal()"><i class="fas fa-times"></i></button>
            <div class="modal-title" id="previewTitle">Loading...</div>
            <div class="modal-sub" id="previewSub"></div>
        </div>
        <div id="previewBody">
            <div class="modal-loading"><i class="fas fa-spinner fa-spin"></i>Checking live stock…</div>
        </div>
        <div class="modal-actions" id="previewActions" style="display:none;">
            <button type="button" class="btn-cancel" onclick="closePreviewModal()">Cancel</button>
            <button type="button" class="btn-confirm-delete" id="previewConfirmBtn" onclick="doActualDelete()">
                <i class="fas fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($_SESSION['delete_csrf_token']); ?>;
let pendingDelete = null; // { type, id, btnEl }

function openPreviewModal(type, id, btnEl) {
    pendingDelete = { type, id, btnEl };
    document.getElementById('previewOverlay').style.display = 'flex';
    document.getElementById('previewTitle').textContent = 'Checking live stock…';
    document.getElementById('previewSub').textContent = '';
    document.getElementById('previewActions').style.display = 'none';
    document.getElementById('previewBody').innerHTML =
        '<div class="modal-loading"><i class="fas fa-spinner fa-spin"></i>Checking live stock…</div>';

    fetch('preview_delete.php?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id))
        .then(async res => {
            const data = await res.json().catch(() => ({success:false, message:'Invalid server response.'}));
            return data;
        })
        .then(renderPreview)
        .catch(err => {
            document.getElementById('previewBody').innerHTML =
                '<div class="modal-error"><i class="fas fa-triangle-exclamation"></i><br>Network/server error: ' + err + '</div>';
        });
}

function closePreviewModal() {
    document.getElementById('previewOverlay').style.display = 'none';
    pendingDelete = null;
}

function fmt(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString(undefined, {maximumFractionDigits: 2});
}

function renderPreview(data) {
    if (!data.success) {
        document.getElementById('previewTitle').textContent = 'Cannot preview this record';
        document.getElementById('previewBody').innerHTML =
            '<div class="modal-error"><i class="fas fa-triangle-exclamation"></i><br>' + escapeHtml(data.message || 'Unknown error') + '</div>';
        return;
    }

    document.getElementById('previewTitle').textContent = data.title || 'Confirm Delete';
    document.getElementById('previewSub').textContent = 'This shows the LIVE stock right now, before you decide.';

    let html = '';
    if (data.no_stock_impact) {
        html += '<div class="no-impact-note"><i class="fas fa-circle-info"></i> No stock is affected by this delete.</div>';
    } else {
        data.changes.forEach(c => {
            const blocked = !!c.blocked;
            html += '<div class="change-card ' + (blocked ? 'blocked' : 'ok') + '">';
            html += '  <div class="change-item-name">' + escapeHtml(c.item_name) + (c.unit ? ' (' + escapeHtml(c.unit) + ')' : '') + '</div>';
            html += '  <div class="balance-row">';
            html += '    <div class="balance-block"><div class="balance-label">Before</div><div class="balance-value before">' + fmt(c.current_stock) + '</div></div>';
            html += '    <div class="balance-arrow"><i class="fas fa-arrow-right"></i></div>';
            html += '    <div class="balance-block"><div class="balance-label">After</div><div class="balance-value ' + (blocked ? 'after-blocked' : 'after-ok') + '">' + (blocked ? '✕' : fmt(c.new_stock)) + '</div></div>';
            html += '  </div>';
            if (c.reason) {
                html += '  <div class="change-reason' + (blocked ? '' : ' info') + '">' + escapeHtml(c.reason) + '</div>';
            }
            html += '</div>';
        });
    }
    document.getElementById('previewBody').innerHTML = html;

    document.getElementById('previewActions').style.display = 'flex';
    const confirmBtn = document.getElementById('previewConfirmBtn');
    if (data.overall_blocked) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Blocked — fix above first';
    } else {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Yes, Delete';
    }
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

function doActualDelete() {
    if (!pendingDelete) return;
    const { type, id, btnEl } = pendingDelete;
    const confirmBtn = document.getElementById('previewConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

    fetch('delete_record.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id) +
              '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(async res => {
        const data = await res.json().catch(() => ({success: false, message: 'The server returned an invalid response.'}));
        if (!res.ok && !data.message) data.message = 'Request failed (' + res.status + ').';
        return data;
    })
    .then(data => {
        if (data.success) {
            closePreviewModal();
            const row = btnEl.closest('tr');
            row.style.background = '#ffe3e3';
            row.style.transition = 'opacity 0.4s';
            setTimeout(() => { row.style.opacity = '0'; setTimeout(() => row.remove(), 400); }, 300);
        } else {
            document.getElementById('previewBody').innerHTML =
                '<div class="modal-error"><i class="fas fa-triangle-exclamation"></i><br>' + escapeHtml(data.message) + '</div>';
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Try Again';
        }
    })
    .catch(err => {
        document.getElementById('previewBody').innerHTML =
            '<div class="modal-error"><i class="fas fa-triangle-exclamation"></i><br>Network/server error: ' + err + '</div>';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-trash"></i> Try Again';
    });
}
</script>

</body>
</html>
