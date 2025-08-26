<?php
session_start();
include_once 'config/database.php';
// Include PHPMailer
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Email Configuration
class EmailConfig {
    const FROM_EMAIL = 'mauriceokoth952@gmail.com';
    const FROM_NAME = 'Airtel Risk Management System';
    const SUPPORT_EMAIL = 'support@yourcompany.com';
    const COMPANY_NAME = 'Airtel Risk Management';
    
    // SMTP Settings
    const SMTP_HOST = 'smtp.gmail.com';
    const SMTP_PORT = 587;
    const SMTP_USERNAME = 'mauriceokoth952@gmail.com';
    const SMTP_PASSWORD = 'dein nmuj btpc cxoe'; // Your Gmail App Password
    const SMTP_ENCRYPTION = 'tls';
}

$message = '';
$error = '';
if ($_POST) {
    $email = $_POST['email'];
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    } else {
        // Generate a secure token that includes email verification
        $token_salt = 'your-secret-salt-here'; // Change this to a secure random string
        $timestamp = time();
        $random_string = bin2hex(random_bytes(16));
        $email_hash = md5($email . $token_salt . $timestamp);
        $token = $random_string . '|' . $timestamp . '|' . $email_hash;
        
        // Create reset link
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password_confirm.php?token=" . urlencode($token) . "&email=" . urlencode($email);
        
        // Send email with reset link
        $emailSent = sendPasswordResetEmail($email, $reset_link);
        
        if ($emailSent) {
            $message = "Password reset link has been sent to your email address. Please check your inbox and spam folder.";
        } else {
            $error = "Failed to send reset email. Please try again later or contact support.";
        }
    }
}

function sendPasswordResetEmail($recipientEmail, $resetLink) {
    try {
        // Create a new PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = EmailConfig::SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = EmailConfig::SMTP_USERNAME;
        $mail->Password = EmailConfig::SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = EmailConfig::SMTP_PORT;
        
        // Set sender information
        $mail->setFrom(EmailConfig::FROM_EMAIL, EmailConfig::FROM_NAME);
        $mail->addAddress($recipientEmail);
        $mail->addReplyTo(EmailConfig::SUPPORT_EMAIL, EmailConfig::COMPANY_NAME . ' Support');
        
        // Content settings
        $mail->isHTML(true);
        $mail->Subject = "Password Reset Request - " . EmailConfig::COMPANY_NAME;
        $mail->CharSet = 'UTF-8';
        
        // Create email body
        $mail->Body = createPasswordResetEmailTemplate($recipientEmail, $resetLink);
        
        // Add custom headers
        $mail->addCustomHeader('X-Recipient-Email', $recipientEmail);
        $mail->addCustomHeader('X-Mailer', 'Airtel Risk Management System');
        
        // Send the email
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        return false;
    }
}

function createPasswordResetEmailTemplate($recipientEmail, $resetLink) {
    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Password Reset - ' . EmailConfig::COMPANY_NAME . '</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                background-color: #f5f7fa;
                color: #2c3e50;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
            
            .email-container {
                max-width: 600px;
                margin: 20px auto;
                background-color: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            }
            
            .email-header {
                background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
                color: white;
                padding: 40px 30px;
                text-align: center;
                position: relative;
            }
            
            .email-header h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 10px;
                position: relative;
                z-index: 1;
            }
            
            .email-header p {
                font-size: 16px;
                opacity: 0.9;
                position: relative;
                z-index: 1;
            }
            
            .email-body {
                padding: 40px 30px;
            }
            
            .welcome-message {
                font-size: 24px;
                font-weight: 600;
                color: #2c3e50;
                margin-bottom: 20px;
            }
            
            .intro-text {
                font-size: 16px;
                color: #5a6c7d;
                margin-bottom: 30px;
            }
            
            .reset-button {
                display: inline-block;
                background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
                color: white;
                text-decoration: none;
                padding: 14px 28px;
                border-radius: 50px;
                font-weight: 600;
                font-size: 16px;
                text-align: center;
                margin: 20px 0;
                box-shadow: 0 4px 15px rgba(230, 0, 18, 0.3);
                transition: all 0.3s ease;
            }
            
            .reset-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(230, 0, 18, 0.4);
            }
            
            .security-notice {
                background-color: #fff8e1;
                border-left: 4px solid #ffc107;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
            }
            
            .security-notice h4 {
                font-size: 16px;
                font-weight: 600;
                color: #856404;
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .security-notice ul {
                padding-left: 20px;
                margin: 0;
            }
            
            .security-notice li {
                margin-bottom: 8px;
                color: #856404;
            }
            
            .contact-info {
                font-size: 16px;
                color: #5a6c7d;
                margin-bottom: 30px;
            }
            
            .contact-info a {
                color: #E60012;
                text-decoration: none;
                font-weight: 500;
            }
            
            .contact-info a:hover {
                text-decoration: underline;
            }
            
            .email-footer {
                background-color: #2c3e50;
                color: rgba(255, 255, 255, 0.7);
                padding: 30px;
                text-align: center;
                font-size: 14px;
            }
            
            .email-footer p {
                margin-bottom: 10px;
            }
            
            .email-footer a {
                color: rgba(255, 255, 255, 0.9);
                text-decoration: none;
            }
            
            .email-footer a:hover {
                text-decoration: underline;
            }
            
            .email-footer .company-name {
                font-weight: 600;
                color: white;
            }
            
            .email-footer .system-url {
                color: rgba(255, 255, 255, 0.7);
                font-size: 12px;
                margin-top: 15px;
            }
            
            .email-footer .recipient-info {
                color: rgba(255, 255, 255, 0.7);
                font-size: 12px;
                margin-top: 10px;
            }
            
            /* Responsive styles */
            @media only screen and (max-width: 600px) {
                .email-container {
                    width: 100%;
                    border-radius: 0;
                }
                
                .email-header {
                    padding: 30px 20px;
                }
                
                .email-header h1 {
                    font-size: 24px;
                }
                
                .email-body {
                    padding: 30px 20px;
                }
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                <h1>Password Reset Request</h1>
                <p>' . EmailConfig::COMPANY_NAME . '</p>
            </div>
            
            <div class="email-body">
                <h2 class="welcome-message">Hello,</h2>
                <p class="intro-text">
                    We received a request to reset the password for your ' . EmailConfig::COMPANY_NAME . ' account. 
                    If you made this request, please click the button below to set a new password.
                </p>
                
                <div style="text-align: center;">
                    <a href="' . $resetLink . '" class="reset-button">
                        Reset Password
                    </a>
                </div>
                
                <p style="text-align: center; margin: 20px 0; font-size: 14px; color: #666;">
                    Or copy and paste this link into your browser:<br>
                    <span style="word-break: break-all;">' . $resetLink . '</span>
                </p>
                
                <div class="security-notice">
                    <h4><i class="fas fa-shield-alt"></i> Security Information</h4>
                    <ul>
                        <li>This password reset link will expire in 1 hour for security reasons</li>
                        <li>If you did not request a password reset, please ignore this email or contact support</li>
                        <li>Never share your password with anyone</li>
                        <li>Always ensure you are on the official ' . EmailConfig::COMPANY_NAME . ' website before entering your credentials</li>
                    </ul>
                </div>
                
                <div class="contact-info">
                    If you have any questions or need assistance, please contact us at 
                    <a href="mailto:' . EmailConfig::SUPPORT_EMAIL . '">' . EmailConfig::SUPPORT_EMAIL . '</a>
                </div>
                
                <p>Best regards,<br>
                <strong>' . EmailConfig::COMPANY_NAME . ' Team</strong></p>
            </div>
            
            <div class="email-footer">
                <p>&copy; ' . date('Y') . ' <span class="company-name">' . EmailConfig::COMPANY_NAME . '</span>. All rights reserved.</p>
                <p>This is an automated message. Please do not reply to this email.</p>
                <div class="system-url">System URL: http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '</div>
                <div class="recipient-info">This email was sent to: ' . htmlspecialchars($recipientEmail) . '</div>
            </div>
        </div>
    </body>
    </html>
    ';
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
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.email@example.com">
            </div>
            
            <button type="submit" class="btn">Send Reset Link</button>
        </form>
        
        <div class="back-link">
            <p><a href="login.php">← Back to Login</a></p>
        </div>
    </div>
</body>
</html>
