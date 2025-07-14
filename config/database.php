<?php
class Database {
    private $host = "localhost"; // Your database host (usually 'localhost' for XAMPP/WAMP)
    private $db_name = "airtel_risk_system"; // <-- CONFIRM THIS EXACTLY MATCHES YOUR DATABASE NAME
    private $username = "root"; // Your database username (often 'root' for XAMPP/WAMP)
    private $password = ""; // Your database password (often empty '' for XAMPP/WAMP root)
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            // For debugging, you can uncomment the line below, but remove it for production
            // die("Database connection failed: " . $exception->getMessage());
            die("Database connection failed. Please check your configuration and ensure the database server is running.");
        }

        return $this->conn;
    }
}
?>
