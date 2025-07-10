<?php
// Enhanced Shared Notifications Component with Persistent State
// This file should be included in all pages that need notifications

function getNotifications($db, $user_id) {
    $all_notifications = [];

    // 1. Treatment assignments - Check if table exists first
    try {
        $check_table = "SHOW TABLES LIKE 'risk_treatments'";
        $stmt = $db->prepare($check_table);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            // Check if treatment_name column exists
            $check_column = "SHOW COLUMNS FROM risk_treatments LIKE 'treatment_name'";
            $stmt = $db->prepare($check_column);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $treatment_query = "SELECT rt.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                       owner.full_name as risk_owner_name, 'treatment' as notification_type,
                                       rt.created_at as notification_date, rt.treatment_name
                                FROM risk_treatments rt
                                INNER JOIN risk_incidents ri ON rt.risk_id = ri.id
                                LEFT JOIN users owner ON ri.risk_owner_id = owner.id
                                WHERE rt.assigned_to = :user_id
                                 AND rt.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

                $stmt = $db->prepare($treatment_query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $treatment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $all_notifications = array_merge($all_notifications, $treatment_notifications);
            }
        }
    } catch (PDOException $e) {
        // Table doesn't exist or other error, skip treatment notifications
    }

    // 2. Risk assignments - Check both risk_assignments table and risk_incidents table
    try {
        // First check risk_assignments table
        $assignment_query = "SELECT ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                   reporter.full_name as reporter_name, 'assignment' as notification_type,
                                   ra.assignment_date as notification_date, ra.status as assignment_status
                            FROM risk_assignments ra
                            INNER JOIN risk_incidents ri ON ra.risk_id = ri.id
                            LEFT JOIN users reporter ON ri.reported_by = reporter.id
                            WHERE ra.assigned_to = :user_id
                             AND ra.assignment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             AND ra.status = 'Pending'";

        $stmt = $db->prepare($assignment_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Also check for direct assignments in risk_incidents table (for backward compatibility)
        $direct_assignment_query = "SELECT ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                          reporter.full_name as reporter_name, 'assignment' as notification_type,
                                          ri.updated_at as notification_date, 'Pending' as assignment_status
                                   FROM risk_incidents ri
                                   LEFT JOIN users reporter ON ri.reported_by = reporter.id
                                   WHERE ri.risk_owner_id = :user_id
                                    AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                    AND ri.updated_at != ri.created_at
                                    AND NOT EXISTS (
                                        SELECT 1 FROM risk_assignments ra 
                                        WHERE ra.risk_id = ri.id AND ra.assigned_to = :user_id
                                    )";

        $stmt = $db->prepare($direct_assignment_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $direct_assignment_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $all_notifications = array_merge($all_notifications, $assignment_notifications, $direct_assignment_notifications);
    } catch (PDOException $e) {
        // Skip assignment notifications if error
        error_log("Assignment notification error: " . $e->getMessage());
    }

    // 3. Risk updates
    try {
        $update_query = "SELECT ri.*, ri.risk_name, ri.id as risk_id, ri.risk_owner_id,
                                updater.full_name as updater_name, 'update' as notification_type,
                                ri.updated_at as notification_date
                         FROM risk_incidents ri
                         LEFT JOIN users updater ON ri.reported_by = updater.id
                         WHERE (ri.risk_owner_id = :user_id OR ri.reported_by = :user_id)
                         AND ri.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         AND ri.updated_at != ri.created_at";

        $stmt = $db->prepare($update_query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $update_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_notifications = array_merge($all_notifications, $update_notifications);
    } catch (PDOException $e) {
        // Skip update notifications if error
    }

    // 4. Chat messages (new notifications)
    try {
        $check_table = "SHOW TABLES LIKE 'chat_messages'";
        $stmt = $db->prepare($check_table);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $chat_query = "SELECT cm.*, u.full_name as sender_name, 'chat' as notification_type,
                                  cm.created_at as notification_date, cm.message as chat_message
                           FROM chat_messages cm
                           LEFT JOIN users u ON cm.user_id = u.id
                           WHERE cm.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                           AND cm.user_id != :user_id
                           ORDER BY cm.created_at DESC
                           LIMIT 10";

            $stmt = $db->prepare($chat_query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $chat_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $all_notifications = array_merge($all_notifications, $chat_notifications);
        }
    } catch (PDOException $e) {
        // Chat table doesn't exist yet, skip chat notifications
    }

    // Sort by date (newest first)
    usort($all_notifications, function($a, $b) {
        return strtotime($b['notification_date']) - strtotime($a['notification_date']);
    });

    return array_slice($all_notifications, 0, 20);
}

function renderNotificationBar($notifications) {
    $notification_count = count($notifications);
    
    if (!empty($notifications)): ?>
        <div class="nav-notification-container" onclick="toggleNavNotifications()">
            <i class="fas fa-bell nav-notification-bell" id="notificationBell"></i>
            <span class="nav-notification-text">Notifications</span>
            <span class="nav-notification-badge" id="notificationBadge" style="display: none;"><?php echo $notification_count; ?></span>

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
                    <div id="noNotificationsMessage" style="padding: 2rem; text-align: center; color: #666; display: none;">
                        <div style="font-size: 2rem; margin-bottom: 1rem;">🔔</div>
                        <h4>No New Notifications</h4>
                        <p>All notifications have been read or cleared.</p>
                    </div>
                    <?php foreach ($notifications as $index => $notification): ?>
                    <div class="nav-notification-item" data-nav-notification-id="<?php echo $index; ?>" data-notification-key="<?php echo md5($notification['notification_type'] . '_' . ($notification['risk_id'] ?? $notification['id']) . '_' . $notification['notification_date']); ?>">
                        <?php if ($notification['notification_type'] == 'treatment'): ?>
                            <div class="nav-notification-title">
                                🎯 Treatment Assignment: <?php echo htmlspecialchars($notification['treatment_name'] ?? 'Treatment Task'); ?>
                            </div>
                            <div class="nav-notification-risk">
                                Risk: <?php echo htmlspecialchars($notification['risk_name']); ?>
                            </div>
                            <div class="nav-notification-date">
                                Assigned: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                            </div>
                            <div class="nav-notification-actions">
                                <a href="view_risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-primary">View Risk</a>
                                <a href="manage-risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-warning">Manage Treatment</a>
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
                                <a href="manage-risk.php?id=<?php echo $notification['risk_id']; ?>" class="btn btn-sm btn-success">Start Assessment</a>
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
                        <?php elseif ($notification['notification_type'] == 'chat'): ?>
                            <div class="nav-notification-title">
                                💬 New Chat Message
                            </div>
                            <div class="nav-notification-risk">
                                From: <?php echo htmlspecialchars($notification['sender_name'] ?? 'Unknown User'); ?>
                            </div>
                            <div class="nav-notification-message">
                                <?php echo htmlspecialchars(substr($notification['chat_message'], 0, 100)) . (strlen($notification['chat_message']) > 100 ? '...' : ''); ?>
                            </div>
                            <div class="nav-notification-date">
                                Sent: <?php echo date('M j, Y g:i A', strtotime($notification['notification_date'])); ?>
                            </div>
                            <div class="nav-notification-actions">
                                <button onclick="openChat()" class="btn btn-sm btn-primary">View Chat</button>
                                <button onclick="markNavAsRead(<?php echo $index; ?>)" class="btn btn-sm btn-secondary mark-read-btn">Mark Read</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Enhanced Persistent Notification State Script -->
        <script>
            // Initialize notification state on page load
            document.addEventListener('DOMContentLoaded', function() {
                initializeNotificationState();
            });
            
            function initializeNotificationState() {
                const userId = '<?php echo $_SESSION['user_id']; ?>';
                const readNotifications = getReadNotifications(userId);
                const clearedNotifications = getClearedNotifications(userId);
                const totalNotifications = <?php echo $notification_count; ?>;
                let visibleCount = totalNotifications;
                
                // Check if all notifications were cleared
                if (clearedNotifications) {
                    // Hide all notifications
                    document.querySelectorAll('.nav-notification-item').forEach(notification => {
                        notification.style.display = 'none';
                    });
                    visibleCount = 0;
                    showNoNotificationsMessage();
                } else {
                    // Apply individual read states
                    readNotifications.forEach(notificationKey => {
                        const notification = document.querySelector(`[data-notification-key="${notificationKey}"]`);
                        if (notification) {
                            notification.style.display = 'none';
                            visibleCount--;
                        }
                    });
                    
                    // Show "no notifications" message if all are hidden
                    if (visibleCount === 0) {
                        showNoNotificationsMessage();
                    }
                }
                
                // Update badge and bell
                updateNotificationBadge(visibleCount);
            }
            
            function getReadNotifications(userId) {
                const stored = localStorage.getItem(`readNotifications_${userId}`);
                return stored ? JSON.parse(stored) : [];
            }
            
            function saveReadNotification(userId, notificationKey) {
                const readNotifications = getReadNotifications(userId);
                if (!readNotifications.includes(notificationKey)) {
                    readNotifications.push(notificationKey);
                    localStorage.setItem(`readNotifications_${userId}`, JSON.stringify(readNotifications));
                }
            }
            
            function getClearedNotifications(userId) {
                return localStorage.getItem(`clearedNotifications_${userId}`) === 'true';
            }
            
            function setClearedNotifications(userId, cleared) {
                localStorage.setItem(`clearedNotifications_${userId}`, cleared.toString());
                if (cleared) {
                    // Also clear individual read notifications when clearing all
                    localStorage.removeItem(`readNotifications_${userId}`);
                }
            }
            
            function updateNotificationBadge(count) {
                const badge = document.getElementById('notificationBadge');
                const bell = document.getElementById('notificationBell');
                
                if (badge && bell) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'flex';
                        bell.classList.add('has-notifications');
                    } else {
                        badge.style.display = 'none';
                        bell.classList.remove('has-notifications');
                    }
                }
            }
            
            function showNoNotificationsMessage() {
                const noNotificationsMsg = document.getElementById('noNotificationsMessage');
                if (noNotificationsMsg) {
                    noNotificationsMsg.style.display = 'block';
                }
            }
            
            function hideNoNotificationsMessage() {
                const noNotificationsMsg = document.getElementById('noNotificationsMessage');
                if (noNotificationsMsg) {
                    noNotificationsMsg.style.display = 'none';
                }
            }
            
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
                const userId = '<?php echo $_SESSION['user_id']; ?>';
                const notification = document.querySelector(`[data-nav-notification-id="${notificationId}"]`);
                
                if (notification) {
                    const notificationKey = notification.getAttribute('data-notification-key');
                    
                    // Save to localStorage
                    saveReadNotification(userId, notificationKey);
                    
                    // Hide notification
                    notification.style.display = 'none';
                    
                    // Update badge count
                    const currentBadge = document.getElementById('notificationBadge');
                    if (currentBadge) {
                        const currentCount = parseInt(currentBadge.textContent) || 0;
                        const newCount = Math.max(0, currentCount - 1);
                        updateNotificationBadge(newCount);
                        
                        // Show "no notifications" message if all are hidden
                        if (newCount === 0) {
                            showNoNotificationsMessage();
                        }
                    }
                }
            }
            
            function readAllNotifications() {
                const userId = '<?php echo $_SESSION['user_id']; ?>';
                const notifications = document.querySelectorAll('.nav-notification-item:not([style*="display: none"])');
                
                notifications.forEach(notification => {
                    const notificationKey = notification.getAttribute('data-notification-key');
                    saveReadNotification(userId, notificationKey);
                    notification.style.display = 'none';
                });
                
                updateNotificationBadge(0);
                showNoNotificationsMessage();
            }
            
            function clearAllNotifications() {
                const userId = '<?php echo $_SESSION['user_id']; ?>';
                
                // Mark all as cleared in localStorage
                setClearedNotifications(userId, true);
                
                // Hide all notifications
                const notifications = document.querySelectorAll('.nav-notification-item');
                notifications.forEach(notification => {
                    notification.style.display = 'none';
                });
                
                // Close dropdown
                const dropdown = document.getElementById('navNotificationDropdown');
                if (dropdown) {
                    dropdown.classList.remove('show');
                }
                
                updateNotificationBadge(0);
                showNoNotificationsMessage();
            }
            
            function openChat() {
                // Open chat interface - you can customize this
                alert('Chat feature coming soon! This will open the chat interface.');
            }
            
            // Reset cleared state when new notifications arrive (optional)
            function resetClearedState() {
                const userId = '<?php echo $_SESSION['user_id']; ?>';
                setClearedNotifications(userId, false);
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
    <?php else: ?>
        <div class="nav-notification-container nav-notification-empty">
            <i class="fas fa-bell nav-notification-bell"></i>
            <span class="nav-notification-text">Notifications</span>
        </div>
    <?php endif;
}

// Enhanced JavaScript functions for persistent notifications
function renderNotificationJavaScript() {
    // This function is no longer needed as the JavaScript is now inline
    // Keeping it for backward compatibility
    return '';
}
?>
