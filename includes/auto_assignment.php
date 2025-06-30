<?php
include_once 'includes/auth.php';
requireRole('staff');
include_once 'config/database.php';
include_once 'includes/auto_assignment.php'; // Include the auto-assignment functions

// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Handle risk reporting with automatic assignment
if ($_POST && isset($_POST['submit_risk'])) {
    $risk_name = $_POST['risk_name'];
    $risk_description = $_POST['risk_description'];
    $cause_of_risk = $_POST['cause_of_risk'];
    $department = $_POST['department'];
    $existing_or_new = $_POST['existing_or_new'];
    $to_be_reported_to_board = $_POST['to_be_reported_to_board'];
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Insert the risk without assignment first
        $query = "INSERT INTO risk_incidents (risk_name, risk_description, cause_of_risk, department, reported_by, existing_or_new, to_be_reported_to_board, risk_status) 
                  VALUES (:risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :existing_or_new, :to_be_reported_to_board, 'pending')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $risk_description);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk);
        $stmt->bindParam(':department', $department);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':existing_or_new', $existing_or_new);
        $stmt->bindParam(':to_be_reported_to_board', $to_be_reported_to_board);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            // Automatically assign to a risk owner in the same department
            $assignment_result = assignRiskAutomatically($risk_id, $department, $db);
            
            if ($assignment_result['success']) {
                $db->commit();
                $success_message = "✅ Risk reported successfully and automatically assigned to " . 
                                 htmlspecialchars($assignment_result['owner_name']) . 
                                 " (" . htmlspecialchars($assignment_result['owner_email']) . ")";
            } else {
                $db->commit(); // Still save the risk, but without assignment
                $success_message = "✅ Risk reported successfully! " . $assignment_result['message'] . 
                                 ". The risk will need to be manually assigned by an administrator.";
            }
        } else {
            $db->rollback();
            $error_message = "Failed to report risk. Please try again.";
        }
    } catch (PDOException $e) {
        $db->rollback();
        $error_message = "Failed to report risk: " . $e->getMessage();
    }
}

// Get current user info
$user = getCurrentUser();

// Remove this section:
// Get user's department assignment statistics
$dept_stats = [];
if ($user['department']) {
    $dept_stats = getDepartmentAssignmentStats($user['department'], $db);
}

// Get user's reported risks
$query = "SELECT r.*, u.full_name as owner_name
          FROM risk_incidents r 
          LEFT JOIN users u ON r.risk_owner_id = u.id 
          WHERE r.reported_by = :user_id
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$my_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department statistics
$stats = [];

// Total risks reported by this user
$stats['my_total_risks'] = count($my_risks);

// Risks assigned in user's department
$query = "SELECT COUNT(*) as total FROM risk_incidents WHERE department = :department AND risk_owner_id IS NOT NULL";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$stats['dept_assigned_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Unassigned risks in user's department
$query = "SELECT COUNT(*) as total FROM risk_incidents WHERE department = :department AND risk_owner_id IS NULL";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$stats['dept_unassigned_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Risk owners in user's department
$query = "SELECT COUNT(*) as total FROM users WHERE role = 'risk_owner' AND department = :department AND status = 'active'";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$stats['dept_risk_owners'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

function getRiskLevel($probability, $impact) {
    if (!$probability || !$impact) return 'not-assessed';
    $rating = (int)$probability * (int)$impact;
    if ($rating >= 15) return 'critical';
    if ($rating >= 9) return 'high';
    if ($rating >= 4) return 'medium';
    return 'low';
}

function getRiskLevelText($probability, $impact) {
    if (!$probability || !$impact) return 'Not Assessed';
    $rating = (int)$probability * (int)$impact;
    if ($rating >= 15) return 'Critical';
    if ($rating >= 9) return 'High';
    if ($rating >= 4) return 'Medium';
    return 'Low';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Airtel Risk Management</title>
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
        
        /* Header - Same as risk owner dashboard */
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
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            color: #333;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .dashboard-grid {
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
        
        .stat-description {
            color: #999;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        
        .btn-primary {
            background: #007bff;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 1rem 2rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
            margin-bottom: 2rem;
        }
        
        .cta-button:hover {
            background: #B8000E;
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
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #17a2b8;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            border-top: 4px solid #E60012;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 10px 10px 0 0;
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
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 1rem;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea {
            height: 100px;
            resize: vertical;
        }
        
        .classification-section {
            background: #fff5f5;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #E60012;
            margin-bottom: 1rem;
        }
        
        .classification-section h4 {
            color: #E60012;
            margin-bottom: 1rem;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        
        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid #dee2e6;
            text-align: left;
        }
        
        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #dee2e6;
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .risk-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .risk-low { background: #d4edda; color: #155724; }
        .risk-medium { background: #fff3cd; color: #856404; }
        .risk-high { background: #ffebee; color: #E60012; }
        .risk-critical { background: #ffcdd2; color: #E60012; }
        .risk-not-assessed { background: #e2e3e5; color: #383d41; }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-in-progress { background: #cce5ff; color: #004085; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .badge-warning { background: #fff3cd; color: #856404; }
        
        .text-muted {
            color: #6c757d;
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
            
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modal-body {
                padding: 1.5rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
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
                    <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'S'; ?></div>
                    <div class="user-details">
                        <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : 'No Email'; ?></div>
                        <div class="user-role">Staff • <?php echo $user['department'] ?? 'General'; ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="main-content">
            <?php if (isset($success_message)): ?>
                <div class="success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <!-- Report Risk Button -->
            <button class="cta-button" onclick="openReportModal()">
                <i class="fas fa-plus"></i>
                Report New Risk
            </button>
            
            <!-- Statistics Cards -->
            <div class="dashboard-grid">
                <div class="stat-card">
                    <span class="stat-number"><?php echo $stats['my_total_risks']; ?></span>
                    <div class="stat-label">My Reported Risks</div>
                    <div class="stat-description">Total risks you have reported</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $stats['dept_assigned_risks']; ?></span>
                    <div class="stat-label">Department Assigned</div>
                    <div class="stat-description">Risks assigned in <?php echo $user['department']; ?></div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $stats['dept_unassigned_risks']; ?></span>
                    <div class="stat-label">Pending Assignment</div>
                    <div class="stat-description">Awaiting assignment</div>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $stats['dept_risk_owners']; ?></span>
                    <div class="stat-label">Available Risk Owners</div>
                    <div class="stat-description">In <?php echo $user['department']; ?> department</div>
                </div>
            </div>

            <!-- Auto-Assignment Information -->
            <!-- Remove the entire Auto-Assignment Information card section -->

            <!-- My Reported Risks -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📋 My Reported Risks</h3>
                    <span class="badge badge-info"><?php echo count($my_risks); ?> risks reported</span>
                </div>
                
                <?php if (empty($my_risks)): ?>
                    <div class="alert-info">
                        <p><strong>No risks reported yet.</strong></p>
                        <p>Click "Report New Risk" above to report your first risk. It will be automatically assigned to a risk owner in your department.</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Risk Name</th>
                                <th>Risk Level</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Reported Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($my_risks as $risk): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($risk['risk_name']); ?></strong>
                                    <?php if ($risk['risk_description']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($risk['risk_description'], 0, 100)) . (strlen($risk['risk_description']) > 100 ? '...' : ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="risk-badge risk-<?php echo getRiskLevel($risk['probability'] ?? 0, $risk['impact'] ?? 0); ?>">
                                        <?php echo getRiskLevelText($risk['probability'] ?? 0, $risk['impact'] ?? 0); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo str_replace('_', '-', $risk['risk_status'] ?? 'pending'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $risk['risk_status'] ?? 'Pending')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($risk['owner_name']): ?>
                                        <span class="badge badge-success"><?php echo htmlspecialchars($risk['owner_name']); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($risk['created_at'])); ?></td>
                                <td>
                                    <a href="view_risk.php?id=<?php echo $risk['id']; ?>" class="btn btn-primary">View Details</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- Risk Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report New Risk</h3>
                <button class="close" onclick="closeModal('reportModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="alert-info" style="margin-bottom: 1.5rem;">
                    <strong>ℹ️ Note:</strong> Your risk will be automatically assigned to a risk owner in your department.
                </div>
                
                <form method="POST">
                    <div class="classification-section">
                        <h4>Risk Classification</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="existing_or_new">Risk Type</label>
                                <select id="existing_or_new" name="existing_or_new" required>
                                    <option value="New">New Risk</option>
                                    <option value="Existing">Existing Risk</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="to_be_reported_to_board">Report to Board</label>
                                <select id="to_be_reported_to_board" name="to_be_reported_to_board" required>
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_name">Risk Name</label>
                        <input type="text" id="risk_name" name="risk_name" required placeholder="Enter a clear risk title">
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_description">Risk Description</label>
                        <textarea id="risk_description" name="risk_description" required placeholder="Describe the risk in detail"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="cause_of_risk">Cause of Risk</label>
                        <textarea id="cause_of_risk" name="cause_of_risk" required placeholder="What causes this risk?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" required value="<?php echo $user['department'] ?? ''; ?>" readonly>
                        <small style="color: #666;">Risk will be assigned to a risk owner in this department</small>
                    </div>
                    
                    <button type="submit" name="submit_risk" class="btn">Submit Risk Report</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openReportModal() {
            document.getElementById('reportModal').classList.add('show');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
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
    </script>
</body>
</html>
