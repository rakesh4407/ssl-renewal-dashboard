<?php
require 'config.php';

if (isset($_SESSION['user'])) {
    header("Location: 1newdashboard.php");
} else {
    header("Location: login.php");
}
exit;
?>