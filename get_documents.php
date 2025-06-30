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

// Get documents for this risk
$query = "SELECT td.*, u.full_name as uploaded_by_name
          FROM treatment_documents td
          LEFT JOIN users u ON td.uploaded_by = u.id
          WHERE td.risk_id = :risk_id
          ORDER BY td.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':risk_id', $risk_id);
$stmt->execute();

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($documents);
?>
