<?php
session_start();
include_once 'config/database.php'; // Include the database configuration

$database = new Database();
$db = $database->getConnection();

// Log the logout activity
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user_email = $_SESSION['email'] ?? 'N/A'; // Fallback if email not set
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    $database->logActivity($user_id, 'User Logout', 'User ' . $user_email . ' logged out.', $ip_address);
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to the login page
header("Location: login.php");
exit();
?>
