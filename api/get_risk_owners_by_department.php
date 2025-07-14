<?php
header('Content-Type: application/json'); // Set content type to JSON
include_once '../config/database.php'; // Adjust path as necessary

$database = new Database();
$db = $database->getConnection();

// Get the department from the GET request and trim whitespace
$department = isset($_GET['department']) ? trim($_GET['department']) : '';

$risk_owners = [];

if (!empty($department)) {
    try {
        // Query to select approved risk owners in the specified department
        // Changed status from 'active' to 'approved'
        $query = "SELECT id, full_name, department FROM users WHERE role = 'risk_owner' AND status = 'approved' AND LOWER(department) = LOWER(:department) ORDER BY full_name";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':department', $department);
        $stmt->execute();
        $risk_owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log the error for debugging, but don't expose sensitive info to the client
        error_log("Error fetching risk owners by department: " . $e->getMessage());
        echo json_encode(['error' => 'Database error fetching risk owners.']);
        exit();
    }
}

// Return the risk owners as JSON
echo json_encode($risk_owners);
?>
