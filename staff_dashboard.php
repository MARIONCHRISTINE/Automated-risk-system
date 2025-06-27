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
    
    // Debug: Check if user session is valid
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $error_message = "Session expired. Please log in again.";
        header("Location: login.php");
        exit();
    }
    
    // Verify the user exists in the database
    $user_check_query = "SELECT id FROM users WHERE id = :user_id";
    $user_check_stmt = $db->prepare($user_check_query);
    $user_check_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $user_check_stmt->execute();
    
    if ($user_check_stmt->rowCount() == 0) {
        $error_message = "User account not found. Please contact administrator.";
    } else {
        try {
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
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
            error_log("Risk submission error: " . $e->getMessage());
        }
    }
}

// Get user's reported risks
$query = "SELECT * FROM risk_incidents WHERE reported_by = :user_id ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info
$user = getCurrentUser();
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
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1.2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border-left: 4px solid #28a745;
            font-weight: 500;
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
        
        .chatbot {
            position: fixed;
            bottom: 25px;
            right: 25px;
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, #E60012 0%, #B8000E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(230, 0, 18, 0.3);
            color: white;
            font-size: 1.6rem;
            transition: transform 0.3s;
            z-index: 1000;
        }
        
        .chatbot:hover {
            transform: scale(1.1);
        }
        
        /* Modal Styles */
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
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
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
                    <div class="user-avatar">S</div>
                    <div class="user-details">
                        <div class="user-email"><?php echo $_SESSION['email']; ?></div>
                        <div class="user-role">Staff • <?php echo $user['department'] ?? 'General'; ?></div>
                    </div>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <?php if (isset($success_message)): ?>
                <div class="success">✅ <?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error">❌ <?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <!-- Main Cards Layout -->
            <div class="main-cards-layout">
                <!-- Hero Section -->
                <section class="hero">
                    <button class="cta-button" onclick="openReportModal()">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Report New Risk
                    </button>
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
            <section class="reports-section show" id="reportsSection">
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
                                    <div class="risk-name"><?php echo htmlspecialchars($risk['risk_name']); ?></div>
                                    <button class="view-btn" onclick="viewRisk(<?php echo $risk['id']; ?>, '<?php echo htmlspecialchars($risk['risk_name']); ?>', '<?php echo htmlspecialchars($risk['risk_description']); ?>', '<?php echo htmlspecialchars($risk['cause_of_risk']); ?>', '<?php echo $risk['created_at']; ?>')">
                                        View
                                    </button>
                                </div>
                                <div class="risk-meta">
                                    <span><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></span>
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
    
    <!-- Risk Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report New Risk</h3>
                <button class="close" onclick="closeReportModal()">&times;</button>
            </div>
            <div class="modal-body">
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
                        <input type="text" id="department" name="department" required placeholder="Your department" value="<?php echo $user['department'] ?? ''; ?>">
                    </div>
                    
                    <button type="submit" name="submit_risk" class="btn">Submit Risk Report</button>
                </form>
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
                    <label>Risk Name:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalRiskName"></div>
                </div>
                <div class="form-group">
                    <label>Risk Description:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalRiskDescription"></div>
                </div>
                <div class="form-group">
                    <label>Cause of Risk:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalCauseOfRisk"></div>
                </div>
                <div class="form-group">
                    <label>Date Submitted:</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 3px solid #E60012;" id="modalDateSubmitted"></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="chatbot" onclick="openChatbot()" title="Need help? Click to chat">💬</div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statsCard = document.getElementById('statsCard');
            const reportsSection = document.getElementById('reportsSection');
            
            // Show reports section by default
            if (reportsSection && <?php echo count($user_risks); ?> > 0) {
                reportsSection.style.display = 'block';
                reportsSection.classList.add('show');
            }
            
            if (statsCard && <?php echo count($user_risks); ?> > 0) {
                statsCard.addEventListener('click', function() {
                    toggleReports();
                });
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
        
        function viewRisk(id, name, description, cause, date) {
            document.getElementById('modalRiskName').textContent = name;
            document.getElementById('modalRiskDescription').textContent = description;
            document.getElementById('modalCauseOfRisk').textContent = cause;
            
            const dateObj = new Date(date);
            document.getElementById('modalDateSubmitted').textContent = dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            document.getElementById('riskModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('riskModal').classList.remove('show');
        }
        
        function openChatbot() {
            const responses = [
                "Hello! I'm here to help with risk reporting. What would you like to know?",
                "You can report risks using the 'Report New Risk' button. Make sure to be detailed in your descriptions.",
                "If you need help with risk categories, contact your risk owner or compliance team.",
                "For technical issues, please contact IT support at support@airtel.africa"
            ];
            
            const message = prompt("Hi! I'm your Airtel Risk Assistant. How can I help you today?\n\n• Risk reporting guidance\n• System help\n• Contact information\n\nType your question:");
            
            if (message) {
                const randomResponse = responses[Math.floor(Math.random() * responses.length)];
                alert("Thank you for your question: '" + message + "'\n\n" + randomResponse + "\n\nFor more detailed assistance, please contact the compliance team.");
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('show');
                }
            });
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
    </script>
</body>
</html>
