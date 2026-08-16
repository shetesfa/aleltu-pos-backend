<?php
// all_transactions.php - View all transactions (Admin Only)
require_once 'config.php';

// Only admin and super_admin allowed
if($_SESSION['role'] == 'seller') {
    header("Location: seller_pos.php");
    exit();
}

// Get branch info
$user_branch = getUserBranch($conn, $_SESSION['user_id']);
$current_branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
$current_branch_name = getCurrentBranchName($conn, $current_branch_id);

// Filter parameters
$location_filter = isset($_GET['location']) ? intval($_GET['location']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$payment_filter = isset($_GET['payment']) ? $_GET['payment'] : '';

// Build WHERE clause with branch filter
// FORCE branch filter - cannot be bypassed
$branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);

if ($_SESSION['role'] == 'super_admin' && isset($_GET['branch_id']) && $_GET['branch_id'] > 0) {
    $branch_id = intval($_GET['branch_id']);
} elseif ($_SESSION['role'] != 'super_admin') {
    // Non-superadmin MUST use their assigned branch
    $branch_id = getCurrentBranchId($conn, $_SESSION['user_id'], $_SESSION['role']);
}

$where = "WHERE t.branch_id = $branch_id";
// NOTE: "location_id" does not exist on the transactions table (no locations
// table exists at all), so this filter can never be applied at the database
// level. Removed to stop a fatal SQL error if ?location= is ever passed.
if(!empty($date_from)) {
    $where .= " AND DATE(t.transaction_date) >= '$date_from'";
}
if(!empty($date_to)) {
    $where .= " AND DATE(t.transaction_date) <= '$date_to'";
}
if(!empty($payment_filter)) {
    $where .= " AND t.payment_method = '$payment_filter'";
}

// Get all transactions with pagination (LIMIT prevents loading all 10,000+ rows at once)
$per_page = 100;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Count total for pagination
$count_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM transactions t $where");
$total_rows = mysqli_fetch_assoc($count_result)['total'] ?? 0;
$total_pages = ceil($total_rows / $per_page);

$query = "SELECT t.*
          FROM transactions t
          $where
          ORDER BY t.transaction_date DESC
          LIMIT $offset, $per_page";
$result = mysqli_query($conn, $query);

// No locations table — set empty result
$locations_result = null;

// Get payment methods
$payment_query = "SELECT DISTINCT payment_method FROM transactions WHERE branch_id = $current_branch_id ORDER BY payment_method";
$payment_result = mysqli_query($conn, $payment_query);

// Get total
$total_query = "SELECT SUM(total_amount) as total FROM transactions t $where";
$total_result = mysqli_query($conn, $total_query);
$total_row = mysqli_fetch_assoc($total_result);
$total_amount = $total_row['total'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/jpg" href="image\photo_2026-01-12_07-44-10.jpg">
    <title>All Transactions</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial; background: #f5f5f5; padding: 10px; }
        
        .header {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .branch-badge {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .back-btn {
            background: #3498db;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-bottom: 20px;
            min-height: 44px;
            text-align: center;
        }
        
        .filter-form {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .filter-row {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            width: 100%;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        select, input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            min-height: 44px;
        }
        
        .filter-btn, .reset-btn {
            padding: 12px 20px;
            border-radius: 5px;
            cursor: pointer;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex: 1;
            min-width: 100%;
        }

        .filter-btn {
            background: #27ae60;
            color: white;
            border: none;
        }
        
        .reset-btn {
            background: #e74c3c;
            color: white;
            border: none;
            text-decoration: none;
        }
        
        table {
            width: 100%;
            background: white;
            border-collapse: collapse;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        
        th {
            background: #2c3e50;
            color: white;
            padding: 12px;
            text-align: left;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .total-row {
            background: #27ae60;
            color: white;
            font-weight: bold;
            font-size: 16px;
        }
        
        .delete-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 3px;
            cursor: pointer;
            min-height: 44px;
        }
        
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        
        .page-btn {
            background: #3498db;
            color: white;
            padding: 12px 15px;
            text-decoration: none;
            margin: 5px;
            border-radius: 5px;
            display: inline-block;
            min-height: 44px;
        }

        /* Responsive Breakpoints */
        @media(min-width: 600px) {
            body { padding: 20px; }
            .header { padding: 20px; }
            .filter-row { flex-direction: row; flex-wrap: wrap; }
            .filter-group { flex: 1; min-width: 200px; }
            .filter-btn, .reset-btn { min-width: auto; flex: none; }
            th { padding: 15px; }
            td { padding: 12px 15px; }
            .total-row { font-size: 18px; }
            .delete-btn { padding: 5px 10px; min-height: auto; }
        }

        @media(min-width: 900px) {
            table { display: table; white-space: normal; overflow-x: visible; }
        }
    </style>
</head>
<body>
    <a href="admin_dashboard.php" class="back-btn">← Back to Dashboard</a>
    
    <div class="header">
        <h1>📋 All Transactions</h1>
        <p>View and filter all transactions</p>
        <div class="branch-badge">
            <i class="fas fa-store"></i> <?php echo htmlspecialchars($current_branch_name); ?>
        </div>
    </div>
    
    <!-- Filter Form -->
    <form method="GET" action="" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label>Location:</label>
                <select name="location">
                    <option value="0">All Locations</option>
                    <!-- locations filter removed - table does not exist -->
                </select>
            </div>
            
            <div class="filter-group">
                <label>Date From:</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="filter-group">
                <label>Date To:</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="filter-group">
                <label>Payment Method:</label>
                <select name="payment">
                    <option value="">All Methods</option>
                    <?php while($pay = mysqli_fetch_assoc($payment_result)): ?>
                        <option value="<?php echo $pay['payment_method']; ?>" <?php echo $payment_filter == $pay['payment_method'] ? 'selected' : ''; ?>>
                            <?php echo $pay['payment_method']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="filter-btn">🔍 Apply Filter</button>
            <a href="all_transactions.php" class="reset-btn">🔄 Reset Filter</a>
        </div>
    </form>
    
    <!-- Results -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Date & Time</th>
                <th>Seller</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Change</th>
                <th>Payment</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if(mysqli_num_rows($result) > 0): ?>
                <?php while($row = mysqli_fetch_assoc($result)): ?>
                <tr>
                    <td>#<?php echo $row['id']; ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($row['transaction_date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['seller_name'] ?? 'N/A'); ?></td>
                    <td style="font-weight:bold;color:#27ae60;"><?php echo number_format($row['total_amount'], 2); ?> ETB</td>
                    <td><?php echo number_format($row['paid_amount'], 2); ?> ETB</td>
                    <td><?php echo number_format($row['change_amount'], 2); ?> ETB</td>
                    <td><span style="background:#e3f2fd;padding:5px 10px;border-radius:15px;"><?php echo $row['payment_method']; ?></span></td>
                    <td>
                        <button class="delete-btn" onclick="deleteTransaction(<?php echo $row['id']; ?>)">Delete</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:#999;">No transactions found</td>
                </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4">TOTAL:</td>
                <td colspan="5"><?php echo number_format($total_amount, 2); ?> ETB</td>
            </tr>
        </tfoot>
    </table>
    
    <!-- Export Button -->
    <div style="text-align:center;margin-top:20px;">
        <button onclick="exportToExcel()" style="background:#27ae60;color:white;padding:12px 25px;border:none;border-radius:5px;cursor:pointer;font-size:16px;">
            📊 Export to Excel
        </button>
    </div>

    <script>
        function deleteTransaction(id) {
            if(confirm('Are you sure you want to delete this transaction?')) {
                window.location.href = 'super_delete_manager.php?tab=transactions&search=' + encodeURIComponent(id);
            }
        }
        
        function exportToExcel() {
            // Get current filter parameters
            let location = <?php echo $location_filter; ?>;
            let date_from = "<?php echo $date_from; ?>";
            let date_to = "<?php echo $date_to; ?>";
            let payment = "<?php echo $payment_filter; ?>";
            let branch = <?php echo $current_branch_id; ?>;
            
            // Create export URL
            let url = 'export_all_transactions.php?' +
                      'location=' + location +
                      '&date_from=' + date_from +
                      '&date_to=' + date_to +
                      '&payment=' + encodeURIComponent(payment) +
                      '&branch_id=' + branch;
            
            // Open in new tab
            window.open(url, '_blank');
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
