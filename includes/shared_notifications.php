<?php
// Shared Notifications Component with Persistent State
// This file should be included in both risk_owner_dashboard.php and report_risk.php

function getNotifications($db, $user_id) {
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

    $stmt = $db->prepare($treatment_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $treatment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $treatment_notifications);

    $stmt = $db->prepare($assignment_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $assignment_notifications);

    $stmt = $db->prepare($update_query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $update_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $all_notifications = array_merge($all_notifications, $update_notifications);

    usort($all_notifications, function($a, $b) {
        return strtotime($b['notification_date']) - strtotime($a['notification_date']);
    });

    return array_slice($all_notifications, 0, 20);
}

function renderNotificationBar($notifications) {
    // Get persistent notification state from localStorage via JavaScript
    $notification_count = count($notifications);
    
    if (!empty($notifications)): ?>
        <div class="nav-notification-container" onclick="toggleNavNotifications()">
            <i class="fas fa-bell nav-notification-bell has-notifications"></i>
            <span class="nav-notification-text">Notifications</span>
            <span class="nav-notification-badge" id="notificationBadge"><?php echo $notification_count; ?></span>

            <div class="nav-notification-dropdown" id="navNotificationDropdown">
                <div class="nav-notification-header">
                    <div class="flex justify-between items-center">
                        <span><i class="fas fa-bell"></i> All Notifications</span>
                        <div style="display: flex; gap: 0.5rem;">
                            <button onclick="readAllNotifications()" class="btn btn-sm btn-primary">Read All</button>
                            <button onclick="clearAllNotifications()" class="btn btn-sm btn-outline">Clear All</button>
                        </div>
                    </div>
                </div>
                <div class="nav-notification-content" id="navNotificationContent">
                    <?php foreach ($notifications as $index => $notification): ?>
                    <div class="nav-notification-item" data-nav-notification-id="<?php echo $index; ?>" data-notification-key="<?php echo md5($notification['notification_type'] . '_' . $notification['risk_id'] . '_' . $notification['notification_date']); ?>">
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
                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
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
                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
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
                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Persistent Notification State Script -->
        <script>
            // Initialize notification state on page load
            document.addEventListener('DOMContentLoaded', function() {
                initializeNotificationState();
            });
            
            function initializeNotificationState() {
                const readNotifications = getReadNotifications();
                const clearedNotifications = getClearedNotifications();
                let visibleCount = <?php echo $notification_count; ?>;
                
                // Apply read state
                readNotifications.forEach(notificationKey => {
                    const notification = document.querySelector(`[data-notification-key="${notificationKey}"]`);
                    if (notification) {
                        notification.classList.add('read');
                        notification.style.display = 'none';
                        visibleCount--;
                    }
                });
                
                // Apply cleared state
                if (clearedNotifications) {
                    document.querySelectorAll('.nav-notification-item').forEach(notification => {
                        notification.classList.add('read');
                        notification.style.display = 'none';
                    });
                    visibleCount = 0;
                }
                
                // Update badge
                updateNotificationBadge(visibleCount);
            }
            
            function getReadNotifications() {
                const stored = localStorage.getItem('readNotifications_<?php echo $_SESSION['user_id']; ?>');
                return stored ? JSON.parse(stored) : [];
            }
            
            function saveReadNotification(notificationKey) {
                const readNotifications = getReadNotifications();
                if (!readNotifications.includes(notificationKey)) {
                    readNotifications.push(notificationKey);
                    localStorage.setItem('readNotifications_<?php echo $_SESSION['user_id']; ?>', JSON.stringify(readNotifications));
                }
            }
            
            function getClearedNotifications() {
                return localStorage.getItem('clearedNotifications_<?php echo $_SESSION['user_id']; ?>') === 'true';
            }
            
            function setClearedNotifications(cleared) {
                localStorage.setItem('clearedNotifications_<?php echo $_SESSION['user_id']; ?>', cleared.toString());
            }
            
            function updateNotificationBadge(count) {
                const badge = document.getElementById('notificationBadge');
                const bell = document.querySelector('.nav-notification-bell');
                
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'flex';
                        if (bell) bell.classList.add('has-notifications');
                    } else {
                        badge.style.display = 'none';
                        if (bell) bell.classList.remove('has-notifications');
                    }
                }
            }
        </script>
    <?php else: ?>
        <div class="nav-notification-container nav-notification-empty">
            <i class="fas fa-bell nav-notification-bell"></i>
            <span class="nav-notification-text">Notifications</span>
        </div>
    <?php endif;
}

// Enhanced JavaScript functions for persistent notifications
function renderNotificationJavaScript() {
    ?>
    <script>
        // Enhanced notification functions with persistence
        function toggleNavNotifications() {
            const dropdown = document.getElementById('navNotificationDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
                
                // Position the dropdown
                const container = dropdown.parentElement;
                const rect = container.getBoundingClientRect();
                dropdown.style.top = (rect.bottom + 5) + 'px';
                dropdown.style.left = Math.max(10, rect.left - 200) + 'px';
            }
        }
        
        function markNavAsRead(notificationId) {
            const notification = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
            if (notification) {
                const notificationKey = notification.getAttribute('data-notification-key');
                
                // Save to localStorage
                saveReadNotification(notificationKey);
                
                // Hide notification
                notification.classList.add('read');
                notification.style.display = 'none';
                
                // Update badge count
                const currentBadge = document.getElementById('notificationBadge');
                if (currentBadge) {
                    const currentCount = parseInt(currentBadge.textContent) || 0;
                    const newCount = Math.max(0, currentCount - 1);
                    updateNotificationBadge(newCount);
                }
            }
        }
        
        function readAllNotifications() {
            const notifications = document.querySelectorAll('.nav-notification-item:not(.read)');
            
            notifications.forEach(notification => {
                const notificationKey = notification.getAttribute('data-notification-key');
                saveReadNotification(notificationKey);
                notification.classList.add('read');
                notification.style.display = 'none';
            });
            
            updateNotificationBadge(0);
        }
        
        function clearAllNotifications() {
            // Mark all as cleared
            setClearedNotifications(true);
            
            // Hide all notifications
            const notifications = document.querySelectorAll('.nav-notification-item');
            notifications.forEach(notification => {
                notification.classList.add('read');
                notification.style.display = 'none';
            });
            
            // Close dropdown
            const dropdown = document.getElementById('navNotificationDropdown');
            if (dropdown) {
                dropdown.classList.remove('show');
            }
            
            updateNotificationBadge(0);
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('navNotificationDropdown');
            const container = document.querySelector('.nav-notification-container');
            
            if (dropdown && container && !container.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
    </script>
    <?php
}
?>
