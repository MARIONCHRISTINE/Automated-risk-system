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
            padding-top: 150px; /* Increased to accommodate both header and nav */
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
        
        /* Navigation Bar */
        .nav {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            position: fixed;
            top: 100px; /* Position below header */
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
        /* Enhanced Responsive Styles - IMPROVED VERSION */
@media (max-width: 768px) {
    body {
        padding-top: 200px; /* Increased padding for mobile header + nav */
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
        top: 120px; /* Adjust for mobile header height */
        padding: 0.25rem 0; /* Reduced padding */
    }
    
    .nav-content {
        padding: 0 0.5rem; /* Reduced horizontal padding */
        overflow-x: auto; /* Enable horizontal scrolling */
        -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    }
    
    .nav-menu {
        /* Enable horizontal scrolling layout */
        flex-wrap: nowrap; /* Don't wrap items */
        justify-content: flex-start; /* Align to start */
        gap: 0;
        min-width: max-content; /* Ensure full width of items */
        padding: 0 0.5rem; /* Add some padding */
    }
    
    .nav-item {
        /* Keep horizontal but ensure minimum width */
        flex: 0 0 auto; /* Don't grow or shrink */
        min-width: 80px; /* Minimum touch target */
    }
    
    .nav-item a {
        padding: 0.75rem 0.5rem; /* Reduced padding */
        font-size: 0.75rem; /* Smaller font */
        text-align: center;
        border-bottom: 3px solid transparent;
        border-left: none;
        width: 100%;
        white-space: nowrap; /* Prevent text wrapping */
        min-height: 44px; /* Minimum touch target height */
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
    
    /* Notification container adjustments for mobile */
    .nav-notification-container {
        padding: 0.75rem 0.5rem;
        font-size: 0.75rem;
        min-width: 80px;
    }
    
    .nav-notification-text {
        font-size: 0.65rem; /* Keep text but smaller */
    }
    
    .nav-notification-bell {
        font-size: 1rem;
    }
    
    .nav-notification-badge {
        width: 16px;
        height: 16px;
        font-size: 0.6rem;
    }
    
    /* Add scroll indicators */
    .nav-content::before,
    .nav-content::after {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        width: 20px;
        pointer-events: none;
        z-index: 1;
    }
    
    .nav-content::before {
        left: 0;
        background: linear-gradient(to right, rgba(248, 249, 250, 1), rgba(248, 249, 250, 0));
    }
    
    .nav-content::after {
        right: 0;
        background: linear-gradient(to left, rgba(248, 249, 250, 1), rgba(248, 249, 250, 0));
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
    
    /* Mobile Dashboard Grid */
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
    
    /* Mobile Charts */
    .chart-container {
        height: 250px;
    }
}
/* Tablet Responsive */
@media (max-width: 1024px) and (min-width: 769px) {
    .nav-menu {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .nav-item a {
        padding: 0.75rem 1rem; /* Slightly reduced padding */
        font-size: 0.85rem; /* Slightly smaller font */
    }
    
    .dashboard-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
/* Small Mobile - Extra adjustments */
@media (max-width: 480px) {
    body {
        padding-top: 180px; /* Reduced since nav is smaller */
    }
    
    .header {
        padding: 1rem;
    }
    
    .main-title {
        font-size: 1.1rem;
    }
    
    .sub-title {
        font-size: 0.8rem;
    }
    
    .nav {
        top: 110px; /* Adjust for smaller header */
    }
    
    .nav-content {
        padding: 0 0.25rem; /* Even less padding */
    }
    
    .nav-item {
        min-width: 70px; /* Smaller minimum width */
    }
    
    .nav-item a {
        padding: 0.5rem 0.25rem; /* Even smaller padding */
        font-size: 0.65rem; /* Smaller font */
        gap: 0.125rem;
    }
    
    /* Hide notification text completely on very small screens */
    .nav-notification-text {
        display: none;
    }
    
    .nav-notification-container {
        min-width: 50px;
        padding: 0.5rem 0.25rem;
    }
    
    .stat-number {
        font-size: 1.8rem;
    }
    
    .stat-label {
        font-size: 0.8rem;
    }
}
/* Very Small Mobile - 360px and below */
@media (max-width: 360px) {
    body {
        padding-top: 170px;
    }
    
    .nav {
        top: 100px;
    }
    
    .nav-item {
        min-width: 60px;
    }
    
    .nav-item a {
        padding: 0.4rem 0.2rem;
        font-size: 0.6rem;
        gap: 0.1rem;
    }
    
    .nav-notification-container {
        min-width: 45px;
        padding: 0.4rem 0.2rem;
    }
    
    .nav-notification-bell {
        font-size: 0.9rem;
    }
    
    .nav-notification-badge {
        width: 14px;
        height: 14px;
        font-size: 0.55rem;
    }
}
/* Landscape orientation on mobile */
@media (max-width: 768px) and (orientation: landscape) {
    body {
        padding-top: 140px;
    }
    
    .header {
        padding: 0.8rem 1rem;
    }
    
    .nav {
        top: 80px;
    }
    
    .nav-item a {
        padding: 0.5rem 0.4rem;
        font-size: 0.7rem;
    }
}
/* Ensure navbar scrolling works smoothly */
.nav-content {
    position: relative;
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* Internet Explorer 10+ */
}
.nav-content::-webkit-scrollbar {
    display: none; /* WebKit */
}
/* Add smooth scroll behavior */
.nav-menu {
    scroll-behavior: smooth;
}
/* Improve touch targets for mobile */
@media (max-width: 768px) {
    .nav-item a {
        -webkit-tap-highlight-color: rgba(230, 0, 18, 0.1);
        tap-highlight-color: rgba(230, 0, 18, 0.1);
    }
    
    .nav-item a:active {
        background-color: rgba(230, 0, 18, 0.15);
        transform: scale(0.98);
    }
}
/* Navigation Notification Styles */
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
.nav-notification-dropdown.expanded {
    box-shadow: 0 25px 50px rgba(0,0,0,0.25) !important;
    width: 90vw !important;
    max-height: 90vh !important;
    top: 5vh !important;
    left: 5vw !important;
    right: 5vw !important;
    transform: none !important;
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
.nav-notification-dropdown.expanded .nav-notification-content {
    max-height: 75vh;
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
                        <div class="user-role">Risk_owner • <?php echo htmlspecialchars($user['department'] ?? 'No Department'); ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        
        <!-- Navigation Bar -->
        
<nav class="nav">
    <div class="nav-content">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="risk_owner_dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_owner_dashboard.php' ? 'active' : ''; ?>">
                    🏠 Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                    📝 Report Risk
                </a>
            </li>
            <li class="nav-item">
                <a href="risk_owner_dashboard.php?tab=my-reports" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                    👀 My Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="risk-procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk-procedures.php' ? 'active' : ''; ?>" target="_blank">
                    📋 Procedures
                </a>
            </li>
            <!-- Enhanced Notifications -->
<li class="nav-item notification-nav-item">
    <?php
    // Get comprehensive notifications for the current user
    if (isset($_SESSION['user_id'])) {
        require_once 'config/database.php';
        $database = new Database();
        $conn = $database->getConnection();
        
        // Get treatment assignments
        $treatment_query = "SELECT rt.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                   owner.full_name as risk_owner_name, 'treatment' as notification_type,
                                   rt.created_at as notification_date
                            FROM risk_treatments rt
                            INNER JOIN risk_incidents ri ON rt.risk_id = ri.id
                            LEFT JOIN users owner ON ri.risk_owner_id = owner.id
                            WHERE rt.assigned_to = :user_id 
                            AND rt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        // Get risk assignments (risks assigned to this user)
        $assignment_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                    reporter.full_name as reporter_name, 'assignment' as notification_type,
                                    ri.updated_at as notification_date
                             FROM risk_incidents ri
                             LEFT JOIN users reporter ON ri.reported_by = reporter.id
                             WHERE ri.risk_owner_id = :user_id 
                             AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        // Get recent risk updates where user is involved
        $update_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                updater.full_name as updater_name, 'update' as notification_type,
                                ri.updated_at as notification_date
                         FROM risk_incidents ri
                         LEFT JOIN users updater ON ri.reported_by = updater.id
                         WHERE (ri.risk_owner_id = :user_id OR ri.reported_by = :user_id)
                         AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         AND ri.updated_at != ri.created_at";
        
        // Combine all notifications
        $all_notifications = [];
        
        // Get treatment notifications
        $stmt = $conn->prepare($treatment_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $treatment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_notifications = array_merge($all_notifications, $treatment_notifications);
        
        // Get assignment notifications
        $stmt = $conn->prepare($assignment_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_notifications = array_merge($all_notifications, $assignment_notifications);
        
        // Get update notifications
        $stmt = $conn->prepare($update_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $update_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_notifications = array_merge($all_notifications, $update_notifications);
        
        // Sort by date (newest first)
        usort($all_notifications, function($a, $b) {
            return strtotime($b['notification_date']) - strtotime($a['notification_date']);
        });
        
        // Limit to 20 most recent
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
                    <button onclick="markAllNavAsRead()" class="btn btn-sm btn-outline">Mark All Read</button>
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
                            <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
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
                            <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
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
                            <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="nav-notification-footer">
                <div class="flex justify-between items-center">
                    <button onclick="expandNavNotifications()" class="btn btn-sm btn-primary" id="navExpandButton">
                        <i class="fas fa-expand-arrows-alt"></i> Expand View
                    </button>
                    <a href="notifications.php" class="btn btn-sm btn-outline">View All</a>
                </div>
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
            <h3 style="margin: 0; color: #8B4513; font-size: 1.2rem; font-weight: 600;">All My Risk Reports</h3>
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
                                $level = getRiskLevel($risk['probability'] ?? 0, $risk['impact'] ?? 0);
                                $levelText = getRiskLevelText($risk['probability'] ?? 0, $risk['impact'] ?? 0);
                                $levelColors = [
                                    'low' => '#28a745',
                                    'medium' => '#ffc107', 
                                    'high' => '#fd7e14',
                                    'critical' => '#dc3545',
                                    'not-assessed' => '#6c757d'
                                ];
                                ?>
                                <span style="background: <?php echo $levelColors[$level] ?? '#6c757d'; ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 500;">
                                    <?php echo $levelText; ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <?php 
                                $status = $risk['risk_status'] ?? 'pending';
                                $statusColors = [
                                    'pending' => '#ffc107',
                                    'in_progress' => '#007bff',
                                    'completed' => '#28a745',
                                    'cancelled' => '#dc3545'
                                ];
                                $statusLabels = [
                                    'pending' => 'Open',
                                    'in_progress' => 'In Progress', 
                                    'completed' => 'Completed',
                                    'cancelled' => 'Cancelled'
                                ];
                                ?>
                                <span style="background: <?php echo $statusColors[$status] ?? '#6c757d'; ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 500;">
                                    <?php echo $statusLabels[$status] ?? ucfirst($status); ?>
                                </span>
                            </td>
                            <td style="padding: 1rem;">
                                <?php if ($risk['risk_owner_name']): ?>
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <div style="width: 32px; height: 32px; background: #E60012; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8rem;">
                                            <?php echo strtoupper(substr($risk['risk_owner_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 500; color: #333; font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($risk['risk_owner_name']); ?>
                                            </div>
                                            <?php if ($risk['risk_owner_id'] == $_SESSION['user_id']): ?>
                                                <div style="font-size: 0.75rem; color: #28a745;">You are the owner</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #ffc107; font-style: italic; font-size: 0.9rem;">Auto-assigning...</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                                <div style="font-size: 0.8rem; color: #999;">
                                    <?php echo date('g:i A', strtotime($risk['created_at'])); ?>
                                </div>
                            </td>
                            <td style="padding: 1rem; color: #666; font-size: 0.9rem;">
                                <?php echo date('M j, Y', strtotime($risk['updated_at'])); ?>
                                <div style="font-size: 0.8rem; color: #999;">
                                    <?php echo date('g:i A', strtotime($risk['updated_at'])); ?>
                                </div>
                            </td>
                            <td style="padding: 1rem;">
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <a href="view_risk.php?id=<?php echo $risk['id']; ?>" 
                                       style="background: #007bff; color: white; padding: 0.4rem 0.8rem; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: 500;">
                                        View
                                    </a>
                                    <?php if ($risk['risk_owner_id'] == $_SESSION['user_id']): ?>
                                        <a href="risk_assessment.php?id=<?php echo $risk['id']; ?>" 
                                           style="background: #28a745; color: white; padding: 0.4rem 0.8rem; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: 500;">
                                            Manage
                                        </a>
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
        // Risk Category Chart (NEW)
        const categoryData = <?php echo json_encode($risk_by_category); ?>;
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        if (categoryData.length > 0) {
            const categoryColors = {
                'Strategic Risk': '#6f42c1',
                'Operational Risk': '#dc3545',
                'Financial Risk': '#28a745',
                'Compliance Risk': '#ffc107',
                'Technology Risk': '#007bff',
                'Reputational Risk': '#fd7e14',
                'Human Resources Risk': '#20c997',
                'Environmental Risk': '#6c757d'
            };
            new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: categoryData.map(item => item.risk_category || 'Uncategorized'),
                    datasets: [{
                        data: categoryData.map(item => item.count),
                        backgroundColor: categoryData.map(item => 
                            categoryColors[item.risk_category] || '#6c757d'
                        ),
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} risks (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            categoryCtx.canvas.parentNode.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No risk categories data available</p>';
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
            if (event.target) {
                event.target.classList.add('active');
            }
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
        // Handle tab parameter from URL
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            if (tab) {
                showTab(tab);
            }
        });
        // IMPROVED BROWSER BACK BUTTON HANDLING
        document.addEventListener('DOMContentLoaded', function() {
            // Get the referrer (where user came from)
            const referrer = document.referrer;
            
            // Check if user came from login page
            const cameFromLogin = referrer.includes('login.php') || referrer.includes('logout.php') || !referrer;
            
            if (cameFromLogin) {
                // If user came from login, replace the current history entry
                // This prevents the back button from going to login
                if (window.history.replaceState) {
                    // Create a safe fallback page in history
                    window.history.replaceState(
                        { page: 'dashboard', preventBack: true }, 
                        'Risk Owner Dashboard', 
                        'risk_owner_dashboard.php'
                    );
                    
                    // Add a new entry so back button has somewhere safe to go
                    window.history.pushState(
                        { page: 'dashboard' }, 
                        'Risk Owner Dashboard', 
                        'risk_owner_dashboard.php'
                    );
                }
            } else {
                // User came from a valid page, store it
                sessionStorage.setItem('validReferrer', referrer);
            }
        });
        // Handle the browser's back button
        window.addEventListener('popstate', function(event) {
            // Check if this is our prevented back state
            if (event.state && event.state.preventBack) {
                // Don't allow going back to login, stay on dashboard
                window.history.pushState(
                    { page: 'dashboard' }, 
                    'Risk Owner Dashboard', 
                    'risk_owner_dashboard.php'
                );
                return;
            }
            
            // Check if we have a valid referrer stored
            const validReferrer = sessionStorage.getItem('validReferrer');
            
            if (validReferrer && !validReferrer.includes('login.php')) {
                // Go to the valid referrer
                window.location.href = validReferrer;
            } else {
                // No valid referrer, stay on dashboard
                window.history.pushState(
                    { page: 'dashboard' }, 
                    'Risk Owner Dashboard', 
                    'risk_owner_dashboard.php'
                );
            }
        });
        // Prevent navigation to login page via back button
        window.addEventListener('beforeunload', function() {
            // Clear login-related referrers
            const referrer = document.referrer;
            if (referrer && !referrer.includes('login.php') && !referrer.includes('logout.php')) {
                sessionStorage.setItem('lastValidPage', window.location.href);
            }
        });
        // Additional safety: Override history.back() if needed
        const originalBack = window.history.back;
        window.history.back = function() {
            const validReferrer = sessionStorage.getItem('validReferrer');
            
            if (validReferrer && !validReferrer.includes('login.php')) {
                window.location.href = validReferrer;
            } else {
                // Stay on current page or go to a safe default
                console.log('Back navigation blocked - would go to login');
            }
        };
// Navigation notification functionality with persistent storage
let navIsExpanded = false;
let navReadNotifications = new Set();
// Load read notifications from localStorage
function loadReadNotifications() {
    const stored = localStorage.getItem('readNotifications_<?php echo $_SESSION['user_id']; ?>');
    if (stored) {
        navReadNotifications = new Set(JSON.parse(stored));
    }
}
// Save read notifications to localStorage
function saveReadNotifications() {
    localStorage.setItem('readNotifications_<?php echo $_SESSION['user_id']; ?>', JSON.stringify([...navReadNotifications]));
}
function toggleNavNotifications() {
    // Don't open if no notifications
    const container = document.querySelector('.nav-notification-container');
    if (container && container.classList.contains('nav-notification-empty')) {
        return;
    }
    
    const dropdown = document.getElementById('navNotificationDropdown');
    if (!dropdown) return;
    
    if (dropdown.classList.contains('show')) {
        // Close dropdown
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
        
        const bell = document.querySelector('.nav-notification-bell');
        if (bell) {
            bell.classList.remove('has-notifications');
        }
        
        // Reset expanded state
        navIsExpanded = false;
        dropdown.classList.remove('expanded');
        updateExpandButton();
    } else {
        // Open dropdown
        dropdown.style.display = 'block';
        
        // Position dropdown near the notification icon
        const icon = document.querySelector('.nav-notification-container');
        if (icon) {
            const iconRect = icon.getBoundingClientRect();
            dropdown.style.top = `${iconRect.bottom + 10}px`;
            dropdown.style.right = `${Math.max(20, window.innerWidth - iconRect.right)}px`;
        }
        
        // Add show class for animation
        setTimeout(() => {
            dropdown.classList.add('show');
        }, 10);
        
        const bell = document.querySelector('.nav-notification-bell');
        if (bell) {
            bell.classList.add('has-notifications');
        }
        
        // Apply read states from localStorage
        applyReadStates();
        updateExpandButton();
    }
}
function expandNavNotifications() {
    const dropdown = document.getElementById('navNotificationDropdown');
    if (!dropdown) return;
    
    navIsExpanded = !navIsExpanded;
    
    if (navIsExpanded) {
        dropdown.classList.add('expanded');
        const content = dropdown.querySelector('.nav-notification-content');
        if (content) {
            content.style.maxHeight = '75vh';
        }
    } else {
        dropdown.classList.remove('expanded');
        const icon = document.querySelector('.nav-notification-container');
        if (icon) {
            const iconRect = icon.getBoundingClientRect();
            dropdown.style.top = `${iconRect.bottom + 10}px`;
            dropdown.style.right = `${Math.max(20, window.innerWidth - iconRect.right)}px`;
        }
        const content = dropdown.querySelector('.nav-notification-content');
        if (content) {
            content.style.maxHeight = '350px';
        }
    }
    
    updateExpandButton();
}
function updateExpandButton() {
    const button = document.getElementById('navExpandButton');
    if (!button) return;
    
    if (navIsExpanded) {
        button.innerHTML = '<i class="fas fa-compress-arrows-alt"></i> Collapse View';
        button.title = 'Collapse to normal size';
    } else {
        button.innerHTML = '<i class="fas fa-expand-arrows-alt"></i> Expand All Notifications';
        button.title = 'Expand to full screen';
    }
}
function applyReadStates() {
    const items = document.querySelectorAll('.nav-notification-item');
    items.forEach((item, index) => {
        if (navReadNotifications.has(index)) {
            item.style.background = '#f1f3f4';
            item.style.borderLeft = '4px solid #6c757d';
            item.style.opacity = '0.7';
            item.classList.add('read');
            item.classList.remove('unread');
        } else {
            item.style.background = '#fff3cd';
            item.style.borderLeft = '4px solid #ffc107';
            item.classList.add('unread');
            item.classList.remove('read');
        }
    });
    updateNavNotificationBadge();
}
function markNavAsRead(notificationId) {
    const item = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
    if (item) {
        item.style.background = '#f1f3f4';
        item.style.borderLeft = '4px solid #6c757d';
        item.style.opacity = '0.7';
        item.classList.add('read');
        item.classList.remove('unread');
        navReadNotifications.add(notificationId);
        saveReadNotifications();
        updateNavNotificationBadge();
    }
}
function markAllNavAsRead() {
    const items = document.querySelectorAll('.nav-notification-item');
    items.forEach((item, index) => {
        item.style.background = '#f1f3f4';
        item.style.borderLeft = '4px solid #6c757d';
        item.style.opacity = '0.7';
        item.classList.add('read');
        item.classList.remove('unread');
        navReadNotifications.add(index);
    });
    saveReadNotifications();
    updateNavNotificationBadge();
}
function updateNavNotificationBadge() {
    const badge = document.querySelector('.nav-notification-badge');
    const items = document.querySelectorAll('.nav-notification-item');
    const totalNotifications = items.length;
    const unreadCount = totalNotifications - navReadNotifications.size;
    
    if (badge) {
        if (unreadCount > 0) {
            badge.textContent = unreadCount;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
}
// Initialize notifications on page load
document.addEventListener('DOMContentLoaded', function() {
    loadReadNotifications();
    setTimeout(() => {
        applyReadStates();
        updateExpandButton();
    }, 100);
});
// Search and Filter functionality for Reports
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchRisks');
    const statusFilter = document.getElementById('statusFilter');
    const tableRows = document.querySelectorAll('.risk-report-row');
    function filterReports() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
        const statusFilter = document.getElementById('statusFilter');
        const selectedStatus = statusFilter ? statusFilter.value : '';
        tableRows.forEach(row => {
            const searchData = row.getAttribute('data-search') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            
            const matchesSearch = searchData.includes(searchTerm);
            const matchesStatus = !selectedStatus || rowStatus === selectedStatus;
            
            if (matchesSearch && matchesStatus) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', filterReports);
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', filterReports);
    }
});
    </script>
</body>
</html>
