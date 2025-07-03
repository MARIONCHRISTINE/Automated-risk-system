<?php
// Get notifications for the navbar
if (isset($_SESSION['user_id'])) {
    require_once 'config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
    
    $treatment_query = "SELECT rt.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                               owner.full_name as risk_owner_name, 'treatment' as notification_type,
                               rt.created_at as notification_date
                        FROM risk_treatments rt
                        INNER JOIN risk_incidents ri ON rt.risk_id = ri.id
                        LEFT JOIN users owner ON ri.risk_owner_id = owner.id
                        WHERE rt.assigned_to = :user_id 
                        AND rt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $assignment_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                reporter.full_name as reporter_name, 'assignment' as notification_type,
                                ri.updated_at as notification_date
                         FROM risk_incidents ri
                         LEFT JOIN users reporter ON ri.reported_by = reporter.id
                         WHERE ri.risk_owner_id = :user_id 
                         AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $update_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                            updater.full_name as updater_name, 'update' as notification_type,
                            ri.updated_at as notification_date
                     FROM risk_incidents ri
                     LEFT JOIN users updater ON ri.reported_by = updater.id
                     WHERE (ri.risk_owner_id = :user_id OR ri.reported_by = :user_id)
                     AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     AND ri.updated_at != ri.created_at";
    
    $all_notifications = [];
    
    $stmt = $conn->prepare($treatment_query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $treatment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $treatment_notifications);
    
    $stmt = $conn->prepare($assignment_query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $assignment_notifications);
    
    $stmt = $conn->prepare($update_query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $update_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $update_notifications);
    
    usort($all_notifications, function($a, $b) {
        return strtotime($b['notification_date']) - strtotime($a['notification_date']);
    });
    
    $all_notifications = array_slice($all_notifications, 0, 20);
}
?>

<style>
/* Notification Dropdown Styles */
.nav-notification-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    width: 400px;
    max-width: 90vw;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    z-index: 1050;
    max-height: 500px;
    overflow: hidden;
}

.nav-notification-dropdown.show {
    display: block;
}

.nav-notification-header {
    padding: 1rem;
    border-bottom: 1px solid #dee2e6;
    background: #f8f9fa;
    border-radius: 0.5rem 0.5rem 0 0;
}

.nav-notification-header .flex {
    display: flex;
}

.nav-notification-header .justify-between {
    justify-content: space-between;
}

.nav-notification-header .items-center {
    align-items: center;
}

.nav-notification-content {
    max-height: 350px;
    overflow-y: auto;
    padding: 0.5rem;
}

.nav-notification-item {
    padding: 1rem;
    border-bottom: 1px solid #f1f3f4;
    background: white;
    border-left: 4px solid #E60012;
    margin-bottom: 0.5rem;
    border-radius: 0.25rem;
    transition: all 0.3s;
}

.nav-notification-item:hover {
    background: #f8f9fa;
}

.nav-notification-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.nav-notification-risk {
    color: #666;
    font-size: 0.85rem;
    margin-bottom: 0.25rem;
}

.nav-notification-date {
    color: #999;
    font-size: 0.8rem;
    margin-bottom: 0.75rem;
}

.nav-notification-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.nav-notification-footer {
    padding: 0.75rem 1rem;
    border-top: 1px solid #dee2e6;
    background: #f8f9fa;
    border-radius: 0 0 0.5rem 0.5rem;
}

.nav-notification-footer .flex {
    display: flex;
}

.nav-notification-footer .justify-between {
    justify-content: space-between;
}

.nav-notification-footer .items-center {
    align-items: center;
}

/* Button styles for notifications */
.btn {
    padding: 0.375rem 0.75rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.btn-primary {
    background: #E60012;
    color: white;
    border-color: #E60012;
}

.btn-primary:hover {
    background: #B8000E;
    border-color: #B8000E;
    color: white;
    text-decoration: none;
}

.btn-success {
    background: #28a745;
    color: white;
    border-color: #28a745;
}

.btn-success:hover {
    background: #218838;
    border-color: #218838;
    color: white;
    text-decoration: none;
}

.btn-warning {
    background: #ffc107;
    color: #212529;
    border-color: #ffc107;
}

.btn-warning:hover {
    background: #e0a800;
    border-color: #e0a800;
    color: #212529;
    text-decoration: none;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border-color: #6c757d;
}

.btn-secondary:hover {
    background: #545b62;
    border-color: #545b62;
    color: white;
    text-decoration: none;
}

.btn-outline {
    background: transparent;
    color: #6c757d;
    border-color: #6c757d;
}

.btn-outline:hover {
    background: #6c757d;
    color: white;
    text-decoration: none;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .nav-notification-dropdown {
        width: 350px;
        right: -50px;
    }
    
    .nav-notification-actions {
        flex-direction: column;
    }
    
    .nav-notification-actions .btn {
        width: 100%;
        text-align: center;
    }
}
</style>

<nav class="nav">
    <div class="nav-content">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="risk_owner_dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk_owner_dashboard.php' ? 'active' : ''; ?>">
                    🏠 Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="report_risk.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'report_risk.php' ? 'active' : ''; ?>">
                    📝 Report Risk
                </a>
            </li>
            <li class="nav-item">
                <a href="risk_owner_dashboard.php?tab=my-reports" class="<?php echo isset($_GET['tab']) && $_GET['tab'] == 'my-reports' ? 'active' : ''; ?>">
                    👀 My Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="risk-procedures.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'risk-procedures.php' ? 'active' : ''; ?>" target="_blank">
                    📋 Procedures
                </a>
            </li>
            <li class="nav-item notification-nav-item">
                <?php if (isset($_SESSION['user_id']) && !empty($all_notifications)): ?>
                <div class="nav-notification-container" onclick="toggleNavNotifications()">
                    <i class="fas fa-bell nav-notification-bell"></i>
                    <span class="nav-notification-text">Notifications</span>
                    <span class="nav-notification-badge"><?php echo count($all_notifications); ?></span>
                    
                    <div class="nav-notification-dropdown" id="navNotificationDropdown">
                        <div class="nav-notification-header">
                            <div class="flex justify-between items-center">
                                <span><i class="fas fa-bell"></i> All Notifications</span>
                                <button onclick="markAllNavAsRead()" class="btn btn-sm btn-outline">Mark All Read</button>
                            </div>
                        </div>
                        <div class="nav-notification-content" id="navNotificationContent">
                            <?php foreach ($all_notifications as $index => $notification): ?>
                            <div class="nav-notification-item" data-nav-notification-id="<?php echo $index; ?>">
                                <?php if ($notification['notification_type'] == 'treatment'): ?>
                                    <div class="nav-notification-title">
                                        🎯 Treatment Assignment: <?php echo htmlspecialchars($notification['treatment_name']); ?>
                                    </div>
                                    <div class="nav-notification-risk">
                                        Risk: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                    </div>
                                    <div class="nav-notification-date">
                                        Assigned: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                    </div>
                                    <div class="nav-notification-actions">
                                        <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Risk</a>
                                        <a href="advanced-risk-management.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-warning">Manage Treatment</a>
                                        <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
                                    </div>
                                <?php elseif ($notification['notification_type'] == 'assignment'): ?>
                                    <div class="nav-notification-title">
                                        📋 Risk Assignment: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                    </div>
                                    <div class="nav-notification-risk">
                                        Reported by: <?php echo htmlspecialchars($notification['reporter_name'] ?? 'System'); ?>
                                    </div>
                                    <div class="nav-notification-date">
                                        Assigned: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                    </div>
                                    <div class="nav-notification-actions">
                                        <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Details</a>
                                        <a href="risk_assessment.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-success">Start Assessment</a>
                                        <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
                                    </div>
                                <?php elseif ($notification['notification_type'] == 'update'): ?>
                                    <div class="nav-notification-title">
                                        🔄 Risk Update: <?php echo htmlspecialchars($notification['risk_name']); ?>
                                    </div>
                                    <div class="nav-notification-risk">
                                        Updated by: <?php echo htmlspecialchars($notification['updater_name'] ?? 'System'); ?>
                                    </div>
                                    <div class="nav-notification-date">
                                        Updated: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                                    </div>
                                    <div class="nav-notification-actions">
                                        <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Changes</a>
                                        <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary">Mark Read</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="nav-notification-footer">
                            <div class="flex justify-between items-center">
                                <button onclick="expandNavNotifications()" class="btn btn-sm btn-primary" id="navExpandButton">
                                    <i class="fas fa-expand-arrows-alt"></i> Expand View
                                </button>
                                <a href="notifications.php" class="btn btn-sm btn-outline">View All</a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="nav-notification-container nav-notification-empty">
                    <i class="fas fa-bell nav-notification-bell"></i>
                    <span class="nav-notification-text">Notifications</span>
                </div>
                <?php endif; ?>
            </li>
        </ul>
    </div>
</nav>
