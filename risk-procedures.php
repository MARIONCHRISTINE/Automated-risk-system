<?php
// Update the require paths to match your project structure
session_start();

// Check if user is logged in (basic check)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Reporting Procedures - Airtel Risk Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
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
            min-height: 100vh;
            padding-top: 150px;
        }

        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-top: 4px solid #E60012;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .card-title {
            color: #333;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }

        .btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 5px;
            cursor: pointer;
            margin: 0.25rem;
            transition: all 0.3s;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #B8000E;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #545b62;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        .btn-primary {
            background: #E60012;
        }

        .procedure-section {
            margin: 2rem 0;
            padding: 1.5rem;
            border-left: 4px solid #E60012;
            background: #f8f9fa;
            border-radius: 0 8px 8px 0;
        }
        
        .procedure-title {
            color: #E60012;
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .field-definition {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1rem;
            margin: 0.5rem 0;
        }
        
        .field-name {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .field-description {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .risk-matrix-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        
        .risk-matrix-table th,
        .risk-matrix-table td {
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            text-align: center;
        }
        
        .risk-matrix-table th {
            background: #E60012;
            color: white;
        }
        
        .matrix-1 { background: #d4edda; }
        .matrix-2 { background: #fff3cd; }
        .matrix-3 { background: #f8d7da; }
        .matrix-4 { background: #f5c6cb; }
        .matrix-5 { background: #dc3545; color: white; }
        
        .toc {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: 0.25rem;
            margin-bottom: 2rem;
        }
        
        .toc ul {
            list-style: none;
            padding-left: 0;
        }
        
        .toc li {
            margin: 0.5rem 0;
        }
        
        .toc a {
            text-decoration: none;
            color: #E60012;
        }
        
        .toc a:hover {
            text-decoration: underline;
        }
        
        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .department-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            border-left: 4px solid #E60012;
        }
        
        .department-title {
            font-weight: 600;
            color: #E60012;
            margin-bottom: 0.5rem;
        }
        
        .auto-assignment-flow {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }
        
        .flow-step {
            display: flex;
            align-items: center;
            margin: 0.5rem 0;
        }
        
        .flow-step i {
            color: #2196f3;
            margin-right: 0.5rem;
            width: 20px;
        }

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }
        
        @media print {
            .main-content { margin: 0; }
            .card { box-shadow: none; }
            .btn { display: none; }
            nav { display: none; }
        }

        @media (max-width: 768px) {
            body {
                padding-top: 200px;
            }

            .main-content {
                padding: 1rem;
            }

            .card {
                padding: 1rem;
            }

            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .department-grid {
                grid-template-columns: 1fr;
            }

            .procedure-section {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h1 class="card-title">Risk Reporting Procedures</h1>
                <div>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Print Procedures
                    </button>
                    <a href="report_risk.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Report New Risk
                    </a>
                </div>
            </div>
            <div class="card-body">
                
                <!-- Table of Contents -->
                <div class="toc">
                    <h3><i class="fas fa-list"></i> Table of Contents</h3>
                    <ul>
                        <li><a href="#overview">1. Overview</a></li>
                        <li><a href="#auto-assignment">2. Automatic Assignment System</a></li>
                        <li><a href="#departments">3. Department Structure & Responsibilities</a></li>
                        <li><a href="#identification">4. Risk Identification</a></li>
                        <li><a href="#assessment">5. Risk Assessment</a></li>
                        <li><a href="#treatment">6. Risk Treatment</a></li>
                        <li><a href="#monitoring">7. Monitoring & Review</a></li>
                        <li><a href="#roles">8. Roles & Responsibilities</a></li>
                        <li><a href="#matrix">9. Risk Assessment Matrix</a></li>
                        <li><a href="#definitions">10. Field Definitions</a></li>
                    </ul>
                </div>

                <!-- 1. Overview -->
                <div id="overview" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-info-circle"></i> 1. OVERVIEW</div>
                    <p>This document outlines the procedures for reporting, assessing, and managing risks within the Airtel Risk Management System. All staff members are required to follow these procedures when identifying and reporting risks.</p>
                    
                    <h4>Purpose</h4>
                    <ul>
                        <li>Establish a systematic approach to risk identification and reporting</li>
                        <li>Ensure consistent risk assessment across all departments</li>
                        <li>Provide clear guidelines for risk treatment and monitoring</li>
                        <li>Enable effective risk communication to management and the Board</li>
                        <li>Implement automated assignment based on department structure</li>
                    </ul>
                    
                    <h4>Scope</h4>
                    <p>These procedures apply to all risks that may impact operations, including but not limited to:</p>
                    <ul>
                        <li>Strategic risks</li>
                        <li>Operational risks</li>
                        <li>Financial risks</li>
                        <li>Compliance and regulatory risks</li>
                        <li>Technology and cybersecurity risks</li>
                        <li>Reputational risks</li>
                        <li>Human resources risks</li>
                        <li>Environmental risks</li>
                    </ul>
                </div>

                <!-- 2. Automatic Assignment System -->
                <div id="auto-assignment" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-robot"></i> 2. AUTOMATIC ASSIGNMENT SYSTEM</div>
                    
                    <h4>2.1 How Auto-Assignment Works</h4>
                    <p>Our system automatically assigns risks to appropriate risk owners based on the department selected during risk reporting. This ensures immediate accountability and faster response times.</p>
                    
                    <div class="auto-assignment-flow">
                        <h5><i class="fas fa-cogs"></i> Assignment Flow</h5>
                        <div class="flow-step">
                            <i class="fas fa-user"></i>
                            <span>User reports a risk and selects affected department</span>
                        </div>
                        <div class="flow-step">
                            <i class="fas fa-search"></i>
                            <span>System identifies department head/risk owner</span>
                        </div>
                        <div class="flow-step">
                            <i class="fas fa-bell"></i>
                            <span>Automatic notification sent to assigned risk owner</span>
                        </div>
                        <div class="flow-step">
                            <i class="fas fa-clipboard-check"></i>
                            <span>Risk owner receives dashboard notification and email alert</span>
                        </div>
                        <div class="flow-step">
                            <i class="fas fa-clock"></i>
                            <span>24-hour response time expectation begins</span>
                        </div>
                    </div>
                    
                    <h4>2.2 Assignment Rules</h4>
                    <ul>
                        <li><strong>Primary Assignment:</strong> Based on the department most affected by the risk</li>
                        <li><strong>Secondary Assignment:</strong> Cross-functional risks may be assigned to multiple departments</li>
                        <li><strong>Escalation:</strong> High and critical risks are automatically escalated to senior management</li>
                        <li><strong>Backup Assignment:</strong> If primary risk owner is unavailable, assignment goes to deputy</li>
                    </ul>
                    
                    <h4>2.3 Notification System</h4>
                    <ul>
                        <li>Immediate dashboard notification</li>
                        <li>Email notification within 5 minutes</li>
                        <li>SMS notification for critical risks</li>
                        <li>Daily digest for pending actions</li>
                    </ul>
                </div>

                <!-- 3. Department Structure -->
                <div id="departments" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-building"></i> 3. DEPARTMENT STRUCTURE & RESPONSIBILITIES</div>
                    
                    <h4>3.1 Department Risk Owners</h4>
                    <p>Each department has designated risk owners responsible for managing risks within their area:</p>
                    
                    <div class="department-grid">
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-chart-line"></i> Finance & Accounting</div>
                            <ul>
                                <li>Financial reporting risks</li>
                                <li>Budget and cash flow risks</li>
                                <li>Audit and compliance risks</li>
                                <li>Investment and credit risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-cogs"></i> Operations</div>
                            <ul>
                                <li>Service delivery risks</li>
                                <li>Supply chain risks</li>
                                <li>Quality control risks</li>
                                <li>Process efficiency risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-laptop-code"></i> Information Technology</div>
                            <ul>
                                <li>Cybersecurity risks</li>
                                <li>System availability risks</li>
                                <li>Data integrity risks</li>
                                <li>Technology upgrade risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-users"></i> Human Resources</div>
                            <ul>
                                <li>Talent retention risks</li>
                                <li>Compliance and legal risks</li>
                                <li>Training and development risks</li>
                                <li>Employee safety risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-bullhorn"></i> Marketing & Sales</div>
                            <ul>
                                <li>Brand reputation risks</li>
                                <li>Customer satisfaction risks</li>
                                <li>Market competition risks</li>
                                <li>Revenue generation risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-balance-scale"></i> Legal & Compliance</div>
                            <ul>
                                <li>Regulatory compliance risks</li>
                                <li>Legal liability risks</li>
                                <li>Contract management risks</li>
                                <li>Intellectual property risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-handshake"></i> Customer Service</div>
                            <ul>
                                <li>Service quality risks</li>
                                <li>Customer complaint risks</li>
                                <li>Response time risks</li>
                                <li>Customer data risks</li>
                            </ul>
                        </div>
                        
                        <div class="department-card">
                            <div class="department-title"><i class="fas fa-network-wired"></i> Network & Infrastructure</div>
                            <ul>
                                <li>Network outage risks</li>
                                <li>Infrastructure failure risks</li>
                                <li>Capacity planning risks</li>
                                <li>Maintenance risks</li>
                            </ul>
                        </div>
                    </div>
                    
                    <h4>3.2 Cross-Departmental Risks</h4>
                    <p>Some risks affect multiple departments and require coordinated response:</p>
                    <ul>
                        <li><strong>Business Continuity:</strong> All departments involved</li>
                        <li><strong>Data Breach:</strong> IT, Legal, Customer Service, Marketing</li>
                        <li><strong>Regulatory Changes:</strong> Legal, Finance, Operations</li>
                        <li><strong>Major System Outage:</strong> IT, Operations, Customer Service</li>
                    </ul>
                </div>

                <!-- 4. Risk Identification -->
                <div id="identification" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-search"></i> 4. RISK IDENTIFICATION</div>
                    
                    <h4>4.1 When to Report a Risk</h4>
                    <p>Risks should be reported when:</p>
                    <ul>
                        <li>A new risk is identified that could impact business objectives</li>
                        <li>An existing risk has changed in nature or severity</li>
                        <li>A risk event has occurred or is imminent</li>
                        <li>Regular risk reviews identify emerging risks</li>
                        <li>Department-specific risk indicators are triggered</li>
                    </ul>
                    
                    <h4>4.2 Risk Identification Process</h4>
                    <ol>
                        <li><strong>Identify the Risk:</strong> Clearly describe what could go wrong</li>
                        <li><strong>Select Department:</strong> Choose the primary affected department (triggers auto-assignment)</li>
                        <li><strong>Determine Risk Type:</strong> Classify as "Existing" or "New" risk</li>
                        <li><strong>Board Reporting:</strong> Determine if the risk requires Board attention</li>
                        <li><strong>Document Details:</strong> Complete all required fields in the risk register</li>
                    </ol>
                    
                    <div class="field-definition">
                        <div class="field-name">Risk Name</div>
                        <div class="field-description">A clear, concise title that describes the risk (e.g., "Customer Data Breach", "Regulatory Non-Compliance")</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Risk Description</div>
                        <div class="field-description">Detailed explanation of the risk, including what could happen and potential consequences</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Cause of Risk</div>
                        <div class="field-description">Root causes or factors that could trigger the risk event</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Department</div>
                        <div class="field-description">Primary department affected by the risk - this determines automatic assignment to the appropriate risk owner</div>
                    </div>
                </div>

                <!-- 5. Risk Assessment -->
                <div id="assessment" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-calculator"></i> 5. RISK ASSESSMENT</div>
                    
                    <h4>5.1 Two-Stage Assessment</h4>
                    <p>All risks must be assessed at two levels:</p>
                    
                    <h5>Inherent Risk (Gross Risk)</h5>
                    <p>The risk level before considering any controls or mitigation measures currently in place.</p>
                    
                    <h5>Residual Risk (Net Risk)</h5>
                    <p>The risk level after considering existing controls and mitigation measures.</p>
                    
                    <h4>5.2 Assessment Criteria</h4>
                    
                    <h5>Likelihood Scale (1-5)</h5>
                    <ul>
                        <li><strong>1 - Very Low:</strong> Rare occurrence, less than 5% chance</li>
                        <li><strong>2 - Low:</strong> Unlikely to occur, 5-25% chance</li>
                        <li><strong>3 - Medium:</strong> Possible occurrence, 25-50% chance</li>
                        <li><strong>4 - High:</strong> Likely to occur, 50-75% chance</li>
                        <li><strong>5 - Very High:</strong> Almost certain, more than 75% chance</li>
                    </ul>
                    
                    <h5>Consequence Scale (1-5)</h5>
                    <ul>
                        <li><strong>1 - Very Low:</strong> Minimal impact on operations, reputation, or finances</li>
                        <li><strong>2 - Low:</strong> Minor impact, easily manageable</li>
                        <li><strong>3 - Medium:</strong> Moderate impact requiring management attention</li>
                        <li><strong>4 - High:</strong> Significant impact affecting business operations</li>
                        <li><strong>5 - Very High:</strong> Severe impact threatening business continuity</li>
                    </ul>
                    
                    <h4>5.3 Risk Rating Calculation</h4>
                    <p>Risk Rating = Likelihood × Consequence</p>
                    <p>This produces a score from 1-25, categorized as:</p>
                    <ul>
                        <li><strong>Low Risk:</strong> 1-3 (Green)</li>
                        <li><strong>Medium Risk:</strong> 4-8 (Yellow)</li>
                        <li><strong>High Risk:</strong> 9-14 (Orange)</li>
                        <li><strong>Critical Risk:</strong> 15-25 (Red)</li>
                    </ul>
                    
                    <h4>5.4 Department-Specific Assessment Guidelines</h4>
                    <p>Each department may have specific criteria for assessing risks within their domain. Risk owners should consider department-specific impact factors when conducting assessments.</p>
                </div>

                <!-- 6. Risk Treatment -->
                <div id="treatment" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-shield-alt"></i> 6. RISK TREATMENT</div>
                    
                    <h4>6.1 Treatment Strategies</h4>
                    <ul>
                        <li><strong>Accept:</strong> Acknowledge the risk and take no further action</li>
                        <li><strong>Avoid:</strong> Eliminate the risk by changing processes or activities</li>
                        <li><strong>Mitigate:</strong> Reduce likelihood or consequence through controls</li>
                        <li><strong>Transfer:</strong> Share or transfer risk through insurance or contracts</li>
                    </ul>
                    
                    <h4>6.2 Required Documentation</h4>
                    
                    <div class="field-definition">
                        <div class="field-name">Treatment Action</div>
                        <div class="field-description">Specific actions to be taken to address the risk (Accept/Avoid/Mitigate/Transfer)</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Controls / Action Plan</div>
                        <div class="field-description">Detailed description of controls to be implemented or actions to be taken, including timelines and resources required</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Planned Completion Date</div>
                        <div class="field-description">Target date for completing risk treatment actions</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Risk Owner</div>
                        <div class="field-description">Individual responsible for managing the risk and implementing treatment actions (automatically assigned based on department)</div>
                    </div>
                </div>

                <!-- 7. Monitoring & Review -->
                <div id="monitoring" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-chart-bar"></i> 7. MONITORING & REVIEW</div>
                    
                    <h4>7.1 Ongoing Monitoring</h4>
                    <p>All risks must be regularly monitored and reviewed:</p>
                    <ul>
                        <li><strong>Critical Risks:</strong> Weekly review</li>
                        <li><strong>High Risks:</strong> Monthly review</li>
                        <li><strong>Medium Risks:</strong> Quarterly review</li>
                        <li><strong>Low Risks:</strong> Annual review</li>
                    </ul>
                    
                    <h4>7.2 Status Tracking</h4>
                    <ul>
                        <li><strong>Open:</strong> Risk identified, treatment not yet started</li>
                        <li><strong>In Progress:</strong> Treatment actions are being implemented</li>
                        <li><strong>Completed:</strong> Treatment actions completed, awaiting verification</li>
                        <li><strong>Overdue:</strong> Treatment actions past planned completion date</li>
                        <li><strong>Closed:</strong> Risk adequately treated or no longer relevant</li>
                    </ul>
                    
                    <div class="field-definition">
                        <div class="field-name">Progress Update / Report</div>
                        <div class="field-description">Regular updates on the status of risk treatment actions, including any changes to risk levels or new developments</div>
                    </div>
                    
                    <h4>7.3 Automated Reminders</h4>
                    <p>The system automatically sends reminders to risk owners:</p>
                    <ul>
                        <li>7 days before planned completion date</li>
                        <li>On the planned completion date</li>
                        <li>Weekly reminders for overdue items</li>
                        <li>Monthly summary reports to department heads</li>
                    </ul>
                </div>

                <!-- 8. Roles & Responsibilities -->
                <div id="roles" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-users-cog"></i> 8. ROLES & RESPONSIBILITIES</div>
                    
                    <h4>All Staff</h4>
                    <ul>
                        <li>Identify and report risks within their area of responsibility</li>
                        <li>Select appropriate department when reporting risks</li>
                        <li>Implement assigned risk treatment actions</li>
                        <li>Provide updates on risk status when requested</li>
                    </ul>
                    
                    <h4>Risk Owners (Department Heads)</h4>
                    <ul>
                        <li>Take ownership of automatically assigned risks</li>
                        <li>Respond to risk assignments within 24 hours</li>
                        <li>Develop and implement risk treatment plans</li>
                        <li>Provide regular updates on risk status</li>
                        <li>Escalate significant changes in risk levels</li>
                        <li>Manage department-specific risk registers</li>
                    </ul>
                    
                    <h4>Deputy Risk Owners</h4>
                    <ul>
                        <li>Act as backup when primary risk owner is unavailable</li>
                        <li>Support risk assessment and treatment activities</li>
                        <li>Maintain awareness of department risk profile</li>
                    </ul>
                    
                    <h4>Compliance Team</h4>
                    <ul>
                        <li>Maintain the central risk register</li>
                        <li>Monitor auto-assignment system performance</li>
                        <li>Facilitate risk assessment processes</li>
                        <li>Prepare risk reports for management</li>
                        <li>Monitor compliance with risk procedures</li>
                        <li>Manage system notifications and escalations</li>
                    </ul>
                    
                    <h4>Management</h4>
                    <ul>
                        <li>Review and approve risk treatment strategies</li>
                        <li>Allocate resources for risk management</li>
                        <li>Escalate significant risks to the Board</li>
                        <li>Ensure risk procedures are followed</li>
                        <li>Review department risk performance</li>
                    </ul>
                </div>

                <!-- 9. Risk Assessment Matrix -->
                <div id="matrix" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-table"></i> 9. RISK ASSESSMENT MATRIX</div>
                    
                    <table class="risk-matrix-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Likelihood</th>
                                <th colspan="5">Consequence</th>
                            </tr>
                            <tr>
                                <th>1 - Very Low</th>
                                <th>2 - Low</th>
                                <th>3 - Medium</th>
                                <th>4 - High</th>
                                <th>5 - Very High</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th>5 - Very High</th>
                                <td class="matrix-2">5</td>
                                <td class="matrix-3">10</td>
                                <td class="matrix-4">15</td>
                                <td class="matrix-5">20</td>
                                <td class="matrix-5">25</td>
                            </tr>
                            <tr>
                                <th>4 - High</th>
                                <td class="matrix-1">4</td>
                                <td class="matrix-2">8</td>
                                <td class="matrix-3">12</td>
                                <td class="matrix-4">16</td>
                                <td class="matrix-5">20</td>
                            </tr>
                            <tr>
                                <th>3 - Medium</th>
                                <td class="matrix-1">3</td>
                                <td class="matrix-2">6</td>
                                <td class="matrix-3">9</td>
                                <td class="matrix-3">12</td>
                                <td class="matrix-4">15</td>
                            </tr>
                            <tr>
                                <th>2 - Low</th>
                                <td class="matrix-1">2</td>
                                <td class="matrix-1">4</td>
                                <td class="matrix-2">6</td>
                                <td class="matrix-2">8</td>
                                <td class="matrix-3">10</td>
                            </tr>
                            <tr>
                                <th>1 - Very Low</th>
                                <td class="matrix-1">1</td>
                                <td class="matrix-1">2</td>
                                <td class="matrix-1">3</td>
                                <td class="matrix-1">4</td>
                                <td class="matrix-2">5</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 1rem;">
                        <p><strong>Risk Level Legend:</strong></p>
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                            <span class="matrix-1" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Low (1-3)</span>
                            <span class="matrix-2" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Medium (4-8)</span>
                            <span class="matrix-3" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">High (9-14)</span>
                            <span class="matrix-5" style="padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Critical (15-25)</span>
                        </div>
                    </div>
                </div>

                <!-- 10. Field Definitions -->
                <div id="definitions" class="procedure-section">
                    <div class="procedure-title"><i class="fas fa-book"></i> 10. COMPLETE FIELD DEFINITIONS</div>
                    
                    <h4>Risk Identification Fields</h4>
                    
                    <div class="field-definition">
                        <div class="field-name">Date of Risk Entry</div>
                        <div class="field-description">Automatically populated with the date the risk is entered into the system</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Department</div>
                        <div class="field-description">
                            Primary department affected by the risk. This selection triggers automatic assignment to the appropriate risk owner:
                            <br><strong>Available Options:</strong> Finance & Accounting, Operations, Information Technology, Human Resources, Marketing & Sales, Legal & Compliance, Customer Service, Network & Infrastructure
                        </div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Existing or New Risk</div>
                        <div class="field-description">
                            <strong>Existing:</strong> Risk was previously identified but requires updating<br>
                            <strong>New:</strong> Risk is being reported for the first time
                        </div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">To be Reported to Board</div>
                        <div class="field-description">
                            <strong>Yes:</strong> Risk is significant enough to require Board attention (automatically escalated)<br>
                            <strong>No:</strong> Risk can be managed at operational level
                        </div>
                    </div>
                    
                    <h4>Assessment Fields</h4>
                    
                    <div class="field-definition">
                        <div class="field-name">Inherent Likelihood (L)</div>
                        <div class="field-description">Probability of the risk occurring without any controls (1-5 scale)</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Inherent Consequence (C)</div>
                        <div class="field-description">Impact if the risk occurs without any controls (1-5 scale)</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Inherent Rating</div>
                        <div class="field-description">Automatically calculated: Inherent Likelihood × Inherent Consequence</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Residual Likelihood (L)</div>
                        <div class="field-description">Probability of the risk occurring with current controls in place (1-5 scale)</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Residual Consequence (C)</div>
                        <div class="field-description">Impact if the risk occurs with current controls in place (1-5 scale)</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Residual Rating</div>
                        <div class="field-description">Automatically calculated: Residual Likelihood × Residual Consequence</div>
                    </div>
                    
                    <h4>Auto-Assignment Fields</h4>
                    
                    <div class="field-definition">
                        <div class="field-name">Assigned Risk Owner</div>
                        <div class="field-description">Automatically populated based on selected department. Shows the name and contact details of the assigned risk owner.</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Assignment Date</div>
                        <div class="field-description">Automatically populated with the date and time the risk was assigned to the risk owner.</div>
                    </div>
                    
                    <div class="field-definition">
                        <div class="field-name">Response Due Date</div>
                        <div class="field-description">Automatically calculated as 24 hours from assignment date for initial response requirement.</div>
                    </div>
                </div>
                
                <div class="alert alert-info" style="margin-top: 2rem;">
                    <h4><i class="fas fa-question-circle"></i> Need Help?</h4>
                    <p>If you need assistance with risk reporting or have questions about these procedures, contact the Compliance Team or your Department Risk Owner.</p>
                    <p><strong>Remember:</strong> Early identification and reporting of risks is crucial for effective risk management. The auto-assignment system ensures immediate attention to your reported risks.</p>
                    
                    <h5>Quick Contact Information:</h5>
                    <ul>
                        <li><strong>Compliance Team:</strong> compliance@airtel.africa</li>
                        <li><strong>Risk Management Hotline:</strong> ext. 2345</li>
                        <li><strong>Emergency Risk Reporting:</strong> Available 24/7 through the system</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add the navbar JavaScript functions
        function toggleNavNotifications() {
            const dropdown = document.getElementById('navNotificationDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }

        function markNavAsRead(notificationId) {
            const notification = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
            if (notification) {
                notification.style.opacity = '0.6';
                notification.style.borderLeftColor = '#28a745';
            }
        }

        function markAllNavAsRead() {
            const notifications = document.querySelectorAll('.nav-notification-item');
            notifications.forEach(notification => {
                notification.style.opacity = '0.6';
                notification.style.borderLeftColor = '#28a745';
            });
        }

        function expandNavNotifications() {
            window.open('notifications.php', '_blank');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('navNotificationDropdown');
            const container = document.querySelector('.nav-notification-container');
            
            if (dropdown && container && !container.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Smooth scrolling for table of contents links
        document.querySelectorAll('.toc a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href').substring(1);
                const targetElement = document.getElementById(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>
</body>
</html>
