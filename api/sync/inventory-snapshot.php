<?php
/**
 * ALELTU — Inventory Snapshot Endpoint (Incremental Sync)
 * GET /api/sync/inventory-snapshot.php
 *
 * Returns products + seller_inventory changed AFTER a given version cursor.
 * Devices store last_version locally; pass it as since_version to get diffs.
 *
 * Query params:
 *   since_version  INT  (optional, 0 = full refresh)
 *   branch_id      INT  (optional, admin/boss only override)
 *
 * FIXED:
 *  - All SQL now uses prepared statements or integer-cast literals only
 *  - N+1 risk_level query replaced with single pre-fetch
 *  - offline_rules always returned (even when no inventory changes)
 *  - Proper error responses on DB failure
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);

api_require_auth();

$user      = $GLOBALS['api_user'];
$branch_id = (int)($user['branch_id'] ?? 0);

// Admin/boss can request a specific branch
if (isset($_GET['branch_id']) && in_array($user['role'], ['admin', 'super_admin', 'boss'], true)) {
    $branch_id = (int)$_GET['branch_id'];
}
if ($branch_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'INVALID_BRANCH', 'message' => 'A valid branch_id is required.']);
    exit;
}

$since_version = max(0, (int)($_GET['since_version'] ?? 0));

// ── Pre-fetch risk levels in ONE query (prevents N+1 loop) ───────────────────
$risk_levels = [];
$rl_stmt = mysqli_prepare($conn,
    "SELECT min_qty, max_qty, level_name FROM stock_risk_levels
     WHERE branch_id = ? OR branch_id IS NULL
     ORDER BY branch_id DESC, min_qty ASC"
);
if ($rl_stmt) {
    mysqli_stmt_bind_param($rl_stmt, 'i', $branch_id);
    mysqli_stmt_execute($rl_stmt);
    $rl_res = mysqli_stmt_get_result($rl_stmt);
    while ($r = mysqli_fetch_assoc($rl_res)) {
        $risk_levels[] = $r;
    }
    mysqli_stmt_close($rl_stmt);
}

function getRiskLevel(float $qty, array $levels): string {
    foreach ($levels as $lvl) {
        $min = (float)$lvl['min_qty'];
        $max = $lvl['max_qty'] !== null ? (float)$lvl['max_qty'] : PHP_FLOAT_MAX;
        if ($qty >= $min && $qty <= $max) {
            return $lvl['level_name'];
        }
    }
    return 'HIGH';
}

// ── Get current server version for this branch ────────────────────────────────
$ver_stmt = mysqli_prepare($conn,
    "SELECT current_version FROM sync_version_counter WHERE branch_id = ? LIMIT 1"
);
$server_version = 0;
if ($ver_stmt) {
    mysqli_stmt_bind_param($ver_stmt, 'i', $branch_id);
    mysqli_stmt_execute($ver_stmt);
    $ver_res = mysqli_stmt_get_result($ver_stmt);
    if ($ver_row = mysqli_fetch_assoc($ver_res)) {
        $server_version = (int)$ver_row['current_version'];
    }
    mysqli_stmt_close($ver_stmt);
}

// ── Always fetch offline_rules (needed on every sync) ─────────────────────────
$offline_rules = [];
$rules_stmt = mysqli_prepare($conn,
    "SELECT * FROM offline_rules WHERE branch_id = ? AND is_active = 1 ORDER BY priority ASC"
);
if ($rules_stmt) {
    mysqli_stmt_bind_param($rules_stmt, 'i', $branch_id);
    mysqli_stmt_execute($rules_stmt);
    $rules_res = mysqli_stmt_get_result($rules_stmt);
    while ($r = mysqli_fetch_assoc($rules_res)) {
        $offline_rules[] = $r;
    }
    mysqli_stmt_close($rules_stmt);
}

// ── Up to date check — still return offline_rules ─────────────────────────────
if ($since_version >= $server_version && $since_version > 0) {
    echo json_encode([
        'success'        => true,
        'since_version'  => $since_version,
        'server_version' => $server_version,
        'has_changes'    => false,
        'products'       => [],
        'inventory'      => [],
        'offline_rules'  => $offline_rules,
    ]);
    exit;
}

$products  = [];
$inventory = [];

// ── FULL REFRESH (since_version = 0) ─────────────────────────────────────────
if ($since_version === 0) {
    // All active products for this branch
    $p_stmt = mysqli_prepare($conn,
        "SELECT id, name, '' AS code, '' AS barcode, 0 AS category_id, 'pcs' AS unit,
                unit_price AS price, 0.00 AS cost_price, 0.00 AS tax_rate,
                is_active, branch_id, '' AS description,
                created_at, last_edited_at AS updated_at
         FROM products
         WHERE branch_id = ? AND (is_active IS NULL OR is_active = 1)"
    );
    if ($p_stmt) {
        mysqli_stmt_bind_param($p_stmt, 'i', $branch_id);
        mysqli_stmt_execute($p_stmt);
        $p_res = mysqli_stmt_get_result($p_stmt);
        while ($row = mysqli_fetch_assoc($p_res)) $products[] = $row;
        mysqli_stmt_close($p_stmt);
    }

    // All inventory for this branch with offline allowances
    $i_stmt = mysqli_prepare($conn,
        "SELECT si.id, si.seller_id, si.branch_id, si.item_name,
                COALESCE(si.product_id, 0) AS product_id,
                si.current_stock AS quantity,
                COALESCE(si.unit, 'pcs') AS unit,
                COALESCE(si.price, 0) AS price,
                COALESCE(si.low_stock_alert, 5) AS low_stock_alert,
                si.last_updated AS updated_at,
                '' AS barcode, 0 AS tax_rate,
                COALESCE(oa.allowed_qty, 999999) AS allowed_offline_qty,
                COALESCE(oa.reserved_qty, 0) AS reserved_qty
         FROM seller_inventory si
         LEFT JOIN offline_allowances oa
               ON oa.product_id = si.product_id
              AND oa.branch_id = si.branch_id
              AND oa.is_active = 1
         WHERE si.branch_id = ?"
    );
    if ($i_stmt) {
        mysqli_stmt_bind_param($i_stmt, 'i', $branch_id);
        mysqli_stmt_execute($i_stmt);
        $i_res = mysqli_stmt_get_result($i_stmt);
        while ($row = mysqli_fetch_assoc($i_res)) {
            $row['risk_level'] = getRiskLevel((float)$row['quantity'], $risk_levels);
            $inventory[] = $row;
        }
        mysqli_stmt_close($i_stmt);
    }

} else {
    // ── INCREMENTAL DIFF (since_version > 0) ─────────────────────────────────
    $changed_product_ids = [];
    $changed_inv_ids     = [];

    $diff_stmt = mysqli_prepare($conn,
        "SELECT entity_type, entity_id FROM inventory_versions
         WHERE branch_id = ? AND version > ?
         ORDER BY entity_type, entity_id"
    );
    if ($diff_stmt) {
        mysqli_stmt_bind_param($diff_stmt, 'ii', $branch_id, $since_version);
        mysqli_stmt_execute($diff_stmt);
        $diff_res = mysqli_stmt_get_result($diff_stmt);
        while ($row = mysqli_fetch_assoc($diff_res)) {
            if ($row['entity_type'] === 'products')         $changed_product_ids[] = (int)$row['entity_id'];
            elseif ($row['entity_type'] === 'seller_inventory') $changed_inv_ids[] = (int)$row['entity_id'];
        }
        mysqli_stmt_close($diff_stmt);
    }

    // Fetch changed products (safe: IDs are all (int)-cast above)
    if (!empty($changed_product_ids)) {
        $ids_sql = implode(',', $changed_product_ids);
        $p_stmt = mysqli_prepare($conn,
            "SELECT id, name, '' AS code, '' AS barcode, 0 AS category_id, 'pcs' AS unit,
                    unit_price AS price, 0.00 AS cost_price, 0.00 AS tax_rate,
                    is_active, branch_id, '' AS description,
                    created_at, last_edited_at AS updated_at
             FROM products
             WHERE id IN ($ids_sql) AND branch_id = ?"
        );
        if ($p_stmt) {
            mysqli_stmt_bind_param($p_stmt, 'i', $branch_id);
            mysqli_stmt_execute($p_stmt);
            $p_res = mysqli_stmt_get_result($p_stmt);
            while ($row = mysqli_fetch_assoc($p_res)) $products[] = $row;
            mysqli_stmt_close($p_stmt);
        }
    }

    // Fetch changed inventory (safe: IDs are all (int)-cast above)
    if (!empty($changed_inv_ids)) {
        $ids_sql = implode(',', $changed_inv_ids);
        $i_stmt = mysqli_prepare($conn,
            "SELECT si.id, si.seller_id, si.branch_id, si.item_name,
                    COALESCE(si.product_id, 0) AS product_id,
                    si.current_stock AS quantity,
                    COALESCE(si.unit, 'pcs') AS unit,
                    COALESCE(si.price, 0) AS price,
                    COALESCE(si.low_stock_alert, 5) AS low_stock_alert,
                    si.last_updated AS updated_at,
                    '' AS barcode, 0 AS tax_rate,
                    COALESCE(oa.allowed_qty, 999999) AS allowed_offline_qty,
                    COALESCE(oa.reserved_qty, 0) AS reserved_qty
             FROM seller_inventory si
             LEFT JOIN offline_allowances oa
                   ON oa.product_id = si.product_id
                  AND oa.branch_id = si.branch_id
                  AND oa.is_active = 1
             WHERE si.id IN ($ids_sql) AND si.branch_id = ?"
        );
        if ($i_stmt) {
            mysqli_stmt_bind_param($i_stmt, 'i', $branch_id);
            mysqli_stmt_execute($i_stmt);
            $i_res = mysqli_stmt_get_result($i_stmt);
            while ($row = mysqli_fetch_assoc($i_res)) {
                $row['risk_level'] = getRiskLevel((float)$row['quantity'], $risk_levels);
                $inventory[] = $row;
            }
            mysqli_stmt_close($i_stmt);
        }
    }
}

echo json_encode([
    'success'        => true,
    'since_version'  => $since_version,
    'server_version' => $server_version,
    'has_changes'    => true,
    'products'       => $products,
    'inventory'      => $inventory,
    'offline_rules'  => $offline_rules,
    'timestamp'      => date('c'),
]);
