<?php
include_once 'includes/auth.php';
requireRole('compliance');
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get all risks for compliance overview
$query = "SELECT r.*, u.full_name as reporter_name, ro.full_name as owner_name 
          FROM risk_incidents r 
          LEFT JOIN users u ON r.reported_by = u.id 
          LEFT JOIN users ro ON r.risk_owner_id = ro.id 
          ORDER BY r.risk_score DESC, r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$all_risks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get risk statistics
$stats_query = "SELECT 
    COUNT(*) as total_risks,
    SUM(CASE WHEN risk_level = 'Critical' THEN 1 ELSE 0 END) as critical_risks,
    SUM(CASE WHEN risk_level = 'High' THEN 1 ELSE 0 END) as high_risks,
    SUM(CASE WHEN risk_level = 'Medium' THEN 1 ELSE 0 END) as medium_risks,
    SUM(CASE WHEN risk_level = 'Low' THEN 1 ELSE 0 END) as low_risks,
    SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) as open_risks,
    SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed_risks
    FROM risk_incidents";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .heat-map {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 2px;
            margin: 1rem 0;
        }
        
        .heat-cell {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border-radius: 4px;
        }
        
        .heat-header {
            background: #f8f9fa;
            color: #333;
        }
        
        .heat-low { background: #d4edda; color: #155724; }
        .heat-medium { background: #fff3cd; color: #856404; }
        .heat-high { background: #f8d7da; color: #721c24; }
        .heat-critical { background: #f5c6cb; color: #721c24; }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.5rem;
            text-decoration: none;
            display: inline-block;
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
        
        .chart-container {
            position: relative;
            height: 400px;
            margin: 2rem 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Compliance Dashboard</h1>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['full_name']; ?></span>
            <a href="logout.php" class="logout-btn" style="background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 5px; text-decoration: none; margin-left: 1rem;">Logout</a>
        </div>
    </div>
    
    <div class="nav-tabs">
        <button class="active" onclick="showTab('overview')">Overview</button>
        <button onclick="showTab('heatmap')">Risk Heat Map</button>
        <button onclick="showTab('reports')">Board Reports</button>
        <button onclick="showTab('analytics')">Analytics</button>
    </div>
    
    <div class="container">
        <div id="overview-tab" class="tab-content">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" style="color: #667eea;"><?php echo $stats['total_risks']; ?></div>
                    <div class="stat-label">Total Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #dc3545;"><?php echo $stats['critical_risks']; ?></div>
                    <div class="stat-label">Critical Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #fd7e14;"><?php echo $stats['high_risks']; ?></div>
                    <div class="stat-label">High Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #ffc107;"><?php echo $stats['medium_risks']; ?></div>
                    <div class="stat-label">Medium Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #28a745;"><?php echo $stats['low_risks']; ?></div>
                    <div class="stat-label">Low Risks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #17a2b8;"><?php echo $stats['open_risks']; ?></div>
                    <div class="stat-label">Open Risks</div>
                </div>
            </div>
            
            <div class="card">
                <h2>Recent High Priority Risks</h2>
                <table class="risk-table">
                    <thead>
                        <tr>
                            <th>Risk Name</th>
                            <th>Department</th>
                            <th>Risk Level</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Reporter</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $high_priority_risks = array_filter($all_risks, function($risk) {
                            return in_array($risk['risk_level'], ['Critical', 'High']);
                        });
                        $high_priority_risks = array_slice($high_priority_risks, 0, 10);
                        ?>
                        <?php foreach ($high_priority_risks as $risk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($risk['risk_name']); ?></td>
                                <td><?php echo htmlspecialchars($risk['department']); ?></td>
                                <td>
                                    <span class="risk-level level-<?php echo strtolower($risk['risk_level']); ?>">
                                        <?php echo $risk['risk_level']; ?>
                                    </span>
                                </td>
                                <td><?php echo $risk['risk_score']; ?></td>
                                <td><?php echo $risk['status']; ?></td>
                                <td><?php echo htmlspecialchars($risk['reporter_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($risk['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="heatmap-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Risk Heat Map</h2>
                <p>This matrix shows the relationship between probability and impact for risk assessment.</p>
                
                <div class="heat-map">
                    <div class="heat-cell heat-header"></div>
                    <div class="heat-cell heat-header">1</div>
                    <div class="heat-cell heat-header">2</div>
                    <div class="heat-cell heat-header">3</div>
                    <div class="heat-cell heat-header">4</div>
                    <div class="heat-cell heat-header">5</div>
                    
                    <?php for ($prob = 5; $prob >= 1; $prob--): ?>
                        <div class="heat-cell heat-header"><?php echo $prob; ?></div>
                        <?php for ($impact = 1; $impact <= 5; $impact++): 
                            $score = $prob * $impact;
                            $level = 'low';
                            if ($score > 20) $level = 'critical';
                            elseif ($score > 12) $level = 'high';
                            elseif ($score > 6) $level = 'medium';
                        ?>
                            <div class="heat-cell heat-<?php echo $level; ?>"><?php echo $score; ?></div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </div>
                
                <div style="margin-top: 2rem;">
                    <h3>Legend</h3>
                    <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div class="heat-cell heat-low" style="width: 30px; height: 30px;"></div>
                            <span>Low (1-6)</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div class="heat-cell heat-medium" style="width: 30px; height: 30px;"></div>
                            <span>Medium (7-12)</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div class="heat-cell heat-high" style="width: 30px; height: 30px;"></div>
                            <span>High (13-20)</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div class="heat-cell heat-critical" style="width: 30px; height: 30px;"></div>
                            <span>Critical (21-25)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="reports-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Board Reports</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                    <div>
                        <h3>Executive Summary</h3>
                        <p>Generate comprehensive risk reports for board meetings.</p>
                        <a href="generate_board_report.php" class="btn">Generate Executive Report</a>
                    </div>
                    <div>
                        <h3>Risk Trend Analysis</h3>
                        <p>Analyze risk trends over time periods.</p>
                        <a href="trend_analysis.php" class="btn">View Trends</a>
                    </div>
                    <div>
                        <h3>Department Reports</h3>
                        <p>Department-specific risk analysis.</p>
                        <a href="department_reports.php" class="btn">Department Analysis</a>
                    </div>
                    <div>
                        <h3>Export Data</h3>
                        <p>Download risk data in various formats.</p>
                        <a href="export_all_data.php" class="btn">Export All Data</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="analytics-tab" class="tab-content" style="display: none;">
            <div class="card">
                <h2>Risk Analytics</h2>
                <div class="chart-container">
                    <canvas id="riskChart"></canvas>
                </div>
            </div>
            
            <div class="card">
                <h2>Department Risk Distribution</h2>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
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
            
            // Initialize charts when analytics tab is shown
            if (tabName === 'analytics') {
                initializeCharts();
            }
        }
        
        function initializeCharts() {
            // Risk Level Distribution Chart
            const ctx1 = document.getElementById('riskChart').getContext('2d');
            new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        data: [
                            <?php echo $stats['critical_risks']; ?>,
                            <?php echo $stats['high_risks']; ?>,
                            <?php echo $stats['medium_risks']; ?>,
                            <?php echo $stats['low_risks']; ?>
                        ],
                        backgroundColor: ['#dc3545', '#fd7e14', '#ffc107', '#28a745']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Risk Level Distribution'
                        }
                    }
                }
            });
            
            // Department Risk Chart
            const ctx2 = document.getElementById('departmentChart').getContext('2d');
            
            // Get department data
            <?php
            $dept_query = "SELECT department, COUNT(*) as count FROM risk_incidents GROUP BY department";
            $dept_stmt = $db->prepare($dept_query);
            $dept_stmt->execute();
            $dept_data = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $departments = array_column($dept_data, 'department');
            $counts = array_column($dept_data, 'count');
            ?>
            
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($departments); ?>,
                    datasets: [{
                        label: 'Number of Risks',
                        data: <?php echo json_encode($counts); ?>,
                        backgroundColor: '#667eea'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Risks by Department'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
