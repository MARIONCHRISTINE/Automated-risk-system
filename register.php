<?php
session_start();
include_once 'config/database.php';

// Include compatibility functions if file exists
if (file_exists('includes/php_compatibility.php')) {
    include_once 'includes/php_compatibility.php';
} else {
    // Define essential functions inline if include fails
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
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = $_POST['full_name'];
    $role = $_POST['role'];
    $department = $_POST['department'];
    
    // Validate email domain - using compatible function
    if (!endsWith($email, '@airtel.africa')) {
        $error = "Please use your Airtel email address ending with @airtel.africa";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (empty($role)) {
        $error = "Please select your role.";
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if email already exists
        $check_query = "SELECT id FROM users WHERE email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $error = "Email address already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Admin accounts are auto-approved, others need approval
            $status = ($role === 'admin') ? 'approved' : 'pending';
            
            $query = "INSERT INTO users (email, password, full_name, role, department, status) VALUES (:email, :password, :full_name, :role, :department, :status)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':department', $department);
            $stmt->bindParam(':status', $status);
            
            if ($stmt->execute()) {
                if ($role === 'admin') {
                    $message = "Admin account created successfully! You can now login.";
                } else {
                    $message = "Registration successful! Your account is pending approval by the administrator.";
                }
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Airtel Risk Management</title>
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
            padding: 2rem 0;
        }
        
        .register-container {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(230, 0, 18, 0.2);
            width: 100%;
            max-width: 500px;
            border-top: 5px solid #E60012;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .airtel-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            display: block;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(230, 0, 18, 0.2);
        }
        
        .logo h1 {
            color: #E60012;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
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
        
        input, select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .role-selection {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .role-card {
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .role-card:hover {
            border-color: #E60012;
            background: #fff5f5;
        }
        
        .role-card.selected {
            border-color: #E60012;
            background: #fff5f5;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .role-card input[type="radio"] {
            display: none;
        }
        
        .role-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .role-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }
        
        .role-desc {
            font-size: 0.8rem;
            color: #666;
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
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .login-link a {
            color: #E60012;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .php-version-info {
            background: #fff5f5;
            color: #E60012;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
            text-align: center;
            border: 1px solid #ffcdd2;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Join Airtel Risk Management</h1>
            <p>Create your account to get started</p>
        </div>
        
        <div class="php-version-info">
            Running PHP <?php echo phpversion(); ?> - System Ready ✓
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="email">Airtel Email Address</label>
                <input type="email" id="email" name="email" required placeholder="your.name@airtel.africa" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="department">Department</label>
                <select id="department" name="department" required>
                    <option value="">Select Department</option>
                    <option value="Airtel Money" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Airtel Money') ? 'selected' : ''; ?>>Airtel Money</option>
                    <option value="IT & Technology" <?php echo (isset($_POST['department']) && $_POST['department'] === 'IT & Technology') ? 'selected' : ''; ?>>IT & Technology</option>
                    <option value="Finance" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                    <option value="Operations" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Operations') ? 'selected' : ''; ?>>Operations</option>
                    <option value="Risk & Compliance" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Risk & Compliance') ? 'selected' : ''; ?>>Risk & Compliance</option>
                    <option value="Human Resources" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Human Resources') ? 'selected' : ''; ?>>Human Resources</option>
                    <option value="Marketing" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                    <option value="Customer Service" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Customer Service') ? 'selected' : ''; ?>>Customer Service</option>
                    <option value="Network" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Network') ? 'selected' : ''; ?>>Network</option>
                    <option value="Legal" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Legal') ? 'selected' : ''; ?>>Legal</option>
                    <option value="Other" <?php echo (isset($_POST['department']) && $_POST['department'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Select Your Role</label>
                <div class="role-selection">
                    <label class="role-card" for="role_staff">
                        <input type="radio" id="role_staff" name="role" value="staff" <?php echo (isset($_POST['role']) && $_POST['role'] === 'staff') ? 'checked' : ''; ?>>
                        <span class="role-icon">👤</span>
                        <div class="role-title">Staff</div>
                        <div class="role-desc">Report risks and view status</div>
                    </label>
                    
                    <label class="role-card" for="role_risk_owner">
                        <input type="radio" id="role_risk_owner" name="role" value="risk_owner" <?php echo (isset($_POST['role']) && $_POST['role'] === 'risk_owner') ? 'checked' : ''; ?>>
                        <span class="role-icon">🎯</span>
                        <div class="role-title">Risk Owner</div>
                        <div class="role-desc">Manage department risks</div>
                    </label>
                    
                    <label class="role-card" for="role_compliance">
                        <input type="radio" id="role_compliance" name="role" value="compliance" <?php echo (isset($_POST['role']) && $_POST['role'] === 'compliance') ? 'checked' : ''; ?>>
                        <span class="role-icon">📊</span>
                        <div class="role-title">Compliance</div>
                        <div class="role-desc">Generate reports & analytics</div>
                    </label>
                    
                    <label class="role-card" for="role_admin">
                        <input type="radio" id="role_admin" name="role" value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'checked' : ''; ?>>
                        <span class="role-icon">⚙️</span>
                        <div class="role-title">Administrator</div>
                        <div class="role-desc">System management</div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="6" placeholder="Minimum 6 characters">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter your password">
            </div>
            
            <button type="submit" class="btn">Create Account</button>
        </form>
        
        <div class="login-link">
            <p>Already have an account? <a href="login.php">Sign in here</a></p>
        </div>
    </div>
    
    <script>
        // Handle role card selection
        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
                
                // Add selected class to clicked card
                this.classList.add('selected');
                
                // Check the radio button
                this.querySelector('input[type="radio"]').checked = true;
            });
        });
        
        // Set initial selected state
        document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
            radio.closest('.role-card').classList.add('selected');
        });
    </script>
</body>
</html>
