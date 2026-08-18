<?php
/**
 * work_excel.php — Pure PHP SSL bulk scanner with PARALLEL checking
 *
 * Uses curl_multi to check up to 10 domains simultaneously, making
 * large files (300–1000+ rows) feasible even on shared hosting.
 *
 * Performance comparison (300 domains):
 *   Old sequential:  ~50 minutes (10s timeout × 300 = 3000s)
 *   New parallel:    ~2 minutes  (10 batches of 30, ~4s each)
 *
 * Streams JSON progress lines to the browser so the existing frontend
 * JS in 1newdashboard.php keeps working unchanged.
 */

session_start();
require 'config.php';

// ── Auth check ──
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'message' => 'Not logged in']);
    exit;
}

require __DIR__ . '/lib/SimpleXLSX.php';
require __DIR__ . '/lib/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

// ══════════════════════════════════════════════
//  GET — Download a result file
// ══════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = __DIR__ . '/scan_results/' . $filename;

    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['type' => 'error', 'message' => 'File not found']);
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache');
    readfile($filepath);
    exit;
}

// ══════════════════════════════════════════════
//  Configuration — tune these for your host
// ══════════════════════════════════════════════
define('BATCH_SIZE', 10);       // domains checked in parallel per batch
define('CONNECT_TIMEOUT', 4);   // seconds to wait for TCP+TLS handshake
define('TOTAL_TIMEOUT', 5);     // seconds total per-domain timeout

// ══════════════════════════════════════════════
//  Stream setup — flush output immediately
// ══════════════════════════════════════════════
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(1);
set_time_limit(0);

header('Content-Type: application/x-ndjson');
header('X-Accel-Buffering: no');

function emit($data) {
    echo json_encode($data) . "\n";
    flush();
}

// ══════════════════════════════════════════════
//  SSL helper functions
// ══════════════════════════════════════════════

/**
 * Clean a raw domain string (strip protocol, path, port).
 */
function cleanDomain($hostname) {
    if (empty($hostname)) return '';
    $hostname = trim($hostname);
    $hostname = preg_replace('#^https?://#', '', $hostname);
    $hostname = preg_replace('#/.*$#', '', $hostname);
    $hostname = preg_replace('#:\d+$#', '', $hostname);
    return $hostname;
}

/**
 * Check SSL certificates for a batch of domains IN PARALLEL using curl_multi.
 *
 * @param array $domains  Indexed array of domain strings
 * @return array  Indexed array of parsed cert arrays (or null on failure)
 */
function checkSSLBatch(array $domains) {
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($domains as $idx => $rawDomain) {
        $clean = cleanDomain($rawDomain);

        if ($clean === '') {
            $results[$idx] = null;
            continue;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://{$clean}:443",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_NOBODY         => true,           // HEAD request — no body download
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CERTINFO       => true,            // capture full cert chain
            CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => TOTAL_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[$idx] = $ch;
    }

    // Execute all handles concurrently
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh, 0.05) !== -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    // Collect results
    foreach ($handles as $idx => $ch) {
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if (!empty($certInfo) && isset($certInfo[0])) {
            $pem = $certInfo[0]['Cert'] ?? '';
            if ($pem) {
                $cert = openssl_x509_parse($pem);
                $results[$idx] = $cert ?: null;
            } else {
                $results[$idx] = null;
            }
        } else {
            $results[$idx] = null;
        }
    }

    curl_multi_close($mh);
    return $results;
}

/**
 * Fallback: single-domain check using stream_socket_client.
 * Used only if curl_multi is not available on the host.
 */
function getSSLInfoFallback($hostname) {
    $hostname = cleanDomain($hostname);
    if ($hostname === '') return null;

    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client(
        "ssl://{$hostname}:443",
        $errno,
        $errstr,
        CONNECT_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$client) return null;

    $params = stream_context_get_params($client);
    fclose($client);

    if (!isset($params['options']['ssl']['peer_certificate'])) return null;

    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    return $cert ?: null;
}

function extractOrgName($cert) {
    return $cert['issuer']['O'] ?? '';
}

function extractCommonName($cert) {
    $cn = $cert['issuer']['CN'] ?? '';
    if ($cn === '') return '';

    $knownCAs = ['Sectigo', 'DigiCert', 'GeoTrust', 'Go Daddy', 'GlobalSign', "Let's Encrypt",
                 'COMODO', 'cPanel', 'R3', 'Cloudflare', 'Amazon', 'Google', 'Microsoft'];
    foreach ($knownCAs as $ca) {
        if (stripos($cn, $ca) !== false) return $ca;
    }
    return $cn;
}

function extractWildcardCN($cert) {
    $cn = $cert['subject']['CN'] ?? '';
    return (strpos($cn, '*.') === 0) ? $cn : '';
}

function extractCountry($cert) {
    return $cert['subject']['C'] ?? '';
}

function findDomainColumn($headerRow) {
    foreach ($headerRow as $idx => $col) {
        if (stripos(trim($col), 'domain') !== false) return $idx;
    }
    return null;
}

/**
 * Build a result row from a parsed certificate (or null for failure).
 */
function buildResultRow($domain, $cert, $today) {
    if ($cert) {
        $notBefore = $cert['validFrom_time_t'] ?? null;
        $notAfter  = $cert['validTo_time_t'] ?? null;

        $validityDays = '';
        $validityYears = '';
        $daysRemaining = null;

        if ($notBefore && $notAfter) {
            $validityDays  = round(($notAfter - $notBefore) / 86400);
            $validityYears = $validityDays < 380 ? '1 year' : '2 years';
            $daysRemaining = round(($notAfter - time()) / 86400);
        }

        return [
            'Domain'               => $domain,
            'SSL_Not_Before'       => $notBefore ? date('d/m/Y', $notBefore) : '',
            'SSL_Not_After'        => $notAfter  ? date('d/m/Y', $notAfter)  : '',
            'Organization_Name'    => extractOrgName($cert),
            'Common_Name'          => extractCommonName($cert),
            'Validity_days'        => $validityDays,
            'Validity_years'       => $validityYears,
            'Today_date'           => $today,
            'Wild_card_Common_Name'=> extractWildcardCN($cert),
            'days_remaining'       => $daysRemaining,
            'Country'              => extractCountry($cert),
            'Email'                => '',
            'Phone Number'         => '',
        ];
    }

    return [
        'Domain'               => $domain,
        'SSL_Not_Before'       => '',
        'SSL_Not_After'        => '',
        'Organization_Name'    => '',
        'Common_Name'          => '',
        'Validity_days'        => '',
        'Validity_years'       => '',
        'Today_date'           => $today,
        'Wild_card_Common_Name'=> '',
        'days_remaining'       => null,
        'Country'              => '',
        'Email'                => '',
        'Phone Number'         => '',
    ];
}

// ══════════════════════════════════════════════
//  Validate upload
// ══════════════════════════════════════════════
if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
    emit(['type' => 'error', 'message' => 'No file uploaded or upload error']);
    exit;
}

$tmpPath  = $_FILES['excel']['tmp_name'];
$origName = $_FILES['excel']['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$rows = [];

if ($ext === 'csv') {
    $handle = fopen($tmpPath, 'r');
    if ($handle) {
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }
} elseif (in_array($ext, ['xlsx', 'xls'])) {
    if ($xlsx = SimpleXLSX::parse($tmpPath)) {
        $rows = $xlsx->rows();
    } else {
        emit(['type' => 'error', 'message' => 'Could not read Excel file: ' . SimpleXLSX::parseError()]);
        exit;
    }
} else {
    emit(['type' => 'error', 'message' => 'Unsupported file type. Use .csv, .xlsx, or .xls']);
    exit;
}

if (count($rows) < 2) {
    emit(['type' => 'error', 'message' => 'File appears to be empty']);
    exit;
}

$headerRow    = array_shift($rows);
$domainColIdx = findDomainColumn($headerRow);

if ($domainColIdx === null) {
    emit(['type' => 'error', 'message' => 'No "Domain" column found. Columns: ' . implode(', ', $headerRow)]);
    exit;
}

$total = count($rows);
$today = date('d/m/Y');

// Detect whether curl_multi is available
$useCurlMulti = function_exists('curl_multi_init');

emit([
    'type'   => 'start',
    'total'  => $total,
    'method' => $useCurlMulti ? 'parallel' : 'sequential'
]);

$results      = [];
$successCount = 0;
$failedCount  = 0;
$processed    = 0;

// ══════════════════════════════════════════════
//  Main scan loop — batched parallel
// ══════════════════════════════════════════════
$batches = array_chunk($rows, BATCH_SIZE, true);

foreach ($batches as $batch) {
    // Extract domains for this batch
    $batchDomains = [];
    $batchIndices = [];
    foreach ($batch as $rowIdx => $row) {
        $domain = trim($row[$domainColIdx] ?? '');
        $batchDomains[$rowIdx] = $domain;
        $batchIndices[] = $rowIdx;
    }

    // Emit progress for the batch (show first domain in batch)
    $firstDomain = reset($batchDomains);
    $batchStart  = $processed + 1;
    $batchEnd    = min($processed + count($batchDomains), $total);

    emit([
        'type'    => 'progress',
        'current' => $batchEnd,
        'total'   => $total,
        'domain'  => ($firstDomain !== '' ? $firstDomain : '(empty)') .
                     (count($batchDomains) > 1 ? ' (+' . (count($batchDomains) - 1) . ' more)' : '')
    ]);

    // Check SSL for the whole batch at once
    if ($useCurlMulti) {
        $certs = checkSSLBatch($batchDomains);
    } else {
        // Fallback: sequential (slower, but works everywhere)
        $certs = [];
        foreach ($batchDomains as $idx => $domain) {
            $certs[$idx] = getSSLInfoFallback($domain);
        }
    }

    // Process results
    foreach ($batchDomains as $rowIdx => $domain) {
        $cert = $certs[$rowIdx] ?? null;
        $results[] = buildResultRow($domain, $cert, $today);

        if ($cert) {
            $successCount++;
        } else {
            $failedCount++;
        }
    }

    $processed += count($batchDomains);
}

// ══════════════════════════════════════════════
//  Write output Excel file
// ══════════════════════════════════════════════
$outputDir = __DIR__ . '/scan_results';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$filename   = 'SSL_Scan_Result_' . date('Y-m-d_His') . '.xlsx';
$outputPath = $outputDir . '/' . $filename;

if (!empty($results)) {
    $header    = array_keys($results[0]);
    $sheetData = [$header];
    foreach ($results as $r) {
        $sheetData[] = array_values($r);
    }
    SimpleXLSXGen::fromArray($sheetData)->saveAs($outputPath);
}

emit([
    'type'         => 'complete',
    'download_url' => 'work_excel.php?download=' . urlencode($filename),
    'filename'     => $filename,
    'total'        => $total,
    'success'      => $successCount,
    'failed'       => $failedCount
]);
