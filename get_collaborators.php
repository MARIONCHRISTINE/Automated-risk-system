<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';

header('Content-Type: application/json');

$risk_id = isset($_GET['risk_id']) ? (int)$_GET['risk_id'] : 0;

if (!$risk_id) {
    echo json_encode([]);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get collaborators for this risk
$query = "SELECT DISTINCT u.id, u.full_name, u.email, u.role 
          FROM risk_treatments rt
          JOIN users u ON rt.assigned_to = u.id
          WHERE rt.risk_id = :risk_id AND rt.assigned_to != :owner_id
          ORDER BY u.full_name";

$stmt = $db->prepare($query);
$stmt->bindParam(':risk_id', $risk_id);
$stmt->bindParam(':owner_id', $_SESSION['user_id']);
$stmt->execute();

$collaborators = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($collaborators);
?>
