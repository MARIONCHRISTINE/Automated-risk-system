<?php
include_once 'includes/auth.php';
requireRole('admin');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle user approval
if ($_POST && isset($_POST['approve_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'approved' WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User approved successfully!";
    }
}

// Handle user suspension
if ($_POST && isset($_POST['suspend_user'])) {
    $user_id = $_POST['user_id'];
    $query = "UPDATE users SET status = 'suspended' WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User suspended successfully!";
    }
}

// Handle user deletion
if ($_POST && isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    $query = "DELETE FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    if ($stmt->execute()) {
        $success_message = "User deleted successfully!";
    }
}

// Get all users
$users_query = "SELECT * FROM users ORDER BY created_at DESC";
$users_stmt = $db->prepare($users_query);
$users_stmt->execute();
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending users
$pending_users = array_filter($all_users, function($user) {
    return $user['status'] === 'pending';
});

// Get system statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM users) as total_users,
    (SELECT COUNT(*) FROM users WHERE status = 'pending') as pending_users,
    (SELECT COUNT(*) FROM risk_incidents) as total_risks,
    (SELECT COUNT(*) FROM risk_incidents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as recent_risks";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$system_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .nav-tabs {
            background: white;
            padding: 0 2rem;
            border-bottom: 1px solid #ddd;
        }
        
        .nav-tabs button {
            background: none;
            border: none;
            padding: 1rem 2rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-size: 1rem;
        }
        
        .nav-tabs button.active {
            border-bottom-color: #667eea;
            color: #667eea;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .stats-grid {
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
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .user-table th, .user-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .user-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-suspended { background: #f8d7da; color: #721c24; }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            font-size: 0.9rem;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .bulk-upload {
            border: 2px dashed #ddd;
            padding: 2rem;
            text-align: center;
            border-radius: 10px;
            margin: 1rem 0;
        }
        
        .bulk-upload:hover {
            border-color: #667eea;
            background: #f8f9ff;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['full_name']; ?></span>
            <a href="logout.php" class="logout-btn" style="background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 5px; text-decoration: none; margin-left: 1rem;">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <button class="active" onclick="showTab('overview')">Overview</button>
        <button onclick="showTab('users')">User Management</button>
        <button onclick="showTab('database')">Database Management</button>
        <button onclick="showTab('bulk')">Bulk Upload</button>
    </div>
    
    <div class="container">
        <?php if (isset($success_message)): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <div id="overview-tab" class="tab-content">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" style="color: #667eea;"><?php echo $system_stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ffc107;"><?php echo $system_stats['pending_users']; ?></div>
                    <div class="stat-label">Pending Approvals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #28a745;"><?php echo $system_stats['total_risks']; ?></div>
                    <div class="stat-label">Total Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #17a2b8;"><?php echo $system_stats['recent_risks']; ?></div>
                    <div class="stat-label">Recent Risks (30 days)</div>
                </div>
            </div>
            
            <?php if (count($pending_users) > 0): ?>
            <div class="card">
                <h2>Pending User Approvals</h2>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Registration Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo ucfirst($user['role']); ?></td>
                                <td><?php echo htmlspecialchars($user['department']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="approve_user" class="btn btn-success">Approve</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <div id="users-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>All Users</h2>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $user): ?>
                            <?php if ($user['id'] != $_SESSION['user_id']): // Don't show current admin ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo ucfirst($user['role']); ?></td>
                                    <td><?php echo htmlspecialchars($user['department']); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['status'] === 'pending'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="approve_user" class="btn btn-success">Approve</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($user['status'] === 'approved'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="suspend_user" class="btn btn-warning">Suspend</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="database-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Database Management</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                    <div>
                        <h3>Backup Database</h3>
                        <p>Create a backup of the entire risk management database.</p>
                        <a href="backup_database.php" class="btn">Create Backup</a>
                    </div>
                    <div>
                        <h3>System Logs</h3>
                        <p>View system activity and error logs.</p>
                        <a href="view_logs.php" class="btn">View Logs</a>
                    </div>
                    <div>
                        <h3>Data Cleanup</h3>
                        <p>Clean up old data and optimize database performance.</p>
                        <a href="cleanup_data.php" class="btn">Cleanup Data</a>
                    </div>
                    <div>
                        <h3>Risk Categories</h3>
                        <p>Manage risk categories and classifications.</p>
                        <a href="manage_categories.php" class="btn">Manage Categories</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="bulk-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Bulk Upload</h2>
                <p>Upload historical risk data from CSV or Excel files.</p>
                
                <div class="bulk-upload">
                    <form method="POST" enctype="multipart/form-data" id="bulkUploadForm">
                        <h3>📁 Upload Risk Data</h3>
                        <p>Supported formats: CSV, XLS, XLSX</p>
                        <input type="file" name="bulk_file" accept=".csv,.xls,.xlsx" required style="margin: 1rem 0;">
                        <br>
                        <button type="submit" name="bulk_upload" class="btn">Upload Data</button>
                    </form>
                </div>
                
                <div style="margin-top: 2rem;">
                    <h3>CSV Format Requirements</h3>
                    <p>Your CSV file should have the following columns:</p>
                    <ul style="margin-left: 2rem; margin-top: 1rem;">
                        <li>risk_name</li>
                        <li>risk_description</li>
                        <li>cause_of_risk</li>
                        <li>department</li>
                        <li>probability (1-5)</li>
                        <li>impact (1-5)</li>
                        <li>status (Open, In Progress, Mitigated, Closed)</li>
                    </ul>
                    
                    <a href="download_template.php" class="btn" style="margin-top: 1rem;">Download Template</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.style.display = 'none');
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.nav-tabs button');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName + '-tab').style.display = 'block';
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
