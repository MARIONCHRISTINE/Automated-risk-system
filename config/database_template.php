<?php
/**
 * Database Configuration Template
 * Copy this file to database.php and update with your settings
 */

class Database {
    // === DEVELOPMENT CONFIGURATION ===
    
    // Option 1: Local Development (Default XAMPP)
    private $host = "localhost";
    private $db_name = "airtel_risk_system"; // Changed from risk_management_system
    private $username = "root";
    private $password = "";
    
    // Option 2: Team Network Database
    // private $host = "192.168.1.100"; // Replace with host developer's IP
    // private $db_name = "airtel_risk_system";
    // private $username = "airtel_team";
    // private $password = "team_password_2024";
    
    // Option 3: Cloud Database (DigitalOcean example)
    // private $host = "your-cluster.db.ondigitalocean.com";
    // private $db_name = "airtel_risk_system";
    // private $username = "doadmin";
    // private $password = "your-secure-password";
    // private $port = "25060";
    
    // Option 4: Docker MySQL
    // private $host = "localhost";
    // private $db_name = "airtel_risk_system";
    // private $username = "airtel_user";
    // private $password = "airtel_password";
    
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            // Standard connection
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name;
            
            // Uncomment for custom port (cloud databases)
            // $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
            echo "<br><br>Troubleshooting tips:";
            echo "<ul>";
            echo "<li>Check if MySQL is running</li>";
            echo "<li>Verify database name exists: airtel_risk_system</li>";
            echo "<li>Check username/password</li>";
            echo "<li>For network databases, check IP and firewall</li>";
            echo "</ul>";
        }
        return $this->conn;
    }
}

// === TEAM DEVELOPMENT NOTES ===
/*
1. Each developer should copy this file to database.php
2. Update the configuration for your setup
3. Never commit database.php with real passwords to Git
4. Use the dev_setup.php to create admin accounts
5. Test connection with setup.php

RECOMMENDED APPROACH FOR 2 DEVELOPERS:
- Developer 1: Acts as database host (Method 1)
- Developer 2: Connects to Developer 1's database
- Both create admin accounts using dev_setup.php

DATABASE NAME: airtel_risk_system
*/
?>
