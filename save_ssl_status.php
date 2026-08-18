<?php
session_start();
require 'config.php';

// Auth check — reject unauthenticated requests
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// Agents are read-only
if ($_SESSION['user'] === 'agentssllock') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Read-only access']);
    exit;
}

header('Content-Type: application/json');

$domain = trim($_POST['domain'] ?? '');
$status = trim($_POST['status'] ?? '');

$allowed = ['renewed', 'not_renewed', 'renewed_with_others'];
if ($domain === '' || !in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

if (!$db_connected) {
    echo json_encode(['success' => false, 'error' => 'Database not connected']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO ssl_renewal_status (domain, status) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE status = VALUES(status)"
);
$stmt->bind_param("ss", $domain, $status);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
$stmt->close();
?>
