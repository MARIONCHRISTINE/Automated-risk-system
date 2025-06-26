<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle document upload
if ($_POST && isset($_POST['upload_document'])) {
    $risk_id = $_POST['risk_id'];
    
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = $_FILES['document']['name'];
        $file_tmp = $_FILES['document']['tmp_name'];
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $new_file_name = uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $new_file_name;
        
        if (move_uploaded_file($file_tmp, $file_path)) {
            $query = "INSERT INTO risk_documents (risk_id, file_name, file_path, uploaded_by) VALUES (:risk_id, :file_name, :file_path, :uploaded_by)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':risk_id', $risk_id);
            $stmt->bindParam(':file_name', $file_name);
            $stmt->bindParam(':file_path', $file_path);
            $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $success_message = "Document uploaded successfully!";
            }
        }
    }
}

// Handle risk scoring
if ($_POST && isset($_POST['update_risk_score'])) {
    $risk_id = $_POST['risk_id'];
    $probability = $_POST['probability'];
    $impact = $_POST['impact'];
    
    // Call Python microservice for risk scoring
    $risk_data = array(
        'probability' => floatval($probability),
        'impact' => floatval($impact)
    );
    
    $ch = curl_init('http://localhost:5000/calculate_risk');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($risk_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $result = json_decode($response, true);
        if ($result['success']) {
            $risk_score = $result['data']['score'];
            $risk_level = $result['data']['level'];
            
            $query = "UPDATE risk_incidents SET probability = :probability, impact = :impact, risk_score = :risk_score, risk_level = :risk_level WHERE id = :risk_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':probability', $probability);
            $stmt->bindParam(':impact', $impact);
            $stmt->bindParam(':risk_score', $risk_score);
            $stmt->bindParam(':risk_level', $risk_level);
            $stmt->bindParam(':risk_id', $risk_id);
            
            if ($stmt->execute()) {
                $success_message = "Risk score updated successfully!";
            }
        }
    }
}

// Get current user info
$user = getCurrentUser();

// Get risks for this risk owner's department
$query = "SELECT r.*, u.full_name as reporter_name FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          WHERE r.department = :department OR r.risk_owner_id = :user_id 
          ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':department', $user['department']);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$department_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Owner Dashboard</title>
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
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .risk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }
        
        .risk-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            background: white;
        }
        
        .risk-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .risk-level {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .level-low { background: #d4edda; color: #155724; }
        .level-medium { background: #fff3cd; color: #856404; }
        .level-high { background: #f8d7da; color: #721c24; }
        .level-critical { background: #f5c6cb; color: #721c24; }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
        }
        
        .btn-secondary {
            background: #6c757d;
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
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Risk Owner Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['full_name']; ?> (<?php echo $user['department']; ?>)</span>
            <a href="logout.php" class="logout-btn" style="background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 5px; text-decoration: none; margin-left: 1rem;">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <button class="active" onclick="showTab('risks')">Manage Risks</button>
        <button onclick="showTab('reports')">Reports</button>
        <button onclick="showTab('procedures')">Procedures</button>
    </div>
    
    <div class="container">
        <?php if (isset($success_message)): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <div id="risks-tab" class="tab-content">
            <div class="card">
                <h2>Department Risks (<?php echo $user['department']; ?>)</h2>
                <div class="risk-grid">
                    <?php foreach ($department_risks as $risk): ?>
                        <div class="risk-card">
                            <div class="risk-header">
                                <h3><?php echo htmlspecialchars($risk['risk_name']); ?></h3>
                                <span class="risk-level level-<?php echo strtolower($risk['risk_level']); ?>">
                                    <?php echo $risk['risk_level']; ?>
                                </span>
                            </div>
                            <p><strong>Description:</strong> <?php echo htmlspecialchars($risk['risk_description']); ?></p>
                            <p><strong>Cause:</strong> <?php echo htmlspecialchars($risk['cause_of_risk']); ?></p>
                            <p><strong>Reported by:</strong> <?php echo htmlspecialchars($risk['reporter_name']); ?></p>
                            <p><strong>Status:</strong> <?php echo $risk['status']; ?></p>
                            <p><strong>Risk Score:</strong> <?php echo $risk['risk_score']; ?></p>
                            
                            <div style="margin-top: 1rem;">
                                <button class="btn" onclick="openScoreModal(<?php echo $risk['id']; ?>, <?php echo $risk['probability']; ?>, <?php echo $risk['impact']; ?>)">Update Score</button>
                                <button class="btn btn-secondary" onclick="openDocumentModal(<?php echo $risk['id']; ?>)">Upload Document</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div id="reports-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Risk Reports</h2>
                <button class="btn" onclick="generateReport()">Generate Department Report</button>
                <button class="btn btn-secondary" onclick="exportToCSV()">Export to CSV</button>
            </div>
        </div>
        
        <div id="procedures-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Risk Management Procedures</h2>
                <div style="padding: 1rem; background: #f8f9fa; border-radius: 5px;">
                    <h3>Risk Assessment Guidelines</h3>
                    <ul style="margin-left: 2rem; margin-top: 1rem;">
                        <li>Probability Scale: 1 (Very Low) to 5 (Very High)</li>
                        <li>Impact Scale: 1 (Minimal) to 5 (Catastrophic)</li>
                        <li>Risk Score = Probability × Impact</li>
                        <li>Low Risk: 1-6, Medium Risk: 7-12, High Risk: 13-20, Critical Risk: 21-25</li>
                    </ul>
                    
                    <h3 style="margin-top: 2rem;">Document Requirements</h3>
                    <ul style="margin-left: 2rem; margin-top: 1rem;">
                        <li>Risk assessment forms</li>
                        <li>Mitigation plans</li>
                        <li>Evidence of risk occurrence</li>
                        <li>Approved file formats: PDF, DOC, DOCX, XLS, XLSX</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Risk Scoring Modal -->
    <div id="scoreModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('scoreModal')">&times;</span>
            <h2>Update Risk Score</h2>
            <form method="POST">
                <input type="hidden" id="score_risk_id" name="risk_id">
                <div class="form-group">
                    <label for="probability">Probability (1-5)</label>
                    <select id="probability" name="probability" required>
                        <option value="1">1 - Very Low</option>
                        <option value="2">2 - Low</option>
                        <option value="3">3 - Medium</option>
                        <option value="4">4 - High</option>
                        <option value="5">5 - Very High</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="impact">Impact (1-5)</label>
                    <select id="impact" name="impact" required>
                        <option value="1">1 - Minimal</option>
                        <option value="2">2 - Minor</option>
                        <option value="3">3 - Moderate</option>
                        <option value="4">4 - Major</option>
                        <option value="5">5 - Catastrophic</option>
                    </select>
                </div>
                <button type="submit" name="update_risk_score" class="btn">Update Score</button>
            </form>
        </div>
    </div>
    
    <!-- Document Upload Modal -->
    <div id="documentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('documentModal')">&times;</span>
            <h2>Upload Document</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" id="doc_risk_id" name="risk_id">
                <div class="form-group">
                    <label for="document">Select Document</label>
                    <input type="file" id="document" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx" required>
                </div>
                <button type="submit" name="upload_document" class="btn">Upload Document</button>
            </form>
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
        
        function openScoreModal(riskId, probability, impact) {
            document.getElementById('score_risk_id').value = riskId;
            document.getElementById('probability').value = probability || 1;
            document.getElementById('impact').value = impact || 1;
            document.getElementById('scoreModal').style.display = 'block';
        }
        
        function openDocumentModal(riskId) {
            document.getElementById('doc_risk_id').value = riskId;
            document.getElementById('documentModal').style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function generateReport() {
            window.open('generate_report.php?type=department', '_blank');
        }
        
        function exportToCSV() {
            window.open('export_csv.php?type=department', '_blank');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
