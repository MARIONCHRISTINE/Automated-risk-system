<?php
include_once 'includes/auth.php';
requireRole('compliance_team');
include_once 'config/database.php';
 $database = new Database();
 $db = $database->getConnection();

// Set active tab from URL parameter
 $activeTab = 'overview'; // default
if (isset($_GET['tab']) && in_array($_GET['tab'], ['overview', 'board', 'analytics', 'notifications', 'calendar', 'departmental-rankings'])) {
    $activeTab = $_GET['tab'];
}

// Capture message and type from URL and set in session
if (isset($_GET['message']) && isset($_GET['type'])) {
    $_SESSION['message'] = $_GET['message'];
    $_SESSION['message_type'] = $_GET['type'];
}

// Handle bulk board reporting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_board_report'])) {
    if (!empty($_POST['selected_risks'])) {
        $selectedRisks = $_POST['selected_risks'];
        $placeholders = implode(',', array_fill(0, count($selectedRisks), '?'));
        
        // Get the selected risk details before updating
        $riskDetailsQuery = "SELECT id, custom_risk_id, risk_name, risk_level, status FROM risk_incidents WHERE id IN ($placeholders)";
        $stmt = $db->prepare($riskDetailsQuery);
        
        // Bind parameters
        foreach ($selectedRisks as $index => $riskId) {
            $stmt->bindValue($index + 1, $riskId);
        }
        
        $stmt->execute();
        $riskDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update the risks to be reported to board
        $updateQuery = "UPDATE risk_incidents SET to_be_reported_to_board = 'YES' WHERE id IN ($placeholders)";
        $stmt = $db->prepare($updateQuery);
        
        // Bind parameters
        foreach ($selectedRisks as $index => $riskId) {
            $stmt->bindValue($index + 1, $riskId);
        }
        
        if ($stmt->execute()) {
            // Create notifications for high and critical risks
            foreach ($riskDetails as $risk) {
                if ($risk['risk_level'] === 'High' || $risk['risk_level'] === 'Critical') {
                    $notificationQuery = "INSERT INTO audit_logs (title, message, timestamp) VALUES (?, ?, NOW())";
                    $notificationStmt = $db->prepare($notificationQuery);
                    $title = "High/Critical Risk Reported to Board";
                    $riskIdDisplay = !empty($risk['custom_risk_id']) ? $risk['custom_risk_id'] : $risk['id'];
                    $message = "Risk #{$riskIdDisplay} ({$risk['risk_name']}) with {$risk['risk_level']} rating has been reported to the board.";
                    $notificationStmt->execute([$title, $message]);
                }
            }
            
            $message = count($selectedRisks) . " risks successfully reported to board.";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=notifications&message=" . urlencode($message) . "&type=success");
            exit();
        } else {
            $message = "Error reporting risks to board.";
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=notifications&message=" . urlencode($message) . "&type=error");
            exit();
        }
    } else {
        $message = "No risks selected for board reporting.";
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=notifications&message=" . urlencode($message) . "&type=warning");
        exit();
    }
}

// Handle individual board reporting toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['risk_id']) && isset($_POST['report_to_board'])) {
    $riskId = $_POST['risk_id'];
    $reportToBoard = $_POST['report_to_board'];
    
    // Get risk details before updating
    $riskDetailsQuery = "SELECT id, custom_risk_id, risk_name, risk_level, status FROM risk_incidents WHERE id = ?";
    $stmt = $db->prepare($riskDetailsQuery);
    $stmt->execute([$riskId]);
    $riskDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $updateQuery = "UPDATE risk_incidents SET to_be_reported_to_board = ? WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if ($stmt->execute([$reportToBoard, $riskId])) {
        // Create notification for high or critical risks being reported to board
        if ($reportToBoard === 'YES' && ($riskDetails['risk_level'] === 'High' || $riskDetails['risk_level'] === 'Critical')) {
            $notificationQuery = "INSERT INTO audit_logs (title, message, timestamp) VALUES (?, ?, NOW())";
            $notificationStmt = $db->prepare($notificationQuery);
            $title = "High/Critical Risk Reported to Board";
            $riskIdDisplay = !empty($riskDetails['custom_risk_id']) ? $riskDetails['custom_risk_id'] : $riskDetails['id'];
            $message = "Risk #{$riskIdDisplay} ({$riskDetails['risk_name']}) with {$riskDetails['risk_level']} rating has been reported to the board.";
            $notificationStmt->execute([$title, $message]);
        }
        
        $message = "Risk status updated successfully.";
        if ($reportToBoard === 'YES' && ($riskDetails['risk_level'] === 'High' || $riskDetails['risk_level'] === 'Critical')) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?tab=notifications&message=" . urlencode($message) . "&type=success");
        } else {
            header("Location: " . $_SERVER['PHP_SELF']);
        }
        exit();
    }
}

// Get departments for filter dropdown - FIXED to handle duplicates
 $dept_query = "SELECT DISTINCT TRIM(department) as department FROM risk_incidents WHERE department IS NOT NULL AND TRIM(department) != '' ORDER BY department";
 $dept_stmt = $db->prepare($dept_query);
 $dept_stmt->execute();
 $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get all risks for compliance overview - FIXED to ensure status is always fetched correctly
 $query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name,
         DATEDIFF(NOW(), r.created_at) as days_open,
         CASE 
           WHEN DATEDIFF(NOW(), r.created_at) > 90 THEN 'aged'
           WHEN DATEDIFF(NOW(), r.created_at) > 60 THEN 'maturing'
           ELSE 'new'
         END as aging_status
         FROM risk_incidents r 
         LEFT JOIN users u ON r.reported_by = u.id 
         LEFT JOIN users ro ON r.risk_owner_id = ro.id 
         ORDER BY r.created_at DESC";
 $stmt = $db->prepare($query);
 $stmt->execute();
 $all_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent risks for initial display - FIXED to ensure status is always fetched correctly
 $recent_risks_query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name
                      FROM risk_incidents r 
                      LEFT JOIN users u ON r.reported_by = u.id 
                      LEFT JOIN users ro ON r.risk_owner_id = ro.id 
                      ORDER BY r.created_at DESC LIMIT 10";
 $recent_stmt = $db->prepare($recent_risks_query);
 $recent_stmt->execute();
 $recent_risks = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get board risks specifically - FIXED to ensure status is always fetched correctly
 $board_risks_query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name
                      FROM risk_incidents r 
                      LEFT JOIN users u ON r.reported_by = u.id 
                      LEFT JOIN users ro ON r.risk_owner_id = ro.id 
                      WHERE r.to_be_reported_to_board = 'YES' OR r.risk_level IN ('High', 'Critical')
                      ORDER BY r.created_at DESC";
 $board_stmt = $db->prepare($board_risks_query);
 $board_stmt->execute();
 $board_risks = $board_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get enhanced risk statistics - FIXED to ensure status is always fetched correctly
 $stats_query = "SELECT 
   COUNT(*) as total_risks,
   SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
   SUM(CASE WHEN risk_level = 'High' THEN 1 ELSE 0 END) as high_risks,
   SUM(CASE WHEN risk_level = 'Medium' THEN 1 ELSE 0 END) as medium_risks,
   SUM(CASE WHEN risk_level = 'Low' THEN 1 ELSE 0 END) as low_risks,
   SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_risks,
   SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_risks,
   SUM(CASE WHEN to_be_reported_to_board = 'YES' THEN 1 ELSE 0 END) as board_risks,
   SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 90 THEN 1 ELSE 0 END) as aged_risks,
   SUM(CASE WHEN planned_completion_date < NOW() AND status != 'closed' THEN 1 ELSE 0 END) as overdue_risks
   FROM risk_incidents";
 $stats_stmt = $db->prepare($stats_query);
 $stats_stmt->execute();
 $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activity from audit_logs
 $alerts_query = "SELECT * FROM audit_logs ORDER BY timestamp DESC LIMIT 10";
 $alerts_stmt = $db->prepare($alerts_query);
 $alerts_stmt->execute();
 $recent_alerts = $alerts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming deadlines from risk_incidents - FIXED to ensure status is always fetched correctly
 $reviews_query = "SELECT risk_name, department, planned_completion_date as review_date, 'Risk Review' as review_type 
                 FROM risk_incidents 
                 WHERE planned_completion_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
                 AND status != 'closed'
                 ORDER BY planned_completion_date ASC";
 $reviews_stmt = $db->prepare($reviews_query);
 $reviews_stmt->execute();
 $upcoming_reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get events for calendar - FIXED to ensure status is always fetched correctly
 $events_query = "SELECT 
   id,
   custom_risk_id,
   risk_name as title,
   COALESCE(target_completion_date, planned_completion_date) as start,
   'Risk Review' as type,
   department,
   risk_level,
   status
FROM risk_incidents 
WHERE (target_completion_date IS NOT NULL OR planned_completion_date IS NOT NULL)
AND status != 'closed'
ORDER BY start ASC";
 $events_stmt = $db->prepare($events_query);
 $events_stmt->execute();
 $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to FullCalendar event format
 $calendarEvents = [];
foreach ($events as $event) {
    $riskIdDisplay = !empty($event['custom_risk_id']) ? $event['custom_risk_id'] : $event['id'];
    $calendarEvents[] = [
        'id' => $event['id'],
        'title' => $riskIdDisplay . ' - ' . $event['title'] . ' (' . $event['type'] . ')',
        'start' => $event['start'],
        'backgroundColor' => getEventColor($event['risk_level']),
        'borderColor' => getEventColor($event['risk_level']),
        'extendedProps' => [
            'department' => $event['department'],
            'risk_level' => $event['risk_level'],
            'status' => $event['status'],
            'type' => $event['type'],
            'custom_risk_id' => $event['custom_risk_id']
        ]
    ];
}

// Helper function to get event color based on risk level
function getEventColor($riskLevel) {
    switch($riskLevel) {
        case 'Critical': return '#dc3545';
        case 'High': return '#fd7e14';
        case 'Medium': return '#ffc107';
        case 'Low': return '#28a745';
        default: return '#17a2b8';
    }
}

// Get heat map data
 $heatmap_query = "SELECT 
   TRIM(department) as department,
   risk_level,
   COUNT(*) as risk_count
   FROM risk_incidents 
   WHERE department IS NOT NULL AND TRIM(department) != '' AND risk_level IS NOT NULL
   GROUP BY TRIM(department), risk_level
   ORDER BY TRIM(department), 
   CASE risk_level 
       WHEN 'Low' THEN 1 
       WHEN 'Medium' THEN 2 
       WHEN 'High' THEN 3 
       WHEN 'Critical' THEN 4 
       WHEN 'Not Assessed' THEN 5
   END";
 $heatmap_stmt = $db->prepare($heatmap_query);
 $heatmap_stmt->execute();
 $heatmap_data = $heatmap_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly trends data
 $trends_query = "SELECT 
   DATE_FORMAT(created_at, '%Y-%m') as month,
   risk_level,
   COUNT(*) as count
   FROM risk_incidents 
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
   GROUP BY DATE_FORMAT(created_at, '%Y-%m'), risk_level
   ORDER BY month";
 $trends_stmt = $db->prepare($trends_query);
 $trends_stmt->execute();
 $trends_data = $trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department analysis data - FIXED to handle duplicates
 $dept_analysis_query = "SELECT 
   TRIM(department) as department,
   COUNT(*) as total_risks,
   SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
   SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_risks
   FROM risk_incidents 
   WHERE department IS NOT NULL AND TRIM(department) != ''
   GROUP BY TRIM(department)
   ORDER BY total_risks DESC";
 $dept_analysis_stmt = $db->prepare($dept_analysis_query);
 $dept_analysis_stmt->execute();
 $dept_analysis_data = $dept_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get risk category distribution data
 $category_data = [];
 $category_counts = [];
foreach ($all_risks as $risk) {
    $riskCategories = [];
    $rawCategories = $risk['risk_categories'] ?? '[]';
    
    if (is_string($rawCategories)) {
        $decodedCategories = json_decode($rawCategories, true);
        if (is_array($decodedCategories)) {
            if (count($decodedCategories) > 0 && is_array($decodedCategories[0])) {
                $riskCategories = $decodedCategories[0];
            } else {
                $riskCategories = $decodedCategories;
            }
        }
    } else if (is_array($rawCategories)) {
        if (count($rawCategories) > 0 && is_array($rawCategories[0])) {
            $riskCategories = $rawCategories[0];
        } else {
            $riskCategories = $rawCategories;
        }
    }
    
    foreach ($riskCategories as $category) {
        if (!isset($category_counts[$category])) {
            $category_counts[$category] = 0;
        }
        $category_counts[$category]++;
    }
}
foreach ($category_counts as $category => $count) {
    $category_data[] = [
        'category_name' => $category,
        'risk_count' => $count
    ];
}

// Get departmental rankings data - FIXED to handle duplicates
 $dept_rankings_query = "
   SELECT 
       TRIM(department) as department,
       COUNT(*) as total_risks
   FROM risk_incidents 
   WHERE department IS NOT NULL AND TRIM(department) != ''
   GROUP BY TRIM(department)
   ORDER BY total_risks DESC
";
 $dept_rankings_stmt = $db->prepare($dept_rankings_query);
 $dept_rankings_stmt->execute();
 $dept_rankings = $dept_rankings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department risk trends - FIXED to handle duplicates
 $dept_trends_query = "
   SELECT 
       TRIM(department) as department,
       DATE_FORMAT(created_at, '%Y-%m') as month,
       COUNT(*) as monthly_risk_count
   FROM risk_incidents 
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
   AND department IS NOT NULL AND TRIM(department) != ''
   GROUP BY TRIM(department), DATE_FORMAT(created_at, '%Y-%m')
   ORDER BY department, month
";
 $dept_trends_stmt = $db->prepare($dept_trends_query);
 $dept_trends_stmt->execute();
 $dept_trends_data = $dept_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process trends data
foreach ($dept_rankings as &$dept) {
    $dept_trends = array_filter($dept_trends_data, function($item) use ($dept) {
        return $item['department'] === $dept['department'];
    });
    
    if (count($dept_trends) >= 2) {
        usort($dept_trends, function($a, $b) {
            return strcmp($a['month'], $b['month']);
        });
        
        $latest = end($dept_trends);
        $previous = prev($dept_trends);
        
        if ($latest && $previous) {
            $dept['trend'] = $latest['monthly_risk_count'] - $previous['monthly_risk_count'];
        }
    }
}

// Get Risk Category Breakdown by Department - FIXED to handle duplicates
 $dept_category_query = "
   SELECT 
       TRIM(department) as department,
       risk_categories
   FROM risk_incidents 
   WHERE department IS NOT NULL AND TRIM(department) != ''
";
 $dept_category_stmt = $db->prepare($dept_category_query);
 $dept_category_stmt->execute();
 $dept_category_raw = $dept_category_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process department category data
 $dept_category_data = [];
foreach ($dept_category_raw as $item) {
    $dept = $item['department'];
    $rawCategories = $item['risk_categories'] ?? '[]';
    
    $riskCategories = [];
    if (is_string($rawCategories)) {
        $decodedCategories = json_decode($rawCategories, true);
        if (is_array($decodedCategories)) {
            if (count($decodedCategories) > 0 && is_array($decodedCategories[0])) {
                $riskCategories = $decodedCategories[0];
            } else {
                $riskCategories = $decodedCategories;
            }
        }
    } else if (is_array($rawCategories)) {
        if (count($rawCategories) > 0 && is_array($rawCategories[0])) {
            $riskCategories = $rawCategories[0];
        } else {
            $riskCategories = $rawCategories;
        }
    }
    
    foreach ($riskCategories as $category) {
        if (!isset($dept_category_data[$dept])) {
            $dept_category_data[$dept] = [];
        }
        if (!isset($dept_category_data[$dept][$category])) {
            $dept_category_data[$dept][$category] = 0;
        }
        $dept_category_data[$dept][$category]++;
    }
}

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $reportType = $_POST['report_type'] ?? 'monthly';
    $department = $_POST['department'] ?? 'all';
    $riskLevel = $_POST['risk_level'] ?? 'all';
    $format = $_POST['format'] ?? 'pdf';
    $dateFrom = $_POST['date_from'] ?? null;
    $dateTo = $_POST['date_to'] ?? null;
    
    $query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name
              FROM risk_incidents r 
              LEFT JOIN users u ON r.reported_by = u.id 
              LEFT JOIN users ro ON r.risk_owner_id = ro.id 
              WHERE 1=1";
    $params = [];
    
    if ($department !== 'all') {
        $query .= " AND TRIM(r.department) = ?";
        $params[] = $department;
    }
    
    if ($riskLevel !== 'all') {
        $query .= " AND r.risk_level = ?";
        $params[] = $riskLevel;
    }
    
    switch ($reportType) {
        case 'monthly':
            $query .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
        case 'quarterly':
            $query .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
            break;
        case 'annual':
            $query .= " AND r.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            break;
        case 'custom':
            if ($dateFrom) {
                $query .= " AND r.created_at >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $query .= " AND r.created_at <= DATE_ADD(?, INTERVAL 1 DAY)";
                $params[] = $dateTo;
            }
            break;
    }
    
    $query .= " ORDER BY r.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'excel') {
        exportToExcel($reportData, $reportType . '_report');
    } else {
        exportToPDF($reportData, $reportType . '_report');
    }
    
    exit();
}

// Export functions
function exportToExcel($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename . '_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, [
        'Risk ID', 
        'Risk Name', 
        'Risk Level', 
        'Status', 
        'Department', 
        'Date Created',
        'Risk Description',
        'Treatment Action',
        'Target Completion Date',
        'Risk Owner'
    ]);
    
    foreach ($data as $row) {
        $riskIdDisplay = !empty($row['custom_risk_id']) ? $row['custom_risk_id'] : $row['id'];
        fputcsv($output, [
            $riskIdDisplay,
            $row['risk_name'],
            $row['risk_level'],
            $row['status'],
            $row['department'],
            date('Y-m-d', strtotime($row['created_at'])),
            $row['risk_description'],
            $row['treatment_action'] ?? 'N/A',
            $row['target_completion_date'] ?? 'N/A',
            $row['owner_name'] ?? 'N/A'
        ]);
    }
    
    fclose($output);
    exit();
}

function exportToPDF($data, $filename) {
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <title>' . ucfirst($filename) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #E60012; padding-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
            th { background-color: #495057; color: white; font-weight: bold; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
            .risk-badge { padding: 3px 6px; border-radius: 10px; font-size: 10px; font-weight: bold; color: white; }
            .critical { background-color: #dc3545; }
            .high { background-color: #fd7e14; }
            .medium { background-color: #ffc107; color: #212529; }
            .low { background-color: #28a745; }
            .print-only { display: block; }
            .no-print { display: none; }
            .risk-id-badge {
                display: inline-block;
                background: #E60012;
                color: white;
                padding: 0.25rem 0.75rem;
                border-radius: 4px;
                font-size: 0.85rem;
                font-weight: 600;
                margin-right: 0.5rem;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Airtel Risk Management System</h1>
            <h2>' . ucfirst(str_replace('_', ' ', $filename)) . '</h2>
            <p>Generated on: ' . date('F j, Y') . ' | Total Risks: ' . count($data) . '</p>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Risk ID</th>
                    <th>Risk Name</th>
                    <th>Risk Level</th>
                    <th>Status</th>
                    <th>Department</th>
                    <th>Date Created</th>
                    <th>Risk Description</th>
                    <th>Treatment Action</th>
                    <th>Target Completion</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($data as $row) {
        $riskLevelClass = strtolower($row['risk_level']);
        $riskIdDisplay = !empty($row['custom_risk_id']) ? 
            '<span class="risk-id-badge">' . htmlspecialchars($row['custom_risk_id']) . '</span>' : 
            htmlspecialchars($row['id']);
        
        $html .= '<tr>
            <td>' . $riskIdDisplay . '</td>
            <td>' . htmlspecialchars($row['risk_name']) . '</td>
            <td><span class="risk-badge ' . $riskLevelClass . '">' . $row['risk_level'] . '</span></td>
            <td>' . $row['status'] . '</td>
            <td>' . $row['department'] . '</td>
            <td>' . date('M j, Y', strtotime($row['created_at'])) . '</td>
            <td>' . htmlspecialchars(substr($row['risk_description'], 0, 100)) . '...</td>
            <td>' . htmlspecialchars(substr($row['treatment_action'] ?? '', 0, 100)) . '...</td>
            <td>' . ($row['target_completion_date'] ? date('M j, Y', strtotime($row['target_completion_date'])) : 'N/A') . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
        </table>
        <div class="footer">
            <p>This report contains ' . count($data) . ' risk records exported from the Airtel Risk Management System.</p>
            <p>Report generated on ' . date('F j, Y, g:i a') . '</p>
        </div>
    </body>
    </html>';
    
    echo $html;
    exit();
}

function getRiskLevelColor($level) {
    switch($level) {
        case 'Critical': return '#dc3545';
        case 'High': return '#fd7e14';
        case 'Medium': return '#ffc107';
        case 'Low': return '#28a745';
        case 'Not Assessed': return '#6c757d';
        default: return '#6c757d';
    }
}

// Function to get beautiful status display
function getBeautifulStatus($status) {
    if (!$status) $status = 'pending';
    
    switch(strtolower($status)) {
        case 'pending':
            return [
                'text' => 'Open',
                'color' => '#0c5460',
                'bg' => '#d1ecf1'
            ];
        case 'in_progress':
            return [
                'text' => 'In Progress',
                'color' => '#004085',
                'bg' => '#cce5ff'
            ];
        case 'completed':
        case 'closed':
            return [
                'text' => 'Completed',
                'color' => '#155724',
                'bg' => '#d4edda'
            ];
        case 'cancelled':
            return [
                'text' => 'Cancelled',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        case 'overdue':
            return [
                'text' => 'Overdue',
                'color' => '#721c24',
                'bg' => '#f8d7da'
            ];
        default:
            return [
                'text' => 'Open',
                'color' => '#0c5460',
                'bg' => '#d1ecf1'
            ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Compliance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js'></script>
    
    <style>
    :root {
        --primary-color: #E60012;
        --gradient: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
        --shadow: 0 4px 20px rgba(230, 0, 18, 0.15);
        --border-color: #e9ecef;
        --text-light: #6c757d;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --info-color: #17a2b8;
        --critical-color: #dc3545;
        --high-color: #fd7e14;
        --medium-color: #ffc107;
        --low-color: #28a745;
        --not-assessed-color: #6c757d;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background: white;
        color: #333;
        padding-top: 180px;
    }
    
    /* Header Styles */
    .header {
        background: #E60012;
        padding: 1.5rem 2rem;
        color: white;
        position: fixed !important;
        top: 0 !important;
        left: 0;
        right: 0;
        z-index: 9999 !important;
        box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        display: flex;
        align-items: center;
    }
    
    .header-content {
        max-width: 1200px;
        margin: 0 auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
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
        color: white;
        text-decoration: none;
    }
    
    /* Navigation Styles */
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
    
    /* Main Content */
    .main-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
        height: calc(100vh - 160px);
        overflow-y: auto;
    }
    
    /* Tab Content */
    .tab-content {
        display: none;
        height: 100%;
        overflow-y: auto;
    }
    
    .tab-content.active {
        display: block;
    }
    
    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
        width: 100%;
    }
    
    .stat-card {
        background: white;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-top: 4px solid #E60012;
        transition: transform 0.3s ease;
        text-align: center;
        min-height: 180px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        width: 100%;
        box-sizing: border-box;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(230, 0, 18, 0.15);
    }
    
    .stat-number {
        font-size: 2.5rem;
        font-weight: bold;
        color: #E60012;
        margin-bottom: 0.5rem;
        display: block;
        line-height: 1;
    }
    
    .stat-label {
        color: #495057;
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        display: block;
        line-height: 1.2;
    }
    
    .stat-description {
        color: #6c757d;
        font-size: 0.85rem;
        display: block;
        line-height: 1.3;
    }
    
    /* Card Styles */
    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        border-top: 4px solid #E60012;
        padding: 2rem;
        margin-bottom: 1.5rem;
        max-height: calc(100vh - 220px);
        overflow-y: auto;
    }
    
    .card-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        border-radius: 8px 8px 0 0;
    }
    
    .card-header h2 {
        color: var(--text-dark);
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
    }
    
    .card-body {
        padding: 1.5rem;
    }
    
    /* Button Styles */
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }
    
    .btn-primary {
        background: var(--gradient);
        color: white;
        box-shadow: 0 2px 8px rgba(230, 0, 18, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(230, 0, 18, 0.4);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-warning {
        background: #ffc107;
        color: #212529;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.85rem;
        border-radius: 4px;
    }
    
    /* Table Styles */
    .table-container {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: white;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    td {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-dark);
    }
    
    tr:hover {
        background: rgba(230, 0, 18, 0.05);
    }
    
    /* Status Badges */
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .status-high { background: #fee; color: #dc3545; border: 1px solid #f5c6cb; }
    .status-medium { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
    .status-low { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .status-completed { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    
    /* Board Report Badge */
    .board-report-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #e6f7ff;
        color: #0050b3;
        border: 1px solid #91d5ff;
    }
    
    /* Custom Risk ID Badge */
    .risk-id-badge {
        display: inline-block;
        background: #E60012;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
        margin-right: 0.5rem;
    }
    
    /* Notification styles */
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        min-width: 250px;
        max-width: 400px;
        color: white;
        font-weight: 500;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .notification-success {
        background-color: #28a745;
    }
    
    .notification-error {
        background-color: #dc3545;
    }
    
    .notification-warning {
        background-color: #ffc107;
        color: #212529;
    }
    
    .notification-info {
        background-color: #17a2b8;
    }
    
    .notification.show {
        opacity: 1;
    }
    
    /* Alert highlight for high/critical risks */
    .alert-highlight {
        background-color: #fff3cd;
        border-left: 4px solid #ffc107;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
        100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
    }
    
    .alert-critical {
        background-color: #f8d7da;
        border-left: 4px solid #dc3545;
        animation: pulse-critical 2s infinite;
    }
    
    @keyframes pulse-critical {
        0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
        70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }
    
    /* Filter Section */
    .filter-section {
        background: #f8f9fa;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #e9ecef;
    }
    
    .filter-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-label {
        font-weight: 500;
        color: #495057;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }
    
    .filter-input {
        padding: 0.5rem;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        font-size: 0.9rem;
    }
    
    .filter-input:focus {
        outline: none;
        border-color: #E60012;
        box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    /* Bulk Actions */
    .bulk-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .selected-count {
        font-weight: 500;
        color: #495057;
    }
    
    /* Risk Selection Checkbox */
    .risk-select-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    /* Board Report Status */
    .board-reported {
        color: #28a745;
        font-weight: 600;
    }
    
    .board-not-reported {
        color: #6c757d;
    }
    
    /* Print styles */
    @media print {
        .no-print {
            display: none !important;
        }
        
        .print-only {
            display: block !important;
        }
        
        body {
            padding-top: 0 !important;
            background: white !important;
            color: black !important;
        }
        
        .header, .nav {
            display: none !important;
        }
        
        .main-content {
            padding: 0 !important;
            max-height: none !important;
            overflow: visible !important;
        }
        
        .card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
            margin-bottom: 20px;
        }
        
        .stat-card {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 15px !important;
        }
        
        .stats-grid .stat-card {
            min-height: auto !important;
            padding: 15px !important;
        }
        
        .stat-number {
            font-size: 2rem !important;
        }
        
        /* Ensure charts are printed properly */
        .risk-matrix-container, canvas {
            max-width: 100% !important;
            height: auto !important;
        }
        
        /* Adjust table for print */
        table {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        
        th, td {
            border: 1px solid #ddd !important;
            padding: 8px !important;
        }
        
        th {
            background-color: #f2f2f2 !important;
            color: black !important;
        }
        
        /* Remove background colors from status badges for print */
        .status-badge {
            border: 1px solid #ddd !important;
            background: white !important;
            color: black !important;
        }
        
        /* Print header */
        .print-only.header {
            display: block !important;
            background: white !important;
            color: black !important;
            padding: 20px 0;
            border-bottom: 2px solid #E60012;
            margin-bottom: 20px;
        }
        
        .print-only .header-content {
            max-width: 100%;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }
        
        .print-only .main-title, .print-only .sub-title {
            color: black !important;
        }
        
        .print-only .user-email, .print-only .user-role {
            color: black !important;
        }
    }
    
    .print-only {
        display: none;
    }
    
    /* Professional report styles */
    .report-header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 2px solid #E60012;
    }
    
    .report-title {
        font-size: 24px;
        font-weight: bold;
        color: #333;
        margin-bottom: 5px;
    }
    
    .report-subtitle {
        font-size: 16px;
        color: #666;
        margin-bottom: 10px;
    }
    
    .report-date {
        font-size: 14px;
        color: #888;
    }
    
    .report-section {
        margin-bottom: 25px;
    }
    
    .report-section-title {
        font-size: 18px;
        font-weight: bold;
        color: #333;
        margin-bottom: 15px;
        padding-bottom: 5px;
        border-bottom: 1px solid #ddd;
    }
    
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }
    
    .report-table th {
        background-color: #f2f2f2;
        color: #333;
        font-weight: bold;
        text-align: left;
        padding: 10px;
        border: 1px solid #ddd;
    }
    
    .report-table td {
        padding: 10px;
        border: 1px solid #ddd;
    }
    
    .report-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    
    .report-footer {
        text-align: center;
        margin-top: 30px;
        padding-top: 15px;
        border-top: 1px solid #ddd;
        font-size: 12px;
        color: #888;
    }
    
    /* Risk Details Modal Styles */
    .risk-details-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 10000;
    }
    
    .risk-details-modal-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 12px;
        width: 95%;
        max-width: 1000px;
        max-height: 90%;
        overflow-y: auto;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    
    .risk-details-modal-header {
        padding: 2rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8f9fa;
        border-radius: 12px 12px 0 0;
    }
    
    .risk-details-modal-body {
        padding: 2rem;
    }
    
    .risk-details-modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #6c757d;
    }
    
    /* Risk Details Popup */
    #riskPopupOverlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }
    
    #riskPopup {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        padding: 2rem;
        max-width: 500px;
        width: 90%;
        z-index: 1000;
    }
    
    /* Chart container fixes */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    .chart-container-large {
        position: relative;
        height: 400px;
        width: 100%;
    }
    
    /* Custom date range fields */
    .custom-date-range {
        display: none;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e9ecef;
    }
    
    .custom-date-range.show {
        display: block;
    }
    
    /* Multi-select department filter styles */
    .multi-select-container {
        position: relative;
        margin-bottom: 1rem;
    }
    
    .multi-select-input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        font-size: 0.9rem;
        cursor: pointer;
        background-color: white;
    }
    
    .multi-select-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        z-index: 100;
        max-height: 400px;
        display: none;
        padding: 0.5rem;
    }
    
    .multi-select-dropdown.show {
        display: block;
    }
    
    .multi-select-option {
        padding: 0.5rem;
        cursor: pointer;
        display: flex;
        align-items: center;
    }
    
    .multi-select-option:hover {
        background-color: #f8f9fa;
    }
    
    .multi-select-option input {
        margin-right: 0.5rem;
    }
    
    .multi-select-tag {
        display: inline-block;
        background-color: #e9ecef;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        margin-right: 0.25rem;
        font-size: 0.85rem;
    }
    
    .multi-select-tag .remove {
        margin-left: 0.25rem;
        cursor: pointer;
        font-weight: bold;
    }
    
    /* Department Category Options - Improved Scrolling */
    #deptCategoryOptions {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e9ecef;
        border-radius: 4px;
        margin: 0.5rem 0;
    }
    
    #deptCategoryOptions::-webkit-scrollbar {
        width: 8px;
    }
    
    #deptCategoryOptions::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    #deptCategoryOptions::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }
    
    #deptCategoryOptions::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    /* Risk Trends Date Filter Styles */
    .trends-date-filter {
        background: #f8f9fa;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        border: 1px solid #e9ecef;
    }
    
    .trends-date-filter .filter-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 0;
    }
    
    .trends-date-filter .filter-group {
        flex: 1;
        margin-bottom: 0;
    }
    
    .trends-date-filter .filter-input {
        width: 100%;
    }
    
    /* Risk Trends Chart Enhancements */
    .risk-trends-container {
        position: relative;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        padding: 1.5rem;
        margin-bottom: 2rem;
        border-top: 4px solid #E60012;
    }
    
    .risk-trends-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .risk-trends-title {
        font-size: 1.3rem;
        font-weight: 600;
        color: #495057;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .risk-trends-controls {
        display: flex;
        gap: 1rem;
        align-items: center;
    }
    
    .risk-trends-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-top: 1rem;
        justify-content: center;
    }
    
    .risk-trends-legend-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
        color: #495057;
    }
    
    .risk-trends-legend-color {
        width: 12px;
        height: 12px;
        border-radius: 50%;
    }
    
    .risk-trends-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 1.5rem;
    }
    
    .risk-trends-stat-card {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        border-left: 4px solid #E60012;
    }
    
    .risk-trends-stat-value {
        font-size: 1.5rem;
        font-weight: bold;
        color: #E60012;
        margin-bottom: 0.25rem;
    }
    
    .risk-trends-stat-label {
        font-size: 0.85rem;
        color: #6c757d;
    }
    
    /* Calendar Styles */
    .calendar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .calendar-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #495057;
    }
    
    .calendar-controls {
        display: flex;
        gap: 1rem;
    }
    
    .calendar-container {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .fc-theme-standard .fc-scrollgrid {
        border-radius: 8px;
        overflow: hidden;
    }
    
    .fc-event {
        border-radius: 4px;
        padding: 2px 4px;
        font-size: 0.85rem;
    }
    
    .fc-event-title {
        font-weight: 500;
    }
    
    /* Upcoming Deadlines - REDESIGNED */
    .deadlines-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .deadlines-header {
        background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
        color: white;
        padding: 1.25rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .deadlines-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .deadlines-filter {
        display: flex;
        gap: 0.5rem;
    }
    
    .deadlines-filter-btn {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 20px;
        padding: 0.4rem 0.8rem;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .deadlines-filter-btn:hover {
        background: rgba(255, 255, 255, 0.3);
    }
    
    .deadlines-filter-btn.active {
        background: white;
        color: #E60012;
    }
    
    .deadlines-body {
        padding: 1.5rem;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .deadline-card {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        border-left: 4px solid #17a2b8;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .deadline-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .deadline-card.urgent {
        border-left-color: #dc3545;
        background: linear-gradient(to right, rgba(220, 53, 69, 0.05), #f8f9fa);
    }
    
    .deadline-card.warning {
        border-left-color: #ffc107;
        background: linear-gradient(to right, rgba(255, 193, 7, 0.05), #f8f9fa);
    }
    
    .deadline-card.info {
        border-left-color: #17a2b8;
        background: linear-gradient(to right, rgba(23, 162, 184, 0.05), #f8f9fa);
    }
    
    .deadline-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }
    
    .deadline-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #495057;
        margin: 0;
        line-height: 1.3;
    }
    
    .deadline-days {
        background: #17a2b8;
        color: white;
        border-radius: 20px;
        padding: 0.3rem 0.8rem;
        font-size: 0.85rem;
        font-weight: 600;
        white-space: nowrap;
    }
    
    .deadline-card.urgent .deadline-days {
        background: #dc3545;
    }
    
    .deadline-card.warning .deadline-days {
        background: #ffc107;
        color: #212529;
    }
    
    .deadline-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 0.5rem;
    }
    
    .deadline-meta-item {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        color: #6c757d;
        font-size: 0.9rem;
    }
    
    .deadline-meta-icon {
        width: 16px;
        height: 16px;
        opacity: 0.7;
    }
    
    .deadline-date {
        font-weight: 500;
        color: #495057;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }
    
    .deadline-progress {
        height: 6px;
        background: #e9ecef;
        border-radius: 3px;
        margin-top: 1rem;
        overflow: hidden;
    }
    
    .deadline-progress-bar {
        height: 100%;
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    
    .deadline-card.urgent .deadline-progress-bar {
        background: #dc3545;
    }
    
    .deadline-card.warning .deadline-progress-bar {
        background: #ffc107;
    }
    
    .deadline-card.info .deadline-progress-bar {
        background: #17a2b8;
    }
    
    .empty-deadlines {
        text-align: center;
        padding: 2rem;
        color: #6c757d;
    }
    
    .empty-deadlines-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    
    /* Active Reminders Section - REDESIGNED */
    .active-reminders-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .active-reminders-header {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
        color: white;
        padding: 1.25rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .active-reminders-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .active-reminders-body {
        padding: 1.5rem;
    }
    
    .active-reminders-list {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 0.5rem;
    }
    
    .active-reminder-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        border-radius: 6px;
        margin-bottom: 0.5rem;
        background: #f8f9fa;
        transition: all 0.2s ease;
    }
    
    .active-reminder-item:hover {
        background: #e9ecef;
    }
    
    .active-reminder-item:last-child {
        margin-bottom: 0;
    }
    
    .active-reminder-content {
        flex: 1;
    }
    
    .active-reminder-title {
        font-weight: 500;
        color: #495057;
        margin-bottom: 0.25rem;
        font-size: 0.95rem;
    }
    
    .active-reminder-meta {
        color: #6c757d;
        font-size: 0.85rem;
    }
    
    .active-reminder-days {
        background: #28a745;
        color: white;
        border-radius: 20px;
        padding: 0.25rem 0.6rem;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    /* Reminder Settings - REDESIGNED */
    .reminder-settings-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        border-top: 4px solid #E60012;
    }
    
    .reminder-settings-header {
        background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
        color: white;
        padding: 1.25rem 1.5rem;
    }
    
    .reminder-settings-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .reminder-settings-body {
        padding: 1.5rem;
    }
    
    .reminder-settings-intro {
        color: #6c757d;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
        line-height: 1.5;
    }
    
    .reminder-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .reminder-option-card {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 1.25rem;
        border: 2px solid transparent;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .reminder-option-card:hover {
        border-color: #E60012;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .reminder-option-card.active {
        border-color: #E60012;
        background: linear-gradient(to right, rgba(230, 0, 18, 0.05), #f8f9fa);
    }
    
    .reminder-option-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }
    
    .reminder-option-checkbox {
        width: 20px;
        height: 20px;
        accent-color: #E60012;
        cursor: pointer;
    }
    
    .reminder-option-title {
        font-weight: 600;
        color: #495057;
        font-size: 1rem;
    }
    
    .reminder-option-description {
        color: #6c757d;
        font-size: 0.9rem;
        margin-left: 2.75rem;
    }
    
    .reminder-save-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e9ecef;
    }
    
    .reminder-save-btn {
        background: #E60012;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 0.75rem 1.5rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .reminder-save-btn:hover {
        background: #B8000E;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(230, 0, 18, 0.3);
    }
    
    .reminder-status {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #6c757d;
        font-size: 0.9rem;
    }
    
    .reminder-status-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #28a745;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .header {
            padding: 0.75rem 1rem;
            height: 80px;
        }
        
        .header-content {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .main-title {
            font-size: 1.2rem;
        }
        
        .sub-title {
            font-size: 0.8rem;
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
        
        body {
            padding-top: 180px;
        }
        
        .main-content {
            padding: 1rem;
            max-height: calc(100vh - 160px);
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .stat-card {
            min-height: 120px;
            padding: 1.25rem;
        }
        
        .stat-number {
            font-size: 2rem;
        }
        
        .risk-trends-controls {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .risk-trends-stats {
            grid-template-columns: repeat(2, 1fr);
        }
        
        /* Improved mobile scrolling for department filter */
        .multi-select-dropdown {
            max-height: 300px;
        }
        
        #deptCategoryOptions {
            max-height: 200px;
        }
        
        /* Mobile adjustments for deadline cards */
        .deadline-card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .deadline-meta {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        /* Mobile adjustments for reminder options */
        .reminder-options {
            grid-template-columns: 1fr;
        }
        
        .reminder-save-section {
            flex-direction: column;
            gap: 1rem;
            align-items: stretch;
        }
    }
    
    @media (max-width: 1024px) and (min-width: 769px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    /* Hide scrollbars */
    body {
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    body::-webkit-scrollbar {
        display: none; /* WebKit */
    }
    
    .tab-content {
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    .tab-content::-webkit-scrollbar {
        display: none; /* WebKit */
    }
    
    .card {
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* Internet Explorer 10+ */
    }
    
    .card::-webkit-scrollbar {
        display: none; /* WebKit */
    }
    
    /* Loading spinner */
    .spinner {
        border: 4px solid rgba(0, 0, 0, 0.1);
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border-left-color: #E60012;
        animation: spin 1s ease infinite;
        margin: 0 auto;
    }
    
    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }
        100% {
            transform: rotate(360deg);
        }
    }
</style>
</head>
<body>
    <!-- Print Only Header -->
    <div class="print-only header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Compliance</h1>
                    <p class="sub-title">Risk Management System</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : '232000@airtel.africa'; ?></div>
                    <div class="user-role"><?php echo isset($_SESSION['department']) ? $_SESSION['department'] . ' • Airtel Money' : 'Compliance • Airtel Money'; ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Header -->
    <header class="header no-print">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Compliance</h1>
                    <p class="sub-title">Risk Management System</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'M'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : '232000@airtel.africa'; ?></div>
                    <div class="user-role"><?php echo isset($_SESSION['department']) ? $_SESSION['department'] . ' • Airtel Money' : 'Compliance • Airtel Money'; ?></div>
                </div>
                <a href="logout.php" class="logout-btn no-print">Logout</a>
            </div>
        </div>
    </header>
    
    <!-- Navigation -->
    <nav class="nav no-print">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'overview' ? 'class="active"' : ''; ?> onclick="showTab('overview')">
                        <span>📊</span> Overview
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'board' ? 'class="active"' : ''; ?> onclick="showTab('board')">
                        <span>📋</span> Board Risks
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'analytics' ? 'class="active"' : ''; ?> onclick="showTab('analytics')">
                        <span>📈</span> Analytics
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'notifications' ? 'class="active"' : ''; ?> onclick="showTab('notifications')">
                        <span>🔔</span> Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'calendar' ? 'class="active"' : ''; ?> onclick="showTab('calendar')">
                        <span>📅</span> Calendar
                    </a>
                </li>
                <li class="nav-item">
                    <a href="#" <?php echo $activeTab === 'departmental-rankings' ? 'class="active"' : ''; ?> onclick="showTab('departmental-rankings')">
                        <span></span> Departmental Rankings
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Overview Tab -->
        <div id="overview" class="tab-content <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Compliance Risk Management Report</div>
                <div class="report-subtitle">Overview and Key Metrics</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['total_risks']) ? $stats['total_risks'] : '0'; ?></div>
                    <div class="stat-label">Total Risks</div>
                    <div class="stat-description">All risks in system</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['aged_risks']) ? $stats['aged_risks'] : '0'; ?></div>
                    <div class="stat-label">Aged Risks</div>
                    <div class="stat-description">Risks over 90 days old</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['closed_risks']) ? $stats['closed_risks'] : '0'; ?></div>
                    <div class="stat-label">Successfully Managed Risks</div>
                    <div class="stat-description">Successfully closed risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['board_risks']) ? $stats['board_risks'] : '0'; ?></div>
                    <div class="stat-label">Board Risks</div>
                    <div class="stat-description">Risks reported to board</div>
                </div>
            </div>
            
            <!-- Quick Actions Section -->
            <div style="margin-bottom: 2rem;" class="no-print">
                <div style="background: white; border-radius: 12px; border-top: 3px solid #E60012; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 1.5rem;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #333; font-size: 1.4rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        🚀 Quick Actions
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <!-- Team Chat Card -->
                        <a href="teamchat.php" style="text-decoration: none;">
                            <div style="background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(111, 66, 193, 0.2); color: white; text-align: center; cursor: pointer; transition: all 0.3s ease; min-height: 100px; display: flex; flex-direction: column; justify-content: center;" 
                                 onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(111, 66, 193, 0.3)'"
                                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(111, 66, 193, 0.2)'">
                                <div style="font-size: 2.5rem; margin-bottom: 0.5rem; opacity: 0.9;">💬</div>
                                <h4 style="margin: 0 0 0.3rem 0; font-size: 1.3rem; font-weight: 600; letter-spacing: -0.02em;">Team Chat</h4>
                                <p style="margin: 0; opacity: 0.85; font-size: 0.9rem; font-weight: 400;">Collaborate with your team</p>
                            </div>
                        </a>
                        
                        <!-- AI Assistant Card -->
                        <div style="background: linear-gradient(135deg, #00bcd4 0%, #0097a7 100%); padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 188, 212, 0.2); color: white; text-align: center; cursor: pointer; transition: all 0.3s ease; min-height: 100px; display: flex; flex-direction: column; justify-content: center; position: relative;"
                             onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 25px rgba(0, 188, 212, 0.3)'"
                             onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 188, 212, 0.2)'">
                            <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 0.5rem auto; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <img src="chatbot.png" alt="AI Assistant" style="width: 30px; height: 30px; object-fit: contain; border-radius: 50%;">
                            </div>
                            <h4 style="margin: 0 0 0.3rem 0; font-size: 1.3rem; font-weight: 600; letter-spacing: -0.02em;">AI Assistant</h4>
                            <p style="margin: 0; opacity: 0.85; font-size: 0.9rem; font-weight: 400;">Get help with risk management</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- All Risks Table -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📋 All Risks</h3>
                    <div style="display: flex; gap: 1rem;" class="no-print">
                        <button onclick="exportFilteredRisks('pdf')" class="btn btn-danger btn-sm">📄 Export PDF</button>
                        <button onclick="exportFilteredRisks('excel')" class="btn btn-success btn-sm">📊 Export Excel</button>
                    </div>
                </div>
                
                <!-- Display notification if set -->
                <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['message']; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php 
                    unset($_SESSION['message']);
                    unset($_SESSION['message_type']);
                endif; ?>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Search by Risk ID</label>
                            <input type="text" id="searchRiskId" class="filter-input" placeholder="Enter Risk ID (e.g., RC/2025/09/01 or RSK-2023-12345)">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Department</label>
                            <select id="departmentFilter" class="filter-input">
                                <option value="all">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Risk Level</label>
                            <select id="riskLevelFilter" class="filter-input">
                                <option value="all">All Risk Levels</option>
                                <option value="Critical">Critical</option>
                                <option value="High">High</option>
                                <option value="Medium">Medium</option>
                                <option value="Low">Low</option>
                                <option value="Not Assessed">Not Assessed</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Board Report</label>
                            <select id="boardFilter" class="filter-input">
                                <option value="all">All Risks</option>
                                <option value="YES">Reported to Board</option>
                                <option value="NO">Not Reported to Board</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label">Date From</label>
                            <input type="date" id="dateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date To</label>
                            <input type="date" id="dateTo" class="filter-input">
                        </div>
                        <div class="filter-group" style="display: flex; align-items: flex-end;">
                            <button onclick="clearAllFilters()" class="btn btn-secondary">Clear Filters</button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions no-print">
                    <div>
                        <button id="viewAllBtn" onclick="toggleViewAll()" class="btn btn-primary">View All Risks</button>
                        <span id="tableTitle" style="margin-left: 1rem; font-weight: 500;">Recent Risks (Last 10)</span>
                    </div>
                    <div>
                        <span id="selectedCount" class="selected-count">0 risks selected</span>
                        <button id="boardReportBtn" onclick="submitBoardReport()" class="btn btn-primary" disabled>
                            Report Selected to Board
                        </button>
                    </div>
                </div>
                
                <!-- Risks Table -->
                <div style="overflow-x: auto;">
                    <form id="boardReportForm" method="post">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">
                                        <input type="checkbox" id="selectAllRisks" onchange="toggleSelectAll()" class="risk-select-checkbox">
                                    </th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk ID</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Name</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Level</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Status</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Department</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Date Created</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Board Report Status</th>
                                    <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="risksTableBody">
                                <?php foreach ($recent_risks as $risk): 
                                    $levelColors = [
                                        'Critical' => '#dc3545',
                                        'High' => '#fd7e14', 
                                        'Medium' => '#ffc107',
                                        'Low' => '#28a745',
                                        'Not Assessed' => '#6c757d'
                                    ];
                                    $color = $levelColors[$risk['risk_level']] ?? '#6c757d';
                                    
                                    // Get beautiful status display
                                    $statusInfo = getBeautifulStatus($risk['status']);
                                ?>
                                <tr style="border-bottom: 1px solid #e9ecef;">
                                    <td style="padding: 1rem;">
                                        <input type="checkbox" name="selected_risks[]" value="<?php echo $risk['id']; ?>" 
                                               onchange="updateSelectedCount()" class="risk-select-checkbox">
                                    </td>
                                    <td style="padding: 1rem; color: #495057; font-weight: 500;">
                                        <?php if (!empty($risk['custom_risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                        <?php else: ?>
                                            <?php echo $risk['id']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                    <td style="padding: 1rem;">
                                        <span style="padding: 0.25rem 0.75rem; background: <?php echo $color; ?>; color: white; border-radius: 20px; font-size: 0.85rem; font-weight: 500;">
                                            <?php echo htmlspecialchars($risk['risk_level']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                            <?php echo $statusInfo['text']; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem; color: #495057;"><?php echo htmlspecialchars($risk['department']); ?></td>
                                    <td style="padding: 1rem; color: #495057;"><?php echo date('M j, Y', strtotime($risk['created_at'])); ?></td>
                                    <td style="padding: 1rem;">
                                        <?php if ($risk['to_be_reported_to_board'] == 'YES'): ?>
                                            <span class="board-report-badge">Already Reported</span>
                                        <?php else: ?>
                                            <span class="board-not-reported">Not Reported</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <button type="button" onclick="viewFullRiskDetails(<?php echo $risk['id']; ?>)" class="btn btn-primary btn-sm">View Details</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <input type="hidden" name="bulk_board_report" value="1">
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Board Risks Tab -->
        <div id="board" class="tab-content <?php echo $activeTab === 'board' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Board Risks Report</div>
                <div class="report-subtitle">Risks Reported to the Board</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            
            <!-- Board Risks Summary -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo isset($stats['board_risks']) ? $stats['board_risks'] : '0'; ?></div>
                    <div class="stat-label">Total Board Risks</div>
                    <div class="stat-description">Risks reported to board</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #dc3545;">
                        <?php 
                        $critical_board_risks = 0;
                        foreach ($all_risks as $risk) {
                            if (($risk['to_be_reported_to_board'] == 'YES' || $risk['risk_level'] == 'Critical') && $risk['risk_level'] == 'Critical') {
                                $critical_board_risks++;
                            }
                        }
                        echo $critical_board_risks;
                        ?>
                    </div>
                    <div class="stat-label">Critical Board Risks</div>
                    <div class="stat-description">Critical risks reported to board</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ffc107;">
                        <?php 
                        $open_board_risks = 0;
                        foreach ($all_risks as $risk) {
                            if (($risk['to_be_reported_to_board'] == 'YES' || $risk['risk_level'] == 'High' || $risk['risk_level'] == 'Critical') && $risk['status'] != 'closed') {
                                $open_board_risks++;
                            }
                        }
                        echo $open_board_risks;
                        ?>
                    </div>
                    <div class="stat-label">Open Board Risks</div>
                    <div class="stat-description">Board risks still open</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #28a745;">
                        <?php 
                        $closed_board_risks = 0;
                        foreach ($all_risks as $risk) {
                            if (($risk['to_be_reported_to_board'] == 'YES' || $risk['risk_level'] == 'High' || $risk['risk_level'] == 'Critical') && $risk['status'] == 'closed') {
                                $closed_board_risks++;
                            }
                        }
                        echo $closed_board_risks;
                        ?>
                    </div>
                    <div class="stat-label">Closed Board Risks</div>
                    <div class="stat-description">Board risks successfully closed</div>
                </div>
            </div>
            
            <!-- Board Risks Table -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📋 Board Risks</h3>
                    <div style="display: flex; gap: 1rem;" class="no-print">
                        <button onclick="exportBoardRisks('pdf')" class="btn btn-danger btn-sm">📄 Export PDF</button>
                        <button onclick="exportBoardRisks('excel')" class="btn btn-success btn-sm">📊 Export Excel</button>
                    </div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk ID</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Name</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Risk Level</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Status</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Department</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Date Created</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $board_risks = array_filter($all_risks, function($risk) {
                                return $risk['to_be_reported_to_board'] == 'YES' || $risk['risk_level'] == 'High' || $risk['risk_level'] == 'Critical';
                            });
                            
                            if (empty($board_risks)): ?>
                                <tr>
                                    <td colspan="7" style="padding: 2rem; text-align: center; color: #6c757d;">
                                        No risks are currently marked for board reporting.
                                    </td>
                                </tr>
                            <?php else: 
                                foreach ($board_risks as $risk): 
                                    $levelColors = [
                                        'Critical' => '#dc3545',
                                        'High' => '#fd7e14', 
                                        'Medium' => '#ffc107',
                                        'Low' => '#28a745',
                                        'Not Assessed' => '#6c757d'
                                    ];
                                    $color = $levelColors[$risk['risk_level']] ?? '#6c757d';
                                    
                                    // Get beautiful status display - FIXED: Using same function as main risks table
                                    $statusInfo = getBeautifulStatus($risk['status']);
                            ?>
                                <tr style="border-bottom: 1px solid #e9ecef;">
                                    <td style="padding: 1rem; color: #495057; font-weight: 500;">
                                        <?php if (!empty($risk['custom_risk_id'])): ?>
                                            <span class="risk-id-badge"><?php echo htmlspecialchars($risk['custom_risk_id']); ?></span>
                                        <?php else: ?>
                                            <?php echo $risk['id']; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                    <td style="padding: 1rem;">
                                        <span style="padding: 0.25rem 0.75rem; background: <?php echo $color; ?>; color: white; border-radius: 20px; font-size: 0.85rem; font-weight: 500;">
                                            <?php echo htmlspecialchars($risk['risk_level']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <span style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                                            <?php echo $statusInfo['text']; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 1rem; color: #495057;"><?php echo htmlspecialchars($risk['department']); ?></td>
                                    <td style="padding: 1rem; color: #495057;"><?php echo date('M j, Y', strtotime($risk['created_at'])); ?></td>
                                    <td style="padding: 1rem;">
                                        <button type="button" onclick="viewFullRiskDetails(<?php echo $risk['id']; ?>)" class="btn btn-primary btn-sm">View Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Analytics Tab -->
        <div id="analytics" class="tab-content <?php echo $activeTab === 'analytics' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Risk Analytics Report</div>
                <div class="report-subtitle">Risk Trends and Department Analysis</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                <!-- Risk Category Distribution -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📊 Risk Category Distribution</h3>
                    <div class="chart-container">
                        <canvas id="categoryDistributionChart"></canvas>
                    </div>
                </div>
                
                <!-- Report Generation -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;" class="no-print">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📋 Generate Reports</h3>
                    
                    <form id="reportForm" method="post" action="">
                        <input type="hidden" name="generate_report" value="1">
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Report Type:</label>
                            <select id="reportType" name="report_type" onchange="toggleCustomDateRange()" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                                <option value="monthly">Monthly Report</option>
                                <option value="quarterly">Quarterly Report</option>
                                <option value="annual">Annual Report</option>
                                <option value="custom">Custom Date Range</option>
                            </select>
                        </div>
                        
                        <!-- Custom Date Range Fields -->
                        <div id="customDateRange" class="custom-date-range">
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">From:</label>
                                <input type="date" id="dateFrom" name="date_from" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">To:</label>
                                <input type="date" id="dateTo" name="date_to" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Department:</label>
                            <select id="reportDepartment" name="department" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                                <option value="all">All Departments</option>
                                <?php foreach($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: #495057;">Risk Level:</label>
                            <select id="reportRiskLevel" name="risk_level" style="width: 100%; padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 6px;">
                                <option value="all">All Risk Levels</option>
                                <option value="Critical">Critical Only</option>
                                <option value="High">High Only</option>
                                <option value="Medium">Medium Only</option>
                                <option value="Low">Low Only</option>
                                <option value="Not Assessed">Not Assessed Only</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <button type="submit" name="format" value="pdf" class="btn btn-danger" style="padding: 0.75rem; background: #E60012; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                                📄 Download PDF Report
                            </button>
                            <button type="submit" name="format" value="excel" class="btn btn-success" style="padding: 0.75rem; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                                📊 Download Excel Report
                            </button>
                            <button type="button" onclick="printReport()" class="btn btn-primary" style="padding: 0.75rem; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                                🖨️ Print Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Analytics Charts -->
            <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
                <!-- Risk Trends -->
                <div class="risk-trends-container">
                    <div class="risk-trends-header">
                        <h3 class="risk-trends-title">📈 Risk Trends</h3>
                        <div class="risk-trends-controls">
                            <select id="trendsPeriod" class="filter-input" style="width: 150px;" onchange="updateTrendsPeriod()">
                                <option value="12">Last 12 Months</option>
                                <option value="6">Last 6 Months</option>
                                <option value="3">Last 3 Months</option>
                                <option value="custom">Custom Range</option>
                            </select>
                            <button onclick="refreshTrendsData()" class="btn btn-primary btn-sm">
                                <span>🔄</span> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <!-- Date Range Filter for Trends -->
                    <div class="trends-date-filter" id="trendsDateFilter" style="display: none;">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label class="filter-label">From:</label>
                                <input type="month" id="trendsDateFrom" class="filter-input" value="<?php echo date('Y-m', strtotime('-11 months')); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">To:</label>
                                <input type="month" id="trendsDateTo" class="filter-input" value="<?php echo date('Y-m'); ?>">
                            </div>
                            <div class="filter-group">
                                <button onclick="applyTrendsDateFilter()" class="btn btn-primary">Apply</button>
                                <button onclick="resetTrendsDateFilter()" class="btn btn-secondary">Reset</button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="chart-container-large">
                        <canvas id="riskTrendsChart"></canvas>
                    </div>
                    
                    <div class="risk-trends-legend">
                        <div class="risk-trends-legend-item">
                            <div class="risk-trends-legend-color" style="background-color: var(--critical-color);"></div>
                            <span>Critical</span>
                        </div>
                        <div class="risk-trends-legend-item">
                            <div class="risk-trends-legend-color" style="background-color: var(--high-color);"></div>
                            <span>High</span>
                        </div>
                        <div class="risk-trends-legend-item">
                            <div class="risk-trends-legend-color" style="background-color: var(--medium-color);"></div>
                            <span>Medium</span>
                        </div>
                        <div class="risk-trends-legend-item">
                            <div class="risk-trends-legend-color" style="background-color: var(--low-color);"></div>
                            <span>Low</span>
                        </div>
                        <div class="risk-trends-legend-item">
                            <div class="risk-trends-legend-color" style="background-color: var(--not-assessed-color);"></div>
                            <span>Not Assessed</span>
                        </div>
                    </div>
                    
                    <div class="risk-trends-stats">
                        <div class="risk-trends-stat-card">
                            <div class="risk-trends-stat-value" id="totalRisksCount">0</div>
                            <div class="risk-trends-stat-label">Total Risks</div>
                        </div>
                        <div class="risk-trends-stat-card">
                            <div class="risk-trends-stat-value" id="criticalRisksCount">0</div>
                            <div class="risk-trends-stat-label">Critical Risks</div>
                        </div>
                        <div class="risk-trends-stat-card">
                            <div class="risk-trends-stat-value" id="highRisksCount">0</div>
                            <div class="risk-trends-stat-label">High Risks</div>
                        </div>
                        <div class="risk-trends-stat-card">
                            <div class="risk-trends-stat-value" id="trendPercentage">0%</div>
                            <div class="risk-trends-stat-label">Trend vs Previous Period</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notifications Tab -->
        <div id="notifications" class="tab-content <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Notifications Report</div>
                <div class="report-subtitle">Recent Alerts and Upcoming Reviews</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <!-- Recent Alerts -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🚨 Recent Alerts</h3>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($recent_alerts)): ?>
                        <div style="text-align: center; padding: 2rem; color: #6c757d;">
                            <p>No recent alerts</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($recent_alerts as $alert): 
                            // Add special styling for high/critical risk alerts
                            $alertClass = '';
                            if (strpos($alert['title'], 'High/Critical Risk') !== false) {
                                if (strpos($alert['message'], 'Critical') !== false) {
                                    $alertClass = 'alert-critical';
                                } else {
                                    $alertClass = 'alert-highlight';
                                }
                            }
                        ?>
                        <div style="margin-bottom: 1rem; padding: 1rem; border-left: 4px solid #dc3545; background: #fff5f5; border-radius: 6px; <?php echo $alertClass; ?>">
                            <h4 style="margin: 0 0 0.5rem 0; color: #dc3545; font-size: 1rem;"><?php echo htmlspecialchars($alert['title']); ?></h4>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;"><?php echo htmlspecialchars($alert['message']); ?></p>
                            <small style="color: #6c757d;"><?php echo date('M j, Y g:i A', strtotime($alert['timestamp'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Upcoming Reviews -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📅 Upcoming Reviews</h3>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($upcoming_reviews)): ?>
                        <div style="text-align: center; padding: 2rem; color: #6c757d;">
                            <p>No upcoming reviews scheduled</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($upcoming_reviews as $review): ?>
                        <div style="margin-bottom: 1rem; padding: 1rem; border-left: 4px solid #17a2b8; background: #f0f9ff; border-radius: 6px;">
                            <h4 style="margin: 0 0 0.5rem 0; color: #17a2b8; font-size: 1rem;"><?php echo htmlspecialchars($review['risk_name']); ?></h4>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;">Department: <?php echo htmlspecialchars($review['department']); ?></p>
                            <p style="margin: 0 0 0.5rem 0; color: #495057; font-size: 0.9rem;">Review Type: <?php echo htmlspecialchars($review['review_type']); ?></p>
                            <small style="color: #6c757d;">Due: <?php echo date('M j, Y', strtotime($review['review_date'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Calendar Tab -->
        <div id="calendar" class="tab-content <?php echo $activeTab === 'calendar' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Compliance Calendar</div>
                <div class="report-subtitle">Upcoming Deadlines and Reviews</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <div class="calendar-header">
                    <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📅 Compliance Calendar</h3>
                    <div class="calendar-controls">
                        <select id="calendarFilter" class="filter-input" onchange="filterCalendar()" style="width: 200px;">
                            <option value="all">All Event Types</option>
                            <option value="Risk Review">Risk Reviews</option>
                            <option value="Control Assessment">Control Assessments</option>
                            <option value="Policy Review">Policy Reviews</option>
                            <option value="Audit Follow-up">Audit Follow-ups</option>
                        </select>
                        <button onclick="refreshCalendar()" class="btn btn-primary btn-sm">
                            <span>🔄</span> Refresh
                        </button>
                    </div>
                </div>
                <div id="calendar-container"></div>
            </div>
            
            <!-- Active Reminders Section - REDESIGNED -->
            <div class="active-reminders-container no-print">
                <div class="active-reminders-header">
                    <h3 class="active-reminders-title">⏰ Active Reminders</h3>
                    <span class="active-reminders-count" id="activeRemindersCount">0</span>
                </div>
                <div class="active-reminders-body">
                    <div class="active-reminders-list" id="active-reminders">
                        <!-- Active reminders will be populated by JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- REDESIGNED: Upcoming Deadlines Section -->
            <div class="deadlines-container no-print">
                <div class="deadlines-header">
                    <h3 class="deadlines-title">⏰ Upcoming Deadlines</h3>
                    <div class="deadlines-filter">
                        <button class="deadlines-filter-btn active" data-filter="all">All</button>
                        <button class="deadlines-filter-btn" data-filter="urgent">Urgent</button>
                        <button class="deadlines-filter-btn" data-filter="warning">This Week</button>
                    </div>
                </div>
                <div class="deadlines-body" id="upcoming-deadlines">
                    <!-- Deadlines will be populated by JavaScript -->
                </div>
            </div>
            
            <!-- REDESIGNED: Reminder Settings Section -->
            <div class="reminder-settings-container no-print">
                <div class="reminder-settings-header">
                    <h3 class="reminder-settings-title">⚙️ Reminder Settings</h3>
                </div>
                <div class="reminder-settings-body">
                    <p class="reminder-settings-intro">
                        Configure when to receive notifications for upcoming deadlines. Select one or more reminder options below.
                    </p>
                    
                    <div class="reminder-options">
                        <div class="reminder-option-card" data-reminder="7days">
                            <div class="reminder-option-header">
                                <input type="checkbox" id="reminder7days" class="reminder-option-checkbox" checked>
                                <label for="reminder7days" class="reminder-option-title">7 days before</label>
                            </div>
                            <p class="reminder-option-description">Get notified a week before deadline</p>
                        </div>
                        
                        <div class="reminder-option-card" data-reminder="3days">
                            <div class="reminder-option-header">
                                <input type="checkbox" id="reminder3days" class="reminder-option-checkbox" checked>
                                <label for="reminder3days" class="reminder-option-title">3 days before</label>
                            </div>
                            <p class="reminder-option-description">Get notified three days before deadline</p>
                        </div>
                        
                        <div class="reminder-option-card" data-reminder="1day">
                            <div class="reminder-option-header">
                                <input type="checkbox" id="reminder1day" class="reminder-option-checkbox" checked>
                                <label for="reminder1day" class="reminder-option-title">1 day before</label>
                            </div>
                            <p class="reminder-option-description">Get notified one day before deadline</p>
                        </div>
                    </div>
                    
                    <div class="reminder-save-section">
                        <button onclick="saveReminderSettings()" class="reminder-save-btn">
                            <span>💾</span> Save Settings
                        </button>
                        <div class="reminder-status">
                            <span class="reminder-status-indicator"></span>
                            <span>Settings saved</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Departmental Rankings Tab -->
        <div id="departmental-rankings" class="tab-content <?php echo $activeTab === 'departmental-rankings' ? 'active' : ''; ?>">
            <div class="report-header print-only">
                <div class="report-title">Departmental Rankings Report</div>
                <div class="report-subtitle">Department Risk Exposure and Analysis</div>
                <div class="report-date">Generated on: <?php echo date('F j, Y'); ?></div>
            </div>
            
            <!-- Departmental Rankings Summary -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($dept_rankings); ?></div>
                    <div class="stat-label">Departments Ranked</div>
                    <div class="stat-description">Total departments analyzed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #dc3545;">
                        <?php 
                        $highest_risk_dept = !empty($dept_rankings) ? $dept_rankings[0]['department'] : 'N/A';
                        echo $highest_risk_dept;
                        ?>
                    </div>
                    <div class="stat-label">Highest Risk</div>
                    <div class="stat-description">Department with most risk exposure</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #17a2b8;">
                        <?php 
                        $total_high_priority = !empty($dept_rankings) ? 
                            array_sum(array_column($dept_rankings, 'total_risks')) : 0;
                        echo $total_high_priority;
                        ?>
                    </div>
                    <div class="stat-label">Total Risks</div>
                    <div class="stat-description">Across all departments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #28a745;">
                        <?php 
                        $total_medium_priority = !empty($dept_rankings) ? 
                            array_sum(array_column($dept_rankings, 'total_risks')) : 0;
                        echo $total_medium_priority;
                        ?>
                    </div>
                    <div class="stat-label">Total Risks</div>
                    <div class="stat-description">Across all departments</div>
                </div>
            </div>
            
            <!-- Departmental Rankings Table -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;"> Departmental Rankings</h3>
                    <div style="display: flex; gap: 1rem;" class="no-print">
                        <select id="rankSortBy" onchange="sortDepartmentRankings()" class="filter-input" style="width: 180px;">
                            <option value="total_risks">Total Risks</option>
                        </select>
                        <button onclick="exportDepartmentRankings('pdf')" class="btn btn-danger btn-sm">📄 Export PDF</button>
                        <button onclick="exportDepartmentRankings('excel')" class="btn btn-success btn-sm">📊 Export Excel</button>
                    </div>
                </div>
                
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Rank</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Department</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Total Risks</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Trend</th>
                                <th style="padding: 1rem; text-align: left; font-weight: 600; color: white; background: #495057;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="departmentRankingsTableBody">
                            <?php 
                            if (!empty($dept_rankings)):
                                foreach ($dept_rankings as $index => $dept): 
                                    $rank = $index + 1;
                                    $trend_class = '';
                                    $trend_icon = '';
                                    
                                    if (isset($dept['trend'])) {
                                        if ($dept['trend'] > 0) {
                                            $trend_class = 'text-danger';
                                            $trend_icon = '↑';
                                        } elseif ($dept['trend'] < 0) {
                                            $trend_class = 'text-success';
                                            $trend_icon = '↓';
                                        } else {
                                            $trend_class = 'text-secondary';
                                            $trend_icon = '→';
                                        }
                                    }
                            ?>
                            <tr style="border-bottom: 1px solid #e9ecef;">
                                <td style="padding: 1rem; color: #495057; font-weight: 500;">
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: <?php echo $rank <= 3 ? '#E60012' : '#6c757d'; ?>; color: white; font-weight: bold;"><?php echo $rank; ?></span>
                                </td>
                                <td style="padding: 1rem; color: #495057; font-weight: 500;"><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td style="padding: 1rem; color: #495057;"><?php echo $dept['total_risks']; ?></td>
                                <td style="padding: 1rem;">
                                    <?php if (isset($dept['trend'])): ?>
                                        <span class="<?php echo $trend_class; ?>" style="font-weight: bold; font-size: 1.2rem;"><?php echo $trend_icon; ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem;">
                                    <button onclick="viewDepartmentDetails('<?php echo htmlspecialchars($dept['department']); ?>')" class="btn btn-primary btn-sm">View Details</button>
                                </td>
                            </tr>
                            <?php endforeach; 
                            else: ?>
                            <tr>
                                <td colspan="5" style="padding: 2rem; text-align: center; color: #6c757d;">
                                    No departmental data available.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Department Risk Distribution -->
            <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012; margin-bottom: 2rem;">
                <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🏢 Department Risk Distribution</h3>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
            
            <!-- Departmental Visualizations -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                <!-- Risk Priority Distribution -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <h3 style="margin: 0 0 1.5rem 0; color: #495057; font-size: 1.3rem; font-weight: 600;">🔴 Risk Priority Distribution</h3>
                    <div class="chart-container">
                        <canvas id="riskPriorityDistributionChart"></canvas>
                    </div>
                </div>
                
                <!-- Risk Category Breakdown by Department -->
                <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #E60012;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="margin: 0; color: #495057; font-size: 1.3rem; font-weight: 600;">📊 Risk Category Breakdown by Department</h3>
                        <div class="no-print">
                            <div class="multi-select-container">
                                <div class="multi-select-input" id="deptCategoryFilterInput" onclick="toggleDeptCategoryDropdown()">
                                    <span id="deptCategoryFilterText">Select Departments</span>
                                    <span style="float: right;">▼</span>
                                </div>
                                <div id="deptCategoryFilterDropdown" class="multi-select-dropdown">
                                    <div style="padding: 0.5rem;">
                                        <input type="text" id="deptCategorySearch" placeholder="Search departments..." style="width: 100%; padding: 0.5rem; border: 1px solid #e9ecef; border-radius: 4px;">
                                    </div>
                                    <div id="deptCategoryOptions">
                                        <?php foreach ($departments as $dept): ?>
                                        <div class="multi-select-option" data-value="<?php echo htmlspecialchars($dept); ?>">
                                            <input type="checkbox" id="dept_<?php echo htmlspecialchars($dept); ?>" value="<?php echo htmlspecialchars($dept); ?>">
                                            <label for="dept_<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="padding: 0.5rem; text-align: center; border-top: 1px solid #e9ecef;">
                                        <button onclick="selectAllDeptCategories()" class="btn btn-sm btn-secondary">Select All</button>
                                        <button onclick="clearAllDeptCategories()" class="btn btn-sm btn-secondary">Clear All</button>
                                        <button onclick="applyDeptCategoryFilter()" class="btn btn-sm btn-primary">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="deptCategoryBreakdownChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Risk Details Modal -->
    <div id="riskDetailsModal" class="risk-details-modal no-print">
        <div class="risk-details-modal-content">
            <div class="risk-details-modal-header">
                <h3 style="margin: 0; color: #495057;">Complete Risk Details</h3>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button onclick="printRiskDetails()" class="btn btn-secondary btn-sm">🖨️ Print Details</button>
                    <button onclick="closeRiskDetailsModal()" class="risk-details-modal-close">&times;</button>
                </div>
            </div>
            <div id="riskDetailsContent" class="risk-details-modal-body">
                <!-- Full risk details will be loaded here -->
            </div>
        </div>
    </div>
    
    <!-- Risk Details Popup -->
    <div id="riskPopupOverlay" onclick="closeRiskPopup()" class="no-print"></div>
    <div id="riskPopup" class="no-print">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; color: #495057;">Risk Details</h3>
            <button onclick="closeRiskPopup(event)" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6c757d;">&times;</button>
        </div>
        <div id="riskPopupContent">
            <!-- Risk details will be loaded here -->
        </div>
    </div>
    
    <script>
        // Global variables
        let animationTime = 0;
        let clickableAreas = [];
        let showingAllRisks = false;
        let allRisksData = <?php echo json_encode($all_risks); ?>;
        let recentRisksData = <?php echo json_encode($recent_risks); ?>;
        let boardRisksData = <?php echo json_encode($board_risks); ?>;
        
        // Source data management
        let sourceData = recentRisksData;   // Initially showing recent risks
        let filteredData = [...sourceData];  // Initially all recent risks (no filters)
        
        let categoryDistributionChart = null;
        let departmentChart = null;
        let trendsChart = null;
        let calendar = null;
        let calendarInitialized = false;
        let calendarEvents = <?php echo json_encode($calendarEvents); ?>;
        let deptRankings = <?php echo json_encode($dept_rankings); ?>;
        let deptCategoryData = <?php echo json_encode($dept_category_data); ?>;
        let selectedDeptCategories = [];
        let deptCategoryBreakdownChart = null;
        
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeAnalyticsCharts();
            
            // Set up filter event listeners
            document.getElementById('searchRiskId').addEventListener('input', applyFilters);
            document.getElementById('departmentFilter').addEventListener('change', applyFilters);
            document.getElementById('riskLevelFilter').addEventListener('change', applyFilters);
            document.getElementById('boardFilter').addEventListener('change', applyFilters);
            document.getElementById('dateFrom').addEventListener('change', applyFilters);
            document.getElementById('dateTo').addEventListener('change', applyFilters);
            
            // Set up department category filter search
            document.getElementById('deptCategorySearch').addEventListener('input', filterDeptCategoryOptions);
            
            // Set up deadline filter buttons
            document.querySelectorAll('.deadlines-filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.deadlines-filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    filterDeadlines(this.dataset.filter);
                });
            });
            
            // Set up reminder option cards
            document.querySelectorAll('.reminder-option-card').forEach(card => {
                card.addEventListener('click', function() {
                    const checkbox = this.querySelector('.reminder-option-checkbox');
                    checkbox.checked = !checkbox.checked;
                    this.classList.toggle('active', checkbox.checked);
                });
            });
            
            // Show notification if set
            <?php if (isset($_SESSION['message'])): ?>
                showNotification("<?php echo $_SESSION['message']; ?>", "<?php echo $_SESSION['message_type']; ?>");
                <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
            <?php endif; ?>
        });
        
        // Tab navigation
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav items
            const navItems = document.querySelectorAll('.nav-item a');
            navItems.forEach(item => {
                item.classList.remove('active');
            });
            
            // Show the selected tab content
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Add active class to the clicked nav item
            event.target.classList.add('active');
            
            // Initialize calendar if calendar tab is selected and not already initialized
            if (tabName === 'calendar' && !calendarInitialized) {
                initializeCalendar();
                calendarInitialized = true;
            }
            
            // Initialize departmental rankings charts if that tab is selected
            if (tabName === 'departmental-rankings') {
                initializeDepartmentalRankingsCharts();
            }
        }
        
        // Initialize Analytics Charts
        function initializeAnalyticsCharts() {
            // Risk Category Distribution Chart
            const categoryCanvas = document.getElementById('categoryDistributionChart');
            if (categoryCanvas) {
                const ctx = categoryCanvas.getContext('2d');
                const categoryData = <?php echo json_encode($category_data); ?>;
                
                // Prepare data for the chart
                const labels = categoryData.map(item => item.category_name);
                const data = categoryData.map(item => parseInt(item.risk_count));
                
                // Generate colors for each category
                const backgroundColors = [
                    '#E60012', '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFBE0B', 
                    '#FB5607', '#8338EC', '#3A86FF', '#38B000', '#9D4EDD'
                ];
                
                categoryDistributionChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: backgroundColors.slice(0, labels.length),
                            borderColor: '#fff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    boxWidth: 15,
                                    padding: 15,
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            
            // Risk Trends Chart - Enhanced
            const trendsCanvas = document.getElementById('riskTrendsChart');
            if (trendsCanvas) {
                const ctx = trendsCanvas.getContext('2d');
                const trendsData = <?php echo json_encode($trends_data); ?>;
                
                // Get last 12 months
                const months = [];
                const criticalTrend = [];
                const highTrend = [];
                const mediumTrend = [];
                const lowTrend = [];
                const notAssessedTrend = [];
                
                // Initialize with default 12-month range
                const dateFrom = new Date();
                dateFrom.setMonth(dateFrom.getMonth() - 11);
                const dateTo = new Date();
                
                // Generate month labels
                for (let i = 0; i < 12; i++) {
                    const date = new Date(dateFrom);
                    date.setMonth(date.getMonth() + i);
                    const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
                    const monthLabel = date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                    months.push(monthLabel);
                    
                    const criticalCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Critical').reduce((sum, d) => sum + parseInt(d.count), 0);
                    const highCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'High').reduce((sum, d) => sum + parseInt(d.count), 0);
                    const mediumCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Medium').reduce((sum, d) => sum + parseInt(d.count), 0);
                    const lowCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Low').reduce((sum, d) => sum + parseInt(d.count), 0);
                    const notAssessedCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Not Assessed').reduce((sum, d) => sum + parseInt(d.count), 0);
                    
                    criticalTrend.push(criticalCount);
                    highTrend.push(highCount);
                    mediumTrend.push(mediumCount);
                    lowTrend.push(lowCount);
                    notAssessedTrend.push(notAssessedCount);
                }
                
                // Update statistics
                updateTrendsStatistics(criticalTrend, highTrend, mediumTrend, lowTrend, notAssessedTrend);
                
                trendsChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Critical',
                                data: criticalTrend,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--critical-color'),
                                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--critical-color'),
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'High',
                                data: highTrend,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--high-color'),
                                backgroundColor: 'rgba(253, 126, 20, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--high-color'),
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'Medium',
                                data: mediumTrend,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--medium-color'),
                                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--medium-color'),
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'Low',
                                data: lowTrend,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--low-color'),
                                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--low-color'),
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                tension: 0.3,
                                fill: true
                            },
                            {
                                label: 'Not Assessed',
                                data: notAssessedTrend,
                                borderColor: getComputedStyle(document.documentElement).getPropertyValue('--not-assessed-color'),
                                backgroundColor: 'rgba(108, 117, 125, 0.1)',
                                borderWidth: 3,
                                pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--not-assessed-color'),
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7,
                                tension: 0.3,
                                fill: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Risks',
                                    color: '#495057',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Month',
                                    color: '#495057',
                                    font: {
                                        size: 14,
                                        weight: 'bold'
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#E60012',
                                borderWidth: 1,
                                cornerRadius: 6,
                                padding: 12,
                                displayColors: true,
                                callbacks: {
                                    title: function(context) {
                                        return context[0].label;
                                    },
                                    label: function(context) {
                                        const label = context.dataset.label || '';
                                        const value = context.raw || 0;
                                        return `${label}: ${value} risks`;
                                    }
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeOutQuart'
                        }
                    }
                });
            }
        }
        
        // Update trends statistics
        function updateTrendsStatistics(criticalTrend, highTrend, mediumTrend, lowTrend, notAssessedTrend) {
            // Calculate total risks
            let totalRisks = 0;
            let criticalRisks = 0;
            let highRisks = 0;
            
            for (let i = 0; i < criticalTrend.length; i++) {
                totalRisks += criticalTrend[i] + highTrend[i] + mediumTrend[i] + lowTrend[i] + notAssessedTrend[i];
                criticalRisks += criticalTrend[i];
                highRisks += highTrend[i];
            }
            
            // Calculate trend percentage
            let trendPercentage = 0;
            if (criticalTrend.length > 1) {
                const currentTotal = criticalTrend[criticalTrend.length - 1] + highTrend[highTrend.length - 1] + 
                                    mediumTrend[mediumTrend.length - 1] + lowTrend[lowTrend.length - 1] + 
                                    notAssessedTrend[notAssessedTrend.length - 1];
                const previousTotal = criticalTrend[criticalTrend.length - 2] + highTrend[highTrend.length - 2] + 
                                    mediumTrend[mediumTrend.length - 2] + lowTrend[lowTrend.length - 2] + 
                                    notAssessedTrend[notAssessedTrend.length - 2];
                
                if (previousTotal > 0) {
                    trendPercentage = Math.round(((currentTotal - previousTotal) / previousTotal) * 100);
                }
            }
            
            // Update DOM elements
            document.getElementById('totalRisksCount').textContent = totalRisks;
            document.getElementById('criticalRisksCount').textContent = criticalRisks;
            document.getElementById('highRisksCount').textContent = highRisks;
            document.getElementById('trendPercentage').textContent = `${trendPercentage > 0 ? '+' : ''}${trendPercentage}%`;
            
            // Update trend percentage color based on value
            const trendElement = document.getElementById('trendPercentage');
            if (trendPercentage > 0) {
                trendElement.style.color = '#dc3545'; // Red for increase
            } else if (trendPercentage < 0) {
                trendElement.style.color = '#28a745'; // Green for decrease
            } else {
                trendElement.style.color = '#6c757d'; // Gray for no change
            }
        }
        
        // Update trends period
        function updateTrendsPeriod() {
            const period = document.getElementById('trendsPeriod').value;
            const dateFilter = document.getElementById('trendsDateFilter');
            
            if (period === 'custom') {
                dateFilter.style.display = 'block';
            } else {
                dateFilter.style.display = 'none';
                
                // Update chart based on selected period
                const months = parseInt(period);
                const dateFrom = new Date();
                dateFrom.setMonth(dateFrom.getMonth() - months + 1);
                const dateTo = new Date();
                
                updateTrendsChart(dateFrom, dateTo);
            }
        }
        
        // Refresh trends data
        function refreshTrendsData() {
            // Show loading indicator
            const refreshBtn = event.target;
            const originalText = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<span class="spinner"></span> Loading...';
            refreshBtn.disabled = true;
            
            // Simulate data refresh
            setTimeout(() => {
                // Re-initialize the chart with updated data
                initializeAnalyticsCharts();
                
                // Reset button
                refreshBtn.innerHTML = originalText;
                refreshBtn.disabled = false;
                
                // Show success message
                showNotification('Trends data refreshed successfully', 'success');
            }, 1000);
        }
        
        // Initialize Departmental Rankings Charts
        function initializeDepartmentalRankingsCharts() {
            // Department Risk Distribution Chart
            const deptCanvas = document.getElementById('departmentChart');
            if (deptCanvas) {
                const ctx = deptCanvas.getContext('2d');
                const deptData = <?php echo json_encode($dept_analysis_data); ?>;
                
                if (deptData.length > 0) {
                    // Limit to top 10 departments for better visualization
                    const limitedData = deptData.slice(0, 10);
                    const labels = limitedData.map(d => d.department.length > 12 ? d.department.substring(0, 12) + '...' : d.department);
                    const totalRisks = limitedData.map(d => parseInt(d.total_risks));
                    const criticalRisks = limitedData.map(d => parseInt(d.critical_risks));
                    const closedRisks = limitedData.map(d => parseInt(d.closed_risks));
                    
                    departmentChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Total Risks',
                                    data: totalRisks,
                                    backgroundColor: '#007bff',
                                    borderColor: '#0056b3',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Critical Risks',
                                    data: criticalRisks,
                                    backgroundColor: '#dc3545',
                                    borderColor: '#c82333',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Closed Risks',
                                    data: closedRisks,
                                    backgroundColor: '#28a745',
                                    borderColor: '#1e7e34',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Number of Risks',
                                        color: '#495057',
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    }
                                },
                                y: {
                                    title: {
                                        display: true,
                                        text: 'Departments',
                                        color: '#495057',
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }
            }
            
            // Risk Priority Distribution Chart
            const riskPriorityCanvas = document.getElementById('riskPriorityDistributionChart');
            if (riskPriorityCanvas) {
                const ctx = riskPriorityCanvas.getContext('2d');
                
                if (deptRankings.length > 0) {
                    const labels = deptRankings.map(d => d.department);
                    const totalRisks = deptRankings.map(d => d.total_risks);
                    
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Total Risks',
                                    data: totalRisks,
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1,
                                    stack: 'Stack 0'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: 'Departments',
                                        color: '#495057',
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    }
                                },
                                y: {
                                    stacked: true,
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Number of Risks',
                                        color: '#495057',
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }
            }
            
            // Initialize Department Category Breakdown Chart
            initializeDeptCategoryBreakdownChart();
        }
        
        // Initialize Department Category Breakdown Chart
        function initializeDeptCategoryBreakdownChart() {
            const canvas = document.getElementById('deptCategoryBreakdownChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            
            // Filter data based on selected departments
            let filteredData = {...deptCategoryData};
            if (selectedDeptCategories.length > 0) {
                filteredData = {};
                selectedDeptCategories.forEach(dept => {
                    if (deptCategoryData[dept]) {
                        filteredData[dept] = deptCategoryData[dept];
                    }
                });
            }
            
            // Get all unique categories
            const allCategories = new Set();
            Object.values(filteredData).forEach(categories => {
                Object.keys(categories).forEach(category => {
                    allCategories.add(category);
                });
            });
            
            const categoryArray = Array.from(allCategories);
            const departments = Object.keys(filteredData);
            
            // Prepare data for the chart
            const datasets = departments.map((dept, index) => {
                // Generate a color for each department
                const hue = (index * 360 / departments.length) % 360;
                const color = `hsla(${hue}, 70%, 50%, 0.7)`;
                
                const data = categoryArray.map(category => {
                    return filteredData[dept][category] || 0;
                });
                
                return {
                    label: dept,
                    data: data,
                    backgroundColor: color,
                    borderColor: color.replace('0.7', '1'),
                    borderWidth: 1
                };
            });
            
            // Destroy existing chart if it exists
            if (deptCategoryBreakdownChart) {
                deptCategoryBreakdownChart.destroy();
            }
            
            // Create new chart
            deptCategoryBreakdownChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: categoryArray,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Risk Categories',
                                color: '#495057',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Risks',
                                color: '#495057',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    }
                }
            });
        }
        
        // Department Category Filter Functions
        function toggleDeptCategoryDropdown() {
            const dropdown = document.getElementById('deptCategoryFilterDropdown');
            const deptCategoryInput = document.getElementById('deptCategoryFilterInput');
            
            dropdown.classList.toggle('show');
        }
        
        function filterDeptCategoryOptions() {
            const searchTerm = document.getElementById('deptCategorySearch').value.toLowerCase();
            const options = document.querySelectorAll('#deptCategoryOptions .multi-select-option');
            
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    option.style.display = 'flex';
                } else {
                    option.style.display = 'none';
                }
            });
        }
        
        function selectAllDeptCategories() {
            const checkboxes = document.querySelectorAll('#deptCategoryOptions input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }
        
        function clearAllDeptCategories() {
            const checkboxes = document.querySelectorAll('#deptCategoryOptions input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        }
        
        function applyDeptCategoryFilter() {
            // Get selected departments
            selectedDeptCategories = [];
            const checkboxes = document.querySelectorAll('#deptCategoryOptions input[type="checkbox"]:checked');
            checkboxes.forEach(checkbox => {
                selectedDeptCategories.push(checkbox.value);
            });
            
            // Update the filter text
            const filterText = document.getElementById('deptCategoryFilterText');
            if (selectedDeptCategories.length === 0) {
                filterText.textContent = 'Select Departments';
            } else if (selectedDeptCategories.length === 1) {
                filterText.textContent = selectedDeptCategories[0];
            } else {
                filterText.textContent = `${selectedDeptCategories.length} Departments Selected`;
            }
            
            // Close the dropdown
            document.getElementById('deptCategoryFilterDropdown').classList.remove('show');
            
            // Update the chart
            initializeDeptCategoryBreakdownChart();
        }
        
        // Initialize Calendar
        function initializeCalendar() {
            const calendarEl = document.getElementById('calendar-container');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: calendarEvents,
                eventClick: function(info) {
                    // Show event details in a popup
                    showEventDetails(info.event);
                },
                height: 'auto',
                eventDisplay: 'block',
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                }
            });
            calendar.render();
            
            // Also populate the upcoming deadlines list
            populateUpcomingDeadlines();
            populateActiveReminders();
        }
        
        // REDESIGNED: Populate upcoming deadlines
        function populateUpcomingDeadlines() {
            const container = document.getElementById('upcoming-deadlines');
            container.innerHTML = '';
            
            // Get events that are in the next 30 days
            const today = new Date();
            const thirtyDaysLater = new Date();
            thirtyDaysLater.setDate(today.getDate() + 30);
            const upcomingEvents = calendarEvents.filter(event => {
                const eventDate = new Date(event.start);
                return eventDate >= today && eventDate <= thirtyDaysLater;
            });
            
            // Sort by date
            upcomingEvents.sort((a, b) => new Date(a.start) - new Date(b.start));
            
            if (upcomingEvents.length === 0) {
                container.innerHTML = `
                    <div class="empty-deadlines">
                        <div class="empty-deadlines-icon"></div>
                        <p>No upcoming deadlines in the next 30 days.</p>
                    </div>
                `;
                return;
            }
            
            upcomingEvents.forEach(event => {
                const eventDate = new Date(event.start);
                const daysUntil = Math.ceil((eventDate - today) / (1000 * 60 * 60 * 24));
                
                // Determine urgency class
                let urgencyClass = 'info';
                if (daysUntil <= 1) {
                    urgencyClass = 'urgent';
                } else if (daysUntil <= 7) {
                    urgencyClass = 'warning';
                }
                
                // Calculate progress percentage
                const progressPercentage = Math.max(0, Math.min(100, 100 - (daysUntil / 30) * 100));
                
                const eventEl = document.createElement('div');
                eventEl.className = `deadline-card ${urgencyClass}`;
                eventEl.innerHTML = `
                    <div class="deadline-card-header">
                        <h4 class="deadline-title">${event.title}</h4>
                        <span class="deadline-days">${daysUntil} day${daysUntil !== 1 ? 's' : ''}</span>
                    </div>
                    <div class="deadline-meta">
                        <div class="deadline-meta-item">
                            <span class="deadline-meta-icon">🏢</span>
                            <span>${event.extendedProps.department}</span>
                        </div>
                        <div class="deadline-meta-item">
                            <span class="deadline-meta-icon">📋</span>
                            <span>${event.extendedProps.type}</span>
                        </div>
                    </div>
                    <div class="deadline-date">
                        Due: ${eventDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
                    </div>
                    <div class="deadline-progress">
                        <div class="deadline-progress-bar" style="width: ${progressPercentage}%"></div>
                    </div>
                `;
                container.appendChild(eventEl);
            });
        }
        
        // REDESIGNED: Filter deadlines by urgency
        function filterDeadlines(filter) {
            const container = document.getElementById('upcoming-deadlines');
            const deadlineCards = container.querySelectorAll('.deadline-card');
            
            deadlineCards.forEach(card => {
                if (filter === 'all') {
                    card.style.display = 'block';
                } else if (filter === 'urgent') {
                    card.style.display = card.classList.contains('urgent') ? 'block' : 'none';
                } else if (filter === 'warning') {
                    card.style.display = card.classList.contains('warning') ? 'block' : 'none';
                }
            });
        }
        
        // Populate active reminders
        function populateActiveReminders() {
            const container = document.getElementById('active-reminders');
            const countElement = document.getElementById('activeRemindersCount');
            container.innerHTML = '';
            
            // Get events that are in the next 7 days
            const today = new Date();
            const sevenDaysLater = new Date();
            sevenDaysLater.setDate(today.getDate() + 7);
            const reminderEvents = calendarEvents.filter(event => {
                const eventDate = new Date(event.start);
                return eventDate >= today && eventDate <= sevenDaysLater;
            });
            
            // Update count
            countElement.textContent = reminderEvents.length;
            
            if (reminderEvents.length === 0) {
                container.innerHTML = '<p style="text-align: center; padding: 1rem; color: #6c757d;">No active reminders.</p>';
                return;
            }
            
            reminderEvents.forEach(event => {
                const eventDate = new Date(event.start);
                const daysUntil = Math.ceil((eventDate - today) / (1000 * 60 * 60 * 24));
                
                const reminderEl = document.createElement('div');
                reminderEl.className = 'active-reminder-item';
                reminderEl.innerHTML = `
                    <div class="active-reminder-content">
                        <div class="active-reminder-title">${event.title}</div>
                        <div class="active-reminder-meta">${event.extendedProps.department}</div>
                    </div>
                    <span class="active-reminder-days">${daysUntil}d</span>
                `;
                container.appendChild(reminderEl);
            });
        }
        
        // Show event details
        function showEventDetails(event) {
            const props = event.extendedProps;
            const content = `
                <h4>${event.title}</h4>
                <p><strong>Date:</strong> ${event.start.toLocaleDateString()}</p>
                <p><strong>Type:</strong> ${props.type}</p>
                <p><strong>Department:</strong> ${props.department}</p>
                <p><strong>Risk Level:</strong> ${props.risk_level}</p>
                <p><strong>Status:</strong> ${props.status}</p>
            `;
            showEventPopup(content);
        }
        
        // Show event popup
        function showEventPopup(content) {
            const popup = document.getElementById('riskPopup');
            const overlay = document.getElementById('riskPopupOverlay');
            document.getElementById('riskPopupContent').innerHTML = content;
            popup.style.display = 'block';
            overlay.style.display = 'block';
        }
        
        // Filter calendar
        function filterCalendar() {
            const filterValue = document.getElementById('calendarFilter').value;
            
            if (filterValue === 'all') {
                calendar.removeAllEvents();
                calendar.addEventSource(calendarEvents);
            } else {
                calendar.removeAllEvents();
                const filteredEvents = calendarEvents.filter(event => 
                    event.extendedProps.type === filterValue
                );
                calendar.addEventSource(filteredEvents);
            }
        }
        
        // Refresh calendar
        function refreshCalendar() {
            calendar.refetchEvents();
            populateUpcomingDeadlines();
            populateActiveReminders();
            showNotification('Calendar refreshed successfully', 'success');
        }
        
        // REDESIGNED: Save reminder settings
        function saveReminderSettings() {
            const reminder7days = document.getElementById('reminder7days').checked;
            const reminder3days = document.getElementById('reminder3days').checked;
            const reminder1day = document.getElementById('reminder1day').checked;
            
            // In a real application, we would save these settings to the user's profile
            // For now, we'll just show a notification
            showNotification('Reminder settings saved successfully', 'success');
            
            // Update active reminders
            populateActiveReminders();
            
            // Update status indicator
            const statusIndicator = document.querySelector('.reminder-status-indicator');
            statusIndicator.style.background = '#28a745';
            setTimeout(() => {
                statusIndicator.style.background = '#6c757d';
            }, 3000);
        }
        
        // FILTER FUNCTION - CORRECTED TO HANDLE NEW RISK ID FORMAT
        function applyFilters() {
            const searchRiskId = document.getElementById('searchRiskId').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            const riskLevelFilter = document.getElementById('riskLevelFilter').value;
            const boardFilter = document.getElementById('boardFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            
            filteredData = sourceData.filter(risk => {
                // Filter by Risk ID - Simplified and corrected logic
                if (searchRiskId) {
                    const numericId = risk.id.toString().toLowerCase();
                    const customRiskId = risk.custom_risk_id ? risk.custom_risk_id.toLowerCase() : '';
                    const searchLower = searchRiskId.toLowerCase().trim();
                    
                    // Simple includes check covers all cases
                    const matchesNumericId = numericId.includes(searchLower);
                    const matchesCustomId = customRiskId.includes(searchLower);
                    
                    if (!matchesNumericId && !matchesCustomId) {
                        return false;
                    }
                }
                
                // Filter by Department
                if (departmentFilter !== 'all' && risk.department !== departmentFilter) {
                    return false;
                }
                
                // Filter by Risk Level
                if (riskLevelFilter !== 'all' && risk.risk_level !== riskLevelFilter) {
                    return false;
                }
                
                // Filter by Board Reporting - Fixed to handle null/undefined values
                const boardReportStatus = risk.to_be_reported_to_board === 'YES' ? 'YES' : 'NO';
                if (boardFilter !== 'all' && boardReportStatus !== boardFilter) {
                    return false;
                }
                
                // Filter by Date Range - Fixed date comparison logic
                if (dateFrom || dateTo) {
                    const riskDate = new Date(risk.created_at);
                    // Reset time to start of day for accurate comparison
                    riskDate.setHours(0, 0, 0, 0);
                    
                    if (dateFrom) {
                        const fromDate = new Date(dateFrom);
                        fromDate.setHours(0, 0, 0, 0);
                        if (riskDate < fromDate) {
                            return false;
                        }
                    }
                    
                    if (dateTo) {
                        const toDate = new Date(dateTo);
                        // Set to end of day to include the entire day
                        toDate.setHours(23, 59, 59, 999);
                        if (riskDate > toDate) {
                            return false;
                        }
                    }
                }
                
                return true;
            });
            
            // Update table with filtered data
            updateRisksTable(filteredData);
            
            // Update table title
            const tableTitle = document.getElementById('tableTitle');
            if (showingAllRisks) {
                tableTitle.textContent = `All Risks (${filteredData.length} of ${sourceData.length})`;
            } else {
                tableTitle.textContent = `Recent Risks (${filteredData.length} of ${sourceData.length})`;
            }
        }
        
        function updateRisksTable(risks) {
            const tableBody = document.getElementById('risksTableBody');
            tableBody.innerHTML = '';
            
            risks.forEach(risk => {
                const levelColors = {
                    'Critical': '#dc3545',
                    'High': '#fd7e14',
                    'Medium': '#ffc107',
                    'Low': '#28a745',
                    'Not Assessed': '#6c757d'
                };
                const color = levelColors[risk.risk_level] || '#6c757d';
                
                // Get beautiful status display
                const statusInfo = getBeautifulStatus(risk.status);
                
                const row = document.createElement('tr');
                row.setAttribute('data-risk-id', risk.id);
                row.setAttribute('data-department', risk.department);
                row.setAttribute('data-risk-level', risk.risk_level);
                row.setAttribute('data-board', risk.to_be_reported_to_board || 'NO');
                row.setAttribute('data-created', risk.created_at);
                row.style.borderBottom = '1px solid #e9ecef';
                
                // Format risk ID display
                let riskIdDisplay = '';
                if (risk.custom_risk_id) {
                    riskIdDisplay = `<span class="risk-id-badge">${risk.custom_risk_id}</span>`;
                } else {
                    riskIdDisplay = risk.id;
                }
                
                row.innerHTML = `
                    <td style="padding: 1rem;">
                        <input type="checkbox" name="selected_risks[]" value="${risk.id}" 
                               onchange="updateSelectedCount()" class="risk-select-checkbox">
                    </td>
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">${riskIdDisplay}</td>
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">${risk.risk_name}</td>
                    <td style="padding: 1rem;">
                        <span style="padding: 0.25rem 0.75rem; background: ${color}; color: white; border-radius: 20px; font-size: 0.85rem; font-weight: 500;">
                            ${risk.risk_level}
                        </span>
                    </td>
                    <td style="padding: 1rem;">
                        <span style="background: ${statusInfo['bg']}; color: ${statusInfo['color']}; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.8rem; font-weight: 600;">
                            ${statusInfo['text']}
                        </span>
                    </td>
                    <td style="padding: 1rem; color: #495057;">${risk.department}</td>
                    <td style="padding: 1rem; color: #495057;">${new Date(risk.created_at).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</td>
                    <td style="padding: 1rem;">
                        ${risk.to_be_reported_to_board == 'YES' ? 
                            '<span class="board-report-badge">Already Reported</span>' : 
                            '<span class="board-not-reported">Not Reported</span>'}
                    </td>
                    <td style="padding: 1rem;">
                        <button type="button" onclick="viewFullRiskDetails(${risk.id})" class="btn btn-primary btn-sm">View Details</button>
                    </td>
                `;
                
                tableBody.appendChild(row);
            });
            
            // Update select all checkbox state
            updateSelectAllCheckbox();
        }
        
        function toggleViewAll() {
            const tableTitle = document.getElementById('tableTitle');
            const viewAllBtn = document.getElementById('viewAllBtn');
            
            if (!showingAllRisks) {
                // Show all risks
                sourceData = allRisksData;
                tableTitle.textContent = `All Risks (${allRisksData.length})`;
                viewAllBtn.textContent = 'Show Recent Only';
                showingAllRisks = true;
            } else {
                // Show recent risks only
                sourceData = recentRisksData;
                tableTitle.textContent = 'Recent Risks (Last 10)';
                viewAllBtn.textContent = 'View All Risks';
                showingAllRisks = false;
            }
            
            // Apply the current filters to the new source data
            applyFilters();
        }
        
        function clearAllFilters() {
            document.getElementById('searchRiskId').value = '';
            document.getElementById('departmentFilter').value = 'all';
            document.getElementById('riskLevelFilter').value = 'all';
            document.getElementById('boardFilter').value = 'all';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            
            // Reset filteredData to the entire sourceData
            filteredData = [...sourceData];
            
            // Update the table
            updateRisksTable(filteredData);
            
            // Update table title
            const tableTitle = document.getElementById('tableTitle');
            if (showingAllRisks) {
                tableTitle.textContent = `All Risks (${sourceData.length})`;
            } else {
                tableTitle.textContent = 'Recent Risks (Last 10)';
            }
        }
        
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification notification-${type} no-print`;
            notification.style.position = 'fixed';
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.padding = '15px 20px';
            notification.style.borderRadius = '6px';
            notification.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
            notification.style.zIndex = '10000';
            notification.style.minWidth = '250px';
            notification.style.maxWidth = '400px';
            notification.style.color = 'white';
            notification.style.fontWeight = '500';
            
            // Set background color based on type
            if (type === 'success') {
                notification.style.backgroundColor = '#28a745';
            } else if (type === 'error') {
                notification.style.backgroundColor = '#dc3545';
            } else if (type === 'warning') {
                notification.style.backgroundColor = '#ffc107';
                notification.style.color = '#212529';
            } else {
                notification.style.backgroundColor = '#17a2b8';
            }
            
            // Set message
            notification.textContent = message;
            
            // Add to DOM
            document.body.appendChild(notification);
            
            // Add show class for animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.5s ease';
                
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 500);
            }, 3000);
        }
        
        // Risk details functions
        function getRiskLevelColor(level) {
            switch(level?.toLowerCase()) {
                case 'critical': return '#dc3545';
                case 'high': return '#fd7e14';
                case 'medium': return '#ffc107';
                case 'low': return '#28a745';
                case 'not assessed': return '#6c757d';
                default: return '#6c757d';
            }
        }
        
        // Board reporting functions
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('input[name="selected_risks[]"]:checked');
            const count = checkboxes.length;
            const selectedCount = document.getElementById('selectedCount');
            const boardReportBtn = document.getElementById('boardReportBtn');
            
            selectedCount.textContent = `${count} risk${count !== 1 ? 's' : ''} selected`;
            boardReportBtn.disabled = count === 0;
            
            // Update select all checkbox state
            updateSelectAllCheckbox();
        }
        
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAllRisks');
            const riskCheckboxes = document.querySelectorAll('input[name="selected_risks[]"]');
            
            riskCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateSelectedCount();
        }
        
        function updateSelectAllCheckbox() {
            const selectAllCheckbox = document.getElementById('selectAllRisks');
            const riskCheckboxes = document.querySelectorAll('input[name="selected_risks[]"]');
            const checkedCheckboxes = document.querySelectorAll('input[name="selected_risks[]"]:checked');
            
            // If all checkboxes are checked, check the select all checkbox
            selectAllCheckbox.checked = riskCheckboxes.length > 0 && riskCheckboxes.length === checkedCheckboxes.length;
            
            // If some but not all checkboxes are checked, set the indeterminate state
            selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < riskCheckboxes.length;
        }
        
        function submitBoardReport() {
            const form = document.getElementById('boardReportForm');
            const selectedRisks = document.querySelectorAll('input[name="selected_risks[]"]:checked');
            
            if (selectedRisks.length === 0) {
                showNotification('Please select at least one risk to report to the board.', 'warning');
                return;
            }
            
            // Confirm before submitting
            if (!confirm(`Are you sure you want to report ${selectedRisks.length} risk${selectedRisks.length !== 1 ? 's' : ''} to the board?`)) {
                return;
            }
            
            // Submit the form
            form.submit();
        }
        
        function exportBoardRisks(format) {
            if (boardRisksData.length === 0) {
                showNotification('No board risks to export.', 'error');
                return;
            }
            
            // Collect data from visible rows
            const exportData = [];
            boardRisksData.forEach(risk => {
                exportData.push({
                    riskId: risk.custom_risk_id || risk.id,
                    riskName: risk.risk_name,
                    riskLevel: risk.risk_level,
                    status: risk.status,
                    department: risk.department,
                    dateCreated: new Date(risk.created_at).toLocaleDateString(),
                    boardReport: risk.to_be_reported_to_board || 'NO'
                });
            });
            
            if (format === 'excel') {
                exportToExcel(exportData, 'board_risks');
            } else if (format === 'pdf') {
                exportToPDF(exportData, 'board_risks');
            }
        }
        
        function exportFilteredRisks(format) {
            if (filteredData.length === 0) {
                showNotification('No risks to export. Please adjust your filters.', 'error');
                return;
            }
            
            // Collect data from visible rows
            const exportData = [];
            filteredData.forEach(risk => {
                exportData.push({
                    riskId: risk.custom_risk_id || risk.id,
                    riskName: risk.risk_name,
                    riskLevel: risk.risk_level,
                    status: risk.status,
                    department: risk.department,
                    dateCreated: new Date(risk.created_at).toLocaleDateString(),
                    boardReport: risk.to_be_reported_to_board || 'NO'
                });
            });
            
            if (format === 'excel') {
                exportToExcel(exportData);
            } else if (format === 'pdf') {
                exportToPDF(exportData);
            }
        }
        
        function exportToExcel(data, prefix = 'risk_export') {
            let csvContent = "Risk ID,Risk Name,Risk Level,Status,Department,Date Created,Risk Description,Treatment Action,Target Completion Date,Risk Owner\n";
            
            data.forEach(row => {
                csvContent += `"${row.riskId}","${row.riskName}","${row.riskLevel}","${row.status}","${row.department}","${row.dateCreated}","${row.riskDescription || ''}","${row.treatmentAction || ''}","${row.targetCompletionDate || ''}","${row.riskOwner || ''}"\n`;
            });
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `${prefix}_${new Date().toISOString().split('T')[0]}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showNotification('Excel export completed successfully', 'success');
        }
        
        function exportToPDF(data, prefix = 'risk_export') {
            const printWindow = window.open('', '_blank');
            let tableRows = '';
            
            data.forEach(row => {
                const riskIdDisplay = row.custom_risk_id ? 
                    `<span class="risk-id-badge">${row.custom_risk_id}</span>` : 
                    row.riskId;
                
                tableRows += `
                    <tr>
                        <td>${riskIdDisplay}</td>
                        <td>${row.riskName}</td>
                        <td>${row.riskLevel}</td>
                        <td>${row.status}</td>
                        <td>${row.department}</td>
                        <td>${row.dateCreated}</td>
                        <td>${row.boardReport}</td>
                    </tr>
                `;
            });
            
            printWindow.document.write(`
                <html>
                <head>
                    <title>${prefix === 'board_risks' ? 'Board Risks Report' : 'Risk Export Report'}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #E60012; padding-bottom: 10px; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 12px; }
                        th { background-color: #495057; color: white; font-weight: bold; }
                        tr:nth-child(even) { background-color: #f8f9fa; }
                        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #666; }
                        .risk-badge { padding: 3px 6px; border-radius: 10px; font-size: 10px; font-weight: bold; color: white; }
                        .critical { background-color: #dc3545; }
                        .high { background-color: #fd7e14; }
                        .medium { background-color: #ffc107; color: #212529; }
                        .low { background-color: #28a745; }
                        .print-only { display: block; }
                        .no-print { display: none; }
                        .risk-id-badge {
                            display: inline-block;
                            background: #E60012;
                            color: white;
                            padding: 0.25rem 0.75rem;
                            border-radius: 4px;
                            font-size: 0.85rem;
                            font-weight: 600;
                            margin-right: 0.5rem;
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Airtel Risk Management System</h1>
                        <h2>${prefix === 'board_risks' ? 'Board Risks Report' : 'Risk Export Report'}</h2>
                        <p>Generated on: ${new Date().toLocaleDateString()} | Total Risks: ${data.length}</p>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Risk ID</th>
                                <th>Risk Name</th>
                                <th>Risk Level</th>
                                <th>Status</th>
                                <th>Department</th>
                                <th>Date Created</th>
                                <th>Board Report</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                    <div class="footer">
                        <p>This report contains ${data.length} risk records exported from the Airtel Risk Management System.</p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
            
            showNotification('PDF export completed successfully', 'success');
        }
        
        function printReport() {
            window.print();
        }
        
        function printRisksTable() {
            window.print();
        }
        
        // Toggle custom date range fields
        function toggleCustomDateRange() {
            const reportType = document.getElementById('reportType').value;
            const customDateRange = document.getElementById('customDateRange');
            
            if (reportType === 'custom') {
                customDateRange.classList.add('show');
            } else {
                customDateRange.classList.remove('show');
            }
        }
        
        // Risk Trends Date Filter Functions
        function applyTrendsDateFilter() {
            const dateFrom = document.getElementById('trendsDateFrom').value;
            const dateTo = document.getElementById('trendsDateTo').value;
            
            if (!dateFrom || !dateTo) {
                showNotification('Please select both from and to dates.', 'warning');
                return;
            }
            
            // Convert to Date objects
            const fromDate = new Date(dateFrom + '-01');
            const toDate = new Date(dateTo + '-01');
            
            // Validate date range
            if (fromDate > toDate) {
                showNotification('From date must be before to date.', 'warning');
                return;
            }
            
            // Calculate month difference
            const monthsDiff = (toDate.getFullYear() - fromDate.getFullYear()) * 12 + (toDate.getMonth() - fromDate.getMonth()) + 1;
            
            if (monthsDiff > 24) {
                showNotification('Date range cannot exceed 24 months.', 'warning');
                return;
            }
            
            // Update the trends chart with the selected date range
            updateTrendsChart(fromDate, toDate);
            
            showNotification('Trends chart updated successfully.', 'success');
        }
        
        function resetTrendsDateFilter() {
            // Reset to default 12-month range
            const dateFrom = new Date();
            dateFrom.setMonth(dateFrom.getMonth() - 11);
            const dateTo = new Date();
            
            document.getElementById('trendsDateFrom').value = dateFrom.toISOString().slice(0, 7);
            document.getElementById('trendsDateTo').value = dateTo.toISOString().slice(0, 7);
            
            // Update the chart with default range
            updateTrendsChart(dateFrom, dateTo);
            
            showNotification('Trends chart reset to default range.', 'success');
        }
        
        function updateTrendsChart(fromDate, toDate) {
            if (!trendsChart) return;
            
            const trendsData = <?php echo json_encode($trends_data); ?>;
            
            // Generate month labels
            const months = [];
            const criticalTrend = [];
            const highTrend = [];
            const mediumTrend = [];
            const lowTrend = [];
            const notAssessedTrend = [];
            
            // Calculate number of months in the range
            const monthsDiff = (toDate.getFullYear() - fromDate.getFullYear()) * 12 + (toDate.getMonth() - fromDate.getMonth()) + 1;
            
            // Generate month labels and data
            for (let i = 0; i < monthsDiff; i++) {
                const date = new Date(fromDate);
                date.setMonth(date.getMonth() + i);
                const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
                const monthLabel = date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                months.push(monthLabel);
                
                const criticalCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Critical').reduce((sum, d) => sum + parseInt(d.count), 0);
                const highCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'High').reduce((sum, d) => sum + parseInt(d.count), 0);
                const mediumCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Medium').reduce((sum, d) => sum + parseInt(d.count), 0);
                const lowCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Low').reduce((sum, d) => sum + parseInt(d.count), 0);
                const notAssessedCount = trendsData.filter(d => d.month === monthKey && d.risk_level === 'Not Assessed').reduce((sum, d) => sum + parseInt(d.count), 0);
                
                criticalTrend.push(criticalCount);
                highTrend.push(highCount);
                mediumTrend.push(mediumCount);
                lowTrend.push(lowCount);
                notAssessedTrend.push(notAssessedCount);
            }
            
            // Update statistics
            updateTrendsStatistics(criticalTrend, highTrend, mediumTrend, lowTrend, notAssessedTrend);
            
            // Update chart data
            trendsChart.data.labels = months;
            trendsChart.data.datasets[0].data = criticalTrend;
            trendsChart.data.datasets[1].data = highTrend;
            trendsChart.data.datasets[2].data = mediumTrend;
            trendsChart.data.datasets[3].data = lowTrend;
            trendsChart.data.datasets[4].data = notAssessedTrend;
            
            // Update chart
            trendsChart.update();
        }
        
        // Updated viewFullRiskDetails function to use AJAX
        function viewFullRiskDetails(riskId) {
            // Show the modal with a loading indicator
            const modal = document.getElementById('riskDetailsModal');
            const content = document.getElementById('riskDetailsContent');
            
            modal.style.display = 'block';
            content.innerHTML = '<div style="text-align: center; padding: 2rem;"><div class="spinner"></div><p class="mt-2">Loading risk details...</p></div>';
            
            // Create AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'get_full_risk_details.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            // Handle response
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                // Display the HTML content
                                content.innerHTML = response.html;
                            } else {
                                // Display error message
                                content.innerHTML = `<div class="alert alert-danger">${response.message || 'Error loading risk details'}</div>`;
                            }
                        } catch (e) {
                            // Handle JSON parsing error
                            content.innerHTML = '<div class="alert alert-danger">Error parsing response from server</div>';
                            console.error('Error parsing response:', e);
                        }
                    } else {
                        // Handle HTTP error
                        content.innerHTML = `<div class="alert alert-danger">Error ${xhr.status}: ${xhr.statusText}</div>`;
                    }
                }
            };
            
            // Send request with risk ID and CSRF token if available
            const params = `id=${riskId}`;
            const csrfToken = document.querySelector('meta[name="csrf-token"]');
            if (csrfToken) {
                params += `&csrf_token=${encodeURIComponent(csrfToken.getAttribute('content'))}`;
            }
            xhr.send(params);
        }
        
        function closeRiskDetailsModal() {
            document.getElementById('riskDetailsModal').style.display = 'none';
        }
        
        function printRiskDetails() {
            const printContent = document.getElementById('riskDetailsContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Risk Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .print-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #E60012; padding-bottom: 10px; }
                        .detail-section { margin-bottom: 20px; }
                        .detail-label { font-weight: bold; color: #495057; }
                        .detail-value { margin-left: 10px; }
                        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f8f9fa; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Airtel Risk Management System</h1>
                        <h2>Risk Details Report</h2>
                        <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Departmental Rankings Functions
        function sortDepartmentRankings() {
            const sortBy = document.getElementById('rankSortBy').value;
            const tableBody = document.getElementById('departmentRankingsTableBody');
            const rows = Array.from(tableBody.querySelectorAll('tr'));
            
            // Get the original data
            const deptRankings = <?php echo json_encode($dept_rankings); ?>;
            
            // Sort the data based on selected criteria
            let sortedData = [...deptRankings];
            
            switch(sortBy) {
                case 'total_risks':
                    sortedData.sort((a, b) => b.total_risks - a.total_risks);
                    break;
            }
            
            // Clear the table
            tableBody.innerHTML = '';
            
            // Rebuild the table with sorted data
            sortedData.forEach((dept, index) => {
                const rank = index + 1;
                const trend_class = '';
                const trend_icon = '';
                
                if (dept.trend) {
                    if (dept.trend > 0) {
                        trend_class = 'text-danger';
                        trend_icon = '↑';
                    } else if (dept.trend < 0) {
                        trend_class = 'text-success';
                        trend_icon = '↓';
                    } else {
                        trend_class = 'text-secondary';
                        trend_icon = '→';
                    }
                }
                
                const row = document.createElement('tr');
                row.style.borderBottom = '1px solid #e9ecef';
                row.innerHTML = `
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: ${rank <= 3 ? '#E60012' : '#6c757d'}; color: white; font-weight: bold;">${rank}</span>
                    </td>
                    <td style="padding: 1rem; color: #495057; font-weight: 500;">${dept.department}</td>
                    <td style="padding: 1rem; color: #495057;">${dept.total_risks}</td>
                    <td style="padding: 1rem;">
                        ${dept.trend ? 
                            `<span class="${trend_class}" style="font-weight: bold; font-size: 1.2rem;">${trend_icon}</span>` : 
                            '<span class="text-secondary">-</span>'}
                    </td>
                    <td style="padding: 1rem;">
                        <button onclick="viewDepartmentDetails('${dept.department}')" class="btn btn-primary btn-sm">View Details</button>
                    </td>
                `;
                
                tableBody.appendChild(row);
            });
        }
        
        function viewDepartmentDetails(department) {
            // Redirect to a filtered view of risks for this department
            window.location.href = `risk_register.php?department=${encodeURIComponent(department)}`;
        }
        
        function exportDepartmentRankings(format) {
            if (deptRankings.length === 0) {
                showNotification('No departmental data to export.', 'error');
                return;
            }
            
            // Prepare data for export
            const exportData = deptRankings.map(dept => ({
                department: dept.department,
                total_risks: dept.total_risks,
                trend: dept.trend ? (dept.trend > 0 ? 'Increasing' : (dept.trend < 0 ? 'Decreasing' : 'Stable')) : 'N/A'
            }));
            
            if (format === 'excel') {
                exportToExcel(exportData, 'departmental_rankings');
            } else if (format === 'pdf') {
                exportToPDF(exportData, 'departmental_rankings');
            }
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const deptCategoryDropdown = document.getElementById('deptCategoryFilterDropdown');
            const deptCategoryInput = document.getElementById('deptCategoryFilterInput');
            
            if (!deptCategoryInput.contains(event.target) && !deptCategoryDropdown.contains(event.target)) {
                deptCategoryDropdown.classList.remove('show');
            }
        });
        
        // CloseRiskPopup function to properly close the popup
        function closeRiskPopup(event) {
            if (event) {
                event.stopPropagation();
            }
            document.getElementById('riskPopup').style.display = 'none';
            document.getElementById('riskPopupOverlay').style.display = 'none';
        }
    </script>
</body>
</html>
