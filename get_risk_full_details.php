<?php
include_once 'includes/auth.php';
include_once 'config/database.php';
// Check if risk ID is provided
if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Risk ID is required']);
    exit;
}
// Verify CSRF token if available
if (isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}
// Get and sanitize risk ID
$riskId = (int)$_POST['id'];
if ($riskId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid risk ID']);
    exit;
}
// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
// Get risk details with related information
$query = "SELECT r.*, 
          u.full_name as reporter_name, 
          ro.full_name as owner_name
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          LEFT JOIN users ro ON r.risk_owner_id = ro.id 
          WHERE r.id = :risk_id";
try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':risk_id', $riskId, PDO::PARAM_INT);
    $stmt->execute();
    $risk = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
if (!$risk) {
    echo json_encode(['success' => false, 'message' => 'Risk not found']);
    exit;
}
// Get risk categories
$riskCategories = [];
if (!empty($risk['risk_categories'])) {
    $decodedCategories = json_decode($risk['risk_categories'], true);
    if (is_array($decodedCategories)) {
        // Handle nested arrays (like [["Financial Exposure"]])
        if (count($decodedCategories) > 0 && is_array($decodedCategories[0])) {
            $riskCategories = $decodedCategories[0];
        } else {
            $riskCategories = $decodedCategories;
        }
    }
}
// Format risk level color
$riskLevelColor = '#6c757d'; // default for Not Assessed
switch (strtolower($risk['risk_level'])) {
    case 'critical': $riskLevelColor = '#dc3545'; break;
    case 'high': $riskLevelColor = '#fd7e14'; break;
    case 'medium': $riskLevelColor = '#ffc107'; break;
    case 'low': $riskLevelColor = '#28a745'; break;
}
// Format status color
$statusColor = '#17a2b8'; // default for open
switch (strtolower($risk['status'])) {
    case 'closed': $statusColor = '#28a745'; break;
    case 'in_progress': $statusColor = '#ffc107'; break;
    case 'completed': $statusColor = '#17a2b8'; break;
    case 'overdue': $statusColor = '#dc3545'; break;
}
// Build HTML for risk details with a clean table structure
$html = '<style>
.risk-details-container {
    font-family: Arial, sans-serif;
    margin: 20px;
    color: #333 !important;
}
.risk-header {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
    border-left: 4px solid #007bff;
}
.risk-title {
    margin: 0;
    color: #333 !important;
}
.risk-meta {
    margin-top: 10px;
}
.risk-id {
    font-size: 0.9em;
    font-weight: bold;
    color: #E60012;
}
.section-title {
    color: #495057 !important;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 8px;
    margin-top: 20px;
    margin-bottom: 15px;
    font-weight: bold;
}
.risk-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.risk-table th {
    background-color: #f8f9fa;
    text-align: left;
    padding: 12px;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057 !important;
}
.risk-table td {
    padding: 12px;
    border-bottom: 1px solid #dee2e6;
    color: #333 !important;
}
.risk-table tr:last-child td {
    border-bottom: none;
}
.risk-table tr:nth-child(even) {
    background-color: #f8f9fa;
}
.badge {
    padding: 5px 10px;
    border-radius: 4px;
    color: white;
    font-size: 0.8em;
    font-weight: normal;
}
.category-badge {
    margin-right: 5px;
    margin-bottom: 5px;
    display: inline-block;
}
.risk-section {
    margin-bottom: 20px;
}
.risk-description, .risk-cause, .treatment-action, .controls-action, .progress-update, .collaborators {
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 4px;
    border-left: 3px solid #007bff;
    color: #333 !important;
}
.document-link {
    display: inline-block;
    padding: 8px 15px;
    background-color: #007bff;
    color: white;
    text-decoration: none;
    border-radius: 4px;
}
.document-link:hover {
    background-color: #0069d9;
    color: white;
}
.risk-dates p {
    margin: 5px 0;
    color: #333 !important;
}
</style>';
$html .= '<div class="risk-details-container">';
$html .= '<div class="risk-header">';
$html .= '<h2 class="risk-title">' . htmlspecialchars($risk['risk_name']) . '</h2>';
$html .= '<div class="risk-meta">';
$html .= '<span class="badge" style="background-color: ' . $riskLevelColor . ';">' . htmlspecialchars($risk['risk_level']) . '</span> ';
$html .= '<span class="badge" style="background-color: ' . $statusColor . ';">' . htmlspecialchars($risk['status']) . '</span> ';
// CORRECTED: Display the custom risk ID in the format DEPT/YEAR/MONTH/SEQUENCE
// If custom_risk_id exists, use it, otherwise create the formatted ID
if (!empty($risk['custom_risk_id'])) {
    $formattedId = htmlspecialchars($risk['custom_risk_id']);
} else {
    // Fallback to the old format if custom_risk_id is not available
    $formattedId = !empty($risk['department_abbreviation']) ? 
        htmlspecialchars($risk['department_abbreviation']) . '/' . date('Y/m') . '/' . str_pad($risk['id'], 3, '0', STR_PAD_LEFT) : 
        '#' . htmlspecialchars($risk['id']);
}
$html .= '<span class="risk-id">ID: ' . $formattedId . '</span>';
$html .= '</div>';
$html .= '<div class="risk-dates">';
$html .= '<p>Created: ' . ($risk['created_at'] ? date('M j, Y', strtotime($risk['created_at'])) : 'N/A') . '</p>';
$html .= '<p>Last Updated: ' . ($risk['updated_at'] ? date('M j, Y', strtotime($risk['updated_at'])) : 'N/A') . '</p>';
$html .= '</div>';
$html .= '</div>';
// Basic Information Table
$html .= '<h3 class="section-title">Basic Information</h3>';
$html .= '<table class="risk-table">';
$html .= '<tr><th width="25%">Department</th><td>' . htmlspecialchars($risk['department'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Existing or New</th><td>' . htmlspecialchars($risk['existing_or_new'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Reported By</th><td>' . htmlspecialchars($risk['reporter_name'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Risk Owner</th><td>' . htmlspecialchars($risk['owner_name'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Report to Board</th><td>' . ($risk['to_be_reported_to_board'] == 'YES' ? '<span class="badge" style="background-color: #dc3545;">Yes</span>' : '<span class="badge" style="background-color: #6c757d;">No</span>') . '</td></tr>';
$html .= '</table>';
// Risk Assessment Table
$html .= '<h3 class="section-title">Risk Assessment</h3>';
$html .= '<table class="risk-table">';
$html .= '<tr><th width="25%">Inherent Likelihood</th><td>' . htmlspecialchars($risk['inherent_likelihood'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Inherent Consequence</th><td>' . htmlspecialchars($risk['inherent_consequence'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Inherent Rating</th><td>' . htmlspecialchars($risk['inherent_rating'] ?? 'N/A') . '</td></tr>';
$html .= '<tr><th>Residual Rating</th><td>' . htmlspecialchars($risk['residual_rating'] ?? 'N/A') . '</td></tr>';
$html .= '</table>';
// Risk Description
$html .= '<h3 class="section-title">Risk Description</h3>';
$html .= '<div class="risk-description">' . nl2br(htmlspecialchars($risk['risk_description'] ?? 'No description provided')) . '</div>';
// Cause of Risk
$html .= '<h3 class="section-title">Cause of Risk</h3>';
$html .= '<div class="risk-cause">' . nl2br(htmlspecialchars($risk['cause_of_risk'] ?? 'No cause specified')) . '</div>';
// Risk Categories
if (!empty($riskCategories)) {
    $html .= '<h3 class="section-title">Risk Categories</h3>';
    $html .= '<div class="risk-section">';
    
    foreach ($riskCategories as $category) {
        $html .= '<span class="badge category-badge" style="background-color: #007bff;">' . htmlspecialchars($category) . '</span>';
    }
    
    $html .= '</div>';
}
// Treatment Action
$html .= '<h3 class="section-title">Treatment Action</h3>';
$html .= '<div class="treatment-action">' . nl2br(htmlspecialchars($risk['treatment_action'] ?? 'No treatment action specified')) . '</div>';
// Controls Action Plan
$html .= '<h3 class="section-title">Controls Action Plan</h3>';
$html .= '<div class="controls-action">' . nl2br(htmlspecialchars($risk['controls_action_plan'] ?? 'No controls action plan specified')) . '</div>';
// Important Dates and Monitoring Tables
$html .= '<div style="display: flex; flex-wrap: wrap; gap: 20px;">';
$html .= '<div style="flex: 1; min-width: 300px;">';
$html .= '<h3 class="section-title">Important Dates</h3>';
$html .= '<table class="risk-table">';
$html .= '<tr><th width="40%">Planned Completion Date</th><td>' . ($risk['planned_completion_date'] ? date('M j, Y', strtotime($risk['planned_completion_date'])) : 'Not set') . '</td></tr>';
$html .= '<tr><th>Target Completion Date</th><td>' . ($risk['target_completion_date'] ? date('M j, Y', strtotime($risk['target_completion_date'])) : 'Not set') . '</td></tr>';
$html .= '</table>';
$html .= '</div>';
$html .= '<div style="flex: 1; min-width: 300px;">';
$html .= '<h3 class="section-title">Monitoring</h3>';
$html .= '<table class="risk-table">';
$html .= '<tr><th width="40%">Treatment Status</th><td>' . htmlspecialchars($risk['treatment_status'] ?? 'Not set') . '</td></tr>';
$html .= '<tr><th>Risk Status</th><td>' . htmlspecialchars($risk['risk_status'] ?? 'Not set') . '</td></tr>';
$html .= '</table>';
$html .= '</div>';
$html .= '</div>';
// Progress Update
$html .= '<h3 class="section-title">Progress Update</h3>';
$html .= '<div class="progress-update">' . nl2br(htmlspecialchars($risk['progress_update'] ?? 'No progress update provided')) . '</div>';
// Financial Impact
$html .= '<h3 class="section-title">Financial Impact</h3>';
$html .= '<table class="risk-table">';
$html .= '<tr><th width="25%">Involves Money Loss</th><td>' . ($risk['involves_money_loss'] == 'yes' ? 'Yes' : 'No') . '</td></tr>';
$html .= '<tr><th>Estimated Amount</th><td>' . ($risk['money_amount'] ? 'KES ' . number_format($risk['money_amount'], 2) : 'Not specified') . '</td></tr>';
$html .= '</table>';
// Collaborators
$html .= '<h3 class="section-title">Collaborators</h3>';
$html .= '<div class="collaborators">' . nl2br(htmlspecialchars($risk['collaborators'] ?? 'No collaborators specified')) . '</div>';
// Documents
if (!empty($risk['document_path'])) {
    $html .= '<h3 class="section-title">Related Documents</h3>';
    $html .= '<a href="' . htmlspecialchars($risk['document_path']) . '" target="_blank" class="document-link">';
    $html .= 'View Document';
    $html .= '</a>';
}
$html .= '</div>';
// Return the HTML response
echo json_encode([
    'success' => true,
    'html' => $html
]);
?>
