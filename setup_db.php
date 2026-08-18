<?php
/**
 * setup_db.php — Run once to create the ssl_renewal_status table.
 * Visit http://localhost:8000/setup_db.php in your browser.
 */
require 'config.php';

if (!isset($conn) || $conn->connect_error) {
    die("❌ Database connection failed. Check config.php credentials.");
}

$sql1 = "CREATE TABLE IF NOT EXISTS ssl_renewal_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(50) DEFAULT NULL,
    renewal_status VARCHAR(50) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$sql2 = "CREATE TABLE IF NOT EXISTS date_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    order_no VARCHAR(255) DEFAULT NULL,
    actual_date VARCHAR(255) DEFAULT NULL,
    actual_expiry_date VARCHAR(255) DEFAULT NULL,
    reissue_rem VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(255) DEFAULT NULL,
    term VARCHAR(255) DEFAULT NULL,
    issue_date VARCHAR(255) DEFAULT NULL,
    expiry_date VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($sql1);
$conn->query($sql2);

// Check if status column needs to be added to existing table
$colCheck = $conn->query("SHOW COLUMNS FROM ssl_renewal_status LIKE 'status'");
if ($colCheck && $colCheck->num_rows == 0) {
    @$conn->query("ALTER TABLE ssl_renewal_status ADD COLUMN status VARCHAR(50) DEFAULT NULL AFTER domain");
}
    echo "<!DOCTYPE html><html><head><title>DB Setup</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); text-align: center; max-width: 500px; }
        .card h2 { color: #2dce89; margin-bottom: 10px; }
        .card p { color: #64748b; font-size: 0.95rem; }
        .card a { display: inline-block; margin-top: 16px; padding: 10px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; }
    </style></head><body>
    <div class='card'>
        <h2>✅ Tables Created Successfully!</h2>
        <p>The <code>ssl_renewal_status</code> and <code>date_overrides</code> tables are ready.</p>
        <a href='1newdashboard.php'>← Go to Dashboard</a>
    </div></body></html>";
?>
