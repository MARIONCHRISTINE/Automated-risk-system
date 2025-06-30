<?php
include_once 'includes/auth.php';
requireRole(['risk_owner', 'admin']);
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get risk ID from URL
$risk_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$risk_id) {
    header("Location: risk_owner_dashboard.php");
    exit();
}

// Handle form submission for comprehensive risk update
if ($_POST && isset($_POST['update_comprehensive_risk'])) {
    try {
        $db->beginTransaction();
        
        // Update all comprehensive risk fields
        $query = "UPDATE risk_incidents SET 
                  risk_category = :risk_category,
                  existing_or_new = :existing_or_new,
                  to_be_reported_to_board = :to_be_reported_to_board,
                  inherent_likelihood = :inherent_likelihood,
                  inherent_consequence = :inherent_consequence,
                  inherent_rating = :inherent_rating,
                  residual_likelihood = :residual_likelihood,
                  residual_consequence = :residual_consequence,
                  residual_rating = :residual_rating,
                  treatment_action = :treatment_action,
                  controls_action_plans = :controls_action_plans,
                  target_completion_date = :target_completion_date,
                  progress_update = :progress_update,
                  treatment_status = :treatment_status,
                  risk_status = :risk_status,
                  updated_at = NOW()
                  WHERE id = :risk_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':risk_category', $_POST['risk_category']);
        $stmt->bindParam(':existing_or_new', $_POST['existing_or_new']);
        $stmt->bindParam(':to_be_reported_to_board', $_POST['to_be_reported_to_board']);
        $stmt->bindParam(':inherent_likelihood', $_POST['inherent_likelihood']);
        $stmt->bindParam(':inherent_consequence', $_POST['inherent_consequence']);
        $stmt->bindParam(':inherent_rating', $_POST['inherent_rating']);
        $stmt->bindParam(':residual_likelihood', $_POST['residual_likelihood']);
        $stmt->bindParam(':residual_consequence', $_POST['residual_consequence']);
        $stmt->bindParam(':residual_rating', $_POST['residual_rating']);
        $stmt->bindParam(':treatment_action', $_POST['treatment_action']);
        $stmt->bindParam(':controls_action_plans', $_POST['controls_action_plans']);
        $stmt->bindParam(':target_completion_date', $_POST['target_completion_date']);
        $stmt->bindParam(':progress_update', $_POST['progress_update']);
        $stmt->bindParam(':treatment_status', $_POST['treatment_status']);
        $stmt->bindParam(':risk_status', $_POST['risk_status']);
        $stmt->bindParam(':risk_id', $risk_id);
        
        if ($stmt->execute()) {
            $db->commit();
            $success_message = "Risk updated successfully with all comprehensive details!";
            
            // Redirect back to dashboard with success message
            header("Location: risk_owner_dashboard.php?updated=1&risk_id=" . $risk_id);
            exit();
        } else {
            $db->rollback();
            $error_message = "Failed to update risk.";
        }
    } catch (PDOException $e) {
        $db->rollback();
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Get risk details
$query = "SELECT r.*, u.full_name as reporter_name, u.email as reporter_email,
          ro.full_name as risk_owner_name
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          LEFT JOIN users ro ON r.risk_owner_id = ro.id
          WHERE r.id = :risk_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':risk_id', $risk_id);
$stmt->execute();
$risk = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$risk) {
    header("Location: risk_owner_dashboard.php");
    exit();
}

// Get current user info
$user = getCurrentUser();

// Risk categories for dropdown
$risk_categories = [
    'Strategic Risk',
    'Operational Risk', 
    'Financial Risk',
    'Compliance Risk',
    'Technology Risk',
    'Reputational Risk',
    'Market Risk',
    'Credit Risk',
    'Liquidity Risk',
    'Other'
];

// Treatment status options
$treatment_statuses = [
    'Not Started',
    'In Progress',
    'On Hold',
    'Completed',
    'Cancelled'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Details - <?php echo htmlspecialchars($risk['risk_name']); ?></title>
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
            padding: 2rem;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }
        
        .header h1 {
            color: #E60012;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        
        .header .meta {
            color: #666;
            font-size: 0.9rem;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #6c757d;
            color: white;
            padding: 0.7rem 1.2rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1rem;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #545b62;
        }
        
        .form-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .form-header {
            background: #E60012;
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .form-title {
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .template-reference {
            background: rgba(255,255,255,0.1);
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .form-body {
            padding: 2rem;
        }
        
        .form-sections {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 2rem;
        }
        
        .form-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #E60012;
        }
        
        .section-title {
            color: #E60012;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            transition: border-color 0.3s;
            font-size: 0.9rem;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #E60012;
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        textarea {
            height: 100px;
            resize: vertical;
        }
        
        .readonly-field {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        .risk-rating {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .rating-display {
            background: #E60012;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-weight: bold;
            min-width: 60px;
            text-align: center;
        }
        
        .rating-low { background: #28a745; }
        .rating-medium { background: #ffc107; color: #000; }
        .rating-high { background: #fd7e14; }
        .rating-critical { background: #dc3545; }
        
        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.9rem 2rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #B8000E;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #28a745;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid #dc3545;
        }
        
        .form-actions {
            background: #f8f9fa;
            padding: 1.5rem 2rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        @media (max-width: 1200px) {
            .form-sections {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            
            .form-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="risk_owner_dashboard.php" class="back-btn">
            ← Back to Dashboard
        </a>
        
        <div class="header">
            <h1><?php echo htmlspecialchars($risk['risk_name']); ?></h1>
            <div class="meta">
                <strong>Reported by:</strong> <?php echo htmlspecialchars($risk['reporter_name']); ?> 
                | <strong>Date:</strong> <?php echo date('M j, Y', strtotime($risk['created_at'])); ?>
                | <strong>Department:</strong> <?php echo htmlspecialchars($risk['department']); ?>
            </div>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="success">✅ <?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="error">❌ <?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">Comprehensive Risk Management Form</h2>
                <div class="template-reference">Following Airtel Risk Template</div>
            </div>
            
            <form method="POST">
                <div class="form-body">
                    <div class="form-sections">
                        <!-- RISK IDENTIFICATION Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                📋 RISK IDENTIFICATION
                            </h3>
                            
                            <div class="form-group">
                                <label>Risk ID</label>
                                <input type="text" value="RISK-<?php echo str_pad($risk['id'], 4, '0', STR_PAD_LEFT); ?>" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Date of Risk Entry</label>
                                <input type="text" value="<?php echo date('M j, Y', strtotime($risk['created_at'])); ?>" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="existing_or_new">Existing Risk?</label>
                                    <select id="existing_or_new" name="existing_or_new">
                                        <option value="New" <?php echo ($risk['existing_or_new'] == 'New') ? 'selected' : ''; ?>>New</option>
                                        <option value="Existing" <?php echo ($risk['existing_or_new'] == 'Existing') ? 'selected' : ''; ?>>Existing</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="to_be_reported_to_board">Report to Board</label>
                                    <select id="to_be_reported_to_board" name="to_be_reported_to_board">
                                        <option value="No" <?php echo ($risk['to_be_reported_to_board'] == 'No') ? 'selected' : ''; ?>>No</option>
                                        <option value="Yes" <?php echo ($risk['to_be_reported_to_board'] == 'Yes') ? 'selected' : ''; ?>>Yes</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Risk Name</label>
                                <input type="text" value="<?php echo htmlspecialchars($risk['risk_name']); ?>" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label>Risk Description</label>
                                <textarea class="readonly-field" readonly><?php echo htmlspecialchars($risk['risk_description']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Cause of Risk</label>
                                <textarea class="readonly-field" readonly><?php echo htmlspecialchars($risk['cause_of_risk']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="risk_category">Risk Category</label>
                                <select id="risk_category" name="risk_category">
                                    <option value="">Select Category</option>
                                    <?php foreach ($risk_categories as $category): ?>
                                        <option value="<?php echo $category; ?>" <?php echo ($risk['risk_category'] == $category) ? 'selected' : ''; ?>>
                                            <?php echo $category; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- RISK ASSESSMENT Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                📊 RISK ASSESSMENT
                            </h3>
                            
                            <h4 style="color: #E60012; margin-bottom: 1rem;">Inherent Risk (Gross Risk)</h4>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label for="inherent_likelihood">Likelihood (1-5)</label>
                                    <select id="inherent_likelihood" name="inherent_likelihood" onchange="calculateInherentRating()">
                                        <option value="">Select</option>
                                        <option value="1" <?php echo ($risk['inherent_likelihood'] == 1) ? 'selected' : ''; ?>>1 - Very Low</option>
                                        <option value="2" <?php echo ($risk['inherent_likelihood'] == 2) ? 'selected' : ''; ?>>2 - Low</option>
                                        <option value="3" <?php echo ($risk['inherent_likelihood'] == 3) ? 'selected' : ''; ?>>3 - Medium</option>
                                        <option value="4" <?php echo ($risk['inherent_likelihood'] == 4) ? 'selected' : ''; ?>>4 - High</option>
                                        <option value="5" <?php echo ($risk['inherent_likelihood'] == 5) ? 'selected' : ''; ?>>5 - Very High</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="inherent_consequence">Consequence (1-5)</label>
                                    <select id="inherent_consequence" name="inherent_consequence" onchange="calculateInherentRating()">
                                        <option value="">Select</option>
                                        <option value="1" <?php echo ($risk['inherent_consequence'] == 1) ? 'selected' : ''; ?>>1 - Minimal</option>
                                        <option value="2" <?php echo ($risk['inherent_consequence'] == 2) ? 'selected' : ''; ?>>2 - Minor</option>
                                        <option value="3" <?php echo ($risk['inherent_consequence'] == 3) ? 'selected' : ''; ?>>3 - Moderate</option>
                                        <option value="4" <?php echo ($risk['inherent_consequence'] == 4) ? 'selected' : ''; ?>>4 - Major</option>
                                        <option value="5" <?php echo ($risk['inherent_consequence'] == 5) ? 'selected' : ''; ?>>5 - Catastrophic</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Rating</label>
                                    <div class="risk-rating">
                                        <input type="hidden" id="inherent_rating" name="inherent_rating" value="<?php echo $risk['inherent_rating']; ?>">
                                        <div id="inherent_rating_display" class="rating-display">
                                            <?php echo $risk['inherent_rating'] ?: '-'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <h4 style="color: #E60012; margin: 1.5rem 0 1rem;">Residual Risk (Net Risk)</h4>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label for="residual_likelihood">Likelihood (1-5)</label>
                                    <select id="residual_likelihood" name="residual_likelihood" onchange="calculateResidualRating()">
                                        <option value="">Select</option>
                                        <option value="1" <?php echo ($risk['residual_likelihood'] == 1) ? 'selected' : ''; ?>>1 - Very Low</option>
                                        <option value="2" <?php echo ($risk['residual_likelihood'] == 2) ? 'selected' : ''; ?>>2 - Low</option>
                                        <option value="3" <?php echo ($risk['residual_likelihood'] == 3) ? 'selected' : ''; ?>>3 - Medium</option>
                                        <option value="4" <?php echo ($risk['residual_likelihood'] == 4) ? 'selected' : ''; ?>>4 - High</option>
                                        <option value="5" <?php echo ($risk['residual_likelihood'] == 5) ? 'selected' : ''; ?>>5 - Very High</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="residual_consequence">Consequence (1-5)</label>
                                    <select id="residual_consequence" name="residual_consequence" onchange="calculateResidualRating()">
                                        <option value="">Select</option>
                                        <option value="1" <?php echo ($risk['residual_consequence'] == 1) ? 'selected' : ''; ?>>1 - Minimal</option>
                                        <option value="2" <?php echo ($risk['residual_consequence'] == 2) ? 'selected' : ''; ?>>2 - Minor</option>
                                        <option value="3" <?php echo ($risk['residual_consequence'] == 3) ? 'selected' : ''; ?>>3 - Moderate</option>
                                        <option value="4" <?php echo ($risk['residual_consequence'] == 4) ? 'selected' : ''; ?>>4 - Major</option>
                                        <option value="5" <?php echo ($risk['residual_consequence'] == 5) ? 'selected' : ''; ?>>5 - Catastrophic</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Rating</label>
                                    <div class="risk-rating">
                                        <input type="hidden" id="residual_rating" name="residual_rating" value="<?php echo $risk['residual_rating']; ?>">
                                        <div id="residual_rating_display" class="rating-display">
                                            <?php echo $risk['residual_rating'] ?: '-'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="treatment_action">Treatment Action</label>
                                <textarea id="treatment_action" name="treatment_action" placeholder="Describe the treatment action required"><?php echo htmlspecialchars($risk['treatment_action']); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- RISK TREATMENT Section -->
                        <div class="form-section">
                            <h3 class="section-title">
                                🛡️ RISK TREATMENT
                            </h3>
                            
                            <div class="form-group">
                                <label for="controls_action_plans">Controls/Action Plans</label>
                                <textarea id="controls_action_plans" name="controls_action_plans" placeholder="Detail the controls and action plans"><?php echo htmlspecialchars($risk['controls_action_plans']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="target_completion_date">Target Completion Date</label>
                                <input type="date" id="target_completion_date" name="target_completion_date" value="<?php echo $risk['target_completion_date']; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Risk Owner</label>
                                <input type="text" value="<?php echo htmlspecialchars($risk['risk_owner_name'] ?: 'Unassigned'); ?>" class="readonly-field" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="progress_update">Progress Update</label>
                                <textarea id="progress_update" name="progress_update" placeholder="Provide progress updates"><?php echo htmlspecialchars($risk['progress_update']); ?></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="treatment_status">Treatment Status</label>
                                    <select id="treatment_status" name="treatment_status">
                                        <?php foreach ($treatment_statuses as $status): ?>
                                            <option value="<?php echo $status; ?>" <?php echo ($risk['treatment_status'] == $status) ? 'selected' : ''; ?>>
                                                <?php echo $status; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="risk_status">Overall Risk Status</label>
                                    <select id="risk_status" name="risk_status">
                                        <option value="pending" <?php echo ($risk['risk_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                        <option value="in_progress" <?php echo ($risk['risk_status'] == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo ($risk['risk_status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                        <option value="on_hold" <?php echo ($risk['risk_status'] == 'on_hold') ? 'selected' : ''; ?>>On Hold</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.history.back()">Cancel</button>
                    <button type="submit" name="update_comprehensive_risk" class="btn">Save All Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function calculateInherentRating() {
            const likelihood = document.getElementById('inherent_likelihood').value;
            const consequence = document.getElementById('inherent_consequence').value;
            
            if (likelihood && consequence) {
                const rating = parseInt(likelihood) * parseInt(consequence);
                document.getElementById('inherent_rating').value = rating;
                document.getElementById('inherent_rating_display').textContent = rating;
                
                // Update color based on rating
                const display = document.getElementById('inherent_rating_display');
                display.className = 'rating-display';
                if (rating >= 15) display.classList.add('rating-critical');
                else if (rating >= 9) display.classList.add('rating-high');
                else if (rating >= 4) display.classList.add('rating-medium');
                else display.classList.add('rating-low');
            }
        }
        
        function calculateResidualRating() {
            const likelihood = document.getElementById('residual_likelihood').value;
            const consequence = document.getElementById('residual_consequence').value;
            
            if (likelihood && consequence) {
                const rating = parseInt(likelihood) * parseInt(consequence);
                document.getElementById('residual_rating').value = rating;
                document.getElementById('residual_rating_display').textContent = rating;
                
                // Update color based on rating
                const display = document.getElementById('residual_rating_display');
                display.className = 'rating-display';
                if (rating >= 15) display.classList.add('rating-critical');
                else if (rating >= 9) display.classList.add('rating-high');
                else if (rating >= 4) display.classList.add('rating-medium');
                else display.classList.add('rating-low');
            }
        }
        
        // Initialize ratings on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateInherentRating();
            calculateResidualRating();
        });
    </script>
</body>
</html>
