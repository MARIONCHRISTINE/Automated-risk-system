<?php
/**
 * Setup script to create necessary directories and files
 * Run this once to set up the system structure
 */

echo "<h2>Airtel Risk Management System - Setup</h2>";
echo "<p>Setting up directory structure and files...</p>";

// Create directories if they don't exist
$directories = [
    'includes',
    'uploads',
    'uploads/documents',
    'config',
    'database'
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "<p>✅ Created directory: $dir</p>";
        } else {
            echo "<p>❌ Failed to create directory: $dir</p>";
        }
    } else {
        echo "<p>✅ Directory already exists: $dir</p>";
    }
}

// Check if compatibility file exists
if (!file_exists('includes/php_compatibility.php')) {
    echo "<p>❌ PHP compatibility file missing. Please create includes/php_compatibility.php</p>";
} else {
    echo "<p>✅ PHP compatibility file exists</p>";
}

// Check database connection
try {
    include_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    if ($db) {
        echo "<p>✅ Database connection successful</p>";
    }
} catch (Exception $e) {
    echo "<p>❌ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<h3>System Information:</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Directory: " . __DIR__ . "</p>";
echo "<p>System Path: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";

echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li>Make sure all directories are created</li>";
echo "<li>Run the database setup SQL script</li>";
echo "<li>Start the Python Flask service</li>";
echo "<li>Test the registration and login</li>";
echo "</ol>";

echo "<p><a href='login.php'>Go to Login Page</a> | <a href='register.php'>Go to Registration</a></p>";
?>
