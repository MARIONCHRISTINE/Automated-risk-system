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

// Debug: Ensure department is available
if (empty($user['department'])) {
    // Fallback: get department from session or database
    if (isset($_SESSION['department'])) {
        $user['department'] = $_SESSION['department'];
    } else {
        // Get department from database
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

// Get recently assigned risks (last 24 hours)
$recent_query = "SELECT COUNT(*) as count FROM risk_incidents 
                 WHERE risk_owner_id = :user_id 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
$recent_stmt = $db->prepare($recent_query);
$recent_stmt->bindParam(':user_id', $_SESSION['user_id']);
$recent_stmt->execute();
$stats['recent_assignments'] = $recent_stmt->fetch(PDO::FETCH_ASSOC)['count'];

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
              THEN (ri.residual_likelihood * residual_consequence)
             ELSE 0 
            END DESC,
            ri.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->execute();
$department_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get risks reported by this user
$query = "SELECT ri.*, 
                 ro.full_name as risk_owner_name,
                reporter.full_name as reporter_name 
         FROM risk_incidents ri 
         LEFT JOIN users ro ON ri.risk_owner_id = ro.id
         LEFT JOIN users reporter ON ri.reported_by = reporter.id
         WHERE ri.reported_by = :user_id
         ORDER BY ri.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$my_reported_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// NEW: Risk category distribution for department
$category_query = "SELECT 
    risk_category,
    COUNT(*) as count
    FROM risk_incidents 
    WHERE department = :department
    GROUP BY risk_category
    ORDER BY count DESC";
$category_stmt = $db->prepare($category_query);
$category_stmt->bindParam(':department', $user['department']);
$category_stmt->execute();
$risk_by_category = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

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
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
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

        /* Procedures Specific Styles */
        .procedure-section {
            margin: 2rem 0;
            padding: 1.5rem;
            border-left: 4px solid #E60012;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .procedure-title {
            color: #E60012;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .field-definition {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin: 0.5rem 0;
        }
        
        .field-name {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .field-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .risk-matrix-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .risk-matrix-table th,
        .risk-matrix-table td {
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            text-align: center;
        }
        
        .risk-matrix-table th {
            background: #E60012;
            color: white;
        }
        
        .matrix-1 { background: #d4edda; }
        .matrix-2 { background: #fff3cd; }
        .matrix-3 { background: #f8d7da; }
        .matrix-4 { background: #f5c6cb; }
        .matrix-5 { background: #dc3545; color: white; }
        
        .toc {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: 0.25rem;
            margin-bottom: 2rem;
        }
        
        .toc ul {
            list-style: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 0.5rem 0;
        }
        
        .toc a {
            text-decoration: none;
            color: #E60012;
        }
        
        .toc a:hover {
            text-decoration: underline;
        }
        
        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .department-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            border-left: 4px solid #E60012;
        }
        
        .department-title {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .auto-assignment-flow {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .flow-step {
            display: flex;
            align-items: center;
            margin: 0.5rem 0;
        }
        
        .flow-step i {
            color: #2196f3;
            margin-right: 0.5rem;
            width: 20px;
        }
        
        /* Notification Styles */
        .notification-nav-item {
            position: relative;
        }
        .nav-notification-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            padding: 1rem 1.5rem;
            border-radius: 0.25rem;
            transition: all 0.3s ease;
            color: #6c757d;
            text-decoration: none;
        }
        .nav-notification-container:hover {
            background-color: rgba(230, 0, 18, 0.05);
            color: #E60012;
            text-decoration: none;
        }
        .nav-notification-container.nav-notification-empty {
            opacity: 0.6;
            cursor: default;
        }
        .nav-notification-container.nav-notification-empty:hover {
            background-color: transparent;
            color: #6c757d;
        }
        .nav-notification-bell {
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .nav-notification-container:hover .nav-notification-bell {
            transform: scale(1.1);
        }
        .nav-notification-bell.has-notifications {
            color: #ffc107;
            animation: navBellRing 2s infinite;
        }
        @keyframes navBellRing {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
        }
        .nav-notification-text {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .nav-notification-badge {
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: navPulse 2s infinite;
            margin-left: auto;
        }
        @keyframes navPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        .nav-notification-dropdown {
            position: fixed !important;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            width: 400px;
            max-height: 500px;
            z-index: 1000;
            display: none;
            transition: all 0.3s ease;
            transform: translateY(-10px);
        }
        .nav-notification-dropdown.show {
            display: block;
            transform: translateY(0);
            opacity: 1;
        }
        .nav-notification-header {
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
            color: #495057;
            background: #f8f9fa;
            border-radius: 0.5rem 0.5rem 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .nav-notification-content {
            max-height: 350px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: max-height 0.3s ease;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }
        .nav-notification-content::-webkit-scrollbar {
            width: 8px;
        }
        .nav-notification-content::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        .nav-notification-content::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
            transition: background 0.3s ease;
        }
        .nav-notification-content::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        .nav-notification-item {
            padding: 1rem;
            border-bottom: 1px solid #f8f9fa;
            transition: all 0.3s ease;
            position: relative;
        }
        .nav-notification-item:hover {
            background-color: #f8f9fa;
        }
        .nav-notification-item:last-child {
            border-bottom: none;
        }
        .nav-notification-item.read {
            opacity: 0.6;
            background-color: #f1f3f4;
        }
        .nav-notification-item.unread {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .nav-notification-title {
            font-weight: bold;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        .nav-notification-risk {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .nav-notification-date {
            color: #6c757d;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }
        .nav-notification-actions {
            margin-top: 0.5rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .nav-notification-actions .btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .nav-notification-footer {
            padding: 1rem;
            border-top: 1px solid #dee2e6;
            background: #f8f9fa;
            border-radius: 0 0 0.5rem 0.5rem;
            position: sticky;
            bottom: 0;
            z-index: 10;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 200px;
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
                top: 120px;
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
            
            .nav-notification-container {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                min-width: 80px;
            }
            
            .nav-notification-text {
                font-size: 0.65rem;
            }
            
            .nav-notification-bell {
                font-size: 1rem;
            }
            
            .nav-notification-badge {
                width: 16px;
                height: 16px;
                font-size: 0.6rem;
            }
            
            .nav-notification-dropdown {
                width: 95vw;
                left: 2.5vw !important;
                right: 2.5vw !important;
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.5rem;
            }
            
            .stat-number {
                font-size: 2rem;
            }
            
            .chart-container {
                height: 250px;
            }

            .main-content {
                padding: 1rem;
            }
            .card {
                padding: 1rem;
            }
            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .department-grid {
                grid-template-columns: 1fr;
            }
            .procedure-section {
                padding: 1rem;
            }
        }

        @media print {
            .main-content { margin: 0; }
            .card { box-shadow: none; }
            .btn { display: none; }
            nav { display: none; }
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
        
        <nav class="nav">
            <div class="nav-content">
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('dashboard')" class="<?php echo !isset($_GET['tab']) ? 'active' : ''; ?>">
                            🏠 Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                            📝 Report Risk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('my-reports')" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                            👀 My Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="javascript:void(0)" onclick="showTab('procedures')" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'procedures' ? 'active' : ''; ?>">
                            📋 Procedures
                        </a>
                    </li>
                    <li class="nav-item notification-nav-item">
                        <?php
                        if (isset($_SESSION['user_id'])) {
                            require_once 'config/database.php';
                            $database = new Database();
                            $conn = $database->getConnection();
                            
                            $treatment_query = "SELECT rt.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                                       owner.full_name as risk_owner_name, 'treatment' as notification_type,
                                                       rt.created_at as notification_date
                                                FROM risk_treatments rt
                                                INNER JOIN risk_incidents ri ON rt.risk_id = ri.id
                                                LEFT JOIN users owner ON ri.risk_owner_id = owner.id
                                                WHERE rt.assigned_to = :user_id
                                                 AND rt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                            
                            $assignment_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                                        reporter.full_name as reporter_name, 'assignment' as notification_type,
                                                        ri.updated_at as notification_date
                                                 FROM risk_incidents ri
                                                 LEFT JOIN users reporter ON ri.reported_by = reporter.id
                                                 WHERE ri.risk_owner_id = :user_id
                                                  AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                            
                            $update_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                                    updater.full_name as updater_name, 'update' as notification_type,
                                                    ri.updated_at as notification_date
                                             FROM risk_incidents ri
                                             LEFT JOIN users updater ON ri.reported_by = updater.id
                                             WHERE (ri.risk_owner_id = :user_id OR ri.reported_by = :user_id)
                                             AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                                             AND ri.updated_at != ri.created_at";
                            
                            $all_notifications = [];
                            
                            $stmt = $conn->prepare($treatment_query);
                            $stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stmt->execute();
                            $treatment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $all_notifications = array_merge($all_notifications, $treatment_notifications);
                            
                            $stmt = $conn->prepare($assignment_query);
                            $stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stmt->execute();
                            $assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $all_notifications = array_merge($all_notifications, $assignment_notifications);
                            
                            $stmt = $conn->prepare($update_query);
                            $stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stmt->execute();
                            $update_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $all_notifications = array_merge($all_notifications, $update_notifications);
                            
                            usort($all_notifications, function($a, $b) {
                                return strtotime($b['notification_date']) - strtotime($a['notification_date']);
                            });
                            
                            $all_notifications = array_slice($all_notifications, 0, 20);
                            
                            if (!empty($all_notifications)):
                        ?>
                        <div class="nav-notification-container" onclick="toggleNavNotifications()">
                            <i class="fas fa-bell nav-notification-bell"></i>
                            <span class="nav-notification-text">Notifications</span>
                            <span class="nav-notification-badge"><?php echo count($all_notifications); ?></span>
                            
                            <div class="nav-notification-dropdown" id="navNotificationDropdown">
                                <div class="nav-notification-header">
                                    <div class="flex justify-between items-center">
                                        <span><i class="fas fa-bell"></i> All Notifications</span>
                                        <button onclick="clearAllNotifications()" class="btn btn-sm btn-outline">Clear All</button>
                                    </div>
                                </div>
                                <div class="nav-notification-content" id="navNotificationContent">
                                    <?php foreach ($all_notifications as $index => $notification): ?>
                                    <div class="nav-notification-item" data-nav-notification-id="<?php echo $index; ?>">
                                        <?php if ($notification['notification_type'] == 'treatment'): ?>
                                            <div class="nav-notification-title">
                                                🎯 Treatment Assignment: <?php echo htmlspecialchars($notification['treatment_name']); ?>
                                            </div>
                                            <div class="nav-notification-risk">
                                                Risk: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                            </div>
                                            <div class="nav-notification-date">
                                                Assigned: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                            </div>
                                            <div class="nav-notification-actions">
                                                <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Risk</a>
                                                <a href="advanced-risk-management.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-warning">Manage Treatment</a>
                                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
                                            </div>
                                        <?php elseif ($notification['notification_type'] == 'assignment'): ?>
                                            <div class="nav-notification-title">
                                                📋 Risk Assignment: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                            </div>
                                            <div class="nav-notification-risk">
                                                Reported by: <?php echo htmlspecialchars($notification['reporter_name'] ?? 'System'); ?>
                                            </div>
                                            <div class="nav-notification-date">
                                                Assigned: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                            </div>
                                            <div class="nav-notification-actions">
                                                <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                                <a href="risk_assessment.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-success">Start Assessment</a>
                                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
                                            </div>
                                        <?php elseif ($notification['notification_type'] == 'update'): ?>
                                            <div class="nav-notification-title">
                                                🔄 Risk Update: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                            </div>
                                            <div class="nav-notification-risk">
                                                Updated by: <?php echo htmlspecialchars($notification['updater_name'] ?? 'System'); ?>
                                            </div>
                                            <div class="nav-notification-date">
                                                Updated: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                            </div>
                                            <div class="nav-notification-actions">
                                                <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Changes</a>
                                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="nav-notification-container nav-notification-empty">
                            <i class="fas fa-bell nav-notification-bell"></i>
                            <span class="nav-notification-text">Notifications</span>
                        </div>
                        <?php
                             endif;
                        }
                        ?>
                    </li>
                </ul>
            </div>
        </nav>
        
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
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['department_risks']; ?></span>
                        <div class="stat-label">Department Risks</div>
                        <div class="stat-description">All risks in <?php echo $user['department']; ?></div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['my_assigned_risks']; ?></span>
                        <div class="stat-label">My Assigned Risks</div>
                        <div class="stat-description">Risks assigned to you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php echo $stats['my_high_risks']; ?></span>
                        <div class="stat-label">High/Critical Risks</div>
                        <div class="stat-description">High/Critical risks assigned to you</div>
                    </div>
                    <div class="stat-card" style="transition: transform 0.3s;">
                        <span class="stat-number"><?php
                         // Calculate successfully managed risks (completed status)
                        $managed_query = "SELECT COUNT(*) as total FROM risk_incidents
                                          WHERE risk_owner_id = :user_id
                                          AND risk_status = 'completed'";
                        $managed_stmt = $db->prepare($managed_query);
                        $managed_stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $managed_stmt->execute();
                        $managed_risks = $managed_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                        echo $managed_risks;
                        ?></span>
                        <div class="stat-label">Successfully Managed Risks</div>
                        <div class="stat-description">Risks you have completed</div>
                    </div>
                </div>
                
                <!-- Charts Section -->
                <div class="dashboard-grid">
                    <!-- Risk Category Chart (NEW) -->
                    <div class="card">
                        <h3 class="card-title">Risk Categories Distribution</h3>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Risk Level Chart (EXISTING) -->
                    <div class="card">
                        <h3 class="card-title">Risk Level Distribution</h3>
                        <div class="chart-container">
                            <canvas id="levelChart"></canvas>
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
                        <strong>🤖 Auto-Assignment System:</strong> Staff members can report risks which are automatically assigned to risk owners in their department. You'll receive new assignments and can complete the full risk assessment and treatment planning.
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
                        <a href="report_risk.php" class="btn btn-primary" style="flex: 1; min-width: 180px; padding: 1rem; text-align: center;">
                            📝 Report New Risk<br>
                            <small>Report a risk you've identified</small>
                        </a>
                        <a href="javascript:void(0)" class="btn btn-secondary" style="flex: 1; min-width: 180px; padding: 1rem; text-align: center;" onclick="showTab('department')">
                            🏢 View Department Risks<br>
                            <small>See all risks in your department</small>
                        </a>
                        <a href="javascript:void(0)" class="btn btn-outline" style="flex: 1; min-width: 180px; padding: 1rem; text-align: center;" onclick="showTab('risks')">
                            📋 My Assigned Risks<br>
                            <small>View risks assigned to you</small>
                        </a>
                        <a href="javascript:void(0)" class="btn btn-warning" style="flex: 1; min-width: 180px; padding: 1rem; text-align: center;" onclick="showTab('my-reports')">
                            👀 My Reports<br>
                            <small>View risks you have reported</small>
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
            
            <!-- My Reports Tab -->
            <div id="my-reports-tab" class="tab-content">
                <!-- Statistics Cards -->
                <div class="dashboard-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 2rem;">
                    <?php
                    // Calculate report statistics
                    $total_reports = count($my_reported_risks);
                    $open_reports = 0;
                    $in_progress_reports = 0;
                    $completed_reports = 0;
                    
                    foreach ($my_reported_risks as $risk) {
                        switch ($risk['risk_status']) {
                            case 'pending':
                                $open_reports++;
                                break;
                            case 'in_progress':
                                $in_progress_reports++;
                                break;
                            case 'completed':
                                $completed_reports++;
                                break;
                        }
                    }
                    ?>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $total_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Total Reports</div>
                        <div class="stat-description" style="color: #666;">All risks you have reported</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $open_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Open</div>
                        <div class="stat-description" style="color: #666;">Awaiting review</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $in_progress_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">In Progress</div>
                        <div class="stat-description" style="color: #666;">Being addressed</div>
                    </div>
                    
                    <div class="stat-card" style="border-left: 4px solid #E60012;">
                        <div class="stat-number" style="color: #E60012;"><?php echo $completed_reports; ?></div>
                        <div class="stat-label" style="color: #8B4513; font-weight: 600;">Completed</div>
                        <div class="stat-description" style="color: #666;">Successfully managed</div>
                    </div>
                </div>
                
                <!-- Action Buttons and Search -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
                    <div style="display: flex; gap: 1rem;">
                        <a href="report_risk.php" class="btn" style="background: #E60012; color: white; padding: 0.75rem 1.5rem; border-radius: 5px; text-decoration: none; font-weight: 500;">
                            📝 Report New Risk
                        </a>
                        <a href="risk_owner_dashboard.php" class="btn" style="background: #6c757d; color: white; padding: 0.75rem 1.5rem; border-radius: 5px; text-decoration: none; font-weight: 500;">
                            🏠 Back to Dashboard
                        </a>
                    </div>
                    <div>
                        <input type="text" id="searchRisks" placeholder="Search risks..." style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 5px; width: 250px;">
                    </div>
                </div>
                
                <!-- Reports Table -->
                <div class="card" style="padding: 0;">
                    <div style="padding: 1.5rem 2rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0; color: #333; font-size: 1.2rem; font-weight: 600;">All My Risk Reports</h3>
                        <select id="statusFilter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; background: white;">
                            <option value="">All Statuses</option>
                            <option value="pending">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <?php if (empty($my_reported_risks)): ?>
                        <div style="padding: 3rem; text-align: center; color: #666;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                            <h3 style="margin-bottom: 0.5rem;">No Risk Reports Yet</h3>
                            <p style="margin-bottom: 1.5rem;">You haven't reported any risks yet. Start by reporting your first risk.</p>
                            <a href="report_risk.php" class="btn" style="background: #E60012; color: white; padding: 0.75rem 1.5rem; border-radius: 5px; text-decoration: none;">
                                Report Your First Risk
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table" style="margin: 0;">
                                <thead style="background: #f8f9fa;">
                                    <tr>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Name</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Category</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Level</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Status</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Risk Owner</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Reported Date</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Last Updated</th>
                                        <th style="padding: 1rem; font-weight: 600; color: #495057;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="reportsTableBody">
                                    <?php foreach ($my_reported_risks as $risk): ?>
                                    <tr class="risk-report-row" data-status="<?php echo $risk['risk_status'] ?? 'pending'; ?>" data-search="<?php echo strtolower($risk['risk_name'] . ' ' . ($risk['risk_category'] ?? '')); ?>">
                                        <td style="padding: 1rem; border-left: 4px solid #E60012;">
                                            <div style="font-weight: 600; color: #333; margin-bottom: 0.25rem;">
                                                <?php echo htmlspecialchars($risk['risk_name']); ?>
                                            </div>
                                            <?php if ($risk['risk_description']): ?>
                                                <div style="font-size: 0.85rem; color: #666; line-height: 1.4;">
                                                    <?php echo htmlspecialchars(substr($risk['risk_description'], 0, 80)) . (strlen($risk['risk_description']) > 80 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <span style="background: #f8f9fa; color: #495057; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">
                                                <?php echo htmlspecialchars($risk['risk_category'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php
                                             // Use actual assessed values for risk level calculation
                                            $probability = $risk['probability'] ?? $risk['inherent_likelihood'] ?? $risk['residual_likelihood'] ?? 0;
                                            $impact = $risk['impact'] ?? $risk['inherent_consequence'] ?? $risk['residual_consequence'] ?? 0;
                                            
                                            if ($probability && $impact) {
                                                $riskScore = $probability * $impact;
                                                if ($riskScore >= 15) {
                                                    $levelText = 'CRITICAL';
                                                    $levelColor = '#721c24';
                                                    $levelBg = '#f8d7da';
                                                } elseif ($riskScore >= 9) {
                                                    $levelText = 'HIGH';
                                                    $levelColor = '#721c24';
                                                    $levelBg = '#f8d7da';
                                                } elseif ($riskScore >= 4) {
                                                    $levelText = 'MEDIUM';
                                                    $levelColor = '#856404';
                                                    $levelBg = '#fff3cd';
                                                } else {
                                                    $levelText = 'LOW';
                                                    $levelColor = '#155724';
                                                    $levelBg = '#d4edda';
                                                }
                                            } else {
                                                $levelText = 'NOT ASSESSED';
                                                $levelColor = '#6c757d';
                                                $levelBg = '#e2e3e5';
                                            }
                                            ?>
                                            <span style="background: <?php echo $levelBg; ?>; color: <?php echo $levelColor; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                <?php echo $levelText; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <?php
                                             $status = $risk['risk_status'] ?? 'pending';
                                            switch($status) {
                                                case 'pending':
                                                    $statusText = 'Open';
                                                    $statusColor = '#0c5460';
                                                    $statusBg = '#d1ecf1';
                                                    break;
                                                case 'in_progress':
                                                    $statusText = 'In Progress';
                                                    $statusColor = '#004085';
                                                    $statusBg = '#cce5ff';
                                                    break;
                                                case 'completed':
                                                    $statusText = 'Completed';
                                                    $statusColor = '#155724';
                                                    $statusBg = '#d4edda';
                                                    break;
                                                case 'cancelled':
                                                    $statusText = 'Cancelled';
                                                    $statusColor = '#721c24';
                                                    $statusBg = '#f8d7da';
                                                    break;
                                                default:
                                                    $statusText = 'Open';
                                                    $statusColor = '#0c5460';
                                                    $statusBg = '#d1ecf1';
                                            }
                                            ?>
                                            <span style="background: <?php echo $statusBg; ?>; color: <?php echo $statusColor; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <span style="color: #28a745; font-weight: 600; font-size: 0.9rem;">Me</span>
                                        </td>
                                        <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                            <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                                        </td>
                                        <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                            <?php
                                             if ($risk['updated_at'] != $risk['created_at']) {
                                                echo date('M j, Y', strtotime($risk['updated_at']));
                                            } else {
                                                echo 'Not updated';
                                            }
                                            ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <a href="view_risk.php?id=<?php echo $risk['id']; ?>"
                                                style="background: #E60012; color: white; padding: 0.5rem 1rem; border-radius: 4px; text-decoration: none; font-size: 0.9rem; font-weight: 500; display: inline-block; text-align: center; transition: all 0.3s ease;"
                                               onmouseover="this.style.background='#B8000E';"
                                               onmouseout="this.style.background='#E60012';">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Procedures Tab -->
            <div id="procedures-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h1 class="card-title">Risk Reporting Procedures</h1>
                        <div>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print Procedures
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        
                        <!-- Table of Contents -->
                        <div class="toc">
                            <h3><i class="fas fa-list"></i> Table of Contents</h3>
                            <ul>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('overview')">1. Overview</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('auto-assignment')">2. Automatic Assignment System</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('departments')">3. Department Structure & Responsibilities</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('identification')">4. Risk Identification</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('assessment')">5. Risk Assessment</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('treatment')">6. Risk Treatment</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('monitoring')">7. Monitoring & Review</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('roles')">8. Roles & Responsibilities</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('matrix')">9. Risk Assessment Matrix</a></li>
                                <li><a href="javascript:void(0)" onclick="scrollToProcedureSection('definitions')">10. Field Definitions</a></li>
                            </ul>
                        </div>

                        <!-- 1. Overview -->
                        <div id="overview" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-info-circle"></i> 1. OVERVIEW</div>
                            <p>This document outlines the procedures for reporting, assessing, and managing risks within the Airtel Risk Management System. All staff members are required to follow these procedures when identifying and reporting risks.</p>
                            
                            <h4>Purpose</h4>
                            <ul>
                                <li>Establish a systematic approach to risk identification and reporting</li>
                                <li>Ensure consistent risk assessment across all departments</li>
                                <li>Provide clear guidelines for risk treatment and monitoring</li>
                                <li>Enable effective risk communication to management and the Board</li>
                                <li>Implement automated assignment based on department structure</li>
                            </ul>
                            
                            <h4>Scope</h4>
                            <p>These procedures apply to all risks that may impact operations, including but not limited to:</p>
                            <ul>
                                <li>Strategic risks</li>
                                <li>Operational risks</li>
                                <li>Financial risks</li>
                                <li>Compliance and regulatory risks</li>
                                <li>Technology and cybersecurity risks</li>
                                <li>Reputational risks</li>
                                <li>Human resources risks</li>
                                <li>Environmental risks</li>
                            </ul>
                        </div>

                        <!-- 2. Automatic Assignment System -->
                        <div id="auto-assignment" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-robot"></i> 2. AUTOMATIC ASSIGNMENT SYSTEM</div>
                            
                            <h4>2.1 How Auto-Assignment Works</h4>
                            <p>Our system automatically assigns risks to appropriate risk owners based on the department selected during risk reporting. This ensures immediate accountability and faster response times.</p>
                            
                            <div class="auto-assignment-flow">
                                <h5><i class="fas fa-cogs"></i> Assignment Flow</h5>
                                <div class="flow-step">
                                    <i class="fas fa-user"></i>
                                    <span>User reports a risk and selects affected department</span>
                                </div>
                                <div class="flow-step">
                                    <i class="fas fa-search"></i>
                                    <span>System identifies department head/risk owner</span>
                                </div>
                                <div class="flow-step">
                                    <i class="fas fa-bell"></i>
                                    <span>Automatic notification sent to assigned risk owner</span>
                                </div>
                                <div class="flow-step">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span>Risk owner receives dashboard notification and email alert</span>
                                </div>
                                <div class="flow-step">
                                    <i class="fas fa-clock"></i>
                                    <span>24-hour response time expectation begins</span>
                                </div>
                            </div>
                            
                            <h4>2.2 Assignment Rules</h4>
                            <ul>
                                <li><strong>Primary Assignment:</strong> Based on the department most affected by the risk</li>
                                <li><strong>Secondary Assignment:</strong> Cross-functional risks may be assigned to multiple departments</li>
                                <li><strong>Escalation:</strong> High and critical risks are automatically escalated to senior management</li>
                                <li><strong>Backup Assignment:</strong> If primary risk owner is unavailable, assignment goes to deputy</li>
                            </ul>
                            
                            <h4>2.3 Notification System</h4>
                            <ul>
                                <li>Immediate dashboard notification</li>
                                <li>Email notification within 5 minutes</li>
                                <li>SMS notification for critical risks</li>
                                <li>Daily digest for pending actions</li>
                            </ul>
                        </div>

                        <!-- 3. Department Structure -->
                        <div id="departments" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-building"></i> 3. DEPARTMENT STRUCTURE & RESPONSIBILITIES</div>
                            
                            <h4>3.1 Department Risk Owners</h4>
                            <p>Each department has designated risk owners responsible for managing risks within their area:</p>
                            
                            <div class="department-grid">
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-chart-line"></i> Finance & Accounting</div>
                                    <ul>
                                        <li>Financial reporting risks</li>
                                        <li>Budget and cash flow risks</li>
                                        <li>Audit and compliance risks</li>
                                        <li>Investment and credit risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-cogs"></i> Operations</div>
                                    <ul>
                                        <li>Service delivery risks</li>
                                        <li>Supply chain risks</li>
                                        <li>Quality control risks</li>
                                        <li>Process efficiency risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-laptop-code"></i> Information Technology</div>
                                    <ul>
                                        <li>Cybersecurity risks</li>
                                        <li>System availability risks</li>
                                        <li>Data integrity risks</li>
                                        <li>Technology upgrade risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-users"></i> Human Resources</div>
                                    <ul>
                                        <li>Talent retention risks</li>
                                        <li>Compliance and legal risks</li>
                                        <li>Training and development risks</li>
                                        <li>Employee safety risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-bullhorn"></i> Marketing & Sales</div>
                                    <ul>
                                        <li>Brand reputation risks</li>
                                        <li>Customer satisfaction risks</li>
                                        <li>Market competition risks</li>
                                        <li>Revenue generation risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-balance-scale"></i> Legal & Compliance</div>
                                    <ul>
                                        <li>Regulatory compliance risks</li>
                                        <li>Legal liability risks</li>
                                        <li>Contract management risks</li>
                                        <li>Intellectual property risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-handshake"></i> Customer Service</div>
                                    <ul>
                                        <li>Service quality risks</li>
                                        <li>Customer complaint risks</li>
                                        <li>Response time risks</li>
                                        <li>Customer data risks</li>
                                    </ul>
                                </div>
                                
                                <div class="department-card">
                                    <div class="department-title"><i class="fas fa-network-wired"></i> Network & Infrastructure</div>
                                    <ul>
                                        <li>Network outage risks</li>
                                        <li>Infrastructure failure risks</li>
                                        <li>Capacity planning risks</li>
                                        <li>Maintenance risks</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <h4>3.2 Cross-Departmental Risks</h4>
                            <p>Some risks affect multiple departments and require coordinated response:</p>
                            <ul>
                                <li><strong>Business Continuity:</strong> All departments involved</li>
                                <li><strong>Data Breach:</strong> IT, Legal, Customer Service, Marketing</li>
                                <li><strong>Regulatory Changes:</strong> Legal, Finance, Operations</li>
                                <li><strong>Major System Outage:</strong> IT, Operations, Customer Service</li>
                            </ul>
                        </div>

                        <!-- 4. Risk Identification -->
                        <div id="identification" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-search"></i> 4. RISK IDENTIFICATION</div>
                            
                            <h4>4.1 When to Report a Risk</h4>
                            <p>Risks should be reported when:</p>
                            <ul>
                                <li>A new risk is identified that could impact business objectives</li>
                                <li>An existing risk has changed in nature or severity</li>
                                <li>A risk event has occurred or is imminent</li>
                                <li>Regular risk reviews identify emerging risks</li>
                                <li>Department-specific risk indicators are triggered</li>
                            </ul>
                            
                            <h4>4.2 Risk Identification Process</h4>
                            <ol>
                                <li><strong>Identify the Risk:</strong> Clearly describe what could go wrong</li>
                                <li><strong>Select Department:</strong> Choose the primary affected department (triggers auto-assignment)</li>
                                <li><strong>Determine Risk Type:</strong> Classify as "Existing" or "New" risk</li>
                                <li><strong>Board Reporting:</strong> Determine if the risk requires Board attention</li>
                                <li><strong>Document Details:</strong> Complete all required fields in the risk register</li>
                            </ol>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Name</div>
                                <div class="field-description">A clear, concise title that describes the risk (e.g., "Customer Data Breach", "Regulatory Non-Compliance")</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Description</div>
                                <div class="field-description">Detailed explanation of the risk, including what could happen and potential consequences</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Cause of Risk</div>
                                <div class="field-description">Root causes or factors that could trigger the risk event</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Department</div>
                                <div class="field-description">Primary department affected by the risk - this determines automatic assignment to the appropriate risk owner</div>
                            </div>
                        </div>

                        <!-- 5. Risk Assessment -->
                        <div id="assessment" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-calculator"></i> 5. RISK ASSESSMENT</div>
                            
                            <h4>5.1 Two-Stage Assessment</h4>
                            <p>All risks must be assessed at two levels:</p>
                            
                            <h5>Inherent Risk (Gross Risk)</h5>
                            <p>The risk level before considering any controls or mitigation measures currently in place.</p>
                            
                            <h5>Residual Risk (Net Risk)</h5>
                            <p>The risk level after considering existing controls and mitigation measures.</p>
                            
                            <h4>5.2 Assessment Criteria</h4>
                            
                            <h5>Likelihood Scale (1-5)</h5>
                            <ul>
                                <li><strong>1 - Very Low:</strong> Rare occurrence, less than 5% chance</li>
                                <li><strong>2 - Low:</strong> Unlikely to occur, 5-25% chance</li>
                                <li><strong>3 - Medium:</strong> Possible occurrence, 25-50% chance</li>
                                <li><strong>4 - High:</strong> Likely to occur, 50-75% chance</li>
                                <li><strong>5 - Very High:</strong> Almost certain, more than 75% chance</li>
                            </ul>
                            
                            <h5>Consequence Scale (1-5)</h5>
                            <ul>
                                <li><strong>1 - Very Low:</strong> Minimal impact on operations, reputation, or finances</li>
                                <li><strong>2 - Low:</strong> Minor impact, easily manageable</li>
                                <li><strong>3 - Medium:</strong> Moderate impact requiring management attention</li>
                                <li><strong>4 - High:</strong> Significant impact affecting business operations</li>
                                <li><strong>5 - Very High:</strong> Severe impact threatening business continuity</li>
                            </ul>
                            
                            <h4>5.3 Risk Rating Calculation</h4>
                            <p>Risk Rating = Likelihood × Consequence</p>
                            <p>This produces a score from 1-25, categorized as:</p>
                            <ul>
                                <li><strong>Low Risk:</strong> 1-3 (Green)</li>
                                <li><strong>Medium Risk:</strong> 4-8 (Yellow)</li>
                                <li><strong>High Risk:</strong> 9-14 (Orange)</li>
                                <li><strong>Critical Risk:</strong> 15-25 (Red)</li>
                            </ul>
                            
                            <h4>5.4 Department-Specific Assessment Guidelines</h4>
                            <p>Each department may have specific criteria for assessing risks within their domain. Risk owners should consider department-specific impact factors when conducting assessments.</p>
                        </div>

                        <!-- 6. Risk Treatment -->
                        <div id="treatment" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-shield-alt"></i> 6. RISK TREATMENT</div>
                            
                            <h4>6.1 Treatment Strategies</h4>
                            <ul>
                                <li><strong>Accept:</strong> Acknowledge the risk and take no further action</li>
                                <li><strong>Avoid:</strong> Eliminate the risk by changing processes or activities</li>
                                <li><strong>Mitigate:</strong> Reduce likelihood or consequence through controls</li>
                                <li><strong>Transfer:</strong> Share or transfer risk through insurance or contracts</li>
                            </ul>
                            
                            <h4>6.2 Required Documentation</h4>
                            
                            <div class="field-definition">
                                <div class="field-name">Treatment Action</div>
                                <div class="field-description">Specific actions to be taken to address the risk (Accept/Avoid/Mitigate/Transfer)</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Controls / Action Plan</div>
                                <div class="field-description">Detailed description of controls to be implemented or actions to be taken, including timelines and resources required</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Planned Completion Date</div>
                                <div class="field-description">Target date for completing risk treatment actions</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Owner</div>
                                <div class="field-description">Individual responsible for managing the risk and implementing treatment actions (automatically assigned based on department)</div>
                            </div>
                        </div>

                        <!-- 7. Monitoring & Review -->
                        <div id="monitoring" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-chart-bar"></i> 7. MONITORING & REVIEW</div>
                            
                            <h4>7.1 Ongoing Monitoring</h4>
                            <p>All risks must be regularly monitored and reviewed:</p>
                            <ul>
                                <li><strong>Critical Risks:</strong> Weekly review</li>
                                <li><strong>High Risks:</strong> Monthly review</li>
                                <li><strong>Medium Risks:</strong> Quarterly review</li>
                                <li><strong>Low Risks:</strong> Annual review</li>
                            </ul>
                            
                            <h4>7.2 Status Tracking</h4>
                            <ul>
                                <li><strong>Open:</strong> Risk identified, treatment not yet started</li>
                                <li><strong>In Progress:</strong> Treatment actions are being implemented</li>
                                <li><strong>Completed:</strong> Treatment actions completed, awaiting verification</li>
                                <li><strong>Overdue:</strong> Treatment actions past planned completion date</li>
                                <li><strong>Closed:</strong> Risk adequately treated or no longer relevant</li>
                            </ul>
                            
                            <div class="field-definition">
                                <div class="field-name">Progress Update / Report</div>
                                <div class="field-description">Regular updates on the status of risk treatment actions, including any changes to risk levels or new developments</div>
                            </div>
                            
                            <h4>7.3 Automated Reminders</h4>
                            <p>The system automatically sends reminders to risk owners:</p>
                            <ul>
                                <li>7 days before planned completion date</li>
                                <li>On the planned completion date</li>
                                <li>Weekly reminders for overdue items</li>
                                <li>Monthly summary reports to department heads</li>
                            </ul>
                        </div>

                        <!-- 8. Roles & Responsibilities -->
                        <div id="roles" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-users-cog"></i> 8. ROLES & RESPONSIBILITIES</div>
                            
                            <h4>All Staff</h4>
                            <ul>
                                <li>Identify and report risks within their area of responsibility</li>
                                <li>Select appropriate department when reporting risks</li>
                                <li>Implement assigned risk treatment actions</li>
                                <li>Provide updates on risk status when requested</li>
                            </ul>
                            
                            <h4>Risk Owners (Department Heads)</h4>
                            <ul>
                                <li>Take ownership of automatically assigned risks</li>
                                <li>Respond to risk assignments within 24 hours</li>
                                <li>Develop and implement risk treatment plans</li>
                                <li>Provide regular updates on risk status</li>
                                <li>Escalate significant changes in risk levels</li>
                                <li>Manage department-specific risk registers</li>
                            </ul>
                            
                            <h4>Deputy Risk Owners</h4>
                            <ul>
                                <li>Act as backup when primary risk owner is unavailable</li>
                                <li>Support risk assessment and treatment activities</li>
                                <li>Maintain awareness of department risk profile</li>
                            </ul>
                            
                            <h4>Compliance Team</h4>
                            <ul>
                                <li>Maintain the central risk register</li>
                                <li>Monitor auto-assignment system performance</li>
                                <li>Facilitate risk assessment processes</li>
                                <li>Prepare risk reports for management</li>
                                <li>Monitor compliance with risk procedures</li>
                                <li>Manage system notifications and escalations</li>
                            </ul>
                            
                            <h4>Management</h4>
                            <ul>
                                <li>Review and approve risk treatment strategies</li>
                                <li>Allocate resources for risk management</li>
                                <li>Escalate significant risks to the Board</li>
                                <li>Ensure risk procedures are followed</li>
                                <li>Review department risk performance</li>
                            </ul>
                        </div>

                        <!-- 9. Risk Assessment Matrix -->
                        <div id="matrix" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-table"></i> 9. RISK ASSESSMENT MATRIX</div>
                            
                            <table class="risk-matrix-table">
                                <thead>
                                    <tr>
                                        <th rowspan="2">Likelihood</th>
                                        <th colspan="5">Consequence</th>
                                    </tr>
                                    <tr>
                                        <th>1 - Very Low</th>
                                        <th>2 - Low</th>
                                        <th>3 - Medium</th>
                                        <th>4 - High</th>
                                        <th>5 - Very High</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>5 - Very High</th>
                                        <td class="matrix-2">5</td>
                                        <td class="matrix-3">10</td>
                                        <td class="matrix-4">15</td>
                                        <td class="matrix-5">20</td>
                                        <td class="matrix-5">25</td>
                                    </tr>
                                    <tr>
                                        <th>4 - High</th>
                                        <td class="matrix-1">4</td>
                                        <td class="matrix-2">8</td>
                                        <td class="matrix-3">12</td>
                                        <td class="matrix-4">16</td>
                                        <td class="matrix-5">20</td>
                                    </tr>
                                    <tr>
                                        <th>3 - Medium</th>
                                        <td class="matrix-1">3</td>
                                        <td class="matrix-2">6</td>
                                        <td class="matrix-3">9</td>
                                        <td class="matrix-3">12</td>
                                        <td class="matrix-4">15</td>
                                    </tr>
                                    <tr>
                                        <th>2 - Low</th>
                                        <td class="matrix-1">2</td>
                                        <td class="matrix-1">4</td>
                                        <td class="matrix-2">6</td>
                                        <td class="matrix-2">8</td>
                                        <td class="matrix-3">10</td>
                                    </tr>
                                    <tr>
                                        <th>1 - Very Low</th>
                                        <td class="matrix-1">1</td>
                                        <td class="matrix-1">2</td>
                                        <td class="matrix-1">3</td>
                                        <td class="matrix-1">4</td>
                                        <td class="matrix-2">5</td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div style="margin-top: 1rem;">
                                <p><strong>Risk Level Legend:</strong></p>
                                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                    <span class="matrix-1" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Low (1-3)</span>
                                    <span class="matrix-2" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Medium (4-8)</span>
                                    <span class="matrix-3" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">High (9-14)</span>
                                    <span class="matrix-5" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Critical (15-25)</span>
                                </div>
                            </div>
                        </div>

                        <!-- 10. Field Definitions -->
                        <div id="definitions" class="procedure-section">
                            <div class="procedure-title"><i class="fas fa-book"></i> 10. FIELD DEFINITIONS</div>
                            
                            <h4>Risk Information Fields</h4>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Name</div>
                                <div class="field-description">A clear, concise title that describes the risk. Should be specific enough to distinguish from other risks but brief enough for easy reference.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Description</div>
                                <div class="field-description">Detailed explanation of the risk, including what could happen, when it might occur, and why it matters to the organization.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Cause of Risk</div>
                                <div class="field-description">The underlying factors, conditions, or events that could trigger the risk. Understanding causes helps in developing effective treatments.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Category</div>
                                <div class="field-description">Classification of the risk type (Strategic, Operational, Financial, Compliance, Technology, Reputational, HR, Environmental).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Department</div>
                                <div class="field-description">The primary department affected by or responsible for managing the risk. This field triggers automatic assignment to the appropriate risk owner.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Existing or New</div>
                                <div class="field-description">Indicates whether this is a new risk being reported for the first time, or an existing risk that has changed or needs updating.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">To be Reported to Board</div>
                                <div class="field-description">Indicates whether this risk is significant enough to require Board attention and should be included in Board risk reports.</div>
                            </div>
                            
                            <h4>Assessment Fields</h4>
                            
                            <div class="field-definition">
                                <div class="field-name">Inherent Likelihood</div>
                                <div class="field-description">The probability of the risk occurring without considering any existing controls or mitigation measures (scale 1-5).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Inherent Consequence</div>
                                <div class="field-description">The potential impact if the risk occurs without considering any existing controls or mitigation measures (scale 1-5).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Residual Likelihood</div>
                                <div class="field-description">The probability of the risk occurring after considering existing controls and mitigation measures (scale 1-5).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Residual Consequence</div>
                                <div class="field-description">The potential impact if the risk occurs after considering existing controls and mitigation measures (scale 1-5).</div>
                            </div>
                            
                            <h4>Treatment Fields</h4>
                            
                            <div class="field-definition">
                                <div class="field-name">Treatment Action</div>
                                <div class="field-description">The chosen strategy for addressing the risk: Accept (take no action), Avoid (eliminate the risk), Mitigate (reduce likelihood/impact), or Transfer (share/transfer the risk).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Controls / Action Plan</div>
                                <div class="field-description">Detailed description of specific actions, controls, or measures to be implemented to treat the risk, including timelines and resource requirements.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Planned Completion Date</div>
                                <div class="field-description">The target date by which risk treatment actions should be completed. Used for tracking and automated reminders.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Owner</div>
                                <div class="field-description">The individual responsible for managing the risk an implementing treatment actions. Automatically assigned based on the selected department.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Progress Update / Report</div>
                                <div class="field-description">Regular updates on the status of risk treatment implementation, including progress made, obstacles encountered, and any changes to the risk profile.</div>
                            </div>
                            
                            <h4>Status Fields</h4>
                            
                            <div class="field-definition">
                                <div class="field-name">Risk Status</div>
                                <div class="field-description">Current status of risk management: Open (identified, not yet addressed), In Progress (treatment underway), Completed (treatment finished), Overdue (past planned completion), Closed (adequately managed).</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Created At</div>
                                <div class="field-description">Date and time when the risk was first reported in the system.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Updated At</div>
                                <div class="field-description">Date and time of the most recent update to the risk record.</div>
                            </div>
                            
                            <div class="field-definition">
                                <div class="field-name">Reported By</div>
                                <div class="field-description">The individual who initially identified and reported the risk to the system.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        // Tab functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all nav links
            const navLinks = document.querySelectorAll('.nav-item a');
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected tab
            const selectedTab = document.getElementById(tabName + '-tab');
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Add active class to clicked nav link
            const activeLink = document.querySelector(`[onclick="showTab('${tabName}')"]`);
            if (activeLink) {
                activeLink.classList.add('active');
            }
            
            // Update URL without page reload
            const url = new URL(window.location);
            if (tabName === 'dashboard') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tabName);
            }
            window.history.pushState({}, '', url);
        }
        
        // Initialize tab based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                showTab(tab);
            }
        });
        
        // Chart initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Risk Level Chart
            const levelCtx = document.getElementById('levelChart');
            if (levelCtx) {
                new Chart(levelCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Low', 'Medium', 'High', 'Critical'],
                        datasets: [{
                            data: [
                                <?php echo $risk_levels['low_risks']; ?>,
                                <?php echo $risk_levels['medium_risks']; ?>,
                                <?php echo $risk_levels['high_risks']; ?>,
                                <?php echo $risk_levels['critical_risks']; ?>
                            ],
                            backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
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
            }
            
            // Risk Category Chart
            const categoryCtx = document.getElementById('categoryChart');
            if (categoryCtx) {
                new Chart(categoryCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($cat) { return '"' . htmlspecialchars($cat['risk_category'] ?? 'Uncategorized') . '"'; }, $risk_by_category)); ?>],
                        datasets: [{
                            label: 'Number of Risks',
                            data: [<?php echo implode(',', array_column($risk_by_category, 'count')); ?>],
                            backgroundColor: '#E60012',
                            borderColor: '#B8000E',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }
        });
        
        // Search functionality for My Reports
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchRisks');
            const statusFilter = document.getElementById('statusFilter');
            const tableBody = document.getElementById('reportsTableBody');
            
            if (searchInput && statusFilter && tableBody) {
                function filterReports() {
                    const searchTerm = searchInput.value.toLowerCase();
                    const statusFilter = document.getElementById('statusFilter').value;
                    const rows = tableBody.querySelectorAll('.risk-report-row');
                    
                    rows.forEach(row => {
                        const searchData = row.getAttribute('data-search');
                        const statusData = row.getAttribute('data-status');
                        
                        const matchesSearch = searchData.includes(searchTerm);
                        const matchesStatus = !statusFilter || statusData === statusFilter;
                        
                        if (matchesSearch && matchesStatus) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }
                
                searchInput.addEventListener('input', filterReports);
                statusFilter.addEventListener('change', filterReports);
            }
        });
        
        // Notification functionality
        let notificationDropdownVisible = false;
        let readNotifications = JSON.parse(localStorage.getItem('readNotifications') || '[]');
        
        function toggleNavNotifications() {
            const dropdown = document.getElementById('navNotificationDropdown');
            if (dropdown) {
                notificationDropdownVisible = !notificationDropdownVisible;
                dropdown.classList.toggle('show', notificationDropdownVisible);
                
                if (notificationDropdownVisible) {
                    positionNotificationDropdown();
                    document.addEventListener('click', handleOutsideClick);
                } else {
                    document.removeEventListener('click', handleOutsideClick);
                }
            }
        }
        
        function positionNotificationDropdown() {
            const container = document.querySelector('.nav-notification-container');
            const dropdown = document.getElementById('navNotificationDropdown');
            
            if (container && dropdown) {
                const rect = container.getBoundingClientRect();
                const dropdownWidth = 400;
                
                let left = rect.left;
                if (left + dropdownWidth > window.innerWidth) {
                    left = window.innerWidth - dropdownWidth - 20;
                }
                if (left < 20) {
                    left = 20;
                }
                
                dropdown.style.left = left + 'px';
                dropdown.style.top = (rect.bottom + 5) + 'px';
            }
        }
        
        function handleOutsideClick(event) {
            const container = document.querySelector('.nav-notification-container');
            const dropdown = document.getElementById('navNotificationDropdown');
            
            if (container && dropdown && 
                !container.contains(event.target) && 
                !dropdown.contains(event.target)) {
                notificationDropdownVisible = false;
                dropdown.classList.remove('show');
                document.removeEventListener('click', handleOutsideClick);
            }
        }
        
        function markNavAsRead(notificationId) {
            const notificationItem = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
            if (notificationItem) {
                notificationItem.classList.remove('unread');
                notificationItem.classList.add('read');
                
                const markReadBtn = notificationItem.querySelector('.mark-read-btn');
                if (markReadBtn) {
                    markReadBtn.style.display = 'none';
                }
                
                if (!readNotifications.includes(notificationId)) {
                    readNotifications.push(notificationId);
                    localStorage.setItem('readNotifications', JSON.stringify(readNotifications));
                }
                
                updateNotificationCount();
            }
        }
        
        function clearAllNotifications() {
            const notificationItems = document.querySelectorAll('.nav-notification-item');
            notificationItems.forEach((item, index) => {
                item.classList.remove('unread');
                item.classList.add('read');
                
                const markReadBtn = item.querySelector('.mark-read-btn');
                if (markReadBtn) {
                    markReadBtn.style.display = 'none';
                }
                
                if (!readNotifications.includes(index)) {
                    readNotifications.push(index);
                }
            });
            
            localStorage.setItem('readNotifications', JSON.stringify(readNotifications));
            updateNotificationCount();
            
            // Close dropdown after clearing
            notificationDropdownVisible = false;
            const dropdown = document.getElementById('navNotificationDropdown');
            if (dropdown) {
                dropdown.classList.remove('show');
            }
        }
        
        function updateNotificationCount() {
            const badge = document.querySelector('.nav-notification-badge');
            const bell = document.querySelector('.nav-notification-bell');
            const container = document.querySelector('.nav-notification-container');
            
            const totalNotifications = document.querySelectorAll('.nav-notification-item').length;
            const unreadCount = totalNotifications - readNotifications.length;
            
            if (badge) {
                if (unreadCount > 0) {
                    badge.textContent = unreadCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            
            if (bell) {
                if (unreadCount > 0) {
                    bell.classList.add('has-notifications');
                } else {
                    bell.classList.remove('has-notifications');
                }
            }
            
            if (container) {
                if (unreadCount === 0) {
                    container.classList.add('nav-notification-empty');
                } else {
                    container.classList.remove('nav-notification-empty');
                }
            }
        }
        
        // Initialize notification states on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Apply read states from localStorage
            readNotifications.forEach(notificationId => {
                const notificationItem = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.classList.remove('unread');
                    notificationItem.classList.add('read');
                    
                    const markReadBtn = notificationItem.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.style.display = 'none';
                    }
                }
            });
            
            updateNotificationCount();
        });
        
        // Procedure section scrolling
        function scrollToProcedureSection(sectionId) {
            // First ensure we're on the procedures tab
            showTab('procedures');
            
            // Wait a moment for the tab to be shown, then scroll
            setTimeout(() => {
                const section = document.getElementById(sectionId);
                if (section) {
                    section.scrollIntoView({ 
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }, 100);
        }
        
        // Handle window resize for notification dropdown positioning
        window.addEventListener('resize', function() {
            if (notificationDropdownVisible) {
                positionNotificationDropdown();
            }
        });
    </script>
</body>
</html>
