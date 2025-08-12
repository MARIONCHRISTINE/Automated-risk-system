<?php
include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle AJAX requests for audit functionality
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['ajax_action']) {
        case 'get_audit_logs':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id";
            $params = [];
            
            if ($userId) {
                $query .= " WHERE sal.user_id = :user_id";
                $params[':user_id'] = $userId;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format logs for compatibility
            foreach ($logs as &$log) {
                $log['created_at'] = $log['timestamp'];
                $log['table_name'] = '';
                $log['record_id'] = '';
                $log['user_agent'] = '';
            }
            
            echo json_encode(['success' => true, 'logs' => $logs]);
            exit();
            
        case 'get_login_history':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id
                      WHERE (sal.action LIKE '%login%' OR sal.action LIKE '%Login%' OR sal.action LIKE '%authentication%')";
            $params = [];
            
            if ($userId) {
                $query .= " AND sal.user_id = :user_id";
                $params[':user_id'] = $userId;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for compatibility
            foreach ($history as &$login) {
                $login['created_at'] = $login['timestamp'];
                $login['login_status'] = (strpos(strtolower($login['action']), 'failed') !== false ||
                                         strpos(strtolower($login['action']), 'pending') !== false) ? 'failed' : 'success';
                $login['failure_reason'] = (strpos(strtolower($login['action']), 'failed') !== false ||
                                           strpos(strtolower($login['action']), 'pending') !== false) ? $login['details'] : null;
                $login['location_info'] = null;
            }
            
            echo json_encode(['success' => true, 'history' => $history]);
            exit();
            
        case 'get_suspicious_activities':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $severity = $_POST['severity'] ?? null;
            
            // Define suspicious activity patterns
            $suspiciousPatterns = [
                "sal.action LIKE '%failed%'",
                "sal.action LIKE '%error%'",
                "sal.action LIKE '%unauthorized%'",
                "sal.details LIKE '%failed%'",
                "sal.details LIKE '%error%'",
                "sal.details LIKE '%suspicious%'",
                "sal.details LIKE '%multiple attempts%'",
                "sal.action LIKE '%delete%' AND sal.action LIKE '%permanent%'",
                "sal.action LIKE '%Pending%'",
                "sal.action LIKE '%Suspended%'"
            ];
            
            $query = "SELECT sal.*, u.full_name, u.email, u.role,
                 CASE 
                     WHEN (sal.action LIKE '%permanent%' OR sal.details LIKE '%permanent%') THEN 'critical'
                     WHEN (sal.action LIKE '%failed%' OR sal.details LIKE '%failed%' OR sal.action LIKE '%Pending%') THEN 'high'
                     WHEN (sal.action LIKE '%error%' OR sal.details LIKE '%error%' OR sal.action LIKE '%Suspended%') THEN 'medium'
                     ELSE 'low'
                 END as severity,
                 COALESCE(sal.resolved, 0) as is_resolved
                 FROM system_audit_logs sal 
                 LEFT JOIN users u ON sal.user_id = u.id 
                 WHERE (" . implode(' OR ', $suspiciousPatterns) . ") AND COALESCE(sal.resolved, 0) = 0";
            
            $params = [];
            
            if ($severity) {
                $query .= " HAVING severity = :severity";
                $params[':severity'] = $severity;
                $params[':severity'] = $severity;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add additional fields for compatibility
            foreach ($activities as &$activity) {
                $activity['id'] = $activity['id'];
                $activity['activity_type'] = $activity['action'];
                $activity['description'] = $activity['details'];
                $activity['created_at'] = $activity['timestamp'];
                $activity['resolved_by_name'] = null;
            }
            
            echo json_encode(['success' => true, 'activities' => $activities]);
            exit();
            
        case 'resolve_suspicious_activity':
            $activityId = $_POST['activity_id'] ?? null;
            if ($activityId) {
                $query = "UPDATE system_audit_logs SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                if ($stmt->execute([$_SESSION['user_id'], $activityId])) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } else {
                echo json_encode(['success' => false]);
            }
            exit();
            
        case 'export_audit_logs':
            // Get filter parameters from the request
            $searchTerm = $_POST['search_term'] ?? '';
            $userRoleFilter = $_POST['user_role_filter'] ?? '';
            $actionFilter = $_POST['action_filter'] ?? '';
            $fromDate = $_POST['from_date'] ?? '';
            $toDate = $_POST['to_date'] ?? '';
            
            // Build the query with filters
            $query = "SELECT sal.*, u.full_name, u.email, u.role
                      FROM system_audit_logs sal
                      LEFT JOIN users u ON sal.user_id = u.id WHERE 1=1";
            $params = [];
            
            // Apply search term filter
            if (!empty($searchTerm)) {
                $query .= " AND (sal.user LIKE :search_term OR sal.action LIKE :search_term OR sal.details LIKE :search_term OR sal.timestamp LIKE :search_term)";
                $params[':search_term'] = '%' . $searchTerm . '%';
            }
            
            // Apply user role filter
            if (!empty($userRoleFilter)) {
                $query .= " AND sal.user LIKE :user_role_filter";
                $params[':user_role_filter'] = '%' . $userRoleFilter . '%';
            }
            
            // Apply action filter
            if (!empty($actionFilter)) {
                $query .= " AND sal.action LIKE :action_filter";
                $params[':action_filter'] = '%' . $actionFilter . '%';
            }
            
            // Apply date range filters
            if (!empty($fromDate)) {
                $query .= " AND DATE(sal.timestamp) >= :from_date";
                $params[':from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $query .= " AND DATE(sal.timestamp) <= :to_date";
                $params[':to_date'] = $toDate;
            }
            
            $query .= " ORDER BY sal.timestamp DESC LIMIT 5000"; // Limit to prevent memory issues
            
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate filename with filter info
            $filename = 'audit_logs_' . date('Y-m-d_H-i-s');
            if (!empty($searchTerm)) {
                $filename .= '_search-' . preg_replace('/[^a-zA-Z0-9]/', '', substr($searchTerm, 0, 10));
            }
            if (!empty($actionFilter)) {
                $filename .= '_action-' . preg_replace('/[^a-zA-Z0-9]/', '', substr($actionFilter, 0, 10));
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $filename .= '_filtered';
            }
            $filename .= '.csv';
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'ID', 'Date & Time', 'User', 'Full Name', 'Email', 'Role', 'Action', 'Details', 'IP Address'
            ]);
            
            // Add filter information as comments in CSV
            if (!empty($searchTerm) || !empty($userRoleFilter) || !empty($actionFilter) || !empty($fromDate) || !empty($toDate)) {
                fputcsv($output, ['# Filters Applied:']);
                if (!empty($searchTerm)) fputcsv($output, ['# Search Term: ' . $searchTerm]);
                if (!empty($userRoleFilter)) fputcsv($output, ['# User Role: ' . $userRoleFilter]);
                if (!empty($actionFilter)) fputcsv($output, ['# Action: ' . $actionFilter]);
                if (!empty($fromDate)) fputcsv($output, ['# From Date: ' . $fromDate]);
                if (!empty($toDate)) fputcsv($output, ['# To Date: ' . $toDate]);
                fputcsv($output, ['# Total Records: ' . count($logs)]);
                fputcsv($output, []); // Empty line
            }
            
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    date('Y-m-d H:i:s', strtotime($log['timestamp'])),
                    $log['user'],
                    $log['full_name'] ?? 'Unknown',
                    $log['email'] ?? 'Unknown',
                    $log['role'] ?? 'Unknown',
                    $log['action'],
                    $log['details'] ?? '',
                    $log['ip_address'] ?? ''
                ]);
            }
            
            fclose($output);
            exit();
    }
}

// Handle Broadcast Message
if ($_POST && isset($_POST['send_broadcast'])) {
    $broadcast_message = $_POST['broadcast_message'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    
    error_log("BROADCAST: " . $broadcast_message);
    $success_message = "Broadcast message sent successfully!";
    // Log to system_audit_logs
    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
    $log_stmt = $db->prepare($log_query);
    $log_stmt->execute([
        $_SESSION['user_email'] ?? 'Admin',
        'Broadcast Message Sent',
        'Broadcast message: "' . $broadcast_message . '"',
        $ip_address,
        $_SESSION['user_id']
    ]);
}

// --- Data Fetching for Dashboards ---
// Get system statistics (expanded)
$stats_query = "SELECT
    (SELECT COUNT(*) FROM users) as total_users,
    (SELECT COUNT(*) FROM users WHERE status = 'pending') as pending_users,
    (SELECT COUNT(*) FROM users WHERE status = 'approved') as active_users,
    (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_users,
    (SELECT COUNT(*) FROM users WHERE role = 'risk_owner') as risk_owner_users,
    (SELECT COUNT(*) FROM users WHERE role = 'staff') as staff_users,
    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_users_7_days,
    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_users_30_days,
    (SELECT COUNT(*) FROM risk_incidents) as total_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Open') as open_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'In Progress') as in_progress_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Mitigated') as mitigated_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Closed') as closed_risks";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$system_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle View All Audit Logs request
$show_all_logs = isset($_GET['show_all_logs']) && $_GET['show_all_logs'] == '1';

// Get Audit Logs from database (using system_audit_logs table)
if ($show_all_logs) {
    $audit_logs_query = "SELECT
                            sal.timestamp,
                            sal.user,
                            sal.action,
                            sal.details,
                            sal.ip_address
                         FROM
                            system_audit_logs sal
                         ORDER BY
                            sal.timestamp DESC";
} else {
    $audit_logs_query = "SELECT
                            sal.timestamp,
                            sal.user,
                            sal.action,
                            sal.details,
                            sal.ip_address
                         FROM
                            system_audit_logs sal
                         ORDER BY
                            sal.timestamp DESC
                         LIMIT 10";
}
$audit_logs_stmt = $db->prepare($audit_logs_query);
$audit_logs_stmt->execute();
$audit_logs = $audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for "View All" functionality
$total_logs_query = "SELECT COUNT(*) as total FROM system_audit_logs";
$total_logs_stmt = $db->prepare($total_logs_query);
$total_logs_stmt->execute();
$total_logs_count = $total_logs_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get Login History Logs from database (using system_audit_logs table)
$login_history_query = "SELECT
                                sal.timestamp,
                                sal.user,
                                sal.action,
                                sal.details,
                                sal.ip_address
                            FROM
                                system_audit_logs sal
                            WHERE
                                sal.action LIKE '%Login%' OR sal.action LIKE '%authentication%'
                            ORDER BY
                                sal.timestamp DESC";
$login_history_stmt = $db->prepare($login_history_query);
$login_history_stmt->execute();
$login_history_logs = $login_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get last 5 risks for Database Management -> All Risks section
$recent_risks_query = "SELECT ri.risk_name, ri.department, u.full_name as reported_by_name
                       FROM risk_incidents ri
                       JOIN users u ON ri.reported_by = u.id
                       ORDER BY ri.created_at DESC
                       LIMIT 5";
$recent_risks_stmt = $db->prepare($recent_risks_query);
$recent_risks_stmt->execute();
$recent_risks = $recent_risks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all risks for Database Management -> View All Risks
$all_risks_query = "SELECT ri.*, u.full_name as reported_by_name
                    FROM risk_incidents ri
                    JOIN users u ON ri.reported_by = u.id
                    ORDER BY ri.created_at DESC";
$all_risks_stmt = $db->prepare($all_risks_query);
$all_risks_stmt->execute();
$all_risks = $all_risks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get deleted risks for Database Management -> Recycle Bin (for risks)
$deleted_risks_query = "SELECT ri.*, u.full_name as reported_by_name
                        FROM risk_incidents ri
                        JOIN users u ON ri.reported_by = u.id
                        WHERE ri.status = 'Deleted'
                        ORDER BY ri.updated_at DESC";
$deleted_risks_stmt = $db->prepare($deleted_risks_query);
$deleted_risks_stmt->execute();
$deleted_risks = $deleted_risks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info for header
$user_info = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Airtel Risk Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding-top: 150px;
        }
        
        .dashboard {
            min-height: 100vh;
        }
        
        .header {
            background: #E60012;
            padding: 1.5rem 2rem;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        }
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .logo-circle {
            width: 55px;
            height: 55px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 5px;
        }
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        .header-titles {
            display: flex;
            flex-direction: column;
        }
        .main-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .sub-title {
            font-size: 1rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-avatar {
            width: 45px;
            height: 45px;
            background: white;
            color: #E60012;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }
        .user-details {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }
        .user-email {
            font-size: 1rem;
            font-weight: 500;
            color: white;
            margin: 0;
            line-height: 1.2;
        }
        .user-role {
            font-size: 0.9rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.2;
        }
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.7rem 1.3rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            margin-left: 1rem;
        }
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        .nav {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            position: fixed;
            top: 100px;
            left: 0;
            right: 0;
            z-index: 999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        .nav-menu {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-item {
            margin: 0;
        }
        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            cursor: pointer;
        }
        .nav-item a:hover {
            color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .nav-item a.active {
            color: #E60012;
            border-bottom-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }
        .flex {
            display: flex;
        }
        .justify-between {
            justify-content: space-between;
        }
        .items-center {
            align-items: center;
        }
        .w-full {
            width: 100%;
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        .card-title {
            color: #333;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .stats-grid, .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid #E60012;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: #E60012;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .risk-table, .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .risk-table th, .risk-table td,
        .audit-table th, .audit-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .risk-table th, .audit-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        .status-deleted { background: #f8d7da; color: #721c24; }
        .status-open { background: #f8d7da; color: #721c24; }
        .status-in-progress { background: #fff3cd; color: #856404; }
        .status-mitigated { background: #d4edda; color: #155724; }
        .status-closed { background: #cce5ff; color: #004085; }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-primary {
            background: #007bff;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        
        .bulk-upload {
            border: 2px dashed #e1e5e9;
            padding: 2rem;
            text-align: center;
            border-radius: 10px;
            margin: 1rem 0;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        
        .bulk-upload:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        .close:hover {
            opacity: 0.7;
        }
        .modal-body {
            padding: 2rem;
        }
        .form-group {
            margin-bottom: 1.8rem;
        }
        label {
            display: block;
            margin-bottom: 0.6rem;
            color: #333;
            font-weight: 500;
            font-size: 1rem;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        textarea {
            height: 120px;
            resize: vertical;
        }
        
        .action-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .action-group .btn {
            margin: 0;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-size: 1rem;
        }
        
        /* Audit Log Filters Layout */
        .audit-filters-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .audit-filters-container {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .highlighted-row {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        
        /* Quick Actions Containers */
        .quick-actions-containers {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .quick-action-container {
            color: white;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100px;
        }
        .quick-action-container:hover {
            transform: translateY(-5px);
            text-decoration: none;
            color: white;
        }
        .quick-action-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .quick-action-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        .quick-action-description {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        /* Chatbot Icon Styling */
        .chatbot-icon {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        /* Audit Modal Styles */
        .audit-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .audit-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .audit-modal-content {
            background: white;
            border-radius: 10px;
            width: 95%;
            max-width: 1200px;
            max-height: 85vh;
            overflow-y: auto;
        }
        
        .audit-modal-header {
            background: #E60012;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .audit-modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .audit-close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .audit-modal-body {
            padding: 1.5rem;
        }
        
        .audit-table-container {
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .audit-table th,
        .audit-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .audit-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .audit-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .audit-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .audit-badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .audit-badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .audit-badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .audit-badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .audit-badge-critical {
            background: #721c24;
            color: white;
        }
        
        .audit-badge-high {
            background: #dc3545;
            color: white;
        }
        
        .audit-badge-medium {
            background: #ffc107;
            color: #212529;
        }
        
        .audit-badge-low {
            background: #28a745;
            color: white;
        }
        
        .audit-loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        
        .audit-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .audit-filter-select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        
        .resolve-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
        }
        
        .resolve-btn:hover {
            background: #218838;
        }
        
        .resolve-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        
        /* View All Modal Styles */
        .view-all-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .view-all-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .view-all-modal-content {
            background: white;
            border-radius: 10px;
            width: 95%;
            max-width: 1200px;
            max-height: 85vh;
            overflow-y: auto;
        }
        
        .view-all-modal-header {
            background: #E60012;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .view-all-modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .view-all-close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .view-all-modal-body {
            padding: 1.5rem;
        }
        
        .filter-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            body {
                padding-top: 120px;
            }
            
            .header {
                padding: 1.2rem 1.5rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .header-right {
                align-self: flex-end;
            }
            
            .main-title {
                font-size: 1.3rem;
            }
            
            .sub-title {
                font-size: 0.9rem;
            }
            
            .logout-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
            
            .nav {
                top: 80px;
                padding: 0.25rem 0;
            }
            
            .nav-content {
                padding: 0 0.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .nav-menu {
                flex-wrap: nowrap;
                justify-content: flex-start;
                gap: 0;
                min-width: max-content;
                padding: 0 0.5rem;
            }
            
            .nav-item {
                flex: 0 0 auto;
                min-width: 80px;
            }
            
            .nav-item a {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                text-align: center;
                border-bottom: 3px solid transparent;
                border-left: none;
                width: 100%;
                white-space: nowrap;
                min-height: 44px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
            }
            
            .nav-item a.active {
                border-bottom-color: #E60012;
                border-left-color: transparent;
                background-color: rgba(230, 0, 18, 0.1);
            }
            
            .nav-item a:hover {
                background-color: rgba(230, 0, 18, 0.05);
            }
            
            .main-content {
                padding: 1rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .card {
                padding: 1.5rem;
            }
            .audit-filters-container {
                flex-direction: column;
                gap: 0.5rem;
            }
            .audit-filters-group {
                flex-direction: row;
                align-items: center;
                width: 100%;
            }
            .quick-actions-containers {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<div class="dashboard">
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <!-- Updated logo source to image.png from root folder -->
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Airtel Risk Register System</h1>
                    <p class="sub-title">Admin Management Dashboard</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($user_info['full_name']) ? strtoupper(substr($user_info['full_name'], 0, 1)) : 'A'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo htmlspecialchars($user_info['email']); ?></div>
                    <div class="user-role"><?php echo ucfirst($user_info['role']); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
    
    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'overview')" class="active">
                        📊 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="user_management.php">
                        👥 User Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'database')">
                        🗄️ Database Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'notifications')">
                        🔔 Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" onclick="showTab(event, 'settings')">
                        ⚙️ Settings
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    
    <div class="main-content">
        <?php if (isset($success_message)): ?>
            <div class="success">✅ <?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message)): ?>
            <div class="error">❌ <?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <!-- 1. Dashboard Tab -->
        <div id="overview-tab" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['active_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['total_risks']; ?></div>
                    <div class="stat-label">Total Risks Reported</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $system_stats['pending_users']; ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">🚀 Quick Actions</h3>
                </div>
                
                <div class="quick-actions-containers">
                    <!-- Team Chat -->
                    <div class="quick-action-container" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <a href="teamchat.php" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                            <div class="quick-action-icon">
                                <i class="fas fa-comments" style="font-size: 2rem;"></i>
                            </div>
                            <div class="quick-action-title">Team Chat</div>
                            <div class="quick-action-description">Communicate with your team</div>
                        </a>
                    </div>
                    
                    <!-- AI Assistant -->
                    <div class="quick-action-container" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <a href="javascript:void(0)" onclick="openChatBot()" style="text-decoration: none; color: white; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
                            <div class="quick-action-icon">
                                <img src="chatbot.png" alt="AI Assistant" class="chatbot-icon">
                            </div>
                            <div class="quick-action-title">AI Assistant</div>
                            <div class="quick-action-description">Get help with admin tasks</div>
                        </a>
                    </div>
                </div>
            </div>
            
            <div id="audit-logs-section" class="card">
                <div class="flex justify-between items-center" style="margin-bottom: 1rem;">
                    <div>
                        <h2 style="margin-bottom: 0.5rem;">System Audit Logs</h2>
                        <p style="margin-bottom: 0;">Review system activities and administrative actions.</p>
                    </div>
                    <div>
                        <?php if ($show_all_logs): ?>
                            <span style="color: #666; font-size: 0.9rem;">Showing all <?php echo $total_logs_count; ?> entries</span>
                            <a href="?show_all_logs=0#audit-logs-section" class="btn btn-info" style="margin-left: 1rem;">Show Recent</a>
                        <?php else: ?>
                            <span style="color: #666; font-size: 0.9rem;">Showing last <?php echo min(10, count($audit_logs)); ?> of <?php echo $total_logs_count; ?> entries</span>
                            <a href="?show_all_logs=1#audit-logs-section" class="btn btn-info" style="margin-left: 1rem;">View All</a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Updated audit action buttons -->
                <div class="action-group" style="margin-bottom: 1rem;">
                    <button class="btn btn-info" onclick="exportAuditLogsWithFilters()" style="background: #17a2b8;">
                        <i class="fas fa-download"></i> Export Filtered Audit Logs
                    </button>
                    <button class="btn btn-warning" onclick="showSuspiciousActivitiesModal()" style="background: #ffc107; color: #212529;">
                        <i class="fas fa-exclamation-triangle"></i> Highlight Suspicious Activities
                    </button>
                    <button class="btn btn-primary" onclick="showLoginHistoryModal()" style="background: #007bff;">
                        <i class="fas fa-history"></i> View Login History / IP Tracking
                    </button>
                </div>
                
                <input type="text" id="auditLogSearchInput" class="search-input" onkeyup="filterAuditLogTable()" placeholder="Search logs by user, action, or details...">
                <div class="audit-filters-container">
                    <div class="audit-filters-group">
                        <label for="auditLogFilterUser">Filter by User Role:</label>
                        <select id="auditLogFilterUser" onchange="filterAuditLogTable()">
                            <option value="">All Roles</option>
                            <option value="Staff">Staff</option>
                            <option value="Risk Owner">Risk Owner</option>
                            <option value="Compliance">Compliance</option>
                            <option value="Admin">Admin</option>
                            <option value="System">System</option>
                            <option value="Unknown">Unknown</option>
                        </select>
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterAction">Filter by Action:</label>
                        <select id="auditLogFilterAction" onchange="filterAuditLogTable()">
                            <option value="">All Actions</option>
                            <option value="User Approved">Approved user</option>
                            <option value="User Suspended">Suspended user</option>
                            <option value="User Restored">Restored user</option>
                            <option value="Risk Reported">Reported risk</option>
                            <option value="Report Downloaded">Downloaded report</option>
                            <option value="Role Changed">Changed role</option>
                            <option value="Failed Login">Failed login attempt</option>
                            <option value="Successful Logout">Successful log out</option>
                            <option value="Successful Login">Successful log in</option>
                        </select>
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterFromDate">From Date:</label>
                        <input type="date" id="auditLogFilterFromDate" onchange="filterAuditLogTable()">
                    </div>
                    <div class="audit-filters-group">
                        <label for="auditLogFilterToDate">To Date:</label>
                        <input type="date" id="auditLogFilterToDate" onchange="filterAuditLogTable()">
                    </div>
                </div>
                <table class="audit-table" id="auditLogTable">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($audit_logs) > 0): ?>
                            <?php foreach ($audit_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                    <td><?php echo htmlspecialchars($log['user']); ?></td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align: center;">No audit logs available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 2. Database Management Tab -->
        <div id="database-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2>All Risks</h2>
                    <button class="btn btn-primary" onclick="showViewAllModal()">
                        <i class="fas fa-eye"></i> View All
                    </button>
                </div>
                <p>Last 5 reported risk incidents.</p>
                <table class="risk-table">
                    <thead>
                        <tr>
                            <th>Risk Name</th>
                            <th>Department</th>
                            <th>Reported By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_risks) > 0): ?>
                            <?php foreach ($recent_risks as $risk): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                    <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                    <td><?php echo htmlspecialchars($risk['reported_by_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="text-align: center;">No risks found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div id="bulk-upload-section" class="card">
                <h2>Bulk Upload Risk Data</h2>
                <p>Upload historical risk data from CSV or Excel files.</p>
                
                <div class="bulk-upload">
                    <!-- Enhanced form with better error handling and success feedback -->
                    <form action="upload_risk_data.php" method="POST" enctype="multipart/form-data" id="bulkUploadForm" onsubmit="return validateUpload()">
                        <h3>📁 Upload Risk Data</h3>
                        <p>Supported formats: CSV, XLS, XLSX</p>
                        <input type="file" name="bulk_file" id="bulk_file" accept=".csv,.xls,.xlsx" required style="margin: 1rem 0;">
                        <br>
                        <button type="submit" name="bulk_upload" class="btn" style="background-color: #E60012; color: white; padding: 10px 20px;">
                            <i class="fas fa-upload"></i> Upload Data
                        </button>
                    </form>
                </div>
                
                <div style="margin-top: 2rem;">
                    <h3>CSV Format Requirements</h3>
                    <p>Your CSV file should have the following columns:</p>
                    <ul style="margin-left: 2rem; margin-top: 1rem;">
                        <li>risk_name</li>
                        <li>risk_description</li>
                        <li>cause_of_risk</li>
                        <li>department</li>
                        <li>probability (1-5)</li>
                        <li>impact (1-5)</li>
                        <li>status (Open, In Progress, Mitigated, Closed)</li>
                    </ul>
                    <!-- Updated download template button to match theme with proper styling and functionality -->
                    <a href="download_template.php" class="btn" style="background-color: #28a745; color: white; padding: 10px 20px; text-decoration: none; display: inline-block; margin-top: 1rem;" target="_blank" onclick="return confirmTemplateDownload()">
                        <i class="fas fa-download"></i> Download Template
                    </a>
                </div>
            </div>
            
            <!-- Removed Recycle Bin section entirely as requested -->
            <!-- Removed Track Aging Risks section as requested -->
            <!-- Removed Export Data section as requested -->
            <!-- Removed Risk Categories section as requested -->
        </div>
        
        <!-- 3. Notifications Tab -->
        <div id="notifications-tab" class="tab-content">
            <div class="card">
                <h2>Send Broadcast Message</h2>
                <p>Send a message to all active users in the system.</p>
                <form method="POST">
                    <div class="form-group">
                        <label for="broadcast_message">Message</label>
                        <textarea id="broadcast_message" name="broadcast_message" rows="5" required placeholder="Enter your broadcast message here..."></textarea>
                    </div>
                    <button type="submit" name="send_broadcast" class="btn">Send Broadcast</button>
                </form>
            </div>
            <div class="card">
                <h2>System Notifications Settings</h2>
                <p>Configure notification channels (email, in-app, SMS).</p>
                <button class="btn btn-primary" onclick="alert('Simulating Notification Settings...');">Configure Settings</button>
            </div>
            <div class="card">
                <h2>Escalation Alerts</h2>
                <p>Manage rules for automatic risk escalation alerts.</p>
                <button class="btn btn-warning" onclick="alert('Simulating Escalation Alert Settings...');">Manage Escalation Rules</button>
            </div>
            <div class="card">
                <h2>Direct Message</h2>
                <p>Send direct messages to specific roles or users.</p>
                <button class="btn btn-info" onclick="alert('Simulating Direct Messaging...');">Send Direct Message</button>
            </div>
            <div class="card">
                <h2>Reminders for Risk Review</h2>
                <p>Set up automated reminders for risk reviews.</p>
                <button class="btn btn-primary" onclick="alert('Simulating Reminder Settings...');">Set Reminders</button>
            </div>
            <div class="card">
                <h2>Global Announcements</h2>
                <p>Create and manage global announcements (e.g., policy changes).</p>
                <button class="btn btn-success" onclick="alert('Simulating Global Announcements...');">Create Announcement</button>
            </div>
        </div>
        
        <!-- 4. System Settings Tab -->
        <div id="settings-tab" class="tab-content">
            <div class="card">
                <h2>System Settings</h2>
                <p>Configure and fine-tune platform behavior.</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div class="sub-card" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #E60012;">
                        <h3>Configure Risk Thresholds</h3>
                        <p>Define and adjust thresholds for risk levels.</p>
                        <button class="btn btn-warning" onclick="alert('Simulating Risk Threshold Configuration...');">Configure Thresholds</button>
                    </div>
                    <div class="sub-card" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #E60012;">
                        <h3>Update Organization Info</h3>
                        <p>Manage general organization details and branding.</p>
                        <button class="btn btn-info" onclick="alert('Simulating Organization Info Update...');">Edit Info</button>
                    </div>
                    <div class="sub-card" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #E60012;">
                        <h3>Change Default Roles/Permissions</h3>
                        <p>Adjust default roles and their associated permissions.</p>
                        <button class="btn btn-primary" onclick="alert('Simulating Role/Permission Changes...');">Manage Roles</button>
                    </div>
                    <div class="sub-card" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #E60012;">
                        <h3>Authentication Settings</h3>
                        <p>Configure security settings like 2FA and password policies.</p>
                        <button class="btn btn-primary" onclick="alert('Simulating Authentication Settings...');">Configure Auth</button>
                    </div>
                    <div class="sub-card" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #E60012;">
                        <h3>Localization</h3>
                        <p>Set time zone, language, and other regional preferences.</p>
                        <button class="btn btn-info" onclick="alert('Simulating Localization Settings...');">Configure Localization</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Login History Modal -->
<div id="loginHistoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Login History & IP Tracking</h3>
            <button class="close" onclick="closeLoginHistoryModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>This table displays actual login-related activities from your audit logs.</p>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details (including IP)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($login_history_logs) > 0): ?>
                        <?php foreach ($login_history_logs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                <td><?php echo htmlspecialchars($log['user']); ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><?php echo htmlspecialchars($log['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center;">No login history available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Suspicious Activities Modal -->
<div id="suspiciousActivitiesModal" class="audit-modal">
    <div class="audit-modal-content">
        <div class="audit-modal-header">
            <h3 class="audit-modal-title">Suspicious Activities</h3>
            <button class="audit-close" onclick="closeSuspiciousActivitiesModal()">&times;</button>
        </div>
        <div class="audit-modal-body">
            <div class="audit-filters">
                <select id="severityFilter" class="audit-filter-select" onchange="loadSuspiciousActivities()">
                    <option value="">All Severities</option>
                    <option value="critical">Critical</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div class="audit-table-container">
                <div class="audit-loading" id="suspiciousLoading">Loading suspicious activities...</div>
                <table class="audit-table" id="suspiciousTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Activity Type</th>
                            <th>Description</th>
                            <th>Severity</th>
                            <th>Date/Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="suspiciousTableBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Login History Modal -->
<div id="enhancedLoginHistoryModal" class="audit-modal">
    <div class="audit-modal-content">
        <div class="audit-modal-header">
            <h3 class="audit-modal-title">Login History & IP Tracking</h3>
            <button class="audit-close" onclick="closeEnhancedLoginHistoryModal()">&times;</button>
        </div>
        <div class="audit-modal-body">
            <div class="audit-table-container">
                <div class="audit-loading" id="enhancedLoginLoading">Loading login history...</div>
                <table class="audit-table" id="enhancedLoginTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>IP Address</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Date/Time</th>
                        </tr>
                    </thead>
                    <tbody id="enhancedLoginTableBody">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View All Risks Modal -->
<div id="viewAllModal" class="view-all-modal">
    <div class="view-all-modal-content">
        <div class="view-all-modal-header">
            <h3 class="view-all-modal-title">All Risks</h3>
            <button class="view-all-close" onclick="closeViewAllModal()">&times;</button>
        </div>
        <div class="view-all-modal-body">
            <div class="filter-section">
                <div class="filter-group">
                    <label for="modalDateFrom">From Date:</label>
                    <input type="date" id="modalDateFrom" onchange="filterModalRisks()">
                </div>
                <div class="filter-group">
                    <label for="modalDateTo">To Date:</label>
                    <input type="date" id="modalDateTo" onchange="filterModalRisks()">
                </div>
                <div class="filter-group">
                    <label for="modalDepartmentFilter">Department:</label>
                    <select id="modalDepartmentFilter" onchange="filterModalRisks()">
                        <option value="">All Departments</option>
                        <option value="IT">IT</option>
                        <option value="Finance">Finance</option>
                        <option value="HR">HR</option>
                        <option value="Operations">Operations</option>
                        <option value="Marketing">Marketing</option>
                        <option value="Legal">Legal</option>
                    </select>
                </div>
                <!-- Added clear filters button and improved download button styling -->
                <div class="filter-group" style="align-self: flex-end;">
                    <button type="button" onclick="clearFilters()" class="btn" style="background-color: #6c757d; color: white; padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px;">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                    <button type="button" onclick="downloadFilteredRisks()" id="downloadBtn" class="btn" style="background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-download"></i> Download Filtered Data
                    </button>
                </div>
            </div>
            <div class="audit-table-container">
                <table class="audit-table" id="modalRiskTable">
                    <thead>
                        <tr>
                            <th>Risk Name</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Reported By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="modalRiskTableBody">
                        <?php if (count($all_risks) > 0): ?>
                            <?php foreach ($all_risks as $risk): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                    <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $risk['status'])); ?>"><?php echo htmlspecialchars($risk['status']); ?></span></td>
                                    <td><?php echo htmlspecialchars($risk['reported_by_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align: center;">No risks found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function showTab(event, tabName) {
        event.preventDefault();
        const tabContents = document.querySelectorAll('.tab-content');
        tabContents.forEach(content => {
            content.classList.remove('active');
        });
        
        const navLinks = document.querySelectorAll('.nav-item a');
        navLinks.forEach(link => {
            link.classList.remove('active');
        });
        
        const selectedTab = document.getElementById(tabName + '-tab');
        if (selectedTab) {
            selectedTab.classList.add('active');
        }
        
        event.currentTarget.classList.add('active');
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const initialTab = 'overview';
        const overviewTabContent = document.getElementById(initialTab + '-tab');
        const overviewNavLink = document.querySelector(`.nav-item a[onclick*="${initialTab}"]`);
        if (overviewTabContent) {
            overviewTabContent.classList.add('active');
        }
        if (overviewNavLink) {
            overviewNavLink.classList.add('active');
        }
    });
    
    function openLoginHistoryModal() {
        document.getElementById('loginHistoryModal').classList.add('show');
    }
    
    function closeLoginHistoryModal() {
        document.getElementById('loginHistoryModal').classList.remove('show');
    }
    
    function showViewAllModal() {
        document.getElementById('viewAllModal').classList.add('show');
    }
    
    function closeViewAllModal() {
        document.getElementById('viewAllModal').classList.remove('show');
    }
    
    function filterModalRisks() {
        const dateFrom = document.getElementById('modalDateFrom').value;
        const dateTo = document.getElementById('modalDateTo').value;
        const department = document.getElementById('modalDepartmentFilter').value.toLowerCase();
        const table = document.getElementById('modalRiskTable');
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            const cells = row.getElementsByTagName('td');
            
            if (cells.length === 0) continue;
            
            const riskDepartment = cells[1].textContent.toLowerCase();
            const riskDate = cells[4].textContent;
            const riskDateObj = new Date(riskDate);
            
            let showRow = true;
            
            // Filter by department
            if (department && !riskDepartment.includes(department)) {
                showRow = false;
            }
            
            // Filter by date range
            if (dateFrom) {
                const fromDate = new Date(dateFrom);
                if (riskDateObj < fromDate) {
                    showRow = false;
                }
            }
            
            if (dateTo) {
                const toDate = new Date(dateTo);
                if (riskDateObj > toDate) {
                    showRow = false;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
        }
    }
    
    // Updated export function to use current filters
    function exportAuditLogsWithFilters() {
        const searchTerm = document.getElementById('auditLogSearchInput').value;
        const userRoleFilter = document.getElementById('auditLogFilterUser').value;
        const actionFilter = document.getElementById('auditLogFilterAction').value;
        const fromDate = document.getElementById('auditLogFilterFromDate').value;
        const toDate = document.getElementById('auditLogFilterToDate').value;
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'ajax_action';
        actionInput.value = 'export_audit_logs';
        form.appendChild(actionInput);
        
        if (searchTerm) {
            const searchInput = document.createElement('input');
            searchInput.type = 'hidden';
            searchInput.name = 'search_term';
            searchInput.value = searchTerm;
            form.appendChild(searchInput);
        }
        
        if (userRoleFilter) {
            const userRoleInput = document.createElement('input');
            userRoleInput.type = 'hidden';
            userRoleInput.name = 'user_role_filter';
            userRoleInput.value = userRoleFilter;
            form.appendChild(userRoleInput);
        }
        
        if (actionFilter) {
            const actionFilterInput = document.createElement('input');
            actionFilterInput.type = 'hidden';
            actionFilterInput.name = 'action_filter';
            actionFilterInput.value = actionFilter;
            form.appendChild(actionFilterInput);
        }
        
        if (fromDate) {
            const fromDateInput = document.createElement('input');
            fromDateInput.type = 'hidden';
            fromDateInput.name = 'from_date';
            fromDateInput.value = fromDate;
            form.appendChild(fromDateInput);
        }
        
        if (toDate) {
            const toDateInput = document.createElement('input');
            toDateInput.type = 'hidden';
            toDateInput.name = 'to_date';
            toDateInput.value = toDate;
            form.appendChild(toDateInput);
        }
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    function showSuspiciousActivitiesModal() {
        document.getElementById('suspiciousActivitiesModal').classList.add('show');
        loadSuspiciousActivities();
    }
    
    function closeSuspiciousActivitiesModal() {
        document.getElementById('suspiciousActivitiesModal').classList.remove('show');
    }
    
    function loadSuspiciousActivities() {
        const loading = document.getElementById('suspiciousLoading');
        const table = document.getElementById('suspiciousTable');
        const tbody = document.getElementById('suspiciousTableBody');
        const severityFilter = document.getElementById('severityFilter').value;
        
        loading.style.display = 'block';
        table.style.display = 'none';
        
        const formData = new FormData();
        formData.append('ajax_action', 'get_suspicious_activities');
        formData.append('limit', '50');
        if (severityFilter) {
            formData.append('severity', severityFilter);
        }
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            loading.style.display = 'none';
            table.style.display = 'table';
            
            if (data.success && data.activities.length > 0) {
                tbody.innerHTML = '';
                data.activities.forEach(activity => {
                    const row = document.createElement('tr');
                    const isResolved = activity.is_resolved == 1;
                    
                    row.innerHTML = `
                        <td>
                            <strong>${activity.full_name || 'Unknown'}</strong><br>
                            <small class="text-muted">${activity.email || 'Unknown'}</small>
                        </td>
                        <td>${activity.activity_type.replace(/_/g, ' ').toUpperCase()}</td>
                        <td>${activity.description}</td>
                        <td><span class="audit-badge audit-badge-${activity.severity}">${activity.severity.toUpperCase()}</span></td>
                        <td>${formatDateTime(activity.created_at)}</td>
                        <td>
                            ${isResolved ?
                                `<span class="audit-badge audit-badge-success">Resolved</span><br><small>by ${activity.resolved_by_name}</small>` :
                                '<span class="audit-badge audit-badge-warning">Open</span>'
                            }
                        </td>
                        <td>
                            ${!isResolved ?
                                `<button class="resolve-btn" onclick="resolveSuspiciousActivity(${activity.id})">Resolve</button>` :
                                '-'
                            }
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No suspicious activities found</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading suspicious activities:', error);
            loading.style.display = 'none';
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: red;">Error loading suspicious activities</td></tr>';
            table.style.display = 'table';
        });
    }
    
    function resolveSuspiciousActivity(activityId) {
        if (!confirm('Mark this suspicious activity as resolved?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('ajax_action', 'resolve_suspicious_activity');
        formData.append('activity_id', activityId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadSuspiciousActivities(); // Reload the table
            } else {
                alert('Error resolving suspicious activity');
            }
        })
        .catch(error => {
            console.error('Error resolving suspicious activity:', error);
            alert('Error resolving suspicious activity');
        });
    }
    
    function showLoginHistoryModal() {
        document.getElementById('enhancedLoginHistoryModal').classList.add('show');
        loadEnhancedLoginHistory();
    }
    
    function closeEnhancedLoginHistoryModal() {
        document.getElementById('enhancedLoginHistoryModal').classList.remove('show');
    }
    
    function loadEnhancedLoginHistory() {
        const loading = document.getElementById('enhancedLoginLoading');
        const table = document.getElementById('enhancedLoginTable');
        const tbody = document.getElementById('enhancedLoginTableBody');
        
        loading.style.display = 'block';
        table.style.display = 'none';
        
        const formData = new FormData();
        formData.append('ajax_action', 'get_login_history');
        formData.append('limit', '100');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            loading.style.display = 'none';
            table.style.display = 'table';
            
            if (data.success && data.history.length > 0) {
                tbody.innerHTML = '';
                data.history.forEach(login => {
                    const row = document.createElement('tr');
                    
                    row.innerHTML = `
                        <td>
                            <strong>${login.full_name || 'Unknown'}</strong><br>
                            <small class="text-muted">${login.role || 'Unknown'}</small>
                        </td>
                        <td>${login.email || 'Unknown'}</td>
                        <td>${login.ip_address || 'Unknown'}</td>
                        <td>
                            <span class="audit-badge audit-badge-${login.login_status === 'success' ? 'success' : 'danger'}">
                                ${login.login_status.toUpperCase()}
                            </span>
                            ${login.failure_reason ? `<br><small>${login.failure_reason}</small>` : ''}
                        </td>
                        <td>Unknown</td>
                        <td>${formatDateTime(login.created_at)}</td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No login history found</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading login history:', error);
            loading.style.display = 'none';
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: red;">Error loading login history</td></tr>';
            table.style.display = 'table';
        });
    }
    
    function formatDateTime(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString();
    }
    
    // Functions for quick actions
    function openChatBot() {
        alert('AI Assistant feature coming soon! This will provide intelligent help with admin tasks.');
    }
    
    window.onclick = function(event) {
        const modals = document.querySelectorAll('.modal, .audit-modal, .view-all-modal');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });
    }
    
    function filterRiskTable() {
        const input = document.getElementById('riskSearchInput');
        const filter = input.value.toLowerCase();
        const categoryFilter = document.getElementById('riskFilterCategory').value.toLowerCase();
        const levelFilter = document.getElementById('riskFilterLevel').value.toLowerCase();
        const table = document.getElementById('riskTable');
        const tr = table.getElementsByTagName('tr');
        
        for (let i = 1; i < tr.length; i++) {
            let found = false;
            const td = tr[i].getElementsByTagName('td');
            const riskName = td[0] ? td[0].textContent.toLowerCase() : '';
            const department = td[1] ? td[1].textContent.toLowerCase() : '';
            const status = td[2] ? td[2].textContent.toLowerCase() : '';
            
            const matchesSearch = (riskName.indexOf(filter) > -1 || department.indexOf(filter) > -1 || status.indexOf(filter) > -1);
            const matchesCategory = (categoryFilter === '' || department.indexOf(categoryFilter) > -1);
            const matchesLevel = (levelFilter === '' || status.indexOf(levelFilter) > -1);
            
            tr[i].style.display = (matchesSearch && matchesCategory && matchesLevel) ? "" : "none";
        }
    }
    
    function filterAuditLogTable() {
        const input = document.getElementById('auditLogSearchInput');
        const filter = input.value.toLowerCase();
        const userRoleFilter = document.getElementById('auditLogFilterUser').value.toLowerCase();
        const actionFilter = document.getElementById('auditLogFilterAction').value.toLowerCase();
        const fromDateInput = document.getElementById('auditLogFilterFromDate');
        const toDateInput = document.getElementById('auditLogFilterToDate');
        const fromDate = fromDateInput.value ? new Date(fromDateInput.value + 'T00:00:00') : null;
        const toDate = toDateInput.value ? new Date(toDateInput.value + 'T23:59:59') : null;
        
        const table = document.getElementById('auditLogTable');
        const tr = table.getElementsByTagName('tr');
        
        for (let i = 1; i < tr.length; i++) {
            const row = tr[i];
            const td = row.getElementsByTagName('td');
            const timestampText = td[0] ? td[0].textContent : '';
            const userColumnText = td[1] ? td[1].textContent.toLowerCase() : '';
            const action = td[2] ? td[2].textContent.toLowerCase() : '';
            const details = td[3] ? td[3].textContent.toLowerCase() : '';
            const logDate = timestampText ? new Date(timestampText.replace(' ', 'T')) : null;
            
            const matchesSearch = (filter === '' || timestampText.toLowerCase().includes(filter) || userColumnText.includes(filter) || action.includes(filter) || details.includes(filter));
            
            let matchesUserRole = true;
            if (userRoleFilter !== '') {
                matchesUserRole = userColumnText.includes(userRoleFilter);
            }
            
            const matchesAction = (actionFilter === '' || action.includes(actionFilter));
            
            let matchesDate = true;
            if (fromDate && logDate) {
                matchesDate = matchesDate && (logDate >= fromDate);
            }
            if (toDate && logDate) {
                matchesDate = matchesDate && (logDate <= toDate);
            }
            
            row.style.display = (matchesSearch && matchesUserRole && matchesAction && matchesDate) ? "" : "none";
        }
    }

    function confirmTemplateDownload() {
        return confirm('This will download the CSV template file. Continue?');
    }

    function clearFilters() {
        document.getElementById('modalDateFrom').value = '';
        document.getElementById('modalDateTo').value = '';
        document.getElementById('modalDepartmentFilter').value = '';
        filterModalRisks(); // Refresh the table to show all risks
    }

    function downloadFilteredRisks() {
        const dateFrom = document.getElementById('modalDateFrom').value;
        const dateTo = document.getElementById('modalDateTo').value;
        const department = document.getElementById('modalDepartmentFilter').value;
        const button = document.getElementById('downloadBtn');

        // Validate date range
        if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
            alert('From Date cannot be later than To Date. Please correct the date range.');
            return;
        }

        // Show loading state
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Downloading...';
        button.disabled = true;

        // Get visible rows data for CSV export
        const table = document.getElementById('modalRiskTable');
        const rows = table.getElementsByTagName('tr');
        let csvContent = 'Risk Name,Department,Status,Reported By,Date\n';
        
        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            if (row.style.display !== 'none') {
                const cells = row.getElementsByTagName('td');
                if (cells.length > 0) {
                    const rowData = [];
                    for (let j = 0; j < cells.length; j++) {
                        // Clean the cell content and escape commas
                        let cellText = cells[j].textContent.trim();
                        if (cellText.includes(',')) {
                            cellText = '"' + cellText + '"';
                        }
                        rowData.push(cellText);
                    }
                    csvContent += rowData.join(',') + '\n';
                }
            }
        }

        // Create and download the CSV file
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'filtered_risks_' + new Date().toISOString().split('T')[0] + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Reset button state
        setTimeout(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        }, 1500);
    }

    function validateUpload() {
        const fileInput = document.getElementById('bulk_file');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file to upload.');
            return false;
        }
        
        const allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const allowedExtensions = ['csv', 'xls', 'xlsx'];
        
        if (!allowedExtensions.includes(fileExtension)) {
            alert('Please upload a valid CSV, XLS, or XLSX file.');
            return false;
        }
        
        if (file.size > 10 * 1024 * 1024) { // 10MB limit
            alert('File size must be less than 10MB.');
            return false;
        }
        
        // Show loading state on upload button
        const submitBtn = document.querySelector('#bulkUploadForm button[type="submit"]');
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        submitBtn.disabled = true;
        
        return true;
    }
</script>
</body>
</html>
