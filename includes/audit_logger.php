<?php
class AuditLogger {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function logAction($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $action,
                $tableName,
                $recordId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function logLogin($userId, $email, $status, $failureReason = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_history (user_id, email, ip_address, user_agent, login_status, failure_reason, location_info) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $locationInfo = $this->getLocationInfo($this->getClientIP());
            
            $stmt->execute([
                $userId,
                $email,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $status,
                $failureReason,
                json_encode($locationInfo)
            ]);
            
            // Check for suspicious login patterns
            $this->checkSuspiciousLogin($userId, $email);
            
            return true;
        } catch (Exception $e) {
            error_log("Login logging failed: " . $e->getMessage());
            return false;
        }
    }
    
    private function checkSuspiciousLogin($userId, $email) {
        $suspiciousDetector = new SuspiciousActivityDetector($this->db);
        $suspiciousDetector->checkLoginPatterns($userId, $email);
    }
    
    private function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    private function getLocationInfo($ip) {
        // Simple location detection - in production, use a proper IP geolocation service
        if ($ip === '127.0.0.1' || $ip === 'unknown') {
            return ['country' => 'Local', 'city' => 'Local', 'region' => 'Local'];
        }
        
        // Placeholder - integrate with IP geolocation API
        return ['country' => 'Unknown', 'city' => 'Unknown', 'region' => 'Unknown'];
    }
    
    public function getAuditLogs($limit = 100, $offset = 0, $userId = null) {
        try {
            $whereClause = $userId ? "WHERE al.user_id = ?" : "";
            $params = $userId ? [$userId, $limit, $offset] : [$limit, $offset];
            
            $stmt = $this->db->prepare("
                SELECT al.*, u.full_name, u.email, u.role 
                FROM audit_logs al 
                LEFT JOIN users u ON al.user_id = u.id 
                {$whereClause}
                ORDER BY al.created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to fetch audit logs: " . $e->getMessage());
            return [];
        }
    }
    
    public function getLoginHistory($limit = 100, $offset = 0, $userId = null) {
        try {
            $whereClause = $userId ? "WHERE lh.user_id = ?" : "";
            $params = $userId ? [$userId, $limit, $offset] : [$limit, $offset];
            
            $stmt = $this->db->prepare("
                SELECT lh.*, u.full_name, u.role 
                FROM login_history lh 
                LEFT JOIN users u ON lh.user_id = u.id 
                {$whereClause}
                ORDER BY lh.created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to fetch login history: " . $e->getMessage());
            return [];
        }
    }
}
?>
