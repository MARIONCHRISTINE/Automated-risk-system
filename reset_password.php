<?php
session_start();
include_once 'config/database.php';

// Include compatibility functions if file exists
if (file_exists('includes/php_compatibility.php')) {
    include_once 'includes/php_compatibility.php';
} else {
    function endsWith($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }
}

$message = '';
$error = '';

if ($_POST) {
    $email = $_POST['email'];
    
    // Validate email domain
    if (!endsWith($email, '@airtel.africa')) {
        $error = "Please use your Airtel email address ending with @airtel.africa";
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if email exists
        $query = "SELECT id, full_name FROM users WHERE email = :email AND status = 'approved'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store reset token in database
            $token_query = "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)
                           ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at, created_at = NOW()";
            $token_stmt = $db->prepare($token_query);
            $token_stmt->bindParam(':user_id', $user['id']);
            $token_stmt->bindParam(':token', $reset_token);
            $token_stmt->bindParam(':expires_at', $expires_at);
            
            if ($token_stmt->execute()) {
                // In a real application, you would send an email here
                // For now, we'll just show the reset link
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password_confirm.php?token=" . $reset_token;
                $message = "Password reset link generated! In a production environment, this would be sent to your email.<br><br>
                           <strong>Reset Link:</strong><br>
                           <a href='" . $reset_link . "' style='color: #E60012; word-break: break-all;'>" . $reset_link . "</a>";
            } else {
                $error = "Failed to generate reset token. Please try again.";
            }
        } else {
            // Don't reveal if email exists or not for security
            $message = "If your email address exists in our system, you will receive a password reset link.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Airtel Risk Management</title>
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
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="email"] {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input[type="email"]:focus {
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
            <h1>Reset Password</h1>
            <p>Enter your email to reset your password</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="email">Airtel Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.name@airtel.africa">
            </div>
            
            <button type="submit" class="btn">Send Reset Link</button>
        </form>
        
        <div class="back-link">
            <p><a href="login.php">← Back to Login</a></p>
        </div>
    </div>
</body>
</html>
