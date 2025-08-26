<?php
session_start();
include_once 'config/database.php';
// Include compatibility functions if file exists
if (file_exists('includes/php_compatibility.php')) {
    include_once 'includes/php_compatibility.php';
} else {
    // Define essential functions inline if include fails
    function checkPHPVersion() {
        $minVersion = '7.0';
        $currentVersion = phpversion();
        
        if (version_compare($currentVersion, $minVersion, '<')) {
            return [
                'compatible' => false,
                'message' => "Warning: PHP {$currentVersion} detected. Minimum required: {$minVersion}"
            ];
        }
        
        return [
            'compatible' => true,
            'message' => "PHP {$currentVersion} - Compatible ✓"
        ];
    }
}

// Function to get real IP address
function getRealIPAddress() {
    // Check if we're running on localhost and return a more recognizable IP
    if ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_ADDR'] == '127.0.0.1' || $_SERVER['SERVER_ADDR'] == '::1') {
        return '192.168.1.' . rand(100, 200); // Simulated IP for localhost testing
    }
    
    // Check for shared internet/ISP IP
    if (!empty($_SERVER['HTTP_CLIENT_IP']) && $_SERVER['HTTP_CLIENT_IP'] != '127.0.0.1') {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    // Check for IPs passing through proxies
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    // Check for IPs passing through load balancers
    elseif (!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED'];
    }
    elseif (!empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_FORWARDED_FOR'];
    }
    elseif (!empty($_SERVER['HTTP_FORWARDED'])) {
        $ip = $_SERVER['HTTP_FORWARDED'];
    }
    // Default to REMOTE_ADDR
    else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // Handle IPv6 localhost
    if ($ip == '::1') {
        return '192.168.1.' . rand(100, 200); // Simulated IP for localhost testing
    }
    
    // Validate IP address
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    
    // Fallback to a simulated IP for testing
    return '192.168.1.' . rand(100, 200);
}

$error = '';
$database = new Database();
$db = $database->getConnection();

// Function to create or update user session
function createUserSession($db, $userId, $email, $fullName, $role) {
    // Get current session ID
    $sessionId = session_id();
    
    // Get real IP address
    $ipAddress = getRealIPAddress();
    
    // Get user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Debug logging
    error_log("Creating session for user: $email, IP: $ipAddress, Session ID: $sessionId");
    
    // Use a transaction to ensure data integrity
    try {
        $db->beginTransaction();
        
        // First, delete any existing sessions with the same session_id
        $deleteQuery = "DELETE FROM active_sessions WHERE session_id = :session_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':session_id', $sessionId);
        $deleteStmt->execute();
        
        // Also deactivate any other active sessions for this user (optional - for single session per user)
        $deactivateQuery = "UPDATE active_sessions SET is_active = 0, logout_time = NOW() WHERE user_id = :user_id AND session_id != :session_id";
        $deactivateStmt = $db->prepare($deactivateQuery);
        $deactivateStmt->bindParam(':user_id', $userId);
        $deactivateStmt->bindParam(':session_id', $sessionId);
        $deactivateStmt->execute();
        
        // Now insert the new session record
        $insertQuery = "INSERT INTO active_sessions 
                        (user_id, session_id, user_email, user_name, user_role, ip_address, user_agent, login_time, last_activity, is_active, logout_time, session_data) 
                        VALUES 
                        (:user_id, :session_id, :email, :name, :role, :ip_address, :user_agent, NOW(), NOW(), 1, NULL, NULL)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':user_id', $userId);
        $insertStmt->bindParam(':session_id', $sessionId);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':name', $fullName);
        $insertStmt->bindParam(':role', $role);
        $insertStmt->bindParam(':ip_address', $ipAddress);
        $insertStmt->bindParam(':user_agent', $userAgent);
        
        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create session: " . implode(", ", $insertStmt->errorInfo()));
        }
        
        $db->commit();
        error_log("Successfully created session for user: $email");
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Session creation failed: " . $e->getMessage());
        throw $e;
    }
    
    // Note: Removed concurrent session limiting as we're now enforcing single session per user
}

// Function to check if account is locked
function isAccountLocked($db, $userId) {
    $query = "SELECT * FROM account_lockouts WHERE user_id = :user_id AND is_locked = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $lockout = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if auto-unlock is enabled and lockout period has expired
        $getAutoUnlockQuery = "SELECT setting_value FROM security_settings WHERE setting_name = 'auto_unlock'";
        $getAutoUnlockStmt = $db->prepare($getAutoUnlockQuery);
        $getAutoUnlockStmt->execute();
        $autoUnlockResult = $getAutoUnlockStmt->fetch(PDO::FETCH_ASSOC);
        $autoUnlock = $autoUnlockResult ? (bool)$autoUnlockResult['setting_value'] : true;
        
        if ($autoUnlock && $lockout['unlock_at'] && new DateTime() > new DateTime($lockout['unlock_at'])) {
            // Auto-unlock the account
            $unlockQuery = "UPDATE account_lockouts SET is_locked = 0, failed_attempts = 0 WHERE user_id = :user_id";
            $unlockStmt = $db->prepare($unlockQuery);
            $unlockStmt->bindParam(':user_id', $userId);
            $unlockStmt->execute();
            return false;
        }
        
        return true;
    }
    
    return false;
}

// Function to handle failed login attempt
function handleFailedLogin($db, $userId, $email) {
    // Get security settings
    $getMaxAttemptsQuery = "SELECT setting_value FROM security_settings WHERE setting_name = 'max_failed_attempts'";
    $getMaxAttemptsStmt = $db->prepare($getMaxAttemptsQuery);
    $getMaxAttemptsStmt->execute();
    $maxAttemptsResult = $getMaxAttemptsStmt->fetch(PDO::FETCH_ASSOC);
    $maxAttempts = $maxAttemptsResult ? (int)$maxAttemptsResult['setting_value'] : 5;
    
    $getLockoutDurationQuery = "SELECT setting_value FROM security_settings WHERE setting_name = 'lockout_duration'";
    $getLockoutDurationStmt = $db->prepare($getLockoutDurationQuery);
    $getLockoutDurationStmt->execute();
    $lockoutDurationResult = $getLockoutDurationStmt->fetch(PDO::FETCH_ASSOC);
    $lockoutDuration = $lockoutDurationResult ? (int)$lockoutDurationResult['setting_value'] : 15;
    
    // Check if there's an existing lockout record
    $checkLockoutQuery = "SELECT * FROM account_lockouts WHERE user_id = :user_id";
    $checkLockoutStmt = $db->prepare($checkLockoutQuery);
    $checkLockoutStmt->bindParam(':user_id', $userId);
    $checkLockoutStmt->execute();
    
    if ($checkLockoutStmt->rowCount() > 0) {
        // Update existing record
        $lockout = $checkLockoutStmt->fetch(PDO::FETCH_ASSOC);
        $newFailedAttempts = $lockout['failed_attempts'] + 1;
        
        $updateQuery = "UPDATE account_lockouts SET 
                        failed_attempts = :failed_attempts, 
                        updated_at = NOW()";
        
        // If this is the max attempt, lock the account
        if ($newFailedAttempts >= $maxAttempts) {
            $unlockAt = (new DateTime())->add(new DateInterval("PT{$lockoutDuration}M"))->format('Y-m-d H:i:s');
            $updateQuery .= ", is_locked = 1, locked_at = NOW(), unlock_at = :unlock_at";
        }
        
        $updateQuery .= " WHERE user_id = :user_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':user_id', $userId);
        $updateStmt->bindParam(':failed_attempts', $newFailedAttempts);
        
        if ($newFailedAttempts >= $maxAttempts) {
            $updateStmt->bindParam(':unlock_at', $unlockAt);
        }
        
        $updateStmt->execute();
    } else {
        // Create new lockout record
        $failedAttempts = 1;
        $isLocked = ($failedAttempts >= $maxAttempts) ? 1 : 0;
        $unlockAt = ($isLocked) ? (new DateTime())->add(new DateInterval("PT{$lockoutDuration}M"))->format('Y-m-d H:i:s') : null;
        
        $insertQuery = "INSERT INTO account_lockouts 
                        (user_id, user_email, failed_attempts, is_locked, locked_at, unlock_at, ip_address) 
                        VALUES 
                        (:user_id, :email, :failed_attempts, :is_locked, :locked_at, :unlock_at, :ip_address)";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':user_id', $userId);
        $insertStmt->bindParam(':email', $email);
        $insertStmt->bindParam(':failed_attempts', $failedAttempts);
        $insertStmt->bindParam(':is_locked', $isLocked);
        $insertStmt->bindParam(':ip_address', getRealIPAddress());
        
        if ($isLocked) {
            $insertStmt->bindParam(':locked_at', date('Y-m-d H:i:s'));
            $insertStmt->bindParam(':unlock_at', $unlockAt);
        } else {
            $insertStmt->bindValue(':locked_at', null, PDO::PARAM_NULL);
            $insertStmt->bindValue(':unlock_at', null, PDO::PARAM_NULL);
        }
        
        $insertStmt->execute();
    }
    
    // Return true if account is now locked
    return isAccountLocked($db, $userId);
}

if ($_POST) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $query = "SELECT id, email, password, full_name, role, status FROM users WHERE email = :email";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Debug logging
        error_log("Login attempt for user: $email, IP: " . getRealIPAddress());
        
        // Check if account is locked
        if (isAccountLocked($db, $user['id'])) {
            $error = "Your account has been locked due to too many failed login attempts. Please try again later or contact the administrator.";
            $database->logActivity($user['id'], 'Login Attempt - Account Locked', 'User ' . $email . ' tried to log in but account is locked.', $_SERVER['REMOTE_ADDR']);
        } elseif ($user['status'] !== 'approved') {
            $error = "Your account is pending approval. Please contact the administrator.";
            $database->logActivity($user['id'], 'Login Attempt - Account Pending/Suspended', 'User ' . $email . ' tried to log in but account is ' . $user['status'] . '.', $_SERVER['REMOTE_ADDR']);
        } elseif (password_verify($password, $user['password'])) {
            // Reset failed attempts on successful login
            $resetQuery = "UPDATE account_lockouts SET failed_attempts = 0, is_locked = 0 WHERE user_id = :user_id";
            $resetStmt = $db->prepare($resetQuery);
            $resetStmt->bindParam(':user_id', $user['id']);
            $resetStmt->execute();
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
            // Create or update user session in active_sessions table
            createUserSession($db, $user['id'], $user['email'], $user['full_name'], $user['role']);
            
            // Log successful login
            $database->logActivity($_SESSION['user_id'], 'Successful Login', 'User ' . $_SESSION['email'] . ' logged in successfully.', $_SERVER['REMOTE_ADDR']);
            
            // Redirect based on role
            switch ($user['role']) {
                case 'staff':
                    header("Location: staff_dashboard.php");
                    break;
                case 'risk_owner':
                    header("Location: risk_owner_dashboard.php");
                    break;
                case 'compliance_team':
                    header("Location: compliance_dashboard.php");
                    break;
                case 'admin':
                    header("Location: admin_dashboard.php");
                    break;
            }
            exit();
        } else {
            // Handle failed login attempt
            $isLocked = handleFailedLogin($db, $user['id'], $email);
            
            if ($isLocked) {
                $error = "Your account has been locked due to too many failed login attempts. Please try again later or contact the administrator.";
            } else {
                $error = "Invalid email or password.";
            }
            
            // Log failed login attempt (invalid password)
            $database->logActivity($user['id'], 'Failed Login - Invalid Password', 'User ' . $email . ' entered an invalid password.', $_SERVER['REMOTE_ADDR']);
        }
    } else {
        $error = "Invalid email or password.";
        // Log failed login attempt (user not found)
        $database->logActivity(null, 'Failed Login - User Not Found', 'Attempt to log in with non-existent email: ' . $email, $_SERVER['REMOTE_ADDR']);
    }
}
$phpCheck = checkPHPVersion();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Airtel Risk Management - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(230, 0, 18, 0.2);
            width: 100%;
            max-width: 400px;
            border-top: 5px solid #E60012;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo h1 {
            color: #E60012;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }
        
        .logo p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .airtel-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            display: block;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.2);
            object-fit: cover;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="email"], input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input[type="email"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(230, 0, 18, 0.3);
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #ffcdd2;
        }
        
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .register-link a {
            color: #E60012;
            text-decoration: none;
            font-weight: 500;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        .forgot-password-link {
            text-align: center;
            margin: 1rem 0;
        }
        .forgot-password-link a {
            color: #E60012;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .forgot-password-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Airtel Risk Management</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.name@airtel.africa">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn">Sign In</button>
        </form>
        <div class="forgot-password-link">
            <a href="reset_password.php">Forgot your password?</a>
        </div>
        <div class="register-link">
            <p>Don't have an account? <a href="register.php">Register here</a></p>
        </div>
    </div>
</body>
</html>
