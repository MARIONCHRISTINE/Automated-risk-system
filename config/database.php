<?php
class Database {
    private $host = "localhost";
    private $db_name = "airtel_risk_system";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection(){
        $this->conn = null;
        try{
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        }catch(PDOException $exception){
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }

    // Updated function to log activities into system_audit_logs
    // It now automatically determines the user's display name based on user_id
    public function logActivity($user_id, $action, $details, $ip_address = null) {
        // Determine user display name
        $user_display_name = 'System'; // Default for system actions or unknown
        if ($user_id !== null) {
            $user_query = "SELECT full_name, role FROM users WHERE id = :user_id";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':user_id', $user_id);
            $user_stmt->execute();
            $user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user_info) {
                // Ensure consistent casing for roles in the 'user' column
                $role_display = str_replace('_', ' ', $user_info['role']); // Replace underscore with space
                $role_display = ucwords($role_display); // Capitalize first letter of each word
                $user_display_name = htmlspecialchars($user_info['full_name']) . ' (' . $role_display . ')';
            } else {
                $user_display_name = 'Unknown User (ID: ' . $user_id . ')';
            }
        }

        $query = "INSERT INTO system_audit_logs (user_id, user, action, details, ip_address) VALUES (:user_id, :user_display_name, :action, :details, :ip_address)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':user_display_name', $user_display_name); // This binds the formatted name to the 'user' column
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        
        // Get IP address if not provided
        if ($ip_address === null) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
        }
        $stmt->bindParam(':ip_address', $ip_address);

        return $stmt->execute();
    }
}
?>
