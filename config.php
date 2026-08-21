<?php

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https,    // true only on HTTPS, false on local HTTP development
        'httponly' => true,         // JS cannot read the session cookie
        'samesite' => 'Lax',        // Lax allows redirect cookies to work properly
    ]);
    session_start();
}

// ── Inactivity Auto-Logout: 30 minutes (1800 seconds) ───────────────────
if (isset($_SESSION['user_id'])) {
    $inactive_timeout = 1800; // 30 minutes
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactive_timeout)) {
        session_unset();
        session_destroy();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Session expired due to inactivity. Please log in again.']);
            exit();
        }
        header("Location: index.php?expired=1");
        exit();
    }
    $_SESSION['last_activity'] = time();
}
// ─────────────────────────────────────────────────────────────────────────


$host     = getenv('DB_HOST') ?: '127.0.0.1';
$user     = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$database = getenv('DB_NAME') ?: 'aleltu';

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
@mysqli_real_connect($conn, $host, $user, $password, $database);
if (!$conn || mysqli_connect_errno()) {
    $host = 'localhost';
    @mysqli_real_connect($conn, $host, $user, $password, $database);
}

if (!$conn || mysqli_connect_errno()) {
    die("Connection failed: " . mysqli_connect_error());
}

// ── ENCODING & TIMEZONE LOCK ─────────────────────────────────────────────
date_default_timezone_set('Africa/Addis_Ababa');
mysqli_query($conn, "SET time_zone = '+03:00'");
mysqli_set_charset($conn, 'utf8mb4');                          // Layer 1: PHP driver
mysqli_query($conn, "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'"); // Layer 2: session
mysqli_query($conn, "SET character_set_results = 'utf8mb4'"); // Layer 3: results
// ─────────────────────────────────────────────────────────────────────────

// ============================================
// BRANCH FUNCTIONS - SESSION-CACHED VERSION
// Every function checks session FIRST before any DB query
// ============================================

function getUserBranch($conn, $user_id) {
    if (!$user_id) return null;
    // Use session cache if available and not garbled
    if (isset($_SESSION['branch_id'], $_SESSION['branch_name']) && $_SESSION['branch_id'] > 0 && strpos($_SESSION['branch_name'], 'á') === false) {
        return ['branch_id' => $_SESSION['branch_id'], 'branch_name' => $_SESSION['branch_name']];
    }
    $stmt = mysqli_prepare($conn, "SELECT u.branch_id, p.place_name as branch_name FROM users u LEFT JOIN places p ON u.branch_id = p.id WHERE u.id = ?");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_assoc($result);
    if ($data && isset($data['branch_name'])) {
        $_SESSION['branch_id'] = $data['branch_id'];
        $_SESSION['branch_name'] = $data['branch_name'];
    }
    return $data;
}

function setBranchSession($branch_id, $branch_name) {
    $_SESSION['branch_id']           = $branch_id;
    $_SESSION['branch_name']         = $branch_name;
    $_SESSION['selected_branch_id']  = $branch_id;
    $_SESSION['selected_branch_name']= $branch_name;
    $_SESSION['current_branch_id']   = $branch_id;
    $_SESSION['current_branch_name'] = $branch_name;
}

function getCurrentBranchId($conn, $user_id, $user_role) {
    // SUPER ADMIN
    if ($user_role == 'super_admin') {
        if (isset($_GET['branch_id']) && $_GET['branch_id'] > 0) {
            $branch_id = intval($_GET['branch_id']);
            $name = getCurrentBranchName($conn, $branch_id);
            setBranchSession($branch_id, $name);
            return $branch_id;
        }
        foreach (['branch_id','selected_branch_id','current_branch_id'] as $key) {
            if (!empty($_SESSION[$key]) && $_SESSION[$key] > 0) return intval($_SESSION[$key]);
        }
        return 0;
    }

    // REGULAR USERS - check session first (avoids DB hit on every page)
    foreach (['branch_id','selected_branch_id','current_branch_id'] as $key) {
        if (!empty($_SESSION[$key]) && $_SESSION[$key] > 0) return intval($_SESSION[$key]);
    }

    // Only hit DB if session has nothing
    if ($user_id) {
        $stmt = mysqli_prepare($conn, "SELECT branch_id FROM users WHERE id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                if ($row['branch_id'] > 0) {
                    $_SESSION['branch_id'] = $row['branch_id'];
                    return $row['branch_id'];
                }
            }
        }
    }

    // Last resort - first active branch
    $result = mysqli_query($conn, "SELECT id FROM places WHERE status = 'active' LIMIT 1");
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }
    return 1;
}

function getCurrentBranchName($conn, $branch_id) {
    if (!$branch_id || $branch_id <= 0) return 'No Branch Selected';
    
    // Invalidate garbled session cache automatically
    if (isset($_SESSION['branch_name']) && strpos($_SESSION['branch_name'], 'á') !== false) {
        unset($_SESSION['branch_name'], $_SESSION['selected_branch_name'], $_SESSION['current_branch_name'], $_SESSION['_branch_cache'], $_SESSION['_all_branches']);
    }
    
    // Session cache check
    if (isset($_SESSION['branch_name'], $_SESSION['branch_id']) && $_SESSION['branch_id'] == $branch_id) {
        return $_SESSION['branch_name'];
    }
    
    $stmt = mysqli_prepare($conn, "SELECT place_name FROM places WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $branch_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $_SESSION['_branch_cache'][$branch_id] = $row['place_name'];
            $_SESSION['branch_name'] = $row['place_name'];
            return $row['place_name'];
        }
    }
    return 'Unknown Branch';
}

function getAllBranches($conn) {
    // Invalidate garbled session cache automatically
    if (isset($_SESSION['_all_branches']) && is_array($_SESSION['_all_branches'])) {
        foreach ($_SESSION['_all_branches'] as $b) {
            if (isset($b['place_name']) && strpos($b['place_name'], 'á') !== false) {
                unset($_SESSION['_all_branches']);
                break;
            }
        }
    }
    
    // Cache in session - places table almost never changes
    if (isset($_SESSION['_all_branches']) && !empty($_SESSION['_all_branches'])) {
        return $_SESSION['_all_branches'];
    }
    $branches = [];
    $result = mysqli_query($conn, "SELECT id, place_name FROM places WHERE status = 'active' ORDER BY place_name");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $branches[] = $row;
        }
    }
    $_SESSION['_all_branches'] = $branches;
    return $branches;
}

function displayBranchSelector($conn, $current_branch_id) {
    $branches = getAllBranches($conn);
    ?>
    <div class="branch-selector" style="margin:10px 0;padding:10px;background:rgba(255,255,255,0.1);border-radius:8px;">
        <form method="GET" action="" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <i class="fas fa-store"></i>
            <span style="color:white;">ቅርንጫፍ ምረጥ:</span>
            <select name="branch_id" onchange="this.form.submit()" style="padding:8px;border-radius:5px;border:1px solid #ddd;background:white;color:#333;min-width:200px;">
                <option value="">-- ቅርንጫፍ ምረጥ --</option>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo ($current_branch_id == $branch['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch['place_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit">Go</button></noscript>
        </form>
    </div>
    <?php
}

// ============================================================
// READ-ONLY USER SUPPORT
// Checks if the current logged-in user is marked as read_only
// ============================================================

function loadReadOnlyFlag($conn, $user_id) {
    // Cache in session so we don't query on every page load
    if (isset($_SESSION['read_only'])) {
        return;
    }
    if (!$user_id) {
        $_SESSION['read_only'] = 0;
        return;
    }
    $stmt = mysqli_prepare($conn, "SELECT read_only FROM users WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        $_SESSION['read_only'] = ($row && !empty($row['read_only'])) ? 1 : 0;
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['read_only'] = 0;
    }
}

function isReadOnly() {
    return !empty($_SESSION['read_only']);
}

function userHasBranchAccess($conn, $user_id, $branch_id) {
    $user_role = $_SESSION['role'] ?? '';
    if ($user_role == 'super_admin') {
        // Super admin can access all active branches
        $branches = getAllBranches($conn); // uses cache
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) return true;
        }
        return false;
    }
    $my_branch = getCurrentBranchId($conn, $user_id, $user_role); // uses session cache
    return ($my_branch == $branch_id);
}

/**
 * Resolve a branch for a write request without trusting a browser-supplied
 * branch id. Regular users are always restricted to their own branch;
 * super administrators may choose an active branch.
 */
function resolveWriteBranchId($conn, $requested_branch_id = 0) {
    if (empty($_SESSION['user_id'])) {
        return 0;
    }

    $user_id = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? '';
    $requested_branch_id = (int) $requested_branch_id;

    if ($role !== 'super_admin') {
        return (int) getCurrentBranchId($conn, $user_id, $role);
    }

    $branch_id = $requested_branch_id > 0
        ? $requested_branch_id
        : (int) getCurrentBranchId($conn, $user_id, $role);

    return userHasBranchAccess($conn, $user_id, $branch_id) ? $branch_id : 0;
}

function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return !empty($_SESSION['csrf_token']) &&
        is_string($token) &&
        hash_equals($_SESSION['csrf_token'], $token);
}

// ── MODERN EXCEL STYLING & LOGO FUNCTIONS — REAL .XLSX VERSION (USE THIS) ──
/**
 * Why this replaced the HTML/mso "fake .xls" approach above:
 * Excel's HTML-import engine does NOT reliably support base64 data: URIs
 * as <img> sources - depending on Excel version it either ignores them or
 * turns them into a broken "linked image" (the exact "linked image cannot
 * be displayed" error). There is no HTML/CSS fix for that; it's a format
 * limitation. A real .xlsx built with PhpSpreadsheet embeds the logo as an
 * actual drawing object and uses real cell fills/borders, so it always
 * renders and the gradient strip can never get cut short by column drift.
 *
 * Requires: composer require phpoffice/phpspreadsheet
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function getExcelLogoPathReal() {
    $logoPath = __DIR__ . '/image/photo_2026-01-12_07-44-10.jpg';
    return file_exists($logoPath) ? $logoPath : '';
}

/** Multi-stop vibrant spectrum gradient palette (Indigo -> Emerald -> Gold -> Ruby -> Indigo). */
function getExcelGradientPalette($reverse = false) {
    $palette = [
        '312E81', '4338CA', '1E40AF', '0284C7', '059669', 
        'D97706', 'F59E0B', 'FBBF24', 'FDE68A', 'FBBF24', 
        'F59E0B', 'D97706', '059669', '0284C7', '1E40AF', 
        '4338CA', '312E81'
    ];
    return $reverse ? array_reverse($palette) : $palette;
}

/**
 * Fills one worksheet row with a real multi-stop gradient built from individual
 * cell background fills - one solid colour per column, resampled from
 * the vibrant brand palette.
 */
function renderExcelGradientRow($sheet, $row, $colStart, $colCount, $reverse = false) {
    $palette = getExcelGradientPalette($reverse);
    $colCount = max(1, (int)$colCount);
    for ($i = 0; $i < $colCount; $i++) {
        $pos = ($colCount > 1) ? $i / ($colCount - 1) : 0;
        $idx = (int)round($pos * (count($palette) - 1));
        $color = $palette[$idx];
        $col = $colStart + $i;
        $cell = $sheet->getCell([$col, $row]);
        $cell->getStyle()->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB($color);
    }
    $sheet->getRowDimension($row)->setRowHeight(8);
}

/**
 * Builds the branded banner: double gradient ribbon, perfectly centered logo (embedded drawing),
 * title, and branch/date pill - across rows $startRow.. and columns 1..$colCount.
 */
function renderExcelBannerReal($sheet, $reportTitle, $branchName, $dateRangeText, $startRow, $colCount) {
    $Fill = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
    $Alignment = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;

    $row = $startRow;

    // Top vibrant spectrum gradient ribbon
    renderExcelGradientRow($sheet, $row, 1, $colCount, false);
    $row++;

    // Royal Midnight Obsidian banner block - merged across all columns
    $bannerRows = 6;
    $lastCol = $colCount;
    $sheet->mergeCells([1, $row, $lastCol, $row + $bannerRows - 1]);
    $bannerCell = $sheet->getCell([1, $row]);
    $bannerCell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
    $bannerCell->getStyle()->getAlignment()->setHorizontal($Alignment::HORIZONTAL_CENTER)->setVertical($Alignment::VERTICAL_CENTER);
    for ($i = 0; $i < $bannerRows; $i++) {
        $sheet->getRowDimension($row + $i)->setRowHeight(20);
    }

    // Embedded logo - real drawing object, perfectly centered over the merged banner block
    $logoPath = getExcelLogoPathReal();
    if ($logoPath !== '') {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(76);

        $logoWidth = $drawing->getWidth();
        if ($logoWidth <= 0) {
            $logoWidth = 81;
        }

        // Calculate total width in pixels across all columns and determine exact anchor column
        $colPixelWidths = [];
        $totalWidthPx = 0;
        for ($c = 1; $c <= $colCount; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $w = $sheet->getColumnDimension($colLetter)->getWidth();
            $px = ($w > 0) ? ($w * 7.5 + 8) : 96;
            $colPixelWidths[] = $px;
            $totalWidthPx += $px;
        }

        $targetCenterPx = max(0, ($totalWidthPx - $logoWidth) / 2);

        $accumulatedPx = 0;
        $anchorColIndex = 1;
        $offsetX = 0;

        foreach ($colPixelWidths as $i => $px) {
            if ($accumulatedPx + $px > $targetCenterPx) {
                $anchorColIndex = $i + 1;
                $offsetX = max(0, (int)round($targetCenterPx - $accumulatedPx));
                break;
            }
            $accumulatedPx += $px;
        }

        $anchorColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($anchorColIndex);
        $drawing->setCoordinates($anchorColLetter . $row);

        $bannerHeightPx = $bannerRows * 20;
        $centerOffsetY = max(0, (int)(($bannerHeightPx - $drawing->getHeight()) / 2));

        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($centerOffsetY);
        $drawing->setWorksheet($sheet);
    }

    // Main Title
    $sheet->mergeCells([1, $row + $bannerRows, $lastCol, $row + $bannerRows]);
    $titleCell = $sheet->getCell([1, $row + $bannerRows]);
    $titleCell->setValue('አሌልቱ የእንስሳት ተዋጽኦ');
    $titleCell->getStyle()->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('FBBF24');
    $titleCell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
    $titleCell->getStyle()->getAlignment()->setHorizontal($Alignment::HORIZONTAL_CENTER)->setVertical($Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($row + $bannerRows)->setRowHeight(28);

    // Report Subtitle
    $sheet->mergeCells([1, $row + $bannerRows + 1, $lastCol, $row + $bannerRows + 1]);
    $subCell = $sheet->getCell([1, $row + $bannerRows + 1]);
    $subCell->setValue($reportTitle);
    $subCell->getStyle()->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('F8FAFC');
    $subCell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
    $subCell->getStyle()->getAlignment()->setHorizontal($Alignment::HORIZONTAL_CENTER)->setVertical($Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($row + $bannerRows + 1)->setRowHeight(22);

    // Branch / Date Pill Badge
    $pillText = 'ቅርንጫፍ: ' . $branchName . ($dateRangeText !== '' ? '   |   ' . $dateRangeText : '');
    $sheet->mergeCells([1, $row + $bannerRows + 2, $lastCol, $row + $bannerRows + 2]);
    $pillCell = $sheet->getCell([1, $row + $bannerRows + 2]);
    $pillCell->setValue($pillText);
    $pillCell->getStyle()->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('0F172A');
    $pillCell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('F59E0B');
    $pillCell->getStyle()->getAlignment()->setHorizontal($Alignment::HORIZONTAL_CENTER)->setVertical($Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($row + $bannerRows + 2)->setRowHeight(22);

    $row = $row + $bannerRows + 3;

    // Bottom spectrum gradient ribbon (mirrored palette)
    renderExcelGradientRow($sheet, $row, 1, $colCount, true);
    $row++;

    return $row; // next free row for the data table
}

/** Styles a header row (column titles) with rich Royal Indigo + Bright Gold accents. */
function styleExcelHeaderRow($sheet, $row, $colCount) {
    $Fill = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
    $Border = \PhpOffice\PhpSpreadsheet\Style\Border::class;
    $Alignment = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;
    for ($c = 1; $c <= $colCount; $c++) {
        $cell = $sheet->getCell([$c, $row]);
        $cell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('1E1B4B');
        $cell->getStyle()->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('FDE68A');
        $cell->getStyle()->getBorders()->getAllBorders()->setBorderStyle($Border::BORDER_THIN)->getColor()->setRGB('4338CA');
        $cell->getStyle()->getAlignment()->setHorizontal($Alignment::HORIZONTAL_CENTER)->setVertical($Alignment::VERTICAL_CENTER);
    }
    $sheet->getRowDimension($row)->setRowHeight(26);
}

/** Styles data rows with vibrant, high-visibility line-by-line alternating colors (Soft Gold vs Soft Sky). */
function styleExcelDataRow($sheet, $row, $colCount, $isEven) {
    $Fill = \PhpOffice\PhpSpreadsheet\Style\Fill::class;
    $Border = \PhpOffice\PhpSpreadsheet\Style\Border::class;
    for ($c = 1; $c <= $colCount; $c++) {
        $cell = $sheet->getCell([$c, $row]);
        if ($isEven) {
            // Soft warm golden-amber tint for even rows
            $cell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('FEF9C3');
        } else {
            // Soft fresh sky cyan tint for odd rows
            $cell->getStyle()->getFill()->setFillType($Fill::FILL_SOLID)->getStartColor()->setRGB('E0F2FE');
        }
        $cell->getStyle()->getBorders()->getAllBorders()->setBorderStyle($Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $cell->getStyle()->getFont()->setSize(10)->getColor()->setRGB('0F172A');
    }
    $sheet->getRowDimension($row)->setRowHeight(22);
}

/** Sends the built Spreadsheet to the browser as a real .xlsx download and exits. */
function downloadExcelSpreadsheet($spreadsheet, $filename) {
    if (substr($filename, -5) !== '.xlsx') {
        $filename .= '.xlsx';
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

/**
 * Mathematically exact Ethiopian Calendar Converter
 * Accurately supports Leap Years (ዘመነ ዮሐንስ - ጳጉሜ 6 ቀናት) and New Year roll-over.
 */
function getEthiopianDate($gregorianDate = null) {
    $timestamp = $gregorianDate ? strtotime($gregorianDate) : time();
    if (!$timestamp) $timestamp = time();
    
    $gy = (int)date('Y', $timestamp);
    $gm = (int)date('m', $timestamp);
    $gd = (int)date('d', $timestamp);
    
    $a = (int)((14 - $gm) / 12);
    $y = $gy + 4800 - $a;
    $m = $gm + 12 * $a - 3;
    $jdn = $gd + (int)((153 * $m + 2) / 5) + 365 * $y + (int)($y / 4) - (int)($y / 100) + (int)($y / 400) - 32045;
    
    $eth_epoch = 1723856;
    $r = ($jdn - $eth_epoch) % 1461;
    $n = ($r % 365) + 365 * (int)($r / 1460);
    
    $eth_year = 4 * (int)(($jdn - $eth_epoch) / 1461) + (int)($r / 365) - (int)($r / 1460);
    $eth_month = (int)($n / 30) + 1;
    $eth_day = ($n % 30) + 1;
    if ($eth_month > 13) $eth_month = 13;
    
    $months = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ኅዳር', 4 => 'ታኅሣሥ',
        5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት', 8 => 'ሚያዝያ',
        9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ'
    ];
    
    return [
        'day' => $eth_day,
        'month' => $eth_month,
        'month_name' => $months[$eth_month],
        'year' => $eth_year,
        'formatted' => "$eth_day {$months[$eth_month]} $eth_year",
        'short' => sprintf("%04d-%02d-%02d", $eth_year, $eth_month, $eth_day)
    ];
}

/**
 * Convert Ethiopian Date (Year, Month, Day) to Gregorian 'YYYY-MM-DD'
 */
function ethiopianToGregorianDate($year, $month, $day) {
    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;
    if ($year <= 0 || $month <= 0 || $day <= 0) return date('Y-m-d');
    
    // Julian Day Number for Ethiopian Date
    $jdn = (1723856 + 365) + 365 * ($year - 1) + (int)($year / 4) + 30 * ($month - 1) + $day - 1;
    
    // JDN to Gregorian
    $l = $jdn + 68569;
    $n = (int)((4 * $l) / 146097);
    $l = $l - (int)((146097 * $n + 3) / 4);
    $i = (int)((4000 * ($l + 1)) / 1461001);
    $l = $l - (int)((1461 * $i) / 4) + 31;
    $j = (int)((80 * $l) / 2447);
    $day_g = $l - (int)((2447 * $j) / 80);
    $l = (int)($j / 11);
    $month_g = $j + 2 - (12 * $l);
    $year_g = 100 * ($n - 49) + $i + $l;
    
    return sprintf("%04d-%02d-%02d", $year_g, $month_g, $day_g);
}

/**
 * Convert Gregorian Datetime to 12-Hour Ethiopian Time Display
 */
if (!function_exists('get_ethiopian_time_display')) {
    function get_ethiopian_time_display($gregorian_datetime) {
        if (!$gregorian_datetime) return '-';
        try {
            $dt = new DateTime($gregorian_datetime, new DateTimeZone('Africa/Addis_Ababa'));
        } catch (Exception $e) {
            return '-';
        }
        $hour24 = (int)$dt->format('G'); // 0-23
        $min = $dt->format('i');
        
        // Ethiopian 12-hour calculation (6:00 AM Gregorian is 12:00 ጥዋት Ethiopian)
        $eth_hour = ($hour24 + 6) % 12;
        if ($eth_hour === 0) $eth_hour = 12;
        
        if ($hour24 >= 6 && $hour24 < 12) {
            $period = 'ጥዋት';
        } elseif ($hour24 >= 12 && $hour24 < 18) {
            $period = 'ከሰዓት';
        } elseif ($hour24 >= 18 && $hour24 < 24) {
            $period = 'ማታ';
        } else {
            $period = 'ሌሊት';
        }
        
        return "{$eth_hour}:{$min} {$period}";
    }
}
?>
