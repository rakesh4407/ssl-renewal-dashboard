<?php
/**
 * create_db.php — Creates the ssl_dashboard database if it doesn't exist,
 * then creates the ssl_renewal_status table.
 * Run once: http://localhost:8000/create_db.php
 */

$host = "localhost";
$username = "root";
$password = "";
$dbname = "ssl_dashboard";

// Connect WITHOUT specifying a database first
$conn = new mysqli($host, $username, $password);
if ($conn->connect_error) {
    die("❌ Could not connect to MySQL: " . $conn->connect_error . "<br>Make sure XAMPP MySQL is running.");
}

// Create the database
$conn->query("CREATE DATABASE IF NOT EXISTS `$dbname`");
echo "✅ Database '$dbname' ready.<br>";

// Select it
$conn->select_db($dbname);

// Create the tables
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

if ($conn->query($sql1) === TRUE && $conn->query($sql2) === TRUE) {
    // Ensure status column exists if table was created previously with renewal_status only
    $colCheck = $conn->query("SHOW COLUMNS FROM ssl_renewal_status LIKE 'status'");
    if ($colCheck && $colCheck->num_rows == 0) {
        @$conn->query("ALTER TABLE ssl_renewal_status ADD COLUMN status VARCHAR(50) DEFAULT NULL AFTER domain");
    }
    echo "✅ Tables 'ssl_renewal_status' and 'date_overrides' ready.<br><br>";
    echo "<a href='1newdashboard.php' style='font-size:1.2rem;font-weight:bold;'>→ Go to Dashboard</a>";
} else {
    echo "❌ Error creating tables: " . htmlspecialchars($conn->error);
}

$conn->close();
?>
