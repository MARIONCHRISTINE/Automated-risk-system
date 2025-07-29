<?php
include_once 'includes/auth.php';
requireAnyRole(['risk_owner', 'admin']);
include_once 'config/database.php';
include_once 'includes/audit_logger.php';
include_once 'includes/suspicious_activity_detector.php';

$database = new Database();
$db = $database->getConnection();
$auditLogger = new AuditLogger($db);
$suspiciousDetector = new SuspiciousActivityDetector($db);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_audit_logs':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $logs = $auditLogger->getAuditLogs($limit, $offset, $userId);
            echo json_encode(['success' => true, 'logs' => $logs]);
            exit();
            
        case 'get_login_history':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $userId = $_POST['user_id'] ?? null;
            
            $history = $auditLogger->getLoginHistory($limit, $offset, $userId);
            echo json_encode(['success' => true, 'history' => $history]);
            exit();
            
        case 'get_suspicious_activities':
            $limit = (int)($_POST['limit'] ?? 50);
            $offset = (int)($_POST['offset'] ?? 0);
            $severity = $_POST['severity'] ?? null;
            
            $activities = $suspiciousDetector->getSuspiciousActivities($limit, $offset, $severity);
            echo json_encode(['success' => true, 'activities' => $activities]);
            exit();
            
        case 'resolve_suspicious_activity':
            $activityId = (int)$_POST['activity_id'];
            $result = $suspiciousDetector->resolveSuspiciousActivity($activityId, $_SESSION['user_id']);
            echo json_encode(['success' => $result]);
            exit();
            
        case 'export_audit_logs':
            $logs = $auditLogger->getAuditLogs(1000, 0);
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i-s') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'ID', 'User', 'Email', 'Role', 'Action', 'Table', 'Record ID', 
                'IP Address', 'User Agent', 'Created At'
            ]);
            
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    $log['full_name'] ?? 'Unknown',
                    $log['email'] ?? 'Unknown',
                    $log['role'] ?? 'Unknown',
                    $log['action'],
                    $log['table_name'] ?? '',
                    $log['record_id'] ?? '',
                    $log['ip_address'] ?? '',
                    $log['user_agent'] ?? '',
                    $log['created_at']
                ]);
            }
            
            fclose($output);
            exit();
    }
}

$user = getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit & Security Dashboard - Airtel Risk Management</title>
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
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .header h1 {
            color: #E60012;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .btn-export {
            background: #17a2b8;
            color: white;
        }

        .btn-export:hover {
            background: #138496;
        }

        .btn-suspicious {
            background: #ffc107;
            color: #212529;
        }

        .btn-suspicious:hover {
            background: #e0a800;
        }

        .btn-history {
            background: #007bff;
            color: white;
        }

        .btn-history:hover {
            background: #0056b3;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        .table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-critical {
            background: #721c24;
            color: white;
        }

        .badge-high {
            background: #dc3545;
            color: white;
        }

        .badge-medium {
            background: #ffc107;
            color: #212529;
        }

        .badge-low {
            background: #28a745;
            color: white;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #ddd;
        }

        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }

        .resolve-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
        }

        .resolve-btn:hover {
            background: #218838;
        }

        .resolve-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
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
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 1000px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            background: #E60012;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }

        .modal-body {
            padding: 1.5rem;
        }

        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
            
            .filters {
                flex-direction: column;
            }
            
            .table {
                font-size: 0.8rem;
            }
            
            .table th,
            .table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-shield-alt"></i> Audit & Security Dashboard</h1>
            <p>Monitor system activities, track login history, and identify suspicious behaviors</p>
        </div>

        <div class="action-buttons">
            <button class="btn btn-export" onclick="exportAuditLogs()">
                <i class="fas fa-download"></i> Export Audit Logs
            </button>
            <button class="btn btn-suspicious" onclick="showSuspiciousActivities()">
                <i class="fas fa-exclamation-triangle"></i> Highlight Suspicious Activities
            </button>
            <button class="btn btn-history" onclick="showLoginHistory()">
                <i class="fas fa-history"></i> View Login History / IP Tracking
            </button>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Recent Audit Logs</h3>
                    <button class="btn btn-export" onclick="loadAuditLogs()">
                        <i class="fas fa-refresh"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <div class="loading" id="auditLoading">Loading audit logs...</div>
                        <table class="table" id="auditTable" style="display: none;">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>IP Address</th>
                                    <th>Date/Time</th>
                                </tr>
                            </thead>
                            <tbody id="auditTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Suspicious Activities Modal -->
    <div id="suspiciousModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Suspicious Activities</h3>
                <button class="close" onclick="closeSuspiciousModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="filters">
                    <select id="severityFilter" class="filter-select" onchange="loadSuspiciousActivities()">
                        <option value="">All Severities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                <div class="table-container">
                    <div class="loading" id="suspiciousLoading">Loading suspicious activities...</div>
                    <table class="table" id="suspiciousTable" style="display: none;">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Activity Type</th>
                                <th>Description</th>
                                <th>Severity</th>
                                <th>Date/Time</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="suspiciousTableBody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Login History Modal -->
    <div id="loginModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Login History & IP Tracking</h3>
                <button class="close" onclick="closeLoginModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="table-container">
                    <div class="loading" id="loginLoading">Loading login history...</div>
                    <table class="table" id="loginTable" style="display: none;">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>IP Address</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Date/Time</th>
                            </tr>
                        </thead>
                        <tbody id="loginTableBody">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load audit logs on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAuditLogs();
        });

        function loadAuditLogs() {
            const loading = document.getElementById('auditLoading');
            const table = document.getElementById('auditTable');
            const tbody = document.getElementById('auditTableBody');

            loading.style.display = 'block';
            table.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'get_audit_logs');
            formData.append('limit', '50');

            fetch('audit_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                table.style.display = 'table';

                if (data.success && data.logs.length > 0) {
                    tbody.innerHTML = '';
                    data.logs.forEach(log => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>
                                <strong>${log.full_name || 'Unknown'}</strong><br>
                                <small class="text-muted">${log.role || 'Unknown'}</small>
                            </td>
                            <td><span class="badge badge-info">${log.action}</span></td>
                            <td>${log.table_name || '-'}</td>
                            <td>${log.ip_address || '-'}</td>
                            <td>${formatDateTime(log.created_at)}</td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center">No audit logs found</td></tr>';
                }
            })
            .catch(error => {
                console.error('Error loading audit logs:', error);
                loading.style.display = 'none';
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading audit logs</td></tr>';
                table.style.display = 'table';
            });
        }

        function showSuspiciousActivities() {
            document.getElementById('suspiciousModal').classList.add('show');
            loadSuspiciousActivities();
        }

        function closeSuspiciousModal() {
            document.getElementById('suspiciousModal').classList.remove('show');
        }

        function loadSuspiciousActivities() {
            const loading = document.getElementById('suspiciousLoading');
            const table = document.getElementById('suspiciousTable');
            const tbody = document.getElementById('suspiciousTableBody');
            const severityFilter = document.getElementById('severityFilter').value;

            loading.style.display = 'block';
            table.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'get_suspicious_activities');
            formData.append('limit', '50');
            if (severityFilter) {
                formData.append('severity', severityFilter);
            }

            fetch('audit_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                table.style.display = 'table';

                if (data.success && data.activities.length > 0) {
                    tbody.innerHTML = '';
                    data.activities.forEach(activity => {
                        const row = document.createElement('tr');
                        const isResolved = activity.is_resolved == 1;
                        
                        row.innerHTML = `
                            <td>
                                <strong>${activity.full_name || 'Unknown'}</strong><br>
                                <small class="text-muted">${activity.email || 'Unknown'}</small>
                            </td>
                            <td>${activity.activity_type.replace(/_/g, ' ').toUpperCase()}</td>
                            <td>${activity.description}</td>
                            <td><span class="badge badge-${activity.severity}">${activity.severity.toUpperCase()}</span></td>
                            <td>${formatDateTime(activity.created_at)}</td>
                            <td>
                                ${isResolved ? 
                                    `<span class="badge badge-success">Resolved</span><br><small>by ${activity.resolved_by_name}</small>` : 
                                    '<span class="badge badge-warning">Open</span>'
                                }
                            </td>
                            <td>
                                ${!isResolved ? 
                                    `<button class="resolve-btn" onclick="resolveSuspiciousActivity(${activity.id})">Resolve</button>` : 
                                    '-'
                                }
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center">No suspicious activities found</td></tr>';
                }
            })
            .catch(error => {
                console.error('Error loading suspicious activities:', error);
                loading.style.display = 'none';
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading suspicious activities</td></tr>';
                table.style.display = 'table';
            });
        }

        function resolveSuspiciousActivity(activityId) {
            if (!confirm('Mark this suspicious activity as resolved?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'resolve_suspicious_activity');
            formData.append('activity_id', activityId);

            fetch('audit_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSuspiciousActivities(); // Reload the table
                } else {
                    alert('Error resolving suspicious activity');
                }
            })
            .catch(error => {
                console.error('Error resolving suspicious activity:', error);
                alert('Error resolving suspicious activity');
            });
        }

        function showLoginHistory() {
            document.getElementById('loginModal').classList.add('show');
            loadLoginHistory();
        }

        function closeLoginModal() {
            document.getElementById('loginModal').classList.remove('show');
        }

        function loadLoginHistory() {
            const loading = document.getElementById('loginLoading');
            const table = document.getElementById('loginTable');
            const tbody = document.getElementById('loginTableBody');

            loading.style.display = 'block';
            table.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'get_login_history');
            formData.append('limit', '100');

            fetch('audit_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                table.style.display = 'table';

                if (data.success && data.history.length > 0) {
                    tbody.innerHTML = '';
                    data.history.forEach(login => {
                        const row = document.createElement('tr');
                        const locationInfo = login.location_info ? JSON.parse(login.location_info) : {};
                        
                        row.innerHTML = `
                            <td>
                                <strong>${login.full_name || 'Unknown'}</strong><br>
                                <small class="text-muted">${login.role || 'Unknown'}</small>
                            </td>
                            <td>${login.email}</td>
                            <td>${login.ip_address}</td>
                            <td>
                                <span class="badge badge-${login.login_status === 'success' ? 'success' : 'danger'}">
                                    ${login.login_status.toUpperCase()}
                                </span>
                                ${login.failure_reason ? `<br><small>${login.failure_reason}</small>` : ''}
                            </td>
                            <td>
                                ${locationInfo.country || 'Unknown'}<br>
                                <small>${locationInfo.city || 'Unknown'}</small>
                            </td>
                            <td>${formatDateTime(login.created_at)}</td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center">No login history found</td></tr>';
                }
            })
            .catch(error => {
                console.error('Error loading login history:', error);
                loading.style.display = 'none';
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading login history</td></tr>';
                table.style.display = 'table';
            });
        }

        function exportAuditLogs() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'audit_dashboard.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'export_audit_logs';
            
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString();
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const suspiciousModal = document.getElementById('suspiciousModal');
            const loginModal = document.getElementById('loginModal');
            
            if (event.target === suspiciousModal) {
                closeSuspiciousModal();
            }
            if (event.target === loginModal) {
                closeLoginModal();
            }
        });
    </script>
</body>
</html>
