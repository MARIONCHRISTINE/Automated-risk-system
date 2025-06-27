<?php
session_start();
include_once 'config/database.php';

echo "<h2>🔍 Database Debug Information</h2>";
echo "<style>body { font-family: Arial, sans-serif; margin: 2rem; } .info { background: #e3f2fd; padding: 1rem; border-radius: 5px; margin: 1rem 0; } .error { background: #ffebee; padding: 1rem; border-radius: 5px; margin: 1rem 0; } .success { background: #e8f5e8; padding: 1rem; border-radius: 5px; margin: 1rem 0; }</style>";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<div class='success'>✅ Database connection successful!</div>";
    
    // Check if users table exists and has data
    echo "<h3>👥 Users Table Check:</h3>";
    $users_query = "SELECT id, email, full_name, role, status FROM users ORDER BY id";
    $users_stmt = $db->prepare($users_query);
    $users_stmt->execute();
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($users) > 0) {
        echo "<div class='success'>Found " . count($users) . " users in database:</div>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
        echo "<tr style='background: #f5f5f5;'><th>ID</th><th>Email</th><th>Name</th><th>Role</th><th>Status</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . $user['id'] . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td>" . htmlspecialchars($user['full_name']) . "</td>";
            echo "<td>" . $user['role'] . "</td>";
            echo "<td>" . $user['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>❌ No users found in database!</div>";
        echo "<p><strong>Solution:</strong> You need to create user accounts first.</p>";
        echo "<p><a href='dev_setup.php' style='background: #E60012; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px;'>Create Admin Account</a></p>";
    }
    
    // Check current session
    echo "<h3>🔐 Current Session:</h3>";
    if (isset($_SESSION['user_id'])) {
        echo "<div class='info'>";
        echo "<strong>User ID:</strong> " . $_SESSION['user_id'] . "<br>";
        echo "<strong>Email:</strong> " . ($_SESSION['email'] ?? 'Not set') . "<br>";
        echo "<strong>Name:</strong> " . ($_SESSION['full_name'] ?? 'Not set') . "<br>";
        echo "<strong>Role:</strong> " . ($_SESSION['role'] ?? 'Not set');
        echo "</div>";
        
        // Verify this user exists in database
        $current_user_query = "SELECT * FROM users WHERE id = :user_id";
        $current_user_stmt = $db->prepare($current_user_query);
        $current_user_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $current_user_stmt->execute();
        
        if ($current_user_stmt->rowCount() > 0) {
            echo "<div class='success'>✅ Current session user exists in database</div>";
        } else {
            echo "<div class='error'>❌ Current session user NOT found in database!</div>";
            echo "<p><strong>Solution:</strong> Please log out and log in again, or create a new account.</p>";
        }
    } else {
        echo "<div class='error'>❌ No active session found</div>";
        echo "<p><a href='login.php' style='background: #E60012; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px;'>Go to Login</a></p>";
    }
    
    // Check risk_incidents table
    echo "<h3>📊 Risk Incidents Table:</h3>";
    $risks_query = "SELECT COUNT(*) as count FROM risk_incidents";
    $risks_stmt = $db->prepare($risks_query);
    $risks_stmt->execute();
    $risk_count = $risks_stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div class='info'>Total risks in database: " . $risk_count['count'] . "</div>";
    
    // Check table structure
    echo "<h3>🏗️ Database Table Structure:</h3>";
    $structure_query = "DESCRIBE risk_incidents";
    $structure_stmt = $db->prepare($structure_query);
    $structure_stmt->execute();
    $columns = $structure_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
    echo "<tr style='background: #f5f5f5;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Database Error: " . $e->getMessage() . "</div>";
}

echo "<hr>";
echo "<h3>🛠️ Quick Actions:</h3>";
echo "<a href='dev_setup.php' style='background: #E60012; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px; margin: 0.5rem;'>Create Admin Account</a>";
echo "<a href='login.php' style='background: #6c757d; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px; margin: 0.5rem;'>Go to Login</a>";
echo "<a href='staff_dashboard.php' style='background: #28a745; color: white; padding: 0.5rem 1rem; text-decoration: none; border-radius: 4px; margin: 0.5rem;'>Back to Dashboard</a>";
?>
