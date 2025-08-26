<?php
session_start();
include_once 'config/database.php';

// Email Configuration
class EmailConfig {
    const COMPANY_NAME = 'Airtel Risk Management';
}

$message = '';
$error = '';

if ($_GET && isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = $_GET['email'];
    $token_salt = 'your-secret-salt-here'; // Must match the salt used in the reset form
    
    // Validate token format
    $token_parts = explode('|', $token);
    if (count($token_parts) !== 3) {
        $error = "Invalid reset link. Please request a new password reset.";
    } else {
        list($random_string, $timestamp, $email_hash) = $token_parts;
        
        // Check if token is expired (1 hour = 3600 seconds)
        if (time() - $timestamp > 3600) {
            $error = "This reset link has expired. Please request a new password reset.";
        } else {
            // Verify the email hash
            $expected_hash = md5($email . $token_salt . $timestamp);
            if ($email_hash !== $expected_hash) {
                $error = "Invalid reset link. Please request a new password reset.";
            } else {
                // Token is valid, check if email exists in database
                $database = new Database();
                $db = $database->getConnection();
                
                $query = "SELECT id, full_name FROM users WHERE email = :email AND status = 'approved'";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_email'] = $email;
                    header('Location: reset_password_form.php');
                    exit;
                } else {
                    $error = "No account found with this email address or account is not active.";
                }
            }
        }
    }
} else {
    $error = "Invalid reset link. Please request a new password reset.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - Airtel Risk Management</title>
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
        
        .reset-container {
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
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #ffcdd2;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 0.75rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #c8e6c9;
        }
        
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .back-link a {
            color: #E60012;
            text-decoration: none;
            font-weight: 500;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Password Reset</h1>
            <p><?php echo EmailConfig::COMPANY_NAME; ?></p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="back-link">
            <p><a href="forgot_password.php">← Request New Reset Link</a></p>
            <p><a href="login.php">← Back to Login</a></p>
        </div>
    </div>
</body>
</html>
