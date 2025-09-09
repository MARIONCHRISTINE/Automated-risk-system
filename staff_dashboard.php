<?php
include_once 'includes/auth.php';
requireRole('staff');
include_once 'config/database.php';
include_once 'includes/auto_assignment.php';
// Initialize database connection
$database = new Database();
$db = $database->getConnection();
// Improved session management - ensure user data is available
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['error_message'] = "Session expired. Please log in again.";
    header("Location: login.php");
    exit();
}
// Function to get department abbreviation - optimized
function getDepartmentAbbreviation($department) {
    // Normalize the department string by trimming and replacing multiple spaces
    $department = trim($department);
    $department = preg_replace('/\s+/', ' ', $department);
    
    // Replace ampersands with 'and' to avoid them in abbreviations
    $department = str_replace('&', 'and', $department);
    
    // Check for IT department patterns - more comprehensive
    $it_patterns = [
        '/information\s*(and|&)?\s*technology/i',
        '/it\s*(and|&)?\s*technology/i',
        '/^it$/i',
        '/i\s*&\s*t/i',
        '/i\s*and\s*t/i',
        '/i\s*t\s*department/i',
        '/information\s*technology\s*department/i',
        '/department\s*of\s*it/i',
        '/department\s*of\s*information\s*technology/i',
        '/i\.t\./i',
        '/^i$/i' // This will catch single "I" but we'll override it below
    ];
    
    foreach ($it_patterns as $pattern) {
        if (preg_match($pattern, $department)) {
            return 'IT';
        }
    }
    
    // Special handling for common IT department names
    $it_keywords = ['information', 'technology', 'it', 'computer', 'software', 'network', 'system'];
    $words = explode(' ', strtolower($department));
    $it_count = 0;
    
    foreach ($words as $word) {
        if (in_array($word, $it_keywords)) {
            $it_count++;
        }
    }
    
    // If at least 2 IT-related keywords are found, it's likely the IT department
    if ($it_count >= 2) {
        return 'IT';
    }
    
    // If the department name contains "IT" as a standalone word
    if (preg_match('/\bit\b/i', $department)) {
        return 'IT';
    }
    
    $abbreviations = [
        'Risk and Compliance' => 'RC',
        'Finance' => 'FIN',
        'Information Technology' => 'IT',
        'Information and Technology' => 'IT',
        'IT and Technology' => 'IT',
        'IT & Technology' => 'IT',
        'Human Resources' => 'HR',
        'Operations' => 'OPS',
        'Marketing' => 'MKT',
        'Sales' => 'SLS',
        'Legal' => 'LGL',
        'Customer Service' => 'CS',
        'Research and Development' => 'RD',
        'Quality Assurance' => 'QA',
        'Procurement' => 'PRC',
        'Administration' => 'ADM',
        'General' => 'GEN'
    ];
    
    // Check if we have a direct mapping
    if (isset($abbreviations[$department])) {
        return $abbreviations[$department];
    }
    
    // For unknown departments, create abbreviation from first letters of each word
    $words = explode(' ', $department);
    $abbr = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $abbr .= strtoupper(substr($word, 0, 1));
        }
    }
    
    // Limit to 4 characters max
    return substr($abbr, 0, 4);
}
// Function to normalize risk categories (replace I&T with IT)
function normalizeRiskCategories($categories) {
    if (is_array($categories)) {
        return array_map(function($cat) {
            return preg_replace('/I\s*&\s*T|I\s*and\s*T|IandT|I and T|Iand T|I andT/i', 'IT', $cat);
        }, $categories);
    } else {
        return preg_replace('/I\s*&\s*T|I\s*and\s*T|IandT|I and T|Iand T|I andT/i', 'IT', $categories);
    }
}
// Get complete user information with department data only if not already in session
if (!isset($_SESSION['department']) || empty($_SESSION['department']) || 
    !isset($_SESSION['department_id']) || empty($_SESSION['department_id'])) {
    
    $query = "SELECT u.id, u.email, u.full_name, u.department_id, d.name as department_name 
              FROM users u 
              LEFT JOIN departments d ON u.department_id = d.id 
              WHERE u.id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Store department information in session
        $_SESSION['department'] = $userData['department_name'] ?? 'General';
        $_SESSION['department_id'] = $userData['department_id'] ?? 1;
    } else {
        $_SESSION['error_message'] = "User account not found. Please contact administrator.";
        header("Location: login.php");
        exit();
    }
}
// Set user information from session
$user = [
    'id' => $_SESSION['user_id'],
    'email' => $_SESSION['email'],
    'full_name' => $_SESSION['full_name'],
    'department' => $_SESSION['department'],
    'department_id' => $_SESSION['department_id']
];
// Create risk_sequences table if it doesn't exist
$check_table_query = "SHOW TABLES LIKE 'risk_sequences'";
$stmt = $db->prepare($check_table_query);
$stmt->execute();
if ($stmt->rowCount() == 0) {
    // Create the table
    $create_table_query = "CREATE TABLE `risk_sequences` (
        `department` varchar(100) NOT NULL,
        `year_month` varchar(7) NOT NULL,
        `last_number` int(11) NOT NULL,
        PRIMARY KEY (`department`, `year_month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($create_table_query);
}
// Check if custom_risk_id column exists, if not add it
$check_column_query = "SHOW COLUMNS FROM `risk_incidents` LIKE 'custom_risk_id'";
$stmt = $db->prepare($check_column_query);
$stmt->execute();
if ($stmt->rowCount() == 0) {
    $alter_table_query = "ALTER TABLE `risk_incidents` ADD COLUMN `custom_risk_id` VARCHAR(50) DEFAULT NULL";
    $db->exec($alter_table_query);
}
if (isset($_POST['submit_risk'])) {
    $risk_categories = isset($_POST['risk_categories']) ? $_POST['risk_categories'] : [];
    $category_details = [];
    
    // Process category details
    foreach ($risk_categories as $category) {
        $field_name = 'category_details[' . $category . ']';
        if (isset($_POST['category_details'][$category]) && !empty($_POST['category_details'][$category])) {
            $category_details[$category] = $_POST['category_details'][$category];
        }
    }
    
    // Normalize risk categories to replace I&T with IT
    $risk_categories = normalizeRiskCategories($risk_categories);
    
    // Store JSON-encoded strings in variables before binding
    $risk_categories_json = json_encode($risk_categories);
    $category_details_json = json_encode($category_details);
    
    $involves_money_loss = isset($_POST['involves_money_loss']) ? $_POST['involves_money_loss'] == 'Yes' ? 1 : 0 : 0;
    $money_amount = isset($_POST['money_amount']) ? $_POST['money_amount'] : '0.00';
    $risk_description = $_POST['risk_description'];
    
    // Get cause of risk from radio buttons
    $cause_of_risk = $_POST['cause_of_risk'];
    // Get cause of risk description
    $cause_of_risk_description = isset($_POST['cause_of_risk_description']) ? $_POST['cause_of_risk_description'] : '';
    
    $user_id = $_SESSION['user_id'];
    
    // Get user's department from session (already available)
    $department = $_SESSION['department'];
    $department_id = $_SESSION['department_id'];
    
    // Get the current date for risk entry
    $date_of_risk_entry = date('Y-m-d');
    
    // Extract year and month from the entry date
    $risk_year = date('Y', strtotime($date_of_risk_entry));
    $risk_month = date('m', strtotime($date_of_risk_entry));
    $year_month = date('Y-m', strtotime($date_of_risk_entry));
    
    // Get the next number for this department and year_month
    $query = "SELECT last_number FROM risk_sequences WHERE department = :department AND `year_month` = :year_month FOR UPDATE";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->bindParam(':year_month', $year_month);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $next_number = $result['last_number'] + 1;
        $update_query = "UPDATE risk_sequences SET last_number = :next_number WHERE department = :department AND `year_month` = :year_month";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':next_number', $next_number);
        $update_stmt->bindParam(':department', $department);
        $update_stmt->bindParam(':year_month', $year_month);
        $update_stmt->execute();
    } else {
        $next_number = 1;
        $insert_query = "INSERT INTO risk_sequences (department, `year_month`, last_number) VALUES (:department, :year_month, :next_number)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':department', $department);
        $insert_stmt->bindParam(':year_month', $year_month);
        $insert_stmt->bindParam(':next_number', $next_number);
        $insert_stmt->execute();
    }
    
    // Get department abbreviation
    $dept_abbr = getDepartmentAbbreviation($department);
    
    // Format sequence number with leading zeros (3 digits)
    $formatted_sequence = str_pad($next_number, 3, '0', STR_PAD_LEFT);
    
    // Form the custom risk ID with abbreviation and formatted sequence number
    $custom_risk_id = $dept_abbr . '/' . $risk_year . '/' . $risk_month . '/' . $formatted_sequence;
    
    // Handle file upload (optional)
    $uploaded_file_path = null;
    if (isset($_FILES['risk_document']) && $_FILES['risk_document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/risk_documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_extension = strtolower(pathinfo($_FILES['risk_document']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        if (in_array($file_extension, $allowed_extensions)) {
            $file_name = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['risk_document']['tmp_name'], $upload_path)) {
                $uploaded_file_path = $upload_path;
            }
        }
    }
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        $query = "INSERT INTO risk_incidents (
                    custom_risk_id,
                    risk_name,
                    risk_categories, 
                    category_details, 
                    risk_description, 
                    cause_of_risk, 
                    cause_of_risk_description,
                    department_id, 
                    normalized_department,
                    department_abbreviation,
                    reported_by,
                    risk_owner_id,
                    involves_money_loss,
                    money_amount,
                    existing_or_new,
                    treatment_status,
                    risk_status,
                    date_of_risk_entry,
                    created_at, 
                    updated_at
                  ) VALUES (
                    :custom_risk_id,
                    :risk_name,
                    :risk_categories, 
                    :category_details, 
                    :risk_description, 
                    :cause_of_risk, 
                    :cause_of_risk_description,
                    :department_id, 
                    :normalized_department,
                    :department_abbreviation,
                    :reported_by,
                    :risk_owner_id,
                    :involves_money_loss,
                    :money_amount,
                    'New',
                    'Not Started',
                    'pending',
                    :date_of_risk_entry,
                    NOW(), 
                    NOW()
                  )";
        $stmt = $db->prepare($query);
        
        $risk_name = $risk_description . '...'; // Generate risk name from description
        $risk_categories_json = json_encode($risk_categories);
        $category_details_json = json_encode($category_details);
        $involves_money = isset($_POST['involves_money_loss']) ? $_POST['involves_money_loss'] == 'Yes' ? 1 : 0 : 0;
        $money_amount_val = isset($_POST['money_amount']) ? $_POST['money_amount'] : '0.00';
        
        $stmt->bindParam(':custom_risk_id', $custom_risk_id);
        $stmt->bindParam(':risk_name', $risk_name);
        $stmt->bindParam(':risk_categories', $risk_categories_json);
        $stmt->bindParam(':category_details', $category_details_json);
        $stmt->bindParam(':risk_description', $risk_description);
        $stmt->bindParam(':cause_of_risk', $cause_of_risk);
        $stmt->bindParam(':cause_of_risk_description', $cause_of_risk_description);
        $stmt->bindParam(':department_id', $department_id);
        $stmt->bindParam(':normalized_department', $department);
        $stmt->bindParam(':department_abbreviation', $dept_abbr);
        $stmt->bindParam(':reported_by', $user_id);
        $stmt->bindParam(':risk_owner_id', $user_id);
        $stmt->bindParam(':involves_money_loss', $involves_money);
        $stmt->bindParam(':money_amount', $money_amount_val);
        $stmt->bindParam(':date_of_risk_entry', $date_of_risk_entry);
        
        if ($stmt->execute()) {
            $risk_id = $db->lastInsertId();
            
            if ($uploaded_file_path) {
                $doc_query = "INSERT INTO risk_documents (
                                risk_id, 
                                section_type, 
                                original_filename, 
                                stored_filename, 
                                file_path, 
                                file_size,
                                document_content,
                                mime_type, 
                                uploaded_by, 
                                uploaded_at
                              ) VALUES (
                                :risk_id, 
                                'risk_identification', 
                                :original_filename, 
                                :stored_filename, 
                                :file_path, 
                                :file_size,
                                :document_content,
                                :mime_type, 
                                :uploaded_by, 
                                NOW()
                              )";
                $doc_stmt = $db->prepare($doc_query);
                $original_filename = $_FILES['risk_document']['name'];
                $stored_filename = basename($uploaded_file_path);
                $file_size = $_FILES['risk_document']['size'];
                $file_content = file_get_contents($uploaded_file_path);
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $uploaded_file_path);
                finfo_close($finfo);
                $doc_stmt->bindParam(':risk_id', $risk_id);
                $doc_stmt->bindParam(':original_filename', $original_filename);
                $doc_stmt->bindParam(':stored_filename', $stored_filename);
                $doc_stmt->bindParam(':file_path', $uploaded_file_path);
                $doc_stmt->bindParam(':file_size', $file_size);
                $doc_stmt->bindParam(':document_content', $file_content, PDO::PARAM_LOB);
                $doc_stmt->bindParam(':mime_type', $mime_type);
                $doc_stmt->bindParam(':uploaded_by', $user_id);
                $doc_stmt->execute();
            }
            
            // Commit transaction
            $db->commit();
            
            // Call auto-assignment function
            $assignment_result = assignRiskAutomatically($risk_id, $_SESSION['user_id'], $db);
            if ($assignment_result['success']) {
                // Store success message in session without ID
                $_SESSION['toast_message'] = "Risk reported successfully.";
                $_SESSION['toast_type'] = "success";
                header("Location: staff_dashboard.php");
                exit();
            } else {
                // Store success message in session without ID
                $_SESSION['toast_message'] = "Risk reported successfully.";
                $_SESSION['toast_type'] = "success";
                header("Location: staff_dashboard.php");
                exit();
            }
        } else {
            // Rollback transaction if active
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $_SESSION['error_message'] = "Failed to report risk. Please try again.";
        }
    } catch (PDOException $e) {
        // Rollback transaction if active
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Risk submission error: " . $e->getMessage());
    }
}
// Handle success/error messages from session
$toast_message = '';
$toast_type = '';
$error_message = '';
if (isset($_SESSION['toast_message'])) {
    $toast_message = $_SESSION['toast_message'];
    $toast_type = isset($_SESSION['toast_type']) ? $_SESSION['toast_type'] : 'success';
    
    // Clear the session variables
    unset($_SESSION['toast_message']);
    unset($_SESSION['toast_type']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
// Fixed query to join with departments table - explicitly selecting columns to avoid missing column error
$query = "SELECT 
                 r.id,
                 r.date_of_risk_entry,
                 r.existing_or_new,
                 r.to_be_reported_to_board,
                 r.risk_name,
                 r.risk_description,
                 r.cause_of_risk,
                 r.cause_of_risk_description,
               
                 r.residual_likelihood,
                 r.residual_consequence,
                 r.residual_rating,
                 r.risk_rating,
                 r.treatment_action,
                 r.controls_action_plan,
                 r.planned_completion_date,
                 r.risk_owner_id,
                 r.progress_update_report,
                 r.status,
                 r.reported_by,
                 r.created_at,
                 r.updated_at,
                 r.updated_by,
                 r.monitoring_frequency,
                 r.next_review_date,
                 r.risk_appetite,
                 r.escalation_criteria,
                 r.progress_update,
                 r.risk_status,
                 r.department_id,
                 r.normalized_department,
                 r.department_abbreviation,
                 r.collaborators,
                 r.treatment_documents,
                 r.involves_money_loss,
                 r.money_amount,
                 r.probability,
                 r.impact,
                 r.risk_level,
                 r.risk_categories,
                 r.risk_id_number,
                 r.controls_action_plans,
                 r.target_completion_date,
                 r.treatment_status,
                 r.document_path,
                 r.section_b_locked,
                 r.section_c_locked,
                 r.category_details,
                 r.custom_risk_id,
                 d.name as department_name,
                 (SELECT COUNT(*) FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification') as has_document,
                 (SELECT rd.file_path FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification' LIMIT 1) as document_file_path,
                 (SELECT rd.original_filename FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification' LIMIT 1) as document_filename,
                 (SELECT rd.mime_type FROM risk_documents rd WHERE rd.risk_id = r.id AND rd.section_type = 'risk_identification' LIMIT 1) as mime_type
          FROM risk_incidents r 
          LEFT JOIN departments d ON r.department_id = d.id
          WHERE r.reported_by = :user_id 
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Airtel Risk Management</title>
    <style>
        /* CSS styles remain unchanged */
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
            padding-top: 100px; /* Add padding for fixed header */
        }
        .dashboard {
            min-height: 100vh;
        }
        /* Header */
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
        .help-btn {
            background: white;
            color: #E60012;
            border: none;
            border-radius: 30px;
            padding: 0.7rem 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .help-btn:hover {
            background: #f8f8f8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
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
        /* Main Cards Layout */
        .main-cards-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        /* Hero Section */
        .hero {
            text-align: center;
            padding: 5rem 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 300px;
        }
        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 1.5rem 2.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        .cta-button:hover {
            background: #B8000E;
        }
        /* Stats Card */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 300px;
            position: relative;
            border-left: 6px solid #E60012;
        }
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .stat-number {
            font-size: 4.5rem;
            font-weight: 800;
            color: #E60012;
            margin-bottom: 1rem;
        }
        .stat-label {
            font-size: 1.4rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 1.5rem;
        }
        .stat-hint {
            color: #E60012;
            font-size: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        /* Reports Section */
        .reports-section {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            display: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .reports-section.show {
            display: block;
        }
        .reports-header {
            background: #E60012;
            padding: 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .reports-title {
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .hide-reports-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .hide-reports-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .reports-content {
            max-height: 400px;
            overflow-y: auto;
        }
        .risk-item {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
        }
        .risk-item:hover {
            background: #f8f9fa;
        }
        .risk-item:last-child {
            border-bottom: none;
        }
        .risk-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .risk-name {
            font-weight: 600;
            color: #333;
            flex: 1;
        }
        .view-btn {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #E60012;
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }
        .view-btn:hover {
            background: #B8000E;
        }
        .risk-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.9rem;
            color: #666;
        }
        .empty-state {
            padding: 3rem 2rem;
            text-align: center;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        .empty-state p {
            color: #666;
            margin-bottom: 1.5rem;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        /* Modal Styles - Enhanced for Professional Look */
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
            background: white;
            border-radius: 8px;
            width: 95%;
            max-width: 1200px; /* Increased width for wider form */
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: #E60012;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            margin-bottom: 1.8rem;
        }
        label {
            display: block;
            margin-bottom: 0.6rem;
            color: #333;
            font-weight: 500;
            font-size: 1rem;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.9rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        textarea {
            height: 120px;
            resize: vertical;
        }
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #B8000E;
        }
        /* File upload styles */
        .file-upload-area {
            border: 2px dashed #e1e5e9;
            border-radius: 8px;
            padding: 1.5rem; /* Increased padding */
            text-align: center;
            transition: border-color 0.3s;
            background: #f8f9fa;
        }
        .file-upload-area:hover {
            border-color: #E60012;
        }
        .file-upload-area.dragover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        .file-upload-icon {
            font-size: 2rem; /* Increased font size */
            color: #666;
            margin-bottom: 1rem;
        }
        .file-upload-text {
            color: #666;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }
        .file-upload-hint {
            font-size: 0.9rem;
            color: #999;
        }
        .file-input {
            display: none;
        }
        .file-selected {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        /* Enhanced Risk Categories Styling */
        .risk-categories-container {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            max-height: 400px;
            overflow-y: auto;
            display: grid; /* Changed to grid layout */
            grid-template-columns: repeat(2, 1fr); /* Two columns */
            gap: 15px; /* Space between items */
        }
        .category-item {
            margin-bottom: 0; /* Removed margin as grid handles spacing */
        }
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 500;
            font-size: 16px;
            margin: 0;
            padding: 15px 20px;
            border-radius: 8px;
            background: linear-gradient(135deg, #E60012, #B8000E);
            color: white;
            transition: all 0.3s ease;
            width: 100%;
            box-shadow: 0 2px 4px rgba(230, 0, 18, 0.2);
        }
        .checkbox-label:hover {
            background: linear-gradient(135deg, #B8000E, #A50010);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }
        .checkbox-label input[type="checkbox"] {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            accent-color: white;
            cursor: pointer;
        }
        /* Document Link Styles */
        .document-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.3s;
            margin-top: 0.5rem;
        }
        .document-link:hover {
            background: #B8000E;
            color: white;
            text-decoration: none;
        }
        /* Radio Button Styles */
        .radio-group {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .radio-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 12px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            background: white;
            transition: all 0.3s ease;
            min-width: 120px;
            font-weight: 500;
        }
        .radio-option:hover {
            border-color: #E60012;
            background: rgba(230, 0, 18, 0.05);
        }
        .radio-option input[type="radio"] {
            display: none;
        }
        .radio-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #ddd;
            border-radius: 50%;
            margin-right: 12px;
            position: relative;
            transition: all 0.3s ease;
        }
        .radio-option input[type="radio"]:checked + .radio-custom {
            border-color: #E60012;
            background: #E60012;
        }
        .radio-option input[type="radio"]:checked + .radio-custom::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 8px;
            height: 8px;
            background: white;
            border-radius: 50%;
        }
        .radio-option input[type="radio"]:checked ~ .radio-text {
            color: #E60012;
            font-weight: 600;
        }
        .radio-text {
            font-size: 1rem;
            color: #333;
            transition: color 0.3s ease;
        }
        /* Modal Info Display */
        .modal-info-display {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            border-left: 3px solid #E60012;
            margin-bottom: 1rem;
        }
        
        /* Toast Notification Styles - Updated */
        .toast-container {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            background: linear-gradient(135deg, #E60012, #FF6B6B);
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            transform: translateX(120%);
            transition: transform 0.4s ease-in-out;
            position: relative;
            overflow: hidden;
        }
        
        .toast.show {
            transform: translateX(0);
        }
        
        .toast-icon {
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toast-message {
            font-weight: 500;
            font-size: 16px;
            flex: 1;
        }
        
        .toast-close {
            cursor: pointer;
            font-size: 18px;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .toast-close:hover {
            opacity: 1;
        }
        
        .toast::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 4px;
            width: 100%;
            background: rgba(255, 255, 255, 0.3);
            animation: toast-progress 2s linear forwards;
        }
        
        @keyframes toast-progress {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        /* Document Actions */
        .document-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .document-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #E60012;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .document-btn:hover {
            background: #B8000E;
            color: white;
            text-decoration: none;
        }
        
        /* Help Documentation Styles */
        .help-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .help-tabs {
            display: flex;
            border-bottom: 1px solid #e1e5e9;
            margin-bottom: 1.5rem;
        }
        
        .help-tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .help-tab.active {
            color: #E60012;
            border-bottom-color: #E60012;
        }
        
        .help-tab:hover {
            color: #E60012;
        }
        
        .help-content {
            flex: 1;
            overflow-y: auto;
        }
        
        .help-section {
            display: none;
        }
        
        .help-section.active {
            display: block;
        }
        
        .help-section h3 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .help-section p {
            margin-bottom: 1rem;
            color: #555;
            line-height: 1.6;
        }
        
        .help-steps {
            margin: 2rem 0;
        }
        
        .help-step {
            display: flex;
            margin-bottom: 1.5rem;
            align-items: flex-start;
        }
        
        .step-number {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: #E60012;
            color: white;
            border-radius: 50%;
            font-weight: bold;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .step-content {
            flex: 1;
        }
        
        .step-content h4 {
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.2rem;
        }
        
        .step-content ul {
            margin-left: 1.5rem;
            margin-top: 0.5rem;
        }
        
        .step-content li {
            margin-bottom: 0.5rem;
        }
        
        .status-legend {
            margin-top: 1rem;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.9rem;
            margin-right: 0.75rem;
            font-weight: 500;
        }
        
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.assigned {
            background: #d4edda;
            color: #155724;
        }
        
        .help-action {
            margin-top: 2rem;
            text-align: center;
        }
        
        /* FAQ Styles */
        .faq-list {
            margin: 2rem 0;
        }
        
        .faq-item {
            margin-bottom: 1rem;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .faq-question {
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s;
        }
        
        .faq-question:hover {
            background: #f1f3f5;
        }
        
        .faq-question h4 {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
        }
        
        .faq-toggle {
            font-size: 1.2rem;
            color: #666;
            transition: transform 0.3s;
        }
        
        .faq-answer {
            padding: 0 1.5rem;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        
        .faq-answer.show {
            padding: 1rem 1.5rem;
            max-height: 500px;
        }
        
        .faq-answer p {
            margin: 0;
            color: #555;
            line-height: 1.6;
        }
        
        /* Step Guides Styles */
        .step-guides {
            margin: 2rem 0;
        }
        
        .step-guide {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 3px solid #E60012;
        }
        
        .step-guide h4 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }
        
        .step-guide ol {
            margin-left: 1.5rem;
        }
        
        .step-guide li {
            margin-bottom: 0.5rem;
            line-height: 1.6;
        }
        
        /* Learn More Button Styles */
        .learn-more-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #f8f9fa;
            color: #E60012;
            padding: 0.6rem 1.2rem;
            border-radius: 30px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid #e1e5e9;
        }
        
        .learn-more-btn:hover {
            background: #E60012;
            color: white;
            border-color: #E60012;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.2);
        }
        
        /* Enhanced Risk Description Styles */
        .risk-description-container {
            position: relative;
        }
        
        .risk-description-container textarea {
            min-height: 150px;
            font-family: inherit;
            line-height: 1.5;
        }
        
        .char-counter {
            position: absolute;
            bottom: 10px;
            right: 10px;
            font-size: 0.85rem;
            color: #666;
            background: rgba(255, 255, 255, 0.8);
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        .description-hint {
            margin-top: 8px;
            font-size: 0.9rem;
            color: #666;
            font-style: italic;
        }
        
        /* Custom Risk ID Display */
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
        
        /* Cause of Risk Styles - Corrected to match image exactly */
        .cause-of-risk-container {
            margin-bottom: 15px;
        }
        
        .cause-of-risk-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
            font-size: 1.1rem;
        }
        
        .cause-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .cause-option {
            position: relative;
            height: 100%;
        }
        
        .radio-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            margin: 0;
            padding: 15px 20px;
            border-radius: 8px;
            background-color: #E60012; /* Changed to solid color instead of gradient */
            color: white;
            transition: all 0.3s ease;
            width: 100%;
            height: 100%;
            box-shadow: 0 2px 4px rgba(230, 0, 18, 0.2);
            box-sizing: border-box;
        }
        
        .radio-label:hover {
            background-color: #B8000E; /* Changed to solid color instead of gradient */
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.2);
        }
        
        .radio-label input[type="radio"] {
            margin-right: 15px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: white; /* This will make the radio button white when selected */
        }
        
        .secondary-risk-container {
            margin-top: 15px;
            padding: 15px;
            background: #f0f8ff;
            border: 1px solid #b3d9ff;
            border-radius: 8px;
        }
        
        .secondary-risk-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #0066cc;
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
            .main-cards-layout {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .hero {
                padding: 3rem 2rem;
                min-height: 250px;
            }
            .stat-card {
                padding: 2.5rem 1.5rem;
                min-height: 250px;
            }
            .stat-number {
                font-size: 3.5rem;
            }
            .stat-label {
                font-size: 1.2rem;
            }
            .stat-hint {
                font-size: 0.9rem;
            }
            .reports-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            .main-content {
                padding: 1rem;
            }
            .risk-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
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
            .toast-container {
                top: 120px;
                right: 10px;
                left: 10px;
            }
            .toast {
                min-width: auto;
                width: 100%;
            }
            .document-actions {
                flex-direction: column;
            }
            /* Responsive Risk Categories */
            .risk-categories-container {
                grid-template-columns: 1fr; /* Single column on mobile */
            }
            /* Responsive Cause of Risk */
            .cause-options {
                grid-template-columns: 1fr; /* Single column on mobile */
            }
            /* Responsive Help */
            .help-tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            /* Responsive Help Button */
            .help-btn {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
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
                        <div class="user-email"><?php echo $_SESSION['email']; ?></div>
                        <div class="user-role">Staff • <?php echo $user['department']; ?></div>
                    </div>
                    <button class="help-btn" onclick="openHelpModal()" title="Help & FAQs">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Learn More
                    </button>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>
        <!-- Main Content -->
        <main class="main-content">
            <?php if (!empty($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            <!-- Main Cards Layout -->
            <div class="main-cards-layout">
                <!-- Hero Section -->
                <section class="hero">
                    <div style="text-align: center;">
                        <button class="cta-button" onclick="openReportModal()">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            Report New Risk
                        </button>
                    </div>
                </section>
                <!-- Stats Card -->
                <div class="stat-card" id="statsCard" onclick="scrollToReports()">
                    <div class="stat-number"><?php echo count($user_risks); ?></div>
                    <div class="stat-label">Risks Reported</div>
                    <div class="stat-hint">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                        Click to view details
                    </div>
                </div>
            </div>
            <!-- Reports Section -->
            <section class="reports-section" id="reportsSection">
                <div class="reports-header">
                    <h2 class="reports-title">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Your Recent Reports
                    </h2>
                    <button class="hide-reports-btn" onclick="closeReports()">Click to Hide</button>
                </div>
                <div class="reports-content">
                    <?php if (count($user_risks) > 0): ?>
                        <?php foreach (array_slice($user_risks, 0, 10) as $risk): ?>
                            <div class="risk-item">
                                <div class="risk-header">
                                    <!-- Display custom risk ID and risk categories -->
                                    <div class="risk-name">
                                        <?php 
                                        if (!empty($risk['custom_risk_id'])) {
                                            echo '<span class="risk-id-badge">' . htmlspecialchars($risk['custom_risk_id']) . '</span>';
                                        }
                                        $categories = json_decode($risk['risk_categories'], true);
                                        $categories = normalizeRiskCategories($categories);
                                        if (is_array($categories)) {
                                            echo htmlspecialchars(implode(', ', $categories));
                                        } else {
                                            echo htmlspecialchars($categories);
                                        }
                                        ?>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <?php if ($risk['risk_owner_id']): ?>
                                            <span style="background: #d4edda; color: #155724; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600;">
                                                ✅ Assigned
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #fff3cd; color: #856404; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 600;">
                                                🔄 Assigning...
                                            </span>
                                        <?php endif; ?>
                                        <button class="view-btn" onclick="viewRisk(<?php echo $risk['id']; ?>, '<?php echo htmlspecialchars($risk['custom_risk_id']); ?>', '<?php echo htmlspecialchars($risk['risk_categories']); ?>', '<?php echo htmlspecialchars($risk['risk_description']); ?>', '<?php echo htmlspecialchars($risk['cause_of_risk']); ?>', '<?php echo $risk['created_at']; ?>', <?php echo $risk['risk_owner_id'] ? 'true' : 'false'; ?>, '<?php echo htmlspecialchars($risk['document_file_path'] ?? ''); ?>', '<?php echo htmlspecialchars($risk['document_filename'] ?? ''); ?>', '<?php echo htmlspecialchars($risk['mime_type'] ?? ''); ?>', '<?php echo htmlspecialchars($risk['cause_of_risk_description'] ?? ''); ?>')">
                                            View
                                        </button>
                                    </div>
                                </div>
                                <div class="risk-meta">
                                    <span><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></span>
                                    <?php if ($risk['risk_owner_id']): ?>
                                        <span style="color:rgb(204, 11, 11);">• Risk Owner Assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <h3>No risks reported yet</h3>
                            <p>Start by reporting your first risk using the button above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    
    <!-- Toast Notification Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Risk Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report New Risk</h3>
                <button class="close" onclick="closeReportModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="riskForm" method="POST" enctype="multipart/form-data">
                    <h3 style="color: #E60012; margin-bottom: 1.5rem; border-bottom: 2px solid #E60012; padding-bottom: 0.5rem;">
                        Risk Identification Form (Staff View)
                    </h3>
                    
                    <!-- Enhanced risk categories with two-column layout -->
                    <div class="form-group">
                        <label class="form-label"><strong>1. Risk Categories</strong> <span style="color: red;">*</span> <small>(Select all that apply)</small></label>
                        <div class="risk-categories-container">
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Financial Exposure">
                                    Financial Exposure
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Decrease in market share">
                                    Decrease in market share
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Customer Experience">
                                    Customer Experience
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Compliance">
                                    Compliance
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Reputation">
                                    Reputation
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Fraud">
                                    Fraud
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Operations">
                                    Operations
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Networks">
                                    Networks
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="People">
                                    People
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="IT">
                                    IT
                                </label>
                            </div>
                            
                            <div class="category-item">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="risk_categories[]" value="Other">
                                    Other
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_description"><strong>2. Risk Description</strong> <span style="color: red;">*</span></label>
                        <div class="risk-description-container">
                            <textarea id="risk_description" name="risk_description" required placeholder="Describe the risk in detail - what exactly is the problem or potential issue? Include specific details about what could happen, who might be affected, and any relevant context." oninput="updateCharCounter()"></textarea>
                            <div class="char-counter" id="charCounter">0 / 1000</div>
                        </div>
                    
                    </div>
                    
                    <!-- Cause of Risk Section - Corrected to match image exactly -->
                    <div class="form-group">
                        <label class="form-label"><strong>3. Cause of Risk</strong> <span style="color: red;">*</span></label>
                        <div class="cause-of-risk-container">
                            <div class="cause-options">
                                <div class="cause-option">
                                    <label class="radio-label">
                                        <input type="radio" name="cause_of_risk" value="People" required>
                                        People
                                    </label>
                                </div>
                                
                                <div class="cause-option">
                                    <label class="radio-label">
                                        <input type="radio" name="cause_of_risk" value="Process" required>
                                        Process
                                    </label>
                                </div>
                                
                                <div class="cause-option">
                                    <label class="radio-label">
                                        <input type="radio" name="cause_of_risk" value="IT systems" required>
                                        IT systems
                                    </label>
                                </div>
                                
                                <div class="cause-option">
                                    <label class="radio-label">
                                        <input type="radio" name="cause_of_risk" value="External environment" required>
                                        External environment
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group" style="margin-top: 15px;">
                            <label for="cause_of_risk_description">Cause of Risk Description</label>
                            <textarea id="cause_of_risk_description" name="cause_of_risk_description" class="form-control" placeholder="Provide more details about the cause of this risk"></textarea>
                        </div>
                    </div>
                    
                    <!-- Enhanced money loss field with better styling -->
                    <div class="form-group">
                        <label class="form-label"><strong>4. Does your risk involves loss of money?</strong> <span style="color: red;">*</span></label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="involves_money_loss" value="Yes" onchange="toggleMoneyAmount()" required>
                                <span class="radio-custom"></span>
                                <span class="radio-text">Yes</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="involves_money_loss" value="No" onchange="toggleMoneyAmount()" required>
                                <span class="radio-custom"></span>
                                <span class="radio-text">No</span>
                            </label>
                        </div>
                        <div id="money_amount_field" style="display: none; margin-top: 15px;">
                            <label for="money_amount"><strong>Amount</strong></label>
                            <input type="number" id="money_amount" name="money_amount" placeholder="Enter amount in your local currency">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_document"><strong>5. Supporting Document</strong> (Optional)</label>
                        <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('risk_document').click()">
                            <div class="file-upload-icon">📄</div>
                            <div class="file-upload-text">Click to upload a document</div>
                            <div class="file-upload-hint">Supports: PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)</div>
                        </div>
                        <input type="file" id="risk_document" name="risk_document" class="file-input" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
                    </div>
                    
                    <div style="display: flex; justify-content: center; margin-top: 2rem;">
                        <button type="submit" name="submit_risk" class="btn" style="width: auto; padding: 0.9rem 3rem;">
                            Submit Risk Identification
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Help Documentation Modal -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Help Center & FAQs</h3>
                <button class="close" onclick="closeHelpModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="help-container">
                    <!-- Help Navigation Tabs -->
                    <div class="help-tabs">
                        <button class="help-tab active" onclick="showHelpTab('getting-started')">Getting Started</button>
                        <button class="help-tab" onclick="showHelpTab('step-guides')">Step-by-Step Guides</button>
                        <button class="help-tab" onclick="showHelpTab('faq')">FAQ</button>
                    </div>
                    
                    <!-- Help Content Sections -->
                    <div class="help-content">
                        <!-- Getting Started Section -->
                        <div id="getting-started" class="help-section active">
                            <h3>Welcome to Airtel Risk Management System</h3>
                            <p>This guide will help you understand how to use the Risk Management System effectively.</p>
                            
                            <div class="help-steps">
                                <div class="help-step">
                                    <div class="step-number">1</div>
                                    <div class="step-content">
                                        <h4>Understanding Risk Management</h4>
                                        <p>Risk management is the process of identifying, assessing, and controlling threats to an organization's capital and earnings.</p>
                                        <p>In this system, you can report risks, track their status, and ensure they are properly addressed.</p>
                                    </div>
                                </div>
                                
                                <div class="help-step">
                                    <div class="step-number">2</div>
                                    <div class="step-content">
                                        <h4>Reporting a Risk</h4>
                                        <p>Click the "Report New Risk" button to open the risk reporting form. Fill in all required fields:</p>
                                        <ul>
                                            <li>Select appropriate risk categories</li>
                                            <li>Provide a detailed description of the risk</li>
                                            <li>Explain the cause of the risk</li>
                                            <li>Indicate if the risk involves financial loss</li>
                                            <li>Attach any supporting documents if available</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="help-step">
                                    <div class="step-number">3</div>
                                    <div class="step-content">
                                        <h4>Tracking Your Reports</h4>
                                        <p>After submitting a risk, you can track its status in the "Your Recent Reports" section. The system will automatically assign a risk owner who will assess and address the risk.</p>
                                    </div>
                                </div>
                                
                                <div class="help-step">
                                    <div class="step-number">4</div>
                                    <div class="step-content">
                                        <h4>Understanding Risk Status</h4>
                                        <div class="status-legend">
                                            <div class="status-item">
                                                <span class="status-badge pending">🔄 Assigning...</span>
                                                <p>Risk is being assigned to an appropriate owner</p>
                                            </div>
                                            <div class="status-item">
                                                <span class="status-badge assigned">✅ Assigned</span>
                                                <p>Risk owner has been assigned and is working on the risk</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="help-action">
                                <a href="#" class="learn-more-btn">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Learn More About Risk Management
                                </a>
                            </div>
                        </div>
                        
                        <!-- Step-by-Step Guides Section -->
                        <div id="step-guides" class="help-section">
                            <h3>Step-by-Step Guides</h3>
                            <p>Follow these detailed guides to navigate through the risk management process.</p>
                            
                            <div class="step-guides">
                                <div class="step-guide">
                                    <h4>Step 1: Accessing the Risk Reporting Form</h4>
                                    <ol>
                                        <li>Log in to the Airtel Risk Management System</li>
                                        <li>Click on the "Report New Risk" button on the dashboard</li>
                                        <li>The risk reporting form will open in a modal window</li>
                                    </ol>
                                </div>
                                
                                <div class="step-guide">
                                    <h4>Step 2: Filling the Risk Reporting Form</h4>
                                    <ol>
                                        <li>Select all applicable risk categories</li>
                                        <li>Provide a detailed description of the risk</li>
                                        <li>Explain the cause of the risk</li>
                                        <li>Indicate if the risk involves financial loss</li>
                                        <li>Attach any supporting documents if available</li>
                                        <li>Click "Submit Risk Identification" to complete the report</li>
                                    </ol>
                                </div>
                                
                                <div class="step-guide">
                                    <h4>Step 3: Tracking Your Risk Report</h4>
                                    <ol>
                                        <li>Click on the "Risks Reported" card on the dashboard</li>
                                        <li>View the list of your reported risks</li>
                                        <li>Click "View" to see details of a specific risk</li>
                                        <li>Check the assignment status to see if a risk owner has been assigned</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        
                        <!-- FAQ Section -->
                        <div id="faq" class="help-section">
                            <h3>Frequently Asked Questions</h3>
                            <div class="faq-list">
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <h4>What is considered a risk?</h4>
                                        <span class="faq-toggle">+</span>
                                    </div>
                                    <div class="faq-answer">
                                        <p>A risk is any event or circumstance that could have a negative impact on the organization's objectives. This includes financial risks, operational risks, compliance risks, reputational risks, and more. If you identify something that could potentially harm the organization, it should be reported as a risk.</p>
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <h4>How do I know which risk category to select?</h4>
                                        <span class="faq-toggle">+</span>
                                    </div>
                                    <div class="faq-answer">
                                        <p>Select all categories that apply to your risk. For example, if the risk involves financial loss and affects customer experience, select both "Financial Exposure" and "Customer Experience". If you're unsure, select the category that best represents the primary impact of the risk.</p>
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <h4>What happens after I report a risk?</h4>
                                        <span class="faq-toggle">+</span>
                                    </div>
                                    <div class="faq-answer">
                                        <p>After you report a risk, the system automatically assigns it to a qualified risk owner based on the risk category and department. The risk owner will then assess the risk, determine its severity, and develop a treatment plan. You can track the status of your risk in the "Your Recent Reports" section.</p>
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <h4>Can I report a risk anonymously?</h4>
                                        <span class="faq-toggle">+</span>
                                    </div>
                                    <div class="faq-answer">
                                        <p>Currently, all risk reports are linked to your user account. This allows the risk owner to follow up with you if additional information is needed. If you have concerns about reporting a particular risk, please contact the compliance team for guidance.</p>
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <h4>What types of documents can I attach?</h4>
                                        <span class="faq-toggle">+</span>
                                    </div>
                                    <div class="faq-answer">
                                        <p>You can attach PDF, DOC, DOCX, TXT, JPG, and PNG files. The maximum file size is 10MB. Supporting documents can include evidence of the risk, relevant policies, or any other information that helps explain the risk.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Risk Details Modal -->
    <div id="riskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Risk Details</h3>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Risk ID:</label>
                    <div class="modal-info-display" id="modalRiskId"></div>
                </div>
                <div class="form-group">
                    <label>Risk Categories:</label>
                    <div class="modal-info-display" id="modalRiskCategory"></div>
                </div>
                <div class="form-group">
                    <label>Risk Description:</label>
                    <div class="modal-info-display" id="modalRiskDescription"></div>
                </div>
                <div class="form-group">
                    <label>Cause of Risk:</label>
                    <div class="modal-info-display" id="modalCauseOfRisk"></div>
                </div>
                <div class="form-group">
                    <label>Cause of Risk Description:</label>
                    <div class="modal-info-display" id="modalCauseOfRiskDescription"></div>
                </div>
                <div class="form-group">
                    <label>Date Submitted:</label>
                    <div class="modal-info-display" id="modalDateSubmitted"></div>
                </div>
                <div class="form-group">
                    <label>Assignment Status:</label>
                    <div class="modal-info-display" id="modalAssignmentStatus"></div>
                </div>
                <div class="form-group" id="documentSection" style="display: none;">
                    <label>Supporting Document:</label>
                    <div class="modal-info-display">
                        <div class="document-actions">
                            <a href="#" id="documentViewLink" class="document-btn" target="_blank">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                View Document
                            </a>
                            <a href="#" id="documentDownloadLink" class="document-btn" download>
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Download Document
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statsCard = document.getElementById('statsCard');
            const reportsSection = document.getElementById('reportsSection');
            // Hide reports section by default - user must click to view
            if (reportsSection) {
                reportsSection.style.display = 'none';
                reportsSection.classList.remove('show');
            }
            if (statsCard && <?php echo count($user_risks); ?> > 0) {
                statsCard.addEventListener('click', function() {
                    toggleReports();
                });
            }
            
            // Show toast notification if toast message exists in session
            <?php if (!empty($toast_message)): ?>
                showToast("<?php echo addslashes($toast_message); ?>", 2000);
            <?php endif; ?>
        });
        
        function handleFileSelect(input) {
            const fileUploadArea = document.getElementById('fileUploadArea');
            const file = input.files[0];
            if (file) {
                // Check file size (10MB limit)
                if (file.size > 10 * 1024 * 1024) {
                    alert('File size must be less than 10MB');
                    input.value = '';
                    return;
                }
                fileUploadArea.classList.add('file-selected');
                fileUploadArea.innerHTML = `
                    <div class="file-upload-icon">✅</div>
                    <div class="file-upload-text">File selected: ${file.name}</div>
                    <div class="file-upload-hint">Click to change file</div>
                `;
            } else {
                fileUploadArea.classList.remove('file-selected');
                fileUploadArea.innerHTML = `
                    <div class="file-upload-icon">📄</div>
                    <div class="file-upload-text">Click to upload a document</div>
                    <div class="file-upload-hint">Supports: PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)</div>
                `;
            }
        }
        
        // Drag and drop functionality
        document.addEventListener('DOMContentLoaded', function() {
            const fileUploadArea = document.getElementById('fileUploadArea');
            const fileInput = document.getElementById('risk_document');
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, preventDefaults, false);
            });
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            ['dragenter', 'dragover'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, highlight, false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                fileUploadArea.addEventListener(eventName, unhighlight, false);
            });
            function highlight(e) {
                fileUploadArea.classList.add('dragover');
            }
            function unhighlight(e) {
                fileUploadArea.classList.remove('dragover');
            }
            fileUploadArea.addEventListener('drop', handleDrop, false);
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFileSelect(fileInput);
                }
            }
        });
        
        function toggleReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection.classList.contains('show')) {
                closeReports();
            } else {
                showReports();
            }
        }
        
        function showReports() {
            const reportsSection = document.getElementById('reportsSection');
            reportsSection.style.display = 'block';
            setTimeout(() => reportsSection.classList.add('show'), 10);
        }
        
        function closeReports() {
            const reportsSection = document.getElementById('reportsSection');
            reportsSection.classList.remove('show');
            setTimeout(() => reportsSection.style.display = 'none', 300);
        }
        
        function openReportModal() {
            document.getElementById('reportModal').classList.add('show');
        }
        
        function closeReportModal() {
            document.getElementById('reportModal').classList.remove('show');
        }
        
        function openHelpModal() {
            document.getElementById('helpModal').classList.add('show');
        }
        
        function closeHelpModal() {
            document.getElementById('helpModal').classList.remove('show');
        }
        
        function showHelpTab(tabName) {
            // Hide all sections
            const sections = document.querySelectorAll('.help-section');
            sections.forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.help-tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const toggle = element.querySelector('.faq-toggle');
            
            answer.classList.toggle('show');
            
            if (answer.classList.contains('show')) {
                toggle.textContent = '-';
            } else {
                toggle.textContent = '+';
            }
        }
        
        function viewRisk(id, riskId, categories, description, cause, date, isAssigned, documentPath, documentFilename, mimeType, causeDescription) {
            // Set the custom risk ID
            document.getElementById('modalRiskId').textContent = riskId || 'Not assigned';
            
            // Parse and display categories properly
            try {
                let parsedCategories = JSON.parse(categories);
                if (Array.isArray(parsedCategories)) {
                    // Normalize categories to replace I&T with IT
                    parsedCategories = parsedCategories.map(cat => cat.replace(/I&T/g, 'IT'));
                    document.getElementById('modalRiskCategory').textContent = parsedCategories.join(', ');
                } else {
                    // Normalize single category
                    document.getElementById('modalRiskCategory').textContent = categories.replace(/I&T/g, 'IT');
                }
            } catch (e) {
                // Normalize if JSON parsing fails
                document.getElementById('modalRiskCategory').textContent = categories.replace(/I&T/g, 'IT');
            }
            
            document.getElementById('modalRiskDescription').textContent = description;
            document.getElementById('modalCauseOfRisk').textContent = cause.replace(/I&T/g, 'IT');
            
            // Display cause of risk description if available
            const causeDescElement = document.getElementById('modalCauseOfRiskDescription');
            if (causeDescription && causeDescription.trim() !== '') {
                causeDescElement.textContent = causeDescription.replace(/I&T/g, 'IT');
                causeDescElement.parentElement.style.display = 'block';
            } else {
                causeDescElement.parentElement.style.display = 'none';
            }
            
            const dateObj = new Date(date);
            document.getElementById('modalDateSubmitted').textContent = dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Assignment status
            const assignmentStatus = document.getElementById('modalAssignmentStatus');
            if (assignmentStatus) {
                if (isAssigned) {
                    assignmentStatus.innerHTML = '<span style="color: #28a745; font-weight: 600;">✅ Assigned to Risk Owner</span><br><small style="color: #666;">Risk owner is completing the full assessment and treatment plans.</small>';
                } else {
                    assignmentStatus.innerHTML = '<span style="color: #ffc107; font-weight: 600;">🔄 Assignment in Progress</span><br><small style="color: #666;">System is assigning this risk to a qualified risk owner.</small>';
                }
            }
            
            const documentSection = document.getElementById('documentSection');
            const documentViewLink = document.getElementById('documentViewLink');
            const documentDownloadLink = document.getElementById('documentDownloadLink');
            
            if (documentPath && documentPath.trim() !== '') {
                documentSection.style.display = 'block';
                
                // Set up view link
                documentViewLink.href = documentPath;
                if (mimeType && mimeType.startsWith('image/')) {
                    // For images, open in a new tab
                    documentViewLink.target = '_blank';
                } else {
                    // For other files, let the browser decide how to handle
                    documentViewLink.target = '_blank';
                }
                
                // Set up download link with proper attributes
                documentDownloadLink.href = documentPath;
                documentDownloadLink.download = documentFilename || documentPath.split('/').pop() || 'document';
                
                // Use original filename if available for display
                const filename = documentFilename && documentFilename.trim() !== '' ? documentFilename : (documentPath.split('/').pop() || 'Document');
            } else {
                documentSection.style.display = 'none';
            }
            
            document.getElementById('riskModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('riskModal').classList.remove('show');
        }
        
        function scrollToReports() {
            const reportsSection = document.getElementById('reportsSection');
            if (reportsSection) {
                // Show reports if hidden
                if (!reportsSection.classList.contains('show')) {
                    showReports();
                }
                // Smooth scroll to reports section
                setTimeout(() => {
                    reportsSection.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }, 100);
            }
        }
        
        function toggleMoneyAmount() {
            const yesRadio = document.querySelector('input[name="involves_money_loss"][value="Yes"]');
            const moneyField = document.getElementById('money_amount_field');
            const moneyInput = document.getElementById('money_amount');
            
            if (yesRadio.checked) {
                moneyField.style.display = 'block';
                moneyInput.required = true;
            } else {
                moneyField.style.display = 'none';
                moneyInput.required = false;
                moneyInput.value = '';
            }
        }
        
        function updateCharCounter() {
            const textarea = document.getElementById('risk_description');
            const counter = document.getElementById('charCounter');
            const maxLength = 1000;
            const currentLength = textarea.value.length;
            
            counter.textContent = `${currentLength} / ${maxLength}`;
            
            if (currentLength > maxLength) {
                counter.style.color = '#E60012';
                textarea.value = textarea.value.substring(0, maxLength);
            } else {
                counter.style.color = '#666';
            }
        }
        
        // Toast notification function
        function showToast(message, duration = 2000) {
            const toastContainer = document.getElementById('toastContainer');
            
            // Create toast element
            const toast = document.createElement('div');
            toast.className = 'toast';
            
            // Create toast content
            toast.innerHTML = `
                <div class="toast-icon">✅</div>
                <div class="toast-message">${message}</div>
                <div class="toast-close" onclick="hideToast(this)">×</div>
            `;
            
            // Add toast to container
            toastContainer.appendChild(toast);
            
            // Show toast with animation
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Auto hide after duration
            const hideTimeout = setTimeout(() => {
                hideToast(toast.querySelector('.toast-close'));
            }, duration);
            
            // Store timeout ID for potential manual close
            toast.setAttribute('data-timeout', hideTimeout);
        }
        
        function hideToast(closeButton) {
            const toast = closeButton.parentElement;
            const timeoutId = toast.getAttribute('data-timeout');
            
            // Clear timeout if it exists
            if (timeoutId) {
                clearTimeout(parseInt(timeoutId));
            }
            
            // Hide toast
            toast.classList.remove('show');
            
            // Remove from DOM after animation completes
            setTimeout(() => {
                toast.remove();
            }, 400);
        }
    </script>
</body>
</html>
