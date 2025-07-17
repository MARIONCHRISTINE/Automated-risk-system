<?php
include_once 'includes/auth.php';
requireRole('admin'); // Only admins can export audit logs
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Fetch audit logs from the database (using activity_logs table)
$query = "SELECT
            sal.timestamp,
            sal.user,
            sal.action,
            sal.details,
            sal.ip_address
          FROM
            system_audit_logs sal
          ORDER BY
            sal.timestamp DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i-s') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV header row
fputcsv($output, ['Timestamp', 'User', 'Action', 'Details', 'IP Address']);

// Write data rows
foreach ($audit_logs as $log) {
    // Ensure the order matches the header and include IP/User Agent
    fputcsv($output, [
        $log['timestamp'],
        $log['user'],
        $log['action'],
        $log['details'],
        $log['ip_address']
    ]);
}

// Close output stream
fclose($output);
exit();
?>
