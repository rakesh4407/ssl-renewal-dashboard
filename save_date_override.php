<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SESSION['user'] === 'agentssllock') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Read-only access']);
    exit;
}

header('Content-Type: application/json');

$domain = trim($_POST['domain'] ?? '');
$type = trim($_POST['type'] ?? '');
$value = trim($_POST['date_value'] ?? '');

// Map the JS "type" values to real column names
$columnMap = [
    'orderNo'           => 'order_no',
    'actualDate'        => 'actual_date',
    'actualExpiryDate'  => 'actual_expiry_date',
    'reissueRem'        => 'reissue_rem',
    'email'             => 'email',
    'phone'             => 'phone',
    'term'              => 'term',
    'issue'             => 'issue_date',
    'expiry'            => 'expiry_date',
];

if ($domain === '' || !isset($columnMap[$type])) {
    echo json_encode(['success' => false, 'error' => 'Invalid field type']);
    exit;
}

if (!$db_connected) {
    echo json_encode(['success' => false, 'error' => 'Database not connected']);
    exit;
}

$column = $columnMap[$type];

// Make sure the row exists first
$stmt = $conn->prepare("INSERT IGNORE INTO date_overrides (domain) VALUES (?)");
$stmt->bind_param("s", $domain);
$stmt->execute();
$stmt->close();

// Column name can't be parameterized, but it's safely whitelisted via $columnMap above
$stmt = $conn->prepare("UPDATE date_overrides SET `$column` = ? WHERE domain = ?");
$stmt->bind_param("ss", $value, $domain);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
$stmt->close();
?>
