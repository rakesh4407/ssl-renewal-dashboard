<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'config.php';


if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$isAgent = (isset($_SESSION['user']) && $_SESSION['user'] === 'agentssllock');

$csvFile = "../uploads/SSLProject_import.csv";

$daysFilter = $_GET['days'] ?? '';
$productFilter = $_GET['product'] ?? '';
$monthFilter = $_GET['month'] ?? '';

$totalSSL = 0;
$redZone = 0;
$yellowZone = 0;
$activeZone = 0;
$unknownZone = 0; // rows whose expiry date couldn't be parsed in any known format

$products = [];
$rows = [];

// ── Notification buckets ──
$notify_critical = []; // <= 10 days
$notify_urgent = []; // <= 20 days
$notify_soon = []; // <= 30 days

/**
 * FIX: a single, shared date parser tried everywhere the same way.
 * Previously, the count/zone/filter logic only accepted 'd/m/Y',
 * while the table-rendering code separately tried 'd-m-Y', 'd-M-Y',
 * and 'd/m/Y'. That mismatch meant a date format that wasn't 'd/m/Y'
 * could render fine in the table but silently break the summary
 * cards, the notification panels, and the day/month filters.
 */
function parseExpiryDate($value)
{
    $value = trim($value);
    if ($value === '')
        return false;

    // Normalize 2-digit years (e.g. 07-07-26) to 4-digit years (e.g. 07-07-2026) to prevent negative day bugs.
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/', $value, $matches)) {
        $year = (int) $matches[3];
        $year += ($year < 50) ? 2000 : 1900;
        $value = sprintf('%02d-%02d-%04d', $matches[1], $matches[2], $year);
    }

    $formats = ['d/m/Y', 'd-m-Y', 'd-M-Y', 'Y-m-d', 'm/d/Y'];
    foreach ($formats as $fmt) {
        $dateObj = DateTime::createFromFormat($fmt, $value);
        // createFromFormat can return a "loose" match (e.g. trailing
        // garbage) without erroring, so double check there were no
        // parse warnings/errors before trusting it.
        if ($dateObj) {
            $errors = DateTime::getLastErrors();
            if (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $dateObj;
            }
        }
    }
    return false;
}

// ── Load saved date overrides from MySQL ──
$dateOverrides = [];
if (isset($db_connected) && $db_connected) {
    $result = $conn->query("SELECT * FROM date_overrides");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dateOverrides[$row['domain']] = [
                'orderNo'          => $row['order_no'],
                'actualDate'       => $row['actual_date'],
                'actualExpiryDate' => $row['actual_expiry_date'],
                'reissueRem'       => $row['reissue_rem'],
                'email'            => $row['email'],
                'phone'            => $row['phone'],
                'term'             => $row['term'],
                'issue'            => $row['issue_date'],
                'expiry'           => $row['expiry_date'],
            ];
        }
    }
}

if (file_exists($csvFile)) {

    // FIX: previously the CSV was opened and read twice — once just to
    // collect distinct product names, once to build $rows. Combined
    // into a single pass: simpler, faster, and avoids any chance of
    // the two reads seeing inconsistent data.
    $handle = fopen($csvFile, "r");
    fgetcsv($handle); // skip header row

    while (($row = fgetcsv($handle)) !== FALSE) {

        $orderNo = $row[0] ?? '';
        $orgName = $row[1] ?? '';
        $sslProduct = $row[2] ?? '';
        $domain = $row[3] ?? '';
        $actualDate = $row[4] ?? '';
        $actualExpiryDate = $row[5] ?? '';
        $reissueRem = $row[6] ?? '';
        $email = $row[7] ?? '';
        $phone = $row[8] ?? '';
        $term = $row[9] ?? '';
        $issueDate = $row[10] ?? '';
        $expiryDate = $row[11] ?? '';

        // Apply overrides if they exist
        if (isset($dateOverrides[$domain]['term'])) {
            $term = $dateOverrides[$domain]['term'];
        }
        if (isset($dateOverrides[$domain]['orderNo'])) {
            $orderNo = $dateOverrides[$domain]['orderNo'];
        }
        if (isset($dateOverrides[$domain]['issue'])) {
            $issueDate = $dateOverrides[$domain]['issue'];
        }
        if (isset($dateOverrides[$domain]['expiry'])) {
            $expiryDate = $dateOverrides[$domain]['expiry'];
        }
        if (isset($dateOverrides[$domain]['actualDate'])) {
            $actualDate = $dateOverrides[$domain]['actualDate'];
        }
        if (isset($dateOverrides[$domain]['actualExpiryDate'])) {
            $actualExpiryDate = $dateOverrides[$domain]['actualExpiryDate'];
        }
        if (isset($dateOverrides[$domain]['reissueRem'])) {
            $reissueRem = $dateOverrides[$domain]['reissueRem'];
        }
        if (isset($dateOverrides[$domain]['email'])) {
            $email = $dateOverrides[$domain]['email'];
        }
        if (isset($dateOverrides[$domain]['phone'])) {
            $phone = $dateOverrides[$domain]['phone'];
        }



        $daysLeft = '';

        if (!empty($sslProduct))
            $products[$sslProduct] = true;

        $dateObj = parseExpiryDate($expiryDate);
        if ($dateObj) {
            $expiryTS = $dateObj->getTimestamp();
            $daysLeft = ceil(($expiryTS - time()) / 86400);
        }

        $totalSSL++;

        if (is_numeric($daysLeft)) {
            if ($daysLeft <= 30)
                $redZone++;
            elseif ($daysLeft <= 60)
                $yellowZone++;
            else
                $activeZone++;

            // Auto-renewal notification buckets
            if ($daysLeft >= 0) {
                if ($daysLeft <= 10)
                    $notify_critical[] = ['domain' => $domain, 'org' => $orgName, 'days' => $daysLeft, 'expiry' => $expiryDate];
                elseif ($daysLeft <= 20)
                    $notify_urgent[] = ['domain' => $domain, 'org' => $orgName, 'days' => $daysLeft, 'expiry' => $expiryDate];
                elseif ($daysLeft <= 30)
                    $notify_soon[] = ['domain' => $domain, 'org' => $orgName, 'days' => $daysLeft, 'expiry' => $expiryDate];
            }
        } else {
            // FIX: rows whose date couldn't be parsed used to vanish
            // from every zone count while still counting toward
            // $totalSSL, so totals never added up. Track them explicitly.
            $unknownZone++;
        }

        $show = true;
        if ($daysFilter == 'expired' && !(is_numeric($daysLeft) && $daysLeft < 0))
            $show = false;
        if ($daysFilter == '0-30' && !(is_numeric($daysLeft) && $daysLeft >= 0 && $daysLeft <= 30))
            $show = false;
        if ($daysFilter == '31-60' && !(is_numeric($daysLeft) && $daysLeft >= 31 && $daysLeft <= 60))
            $show = false;
        if ($daysFilter == '61-90' && !(is_numeric($daysLeft) && $daysLeft >= 61 && $daysLeft <= 90))
            $show = false;
        if ($daysFilter == '90plus' && !(is_numeric($daysLeft) && $daysLeft > 90))
            $show = false;
        if ($productFilter && $sslProduct != $productFilter)
            $show = false;

        if (!empty($monthFilter)) {
            if ($dateObj) {
                if ($dateObj->format('Y-m') != $monthFilter)
                    $show = false;
            } else {
                $show = false;
            }
        }

        if (!$show)
            continue;

        $rows[] = [
            'orderNo' => $orderNo,
            'orgName' => $orgName,
            'sslProduct' => $sslProduct,
            'domain' => $domain,
            'actualDate' => $actualDate,
            'actualExpiryDate' => $actualExpiryDate,
            'reissueRem' => $reissueRem,
            'email' => $email,
            'phone' => $phone,
            'term' => $term,
            'issueDate' => $issueDate,
            'expiryDate' => $expiryDate,
            'daysLeft' => $daysLeft
        ];
    }
    fclose($handle);

    // Sort rows by Days Left (smallest to largest)
    usort($rows, function ($a, $b) {
        // If a date couldn't be parsed, treat it as a very large number so it goes to the bottom
        $aDays = is_numeric($a['daysLeft']) ? (int) $a['daysLeft'] : 999999;
        $bDays = is_numeric($b['daysLeft']) ? (int) $b['daysLeft'] : 999999;
        return $aDays <=> $bDays;
    });
}

// ── Load saved renewal statuses from MySQL ──
$renewalStatuses = [];
if (isset($db_connected) && $db_connected) {
    $result = $conn->query("SELECT domain, status FROM ssl_renewal_status");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $renewalStatuses[$row['domain']] = $row['status'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="SSL Certificate Renewal Dashboard — Track, monitor and manage SSL certificate renewals">
    <title>🔐 SSL Renewal Dashboard</title>
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔐</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        /* ══════════════════════════════════════════════
   DESIGN SYSTEM — CSS CUSTOM PROPERTIES
══════════════════════════════════════════════ */
        :root {
            --font-main: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --bg-body: #f0f2f5;
            --bg-card: #ffffff;
            --bg-header: rgba(255, 255, 255, 0.85);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --gradient-total: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-danger: linear-gradient(135deg, #f5365c 0%, #f56036 100%);
            --gradient-warning: linear-gradient(135deg, #fb6340 0%, #fbb140 100%);
            --gradient-success: linear-gradient(135deg, #2dce89 0%, #2dcecc 100%);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ── Dark Mode ── */
        [data-theme="dark"] {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-header: rgba(15, 23, 42, 0.9);
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.2);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.4);
        }

        [data-theme="dark"] body {
            background: var(--bg-body);
            color: var(--text-primary);
        }

        [data-theme="dark"] .card,
        [data-theme="dark"] #sslCheckerBox {
            background: var(--bg-card);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .table {
            color: var(--text-primary);
        }

        [data-theme="dark"] .table-hover tbody tr:hover {
            background: rgba(102, 126, 234, 0.08) !important;
        }

        [data-theme="dark"] .table thead th {
            background: #0f172a !important;
        }

        [data-theme="dark"] .form-select,
        [data-theme="dark"] .form-control {
            background: var(--bg-card);
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .notif-critical {
            background: rgba(220, 53, 69, 0.1);
        }

        [data-theme="dark"] .notif-urgent {
            background: rgba(253, 126, 20, 0.1);
        }

        [data-theme="dark"] .notif-soon {
            background: rgba(40, 167, 69, 0.1);
        }

        [data-theme="dark"] .ssl-info-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        [data-theme="dark"] .filter-bar {
            background: var(--bg-card);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .alert-warning {
            background: rgba(253, 126, 20, 0.15);
            color: #fbb140;
            border-color: rgba(253, 126, 20, 0.3);
        }

        [data-theme="dark"] .legend-bar {
            background: var(--bg-card);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .table-responsive {
            box-shadow: var(--shadow-md);
        }

        [data-theme="dark"] .bg-white {
            background: var(--bg-card) !important;
        }

        /* ══════════════════════════════════════════════
   BASE STYLES
══════════════════════════════════════════════ */
        * {
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-main);
            background: var(--bg-body);
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
            margin: 0;
        }

        /* ── Sticky Header Bar ── */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--bg-header);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 14px 24px;
            transition: var(--transition);
        }

        .top-header h2 {
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.3px;
            margin: 0;
            background: var(--gradient-total);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions .btn {
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            padding: 6px 14px;
            transition: var(--transition);
        }

        .header-actions .btn:hover {
            transform: translateY(-1px);
        }

        .user-badge {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
        }

        /* Dark mode toggle */
        .dark-toggle {
            background: none;
            border: 2px solid var(--border-color);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .dark-toggle:hover {
            border-color: #667eea;
            transform: rotate(20deg);
        }

        /* ── Section Headings ── */
        .section-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ══════════════════════════════════════════════
   NOTIFICATION PANELS
══════════════════════════════════════════════ */
        .notif-panel {
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 10px;
            animation: slideInLeft 0.4s ease-out;
            transition: var(--transition);
        }

        .notif-panel:hover {
            transform: translateX(4px);
        }

        .notif-critical {
            background: rgba(220, 53, 69, 0.08);
            border-left: 4px solid #f5365c;
        }

        .notif-urgent {
            background: rgba(253, 126, 20, 0.08);
            border-left: 4px solid #fb6340;
        }

        .notif-soon {
            background: rgba(45, 206, 137, 0.08);
            border-left: 4px solid #2dce89;
        }

        .notif-panel .badge-count {
            font-size: 1.1rem;
            font-weight: 700;
        }

        .notif-panel ul {
            margin: 8px 0 0;
            padding-left: 20px;
            font-size: 0.88rem;
            line-height: 1.7;
            color: var(--text-secondary);
        }

        .notif-panel li strong {
            color: var(--text-primary);
        }

        /* ══════════════════════════════════════════════
   SSL CHECKER BOX
══════════════════════════════════════════════ */
        #sslCheckerBox {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 22px 26px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        #sslCheckerBox:hover {
            box-shadow: var(--shadow-md);
        }

        #sslResult {
            margin-top: 16px;
            display: none;
        }

        .ssl-valid {
            color: #2dce89;
        }

        .ssl-expiring {
            color: #fb6340;
        }

        .ssl-expired {
            color: #f5365c;
        }

        .ssl-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-top: 12px;
        }

        .ssl-info-item {
            background: var(--bg-body);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            transition: var(--transition);
        }

        .ssl-info-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .ssl-info-item label {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: block;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .ssl-info-item span {
            font-weight: 600;
            font-size: 0.92rem;
            color: var(--text-primary);
        }

        /* ══════════════════════════════════════════════
   FILTER BAR
══════════════════════════════════════════════ */
        .filter-bar {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 18px 22px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .filter-bar .form-select,
        .filter-bar .form-control {
            border-radius: var(--radius-sm);
            font-size: 0.88rem;
            border-color: var(--border-color);
            transition: var(--transition);
        }

        .filter-bar .form-select:focus,
        .filter-bar .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .filter-bar .btn-primary {
            background: var(--gradient-total);
            border: none;
            font-weight: 600;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }

        .filter-bar .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        /* ── Legend Bar ── */
        .legend-bar {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 12px 18px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .legend-bar .badge {
            font-weight: 600;
            font-size: 0.78rem;
            border-radius: 6px;
        }

        /* ══════════════════════════════════════════════
   GRADIENT STAT CARDS
══════════════════════════════════════════════ */
        .stat-card {
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            color: #fff;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            border: none;
            cursor: default;
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            width: 130px;
            height: 130px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            top: -40px;
            right: -30px;
            transition: var(--transition);
        }

        .stat-card:hover::before {
            transform: scale(1.2);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            bottom: -20px;
            left: -10px;
        }

        .stat-card.total {
            background: var(--gradient-total);
        }

        .stat-card.danger {
            background: var(--gradient-danger);
        }

        .stat-card.warning {
            background: var(--gradient-warning);
        }

        .stat-card.success {
            background: var(--gradient-success);
        }

        .stat-card .stat-icon {
            font-size: 1.6rem;
            margin-bottom: 6px;
        }

        .stat-card .stat-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            opacity: 0.85;
            margin-bottom: 4px;
        }

        .stat-card .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            position: relative;
            z-index: 1;
        }

        /* ── Chart Container ── */
        .chart-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 220px;
        }

        /* ══════════════════════════════════════════════
   TABLE STYLES
══════════════════════════════════════════════ */
        .table-search-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .table-search-bar .search-input {
            max-width: 320px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            padding: 8px 14px 8px 36px;
            font-size: 0.88rem;
            background: var(--bg-card) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z'/%3E%3C/svg%3E") no-repeat 12px center;
            transition: var(--transition);
            color: var(--text-primary);
        }

        .table-search-bar .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.12);
            outline: none;
        }

        .table-search-bar .result-count {
            font-size: 0.82rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .table-responsive {
            border-radius: var(--radius-lg);
            overflow-x: auto;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
        }

        .table {
            margin: 0;
        }

        .table thead th {
            background: #1e293b !important;
            color: #e2e8f0;
            font-weight: 600;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: none;
            padding: 14px 12px;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 11px 12px;
            font-size: 0.86rem;
            vertical-align: middle;
            border-color: var(--border-color);
            transition: background 0.2s;
        }

        .table tbody tr {
            transition: var(--transition);
        }

        .table-hover tbody tr:hover {
            transform: scale(1.001);
        }

        /* ── Colour-alert cells ── */
        .cell-green {
            background-color: #2dce89 !important;
            color: #fff !important;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.82rem;
        }

        .cell-orange {
            background-color: #fb6340 !important;
            color: #fff !important;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.82rem;
        }

        .cell-red {
            background-color: #f5365c !important;
            color: #fff !important;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.82rem;
        }

        .cell-blue {
            background-color: #667eea !important;
            color: #fff !important;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.82rem;
        }

        .cell-green:hover,
        .cell-orange:hover,
        .cell-red:hover,
        .cell-blue:hover {
            filter: brightness(1.1);
            transform: scale(1.02);
        }

        .cell-green:hover::after,
        .cell-orange:hover::after,
        .cell-red:hover::after {
            content: " ✉ Toggle";
            font-size: 10px;
            font-weight: 400;
            opacity: 0.8;
        }

        /* ══════════════════════════════════════════════
   ANIMATIONS
══════════════════════════════════════════════ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .animate-in {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .animate-in-1 {
            animation-delay: 0.05s;
        }

        .animate-in-2 {
            animation-delay: 0.1s;
        }

        .animate-in-3 {
            animation-delay: 0.15s;
        }

        .animate-in-4 {
            animation-delay: 0.2s;
        }

        /* ── Editable Dates ── */
        .editable-date {
            cursor: pointer;
            position: relative;
            transition: var(--transition);
        }

        .editable-date:hover {
            background: rgba(102, 126, 234, 0.08);
        }

        .editable-date:hover::after {
            content: '✎ Double-click to edit';
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            font-size: 0.65rem;
            padding: 4px 8px;
            border-radius: 4px;
            white-space: nowrap;
            pointer-events: none;
            z-index: 100;
        }

        .editable-date input[type="date"] {
            width: 130px;
            padding: 4px;
            border: 1px solid #667eea;
            border-radius: 4px;
            font-family: inherit;
            font-size: 0.8rem;
            outline: none;
        }

        /* ── Editable Field (Email, Phone) ── */
        .editable-field {
            cursor: pointer;
            position: relative;
            transition: var(--transition);
            min-width: 120px;
        }

        .editable-field:hover {
            background: rgba(102, 126, 234, 0.08);
        }

        .editable-field:hover::after {
            content: '✎ Double-click to edit';
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #fff;
            font-size: 0.65rem;
            padding: 4px 8px;
            border-radius: 4px;
            white-space: nowrap;
            pointer-events: none;
            z-index: 100;
        }

        .editable-field .field-empty {
            color: var(--text-muted);
            font-style: italic;
            font-size: 0.78rem;
        }

        /* ══════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .top-header {
                padding: 10px 14px;
            }

            .top-header h2 {
                font-size: 1.1rem;
            }

            .stat-card .stat-number {
                font-size: 1.6rem;
            }

            .ssl-info-grid {
                grid-template-columns: 1fr;
            }

            .chart-card {
                min-height: 180px;
            }

            .table-search-bar {
                flex-direction: column;
                gap: 10px;
            }

            .table-search-bar .search-input {
                max-width: 100%;
            }
        }

        /* ══════════════════════════════════════════════
   RENEWAL STATUS RADIO GROUP
══════════════════════════════════════════════ */
        .renewal-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 155px;
        }

        .renewal-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: var(--transition);
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .renewal-group label:hover {
            transform: translateX(2px);
        }

        .renewal-group input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 14px;
            height: 14px;
            border: 2px solid #cbd5e1;
            border-radius: 50%;
            transition: var(--transition);
            cursor: pointer;
            flex-shrink: 0;
            position: relative;
        }

        .renewal-group input[type="radio"]:checked {
            border-width: 4px;
        }

        /* Renewed = green */
        .renewal-group .lbl-renewed {
            color: #2dce89;
        }

        .renewal-group .lbl-renewed:hover {
            background: rgba(45, 206, 137, 0.08);
            border-color: rgba(45, 206, 137, 0.2);
        }

        .renewal-group input.radio-renewed:checked {
            border-color: #2dce89;
        }

        /* Not Renewed = red */
        .renewal-group .lbl-not-renewed {
            color: #f5365c;
        }

        .renewal-group .lbl-not-renewed:hover {
            background: rgba(245, 54, 92, 0.08);
            border-color: rgba(245, 54, 92, 0.2);
        }

        .renewal-group input.radio-not-renewed:checked {
            border-color: #f5365c;
        }

        /* Renewed with Others = blue/purple */
        .renewal-group .lbl-renewed-others {
            color: #667eea;
        }

        .renewal-group .lbl-renewed-others:hover {
            background: rgba(102, 126, 234, 0.08);
            border-color: rgba(102, 126, 234, 0.2);
        }

        .renewal-group input.radio-renewed-others:checked {
            border-color: #667eea;
        }

        /* Save feedback */
        .renewal-saving {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 2px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .renewal-saving.show {
            opacity: 1;
        }

        /* Toast notification */
        .renewal-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-family: var(--font-main);
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            z-index: 9999;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }

        .renewal-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Footer ── */
        .dashboard-footer {
            text-align: center;
            padding: 20px;
            margin-top: 30px;
            font-size: 0.78rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border-color);
        }

        /* ══════════════════════════════════════════════
   WORK EXCEL MODAL
══════════════════════════════════════════════ */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease-out;
        }

        .modal-overlay.active {
            display: flex;
        }

        .work-modal {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            width: 95%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            animation: scaleIn 0.3s ease-out;
            padding: 0;
        }

        .work-modal-header {
            padding: 22px 28px 16px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .work-modal-header h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .work-modal-header .close-btn {
            background: none;
            border: none;
            font-size: 1.4rem;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px;
            border-radius: 8px;
            transition: var(--transition);
        }

        .work-modal-header .close-btn:hover {
            background: rgba(245, 54, 92, 0.1);
            color: #f5365c;
        }

        .work-modal-body {
            padding: 24px 28px;
        }

        /* Drop zone inside modal */
        .work-drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--bg-body);
        }

        .work-drop-zone:hover,
        .work-drop-zone.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.04);
            transform: scale(1.01);
        }

        .work-drop-zone .drop-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: block;
        }

        .work-drop-zone .drop-text {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .work-drop-zone .drop-hint {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .work-drop-zone .browse-link {
            color: #667eea;
            font-weight: 600;
            text-decoration: underline;
        }

        /* File info inside modal */
        .work-file-info {
            display: none;
            background: rgba(102, 126, 234, 0.06);
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-top: 16px;
        }

        .work-file-info .file-name {
            font-weight: 700;
            font-size: 0.92rem;
        }

        .work-file-info .file-meta {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Progress section */
        .work-progress {
            display: none;
            margin-top: 20px;
        }

        .work-progress-bar-container {
            width: 100%;
            height: 10px;
            background: var(--bg-body);
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .work-progress-bar {
            height: 100%;
            width: 0%;
            background: var(--gradient-total);
            border-radius: 6px;
            transition: width 0.3s ease;
        }

        .work-progress-text {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .work-progress-domain {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Result section */
        .work-result {
            display: none;
            margin-top: 20px;
            text-align: center;
            animation: scaleIn 0.3s ease-out;
        }

        .work-result .result-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .work-result .result-text {
            font-weight: 700;
            font-size: 1.05rem;
            color: #2dce89;
            margin-bottom: 6px;
        }

        .work-result .result-stats {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 16px;
        }

        .work-result .btn-download {
            display: inline-block;
            background: var(--gradient-total);
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            font-family: var(--font-main);
        }

        .work-result .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
        }

        .work-modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .work-modal-actions .btn-start {
            flex: 1;
            background: var(--gradient-total);
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            font-family: var(--font-main);
        }

        .work-modal-actions .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
        }

        .work-modal-actions .btn-start:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .work-modal-actions .btn-cancel {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-family: var(--font-main);
        }

        .work-modal-actions .btn-cancel:hover {
            border-color: #f5365c;
            color: #f5365c;
        }

        .work-error {
            display: none;
            margin-top: 16px;
            background: rgba(245, 54, 92, 0.1);
            border: 1px solid rgba(245, 54, 92, 0.3);
            color: #f5365c;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }
    </style>
</head>

<body>

    <!-- ══════════════════════════════════════════════
     STICKY HEADER BAR
══════════════════════════════════════════════ -->
    <div class="top-header">
        <div class="d-flex justify-content-between align-items-center">
            <h2>🔐 SSL Renewal Dashboard</h2>
            <div class="header-actions">
                <span class="user-badge">👤 <?php echo htmlspecialchars($_SESSION['user']); ?></span>
                <?php if (!$isAgent): ?>
                    <button class="btn btn-sm btn-primary" onclick="downloadExcel()"
                        style="border-radius:var(--radius-sm);font-weight:600;">📥 Download Excel</button>
                    <a href="upload.php" class="btn btn-sm"
                        style="background:var(--gradient-success);color:#fff;border:none;">📤 Upload Excel</a>
                <?php endif; ?>
                <button class="btn btn-sm" onclick="openWorkExcelModal()"
                    style="background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);color:#fff;border:none;border-radius:var(--radius-sm);font-weight:600;">🔬
                    Agent Excel</button>
                <button class="dark-toggle" onclick="toggleDarkMode()" title="Toggle Dark Mode"
                    id="darkToggleBtn">🌙</button>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4 pt-4 pb-5">

        <!-- ══════════════════════════════════════════════
     🔔 AUTO-RENEWAL NOTIFICATIONS
══════════════════════════════════════════════ -->
        <?php if (count($notify_critical) || count($notify_urgent) || count($notify_soon)): ?>
            <div class="mb-4 animate-in">
                <div class="section-title">🔔 AUTO-RENEWAL NOTIFICATIONS</div>

                <?php if (count($notify_critical)): ?>
                    <div class="notif-panel notif-critical">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-danger badge-count"><?= count($notify_critical) ?></span>
                            <strong class="text-danger">🔴 CRITICAL — Expiring within 10 days!</strong>
                        </div>
                        <ul>
                            <?php foreach ($notify_critical as $n): ?>
                                <li><strong><?= htmlspecialchars($n['domain']) ?></strong> (<?= htmlspecialchars($n['org']) ?>) —
                                    <span class="text-danger fw-bold"><?= htmlspecialchars($n['days']) ?> days left</span> — expires
                                    <?= htmlspecialchars($n['expiry']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (count($notify_urgent)): ?>
                    <div class="notif-panel notif-urgent">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge badge-count" style="background:#fb6340;"><?= count($notify_urgent) ?></span>
                            <strong style="color:#fb6340;">🟠 URGENT — Expiring within 20 days</strong>
                        </div>
                        <ul>
                            <?php foreach ($notify_urgent as $n): ?>
                                <li><strong><?= htmlspecialchars($n['domain']) ?></strong> (<?= htmlspecialchars($n['org']) ?>) —
                                    <span style="color:#fb6340;" class="fw-bold"><?= htmlspecialchars($n['days']) ?> days
                                        left</span> — expires <?= htmlspecialchars($n['expiry']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (count($notify_soon)): ?>
                    <div class="notif-panel notif-soon">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success badge-count"><?= count($notify_soon) ?></span>
                            <strong class="text-success">🟢 REMINDER — Expiring within 30 days</strong>
                        </div>
                        <ul>
                            <?php foreach ($notify_soon as $n): ?>
                                <li><strong><?= htmlspecialchars($n['domain']) ?></strong> (<?= htmlspecialchars($n['org']) ?>) —
                                    <span class="text-success fw-bold"><?= htmlspecialchars($n['days']) ?> days left</span> —
                                    expires <?= htmlspecialchars($n['expiry']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success mb-4 animate-in" style="border-radius:var(--radius-md);">✅ No SSL certificates
                expiring within 30 days. All good!</div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
     📊 SUMMARY CARDS + CHART
══════════════════════════════════════════════ -->
        <div class="section-title">📊 OVERVIEW</div>
        <div class="row g-3 mb-4">
            <div class="col-lg-2 col-md-3 col-6">
                <div class="stat-card total animate-in animate-in-1">
                    <div class="stat-icon">📋</div>
                    <div class="stat-label">Total SSLs</div>
                    <div class="stat-number" data-count="<?= $totalSSL ?>">0</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <div class="stat-card danger animate-in animate-in-2">
                    <div class="stat-icon">🔴</div>
                    <div class="stat-label">0–30 Days</div>
                    <div class="stat-number" data-count="<?= $redZone ?>">0</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <div class="stat-card warning animate-in animate-in-3">
                    <div class="stat-icon">🟠</div>
                    <div class="stat-label">31–60 Days</div>
                    <div class="stat-number" data-count="<?= $yellowZone ?>">0</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <div class="stat-card success animate-in animate-in-4">
                    <div class="stat-icon">🟢</div>
                    <div class="stat-label">Active (60+)</div>
                    <div class="stat-number" data-count="<?= $activeZone ?>">0</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-12">
                <div class="chart-card animate-in" style="animation-delay:0.25s;">
                    <canvas id="sslChart"></canvas>
                </div>
            </div>
        </div>

        <?php if ($unknownZone > 0): ?>
            <div class="alert alert-warning mb-4" style="border-radius:var(--radius-md); font-size:0.88rem;">
                ⚠️ <strong><?= $unknownZone ?></strong> row(s) had an expiry date that couldn't be parsed and are excluded
                from the zone counts above.
            </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════
     ✅ SSL CERTIFICATE STATUS CHECKER
══════════════════════════════════════════════ -->
        <div class="section-title">✅ SSL CERTIFICATE CHECKER</div>
        <div id="sslCheckerBox">
            <div class="d-flex gap-2 flex-wrap">
                <input type="text" id="checkDomain" class="form-control" placeholder="Enter domain e.g. example.com"
                    style="max-width:380px; border-radius:var(--radius-sm);">
                <button class="btn btn-primary" onclick="checkSSL()"
                    style="background:var(--gradient-total);border:none;border-radius:var(--radius-sm);font-weight:600;">🔍
                    Check SSL</button>
                <button class="btn btn-outline-secondary" onclick="resetChecker()"
                    style="border-radius:var(--radius-sm);">Clear</button>
            </div>
            <div id="sslResult">
                <div id="sslStatus" class="fw-bold fs-5 mt-3"></div>
                <div class="ssl-info-grid" id="sslGrid"></div>
            </div>
            <div id="sslLoading" style="display:none;" class="mt-3 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Checking certificate...
            </div>
            <div id="sslError" style="display:none;" class="mt-3 text-danger fw-semibold"></div>
        </div>

        <!-- ══════════════════════════════════════════════
     🔎 FILTERS
══════════════════════════════════════════════ -->
        <div class="section-title">🔎 FILTERS</div>
        <div class="filter-bar">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Days Remaining</label>
                    <select name="days" class="form-select">
                        <option value="">All Days</option>
                        <option value="expired" <?= $daysFilter == 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="0-30" <?= $daysFilter == '0-30' ? 'selected' : '' ?>>0-30 Days</option>
                        <option value="31-60" <?= $daysFilter == '31-60' ? 'selected' : '' ?>>31-60 Days</option>
                        <option value="61-90" <?= $daysFilter == '61-90' ? 'selected' : '' ?>>61-90 Days</option>
                        <option value="90plus" <?= $daysFilter == '90plus' ? 'selected' : '' ?>>90+ Days</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">SSL Product</label>
                    <select name="product" class="form-select">
                        <option value="">All Products</option>
                        <?php foreach (array_keys($products) as $p) {
                            $sel = ($productFilter == $p) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($p) . "\" $sel>" . htmlspecialchars($p) . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Expiry Month</label>
                    <input type="month" name="month" value="<?= htmlspecialchars($monthFilter) ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100" style="background:var(--gradient-total);border:none;">Apply
                        Filters</button>
                </div>
            </form>
        </div>

        <!-- ── Legend ── -->
        <div class="legend-bar d-flex flex-wrap gap-3 align-items-center mb-3">
            <span class="fw-semibold small" style="color:var(--text-secondary);">Colour Legend:</span>
            <span class="badge px-3 py-2" style="background:#2dce89;">🟢 30 Days — Reminder</span>
            <span class="badge px-3 py-2" style="background:#fb6340;">🟠 20 Days — Urgent</span>
            <span class="badge px-3 py-2" style="background:#f5365c;">🔴 10 Days — Critical</span>
            <span class="small ms-auto" style="color:var(--text-muted);">💡 Click coloured cell to toggle
                clear/restore</span>
        </div>

        <!-- ══════════════════════════════════════════════
     📋 CERTIFICATES TABLE
══════════════════════════════════════════════ -->
        <div class="section-title">📋 CERTIFICATES</div>

        <div class="table-search-bar">
            <span class="result-count" id="resultCount">Showing <?= count($rows) ?> certificate(s)</span>
            <input type="text" id="tableSearch" class="search-input" placeholder="Search domains, orgs, products...">
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white" id="sslTable">
                <thead>
                    <tr>
                        <th>Order No</th>
                        <th>Org Name</th>
                        <th>SSL Product</th>
                        <th>Domain</th>
                        <th>Actual Date</th>
                        <th>Actual Expiry Date</th>
                        <th>Reissue Rem</th>
                        <th>Email</th>
                        <th>Ph.N</th>
                        <th>Term</th>
                        <th>Issue Date</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                        <th style="white-space: nowrap;">30 Day <button class="btn btn-sm py-0 px-1 ms-1"
                                style="font-size:0.7rem; background:rgba(255,255,255,0.15); color:#fff; border:1px solid rgba(255,255,255,0.3);"
                                onclick="toggleDayCols(this)" title="Minimize columns">➖</button></th>
                        <th class="col-hideable">20 Day</th>
                        <th class="col-hideable">15 Day</th>
                        <th class="col-hideable">10 Day</th>
                        <th class="col-hideable">5 Day</th>
                        <th>Renewal Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($rows as $idx => $r) {

                        // FIX: previously this was `if($r['daysLeft'] <= 30)` with no
                        // is_numeric() guard. When a date failed to parse, $r['daysLeft']
                        // is '' and PHP's loose comparison rules make '' <= 30 evaluate
                        // to true, so unparseable rows were silently painted as the most
                        // urgent ("table-danger") category. Now they get their own class.
                        if (is_numeric($r['daysLeft'])) {
                            if ($r['daysLeft'] <= 30)
                                $rowClass = 'table-danger';
                            elseif ($r['daysLeft'] <= 60)
                                $rowClass = 'table-warning';
                            else
                                $rowClass = 'table-success';
                        } else {
                            $rowClass = 'table-secondary';
                        }

                        $dateObj = parseExpiryDate($r['expiryDate']);

                        $r30 = $r20 = $r15 = $r10 = $r5 = '';
                        $colClass30 = $colClass20 = $colClass15 = $colClass10 = $colClass5 = '';

                        if ($dateObj) {
                            $expiryTS = $dateObj->getTimestamp();
                            $r30 = date('d-M-Y', strtotime('-30 days', $expiryTS));
                            $r20 = date('d-M-Y', strtotime('-20 days', $expiryTS));
                            $r15 = date('d-M-Y', strtotime('-15 days', $expiryTS));
                            $r10 = date('d-M-Y', strtotime('-10 days', $expiryTS));
                            $r5 = date('d-M-Y', strtotime('-5 days', $expiryTS));

                            $daysLeft = $r['daysLeft'];
                            if (is_numeric($daysLeft)) {
                                if ($daysLeft <= 10) {
                                    $colClass30 = $colClass20 = $colClass15 = $colClass10 = $colClass5 = 'cell-red';
                                } elseif ($daysLeft <= 20) {
                                    $colClass30 = $colClass20 = $colClass15 = $colClass10 = $colClass5 = 'cell-orange';
                                } elseif ($daysLeft <= 30) {
                                    $colClass30 = $colClass20 = $colClass15 = $colClass10 = $colClass5 = 'cell-green';
                                }
                            }
                        }

                        // Custom user fields are now initialized from CSV (or overridden above)
                    
                        $uid = "row_{$idx}";
                        $domainEsc = htmlspecialchars($r['domain']);

                        // Renewal status radio buttons — saved state from DB
                        $savedStatus = $renewalStatuses[$r['domain']] ?? '';
                        $chkRenewed = ($savedStatus === 'renewed') ? 'checked' : '';
                        $chkNotRenewed = ($savedStatus === 'not_renewed') ? 'checked' : '';
                        $chkRenewedOthers = ($savedStatus === 'renewed_with_others') ? 'checked' : '';
                        $radioName = "renewal_{$idx}";

                        $disabledAttr = $isAgent ? "disabled" : "";
                        $renewalCell = "
    <div class='renewal-group'>
        <label class='lbl-renewed'>
            <input type='radio' class='radio-renewed' name='{$radioName}' value='renewed' {$chkRenewed} {$disabledAttr}
                   onchange=\"saveRenewalStatus('{$domainEsc}', 'renewed', this)\">
            🟢 Renewed
        </label>
        <label class='lbl-not-renewed'>
            <input type='radio' class='radio-not-renewed' name='{$radioName}' value='not_renewed' {$chkNotRenewed} {$disabledAttr}
                   onchange=\"saveRenewalStatus('{$domainEsc}', 'not_renewed', this)\">
            🔴 Not Renewed
        </label>
        <label class='lbl-renewed-others'>
            <input type='radio' class='radio-renewed-others' name='{$radioName}' value='renewed_with_others' {$chkRenewedOthers} {$disabledAttr}
                   onchange=\"saveRenewalStatus('{$domainEsc}', 'renewed_with_others', this)\">
            🔄 Renewed with Others
        </label>
    </div>";

                        // FIX: orderNo, orgName, sslProduct, issueDate, and expiryDate
                        // came straight from the uploaded CSV and were echoed unescaped.
                        // Anyone able to upload a CSV with HTML/script in, e.g., an org
                        // name could inject markup/JS into the dashboard for every viewer.
                        // All CSV-derived text is now passed through htmlspecialchars().
                        echo "<tr class='{$rowClass}' data-uid='{$uid}'>";
                        $safeOrder = htmlspecialchars($r['orderNo']);
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editField(this, 'orderNo', '{$domainEsc}')\"") . ">" . $safeOrder . "</td>";
                        echo "<td>" . htmlspecialchars($r['orgName']) . "</td>";
                        echo "<td>" . htmlspecialchars($r['sslProduct']) . "</td>";
                        echo "<td>{$domainEsc}</td>";

                        $safeActualDate = htmlspecialchars($r['actualDate']);
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editDate(this, 'actualDate', '{$domainEsc}')\"") . ">" . ($safeActualDate ?: "<span class='field-empty'>—</span>") . "</td>";
                        $safeActualExpiryDate = htmlspecialchars($r['actualExpiryDate']);
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editDate(this, 'actualExpiryDate', '{$domainEsc}')\"") . ">" . ($safeActualExpiryDate ?: "<span class='field-empty'>—</span>") . "</td>";
                        $safeReissueRem = htmlspecialchars($r['reissueRem']);
                        echo "<td " . ($isAgent ? "" : "class='editable-field' ondblclick=\"editField(this, 'reissueRem', '{$domainEsc}')\"") . ">" . ($safeReissueRem ?: "<span class='field-empty'>—</span>") . "</td>";

                        $safeEmail = htmlspecialchars($r['email']);
                        $emailDisplay = $safeEmail ?: "<span class='field-empty'>—</span>";
                        echo "<td " . ($isAgent ? "" : "class='editable-field' ondblclick=\"editField(this, 'email', '{$domainEsc}')\"") . ">{$emailDisplay}</td>";
                        $safePhone = htmlspecialchars($r['phone']);
                        $phoneDisplay = $safePhone ?: "<span class='field-empty'>—</span>";
                        echo "<td " . ($isAgent ? "" : "class='editable-field' ondblclick=\"editField(this, 'phone', '{$domainEsc}')\"") . ">{$phoneDisplay}</td>";
                        $safeTerm = htmlspecialchars($r['term']);
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editField(this, 'term', '{$domainEsc}')\"") . ">" . $safeTerm . "</td>";
                        $safeIssue = htmlspecialchars($r['issueDate']);
                        $safeExpiry = htmlspecialchars($r['expiryDate']);
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editDate(this, 'issue', '{$domainEsc}')\"") . ">" . $safeIssue . "</td>";
                        echo "<td " . ($isAgent ? "" : "class='editable-date' ondblclick=\"editDate(this, 'expiry', '{$domainEsc}')\"") . ">" . $safeExpiry . "</td>";
                        echo "<td><strong>" . htmlspecialchars($r['daysLeft']) . "</strong></td>";
                        echo "<td class='{$colClass30}' data-orig='{$colClass30}' onclick=\"toggleCell(this,'30','{$uid}')\" title='Click to toggle'>{$r30}</td>";
                        echo "<td class='col-hideable {$colClass20}' data-orig='{$colClass20}' onclick=\"toggleCell(this,'20','{$uid}')\" title='Click to toggle'>{$r20}</td>";
                        echo "<td class='col-hideable {$colClass15}' data-orig='{$colClass15}' onclick=\"toggleCell(this,'15','{$uid}')\" title='Click to toggle'>{$r15}</td>";
                        echo "<td class='col-hideable {$colClass10}' data-orig='{$colClass10}' onclick=\"toggleCell(this,'10','{$uid}')\" title='Click to toggle'>{$r10}</td>";
                        echo "<td class='col-hideable {$colClass5}'  data-orig='{$colClass5}' onclick=\"toggleCell(this,'5', '{$uid}')\" title='Click to toggle'>{$r5}</td>";
                        echo "<td>{$renewalCell}</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- ── Footer ── -->
        <div class="dashboard-footer">
            🔐 SSL Renewal Dashboard · Developed by <strong>RAKESH G</strong> · <?= date('Y') ?>
        </div>

    </div><!-- /container -->

    <script>
        // ══════════════════════════════════════════
        //  Dark Mode Toggle
        // ══════════════════════════════════════════
        (function () {
            const saved = localStorage.getItem('theme') || '';
            document.documentElement.dataset.theme = saved;
            if (saved === 'dark') {
                const btn = document.getElementById('darkToggleBtn');
                if (btn) btn.textContent = '☀️';
            }
        })();
        function toggleDarkMode() {
            const html = document.documentElement;
            const isDark = html.dataset.theme === 'dark';
            html.dataset.theme = isDark ? '' : 'dark';
            localStorage.setItem('theme', html.dataset.theme);
            document.getElementById('darkToggleBtn').textContent = isDark ? '🌙' : '☀️';
        }

        // ══════════════════════════════════════════
        //  Animated Counters
        // ══════════════════════════════════════════
        function animateCounters() {
            document.querySelectorAll('.stat-number[data-count]').forEach(el => {
                const target = parseInt(el.dataset.count) || 0;
                if (target === 0) { el.textContent = '0'; return; }
                let current = 0;
                const step = Math.max(1, Math.ceil(target / 40));
                const timer = setInterval(() => {
                    current += step;
                    if (current >= target) { current = target; clearInterval(timer); }
                    el.textContent = current;
                }, 30);
            });
        }

        // ══════════════════════════════════════════
        //  Editable Dates Logic
        // ══════════════════════════════════════════
        function editDate(td, type, domain) {
            if (td.querySelector('input')) return; // already editing

            // Try to parse existing date to YYYY-MM-DD for the date input
            let origText = td.textContent.trim();
            let yyyy_mm_dd = '';

            // Simple naive parsers based on known formats (d/m/Y, d-m-Y, Y-m-d)
            const parts = origText.split(/[\/\-]/);
            if (parts.length === 3) {
                if (parts[0].length === 4) { // Y-m-d
                    yyyy_mm_dd = `${parts[0]}-${parts[1].padStart(2, '0')}-${parts[2].padStart(2, '0')}`;
                } else if (parts[2].length === 4 || parts[2].length === 2) { // d-m-Y or d/m/y
                    let year = parts[2];
                    if (year.length === 2) year = (parseInt(year) < 50 ? '20' : '19') + year;
                    yyyy_mm_dd = `${year}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                }
            }

            const input = document.createElement('input');
            input.type = 'date';
            if (yyyy_mm_dd) input.value = yyyy_mm_dd;
            input.dataset.orig = origText;

            td.textContent = '';
            td.appendChild(input);
            input.focus();

            const saveAndClose = () => {
                let newVal = input.value;
                if (!newVal) {
                    td.textContent = input.dataset.orig; // restore original if empty
                    return;
                }

                // Format back to a readable string (d-m-Y)
                const d = new Date(newVal);
                const displayStr = `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()}`;
                td.textContent = displayStr;

                // AJAX Save
                const formData = new FormData();
                formData.append('domain', domain);
                formData.append('type', type);
                formData.append('date_value', displayStr);

                let toast = document.getElementById('renewalToast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.id = 'renewalToast';
                    toast.className = 'renewal-toast';
                    document.body.appendChild(toast);
                }
                toast.textContent = '💾 Saving date...';
                toast.classList.add('show');

                fetch('save_date_override.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            toast.textContent = `✅ Saved ${type} date for ${domain}`;
                        } else {
                            toast.textContent = '❌ Error: ' + (data.error || 'Unknown');
                            td.textContent = input.dataset.orig; // revert
                        }
                        setTimeout(() => toast.classList.remove('show'), 2500);
                    })
                    .catch(() => {
                        toast.textContent = '❌ Could not save. Check server connection.';
                        td.textContent = input.dataset.orig; // revert
                        setTimeout(() => toast.classList.remove('show'), 3000);
                    });
            };

            input.addEventListener('blur', saveAndClose);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    input.blur(); // triggers saveAndClose
                } else if (e.key === 'Escape') {
                    td.textContent = input.dataset.orig;
                }
            });
        }

        function editField(td, type, domain) {
            if (td.querySelector('input')) return; // already editing

            let origText = td.textContent.trim();

            const input = document.createElement('input');
            input.type = 'text';
            input.value = origText;
            input.dataset.orig = origText;
            input.style.width = '80px';
            input.style.padding = '4px';
            input.style.border = '1px solid #667eea';
            input.style.borderRadius = '4px';
            input.style.fontFamily = 'inherit';
            input.style.fontSize = '0.8rem';
            input.style.outline = 'none';

            td.textContent = '';
            td.appendChild(input);
            input.focus();

            const saveAndClose = () => {
                let newVal = input.value.trim();
                if (newVal === '') {
                    td.textContent = input.dataset.orig; // restore original if empty
                    return;
                }

                td.textContent = newVal;

                // AJAX Save
                const formData = new FormData();
                formData.append('domain', domain);
                formData.append('type', type);
                formData.append('date_value', newVal);

                let toast = document.getElementById('renewalToast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.id = 'renewalToast';
                    toast.className = 'renewal-toast';
                    document.body.appendChild(toast);
                }
                toast.textContent = `💾 Saving ${type}...`;
                toast.classList.add('show');

                fetch('save_date_override.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            toast.textContent = `✅ Saved ${type} for ${domain}`;
                        } else {
                            toast.textContent = '❌ Error: ' + (data.error || 'Unknown');
                            td.textContent = input.dataset.orig; // revert
                        }
                        setTimeout(() => toast.classList.remove('show'), 2500);
                    })
                    .catch(() => {
                        toast.textContent = '❌ Could not save. Check server connection.';
                        td.textContent = input.dataset.orig; // revert
                        setTimeout(() => toast.classList.remove('show'), 3000);
                    });
            };

            input.addEventListener('blur', saveAndClose);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    input.blur(); // triggers saveAndClose
                } else if (e.key === 'Escape') {
                    td.textContent = input.dataset.orig;
                }
            });
        }

        // ══════════════════════════════════════════
        //  Chart.js Donut
        // ══════════════════════════════════════════
        function initChart() {
            const ctx = document.getElementById('sslChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Critical (0–30)', 'Warning (31–60)', 'Active (60+)'],
                    datasets: [{
                        data: [<?= $redZone ?>, <?= $yellowZone ?>, <?= $activeZone ?>],
                        backgroundColor: ['#f5365c', '#fb6340', '#2dce89'],
                        borderWidth: 0,
                        spacing: 4,
                        borderRadius: 6
                    }]
                },
                options: {
                    cutout: '68%',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 14,
                                usePointStyle: true,
                                pointStyleWidth: 10,
                                font: { family: "'Inter', sans-serif", size: 11, weight: '600' }
                            }
                        }
                    }
                }
            });
        }

        // ══════════════════════════════════════════
        //  Live Table Search
        // ══════════════════════════════════════════
        document.getElementById('tableSearch').addEventListener('input', function () {
            const query = this.value.toLowerCase();
            const rows = document.querySelectorAll('#sslTable tbody tr');
            let visible = 0;
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = text.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            document.getElementById('resultCount').textContent = 'Showing ' + visible + ' certificate(s)';
        });

        // ══════════════════════════════════════════
        //  SSL Certificate Status Checker (manual)
        // ══════════════════════════════════════════
        function checkSSL() {
            const domain = document.getElementById('checkDomain').value.trim().replace(/^https?:\/\//, '').replace(/\/.*/, '');
            if (!domain) { alert('Please enter a domain name.'); return; }

            document.getElementById('sslResult').style.display = 'none';
            document.getElementById('sslError').style.display = 'none';
            document.getElementById('sslLoading').style.display = 'block';

            fetch('ssl_check.php?domain=' + encodeURIComponent(domain))
                .then(r => r.json())
                .then(data => {
                    document.getElementById('sslLoading').style.display = 'none';
                    if (data.error) {
                        document.getElementById('sslError').style.display = 'block';
                        document.getElementById('sslError').textContent = '❌ ' + data.error;
                        return;
                    }
                    renderSSLResult(data);
                })
                .catch(() => {
                    document.getElementById('sslLoading').style.display = 'none';
                    document.getElementById('sslError').style.display = 'block';
                    document.getElementById('sslError').textContent = '❌ Could not reach ssl_check.php. Make sure the file exists on your server.';
                });
        }

        // FIX: renderSSLResult previously inserted d.domain, d.issuer, etc.
        // directly into innerHTML via a template literal. If ssl_check.php
        // ever echoes back attacker-influenced text (e.g. an issuer/SAN field
        // from a malicious certificate), that would execute as HTML/JS in the
        // page. Build the grid with textContent instead of innerHTML.
        function renderSSLResult(d) {
            const statusEl = document.getElementById('sslStatus');
            const gridEl = document.getElementById('sslGrid');

            let statusClass = 'ssl-valid';
            let statusIcon = '✅ Valid';
            if (d.days_left <= 0) {
                statusClass = 'ssl-expired'; statusIcon = '❌ EXPIRED';
            } else if (d.days_left <= 10) {
                statusClass = 'ssl-expired'; statusIcon = '🔴 Critical — ' + d.days_left + ' days left';
            } else if (d.days_left <= 20) {
                statusClass = 'ssl-expiring'; statusIcon = '🟠 Urgent — ' + d.days_left + ' days left';
            } else if (d.days_left <= 30) {
                statusClass = 'ssl-expiring'; statusIcon = '🟢 Expiring Soon — ' + d.days_left + ' days left';
            } else {
                statusIcon = '✅ Valid — ' + d.days_left + ' days left';
            }

            statusEl.className = statusClass;
            statusEl.textContent = statusIcon;

            const fields = [
                ['Domain', d.domain],
                ['Issued By', d.issuer || 'N/A'],
                ['Valid From', d.valid_from || 'N/A'],
                ['Valid Until', d.valid_to || 'N/A'],
                ['Days Remaining', d.days_left],
                ['Subject Alt Names', d.san || 'N/A']
            ];

            gridEl.innerHTML = '';
            fields.forEach(([label, value]) => {
                const item = document.createElement('div');
                item.className = 'ssl-info-item';

                const labelEl = document.createElement('label');
                labelEl.textContent = label;

                const valueEl = document.createElement('span');
                valueEl.textContent = value;

                item.appendChild(labelEl);
                item.appendChild(valueEl);
                gridEl.appendChild(item);
            });

            document.getElementById('sslResult').style.display = 'block';
        }

        function resetChecker() {
            document.getElementById('checkDomain').value = '';
            document.getElementById('sslResult').style.display = 'none';
            document.getElementById('sslError').style.display = 'none';
            document.getElementById('sslLoading').style.display = 'none';
        }

        // Quick-check button from table row
        function checkDomainFromTable(domain) {
            document.getElementById('checkDomain').value = domain;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(checkSSL, 400);
        }
        // ══════════════════════════════════════════
        //  Save Renewal Status via AJAX
        // ══════════════════════════════════════════
        function saveRenewalStatus(domain, status, radioEl) {
            // Show toast
            let toast = document.getElementById('renewalToast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'renewalToast';
                toast.className = 'renewal-toast';
                document.body.appendChild(toast);
            }
            toast.textContent = '💾 Saving...';
            toast.classList.add('show');

            const formData = new FormData();
            formData.append('domain', domain);
            formData.append('status', status);

            fetch('save_ssl_status.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const labels = { renewed: '🟢 Renewed', not_renewed: '🔴 Not Renewed', renewed_with_others: '🔄 Renewed with Others' };
                        toast.textContent = '✅ ' + domain + ' → ' + (labels[status] || status);
                    } else {
                        toast.textContent = '❌ Error: ' + (data.error || 'Unknown');
                    }
                    setTimeout(() => toast.classList.remove('show'), 2500);
                })
                .catch(() => {
                    toast.textContent = '❌ Could not save. Check server connection.';
                    setTimeout(() => toast.classList.remove('show'), 3000);
                });
        }

        // ══════════════════════════════════════════
        //  DOMContentLoaded — Init Everything
        // ══════════════════════════════════════════
        document.addEventListener('DOMContentLoaded', function () {

            // Animated counters
            animateCounters();

            // Chart
            initChart();

            // Restore cleared cells (now marked as blue)
            const saved = JSON.parse(localStorage.getItem('clearedCells') || '{}');
            document.querySelectorAll('td[onclick^="toggleCell"]').forEach(function (td) {
                const uid = td.closest('tr').dataset.uid;
                const match = td.getAttribute('onclick').match(/'(\d+)'/);
                if (match && saved[uid + '_' + match[1]]) {
                    td.classList.remove('cell-green', 'cell-orange', 'cell-red');
                    td.classList.add('cell-blue');
                    td.dataset.cleared = 'true';
                }
            });

            // Renewal status radios are pre-selected by PHP from DB — no auto-check needed.
        });

        // ══════════════════════════════════════════
        //  Single-click to toggle cell colour
        // ══════════════════════════════════════════
        function toggleCell(td, colDay, uid) {
            const saved = JSON.parse(localStorage.getItem('clearedCells') || '{}');
            const key = uid + '_' + colDay;
            td.style.transition = 'background-color 0.4s';

            if (td.dataset.cleared === 'true') {
                // Restore original color
                td.classList.remove('cell-blue');
                if (td.dataset.orig && td.dataset.orig.trim() !== '') {
                    td.classList.add(td.dataset.orig);
                }
                td.dataset.cleared = 'false';
                delete saved[key];
            } else {
                // Apply new color
                td.classList.remove('cell-green', 'cell-orange', 'cell-red');
                td.classList.add('cell-blue');
                td.dataset.cleared = 'true';
                saved[key] = true;
            }
            localStorage.setItem('clearedCells', JSON.stringify(saved));
        }

        // ══════════════════════════════════════════
        //  Download Excel
        // ══════════════════════════════════════════
        function downloadExcel() {
            const table = document.getElementById('sslTable');
            const rows = table.querySelectorAll('tr');

            // Create a 2D array to hold our data
            const data = [];

            // Process headers
            const headers = [];
            const headerCells = rows[0].querySelectorAll('th');
            headerCells.forEach(th => headers.push(th.textContent.trim()));
            data.push(headers);

            // Process rows
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (row.style.display === 'none') continue; // Skip filtered rows if any

                const rowData = [];
                const cells = row.querySelectorAll('td');

                cells.forEach((cell, index) => {
                    // If it's the last column (Renewal Status)
                    if (index === cells.length - 1) {
                        const checkedRadio = cell.querySelector('input[type="radio"]:checked');
                        if (checkedRadio) {
                            const labelMap = {
                                'renewed': 'Renewed',
                                'not_renewed': 'Not Renewed',
                                'renewed_with_others': 'Renewed with Others'
                            };
                            rowData.push(labelMap[checkedRadio.value] || checkedRadio.value);
                        } else {
                            rowData.push('');
                        }
                    } else {
                        // Regular cell, just get the text
                        rowData.push(cell.textContent.trim());
                    }
                });

                data.push(rowData);
            }

            // Create workbook and worksheet
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(data);

            XLSX.utils.book_append_sheet(wb, ws, "SSL Dashboard");

            // Download the file
            const today = new Date().toISOString().split('T')[0];
            XLSX.writeFile(wb, `SSL_Dashboard_Export_${today}.xlsx`);
        }

        // ══════════════════════════════════════════
        //  Toggle/Minimize Columns
        // ══════════════════════════════════════════
        function toggleDayCols(btn) {
            const isHidden = btn.textContent === '➕';
            const cols = document.querySelectorAll('.col-hideable');

            if (isHidden) {
                cols.forEach(el => el.style.display = '');
                btn.textContent = '➖';
                btn.title = 'Minimize columns';
            } else {
                cols.forEach(el => el.style.display = 'none');
                btn.textContent = '➕';
                btn.title = 'Expand columns';
            }
        }


    </script>

    <!-- ══════════════════════════════════════════════
     WORK EXCEL MODAL
══════════════════════════════════════════════ -->
    <div class="modal-overlay" id="workExcelOverlay" onclick="closeWorkExcelModal(event)">
        <div class="work-modal" onclick="event.stopPropagation()">
            <div class="work-modal-header">
                <h3>🔬 Agent Excel — SSL Scanner</h3>
                <button class="close-btn" onclick="closeWorkExcelModal()" title="Close">✕</button>
            </div>
            <div class="work-modal-body">
                <p style="font-size:0.88rem;color:var(--text-secondary);margin-bottom:16px;">Upload an Excel file with a
                    <strong>Domain</strong> column. The scanner will check each domain's SSL certificate and generate an
                    enriched result file.</p>

                <!-- Upload Area -->
                <div id="workUploadArea">
                    <div class="work-drop-zone" id="workDropZone"
                        onclick="document.getElementById('workFileInput').click();">
                        <span class="drop-icon">📂</span>
                        <div class="drop-text">Drag & drop your Excel file here</div>
                        <div class="drop-hint">or <span class="browse-link">browse files</span> · .xlsx, .xls, .csv
                        </div>
                        <input type="file" id="workFileInput" accept=".csv,.xlsx,.xls" style="display:none;">
                    </div>

                    <div class="work-file-info" id="workFileInfo">
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size:1.4rem;">📋</span>
                            <div>
                                <div class="file-name" id="workFileName"></div>
                                <div class="file-meta" id="workFileMeta"></div>
                            </div>
                            <button type="button" onclick="clearWorkFile()"
                                style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:1.1rem;color:var(--text-muted);"
                                title="Remove file">✕</button>
                        </div>
                    </div>

                    <div class="work-modal-actions" id="workActions">
                        <button class="btn-start" id="workStartBtn" onclick="startWorkExcel()" disabled>🚀 Start
                            Scanning</button>
                        <button class="btn-cancel" onclick="closeWorkExcelModal()">Cancel</button>
                    </div>
                </div>

                <!-- Progress -->
                <div class="work-progress" id="workProgress">
                    <div style="font-weight:600;margin-bottom:10px;">⏳ Scanning SSL certificates...</div>
                    <div class="work-progress-bar-container">
                        <div class="work-progress-bar" id="workProgressBar"></div>
                    </div>
                    <div class="work-progress-text">
                        <span id="workProgressCount">0 / 0</span>
                        <span id="workProgressPercent">0%</span>
                    </div>
                    <div class="work-progress-domain" id="workProgressDomain">Preparing...</div>
                </div>

                <!-- Result -->
                <div class="work-result" id="workResult">
                    <div class="result-icon">✅</div>
                    <div class="result-text">Scanning Complete!</div>
                    <div class="result-stats" id="workResultStats"></div>
                    <a class="btn-download" id="workDownloadBtn" href="#" download>📥 Download Result Excel</a>
                    <div style="margin-top:14px;">
                        <button class="btn-cancel" onclick="resetWorkExcel()"
                            style="border:none;color:#667eea;font-weight:600;">↻ Scan Another File</button>
                    </div>
                </div>

                <!-- Error -->
                <div class="work-error" id="workError"></div>
            </div>
        </div>
    </div>

    <script>
        // ══════════════════════════════════════════
        //  Work Excel — Modal & Scanner Logic
        // ══════════════════════════════════════════
        let workExcelFile = null;

        function openWorkExcelModal() {
            document.getElementById('workExcelOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeWorkExcelModal(e) {
            if (e && e.target !== e.currentTarget) return;
            document.getElementById('workExcelOverlay').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Drag & Drop
        (function () {
            const dz = document.getElementById('workDropZone');
            const fi = document.getElementById('workFileInput');
            if (!dz || !fi) return;

            ['dragenter', 'dragover'].forEach(evt => {
                dz.addEventListener(evt, e => { e.preventDefault(); dz.classList.add('dragover'); });
            });
            ['dragleave', 'drop'].forEach(evt => {
                dz.addEventListener(evt, e => { e.preventDefault(); dz.classList.remove('dragover'); });
            });
            dz.addEventListener('drop', e => {
                if (e.dataTransfer.files.length) {
                    fi.files = e.dataTransfer.files;
                    setWorkFile(e.dataTransfer.files[0]);
                }
            });
            fi.addEventListener('change', function () {
                if (this.files.length) setWorkFile(this.files[0]);
            });
        })();

        function setWorkFile(file) {
            workExcelFile = file;
            document.getElementById('workFileName').textContent = file.name;
            document.getElementById('workFileMeta').textContent = (file.size / 1024).toFixed(1) + ' KB';
            document.getElementById('workFileInfo').style.display = 'block';
            document.getElementById('workDropZone').style.display = 'none';
            document.getElementById('workStartBtn').disabled = false;
            document.getElementById('workError').style.display = 'none';
        }

        function clearWorkFile() {
            workExcelFile = null;
            document.getElementById('workFileInput').value = '';
            document.getElementById('workFileInfo').style.display = 'none';
            document.getElementById('workDropZone').style.display = '';
            document.getElementById('workStartBtn').disabled = true;
        }

        function resetWorkExcel() {
            clearWorkFile();
            document.getElementById('workUploadArea').style.display = '';
            document.getElementById('workProgress').style.display = 'none';
            document.getElementById('workResult').style.display = 'none';
            document.getElementById('workError').style.display = 'none';
            document.getElementById('workActions').style.display = 'flex';
        }

        async function startWorkExcel() {
            if (!workExcelFile) return;

            // Hide upload area, show progress
            document.getElementById('workUploadArea').style.display = 'none';
            document.getElementById('workProgress').style.display = 'block';
            document.getElementById('workResult').style.display = 'none';
            document.getElementById('workError').style.display = 'none';

            const formData = new FormData();
            formData.append('excel', workExcelFile);

            try {
                const response = await fetch('work_excel.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // keep incomplete line

                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const data = JSON.parse(line);
                            handleWorkProgress(data);
                        } catch (e) {
                            // skip non-JSON lines
                        }
                    }
                }

                // Process remaining buffer
                if (buffer.trim()) {
                    try {
                        const data = JSON.parse(buffer);
                        handleWorkProgress(data);
                    } catch (e) { /* skip */ }
                }

            } catch (err) {
                document.getElementById('workProgress').style.display = 'none';
                document.getElementById('workUploadArea').style.display = '';
                document.getElementById('workActions').style.display = 'flex';
                const errDiv = document.getElementById('workError');
                errDiv.textContent = '❌ ' + err.message;
                errDiv.style.display = 'block';
            }
        }

        function handleWorkProgress(data) {
            switch (data.type) {
                case 'start':
                    document.getElementById('workProgressCount').textContent = '0 / ' + data.total;
                    document.getElementById('workProgressPercent').textContent = '0%';
                    document.getElementById('workProgressDomain').textContent = 'Starting...';
                    break;

                case 'progress':
                    const pct = Math.round((data.current / data.total) * 100);
                    document.getElementById('workProgressBar').style.width = pct + '%';
                    document.getElementById('workProgressCount').textContent = data.current + ' / ' + data.total;
                    document.getElementById('workProgressPercent').textContent = pct + '%';
                    document.getElementById('workProgressDomain').textContent = '🔍 Scanning: ' + data.domain;
                    break;

                case 'complete':
                    document.getElementById('workProgress').style.display = 'none';
                    document.getElementById('workResult').style.display = 'block';
                    document.getElementById('workResultStats').textContent =
                        data.total + ' domains scanned · ' + data.success + ' successful · ' + data.failed + ' failed';
                    document.getElementById('workDownloadBtn').href = data.download_url;
                    document.getElementById('workDownloadBtn').download = data.filename || 'SSL_Result.xlsx';
                    break;

                case 'error':
                    document.getElementById('workProgress').style.display = 'none';
                    document.getElementById('workUploadArea').style.display = '';
                    document.getElementById('workActions').style.display = 'flex';
                    const errDiv = document.getElementById('workError');
                    errDiv.textContent = '❌ ' + data.message;
                    errDiv.style.display = 'block';
                    break;
            }
        }
    </script>

</body>

</html>