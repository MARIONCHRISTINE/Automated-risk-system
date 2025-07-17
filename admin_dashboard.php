<?php
include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// --- PHP Logic for New Features ---

// Handle Invite User
if ($_POST && isset($_POST['invite_user'])) {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $department = $_POST['department'];
    $password = password_hash('defaultpassword', PASSWORD_DEFAULT); // Default password for invited users

    // Check if email already exists to prevent duplicate entry errors
    $check_email_query = "SELECT id FROM users WHERE email = :email";
    $check_email_stmt = $db->prepare($check_email_query);
    $check_email_stmt->bindParam(':email', $email);
    $check_email_stmt->execute();

    if ($check_email_stmt->rowCount() > 0) {
        // Removed the error message assignment as per user request.
        // In a future iteration, this could trigger a notification system.
        $error_message = "User with this email already exists.";
        $database->logActivity($_SESSION['user_id'], 'User Invitation Failed', 'Attempt to invite existing user ' . $email . '.', $_SERVER['REMOTE_ADDR']);
    } else {
        // Assuming 'username' column exists and needs to be unique. Using email as username.
        $query = "INSERT INTO users (full_name, email, username, password, role, department, status, created_at, updated_at)
                  VALUES (:full_name, :email, :username, :password, :role, :department, 'pending', NOW(), NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':username', $email); // Bind email to username for the 'username' column
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':department', $department);

        if ($stmt->execute()) {
            $success_message = "User invited successfully! They can log in with 'defaultpassword'.";
            $database->logActivity($_SESSION['user_id'], 'User Invited', 'User ' . $email . ' invited with role ' . $role, $_SERVER['REMOTE_ADDR']);
        } else {
            $error_message = "Failed to invite user. Database error: " . implode(" ", $stmt->errorInfo());
            error_log("Invite user error: " . implode(" ", $stmt->errorInfo())); // Log detailed error for debugging
            $database->logActivity($_SESSION['user_id'], 'User Invitation Failed', 'Failed to invite user ' . $email . '. DB Error: ' . implode(" ", $stmt->errorInfo()), $_SERVER['REMOTE_ADDR']);
        }
    }
}

// Handle Bulk User Actions
if ($_POST && isset($_POST['bulk_action']) && isset($_POST['selected_users'])) {
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'];
    $placeholders = implode(',', array_fill(0, count($selected_users), '?'));

    try {
        $db->beginTransaction();
        $success_count = 0;

        foreach ($selected_users as $user_id) {
            $query = "";
            $log_details = "";
            switch ($action) {
                case 'approve':
                    $query = "UPDATE users SET status = 'approved' WHERE id = ?";
                    $log_details = "Approved user ID: " . $user_id;
                    break;
                case 'suspend':
                    $query = "UPDATE users SET status = 'suspended' WHERE id = ?";
                    $log_details = "Suspended user ID: " . $user_id;
                    break;
                case 'delete_soft': // Soft delete
                    $query = "UPDATE users SET status = 'deleted' WHERE id = ?";
                    $log_details = "Soft deleted user ID: " . $user_id;
                    break;
                case 'delete_permanent': // Permanent delete
                    $query = "DELETE FROM users WHERE id = ?";
                    $log_details = "Permanently deleted user ID: " . $user_id;
                    break;
                case 'reset_password':
                    $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT); // New default password
                    $query = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $new_password);
                    $stmt->bindParam(2, $user_id);
                    if ($stmt->execute()) {
                        $success_count++;
                        $database->logActivity($_SESSION['user_id'], 'Bulk Password Reset', 'Password reset for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                    }
                    continue 2; // Skip to next user in outer loop
                case 'change_role_admin':
                    $query = "UPDATE users SET role = 'admin' WHERE id = ?";
                    $log_details = "Changed role to Admin for user ID: " . $user_id;
                    break;
                case 'change_role_risk_owner':
                    $query = "UPDATE users SET role = 'risk_owner' WHERE id = ?";
                    $log_details = "Changed role to Risk Owner for user ID: " . $user_id;
                    break;
                case 'change_role_staff':
                    $query = "UPDATE users SET role = 'staff' WHERE id = ?";
                    $log_details = "Changed role to Staff for user ID: " . $user_id;
                    break;
                case 'enable_account':
                    $query = "UPDATE users SET status = 'approved' WHERE id = ?"; // Assuming 'approved' is enabled
                    $log_details = "Enabled account for user ID: " . $user_id;
                    break;
                case 'disable_account':
                    $query = "UPDATE users SET status = 'suspended' WHERE id = ?"; // Assuming 'suspended' is disabled
                    $log_details = "Disabled account for user ID: " . $user_id;
                    break;
            }

            if (!empty($query)) {
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $user_id);
                if ($stmt->execute()) {
                    $success_count++;
                    $database->logActivity($_SESSION['user_id'], 'Bulk User Action', $log_details, $_SERVER['REMOTE_ADDR']);
                }
            }
        }

        $db->commit();
        $success_message = "Bulk action '{$action}' completed for {$success_count} users.";
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Database error during bulk action: " . $e->getMessage();
        error_log("Bulk action error: " . $e->getMessage());
        $database->logActivity($_SESSION['user_id'], 'Bulk User Action Failed', 'Database error during bulk action "' . $action . '": ' . $e->getMessage(), $_SERVER['REMOTE_ADDR']);
    }
}


// Handle individual user actions (reset password, change role, toggle status)
if ($_POST && isset($_POST['single_action_user_id'])) {
    $user_id = $_POST['single_action_user_id'];
    $action_type = $_POST['single_action_type'];

    try {
        switch ($action_type) {
            case 'reset_password':
                $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT);
                $query = "UPDATE users SET password = :password WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password', $new_password);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Password reset for user ID: {$user_id}. New password: 'newdefaultpassword'.";
                    $database->logActivity($_SESSION['user_id'], 'Password Reset', 'Password reset for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                } else {
                    $error_message = "Failed to reset password for user ID: {$user_id}.";
                    $database->logActivity($_SESSION['user_id'], 'Password Reset Failed', 'Failed to reset password for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                }
                break;
            case 'change_role':
                $new_role = $_POST['new_role'];
                $query = "UPDATE users SET role = :role WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':role', $new_role);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Role changed to '{$new_role}' for user ID: {$user_id}.";
                    $database->logActivity($_SESSION['user_id'], 'Role Changed', 'Role changed to ' . $new_role . ' for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                } else {
                    $error_message = "Failed to change role for user ID: {$user_id}.";
                    $database->logActivity($_SESSION['user_id'], 'Role Change Failed', 'Failed to change role for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                }
                break;
            case 'toggle_status':
                $current_status_query = "SELECT status FROM users WHERE id = :user_id";
                $current_status_stmt = $db->prepare($current_status_query);
                $current_status_stmt->bindParam(':user_id', $user_id);
                $current_status_stmt->execute();
                $current_status = $current_status_stmt->fetchColumn();

                $new_status = ($current_status === 'approved') ? 'suspended' : 'approved';
                $query = "UPDATE users SET status = :status WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Account status changed to '{$new_status}' for user ID: {$user_id}.";
                    $database->logActivity($_SESSION['user_id'], 'Account Status Changed', 'Account status changed to ' . $new_status . ' for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                } else {
                    $error_message = "Failed to change account status for user ID: {$user_id}.";
                    $database->logActivity($_SESSION['user_id'], 'Account Status Change Failed', 'Failed to change account status for user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
                }
                break;
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        error_log("Individual user action error: " . $e->getMessage());
        $database->logActivity($_SESSION['user_id'], 'Individual User Action Failed', 'Database error for user ID ' . $user_id . ': ' . $e->getMessage(), $_SERVER['REMOTE_ADDR']);
    }
}

// Handle user approval (existing logic, kept for single approval)
if ($_POST && isset($_POST['approve_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'approved' WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User approved successfully!";
        $database->logActivity($_SESSION['user_id'], 'User Approved', 'User ID: ' . $user_id . ' approved.', $_SERVER['REMOTE_ADDR']);
    } else {
        $database->logActivity($_SESSION['user_id'], 'User Approval Failed', 'Failed to approve user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
    }
}

// Handle user suspension (existing logic, kept for single suspension)
if ($_POST && isset($_POST['suspend_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'suspended' WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User suspended successfully!";
        $database->logActivity($_SESSION['user_id'], 'User Suspended', 'User ID: ' . $user_id . ' suspended.', $_SERVER['REMOTE_ADDR']);
    } else {
        $database->logActivity($_SESSION['user_id'], 'User Suspension Failed', 'Failed to suspend user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
    }
}

// Handle user deletion (existing logic, kept for single deletion)
if ($_POST && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'deleted' WHERE id = :user_id"; // Soft delete
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User moved to recycle bin!";
        $database->logActivity($_SESSION['user_id'], 'User Deleted (Soft)', 'User ID: ' . $user_id . ' moved to recycle bin.', $_SERVER['REMOTE_ADDR']);
    } else {
        $database->logActivity($_SESSION['user_id'], 'User Soft Delete Failed', 'Failed to soft delete user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
    }
}

// Handle permanent delete from recycle bin
if ($_POST && isset($_POST['delete_permanent_user'])) {
    $user_id = $_POST['user_id'];
    $query = "DELETE FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User permanently deleted!";
        $database->logActivity($_SESSION['user_id'], 'User Permanently Deleted', 'User ID: ' . $user_id . ' permanently deleted.', $_SERVER['REMOTE_ADDR']);
    } else {
        $database->logActivity($_SESSION['user_id'], 'User Permanent Delete Failed', 'Failed to permanently delete user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
    }
}

// Handle restore from recycle bin
if ($_POST && isset($_POST['restore_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'pending' WHERE id = :user_id"; // Restore to pending for re-approval
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User restored successfully (status set to pending)!";
        $database->logActivity($_SESSION['user_id'], 'User Restored', 'User ID: ' . $user_id . ' restored from recycle bin.', $_SERVER['REMOTE_ADDR']);
    } else {
        $database->logActivity($_SESSION['user_id'], 'User Restore Failed', 'Failed to restore user ID: ' . $user_id, $_SERVER['REMOTE_ADDR']);
    }
}

// Handle Broadcast Message
if ($_POST && isset($_POST['send_broadcast'])) {
    $broadcast_message = $_POST['broadcast_message'];
    // In a real application, you would send this message via email, SMS, or an internal notification system.
    // For this example, we'll just log it and show a success message.
    error_log("BROADCAST: " . $broadcast_message);
    $success_message = "Broadcast message sent successfully!";
    $database->logActivity($_SESSION['user_id'], 'Broadcast Message Sent', 'Broadcast message: "' . $broadcast_message . '"', $_SERVER['REMOTE_ADDR']);
}

// --- Data Fetching for Dashboards ---

// Get all users (excluding current admin and deleted users for main list)
$users_query = "SELECT * FROM users WHERE id != :current_user_id AND status != 'deleted' ORDER BY created_at DESC";
$users_stmt = $db->prepare($users_query);
$users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$users_stmt->execute();
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get Audit Logs from database (using system_audit_logs table)
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
$audit_logs_stmt = $db->prepare($audit_logs_query);
$audit_logs_stmt->execute();
$audit_logs = $audit_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

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
                                sal.action LIKE '%login%' OR sal.action LIKE '%Login%' OR sal.action LIKE '%authentication%'
                            ORDER BY
                                sal.timestamp DESC";
$login_history_stmt = $db->prepare($login_history_query);
$login_history_stmt->execute();
$login_history_logs = $login_history_stmt->fetchAll(PDO::FETCH_ASSOC);


// Get Deleted Users for Recycle Bin
$deleted_users_query = "SELECT * FROM users WHERE status = 'deleted' ORDER BY updated_at DESC";
$deleted_users_stmt = $db->prepare($deleted_users_query);
$deleted_users_stmt->execute();
$deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        WHERE ri.status = 'Deleted' -- Assuming a 'Deleted' status for risks
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
        padding-top: 150px; /* Space for fixed header and nav */
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
        top: 100px; /* Adjust based on header height */
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
        border-top: 4px solid #E60012; /* Consistent card border */
    }
    
    .card h2 {
        color: #333;
        margin-bottom: 1.5rem;
        font-size: 1.5rem;
        font-weight: 600;
    }
    
    .stats-grid {
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
        border-top: 4px solid #E60012; /* Consistent stat card border */
    }
    
    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        color: #E60012; /* Use Airtel Red for numbers */
    }
    
    .stat-label {
        color: #666;
        font-size: 0.9rem;
        font-weight: 600;
    }
    
    .user-table, .risk-table, .audit-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    
    .user-table th, .user-table td,
    .risk-table th, .risk-table td,
    .audit-table th, .audit-table td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    
    .user-table th, .risk-table th, .audit-table th {
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
    .status-deleted { background: #f8d7da; color: #721c24; } /* Added for deleted status */
    .status-open { background: #f8d7da; color: #721c24; }
    .status-in-progress { background: #fff3cd; color: #856404; }
    .status-mitigated { background: #d4edda; color: #155724; }
    .status-closed { background: #cce5ff; color: #004085; }
    
    .btn {
        background: #E60012; /* Airtel Red */
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 5px;
        cursor: pointer;
        margin: 0.25rem;
        font-size: 0.9rem;
        transition: all 0.3s;
        text-decoration: none; /* Ensure buttons as links look consistent */
        display: inline-block;
    }
    
    .btn:hover {
        background: #B8000E; /* Darker Airtel Red */
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
    
    .success {
        background: #d4edda;
        color: #155724;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        border-left: 4px solid #28a745;
        font-weight: 500;
    }
    
    .bulk-upload {
        border: 2px dashed #e1e5e9; /* Consistent border color */
        padding: 2rem;
        text-align: center;
        border-radius: 10px;
        margin: 1rem 0;
        background: #f8f9fa; /* Light background */
        transition: all 0.3s;
    }
    
    .bulk-upload:hover {
        border-color: #E60012; /* Airtel Red on hover */
        background: rgba(230, 0, 18, 0.05); /* Light red tint on hover */
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

    .bulk-actions-container {
        display: flex;
        gap: 1rem;
        margin-bottom: 1rem;
        align-items: center;
    }
    .bulk-actions-container select, .bulk-actions-container button {
        padding: 0.75rem 1rem;
        border-radius: 5px;
        border: 1px solid #ddd;
    }
    .bulk-actions-container select {
        flex-grow: 1;
    }

    /* Audit Log Filters Layout */
    .audit-filters-group {
        display: flex;
        align-items: center;
        gap: 0.5rem; /* Space between label and select */
    }
    .audit-filters-container {
        display: flex;
        gap: 1rem; /* Space between filter groups */
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }
    .highlighted-row {
        background-color: #fff3cd; /* Light yellow for highlighting */
        border-left: 4px solid #ffc107;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        body {
            padding-top: 120px; /* Adjusted for smaller screens */
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
            top: 80px; /* Adjusted for smaller header */
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
    .bulk-actions-container {
        flex-direction: column;
        align-items: stretch;
    }
    .bulk-actions-container select, .bulk-actions-container button {
        width: 100%;
    }
    .audit-filters-container {
        flex-direction: column; /* Stack filters vertically on small screens */
        gap: 0.5rem;
    }
    .audit-filters-group {
        flex-direction: row; /* Keep label and select side-by-side within the group */
        align-items: center;
        width: 100%;
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
                        <a href="#" onclick="showTab(event, 'users')">
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
                    <h2>Quick Actions</h2>
                    <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                        <button class="btn btn-primary" onclick="showTab(event, 'users'); openInviteUserModal();">Invite New User</button>
                        <a href="#" class="btn btn-info" onclick="showTab(event, 'users');">View All Users</a>
                        <a href="#" class="btn btn-warning" onclick="showTab(event, 'database');">Manage Risk Categories</a>
                        <a href="#" class="btn btn-success" onclick="showTab(event, 'notifications');">Send Broadcast</a>
                    </div>
                </div>

                <div id="audit-logs-section" class="card">
                    <h2>System Audit Logs</h2>
                    <p>Review system activities and administrative actions.</p>
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
                                <?php
                                // Dynamically populate actions from audit logs
                                $distinct_actions_query = "SELECT DISTINCT action FROM system_audit_logs ORDER BY action ASC";
                                $distinct_actions_stmt = $db->prepare($distinct_actions_query);
                                $distinct_actions_stmt->execute();
                                $distinct_actions = $distinct_actions_stmt->fetchAll(PDO::FETCH_COLUMN);
                                foreach ($distinct_actions as $action_name):
                                ?>
                                    <option value="<?php echo htmlspecialchars($action_name); ?>"><?php echo htmlspecialchars($action_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Add date filter here -->
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
                    <div class="action-group" style="margin-top: 1rem;">
                        <button class="btn btn-info" onclick="exportAuditLogs()">Export Audit Logs</button>
                        <button class="btn btn-warning" onclick="highlightSuspiciousActivities()">Highlight Suspicious Activities</button>
                        <button class="btn btn-primary" onclick="openLoginHistoryModal()">View Login History / IP Tracking</button>
                    </div>
                </div>
            </div>
            
            <!-- 2. User Management Tab -->
            <div id="users-tab" class="tab-content">
                <div class="card">
                    <div class="flex justify-between items-center" style="margin-bottom: 1.5rem;">
                        <h2>All Users</h2>
                        <button class="btn btn-success" onclick="openInviteUserModal()">Invite New User</button>
                    </div>
                    
                    <input type="text" id="userSearchInput" class="search-input" onkeyup="filterUserTable()" placeholder="Search for users by name, email, or department...">

                    <form method="POST" onsubmit="return confirm('Are you sure you want to perform this bulk action?');">
                        <div class="bulk-actions-container">
                            <select name="bulk_action" id="bulkActionSelect">
                                <option value="">Select Bulk Action</option>
                                <option value="approve">Approve Selected</option>
                                <option value="suspend">Suspend Selected</option>
                                <option value="enable_account">Enable Selected</option>
                                <option value="disable_account">Disable Selected</option>
                                <option value="reset_password">Reset Password Selected</option>
                                <option value="change_role_admin">Change Role to Admin</option>
                                <option value="change_role_risk_owner">Change Role to Risk Owner</option>
                                <option value="change_role_staff">Change Role to Staff</option>
                                <option value="delete_soft">Move to Recycle Bin</option>
                            </select>
                            <button type="submit" class="btn">Apply</button>
                        </div>
                        <table class="user-table" id="userTable">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllUsers"></th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_users as $user): ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user-checkbox"></td>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo ucfirst($user['role']); ?></td>
                                        <td><?php echo htmlspecialchars($user['department']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <?php if ($user['status'] === 'pending'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="approve_user" class="btn btn-success btn-sm">Approve</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($user['status'] === 'approved'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="suspend_user" class="btn btn-warning btn-sm">Suspend</button>
                                                    </form>
                                                <?php else: // if suspended or pending, allow enable ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="single_action_user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="single_action_type" value="toggle_status">
                                                        <button type="submit" class="btn btn-info btn-sm">Enable</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset password for <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                                    <input type="hidden" name="single_action_user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="single_action_type" value="reset_password">
                                                    <button type="submit" class="btn btn-primary btn-sm">Reset Pass</button>
                                                </form>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Change role for <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                                    <input type="hidden" name="single_action_user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="single_action_type" value="change_role">
                                                    <select name="new_role" style="padding: 0.3rem; border-radius: 4px; font-size: 0.8rem;">
                                                        <option value="admin" <?php echo ($user['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                                        <option value="risk_owner" <?php echo ($user['role'] == 'risk_owner') ? 'selected' : ''; ?>>Risk Owner</option>
                                                        <option value="staff" <?php echo ($user['role'] == 'staff') ? 'selected' : ''; ?>>Staff</option>
                                                    </select>
                                                    <button type="submit" class="btn btn-info btn-sm">Change Role</button>
                                                </form>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to move <?php echo htmlspecialchars($user['full_name']); ?> to recycle bin?')">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="delete_user" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>

                <div class="card">
                    <h2>User Activity Logs</h2>
                    <p>Review specific user activities and actions from the audit logs.</p>
                    <button class="btn btn-info" onclick="showTab(event, 'overview'); filterAuditLogsByUser();">View User Activity Logs</button>
                </div>

                <div class="card">
                    <h2>Session Management</h2>
                    <p>View active user sessions and force logout options.</p>
                    <button class="btn btn-warning" onclick="alert('Simulating Session Management...');">View Active Sessions</button>
                    <button class="btn btn-danger" onclick="alert('Simulating Force Logout...');">Force Logout All</button>
                </div>

                <div class="card">
                    <h2>Account Locking Options</h2>
                    <p>Configure account locking policies (e.g., after failed login attempts).</p>
                    <button class="btn btn-primary" onclick="alert('Simulating Account Locking Settings...');">Configure Locking</button>
                </div>

                <div id="recycle-bin-section" class="card">
                    <h2>Recycle Bin (Deleted Users)</h2>
                    <p>Users moved to the recycle bin can be restored or permanently deleted.</p>
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Deleted At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($deleted_users) > 0): ?>
                                <?php foreach ($deleted_users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo ucfirst($user['role']); ?></td>
                                        <td><?php echo htmlspecialchars($user['department']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($user['updated_at'])); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to restore <?php echo htmlspecialchars($user['full_name']); ?>?');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="restore_user" class="btn btn-info btn-sm">Restore</button>
                                            </form>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete <?php echo htmlspecialchars($user['full_name']); ?>? This action cannot be undone.');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete_permanent_user" class="btn btn-danger btn-sm">Permanent Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 3. Database Management Tab -->
            <div id="database-tab" class="tab-content">
                <div class="card">
                    <h2>View All Risks</h2>
                    <p>Browse and manage all reported risk incidents.</p>
                    <input type="text" id="riskSearchInput" class="search-input" onkeyup="filterRiskTable()" placeholder="Search risks by name, department, or status...">
                    <div style="margin-bottom: 1.5rem;">
                        <label for="riskFilterCategory">Filter by Category:</label>
                        <select id="riskFilterCategory" onchange="filterRiskTable()">
                            <option value="">All Categories</option>
                            <!-- Populate dynamically from DB -->
                            <option value="Financial">Financial</option>
                            <option value="Operational">Operational</option>
                            <option value="Compliance">Compliance</option>
                            <option value="Strategic">Strategic</option>
                        </select>
                        <label for="riskFilterLevel" style="margin-left: 1rem;">Filter by Level:</label>
                        <select id="riskFilterLevel" onchange="filterRiskTable()">
                            <option value="">All Levels</option>
                            <option value="High">High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                        <!-- Add date filter here -->
                    </div>
                    <table class="risk-table" id="riskTable">
                        <thead>
                            <tr>
                                <th>Risk Name</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Reported By</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($all_risks) > 0): ?>
                                <?php foreach ($all_risks as $risk): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                        <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                        <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $risk['status'])); ?>"><?php echo htmlspecialchars($risk['status']); ?></span></td>
                                        <td><?php echo htmlspecialchars($risk['reported_by_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info btn-sm">Edit</button>
                                                <button class="btn btn-danger btn-sm">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="text-align: center;">No risks found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div id="bulk-upload-section" class="card">
                    <h2>Bulk Upload Risk Data</h2>
                    <p>Upload historical risk data from CSV or Excel files.</p>
                    
                    <div class="bulk-upload">
                        <form method="POST" enctype="multipart/form-data" id="bulkUploadForm">
                            <h3>📁 Upload Risk Data</h3>
                            <p>Supported formats: CSV, XLS, XLSX</p>
                            <input type="file" name="bulk_file" accept=".csv,.xls,.xlsx" required style="margin: 1rem 0;">
                            <br>
                            <button type="submit" name="bulk_upload" class="btn">Upload Data</button>
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
                        <a href="download_template.php" class="btn" style="margin-top: 1rem;">Download Template</a>
                    </div>
                </div>

                <div class="card">
                    <h2>Recycle Bin (Deleted Risk Reports)</h2>
                    <p>Deleted risk reports can be restored or permanently deleted.</p>
                    <table class="risk-table">
                        <thead>
                            <tr>
                                <th>Risk Name</th>
                                <th>Department</th>
                                <th>Deleted At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($deleted_risks) > 0): ?>
                                <?php foreach ($deleted_risks as $risk): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                        <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($risk['updated_at'])); ?></td>
                                        <td>
                                            <div class="action-group">
                                                <button class="btn btn-info btn-sm">Restore</button>
                                                <button class="btn btn-danger btn-sm">Permanent Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center;">Risk recycle bin is empty.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h2>Track Aging Risks</h2>
                    <p>List of risks that have been unresolved for a specified number of days.</p>
                    <button class="btn btn-primary" onclick="alert('Simulating Aging Risks Report...');">View Aging Risks</button>
                </div>

                <div class="card">
                    <h2>Export Data</h2>
                    <p>Export risk data in various formats.</p>
                    <div class="action-group">
                        <button class="btn btn-info">Export to PDF</button>
                        <button class="btn btn-info">Export to CSV</button>
                        <button class="btn btn-info">Export to Excel</button>
                    </div>
                </div>

                <div class="card">
                    <h2>Risk Categories</h2>
                    <p>Manage risk categories and classifications.</p>
                    <a href="manage_categories.php" class="btn">Manage Categories</a>
                </div>
            </div>
            
            <!-- 4. Notifications Tab -->
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

            <!-- 5. System Settings Tab -->
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
    </div>

    <!-- Invite User Modal -->
    <div id="inviteUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Invite New User</h3>
                <button class="close" onclick="closeInviteUserModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="form-group">
                        <label for="invite_full_name">Full Name</label>
                        <input type="text" id="invite_full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="invite_email">Email</label>
                        <input type="email" id="invite_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="invite_role">Role</label>
                        <select id="invite_role" name="role" required>
                            <option value="staff">Staff</option>
                            <option value="risk_owner">Risk Owner</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="invite_department">Department</label>
                        <input type="text" id="invite_department" name="department" required>
                    </div>
                    <button type="submit" name="invite_user" class="btn">Send Invitation</button>
                </form>
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

    <script>
        function showTab(event, tabName) {
            // Prevent default link behavior
            event.preventDefault();

            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav links
            const navLinks = document.querySelectorAll('.nav-item a');
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected tab content
            const selectedTab = document.getElementById(tabName + '-tab');
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Add active class to clicked nav link
            event.currentTarget.classList.add('active');
        }

        // Set initial active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const initialTab = 'overview'; // Default tab
            const overviewTabContent = document.getElementById(initialTab + '-tab');
            const overviewNavLink = document.querySelector(`.nav-item a[onclick*="${initialTab}"]`);

            if (overviewTabContent) {
                overviewTabContent.classList.add('active');
            }
            if (overviewNavLink) {
                overviewNavLink.classList.add('active');
            }

            // Initialize select all checkbox for users
            const selectAllUsersCheckbox = document.getElementById('selectAllUsers');
            if (selectAllUsersCheckbox) {
                selectAllUsersCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.user-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = selectAllUsersCheckbox.checked;
                    });
                });
            }
        });

        function openInviteUserModal() {
            document.getElementById('inviteUserModal').classList.add('show');
        }

        function closeInviteUserModal() {
            document.getElementById('inviteUserModal').classList.remove('show');
        }

        function openLoginHistoryModal() {
            document.getElementById('loginHistoryModal').classList.add('show');
        }

        function closeLoginHistoryModal() {
            document.getElementById('loginHistoryModal').classList.remove('show');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
        }

        function filterUserTable() {
            const input = document.getElementById('userSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('userTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header row
                let found = false;
                const td = tr[i].getElementsByTagName('td');
                // Check columns 1 (Name), 2 (Email), 4 (Department)
                if (td[1] && td[1].textContent.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                } else if (td[2] && td[2].textContent.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                } else if (td[4] && td[4].textContent.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                }
                tr[i].style.display = found ? "" : "none";
            }
        }

        function filterRiskTable() {
            const input = document.getElementById('riskSearchInput');
            const filter = input.value.toLowerCase();
            const categoryFilter = document.getElementById('riskFilterCategory').value.toLowerCase();
            const levelFilter = document.getElementById('riskFilterLevel').value.toLowerCase();
            const table = document.getElementById('riskTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header row
                let found = false;
                const td = tr[i].getElementsByTagName('td');
                const riskName = td[0] ? td[0].textContent.toLowerCase() : '';
                const department = td[1] ? td[1].textContent.toLowerCase() : '';
                const status = td[2] ? td[2].textContent.toLowerCase() : '';
                // Assuming level/category are part of the risk name or description for now, or would be in hidden data attributes
                // For a real implementation, you'd need actual columns for category and level.

                const matchesSearch = (riskName.indexOf(filter) > -1 || department.indexOf(filter) > -1 || status.indexOf(filter) > -1);
                const matchesCategory = (categoryFilter === '' || department.indexOf(categoryFilter) > -1); // Using department as a proxy for category for now
                const matchesLevel = (levelFilter === '' || status.indexOf(levelFilter) > -1); // Using status as a proxy for level for now

                tr[i].style.display = (matchesSearch && matchesCategory && matchesLevel) ? "" : "none";
            }
        }

        function filterAuditLogTable() {
            const input = document.getElementById('auditLogSearchInput');
            const filter = input.value.toLowerCase();
            const userRoleFilter = document.getElementById('auditLogFilterUser').value.toLowerCase(); // Changed to userRoleFilter
            const actionFilter = document.getElementById('auditLogFilterAction').value.toLowerCase();
            const fromDateInput = document.getElementById('auditLogFilterFromDate');
            const toDateInput = document.getElementById('auditLogFilterToDate');

            const fromDate = fromDateInput.value ? new Date(fromDateInput.value + 'T00:00:00') : null;
            const toDate = toDateInput.value ? new Date(toDateInput.value + 'T23:59:59') : null;

            const table = document.getElementById('auditLogTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header row
                let found = false;
                const td = tr[i].getElementsByTagName('td');
                const timestampText = td[0] ? td[0].textContent : '';
                const user = td[1] ? td[1].textContent.toLowerCase() : ''; // This is the 'user' column from system_audit_logs
                const action = td[2] ? td[2].textContent.toLowerCase() : '';
                const details = td[3] ? td[3].textContent.toLowerCase() : '';

                // Convert timestamp from table to Date object for comparison
                const logDate = timestampText ? new Date(timestampText.replace(' ', 'T')) : null;

                const matchesSearch = (filter === '' || timestampText.toLowerCase().indexOf(filter) > -1 || user.indexOf(filter) > -1 || action.indexOf(filter) > -1 || details.indexOf(filter) > -1);
                
                // Filter by user role: check if the 'user' column string contains the selected role
                let matchesUserRole = true;
                if (userRoleFilter !== '') {
                    // Special handling for "Risk Owner" as it's two words
                    if (userRoleFilter === 'risk owner') {
                        matchesUserRole = user.includes('risk owner');
                    } else {
                        matchesUserRole = user.includes(userRoleFilter);
                    }
                }

                const matchesAction = (actionFilter === '' || action.indexOf(actionFilter) > -1);

                let matchesDate = true;
                if (fromDate && logDate) {
                    matchesDate = matchesDate && (logDate >= fromDate);
                }
                if (toDate && logDate) {
                    matchesDate = matchesDate && (logDate <= toDate);
                }

                tr[i].style.display = (matchesSearch && matchesUserRole && matchesAction && matchesDate) ? "" : "none";
            }
        }

        function filterAuditLogsByUser() {
            // This function now only triggers the filter on the current tab (Dashboard)
            // and does not switch tabs, as audit logs are now on the Dashboard.
            // In a real app, you'd then set the filter value and trigger the filter function
            // document.getElementById('auditLogFilterUser').value = 'specific_user_id_or_name';
            // filterAuditLogTable();
        }

        function exportAuditLogs() {
            // Redirect to a PHP script that generates and forces download of a CSV file
            window.location.href = 'export_audit_logs.php';
        }

        function highlightSuspiciousActivities() {
            const table = document.getElementById('auditLogTable');
            const tr = table.getElementsByTagName('tr');
            let anyHighlighted = false;

            for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header row
                const row = tr[i];
                const action = row.getElementsByTagName('td')[2].textContent.toLowerCase();
                const details = row.getElementsByTagName('td')[3].textContent.toLowerCase();
                const user = row.getElementsByTagName('td')[1].textContent.toLowerCase();

                // Define suspicious rules (example: failed logins, actions by 'System' that are not auto-assignments)
                const isSuspicious = (
                    action.includes('failed login') ||
                    (user.includes('system') && !action.includes('auto-assigned')) ||
                    details.includes('failed') ||
                    details.includes('error') ||
                    details.includes('unauthorized')
                );

                if (isSuspicious) {
                    row.classList.add('highlighted-row');
                    anyHighlighted = true;
                } else {
                    row.classList.remove('highlighted-row');
                }
            }

            if (!anyHighlighted) {
                alert('No suspicious activities found based on current rules.');
            } else {
                alert('Suspicious activities highlighted in yellow.');
            }
        }

        function viewLoginHistory() {
            openLoginHistoryModal();
        }
    </script>
</body>
</html>
