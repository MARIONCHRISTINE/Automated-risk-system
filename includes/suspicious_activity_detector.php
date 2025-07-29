<?php
class SuspiciousActivityDetector {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function checkLoginPatterns($userId, $email) {
        $this->checkMultipleFailedLogins($userId, $email);
        $this->checkUnusualLoginTimes($userId);
        $this->checkMultipleIPAddresses($userId);
        $this->checkRapidLoginAttempts($email);
    }
    
    private function checkMultipleFailedLogins($userId, $email) {
        // Check for 5+ failed logins in the last hour
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as failed_count 
            FROM login_history 
            WHERE email = ? AND login_status = 'failed' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['failed_count'] >= 5) {
            $this->logSuspiciousActivity(
                $userId,
                'multiple_failed_logins',
                "Multiple failed login attempts ({$result['failed_count']}) in the last hour",
                'high'
            );
        }
    }
    
    private function checkUnusualLoginTimes($userId) {
        if (!$userId) return;
        
        // Check if login is outside normal business hours (9 AM - 6 PM)
        $currentHour = date('H');
        if ($currentHour < 9 || $currentHour > 18) {
            // Check if user normally logs in during business hours
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as business_hours_logins,
                       (SELECT COUNT(*) FROM login_history WHERE user_id = ? AND login_status = 'success') as total_logins
                FROM login_history 
                WHERE user_id = ? AND login_status = 'success' 
                AND HOUR(created_at) BETWEEN 9 AND 18
                AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$userId, $userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total_logins'] > 10 && ($result['business_hours_logins'] / $result['total_logins']) > 0.8) {
                $this->logSuspiciousActivity(
                    $userId,
                    'unusual_login_time',
                    "Login attempt outside normal business hours at " . date('H:i'),
                    'medium'
                );
            }
        }
    }
    
    private function checkMultipleIPAddresses($userId) {
        if (!$userId) return;
        
        // Check for logins from multiple IP addresses in the last 24 hours
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT ip_address) as ip_count 
            FROM login_history 
            WHERE user_id = ? AND login_status = 'success' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['ip_count'] >= 3) {
            $this->logSuspiciousActivity(
                $userId,
                'multiple_ip_addresses',
                "Successful logins from {$result['ip_count']} different IP addresses in 24 hours",
                'high'
            );
        }
    }
    
    private function checkRapidLoginAttempts($email) {
        // Check for rapid login attempts (more than 10 in 5 minutes)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM login_history 
            WHERE email = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['attempt_count'] >= 10) {
            $this->logSuspiciousActivity(
                null,
                'rapid_login_attempts',
                "Rapid login attempts ({$result['attempt_count']}) for email: {$email}",
                'critical'
            );
        }
    }
    
    public function checkDataAccess($userId, $action, $tableName, $recordId) {
        // Check for unusual data access patterns
        if (in_array($action, ['SELECT', 'VIEW'])) {
            $this->checkBulkDataAccess($userId, $tableName);
        }
        
        if (in_array($action, ['UPDATE', 'DELETE'])) {
            $this->checkSensitiveDataModification($userId, $action, $tableName, $recordId);
        }
    }
    
    private function checkBulkDataAccess($userId, $tableName) {
        // Check for accessing large amounts of data in short time
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as access_count 
            FROM audit_logs 
            WHERE user_id = ? AND table_name = ? 
            AND action IN ('SELECT', 'VIEW') 
            AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute([$userId, $tableName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['access_count'] >= 50) {
            $this->logSuspiciousActivity(
                $userId,
                'bulk_data_access',
                "Bulk data access: {$result['access_count']} records from {$tableName} in 10 minutes",
                'high'
            );
        }
    }
    
    private function checkSensitiveDataModification($userId, $action, $tableName, $recordId) {
        // Log any modifications to sensitive tables
        $sensitiveTables = ['users', 'risks', 'audit_logs', 'login_history'];
        
        if (in_array($tableName, $sensitiveTables)) {
            $this->logSuspiciousActivity(
                $userId,
                'sensitive_data_modification',
                "{$action} operation on sensitive table: {$tableName} (Record ID: {$recordId})",
                'medium'
            );
        }
    }
    
    private function logSuspiciousActivity($userId, $activityType, $description, $severity = 'medium') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO suspicious_activities (user_id, activity_type, description, severity, ip_address, user_agent, additional_data) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $additionalData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'session_id' => session_id(),
                'referer' => $_SERVER['HTTP_REFERER'] ?? null
            ];
            
            $stmt->execute([
                $userId,
                $activityType,
                $description,
                $severity,
                $this->getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                json_encode($additionalData)
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to log suspicious activity: " . $e->getMessage());
            return false;
        }
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
    
    public function getSuspiciousActivities($limit = 100, $offset = 0, $severity = null) {
        try {
            $whereClause = $severity ? "WHERE severity = ?" : "";
            $params = $severity ? [$severity, $limit, $offset] : [$limit, $offset];
            
            $stmt = $this->db->prepare("
                SELECT sa.*, u.full_name, u.email, u.role,
                       resolver.full_name as resolved_by_name
                FROM suspicious_activities sa 
                LEFT JOIN users u ON sa.user_id = u.id 
                LEFT JOIN users resolver ON sa.resolved_by = resolver.id
                {$whereClause}
                ORDER BY sa.created_at DESC 
                LIMIT ? OFFSET ?
            ");
            
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to fetch suspicious activities: " . $e->getMessage());
            return [];
        }
    }
    
    public function resolveSuspiciousActivity($activityId, $resolvedBy) {
        try {
            $stmt = $this->db->prepare("
                UPDATE suspicious_activities 
                SET is_resolved = TRUE, resolved_by = ?, resolved_at = NOW() 
                WHERE id = ?
            ");
            
            return $stmt->execute([$resolvedBy, $activityId]);
        } catch (Exception $e) {
            error_log("Failed to resolve suspicious activity: " . $e->getMessage());
            return false;
        }
    }
}
?>
