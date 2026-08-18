<?php
/**
 * SSL Certificate Check Proxy
 * 
 * Accepts a domain via GET parameter and returns SSL certificate
 * details as JSON. Used by the dashboard for live SSL status checks.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$domain = trim($_GET['domain'] ?? '');

// Validate domain
if (empty($domain)) {
    echo json_encode(['error' => 'No domain provided']);
    exit;
}

// Basic domain validation — strip protocol and path if present
$domain = preg_replace('#^https?://#', '', $domain);
$domain = preg_replace('#/.*$#', '', $domain);
$domain = trim($domain);

// Only allow valid domain characters
if (!preg_match('/^[a-zA-Z0-9*._-]+\.[a-zA-Z]{2,}$/', $domain) && !filter_var($domain, FILTER_VALIDATE_IP)) {
    echo json_encode(['error' => 'Invalid domain format']);
    exit;
}

// For wildcard domains, check the base domain
$checkDomain = $domain;
if (strpos($checkDomain, '*.') === 0) {
    $checkDomain = substr($checkDomain, 2);
}

// Attempt to get SSL certificate info
$context = stream_context_create([
    'ssl' => [
        'capture_peer_cert' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
]);

$errno = 0;
$errstr = '';

// Try to connect with a short timeout
$client = @stream_socket_client(
    "ssl://{$checkDomain}:443",
    $errno,
    $errstr,
    10, // timeout in seconds
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$client) {
    echo json_encode(['error' => "Could not connect to {$checkDomain}: {$errstr}"]);
    exit;
}

$params = stream_context_get_params($client);
fclose($client);

if (!isset($params['options']['ssl']['peer_certificate'])) {
    echo json_encode(['error' => 'Could not retrieve SSL certificate']);
    exit;
}

$cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

if (!$cert) {
    echo json_encode(['error' => 'Could not parse SSL certificate']);
    exit;
}

// Extract certificate details
$validFrom = date('d-M-Y', $cert['validFrom_time_t']);
$validTo = date('d-M-Y', $cert['validTo_time_t']);
$daysLeft = ceil(($cert['validTo_time_t'] - time()) / 86400);

$issuer = '';
if (isset($cert['issuer'])) {
    $issuerParts = [];
    if (isset($cert['issuer']['O'])) $issuerParts[] = $cert['issuer']['O'];
    if (isset($cert['issuer']['CN'])) $issuerParts[] = $cert['issuer']['CN'];
    $issuer = implode(' - ', $issuerParts);
}

// Subject Alternative Names
$san = '';
if (isset($cert['extensions']['subjectAltName'])) {
    $san = str_replace('DNS:', '', $cert['extensions']['subjectAltName']);
}

echo json_encode([
    'domain' => $domain,
    'issuer' => $issuer ?: 'N/A',
    'valid_from' => $validFrom,
    'valid_to' => $validTo,
    'days_left' => (int)$daysLeft,
    'san' => $san ?: 'N/A'
]);
