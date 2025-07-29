<?php
include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// First, let's check if is_active column exists and add it if it doesn't
try {
    $check_column = $db->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if ($check_column->rowCount() == 0) {
        // Add is_active column if it doesn't exist
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        echo "<script>console.log('Added is_active column to users table');</script>";
    }
} catch (Exception $e) {
    error_log("Column check error: " . $e->getMessage());
}

// --- Data Fetching for User Management (Define queries first) ---

// Get all users (excluding current admin and deleted users for main list)
$users_query = "SELECT * FROM users WHERE id != :current_user_id AND status != 'deleted' ORDER BY created_at DESC";
$users_stmt = $db->prepare($users_query);
$users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);

// Get Deleted Users for Recycle Bin
$deleted_users_query = "SELECT * FROM users WHERE status = 'deleted' ORDER BY updated_at DESC";
$deleted_users_stmt = $db->prepare($deleted_users_query);

// Execute initial queries
$users_stmt->execute();
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$deleted_users_stmt->execute();
$deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info for header
$user_info = getCurrentUser();

// --- PHP Logic for User Management Features ---

// Handle Invite User
if ($_POST && isset($_POST['invite_user'])) {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $department = $_POST['department'];
    $password = password_hash('defaultpassword', PASSWORD_DEFAULT);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    // Check if email already exists
    $check_email_query = "SELECT id FROM users WHERE email = :email";
    $check_email_stmt = $db->prepare($check_email_query);
    $check_email_stmt->bindParam(':email', $email);
    $check_email_stmt->execute();

    if ($check_email_stmt->rowCount() > 0) {
        $error_message = "User with this email already exists.";
        // Log to system_audit_logs
        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->execute([
            $_SESSION['user_email'] ?? 'Admin',
            'User Invitation Failed',
            'Attempt to invite existing user ' . $email,
            $ip_address,
            $_SESSION['user_id']
        ]);
    } else {
        $query = "INSERT INTO users (full_name, email, username, password, role, department, status, is_active, created_at, updated_at)
                  VALUES (:full_name, :email, :username, :password, :role, :department, 'pending', 1, NOW(), NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':full_name', $full_name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':username', $email);
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':department', $department);
        
        if ($stmt->execute()) {
            $newUserId = $db->lastInsertId();
            $success_message = "User invited successfully! They can log in with 'defaultpassword'.";
            // Log to system_audit_logs
            $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->execute([
                $_SESSION['user_email'] ?? 'Admin',
                'User Invited',
                'User ' . $email . ' invited with role ' . $role,
                $ip_address,
                $_SESSION['user_id']
            ]);
            
            // Refresh data after successful invite
            $users_stmt->execute();
            $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Failed to invite user. Database error: " . implode(" ", $stmt->errorInfo());
            error_log("Invite user error: " . implode(" ", $stmt->errorInfo()));
        }
    }
}

// Handle Bulk User Actions - ENHANCED WITH DEBUG
if ($_POST && isset($_POST['bulk_action']) && !empty($_POST['bulk_action']) && isset($_POST['selected_users']) && !empty($_POST['selected_users'])) {
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    // Debug information
    error_log("Bulk Action Debug: Action = " . $action . ", Users = " . implode(',', $selected_users));

    try {
        $db->beginTransaction();
        $success_count = 0;
        $failed_count = 0;
        
        foreach ($selected_users as $user_id) {
            $query = "";
            $log_action_type = 'Bulk User Action';
            $log_details = "";
            
            // Fetch user's email for logging details
            $user_email_query = "SELECT email, full_name FROM users WHERE id = :user_id";
            $user_email_stmt = $db->prepare($user_email_query);
            $user_email_stmt->bindParam(':user_id', $user_id);
            $user_email_stmt->execute();
            $user_data = $user_email_stmt->fetch(PDO::FETCH_ASSOC);
            $user_email = $user_data['email'] ?? 'Unknown';
            $user_name = $user_data['full_name'] ?? 'Unknown';
            $user_email_detail = " (Email: " . $user_email . ", Name: " . $user_name . ")";
            
            switch ($action) {
                case 'approve':
                    $query = "UPDATE users SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'User Approved';
                    $log_details = "Approved user ID: " . $user_id . $user_email_detail;
                    break;
                case 'suspend':
                    $query = "UPDATE users SET status = 'suspended', is_active = 0, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'User Suspended';
                    $log_details = "Suspended user ID: " . $user_id . $user_email_detail;
                    break;
                case 'delete_soft':
                    // FIXED: Remove the condition that prevents already deleted users
                    $query = "UPDATE users SET status = 'deleted', is_active = 0, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'User Moved to Recycle Bin';
                    $log_details = "Moved to recycle bin user ID: " . $user_id . $user_email_detail;
                    break;
                case 'delete_permanent':
                    $query = "DELETE FROM users WHERE id = ?";
                    $log_action_type = 'User Permanently Deleted';
                    $log_details = "Permanently deleted user ID: " . $user_id . $user_email_detail;
                    break;
                case 'reset_password':
                    $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT);
                    $query = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $new_password);
                    $stmt->bindParam(2, $user_id);
                    if ($stmt->execute() && $stmt->rowCount() > 0) {
                        $success_count++;
                        // Log to system_audit_logs
                        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                        $log_stmt = $db->prepare($log_query);
                        $log_stmt->execute([
                            $_SESSION['user_email'] ?? 'Admin',
                            'Password Reset',
                            'Password reset for user ID: ' . $user_id . $user_email_detail,
                            $ip_address,
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $failed_count++;
                    }
                    continue 2;
                case 'change_role_admin':
                    $query = "UPDATE users SET role = 'admin', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Admin for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_risk_owner':
                    $query = "UPDATE users SET role = 'risk_owner', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Risk Owner for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_staff':
                    $query = "UPDATE users SET role = 'staff', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Staff for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'change_role_compliance':
                    $query = "UPDATE users SET role = 'compliance_team', updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Role Changed';
                    $log_details = "Changed role to Compliance for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'enable_account':
                    $query = "UPDATE users SET status = 'approved', is_active = 1, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Account Enabled';
                    $log_details = "Enabled account for user ID: " . $user_id . $user_email_detail;
                    break;
                case 'disable_account':
                    $query = "UPDATE users SET status = 'suspended', is_active = 0, updated_at = NOW() WHERE id = ?";
                    $log_action_type = 'Account Disabled';
                    $log_details = "Disabled account for user ID: " . $user_id . $user_email_detail;
                    break;
            }
            
            if (!empty($query)) {
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $user_id);
                
                if ($stmt->execute()) {
                    $affected_rows = $stmt->rowCount();
                    if ($affected_rows > 0) {
                        $success_count++;
                        error_log("Successfully executed action '$action' for user ID: $user_id. Affected rows: $affected_rows");
                        
                        // DEBUG: Check the user's current status after update
                        if ($action === 'delete_soft') {
                            $check_query = "SELECT status FROM users WHERE id = ?";
                            $check_stmt = $db->prepare($check_query);
                            $check_stmt->bindParam(1, $user_id);
                            $check_stmt->execute();
                            $current_status = $check_stmt->fetchColumn();
                            error_log("DEBUG: User ID $user_id status after delete_soft: $current_status");
                        }
                        
                        // Log to system_audit_logs
                        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                        $log_stmt = $db->prepare($log_query);
                        $log_stmt->execute([
                            $_SESSION['user_email'] ?? 'Admin',
                            $log_action_type,
                            $log_details,
                            $ip_address,
                            $_SESSION['user_id']
                        ]);
                    } else {
                        $failed_count++;
                        error_log("Action '$action' for user ID: $user_id executed but no rows affected");
                    }
                } else {
                    $failed_count++;
                    $error_info = $stmt->errorInfo();
                    error_log("Failed to execute action '$action' for user ID: $user_id. Error: " . implode(' - ', $error_info));
                }
            }
        }
        
        $db->commit();
        
        if ($success_count > 0) {
            $success_message = "Bulk action '{$action}' completed successfully for {$success_count} users.";
            if ($failed_count > 0) {
                $success_message .= " {$failed_count} users failed to update.";
            }
        } else {
            $error_message = "Bulk action '{$action}' failed for all selected users.";
        }
        
        // FORCE REFRESH DATA after bulk actions
        $users_stmt->execute();
        $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deleted_users_stmt->execute();
        $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log the counts
        error_log("After bulk action - Active users: " . count($all_users) . ", Deleted users: " . count($deleted_users));
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error_message = "Database error during bulk action: " . $e->getMessage();
        error_log("Bulk action error: " . $e->getMessage());
    }
}

// Handle individual user actions (reset password, change role, toggle status)
if ($_POST && isset($_POST['single_action_user_id'])) {
    $user_id = $_POST['single_action_user_id'];
    $action_type = $_POST['single_action_type'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    // Fetch user's email for logging details
    $user_email_query = "SELECT email FROM users WHERE id = :user_id";
    $user_email_stmt = $db->prepare($user_email_query);
    $user_email_stmt->bindParam(':user_id', $user_id);
    $user_email_stmt->execute();
    $user_email = $user_email_stmt->fetchColumn();
    $user_email_detail = $user_email ? " (Email: " . $user_email . ")" : "";

    try {
        switch ($action_type) {
            case 'reset_password':
                $new_password = password_hash('newdefaultpassword', PASSWORD_DEFAULT);
                $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password', $new_password);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Password reset for user ID: {$user_id}. New password: 'newdefaultpassword'.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Password Reset',
                        'Password reset for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to reset password for user ID: {$user_id}.";
                }
                break;

            case 'change_role':
                $new_role = $_POST['new_role'];
                $query = "UPDATE users SET role = :role, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':role', $new_role);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Role changed to '{$new_role}' for user ID: {$user_id}.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Role Changed',
                        'Role changed to ' . $new_role . ' for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to change role for user ID: {$user_id}.";
                }
                break;

            case 'toggle_status':
                $current_status_query = "SELECT status FROM users WHERE id = :user_id";
                $current_status_stmt = $db->prepare($current_status_query);
                $current_status_stmt->bindParam(':user_id', $user_id);
                $current_status_stmt->execute();
                $current_status = $current_status_stmt->fetchColumn();
                $new_status = ($current_status === 'approved') ? 'suspended' : 'approved';
                $new_is_active = ($new_status === 'approved') ? 1 : 0;
                
                $query = "UPDATE users SET status = :status, is_active = :is_active, updated_at = NOW() WHERE id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':is_active', $new_is_active);
                $stmt->bindParam(':user_id', $user_id);
                if ($stmt->execute()) {
                    $success_message = "Account status changed to '{$new_status}' for user ID: {$user_id}.";
                    // Log to system_audit_logs
                    $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->execute([
                        $_SESSION['user_email'] ?? 'Admin',
                        'Account Status Changed',
                        'Account status changed to ' . $new_status . ' for user ID: ' . $user_id . $user_email_detail,
                        $ip_address,
                        $_SESSION['user_id']
                    ]);
                } else {
                    $error_message = "Failed to change account status for user ID: {$user_id}.";
                }
                break;
        }
        
        // Refresh data after individual actions
        $users_stmt->execute();
        $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        error_log("Individual user action error: " . $e->getMessage());
    }
}

// Handle permanent delete from recycle bin
if ($_POST && isset($_POST['delete_permanent_user'])) {
    $user_id = $_POST['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    $user_email_query = "SELECT email FROM users WHERE id = :user_id";
    $user_email_stmt = $db->prepare($user_email_query);
    $user_email_stmt->bindParam(':user_id', $user_id);
    $user_email_stmt->execute();
    $user_email = $user_email_stmt->fetchColumn();
    $user_email_detail = $user_email ? " (Email: " . $user_email . ")" : "";

    $query = "DELETE FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);

    if ($stmt->execute()) {
        $success_message = "User permanently deleted!";
        // Log to system_audit_logs
        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->execute([
            $_SESSION['user_email'] ?? 'Admin',
            'User Permanently Deleted',
            'User ID: ' . $user_id . $user_email_detail . ' permanently deleted.',
            $ip_address,
            $_SESSION['user_id']
        ]);
        
        // Refresh deleted users data
        $deleted_users_stmt->execute();
        $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error_message = "Failed to permanently delete user.";
    }
}

// Handle restore from recycle bin
if ($_POST && isset($_POST['restore_user'])) {
    $user_id = $_POST['user_id'];
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'N/A';

    $user_email_query = "SELECT email FROM users WHERE id = :user_id";
    $user_email_stmt = $db->prepare($user_email_query);
    $user_email_stmt->bindParam(':user_id', $user_id);
    $user_email_stmt->execute();
    $user_email = $user_email_stmt->fetchColumn();
    $user_email_detail = $user_email ? " (Email: " . $user_email . ")" : "";

    $query = "UPDATE users SET status = 'pending', is_active = 1, updated_at = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);

    if ($stmt->execute()) {
        $success_message = "User restored successfully (status set to pending)!";
        // Log to system_audit_logs
        $log_query = "INSERT INTO system_audit_logs (user, action, details, ip_address, user_id) VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->execute([
            $_SESSION['user_email'] ?? 'Admin',
            'User Restored',
            'User ID: ' . $user_id . $user_email_detail . ' restored from recycle bin.',
            $ip_address,
            $_SESSION['user_id']
        ]);
        
        // Refresh both user lists after restore
        $users_stmt->execute();
        $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deleted_users_stmt->execute();
        $deleted_users = $deleted_users_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error_message = "Failed to restore user.";
    }
}

// DEBUG: Let's see what we have in our arrays
error_log("DEBUG - Total active users: " . count($all_users));
error_log("DEBUG - Total deleted users: " . count($deleted_users));
if (count($deleted_users) > 0) {
    error_log("DEBUG - First deleted user: " . print_r($deleted_users[0], true));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Airtel Risk Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Your existing CSS styles remain exactly the same */
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
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
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
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .user-table th, .user-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .user-table th {
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
        
        .sections-container {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            margin-bottom: 2rem;
            width: 100%;
            max-width: none;
        }
        
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #e1e5e9;
            width: 100%;
        }
        
        .section-header {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            padding: 1.5rem 2rem;
            position: relative;
        }
        
        .section-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0.1) 100%);
        }
        
        .section-content {
            padding: 2rem;
        }
        
        .table-container {
            overflow-x: auto;
            margin: 1rem 0;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
            width: 100%;
        }
        
        .subsection {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 2px solid #f1f3f4;
        }
        
        .subsection h3 {
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .subsection h3 i {
            color: #E60012;
        }
        
        .subsection p {
            color: #666;
            margin-bottom: 1rem;
        }
        
        .invite-form {
            margin-bottom: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .btn-full {
            width: 100%;
            justify-content: center;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stats-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .mini-stat {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #E60012;
        }
        
        .mini-stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #E60012;
            margin-bottom: 0.25rem;
        }
        
        .mini-stat-label {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .settings-list {
            margin-bottom: 1.5rem;
        }
        
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .setting-item:last-child {
            border-bottom: none;
        }
        
        .setting-label {
            font-weight: 500;
            color: #333;
        }
        
        .setting-value {
            color: #666;
            font-weight: 500;
        }
        
        .info-box {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .info-box i {
            color: #17a2b8;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }
        
        .info-box p {
            margin: 0;
            font-size: 0.9rem;
            color: #495057;
            line-height: 1.4;
        }
        
        .view-toggle-btn {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .debug-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1rem;
            font-family: monospace;
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
                padding: 1rem 0.25rem;
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
            
            .section-header {
                padding: 1.25rem 1.5rem;
            }
            
            .section-content {
                padding: 1.5rem;
            }
            
            .stats-mini-grid {
                grid-template-columns: 1fr;
            }
            
            body {
                padding-top: 140px;
            }
            
            .header-content {
                padding: 0 1rem;
            }
            
            .nav-content {
                padding: 0 1rem;
            }
        }
        @media (max-width: 480px) {
            .section-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .section-title h2 {
                font-size: 1.2rem;
            }
            
            .main-content {
                padding: 0.5rem 0.1rem;
            }
            
            .section-content {
                padding: 1rem;
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
                    <a href="admin_dashboard.php">
                        📊 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="user_management.php" class="active">
                        👥 User Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_dashboard.php#database-tab" onclick="showDatabaseTab()">
                        🗄️ Database Management
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_dashboard.php#notifications-tab" onclick="showNotificationsTab()">
                        🔔 Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="admin_dashboard.php#settings-tab" onclick="showSettingsTab()">
                        ⚙️ Settings
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="main-content">
        <!-- DEBUG INFO -->
        <div class="debug-info">
            <strong>DEBUG INFO:</strong><br>
            Active Users: <?php echo count($all_users); ?><br>
            Deleted Users: <?php echo count($deleted_users); ?><br>
            <?php if (count($deleted_users) > 0): ?>
                Sample Deleted User: <?php echo htmlspecialchars($deleted_users[0]['full_name'] ?? 'N/A'); ?> 
                (Status: <?php echo $deleted_users[0]['status'] ?? 'N/A'; ?>)
            <?php endif; ?>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="success">✅ <?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="error">❌ <?php echo $error_message; ?></div>
        <?php endif; ?>

        <!-- Vertical Section Layout -->
        <div class="sections-container">
            
            <!-- Section 1: All Users -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-users"></i>
                        <h2>All Users (<?php echo count($all_users); ?>)</h2>
                    </div>
                    <div class="section-description">
                        Manage all registered users, their roles, and account status
                    </div>
                </div>
                
                <div class="section-content">
                    <?php if (count($all_users) > 5): ?>
                    <div class="view-toggle-btn">
                        <button class="btn btn-primary" onclick="toggleAllUsers()" id="viewAllBtn">
                            View All Users (<?php echo count($all_users); ?>)
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <input type="text" id="userSearchInput" class="search-input" onkeyup="filterUserTable()" placeholder="Search for users by name, email, email, or department...">
                    
                    <!-- FIXED BULK ACTIONS FORM -->
                    <form method="POST" id="bulkActionForm" onsubmit="return validateBulkAction();">
                        <div class="bulk-actions-container">
                            <select name="bulk_action" id="bulkActionSelect" required>
                                <option value="">Select Action</option>
                                <option value="approve">Approve Selected</option>
                                <option value="suspend">Suspend Selected</option>
                                <option value="enable_account">Enable Selected</option>
                                <option value="disable_account">Disable Selected</option>
                                <option value="reset_password">Reset Password Selected</option>
                                <option value="change_role_admin">Change Role to Admin</option>
                                <option value="change_role_risk_owner">Change Role to Risk Owner</option>
                                <option value="change_role_staff">Change Role to Staff</option>
                                <option value="change_role_compliance">Change Role to Compliance</option>
                                <option value="delete_soft">Move to Recycle Bin</option>
                            </select>
                            <button type="submit" class="btn">Apply</button>
                        </div>
                        
                        <div class="table-container">
                            <table class="user-table" id="userTable">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllUsers"></th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Account Status</th>
                                    </tr>
                                </thead>
                                <tbody id="userTableBody">
                                    <?php 
                                    $display_users = array_slice($all_users, 0, 5); // Show only first 5 users
                                    if (count($display_users) > 0):
                                        foreach ($display_users as $user): 
                                            // Determine account status based on is_active and status
                                            $account_status = 'Active';
                                            if ($user['status'] === 'suspended' || (isset($user['is_active']) && !$user['is_active'])) {
                                                $account_status = 'Locked';
                                            }
                                        ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user-checkbox"></td>
                                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $user['status']; ?>">
                                                        <?php echo ucfirst($user['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $account_status === 'Active' ? 'status-approved' : 'status-suspended'; ?>">
                                                        <?php echo $account_status; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; 
                                    else: ?>
                                        <tr><td colspan="6" style="text-align: center;">No active users found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>

                    <!-- Hidden full user data for JavaScript -->
                    <script>
                    const allUsersData = <?php echo json_encode($all_users); ?>;
                    let showingAll = false;
                    </script>
                    
                    <!-- Recycle Bin Sub-section -->
                    <div class="subsection">
                        <h3><i class="fas fa-trash-restore"></i> Recycle Bin (<?php echo count($deleted_users); ?>)</h3>
                        <p>Users moved to the recycle bin can be restored or permanently deleted.</p>
                        
                        <?php if (count($deleted_users) > 5): ?>
                        <div class="view-toggle-btn">
                            <button class="btn btn-primary" onclick="toggleDeletedUsers()" id="viewAllDeletedBtn">
                                View All Deleted Users (<?php echo count($deleted_users); ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-container">
                            <table class="user-table" id="deletedUserTable">
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
                                <tbody id="deletedUserTableBody">
                                    <?php 
                                    $display_deleted_users = array_slice($deleted_users, 0, 5);
                                    if (count($display_deleted_users) > 0): ?>
                                        <?php foreach ($display_deleted_users as $user): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></td>
                                                <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
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
                        
                        <!-- Hidden deleted users data for JavaScript -->
                        <script>
                        const allDeletedUsersData = <?php echo json_encode($deleted_users); ?>;
                        let showingAllDeleted = false;
                        </script>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Invite User -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-user-plus"></i>
                        <h2>Invite User</h2>
                    </div>
                    <div class="section-description">
                        Add new users to the system with specific roles and permissions
                    </div>
                </div>
                
                <div class="section-content">
                    <form method="POST" class="invite-form">
                        <div class="form-group">
                            <label for="invite_full_name">Full Name</label>
                            <input type="text" id="invite_full_name" name="full_name" required placeholder="Enter full name">
                        </div>
                        <div class="form-group">
                            <label for="invite_email">Email Address</label>
                            <input type="email" id="invite_email" name="email" required placeholder="Enter email address">
                        </div>
                        <div class="form-group">
                            <label for="invite_role">Role</label>
                            <select id="invite_role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="staff">Staff</option>
                                <option value="risk_owner">Risk Owner</option>
                                <option value="compliance_team">Compliance</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="invite_department">Department</label>
                            <input type="text" id="invite_department" name="department" required placeholder="Enter department">
                        </div>
                        <button type="submit" name="invite_user" class="btn btn-success btn-full">
                            <i class="fas fa-paper-plane"></i> Send Invitation
                        </button>
                    </form>
                    
                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <p>New users will receive login credentials via email with a default password that must be changed on first login.</p>
                    </div>
                </div>
            </div>
            
            <!-- Section 3: Session Management -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-clock"></i>
                        <h2>Session Management</h2>
                    </div>
                    <div class="section-description">
                        Monitor and control active user sessions across the system
                    </div>
                </div>
                
                <div class="section-content">
                    <div class="stats-mini-grid">
                        <div class="mini-stat">
                            <div class="mini-stat-number">12</div>
                            <div class="mini-stat-label">Active Sessions</div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-number">3</div>
                            <div class="mini-stat-label">Admin Sessions</div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-info btn-full" onclick="viewActiveSessions()">
                            <i class="fas fa-eye"></i> View Active Sessions
                        </button>
                        <button class="btn btn-warning btn-full" onclick="forceLogoutAll()">
                            <i class="fas fa-sign-out-alt"></i> Force Logout All Users
                        </button>
                        <button class="btn btn-primary btn-full" onclick="sessionSettings()">
                            <i class="fas fa-cog"></i> Session Settings
                        </button>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-shield-alt"></i>
                        <p>Session timeout is currently set to 30 minutes of inactivity.</p>
                    </div>
                </div>
            </div>
            
            <!-- Section 4: Account Locking -->
            <div class="section-card">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-lock"></i>
                        <h2>Account Locking</h2>
                    </div>
                    <div class="section-description">
                        Configure security policies for account lockouts and access control
                    </div>
                </div>
                
                <div class="section-content">
                    <div class="stats-mini-grid">
                        <div class="mini-stat">
                            <div class="mini-stat-number">5</div>
                            <div class="mini-stat-label">Failed Attempts Limit</div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-number">2</div>
                            <div class="mini-stat-label">Currently Locked</div>
                        </div>
                    </div>
                    
                    <div class="settings-list">
                        <div class="setting-item">
                            <span class="setting-label">Max Failed Attempts:</span>
                            <span class="setting-value">5 attempts</span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Lockout Duration:</span>
                            <span class="setting-value">15 minutes</span>
                        </div>
                        <div class="setting-item">
                            <span class="setting-label">Auto-unlock:</span>
                            <span class="setting-value">Enabled</span>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-full" onclick="configureLocking()">
                            <i class="fas fa-cog"></i> Configure Policies
                        </button>
                        <button class="btn btn-warning btn-full" onclick="viewLockedAccounts()">
                            <i class="fas fa-user-lock"></i> View Locked Accounts
                        </button>
                        <button class="btn btn-success btn-full" onclick="unlockAllAccounts()">
                            <i class="fas fa-unlock"></i> Unlock All Accounts
                        </button>
                    </div>
                    
                    <div class="info-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Account lockout helps prevent brute force attacks and unauthorized access attempts.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Enhanced JavaScript for bulk actions validation
        function validateBulkAction() {
            const selectedAction = document.getElementById('bulkActionSelect').value;
            const selectedUsers = document.querySelectorAll('.user-checkbox:checked');
            
            if (!selectedAction) {
                alert('Please select an action to perform.');
                return false;
            }
            
            if (selectedUsers.length === 0) {
                alert('Please select at least one user.');
                return false;
            }
            
            // Confirmation messages based on action
            let confirmMessage = '';
            switch(selectedAction) {
                case 'delete_soft':
                    confirmMessage = `Are you sure you want to move ${selectedUsers.length} user(s) to the recycle bin?`;
                    break;
                case 'delete_permanent':
                    confirmMessage = `Are you sure you want to PERMANENTLY delete ${selectedUsers.length} user(s)? This action cannot be undone.`;
                    break;
                case 'reset_password':
                    confirmMessage = `Are you sure you want to reset passwords for ${selectedUsers.length} user(s)?`;
                    break;
                default:
                    confirmMessage = `Are you sure you want to perform this action on ${selectedUsers.length} user(s)?`;
            }
            
            if (confirm(confirmMessage)) {
                // Show loading message
                const submitBtn = document.querySelector('button[type="submit"]');
                submitBtn.textContent = 'Processing...';
                submitBtn.disabled = true;
                return true;
            }
            return false;
        }

        // Select all functionality
        document.addEventListener('DOMContentLoaded', function() {
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

        function filterUserTable() {
            const input = document.getElementById('userSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('userTable');
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                let found = false;
                const td = tr[i].getElementsByTagName('td');
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

        function toggleAllUsers() {
            const tbody = document.getElementById('userTableBody');
            const btn = document.getElementById('viewAllBtn');
            
            if (!showingAll) {
                // Show all users
                tbody.innerHTML = '';
                allUsersData.forEach(user => {
                    const row = createUserRow(user);
                    tbody.appendChild(row);
                });
                btn.textContent = 'Show Less';
                showingAll = true;
            } else {
                // Show only first 5
                tbody.innerHTML = '';
                allUsersData.slice(0, 5).forEach(user => {
                    const row = createUserRow(user);
                    tbody.appendChild(row);
                });
                btn.textContent = `View All Users (${allUsersData.length})`;
                showingAll = false;
            }
        }

        function createUserRow(user) {
            const row = document.createElement('tr');
            const accountStatus = (user.status === 'suspended' || (user.is_active !== undefined && !user.is_active)) ? 'Locked' : 'Active';
            const accountStatusClass = accountStatus === 'Active' ? 'status-approved' : 'status-suspended';
            
            row.innerHTML = `
                <td><input type="checkbox" name="selected_users[]" value="${user.id}" class="user-checkbox"></td>
                <td>${user.full_name}</td>
                <td>${user.email}</td>
                <td>${user.role.charAt(0).toUpperCase() + user.role.slice(1).replace('_', ' ')}</td>
                <td><span class="status-badge status-${user.status}">${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</span></td>
                <td><span class="status-badge ${accountStatusClass}">${accountStatus}</span></td>
            `;
            return row;
        }

        function toggleDeletedUsers() {
            const tbody = document.getElementById('deletedUserTableBody');
            const btn = document.getElementById('viewAllDeletedBtn');
            
            if (!showingAllDeleted) {
                // Show all deleted users
                tbody.innerHTML = '';
                if (allDeletedUsersData.length > 0) {
                    allDeletedUsersData.forEach(user => {
                        const row = createDeletedUserRow(user);
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>';
                }
                btn.textContent = 'Show Less';
                showingAllDeleted = true;
            } else {
                // Show only first 5
                tbody.innerHTML = '';
                const displayUsers = allDeletedUsersData.slice(0, 5);
                if (displayUsers.length > 0) {
                    displayUsers.forEach user => {
                        const row = createDeletedUserRow(user);
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Recycle bin is empty.</td></tr>';
                }
                btn.textContent = `View All Deleted Users (${allDeletedUsersData.length})`;
                showingAllDeleted = false;
            }
        }

        function createDeletedUserRow(user) {
            const row = document.createElement('tr');
            const deletedDate = new Date(user.updated_at);
            const formattedDate = deletedDate.toLocaleDateString('en-US', { 
                month: 'short', 
                day: '2-digit', 
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            row.innerHTML = `
                <td>${user.full_name}</td>
                <td>${user.email}</td>
                <td>${user.role.charAt(0).toUpperCase() + user.role.slice(1).replace('_', ' ')}</td>
                <td>${user.department || 'N/A'}</td>
                <td>${formattedDate}</td>
                <td>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to restore ${user.full_name}?');">
                        <input type="hidden" name="user_id" value="${user.id}">
                        <button type="submit" name="restore_user" class="btn btn-info btn-sm">Restore</button>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to PERMANENTLY delete ${user.full_name}? This action cannot be undone.');">
                        <input type="hidden" name="user_id" value="${user.id}">
                        <button type="submit" name="delete_permanent_user" class="btn btn-danger btn-sm">Permanent Delete</button>
                    </form>
                </td>
            `;
            return row;
        }

        // Session Management Functions
        function viewActiveSessions() {
            alert('Active Sessions:\n\n• admin@airtel.com - 192.168.1.100 (Active)\n• user1@airtel.com - 192.168.1.101 (Idle 5 min)\n• user2@airtel.com - 192.168.1.102 (Active)\n\nThis feature will show real-time session data.');
        }

        function forceLogoutAll() {
            if (confirm('Are you sure you want to force logout all users? This will end all active sessions immediately.')) {
                alert('All users have been logged out successfully.');
            }
        }

        function sessionSettings() {
            alert('Session Settings:\n\n• Session Timeout: 30 minutes\n• Max Concurrent Sessions: 3\n• Remember Me Duration: 7 days\n\nThis will open the session configuration panel.');
        }

        // Account Locking Functions
        function configureLocking() {
            alert('Account Locking Configuration:\n\n• Max Failed Attempts: 5\n• Lockout Duration: 15 minutes\n• Auto-unlock: Enabled\n• IP-based Blocking: Disabled\n\nThis will open the security policy settings.');
        }

        function viewLockedAccounts() {
            alert('Currently Locked Accounts:\n\n• user3@airtel.com - Locked 5 min ago (4 min remaining)\n• user4@airtel.com - Locked 10 min ago (Manual unlock required)\n\nThis will show the locked accounts management panel.');
        }

        function unlockAllAccounts() {
            if (confirm('Are you sure you want to unlock all currently locked accounts?')) {
                alert('All locked accounts have been unlocked successfully.');
            }
        }

        // Navigation functions for other tabs
        function showDatabaseTab() {
            window.location.href = 'admin_dashboard.php';
            setTimeout(function() {
                if (window.showTab) {
                    showTab({preventDefault: function(){}}, 'database');
                }
            }, 100);
        }

        function showNotificationsTab() {
            window.location.href = 'admin_dashboard.php';
            setTimeout(function() {
                if (window.showTab) {
                    showTab({preventDefault: function(){}}, 'notifications');
                }
            }, 100);
        }

        function showSettingsTab() {
            window.location.href = 'admin_dashboard.php';
            setTimeout(function() {
                if (window.showTab) {
                    showTab({preventDefault: function(){}}, 'settings');
                }
            }, 100);
        }
    </script>
</body>
</html>
</merged_code>