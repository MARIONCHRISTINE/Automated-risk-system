<?php
include_once 'includes/auth.php';
requireRole('staff');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle risk submission
if ($_POST && isset($_POST['submit_risk'])) {
    $risk_name = $_POST['risk_name'];
    $risk_description = $_POST['risk_description'];
    $cause_of_risk = $_POST['cause_of_risk'];
    $department = $_POST['department'];
    
    $query = "INSERT INTO risk_incidents (risk_name, risk_description, cause_of_risk, department, reported_by) VALUES (:risk_name, :risk_description, :cause_of_risk, :department, :reported_by)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':risk_name', $risk_name);
    $stmt->bindParam(':risk_description', $risk_description);
    $stmt->bindParam(':cause_of_risk', $cause_of_risk);
    $stmt->bindParam(':department', $department);
    $stmt->bindParam(':reported_by', $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $success_message = "Risk reported successfully!";
    } else {
        $error_message = "Failed to report risk. Please try again.";
    }
}

// Get user's reported risks
$query = "SELECT * FROM risk_incidents WHERE reported_by = :user_id ORDER BY created_at DESC";
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(230, 0, 18, 0.2);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .airtel-logo {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(255, 255, 255, 0.2);
        }
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 4px solid #E60012;
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-icon {
            color: #E60012;
            font-size: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 0.75rem;
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
            height: 100px;
            resize: vertical;
        }
        
        .btn {
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(230, 0, 18, 0.3);
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid #4caf50;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 4px solid #f44336;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-box {
            text-align: center;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid #e9ecef;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .risk-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .risk-table th, .risk-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .risk-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status-open { background: #fff3cd; color: #856404; }
        .status-inprogress { background: #ffebee; color: #E60012; }
        .status-mitigated { background: #fff5f5; color: #E60012; }
        .status-closed { background: #d4edda; color: #155724; }
        
        .chatbot {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(230, 0, 18, 0.3);
            color: white;
            font-size: 1.5rem;
            transition: transform 0.3s;
        }
        
        .chatbot:hover {
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .container {
                padding: 0 1rem;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <img src="image.png" alt="Airtel Logo" class="airtel-logo">
            <h1>Staff Dashboard</h1>
        </div>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['full_name']; ?></span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($success_message)): ?>
            <div class="success">✅ <?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error">❌ <?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <div class="dashboard-grid">
            <div class="card">
                <h2><span class="card-icon">📝</span>Report New Risk</h2>
                <form method="POST">
                    <div class="form-group">
                        <label for="risk_name">Risk Name</label>
                        <input type="text" id="risk_name" name="risk_name" required placeholder="Enter a clear risk title">
                    </div>
                    
                    <div class="form-group">
                        <label for="risk_description">Risk Description</label>
                        <textarea id="risk_description" name="risk_description" required placeholder="Describe the risk in detail"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="cause_of_risk">Cause of Risk</label>
                        <textarea id="cause_of_risk" name="cause_of_risk" required placeholder="What causes this risk?"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" required placeholder="Your department">
                    </div>
                    
                    <button type="submit" name="submit_risk" class="btn">Submit Risk Report</button>
                </form>
            </div>
            
            <div class="card">
                <h2><span class="card-icon">📊</span>Your Risk Statistics</h2>
                <div class="stats-container">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($user_risks); ?></div>
                        <div class="stat-label">Total Risks Reported</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php echo count(array_filter($user_risks, function($risk) { return $risk['status'] === 'Closed'; })); ?>
                        </div>
                        <div class="stat-label">Resolved Risks</div>
                    </div>
                </div>
                
                <div style="margin-top: 2rem; padding: 1rem; background: #fff5f5; border-radius: 8px; border-left: 4px solid #E60012;">
                    <h4 style="color: #E60012; margin-bottom: 0.5rem;">💡 Quick Tips</h4>
                    <ul style="margin-left: 1rem; color: #666;">
                        <li>Be specific when describing risks</li>
                        <li>Include potential impact details</li>
                        <li>Check back regularly for updates</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2><span class="card-icon">📋</span>My Reported Risks</h2>
            <?php if (count($user_risks) > 0): ?>
                <table class="risk-table">
                    <thead>
                        <tr>
                            <th>Risk Name</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Risk Level</th>
                            <th>Date Reported</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_risks as $risk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $risk['status'])); ?>">
                                        <?php echo $risk['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: <?php 
                                        echo $risk['risk_level'] === 'Critical' ? '#dc3545' : 
                                            ($risk['risk_level'] === 'High' ? '#E60012' : 
                                            ($risk['risk_level'] === 'Medium' ? '#ffc107' : '#28a745')); 
                                    ?>; font-weight: 600;">
                                        <?php echo $risk['risk_level']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📝</div>
                    <h3>No risks reported yet</h3>
                    <p>Start by reporting your first risk using the form above.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="chatbot" onclick="openChatbot()" title="Need help? Click to chat">💬</div>
    
    <script>
        function openChatbot() {
            const responses = [
                "Hello! I'm here to help with risk reporting. What would you like to know?",
                "You can report risks using the form on this page. Make sure to be detailed in your descriptions.",
                "If you need help with risk categories, contact your risk owner or compliance team.",
                "For technical issues, please contact IT support at support@airtel.africa"
            ];
            
            const message = prompt("Hi! I'm your Airtel Risk Assistant. How can I help you today?\n\n• Risk reporting guidance\n• System help\n• Contact information\n\nType your question:");
            
            if (message) {
                const randomResponse = responses[Math.floor(Math.random() * responses.length)];
                alert("Thank you for your question: '" + message + "'\n\n" + randomResponse + "\n\nFor more detailed assistance, please contact the compliance team.");
            }
        }
    </script>
</body>
</html>
