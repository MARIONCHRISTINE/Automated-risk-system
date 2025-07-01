<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';

// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get current user info
$user = getCurrentUser();

// Debug: Ensure department is available
if (empty($user['department'])) {
    if (isset($_SESSION['department'])) {
        $user['department'] = $_SESSION['department'];
    } else {
        $dept_query = "SELECT department FROM users WHERE id = :user_id";
        $dept_stmt = $db->prepare($dept_query);
        $dept_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept_result) {
            $user['department'] = $dept_result['department'];
        }
    }
}

$success = '';
$error = '';

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = 'Risk reported successfully and assigned to you!';
}

// Function to handle file uploads
function handleFileUploads($files, $risk_id, $section_type, $db) {
    $upload_dir = 'uploads/risk_documents/';
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $uploaded_files = [];
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
    $max_size = 10 * 1024 * 1024; // 10MB
    
    if (isset($files['name']) && is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] == UPLOAD_ERR_OK) {
                $file_name = $files['name'][$i];
                $file_tmp = $files['tmp_name'][$i];
                $file_size = $files['size'][$i];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                // Validate file type
                if (!in_array($file_ext, $allowed_types)) {
                    throw new Exception("File type not allowed: $file_name");
                }
                
                // Validate file size
                if ($file_size > $max_size) {
                    throw new Exception("File too large: $file_name (max 10MB)");
                }
                
                // Generate unique filename
                $unique_name = $risk_id . '_' . $section_type . '_' . time() . '_' . $i . '.' . $file_ext;
                $file_path = $upload_dir . $unique_name;
                
                if (move_uploaded_file($file_tmp, $file_path)) {
                    // Save to database
                    $query = "INSERT INTO risk_documents (risk_id, section_type, original_filename, stored_filename, file_path, file_size, uploaded_by, uploaded_at) 
                              VALUES (:risk_id, :section_type, :original_filename, :stored_filename, :file_path, :file_size, :uploaded_by, NOW())";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':risk_id', $risk_id);
                    $stmt->bindParam(':section_type', $section_type);
                    $stmt->bindParam(':original_filename', $file_name);
                    $stmt->bindParam(':stored_filename', $unique_name);
                    $stmt->bindParam(':file_path', $file_path);
                    $stmt->bindParam(':file_size', $file_size);
                    $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
                    $stmt->execute();
                    
                    $uploaded_files[] = $file_name;
                } else {
                    throw new Exception("Failed to upload file: $file_name");
                }
            }
        }
    }
    
    return $uploaded_files;
}

// Handle comprehensive risk reporting
if ($_POST && isset($_POST['submit_comprehensive_risk'])) {
    try {
        $db->beginTransaction();
        
        // Validation
        if (empty($_POST['risk_name']) || empty($_POST['risk_description']) || empty($_POST['cause_of_risk'])) {
            throw new Exception('Risk Name, Description, and Cause are required fields');
        }
        
        $query = "INSERT INTO risk_incidents (
            risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id,
            existing_or_new, to_be_reported_to_board, risk_category,
            inherent_likelihood, inherent_consequence, inherent_rating,
            residual_likelihood, residual_consequence, residual_rating,
            treatment_action, controls_action_plans, target_completion_date,
            progress_update, treatment_status, risk_status,
            created_at, updated_at
        ) VALUES (
            :risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id,
            :existing_or_new, :to_be_reported_to_board, :risk_category,
            :inherent_likelihood, :inherent_consequence, :inherent_rating,
            :residual_likelihood, :residual_consequence, :residual_rating,
            :treatment_action, :controls_action_plans, :target_completion_date,
            :progress_update, :treatment_status, :risk_status,
            NOW(), NOW()
        )";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $_POST['risk_name']);
        $stmt->bindParam(':risk_description', $_POST['risk_description']);
        $stmt->bindParam(':cause_of_risk', $_POST['cause_of_risk']);
        $stmt->bindParam(':department', $_POST['department']);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']); // Auto-assign to self
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':to_be_reported_to_board', $_POST['to_be_reported_to_board']);
        $stmt->bindParam(':risk_category', $_POST['risk_category']);
        $stmt->bindParam(':inherent_likelihood', $_POST['inherent_likelihood']);
        $stmt->bindParam(':inherent_consequence', $_POST['inherent_consequence']);
        $stmt->bindParam(':inherent_rating', $_POST['inherent_rating']);
        $stmt->bindParam(':residual_likelihood', $_POST['residual_likelihood']);
        $stmt->bindParam(':residual_consequence', $_POST['residual_consequence']);
        $stmt->bindParam(':residual_rating', $_POST['residual_rating']);
        $stmt->bindParam(':treatment_action', $_POST['treatment_action']);
        $stmt->bindParam(':controls_action_plans', $_POST['controls_action_plans']);
        $stmt->bindParam(':target_completion_date', $_POST['target_completion_date']);
        $stmt->bindParam(':progress_update', $_POST['progress_update']);
        $stmt->bindParam(':treatment_status', $_POST['treatment_status']);
        $stmt->bindParam(':risk_status', $_POST['risk_status']);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            // Handle file uploads for Controls/Action Plans
            if (isset($_FILES['controls_documents']) && !empty($_FILES['controls_documents']['name'][0])) {
                $controls_files = handleFileUploads($_FILES['controls_documents'], $risk_id, 'controls_action_plans', $db);
            }
            
            // Handle file uploads for Progress Update
            if (isset($_FILES['progress_documents']) && !empty($_FILES['progress_documents']['name'][0])) {
                $progress_files = handleFileUploads($_FILES['progress_documents'], $risk_id, 'progress_update', $db);
            }
            
            $db->commit();
            // Redirect to clear form and show success message
            header("Location: report_risk.php?success=1");
            exit();
        } else {
            $db->rollback();
            $error = "Failed to report comprehensive risk.";
        }
    } catch (Exception $e) {
        $db->rollback();
        $error = 'Error: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report New Risk - Airtel Risk Management</title>
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
            padding-top: 100px;
        }
        
        /* Header - Same as dashboard */
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
        
        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-top: 4px solid #E60012;
        }
        
        .card-header {
            padding: 2rem 2rem 1rem 2rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .section-header {
            background: #E60012;
            color: white;
            padding: 0.75rem 1rem;
            margin: 2rem 0 1rem 0;
            border-radius: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
        }
        
        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 0.25rem;
        }
        
        .progress-step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .progress-step.active {
            background: #E60012;
            color: white;
        }
        
        .progress-step.completed {
            background: #28a745;
            color: white;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 1rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea.form-control {
            height: 100px;
            resize: vertical;
        }
        
        /* File Upload Styles */
        .file-upload-section {
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 0.25rem;
    padding: 0.5rem;
    margin-top: 0.5rem;
    transition: all 0.3s;
}

.file-upload-section:hover {
    border-color: #E60012;
    background: #fff5f5;
}

.file-upload-section.dragover {
    border-color: #E60012;
    background: #fff5f5;
    transform: scale(1.01);
}

.file-input-wrapper {
    position: relative;
    display: inline-block;
    width: 100%;
}

.file-input {
    position: absolute;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
}

.file-input-label {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.4rem;
    border: 1px dashed #ccc;
    border-radius: 0.25rem;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
    text-align: center;
    font-size: 0.85rem;
}

.file-input-label:hover {
    border-color: #E60012;
    background: #fff5f5;
}

.file-list {
    margin-top: 0.5rem;
}

.file-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.4rem;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    margin-bottom: 0.4rem;
    font-size: 0.85rem;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.file-name {
    font-weight: 500;
}

.file-size {
    color: #666;
    font-size: 0.8rem;
}

.file-remove {
    background: #dc3545;
    color: white;
    border: none;
    padding: 0.2rem 0.4rem;
    border-radius: 0.2rem;
    cursor: pointer;
    font-size: 0.75rem;
}

.file-remove:hover {
    background: #c82333;
}

.file-types-info {
    font-size: 0.75rem;
    color: #666;
    margin-top: 0.4rem;
    text-align: center;
}
        
        .risk-matrix {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .matrix-section {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            background: #f8f9fa;
        }
        
        .matrix-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: #E60012;
            text-align: center;
        }
        
        .rating-display {
            text-align: center;
            padding: 0.5rem;
            margin-top: 0.5rem;
            border-radius: 0.25rem;
            font-weight: bold;
        }
        
        .rating-low { background: #d4edda; color: #155724; }
        .rating-medium { background: #fff3cd; color: #856404; }
        .rating-high { background: #f8d7da; color: #721c24; }
        .rating-critical { background: #721c24; color: white; }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        /* Responsive */
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .risk-matrix {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Airtel Risk Register System</h1>
                    <p class="sub-title">Risk Management System</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : 'No Email'; ?></div>
                    <div class="user-role">Risk_owner • <?php echo htmlspecialchars($user['department'] ?? 'No Department'); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Risk Registration Form</h2>
                <div>
                    <a href="risk_owner_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
            <div class="card-body">
                
                <!-- Progress Indicator -->
                <div class="progress-indicator">
                    <div class="progress-step active">1. Identify</div>
                    <div class="progress-step">2. Assess</div>
                    <div class="progress-step">3. Treat</div>
                    <div class="progress-step">4. Monitor</div>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" id="riskForm" enctype="multipart/form-data">
                    
                    <!-- RISK IDENTIFICATION Section -->
                    <div class="section-header">
                        📋 Step 1: Risk Identification
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Existing or New Risk *
                            </label>
                            <select name="existing_or_new" class="form-control" required>
                                <option value="">Select...</option>
                                <option value="Existing" <?php echo (isset($_POST['existing_or_new']) && $_POST['existing_or_new'] == 'Existing') ? 'selected' : ''; ?>>Existing Risk</option>
                                <option value="New" <?php echo (isset($_POST['existing_or_new']) && $_POST['existing_or_new'] == 'New') ? 'selected' : ''; ?>>New Risk</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                To be Reported to Board *
                            </label>
                            <select name="to_be_reported_to_board" class="form-control" required>
                                <option value="">Select...</option>
                                <option value="Yes" <?php echo (isset($_POST['to_be_reported_to_board']) && $_POST['to_be_reported_to_board'] == 'Yes') ? 'selected' : ''; ?>>Yes - Board Attention Required</option>
                                <option value="No" <?php echo (isset($_POST['to_be_reported_to_board']) && $_POST['to_be_reported_to_board'] == 'No') ? 'selected' : ''; ?>>No - Operational Level</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Risk Name *
                        </label>
                        <input type="text" name="risk_name" class="form-control" required 
                               placeholder="e.g., Customer Data Breach, Regulatory Non-Compliance, System Downtime"
                               value="<?php echo isset($_POST['risk_name']) ? htmlspecialchars($_POST['risk_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Risk Description *
                        </label>
                        <textarea name="risk_description" class="form-control" rows="4" required 
                                  placeholder="Describe what could go wrong, the potential impact, and who/what would be affected..."><?php echo isset($_POST['risk_description']) ? htmlspecialchars($_POST['risk_description']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Cause of Risk *
                        </label>
                        <textarea name="cause_of_risk" class="form-control" rows="3" required 
                                  placeholder="What factors or conditions could cause this risk to occur?"><?php echo isset($_POST['cause_of_risk']) ? htmlspecialchars($_POST['cause_of_risk']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Risk Category
                        </label>
                        <select name="risk_category" class="form-control">
                            <option value="">Select Category (Optional)</option>
                            <option value="Strategic Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Strategic Risk') ? 'selected' : ''; ?>>Strategic Risk</option>
                            <option value="Operational Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Operational Risk') ? 'selected' : ''; ?>>Operational Risk</option>
                            <option value="Financial Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Financial Risk') ? 'selected' : ''; ?>>Financial Risk</option>
                            <option value="Compliance Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Compliance Risk') ? 'selected' : ''; ?>>Compliance Risk</option>
                            <option value="Technology Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Technology Risk') ? 'selected' : ''; ?>>Technology Risk</option>
                            <option value="Reputational Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Reputational Risk') ? 'selected' : ''; ?>>Reputational Risk</option>
                            <option value="Market Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Market Risk') ? 'selected' : ''; ?>>Market Risk</option>
                            <option value="Credit Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Credit Risk') ? 'selected' : ''; ?>>Credit Risk</option>
                            <option value="Liquidity Risk" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Liquidity Risk') ? 'selected' : ''; ?>>Liquidity Risk</option>
                            <option value="Other" <?php echo (isset($_POST['risk_category']) && $_POST['risk_category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" required value="<?php echo $user['department'] ?? ''; ?>" readonly style="background: #e9ecef;">
                    </div>
                    
                    <!-- RISK ASSESSMENT Section -->
                    <div class="section-header">
                        📊 Step 2: Risk Assessment
                    </div>
                    
                    <div class="risk-matrix">
                        <div class="matrix-section">
                            <div class="matrix-title">Inherent Risk (Gross Risk)</div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Likelihood (L) *
                                </label>
                                <select name="inherent_likelihood" class="form-control" required onchange="calculateRating('inherent')">
                                    <option value="">Select Likelihood</option>
                                    <option value="1" <?php echo (isset($_POST['inherent_likelihood']) && $_POST['inherent_likelihood'] == '1') ? 'selected' : ''; ?>>1 - Very Low (Rare, &lt;5% chance)</option>
                                    <option value="2" <?php echo (isset($_POST['inherent_likelihood']) && $_POST['inherent_likelihood'] == '2') ? 'selected' : ''; ?>>2 - Low (Unlikely, 5-25% chance)</option>
                                    <option value="3" <?php echo (isset($_POST['inherent_likelihood']) && $_POST['inherent_likelihood'] == '3') ? 'selected' : ''; ?>>3 - Medium (Possible, 25-50% chance)</option>
                                    <option value="4" <?php echo (isset($_POST['inherent_likelihood']) && $_POST['inherent_likelihood'] == '4') ? 'selected' : ''; ?>>4 - High (Likely, 50-75% chance)</option>
                                    <option value="5" <?php echo (isset($_POST['inherent_likelihood']) && $_POST['inherent_likelihood'] == '5') ? 'selected' : ''; ?>>5 - Very High (Almost certain, &gt;75% chance)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Consequence (C) *
                                </label>
                                <select name="inherent_consequence" class="form-control" required onchange="calculateRating('inherent')">
                                    <option value="">Select Consequence</option>
                                    <option value="1" <?php echo (isset($_POST['inherent_consequence']) && $_POST['inherent_consequence'] == '1') ? 'selected' : ''; ?>>1 - Very Low (Minimal impact)</option>
                                    <option value="2" <?php echo (isset($_POST['inherent_consequence']) && $_POST['inherent_consequence'] == '2') ? 'selected' : ''; ?>>2 - Low (Minor impact, easily manageable)</option>
                                    <option value="3" <?php echo (isset($_POST['inherent_consequence']) && $_POST['inherent_consequence'] == '3') ? 'selected' : ''; ?>>3 - Medium (Moderate impact, management attention required)</option>
                                    <option value="4" <?php echo (isset($_POST['inherent_consequence']) && $_POST['inherent_consequence'] == '4') ? 'selected' : ''; ?>>4 - High (Significant impact on operations)</option>
                                    <option value="5" <?php echo (isset($_POST['inherent_consequence']) && $_POST['inherent_consequence'] == '5') ? 'selected' : ''; ?>>5 - Very High (Severe impact, threatens business continuity)</option>
                                </select>
                            </div>
                            
                            <input type="hidden" name="inherent_rating" id="inherent_rating">
                            <div id="inherent_rating_display" class="rating-display">Rating: -</div>
                        </div>
                        
                        <div class="matrix-section">
                            <div class="matrix-title">Residual Risk (Net Risk)</div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Likelihood (L) *
                                </label>
                                <select name="residual_likelihood" class="form-control" required onchange="calculateRating('residual')">
                                    <option value="">Select Likelihood</option>
                                    <option value="1" <?php echo (isset($_POST['residual_likelihood']) && $_POST['residual_likelihood'] == '1') ? 'selected' : ''; ?>>1 - Very Low (Rare, &lt;5% chance)</option>
                                    <option value="2" <?php echo (isset($_POST['residual_likelihood']) && $_POST['residual_likelihood'] == '2') ? 'selected' : ''; ?>>2 - Low (Unlikely, 5-25% chance)</option>
                                    <option value="3" <?php echo (isset($_POST['residual_likelihood']) && $_POST['residual_likelihood'] == '3') ? 'selected' : ''; ?>>3 - Medium (Possible, 25-50% chance)</option>
                                    <option value="4" <?php echo (isset($_POST['residual_likelihood']) && $_POST['residual_likelihood'] == '4') ? 'selected' : ''; ?>>4 - High (Likely, 50-75% chance)</option>
                                    <option value="5" <?php echo (isset($_POST['residual_likelihood']) && $_POST['residual_likelihood'] == '5') ? 'selected' : ''; ?>>5 - Very High (Almost certain, &gt;75% chance)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Consequence (C) *
                                </label>
                                <select name="residual_consequence" class="form-control" required onchange="calculateRating('residual')">
                                    <option value="">Select Consequence</option>
                                    <option value="1" <?php echo (isset($_POST['residual_consequence']) && $_POST['residual_consequence'] == '1') ? 'selected' : ''; ?>>1 - Very Low (Minimal impact)</option>
                                    <option value="2" <?php echo (isset($_POST['residual_consequence']) && $_POST['residual_consequence'] == '2') ? 'selected' : ''; ?>>2 - Low (Minor impact, easily manageable)</option>
                                    <option value="3" <?php echo (isset($_POST['residual_consequence']) && $_POST['residual_consequence'] == '3') ? 'selected' : ''; ?>>3 - Medium (Moderate impact, management attention required)</option>
                                    <option value="4" <?php echo (isset($_POST['residual_consequence']) && $_POST['residual_consequence'] == '4') ? 'selected' : ''; ?>>4 - High (Significant impact on operations)</option>
                                    <option value="5" <?php echo (isset($_POST['residual_consequence']) && $_POST['residual_consequence'] == '5') ? 'selected' : ''; ?>>5 - Very High (Severe impact, threatens business continuity)</option>
                                </select>
                            </div>
                            
                            <input type="hidden" name="residual_rating" id="residual_rating">
                            <div id="residual_rating_display" class="rating-display">Rating: -</div>
                        </div>
                    </div>
                    
                    <!-- RISK TREATMENT Section -->
                    <div class="section-header">
                        🛡️ Step 3: Risk Treatment
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Treatment Action
                        </label>
                        <textarea name="treatment_action" class="form-control" rows="3" 
                                  placeholder="Describe the treatment strategy and approach..."><?php echo isset($_POST['treatment_action']) ? htmlspecialchars($_POST['treatment_action']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Controls / Action Plans
                        </label>
                        <textarea name="controls_action_plans" class="form-control" rows="4" 
                                  placeholder="List specific actions, controls, timelines, and resources required..."><?php echo isset($_POST['controls_action_plans']) ? htmlspecialchars($_POST['controls_action_plans']) : ''; ?></textarea>
                        
                        <!-- File Upload Section for Controls/Action Plans -->
                        <div class="file-upload-section">
                            <div class="file-input-wrapper">
                                <input type="file" name="controls_documents[]" id="controls_documents" class="file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png">
                                <label for="controls_documents" class="file-input-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Click to upload or drag and drop files here</span>
                                </label>
                            </div>
                            <div id="controls_file_list" class="file-list"></div>
                            <div class="file-types-info">
                                Supported formats: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, JPG, PNG (Max 10MB per file)
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Target Completion Date
                        </label>
                        <input type="date" name="target_completion_date" class="form-control" 
                               value="<?php echo isset($_POST['target_completion_date']) ? $_POST['target_completion_date'] : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Risk Owner</label>
                        <input type="text" class="form-control" value="<?php echo $_SESSION['full_name'] ?? $_SESSION['email']; ?> (You)" readonly style="background: #e9ecef;">
                    </div>
                    
                    <!-- MONITORING & REVIEW Section -->
                    <div class="section-header">
                        📈 Step 4: Monitoring & Review
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Progress Update
                        </label>
                        <textarea name="progress_update" class="form-control" rows="3" 
                                  placeholder="Provide current status, progress updates, or initial assessment..."><?php echo isset($_POST['progress_update']) ? htmlspecialchars($_POST['progress_update']) : ''; ?></textarea>
                        
                        <!-- File Upload Section for Progress Update -->
                        <div class="file-upload-section">
                            <div class="file-input-wrapper">
                                <input type="file" name="progress_documents[]" id="progress_documents" class="file-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png">
                                <label for="progress_documents" class="file-input-label">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span>Click to upload or drag and drop files here</span>
                                </label>
                            </div>
                            <div id="progress_file_list" class="file-list"></div>
                            <div class="file-types-info">
                                Supported formats: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, JPG, PNG (Max 10MB per file)
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Treatment Status
                            </label>
                            <select name="treatment_status" class="form-control">
                                <option value="Not Started" <?php echo (!isset($_POST['treatment_status']) || $_POST['treatment_status'] == 'Not Started') ? 'selected' : ''; ?>>Not Started</option>
                                <option value="In Progress" <?php echo (isset($_POST['treatment_status']) && $_POST['treatment_status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="On Hold" <?php echo (isset($_POST['treatment_status']) && $_POST['treatment_status'] == 'On Hold') ? 'selected' : ''; ?>>On Hold</option>
                                <option value="Completed" <?php echo (isset($_POST['treatment_status']) && $_POST['treatment_status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo (isset($_POST['treatment_status']) && $_POST['treatment_status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                Overall Risk Status
                            </label>
                            <select name="risk_status" class="form-control">
                                <option value="pending" <?php echo (!isset($_POST['risk_status']) || $_POST['risk_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="in_progress" <?php echo (isset($_POST['risk_status']) && $_POST['risk_status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo (isset($_POST['risk_status']) && $_POST['risk_status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="on_hold" <?php echo (isset($_POST['risk_status']) && $_POST['risk_status'] == 'on_hold') ? 'selected' : ''; ?>>On Hold</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 3rem; padding-top: 2rem; border-top: 2px solid #dee2e6;">
                        <a href="risk_owner_dashboard.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="submit_comprehensive_risk" class="btn" style="padding: 0.75rem 2rem;">
                            📝 Add Risk to Register
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // File upload handling
        function setupFileUpload(inputId, listId) {
            const fileInput = document.getElementById(inputId);
            const fileList = document.getElementById(listId);
            const uploadSection = fileInput.closest('.file-upload-section');
            
            let selectedFiles = [];
            
            fileInput.addEventListener('change', function(e) {
                handleFiles(e.target.files);
            });
            
            // Drag and drop functionality
            uploadSection.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadSection.classList.add('dragover');
            });
            
            uploadSection.addEventListener('dragleave', function(e) {
                e.preventDefault();
                uploadSection.classList.remove('dragover');
            });
            
            uploadSection.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadSection.classList.remove('dragover');
                handleFiles(e.dataTransfer.files);
            });
            
            function handleFiles(files) {
                for (let file of files) {
                    if (validateFile(file)) {
                        selectedFiles.push(file);
                    }
                }
                updateFileList();
                updateFileInput();
            }
            
            function validateFile(file) {
                const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                    'text/plain', 'image/jpeg', 'image/jpg', 'image/png'];
                const maxSize = 10 * 1024 * 1024; // 10MB
                
                if (!allowedTypes.includes(file.type)) {
                    alert(`File type not allowed: ${file.name}`);
                    return false;
                }
                
                if (file.size > maxSize) {
                    alert(`File too large: ${file.name} (max 10MB)`);
                    return false;
                }
                
                return true;
            }
            
            function updateFileList() {
                fileList.innerHTML = '';
                selectedFiles.forEach((file, index) => {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.innerHTML = `
                        <div class="file-info">
                            <i class="fas fa-file"></i>
                            <span class="file-name">${file.name}</span>
                            <span class="file-size">(${formatFileSize(file.size)})</span>
                        </div>
                        <button type="button" class="file-remove" onclick="removeFile(${index}, '${inputId}', '${listId}')">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    fileList.appendChild(fileItem);
                });
            }
            
            function updateFileInput() {
                const dt = new DataTransfer();
                selectedFiles.forEach(file => dt.items.add(file));
                fileInput.files = dt.files;
            }
            
            // Store reference for removal function
            window[`selectedFiles_${inputId}`] = selectedFiles;
            window[`updateFileList_${inputId}`] = updateFileList;
            window[`updateFileInput_${inputId}`] = updateFileInput;
        }
        
        function removeFile(index, inputId, listId) {
            const selectedFiles = window[`selectedFiles_${inputId}`];
            const updateFileList = window[`updateFileList_${inputId}`];
            const updateFileInput = window[`updateFileInput_${inputId}`];
            
            selectedFiles.splice(index, 1);
            updateFileList();
            updateFileInput();
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function calculateRating(type) {
            const likelihood = document.querySelector(`select[name="${type}_likelihood"]`).value;
            const consequence = document.querySelector(`select[name="${type}_consequence"]`).value;
            const display = document.getElementById(`${type}_rating_display`);
            const hiddenInput = document.getElementById(`${type}_rating`);
            
            if (likelihood && consequence) {
                const rating = parseInt(likelihood) * parseInt(consequence);
                let ratingClass = 'rating-low';
                let ratingText = 'Low';
                
                if (rating >= 15) {
                    ratingClass = 'rating-critical';
                    ratingText = 'Critical';
                } else if (rating >= 9) {
                    ratingClass = 'rating-high';
                    ratingText = 'High';
                } else if (rating >= 4) {
                    ratingClass = 'rating-medium';
                    ratingText = 'Medium';
                }
                
                display.className = `rating-display ${ratingClass}`;
                display.textContent = `Rating: ${rating} (${ratingText})`;
                hiddenInput.value = rating;
                
                // Update progress indicator
                updateProgressIndicator();
            } else {
                display.className = 'rating-display';
                display.textContent = 'Rating: -';
                hiddenInput.value = '';
            }
        }
        
        function updateProgressIndicator() {
            const steps = document.querySelectorAll('.progress-step');
            const form = document.getElementById('riskForm');
            
            // Check completion of each section
            const riskName = form.querySelector('[name="risk_name"]').value;
            const inherentL = form.querySelector('[name="inherent_likelihood"]').value;
            const inherentC = form.querySelector('[name="inherent_consequence"]').value;
            const residualL = form.querySelector('[name="residual_likelihood"]').value;
            const residualC = form.querySelector('[name="residual_consequence"]').value;
            
            // Update step indicators
            steps[0].className = 'progress-step ' + (riskName ? 'completed' : 'active');
            steps[1].className = 'progress-step ' + (inherentL && inherentC && residualL && residualC ? 'completed' : (riskName ? 'active' : ''));
            steps[2].className = 'progress-step ' + (inherentL && inherentC && residualL && residualC ? 'active' : '');
            steps[3].className = 'progress-step';
        }
        
        // Initialize everything on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Setup file uploads
            setupFileUpload('controls_documents', 'controls_file_list');
            setupFileUpload('progress_documents', 'progress_file_list');
            
            // Calculate ratings on page load if values exist
            calculateRating('inherent');
            calculateRating('residual');
            updateProgressIndicator();
            
            // Add event listeners for progress tracking
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', updateProgressIndicator);
                input.addEventListener('input', updateProgressIndicator);
            });
        });
        
        // Form validation before submit
        document.getElementById('riskForm').addEventListener('submit', function(e) {
            const requiredFields = ['risk_name', 'risk_description', 'cause_of_risk', 'inherent_likelihood', 'inherent_consequence', 'residual_likelihood', 'residual_consequence'];
            let missingFields = [];
            
            requiredFields.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (!input.value.trim()) {
                    missingFields.push(input.previousElementSibling.textContent.replace(' *', ''));
                }
            });
            
            if (missingFields.length > 0) {
                e.preventDefault();
                alert('Please complete the following required fields:\n\n• ' + missingFields.join('\n• '));
                return false;
            }
        });
    </script>
</body>
</html>
