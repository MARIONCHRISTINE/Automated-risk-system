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

// Handle success message from risk edit
if (isset($_GET['updated']) && isset($_GET['risk_id'])) {
    $updated_risk_id = (int)$_GET['risk_id'];
    $treatments_count = isset($_GET['treatments']) ? (int)$_GET['treatments'] : 0;
    $success_message = "✅ Risk updated successfully!";
}

// Handle risk assignment/unassignment
if ($_POST && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'assign_to_me') {
            $risk_id = (int)$_POST['risk_id'];
            $query = "UPDATE risk_incidents SET risk_owner_id = :owner_id, updated_at = NOW() WHERE id = :risk_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id']);
            $stmt->bindParam(':risk_id', $risk_id);
            $stmt->execute();
            $success_message = "Risk successfully assigned to you!";
        } elseif ($_POST['action'] === 'unassign_from_me') {
            $risk_id = (int)$_POST['risk_id'];
            $query = "UPDATE risk_incidents SET risk_owner_id = NULL, updated_at = NOW() WHERE id = :risk_id AND risk_owner_id = :owner_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id']);
            $stmt->bindParam(':risk_id', $risk_id);
            $stmt->execute();
            $success_message = "Risk successfully unassigned from you!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle risk reporting (Risk Owners can also report risks)
if ($_POST && isset($_POST['submit_risk'])) {
    $risk_name = $_POST['risk_name'];
    $risk_description = $_POST['risk_description'];
    $cause_of_risk = $_POST['cause_of_risk'];
    $department = $_POST['department'];
    $existing_or_new = $_POST['existing_or_new'];
    $to_be_reported_to_board = $_POST['to_be_reported_to_board'];
    
    try {
        $query = "INSERT INTO risk_incidents (risk_name, risk_description, cause_of_risk, department, reported_by, risk_owner_id, existing_or_new, to_be_reported_to_board, risk_status) 
                  VALUES (:risk_name, :risk_description, :cause_of_risk, :department, :reported_by, :risk_owner_id, :existing_or_new, :to_be_reported_to_board, 'pending')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_description', $risk_description);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk);
        $stmt->bindParam(':department', $department);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        $stmt->bindParam(':risk_owner_id', $_SESSION['user_id']); // Auto-assign to self
        $stmt->bindParam(':existing_or_new', $existing_or_new);
        $stmt->bindParam(':to_be_reported_to_board', $to_be_reported_to_board);
        
        if ($stmt->execute()) {
            $success_message = "Risk reported successfully and assigned to you!";
        }
    } catch (PDOException $e) {
        $error_message = "Failed to report risk: " . $e->getMessage();
    }
}

// Handle accepting assignment
if ($_POST && isset($_POST['accept_assignment'])) {
    $risk_id = $_POST['risk_id'];
    
    // Update assignment status
    $query = "UPDATE risk_assignments SET status = 'Accepted' WHERE risk_id = :risk_id AND assigned_to = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':risk_id', $risk_id);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $success_message = "Risk assignment accepted successfully!";
    }
}

// Handle updating existing_or_new status (Risk Owner decision)
if ($_POST && isset($_POST['update_risk_type'])) {
    $risk_id = $_POST['risk_id'];
    $existing_or_new = $_POST['existing_or_new'];
    $to_be_reported_to_board = $_POST['to_be_reported_to_board'];
    
    $query = "UPDATE risk_incidents SET existing_or_new = :existing_or_new, to_be_reported_to_board = :to_be_reported_to_board WHERE id = :risk_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':existing_or_new', $existing_or_new);
    $stmt->bindParam(':to_be_reported_to_board', $to_be_reported_to_board);
    $stmt->bindParam(':risk_id', $risk_id);
    
    if ($stmt->execute()) {
        $success_message = "Risk classification updated successfully!";
    }
}

// Handle risk assessment update
if ($_POST && isset($_POST['update_assessment'])) {
    $risk_id = $_POST['risk_id'];
    $probability = $_POST['probability'];
    $impact = $_POST['impact'];
    
    // Calculate risk score
    $risk_rating = $probability * $impact;
    
    // Determine risk level based on rating
    $risk_level = 'Low';
    if ($risk_rating > 20) $risk_level = 'Critical';
    elseif ($risk_rating > 12) $risk_level = 'High';
    elseif ($risk_rating > 6) $risk_level = 'Medium';
    
    $query = "UPDATE risk_incidents SET 
              probability = :probability,
              impact = :impact,
              risk_level = :risk_level
              WHERE id = :risk_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':probability', $probability);
    $stmt->bindParam(':impact', $impact);
    $stmt->bindParam(':risk_level', $risk_level);
    $stmt->bindParam(':risk_id', $risk_id);
    
    if ($stmt->execute()) {
        $success_message = "Risk assessment updated successfully!";
    }
}

// Get current user info
$user = getCurrentUser();

// Get comprehensive statistics
$stats = [];

// Risks in my department only
$query = "SELECT COUNT(*) as total FROM risk_incidents WHERE department = :department";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$stats['department_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Risks assigned to this risk owner
$query = "SELECT COUNT(*) as total FROM risk_incidents WHERE risk_owner_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$stats['my_assigned_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Risks reported by this risk owner
$query = "SELECT COUNT(*) as total FROM risk_incidents WHERE reported_by = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$stats['my_reported_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// High/Critical risks assigned to me
$query = "SELECT COUNT(*) as total FROM risk_incidents 
          WHERE risk_owner_id = :user_id 
          AND ((inherent_likelihood IS NOT NULL AND inherent_consequence IS NOT NULL AND (inherent_likelihood * inherent_consequence) >= 9)
          OR (residual_likelihood IS NOT NULL AND residual_consequence IS NOT NULL AND (residual_likelihood * residual_consequence) >= 9))";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$stats['my_high_risks'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get assigned risks (risks assigned to this user)
$query = "SELECT r.*, u.full_name as reporter_name
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          WHERE r.risk_owner_id = :user_id
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$assigned_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department risks
$query = "SELECT ri.*, u.full_name as reporter_name, ro.full_name as risk_owner_name
          FROM risk_incidents ri 
          LEFT JOIN users u ON ri.reported_by = u.id
          LEFT JOIN users ro ON ri.risk_owner_id = ro.id
          WHERE ri.department = :department
          ORDER BY 
            CASE 
              WHEN ri.inherent_likelihood IS NOT NULL AND ri.inherent_consequence IS NOT NULL 
              THEN (ri.inherent_likelihood * ri.inherent_consequence) 
              WHEN ri.residual_likelihood IS NOT NULL AND ri.residual_consequence IS NOT NULL 
              THEN (ri.residual_likelihood * ri.residual_consequence)
              ELSE 0 
            END DESC, 
            ri.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$department_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending assignments (if you have a separate assignments table)
$pending_assignments = []; // Initialize as empty array since we're not sure if this table exists

// Try to get pending assignments, but handle if table doesn't exist
try {
    $pending_query = "SELECT r.*, u.full_name as reporter_name, ra.assignment_date
                      FROM risk_assignments ra
                      JOIN risk_incidents r ON ra.risk_id = r.id
                      JOIN users u ON r.reported_by = u.id
                      WHERE ra.assigned_to = :user_id AND ra.status = 'Pending'
                      ORDER BY ra.assignment_date DESC";
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $pending_stmt->execute();
    $pending_assignments = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist, keep empty array
    $pending_assignments = [];
}

// Risk level distribution for department only
$query = "SELECT 
    SUM(CASE 
        WHEN probability IS NULL OR impact IS NULL THEN 1
        WHEN (probability * impact) <= 3 THEN 1 
        ELSE 0 
    END) as low_risks,
    SUM(CASE 
        WHEN probability IS NOT NULL AND impact IS NOT NULL AND (probability * impact) BETWEEN 4 AND 8 
        THEN 1 ELSE 0 
    END) as medium_risks,
    SUM(CASE 
        WHEN probability IS NOT NULL AND impact IS NOT NULL AND (probability * impact) BETWEEN 9 AND 14 
        THEN 1 ELSE 0 
    END) as high_risks,
    SUM(CASE 
        WHEN probability IS NOT NULL AND impact IS NOT NULL AND (probability * impact) >= 15 
        THEN 1 ELSE 0 
    END) as critical_risks
    FROM risk_incidents 
    WHERE department = :department";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$risk_levels = $stmt->fetch(PDO::FETCH_ASSOC);

// Risk status distribution for department
$query = "SELECT 
    risk_status,
    COUNT(*) as count
    FROM risk_incidents 
    WHERE department = :department
    GROUP BY risk_status
    ORDER BY count DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$risk_by_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'completed': return 'badge-success';
        case 'in_progress': return 'badge-warning';
        case 'cancelled': return 'badge-danger';
        default: return 'badge-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Owner Dashboard - Airtel Risk Management</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .dashboard {
            min-height: 100vh;
        }
        
        /* Header - Exact copy from staff dashboard */
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
        
        /* Updated Navigation Tabs */
        
        /* Main Content */
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
        .stat-description {
            color: #999;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(230, 0, 18, 0.2);
        }

        .action-card .stat-number {
            font-size: 3rem;
        }
        
        .risk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }
        
        .risk-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            background: white;
            border-top: 3px solid #E60012;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .risk-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .risk-header h3 {
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .risk-level, .risk-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .level-low, .risk-low { background: #d4edda; color: #155724; }
        .level-medium, .risk-medium { background: #fff3cd; color: #856404; }
        .level-high, .risk-high { background: #ffebee; color: #E60012; }
        .level-critical, .risk-critical { background: #ffcdd2; color: #E60012; }
        .level-not-assessed, .risk-not-assessed { background: #e2e3e5; color: #383d41; }
        
        .risk-card p {
            margin-bottom: 0.8rem;
            color: #666;
            line-height: 1.5;
        }
        
        .risk-card strong {
            color: #333;
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
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        .btn-outline {
            background: transparent;
            color: #E60012;
            border: 1px solid #E60012;
        }
        .btn-outline:hover {
            background: #E60012;
            color: white;
        }
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
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
        
        .success, .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .error, .alert-danger {
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
        
        .pending-badge {
            background: #fff3cd;
            color: #856404;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
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
        /* Table Styles */
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
        .table tbody + tbody {
            border-top: 2px solid #dee2e6;
        }
        .risk-row {
            position: relative;
            border-left: 4px solid transparent;
        }
        
        .risk-row.critical { border-left-color: #dc3545; }
        .risk-row.high { border-left-color: #fd7e14; }
        .risk-row.medium { border-left-color: #ffc107; }
        .risk-row.low { border-left-color: #28a745; }
        .risk-row.not-assessed { border-left-color: #6c757d; }
        .assignment-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .owner-badge {
            background: #e3f2fd;
            color: #1565c0;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .unassigned-badge {
            background: #fff3e0;
            color: #ef6c00;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .quick-assign-form {
            display: inline-block;
            margin: 0;
        }
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
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .text-muted {
            color: #6c757d;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-top: 120px; /* Increased padding for mobile header */
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
            .assignment-actions {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Header - Exact copy from staff dashboard -->
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
                        <div class="user-role">Risk_owner • <?php echo $user['department'] ?? 'General'; ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        
        
        <!-- Main Content -->
        <main class="main-content">
            <?php if (isset($success_message)): ?>
                <div class="success">✅ <?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <!-- Dashboard Tab -->
            <div id="dashboard-tab" class="tab-content active">
                
                <!-- Risk Owner Statistics Cards -->
                
<div class="dashboard-grid">
    <div class="stat-card">
        <span class="stat-number"><?php echo $stats['department_risks']; ?></span>
        <div class="stat-label">Department Risks</div>
        <div class="stat-description">All risks in <?php echo $user['department']; ?></div>
    </div>
    <div class="stat-card">
        <span class="stat-number"><?php echo $stats['my_assigned_risks']; ?></span>
        <div class="stat-label">My Assigned Risks</div>
        <div class="stat-description">Risks assigned to you</div>
    </div>
    <div class="stat-card action-card" onclick="openReportModal()" style="cursor: pointer; transition: transform 0.3s;">
        <span class="stat-number">📝</span>
        <div class="stat-label">Report New Risk</div>
        <div class="stat-description">Click to report a new risk</div>
    </div>
    <div class="stat-card">
        <span class="stat-number"><?php echo $stats['my_reported_risks']; ?></span>
        <div class="stat-label">Reported Risks</div>
        <div class="stat-description">Risks you have reported</div>
    </div>
</div>

                <!-- Charts Section -->
                
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
    <!-- Risk Status in Department -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Risk Status (<?php echo $user['department']; ?>)</h3>
        </div>
        <div style="padding: 1rem;">
            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Risk Level Distribution -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Risk Level Distribution (<?php echo $user['department']; ?>)</h3>
        </div>
        <div style="padding: 1rem;">
            <div class="chart-container">
                <canvas id="levelChart"></canvas>
            </div>
        </div>
    </div>
</div>

                <!-- Risk Owner Guidelines -->
                
<div class="card">
    <div class="card-header">
        <h3 class="card-title">🎯 Risk Owner Responsibilities (<?php echo $user['department']; ?>)</h3>
    </div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <div>
            <h4 style="color: #E60012; margin-bottom: 1rem;">✅ Your Department Access:</h4>
            <ul style="list-style-type: none; padding: 0;">
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>🏢 Department Risks</strong><br>
                    <small>View and manage all risks within <?php echo $user['department']; ?></small>
                </li>
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>🔄 Auto-Assigned Risks</strong><br>
                    <small>All risks are automatically assigned to risk owners</small>
                </li>
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>📝 Risk Reporting</strong><br>
                    <small>Report new risks discovered in your department</small>
                </li>
                <li style="padding: 0.5rem 0;">
                    <strong>📊 Risk Assessment</strong><br>
                    <small>Complete risk assessments and treatment plans</small>
                </li>
            </ul>
        </div>
        <div>
            <h4 style="color: #28a745; margin-bottom: 1rem;">💡 Department Best Practices:</h4>
            <ul style="list-style-type: none; padding: 0;">
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>🚨 Monitor Department Risks</strong><br>
                    <small>Keep track of all risks affecting your department</small>
                </li>
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>⚡ Quick Response</strong><br>
                    <small>Address high-priority risks in your area promptly</small>
                </li>
                <li style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                    <strong>🤝 Team Collaboration</strong><br>
                    <small>Work with department colleagues on risk management</small>
                </li>
                <li style="padding: 0.5rem 0;">
                    <strong>📈 Progress Tracking</strong><br>
                    <small>Regularly update status of your assigned risks</small>
                </li>
            </ul>
        </div>
    </div>
    
    <div style="background: #e8f5e8; border-left: 4px solid #28a745; padding: 1rem; margin-top: 2rem; border-radius: 0 0.25rem 0.25rem 0;">
        <strong>🤖 Auto-Assignment System:</strong> All risks are automatically assigned to risk owners in your department using a round-robin system. You don't need to claim risks - they'll be assigned to you automatically when reported.
    </div>
    
    <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 1rem; margin-top: 2rem; border-radius: 0 0.25rem 0.25rem 0;">
        <strong>🔒 Access Scope:</strong> Your access is limited to <?php echo $user['department']; ?> department risks only. For system-wide risk reports, contact the Compliance Team at compliance@airtel.africa.
    </div>
</div>

                <!-- Quick Actions -->
                
<div class="card">
    <div class="card-header">
        <h3 class="card-title">🚀 Quick Actions</h3>
    </div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <a href="javascript:void(0)" onclick="openReportModal()" class="btn btn-primary" style="flex: 1; min-width: 200px; padding: 1rem; text-align: center;">
            📝 Report New Risk<br>
            <small>Report a risk you've identified</small>
        </a>
        <a href="javascript:void(0)" class="btn btn-secondary" style="flex: 1; min-width: 200px; padding: 1rem; text-align: center;" onclick="showTab('department')">
            🏢 View Department Risks<br>
            <small>See all risks in your department</small>
        </a>
        <a href="javascript:void(0)" class="btn btn-outline" style="flex: 1; min-width: 200px; padding: 1rem; text-align: center;" onclick="showTab('risks')">
            📋 My Assigned Risks<br>
            <small>View risks assigned to you</small>
        </a>
    </div>
</div>
            </div>
            
            <!-- My Assigned Risks Tab -->
            <div id="risks-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">My Assigned Risks (<?php echo $user['department']; ?>)</h2>
                        <span class="badge badge-info"><?php echo count($assigned_risks); ?> risks assigned to you</span>
                    </div>
                    <?php if (empty($assigned_risks)): ?>
                        <div class="alert-info">
                            <p><strong>No risks assigned to you yet.</strong></p>
                            <p>All risks are automatically assigned by the system. New risks will appear here when assigned to you.</p>
                            <button class="btn btn-primary" onclick="showTab('department')">View Department Risks</button>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Risk Name</th>
                                    <th>Risk Level</th>
                                    <th>Status</th>
                                    <th>Reported By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assigned_risks as $risk): ?>
                                <tr class="risk-row <?php echo getRiskLevel($risk['probability'] ?? 0, $risk['impact'] ?? 0); ?>" data-risk-id="<?php echo $risk['id']; ?>">
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
                                            <?php echo ucfirst(str_replace('_', ' ', $risk['risk_status'] ?? 'Not Started')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                                    <td>
                                        <div class="assignment-actions">
                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                            <form method="POST" class="quick-assign-form" onsubmit="return confirm('Are you sure you want to unassign this risk from yourself?')">
                                                <input type="hidden" name="action" value="unassign_from_me">
                                                <input type="hidden" name="risk_id" value="<?php echo $risk['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline">Unassign</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Department Risks Tab -->
<div id="department-tab" class="tab-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Department Risks (<?php echo $user['department']; ?>)</h2>
            <span class="badge badge-info"><?php echo count($department_risks); ?> risks in your department</span>
        </div>
        <?php if (empty($department_risks)): ?>
            <div class="alert-info">
                <p><strong>No risks in your department yet.</strong></p>
                <p>Your department hasn't reported any risks yet.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Risk Name</th>
                        <th>Risk Level</th>
                        <th>Status</th>
                        <th>Risk Owner</th>
                        <th>Reported By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($department_risks as $risk): ?>
                    <tr class="risk-row <?php echo getRiskLevel($risk['inherent_likelihood'] ?? $risk['residual_likelihood'] ?? 0, $risk['inherent_consequence'] ?? $risk['residual_consequence'] ?? 0); ?>" data-risk-id="<?php echo $risk['id']; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($risk['risk_name']); ?></strong>
                            <?php if ($risk['risk_description']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($risk['risk_description'], 0, 100)) . (strlen($risk['risk_description']) > 100 ? '...' : ''); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="risk-badge risk-<?php echo getRiskLevel($risk['inherent_likelihood'] ?? $risk['residual_likelihood'] ?? 0, $risk['inherent_consequence'] ?? $risk['residual_consequence'] ?? 0); ?>">
                                <?php echo getRiskLevelText($risk['inherent_likelihood'] ?? $risk['residual_likelihood'] ?? 0, $risk['inherent_consequence'] ?? $risk['residual_consequence'] ?? 0); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo str_replace('_', '-', $risk['risk_status'] ?? 'pending'); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $risk['risk_status'] ?? 'Not Started')); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($risk['risk_owner_name']): ?>
                                <span class="owner-badge"><?php echo htmlspecialchars($risk['risk_owner_name']); ?></span>
                            <?php else: ?>
                                <span class="unassigned-badge">Auto-Assigning...</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                        <td>
                            <div class="assignment-actions">
                                <a href="view_risk.php?id=<?php echo $risk['id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                <?php if ($risk['risk_owner_id'] != $_SESSION['user_id'] && $risk['risk_owner_id']): ?>
                                    <form method="POST" class="quick-assign-form" onsubmit="return confirm('Are you sure you want to take over this risk?')">
                                        <input type="hidden" name="action" value="assign_to_me">
                                        <input type="hidden" name="risk_id" value="<?php echo $risk['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline">Take Over</button>
                                    </form>
                                <?php elseif ($risk['risk_owner_id'] == $_SESSION['user_id']): ?>
                                    <span class="badge badge-success">Mine</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

            <!-- Assignments Tab (Notifications) -->
            <div id="assignments-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Notifications</h2>
                        <span class="badge badge-secondary">Coming Soon</span>
                    </div>
                    <div class="alert-info">
                        <p><strong>Notifications feature is under development.</strong></p>
                        <p>This section will show system notifications, assignment alerts, and important updates.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Comprehensive Risk Report Modal for Risk Owners -->
<div id="reportModal" class="modal">
    <div class="modal-content" style="max-width: 1200px;">
        <div class="modal-header">
            <h3 class="modal-title">Report New Risk - Comprehensive Form</h3>
            <button class="close" onclick="closeModal('reportModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2rem;">
                    
                    <!-- RISK IDENTIFICATION Section -->
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #E60012;">
                        <h4 style="color: #E60012; margin-bottom: 1rem;">📋 RISK IDENTIFICATION</h4>
                        
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
                            <label for="risk_category">Risk Category</label>
                            <select id="risk_category" name="risk_category" required>
                                <option value="">Select Category</option>
                                <option value="Strategic Risk">Strategic Risk</option>
                                <option value="Operational Risk">Operational Risk</option>
                                <option value="Financial Risk">Financial Risk</option>
                                <option value="Compliance Risk">Compliance Risk</option>
                                <option value="Technology Risk">Technology Risk</option>
                                <option value="Reputational Risk">Reputational Risk</option>
                                <option value="Market Risk">Market Risk</option>
                                <option value="Credit Risk">Credit Risk</option>
                                <option value="Liquidity Risk">Liquidity Risk</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="department">Department</label>
                            <input type="text" id="department" name="department" required value="<?php echo $user['department'] ?? ''; ?>" readonly style="background: #e9ecef;">
                        </div>
                    </div>
                    
                    <!-- RISK ASSESSMENT Section -->
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #E60012;">
                        <h4 style="color: #E60012; margin-bottom: 1rem;">📊 RISK ASSESSMENT</h4>
                        
                        <h5 style="color: #E60012; margin-bottom: 1rem;">Inherent Risk (Gross Risk)</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div class="form-group">
                                <label for="inherent_likelihood">Likelihood (1-5)</label>
                                <select id="inherent_likelihood" name="inherent_likelihood" onchange="calculateInherentRating()" required>
                                    <option value="">Select</option>
                                    <option value="1">1 - Very Low</option>
                                    <option value="2">2 - Low</option>
                                    <option value="3">3 - Medium</option>
                                    <option value="4">4 - High</option>
                                    <option value="5">5 - Very High</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="inherent_consequence">Consequence (1-5)</label>
                                <select id="inherent_consequence" name="inherent_consequence" onchange="calculateInherentRating()" required>
                                    <option value="">Select</option>
                                    <option value="1">1 - Minimal</option>
                                    <option value="2">2 - Minor</option>
                                    <option value="3">3 - Moderate</option>
                                    <option value="4">4 - Major</option>
                                    <option value="5">5 - Catastrophic</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Inherent Rating</label>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <input type="hidden" id="inherent_rating" name="inherent_rating">
                                <div id="inherent_rating_display" style="background: #E60012; color: white; padding: 0.5rem 1rem; border-radius: 4px; font-weight: bold; min-width: 60px; text-align: center;">-</div>
                            </div>
                        </div>
                        
                        <h5 style="color: #E60012; margin: 1.5rem 0 1rem;">Residual Risk (Net Risk)</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                            <div class="form-group">
                                <label for="residual_likelihood">Likelihood (1-5)</label>
                                <select id="residual_likelihood" name="residual_likelihood" onchange="calculateResidualRating()" required>
                                    <option value="">Select</option>
                                    <option value="1">1 - Very Low</option>
                                    <option value="2">2 - Low</option>
                                    <option value="3">3 - Medium</option>
                                    <option value="4">4 - High</option>
                                    <option value="5">5 - Very High</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="residual_consequence">Consequence (1-5)</label>
                                <select id="residual_consequence" name="residual_consequence" onchange="calculateResidualRating()" required>
                                    <option value="">Select</option>
                                    <option value="1">1 - Minimal</option>
                                    <option value="2">2 - Minor</option>
                                    <option value="3">3 - Moderate</option>
                                    <option value="4">4 - Major</option>
                                    <option value="5">5 - Catastrophic</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Residual Rating</label>
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <input type="hidden" id="residual_rating" name="residual_rating">
                                <div id="residual_rating_display" style="background: #E60012; color: white; padding: 0.5rem 1rem; border-radius: 4px; font-weight: bold; min-width: 60px; text-align: center;">-</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="treatment_action">Treatment Action</label>
                            <textarea id="treatment_action" name="treatment_action" placeholder="Describe the treatment action required"></textarea>
                        </div>
                    </div>
                    
                    <!-- RISK TREATMENT Section -->
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #E60012;">
                        <h4 style="color: #E60012; margin-bottom: 1rem;">🛡️ RISK TREATMENT</h4>
                        
                        <div class="form-group">
                            <label for="controls_action_plans">Controls/Action Plans</label>
                            <textarea id="controls_action_plans" name="controls_action_plans" placeholder="Detail the controls and action plans"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="target_completion_date">Target Completion Date</label>
                            <input type="date" id="target_completion_date" name="target_completion_date">
                        </div>
                        
                        <div class="form-group">
                            <label>Risk Owner</label>
                            <input type="text" value="<?php echo $_SESSION['full_name'] ?? $_SESSION['email']; ?> (You)" readonly style="background: #e9ecef;">
                        </div>
                        
                        <div class="form-group">
                            <label for="progress_update">Initial Progress Update</label>
                            <textarea id="progress_update" name="progress_update" placeholder="Provide initial progress notes"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="treatment_status">Treatment Status</label>
                            <select id="treatment_status" name="treatment_status">
                                <option value="Not Started">Not Started</option>
                                <option value="In Progress">In Progress</option>
                                <option value="On Hold">On Hold</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="risk_status">Overall Risk Status</label>
                            <select id="risk_status" name="risk_status">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="on_hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 1.5rem; margin-top: 2rem; border-top: 1px solid #dee2e6; display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reportModal')">Cancel</button>
                    <button type="submit" name="submit_comprehensive_risk" class="btn">Submit Complete Risk Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calculateInherentRating() {
    const likelihood = document.getElementById('inherent_likelihood').value;
    const consequence = document.getElementById('inherent_consequence').value;
    
    if (likelihood && consequence) {
        const rating = parseInt(likelihood) * parseInt(consequence);
        document.getElementById('inherent_rating').value = rating;
        document.getElementById('inherent_rating_display').textContent = rating;
        
        // Update color based on rating
        const display = document.getElementById('inherent_rating_display');
        display.style.background = '#E60012';
        if (rating >= 15) display.style.background = '#dc3545';
        else if (rating >= 9) display.style.background = '#fd7e14';
        else if (rating >= 4) display.style.background = '#ffc107';
        else display.style.background = '#28a745';
    }
}

function calculateResidualRating() {
    const likelihood = document.getElementById('residual_likelihood').value;
    const consequence = document.getElementById('residual_consequence').value;
    
    if (likelihood && consequence) {
        const rating = parseInt(likelihood) * parseInt(consequence);
        document.getElementById('residual_rating').value = rating;
        document.getElementById('residual_rating_display').textContent = rating;
        
        // Update color based on rating
        const display = document.getElementById('residual_rating_display');
        display.style.background = '#E60012';
        if (rating >= 15) display.style.background = '#dc3545';
        else if (rating >= 9) display.style.background = '#fd7e14';
        else if (rating >= 4) display.style.background = '#ffc107';
        else display.style.background = '#28a745';
    }
}
</script>
    
    <!-- Risk Classification Modal -->
    <div id="classificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Risk Classification</h3>
                <button class="close" onclick="closeModal('classificationModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" id="classification_risk_id" name="risk_id">
                    
                    <div class="classification-section">
                        <h4>Risk Owner Decision</h4>
                        <p style="margin-bottom: 1rem; color: #666;">As the Risk Owner, you need to classify this risk and decide if it should be reported to the board.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="class_existing_or_new">Risk Type</label>
                                <select id="class_existing_or_new" name="existing_or_new" required>
                                    <option value="New">New Risk</option>
                                    <option value="Existing">Existing Risk</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="class_to_be_reported_to_board">Report to Board</label>
                                <select id="class_to_be_reported_to_board" name="to_be_reported_to_board" required>
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_risk_type" class="btn">Update Classification</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Risk Assessment Modal -->
    <div id="assessmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Risk Assessment</h3>
                <button class="close" onclick="closeModal('assessmentModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" id="assessment_risk_id" name="risk_id">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="probability">Probability (1-5)</label>
                            <select id="probability" name="probability" required>
                                <option value="1">1 - Very Low</option>
                                <option value="2">2 - Low</option>
                                <option value="3">3 - Medium</option>
                                <option value="4">4 - High</option>
                                <option value="5">5 - Very High</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="impact">Impact (1-5)</label>
                            <select id="impact" name="impact" required>
                                <option value="1">1 - Minimal</option>
                                <option value="2">2 - Minor</option>
                                <option value="3">3 - Moderate</option>
                                <option value="4">4 - Major</option>
                                <option value="5">5 - Catastrophic</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_assessment" class="btn">Update Assessment</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Risk Status Chart (replace department chart)
        const statusData = <?php echo json_encode($risk_by_status); ?>;
        const statusCtx = document.getElementById('statusChart').getContext('2d');

        if (statusData.length > 0) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: statusData.map(item => item.risk_status ? item.risk_status.replace('_', ' ').toUpperCase() : 'PENDING'),
                    datasets: [{
                        data: statusData.map(item => item.count),
                        backgroundColor: [
                            '#28a745', // completed
                            '#ffc107', // in_progress  
                            '#6c757d', // pending
                            '#dc3545', // cancelled
                            '#17a2b8'  // on_hold
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        } else {
            statusCtx.canvas.parentNode.innerHTML = '<p style="text-align: center; color: #666;">No data available</p>';
        }
        
        // Risk Level Chart
        const riskLevels = <?php echo json_encode($risk_levels); ?>;
        const levelCtx = document.getElementById('levelChart').getContext('2d');
        
        new Chart(levelCtx, {
            type: 'bar',
            data: {
                labels: ['Low (1-3)', 'Medium (4-8)', 'High (9-14)', 'Critical (15+)'],
                datasets: [{
                    label: 'Number of Risks',
                    data: [
                        parseInt(riskLevels.low_risks) || 0,
                        parseInt(riskLevels.medium_risks) || 0,
                        parseInt(riskLevels.high_risks) || 0,
                        parseInt(riskLevels.critical_risks) || 0
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#ffc107',
                        '#fd7e14',
                        '#dc3545'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
        
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.nav-tabs button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }
        
        function openReportModal() {
            document.getElementById('reportModal').classList.add('show');
        }
        
        function openClassificationModal(riskId) {
            document.getElementById('classification_risk_id').value = riskId;
            document.getElementById('classificationModal').classList.add('show');
        }
        
        function openAssessmentModal(riskId) {
            document.getElementById('assessment_risk_id').value = riskId;
            document.getElementById('assessmentModal').classList.add('show');
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

        // Handle highlighting updated risk row
        <?php if (isset($_GET['updated']) && isset($_GET['risk_id'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const updatedRiskId = <?php echo (int)$_GET['risk_id']; ?>;
            
            // Clean the URL to remove the parameters
            if (window.history.replaceState) {
                window.history.replaceState(null, null, 'risk_owner_dashboard.php');
            }
            
            // Highlight the updated risk row
            setTimeout(function() {
                const riskRow = document.querySelector(`tr[data-risk-id="${updatedRiskId}"]`);
                if (riskRow) {
                    riskRow.style.backgroundColor = '#d4edda';
                    riskRow.style.border = '2px solid #28a745';
                    
                    // Scroll to the row
                    riskRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Remove highlight after 8 seconds
                    setTimeout(() => {
                        riskRow.style.backgroundColor = '';
                        riskRow.style.border = '';
                    }, 8000);
                }
            }, 500);
        });
        <?php endif; ?>
        
        // Auto-refresh page every 5 minutes to show updated assignments
        setTimeout(function() {
            location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>
<?php
// Handle comprehensive risk reporting (Risk Owners can report complete risks)
if ($_POST && isset($_POST['submit_comprehensive_risk'])) {
    try {
        $db->beginTransaction();
        
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
            $db->commit();
            $success_message = "Comprehensive risk reported successfully and assigned to you!";
        } else {
            $db->rollback();
            $error_message = "Failed to report comprehensive risk.";
        }
    } catch (PDOException $e) {
        $db->rollback();
        $error_message = "Failed to report comprehensive risk: " . $e->getMessage();
    }
}
?>
