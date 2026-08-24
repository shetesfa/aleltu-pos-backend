<?php
// filter_by_item.php - Filter by Item (Admin Only)
require_once 'config.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

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
$item_name      = isset($_GET['item'])      ? trim($_GET['item'])      : '';
$location_filter = isset($_GET['location'])  ? intval($_GET['location']) : 0;
$date_from      = isset($_GET['date_from'])  ? $_GET['date_from']       : '';
$date_to        = isset($_GET['date_to'])    ? $_GET['date_to']         : '';

// Get unique items for dropdown with branch filter
$items_query = "SELECT DISTINCT ti.product_name FROM transaction_items ti
                JOIN transactions t ON ti.transaction_id = t.id
                WHERE t.branch_id = $current_branch_id
                ORDER BY ti.product_name";
$items_result = mysqli_query($conn, $items_query);

// NOTE: there is no "locations" table in the database — this dropdown is kept
// for the page layout but has nothing to populate it. Filtering by location
// is intentionally disabled below instead of crashing on a table that doesn't exist.
$locations_result = null;

// Build WHERE clause with branch filter
$where = "WHERE t.branch_id = $current_branch_id";
if (!empty($item_name)) {
    $safe_item_name = mysqli_real_escape_string($conn, $item_name);
    $where .= " AND ti.product_name LIKE '%$safe_item_name%'";
}
// NOTE: "location_id" does not exist on the transactions table, so this
// filter cannot be applied at the database level. $location_filter is kept
// only so the dropdown on the page still remembers what was selected.
if(!empty($date_from)) {
    $safe_date_from = mysqli_real_escape_string($conn, $date_from);
    $where .= " AND DATE(t.transaction_date) >= '$safe_date_from'";
}
if(!empty($date_to)) {
    $safe_date_to = mysqli_real_escape_string($conn, $date_to);
    $where .= " AND DATE(t.transaction_date) <= '$safe_date_to'";
}

// Get item sales data
$sales_query = "SELECT 
                ti.product_name,
                SUM(ti.quantity) as total_quantity,
                SUM(ti.subtotal) as total_amount,
                COUNT(DISTINCT ti.transaction_id) as transaction_count,
                '' as location_name
                FROM transaction_items ti
                JOIN transactions t ON ti.transaction_id = t.id
                $where
                GROUP BY ti.product_name
                ORDER BY total_amount DESC";
$sales_result = mysqli_query($conn, $sales_query);

// Get total summary
$summary_query = "SELECT 
                  SUM(ti.quantity) as total_items_sold,
                  SUM(ti.subtotal) as total_sales_amount
                  FROM transaction_items ti
                  JOIN transactions t ON ti.transaction_id = t.id
                  $where";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/jpg" href="image\photo_2026-01-12_07-44-10.jpg">
    <title>Filter by Item</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial; background: #f5f5f5; padding: 10px; }
        
        @media(min-width: 600px) {
            body { padding: 20px; }
        }

        .header {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        @media(min-width: 600px) {
            .header { padding: 20px; }
        }
        
        .branch-badge {
            display: inline-block;
            background: #9b59b6;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .back-btn {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            min-height: 44px;
        }
        
        .filter-form {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        @media(min-width: 600px) {
            .filter-form { padding: 20px; }
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-direction: column;
        }

        @media(min-width: 600px) {
            .filter-row {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }
        
        .filter-group {
            flex: 1;
            width: 100%;
        }

        @media(min-width: 600px) {
            .filter-group {
                min-width: 200px;
                width: auto;
            }
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        select, input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            min-height: 44px;
        }
        
        .filter-btn {
            background: #9b59b6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            min-height: 44px;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        @media(min-width: 600px) {
            .filter-btn {
                width: auto;
            }
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media(min-width: 600px) {
            .summary-cards {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .card .value {
            font-size: 24px;
            font-weight: bold;
            color: #9b59b6;
            margin: 10px 0;
        }

        @media(min-width: 600px) {
            .card .value {
                font-size: 32px;
            }
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

        @media(min-width: 900px) {
            table {
                display: table;
                white-space: normal;
                overflow: hidden;
            }
        }
        
        th {
            background: #9b59b6;
            color: white;
            padding: 12px 10px;
            text-align: left;
        }

        @media(min-width: 600px) {
            th { padding: 15px; }
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }

        @media(min-width: 600px) {
            td { padding: 12px 15px; }
        }
        
        .item-name {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .progress-bar {
            height: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            margin: 5px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #9b59b6, #3498db);
            border-radius: 10px;
        }
        
        .no-data {
            text-align: center;
            padding: 30px 20px;
            background: white;
            border-radius: 10px;
            color: #7f8c8d;
        }

        @media(min-width: 600px) {
            .no-data { padding: 50px; }
        }
    </style>
</head>
<body>
    <a href="admin_dashboard.php" class="back-btn">← Back to Dashboard</a>
    
    <div class="header">
        <h1>🔍 Filter by Item</h1>
        <p>Analyze sales by specific items</p>
        <div class="branch-badge"><?php echo htmlspecialchars($current_branch_name); ?></div>
    </div>
    
    <!-- Filter Form -->
    <form method="GET" action="" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label>Item Name:</label>
                <input type="text" name="item" list="items-list" value="<?php echo htmlspecialchars($item_name); ?>" placeholder="Enter item name">
                <datalist id="items-list">
                    <?php while($item = mysqli_fetch_assoc($items_result)): ?>
                        <option value="<?php echo htmlspecialchars($item['product_name']); ?>">
                    <?php endwhile; ?>
                </datalist>
            </div>
            
            <div class="filter-group">
                <label>Location:</label>
                <select name="location">
                    <option value="0">All Locations</option>
                    <?php 
                    if ($locations_result) {
                        mysqli_data_seek($locations_result, 0);
                        while($loc = mysqli_fetch_assoc($locations_result)): ?>
                            <option value="<?php echo $loc['id']; ?>" <?php echo $location_filter == $loc['id'] ? 'selected' : ''; ?>>
                                <?php echo $loc['name']; ?>
                            </option>
                        <?php endwhile;
                    } ?>
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
        </div>
        
        <button type="submit" class="filter-btn">🔍 Filter Items</button>
        <a href="filter_by_item.php" style="margin-left: 10px; color: #666; text-decoration: none;">Reset</a>
    </form>
    
    <?php if(!empty($item_name) || $location_filter > 0 || !empty($date_from)): ?>
        <!-- Summary -->
        <div class="summary-cards">
            <div class="card">
                <h3>Total Items Sold</h3>
                <div class="value"><?php echo number_format($summary['total_items_sold'] ?? 0); ?></div>
                <p>Units</p>
            </div>
            
            <div class="card">
                <h3>Total Sales Amount</h3>
                <div class="value"><?php echo number_format($summary['total_sales_amount'] ?? 0, 2); ?> ETB</div>
                <p>From filtered items</p>
            </div>
            
            <div class="card">
                <h3>Unique Items</h3>
                <div class="value"><?php echo mysqli_num_rows($sales_result); ?></div>
                <p>Different products</p>
            </div>
        </div>
        
        <!-- Results Table -->
        <?php if(mysqli_num_rows($sales_result) > 0): ?>
            <h2>Item Sales Details</h2>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Location</th>
                        <th>Quantity Sold</th>
                        <th>Total Amount</th>
                        <th>Transactions</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Find max amount for progress bar
                    mysqli_data_seek($sales_result, 0);
                    $max_amount = 0;
                    while($row = mysqli_fetch_assoc($sales_result)) {
                        if($row['total_amount'] > $max_amount) {
                            $max_amount = $row['total_amount'];
                        }
                    }
                    mysqli_data_seek($sales_result, 0);
                    
                    while($row = mysqli_fetch_assoc($sales_result)): 
                        $percentage = $max_amount > 0 ? ($row['total_amount'] / $max_amount) * 100 : 0;
                    ?>
                    <tr>
                        <td class="item-name"><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><?php echo $row['location_name']; ?></td>
                        <td><strong><?php echo $row['total_quantity']; ?></strong> units</td>
                        <td style="font-weight:bold;color:#27ae60;"><?php echo number_format($row['total_amount'], 2); ?> ETB</td>
                        <td><?php echo $row['transaction_count']; ?> sales</td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                            <small><?php echo number_format($percentage, 1); ?>% of top item</small>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <!-- Export Button -->
            <div style="text-align:center;margin-top:20px;">
                <a href="export_item_report.php?item=<?php echo urlencode($item_name); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&branch_id=<?php echo $current_branch_id; ?>" style="background:#9b59b6;color:white;padding:12px 25px;border:none;border-radius:6px;cursor:pointer;font-size:16px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;font-weight:bold;">
                    📊 Export Item Report (.xlsx)
                </a>
            </div>
        <?php else: ?>
            <div class="no-data">
                <h3>No item sales found for the selected filters</h3>
                <p>Try different search terms or date ranges</p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-data">
            <h3>👆 Select filters above to view item sales data</h3>
            <p>Try searching for "Coffee" or select a specific location</p>
        </div>
    <?php endif; ?>
</body>
</html>
<?php mysqli_close($conn); ?>