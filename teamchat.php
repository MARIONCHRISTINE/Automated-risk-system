<?php
include_once 'includes/auth.php';
requireRole('risk_owner');
include_once 'config/database.php';

// Verify session data exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['email'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get current user info
$user = getCurrentUser();

// Create enhanced chat tables
try {
    // Chat rooms table (enhanced)
    $createRoomsTable = "CREATE TABLE IF NOT EXISTS chat_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        type ENUM('group', 'private') DEFAULT 'group',
        description TEXT,
        created_by INT,
        is_archived TINYINT DEFAULT 0,
        archived_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )";
    $db->exec($createRoomsTable);

    // Chat messages table (enhanced with file support)
    $createMessagesTable = "CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT,
        sender_id INT,
        message TEXT,
        message_type ENUM('text', 'file', 'system') DEFAULT 'text',
        file_name VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        file_size INT NULL,
        file_type VARCHAR(100) NULL,
        is_deleted TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id)
    )";
    $db->exec($createMessagesTable);

    // Chat participants table (enhanced with read status)
    $createParticipantsTable = "CREATE TABLE IF NOT EXISTS chat_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT,
        user_id INT,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_message_read INT DEFAULT 0,
        is_archived TINYINT DEFAULT 0,
        FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE KEY unique_participant (room_id, user_id)
    )";
    $db->exec($createParticipantsTable);

    // Create uploads directory
    $uploadDir = 'uploads/chat/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Insert default chat rooms if they don't exist
    $checkRooms = "SELECT COUNT(*) as count FROM chat_rooms WHERE is_archived = 0";
    $stmt = $db->prepare($checkRooms);
    $stmt->execute();
    $roomCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    if ($roomCount == 0) {
        $defaultRooms = [
            ['name' => 'All Risk Owners', 'type' => 'group', 'description' => 'General discussion for all risk owners across departments'],
            ['name' => 'Risk Owners + Compliance', 'type' => 'group', 'description' => 'Communication channel with compliance team']
        ];

        foreach ($defaultRooms as $room) {
            $insertRoom = "INSERT INTO chat_rooms (name, type, description, created_by) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($insertRoom);
            $stmt->execute([$room['name'], $room['type'], $room['description'], $_SESSION['user_id']]);
        }
    }

} catch (PDOException $e) {
    // Tables might already exist, continue
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'send_message':
            $room_id = (int)$_POST['room_id'];
            $message = trim($_POST['message']);
            $message_type = 'text';
            $file_info = null;
            
            // Handle file upload
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/chat/';
                $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
                $maxSize = 10 * 1024 * 1024; // 10MB
                
                $fileInfo = pathinfo($_FILES['file']['name']);
                $extension = strtolower($fileInfo['extension']);
                
                if (!in_array($extension, $allowedTypes)) {
                    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
                    exit();
                }
                
                if ($_FILES['file']['size'] > $maxSize) {
                    echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
                    exit();
                }
                
                $fileName = uniqid() . '_' . time() . '.' . $extension;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                    $message_type = 'file';
                    $file_info = [
                        'name' => $_FILES['file']['name'],
                        'path' => $filePath,
                        'size' => $_FILES['file']['size'],
                        'type' => $_FILES['file']['type']
                    ];
                    
                    if (empty($message)) {
                        $message = 'Shared a file: ' . $_FILES['file']['name'];
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
                    exit();
                }
            }
            
            if (!empty($message) || $message_type === 'file') {
                $insertMessage = "INSERT INTO chat_messages (room_id, sender_id, message, message_type, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($insertMessage);
                $stmt->execute([
                    $room_id, 
                    $_SESSION['user_id'], 
                    $message, 
                    $message_type,
                    $file_info ? $file_info['name'] : null,
                    $file_info ? $file_info['path'] : null,
                    $file_info ? $file_info['size'] : null,
                    $file_info ? $file_info['type'] : null
                ]);
                
                // Update last seen
                $updateLastSeen = "UPDATE chat_participants SET last_seen = NOW() WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($updateLastSeen);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
            }
            exit();
            
        case 'get_messages':
            $room_id = (int)$_POST['room_id'];
            $last_message_id = isset($_POST['last_message_id']) ? (int)$_POST['last_message_id'] : 0;
            
            // Check if is_deleted column exists
            $checkColumn = "SHOW COLUMNS FROM chat_messages LIKE 'is_deleted'";
            $stmt = $db->prepare($checkColumn);
            $stmt->execute();
            $hasDeletedColumn = $stmt->fetch() !== false;
            
            if ($hasDeletedColumn) {
                $query = "SELECT cm.*, u.full_name, u.email 
                         FROM chat_messages cm 
                         JOIN users u ON cm.sender_id = u.id 
                         WHERE cm.room_id = ? AND cm.id > ? AND cm.is_deleted = 0
                         ORDER BY cm.created_at ASC";
            } else {
                $query = "SELECT cm.*, u.full_name, u.email 
                         FROM chat_messages cm 
                         JOIN users u ON cm.sender_id = u.id 
                         WHERE cm.room_id = ? AND cm.id > ?
                         ORDER BY cm.created_at ASC";
            }
            
            $stmt = $db->prepare($query);
            $stmt->execute([$room_id, $last_message_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update last message read
            if (!empty($messages)) {
                $lastMessageId = end($messages)['id'];
                $updateRead = "UPDATE chat_participants SET last_message_read = ?, last_seen = NOW() WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($updateRead);
                $stmt->execute([$lastMessageId, $room_id, $_SESSION['user_id']]);
            }
            
            echo json_encode(['success' => true, 'messages' => $messages]);
            exit();
            
        case 'create_private_chat':
            $other_user_id = (int)$_POST['other_user_id'];
            
            // Check if private chat already exists
            $checkExisting = "SELECT cr.id FROM chat_rooms cr
                             JOIN chat_participants cp1 ON cr.id = cp1.room_id
                             JOIN chat_participants cp2 ON cr.id = cp2.room_id
                             WHERE cr.type = 'private' AND cr.is_archived = 0
                             AND cp1.user_id = ? AND cp2.user_id = ?
                             AND cp1.is_archived = 0 AND cp2.is_archived = 0";
            $stmt = $db->prepare($checkExisting);
            $stmt->execute([$_SESSION['user_id'], $other_user_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo json_encode(['success' => true, 'room_id' => $existing['id']]);
            } else {
                // Get other user's name
                $getUserName = "SELECT full_name FROM users WHERE id = ?";
                $stmt = $db->prepare($getUserName);
                $stmt->execute([$other_user_id]);
                $otherUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Create new private chat
                $chatName = 'Private Chat with ' . $otherUser['full_name'];
                $insertRoom = "INSERT INTO chat_rooms (name, type, description, created_by) VALUES (?, 'private', ?, ?)";
                $stmt = $db->prepare($insertRoom);
                $stmt->execute([$chatName, 'Private conversation', $_SESSION['user_id']]);
                $room_id = $db->lastInsertId();
                
                // Add both participants
                $insertParticipant = "INSERT INTO chat_participants (room_id, user_id) VALUES (?, ?)";
                $stmt = $db->prepare($insertParticipant);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
                $stmt->execute([$room_id, $other_user_id]);
                
                echo json_encode(['success' => true, 'room_id' => $room_id]);
            }
            exit();
            
        case 'get_users':
            // Get all users except staff
            $query = "SELECT id, full_name, email, role, department 
                     FROM users 
                     WHERE role != 'staff' AND id != ? 
                     ORDER BY full_name";
            $stmt = $db->prepare($query);
            $stmt->execute([$_SESSION['user_id']]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'users' => $users]);
            exit();
            
        case 'archive_chat':
            $room_id = (int)$_POST['room_id'];
            
            // Archive for current user only (private chats) or mark as archived (group chats)
            if ($_POST['chat_type'] === 'private') {
                $archiveParticipant = "UPDATE chat_participants SET is_archived = 1 WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($archiveParticipant);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
            } else {
                // For group chats, just hide for this user
                $archiveParticipant = "UPDATE chat_participants SET is_archived = 1 WHERE room_id = ? AND user_id = ?";
                $stmt = $db->prepare($archiveParticipant);
                $stmt->execute([$room_id, $_SESSION['user_id']]);
            }
            
            echo json_encode(['success' => true]);
            exit();
            
        case 'get_unread_counts':
            // Get unread message counts for all chats
            $query = "SELECT cr.id as room_id, cr.name, cr.type,
                     COALESCE((SELECT COUNT(*) FROM chat_messages cm 
                              WHERE cm.room_id = cr.id 
                              AND cm.id > COALESCE(cp.last_message_read, 0)
                              AND cm.sender_id != ?), 0) as unread_count
                     FROM chat_rooms cr
                     JOIN chat_participants cp ON cr.id = cp.room_id
                     WHERE cp.user_id = ? AND cp.is_archived = 0 AND cr.is_archived = 0";
            $stmt = $db->prepare($query);
            $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
            $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'counts' => $counts]);
            exit();
    }
}

// Get chat rooms for current user (excluding archived)
$getRooms = "SELECT DISTINCT cr.*, 
             (SELECT COUNT(*) FROM chat_messages WHERE room_id = cr.id) as message_count,
             (SELECT message FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
             (SELECT created_at FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
             COALESCE((SELECT COUNT(*) FROM chat_messages cm 
                      WHERE cm.room_id = cr.id 
                      AND cm.id > COALESCE(cp.last_message_read, 0)
                      AND cm.sender_id != ?), 0) as unread_count
             FROM chat_rooms cr
             LEFT JOIN chat_participants cp ON cr.id = cp.room_id AND cp.user_id = ?
             WHERE (cr.type = 'group' OR cp.user_id = ?) 
             AND cr.is_archived = 0 
             AND (cp.is_archived = 0 OR cp.is_archived IS NULL)
             ORDER BY last_message_time DESC, cr.created_at DESC";
$stmt = $db->prepare($getRooms);
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$chat_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-join user to group chats if not already joined
foreach ($chat_rooms as $room) {
    if ($room['type'] === 'group') {
        $checkParticipant = "SELECT id FROM chat_participants WHERE room_id = ? AND user_id = ?";
        $stmt = $db->prepare($checkParticipant);
        $stmt->execute([$room['id'], $_SESSION['user_id']]);
        
        if (!$stmt->fetch()) {
            $insertParticipant = "INSERT INTO chat_participants (room_id, user_id) VALUES (?, ?)";
            $stmt = $db->prepare($insertParticipant);
            $stmt->execute([$room['id'], $_SESSION['user_id']]);
        }
    }
}

// Get private chats (excluding archived)
$getPrivateChats = "SELECT cr.*, cp2.user_id as other_user_id, u.full_name as other_user_name,
                   (SELECT COUNT(*) FROM chat_messages WHERE room_id = cr.id) as message_count,
                   (SELECT message FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM chat_messages WHERE room_id = cr.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
                   COALESCE((SELECT COUNT(*) FROM chat_messages cm 
                            WHERE cm.room_id = cr.id 
                            AND cm.id > COALESCE(cp1.last_message_read, 0)
                            AND cm.sender_id != ?), 0) as unread_count
                   FROM chat_rooms cr
                   JOIN chat_participants cp1 ON cr.id = cp1.room_id
                   JOIN chat_participants cp2 ON cr.id = cp2.room_id
                   JOIN users u ON cp2.user_id = u.id
                   WHERE cr.type = 'private' AND cp1.user_id = ? AND cp2.user_id != ?
                   AND cr.is_archived = 0 AND cp1.is_archived = 0
                   ORDER BY last_message_time DESC";
$stmt = $db->prepare($getPrivateChats);
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
$private_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Chat - Airtel Risk Management</title>
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
            height: 100vh;
            overflow: hidden;
            padding-top: 150px;
        }

        /* Header styles from dashboard */
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
            text-decoration: none;
            color: white;
        }

        /* Navigation styles from dashboard */
        .nav {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            position: fixed;
            top: 100px;
            left: 0;
            right: 0;
            z-index: 999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .nav-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }
        .nav-menu {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-item {
            margin: 0;
        }
        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            color: #6c757d;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            cursor: pointer;
        }
        .nav-item a:hover {
            color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
            text-decoration: none;
        }
        .nav-item a.active {
            color: #E60012;
            border-bottom-color: #E60012;
            background-color: rgba(230, 0, 18, 0.05);
        }

        .chat-container {
            display: flex;
            height: calc(100vh - 150px);
        }

        .sidebar {
            width: 320px;
            background: white;
            border-right: 1px solid #e1e5e9;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid #e1e5e9;
            background: #f8f9fa;
        }

        .sidebar-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .new-chat-btn {
            background: #E60012;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            width: 100%;
            transition: background 0.3s;
        }

        .new-chat-btn:hover {
            background: #B8000E;
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
        }

        .chat-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .chat-item:hover {
            background: #f8f9fa;
        }

        .chat-item.active {
            background: #e3f2fd;
            border-left: 4px solid #E60012;
        }

        .chat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .chat-icon.group {
            background: #e3f2fd;
            color: #1976d2;
        }

        .chat-icon.private {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-last-message {
            font-size: 0.85rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: none;
        }

        .chat-item:hover .chat-actions {
            display: flex;
            gap: 0.25rem;
        }

        .chat-action-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.75rem;
            color: #666;
            transition: all 0.3s;
        }

        .chat-action-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .unread-badge {
            background: #E60012;
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-left: auto;
        }

        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: white;
        }

        .chat-header {
            padding: 1rem;
            border-bottom: 1px solid #e1e5e9;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .chat-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-info h3 {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
        }

        .chat-header-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .chat-header-actions {
            display: flex;
            gap: 0.5rem;
        }

        .header-action-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.5rem;
            cursor: pointer;
            color: #666;
            transition: all 0.3s;
        }

        .header-action-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background: #fafafa;
        }

        .message {
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
        }

        .message.own {
            flex-direction: row-reverse;
        }

        .message-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #E60012;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .message.own .message-avatar {
            background: #28a745;
        }

        .message-content {
            max-width: 70%;
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .message.own .message-content {
            background: #E60012;
            color: white;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }

        .message-sender {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .message.own .message-sender {
            color: rgba(255,255,255,0.9);
        }

        .message-time {
            font-size: 0.75rem;
            color: #666;
        }

        .message.own .message-time {
            color: rgba(255,255,255,0.7);
        }

        .message-text {
            word-wrap: break-word;
            line-height: 1.4;
        }

        .message-file {
            background: rgba(0,0,0,0.1);
            padding: 0.5rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .message.own .message-file {
            background: rgba(255,255,255,0.2);
        }

        .file-icon {
            width: 30px;
            height: 30px;
            background: rgba(0,0,0,0.1);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-size {
            font-size: 0.75rem;
            opacity: 0.8;
        }

        .file-download {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: background 0.3s;
        }

        .file-download:hover {
            background: rgba(0,0,0,0.1);
        }

        .message-input-container {
            padding: 1rem;
            border-top: 1px solid #e1e5e9;
            background: white;
        }

        .message-input-form {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }

        .file-input-container {
            position: relative;
        }

        .file-input {
            display: none;
        }

        .file-input-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.3s;
        }

        .file-input-btn:hover {
            background: #e9ecef;
            color: #333;
        }

        .message-input {
            flex: 1;
            border: 2px solid #e1e5e9;
            border-radius: 20px;
            padding: 0.75rem 1rem;
            resize: none;
            max-height: 100px;
            min-height: 44px;
            font-family: inherit;
            font-size: 0.9rem;
        }

        .message-input:focus {
            outline: none;
            border-color: #E60012;
        }

        .send-btn {
            background: #E60012;
            color: white;
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .send-btn:hover {
            background: #B8000E;
        }

        .send-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #666;
            text-align: center;
        }

        .empty-chat i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #ddd;
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
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            background: #E60012;
            color: white;
            padding: 1rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .user-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .user-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .user-item:hover {
            background: #f8f9fa;
        }

        .user-avatar-small {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #E60012;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .user-info-small {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .user-role {
            font-size: 0.85rem;
            color: #666;
        }

        .file-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            display: none;
            align-items: center;
            gap: 0.5rem;
        }

        .file-preview.show {
            display: flex;
        }

        .file-preview-info {
            flex: 1;
            min-width: 0;
        }

        .file-preview-name {
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-preview-size {
            font-size: 0.8rem;
            color: #666;
        }

        .file-preview-remove {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
        }

        @media (max-width: 768px) {
            body {
                padding-top: 200px;
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
            
            .nav {
                top: 120px;
                padding: 0.25rem 0;
            }
            
            .nav-content {
                padding: 0 0.5rem;
                overflow-x: auto;
            }
            
            .nav-menu {
                flex-wrap: nowrap;
                justify-content: flex-start;
                gap: 0;
                min-width: max-content;
                padding: 0 0.5rem;
            }
            
            .nav-item a {
                padding: 0.75rem 0.5rem;
                font-size: 0.75rem;
                text-align: center;
                min-height: 44px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
                white-space: nowrap;
            }

            .chat-container {
                height: calc(100vh - 200px);
            }

            .sidebar {
                width: 100%;
                position: absolute;
                z-index: 100;
                height: 100%;
                transform: translateX(-100%);
                transition: transform 0.3s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .chat-main {
                width: 100%;
            }
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #666;
        }

        .typing-indicator {
            display: none;
            padding: 0.5rem 1rem;
            color: #666;
            font-style: italic;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <div class="logo-circle">
                    <img src="image.png" alt="Airtel Logo" />
                </div>
                <div class="header-titles">
                    <h1 class="main-title">Airtel Risk Register System</h1>
                    <p class="sub-title">Team Chat</p>
                </div>
            </div>
            <div class="header-right">
                <div class="user-avatar"><?php echo isset($_SESSION['full_name']) ? strtoupper(substr($_SESSION['full_name'], 0, 1)) : 'R'; ?></div>
                <div class="user-details">
                    <div class="user-email"><?php echo isset($_SESSION['email']) ? $_SESSION['email'] : 'No Email'; ?></div>
                    <div class="user-role">Risk_owner • <?php echo htmlspecialchars($user['department'] ?? 'No Department'); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </header>

    <nav class="nav">
        <div class="nav-content">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php">
                        🏠 Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a href="report_risk.php">
                        📝 Report Risk
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php?tab=my-reports">
                        👀 My Reports
                    </a>
                </li>
                <li class="nav-item">
                    <a href="risk_owner_dashboard.php?tab=procedures">
                        📋 Procedures
                    </a>
                </li>
                <li class="nav-item">
                    <a href="teamchat.php" class="active">
                        💬 Team Chat
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="chat-container">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h3 class="sidebar-title">Conversations</h3>
                <button class="new-chat-btn" onclick="openNewChatModal()">
                    <i class="fas fa-plus"></i> New Chat
                </button>
            </div>
            
            <div class="chat-list">
                <!-- Group Chats -->
                <?php foreach ($chat_rooms as $room): ?>
                    <?php if ($room['type'] === 'group'): ?>
                    <div class="chat-item" onclick="selectChat(<?php echo $room['id']; ?>, 'group', '<?php echo htmlspecialchars($room['name']); ?>', '<?php echo htmlspecialchars($room['description'] ?? ''); ?>')">
                        <div class="chat-icon group">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($room['name']); ?></div>
                            <div class="chat-last-message">
                                <?php echo $room['last_message'] ? htmlspecialchars(substr($room['last_message'], 0, 30)) . '...' : 'No messages yet'; ?>
                            </div>
                        </div>
                        <?php if ($room['unread_count'] > 0): ?>
                            <div class="unread-badge"><?php echo $room['unread_count']; ?></div>
                        <?php endif; ?>
                        <div class="chat-actions">
                            <button class="chat-action-btn" onclick="event.stopPropagation(); archiveChat(<?php echo $room['id']; ?>, 'group')" title="Clear Chat">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Private Chats -->
                <?php if (!empty($private_chats)): ?>
                    <div style="padding: 0.5rem 1rem; background: #f8f9fa; font-size: 0.85rem; font-weight: 600; color: #666; border-bottom: 1px solid #e1e5e9;">
                        Private Messages
                    </div>
                    <?php foreach ($private_chats as $chat): ?>
                    <div class="chat-item" onclick="selectChat(<?php echo $chat['id']; ?>, 'private', '<?php echo htmlspecialchars($chat['other_user_name']); ?>', 'Private conversation')">
                        <div class="chat-icon private">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['other_user_name']); ?></div>
                            <div class="chat-last-message">
                                <?php echo $chat['last_message'] ? htmlspecialchars(substr($chat['last_message'], 0, 30)) . '...' : 'No messages yet'; ?>
                            </div>
                        </div>
                        <?php if ($chat['unread_count'] > 0): ?>
                            <div class="unread-badge"><?php echo $chat['unread_count']; ?></div>
                        <?php endif; ?>
                        <div class="chat-actions">
                            <button class="chat-action-btn" onclick="event.stopPropagation(); archiveChat(<?php echo $chat['id']; ?>, 'private')" title="Clear Chat">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="chat-main">
            <div class="empty-chat" id="emptyChat">
                <i class="fas fa-comments"></i>
                <h3>Welcome to Team Chat</h3>
                <p>Select a conversation to start chatting with your colleagues</p>
                <p style="font-size: 0.9rem; color: #999; margin-top: 1rem;">
                    💡 You can now message anyone in the system and attach files to your messages
                </p>
            </div>

            <div id="chatArea" style="display: none; height: 100%; flex-direction: column;">
                <div class="chat-header" id="chatHeader">
                    <!-- Chat header will be populated by JavaScript -->
                </div>
                
                <div class="messages-container" id="messagesContainer">
                    <!-- Messages will be loaded here -->
                </div>
                
                <div class="message-input-container">
                    <div class="file-preview" id="filePreview">
                        <div class="file-icon">
                            <i class="fas fa-file"></i>
                        </div>
                        <div class="file-preview-info">
                            <div class="file-preview-name" id="filePreviewName"></div>
                            <div class="file-preview-size" id="filePreviewSize"></div>
                        </div>
                        <button class="file-preview-remove" onclick="removeFilePreview()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <form class="message-input-form" id="messageForm" enctype="multipart/form-data">
                        <div class="file-input-container">
                            <input type="file" id="fileInput" class="file-input" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" onchange="handleFileSelect(event)">
                            <label for="fileInput" class="file-input-btn" title="Attach File">
                                <i class="fas fa-paperclip"></i>
                            </label>
                        </div>
                        <textarea 
                            class="message-input" 
                            id="messageInput" 
                            placeholder="Type your message..." 
                            rows="1"
                            onkeydown="handleKeyDown(event)"
                        ></textarea>
                        <button type="submit" class="send-btn" id="sendBtn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Start New Conversation</h3>
                <button class="close" onclick="closeNewChatModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 1rem;">
                    <input type="text" id="userSearch" placeholder="Search users..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <div class="loading" id="usersLoading">Loading users...</div>
                <div class="user-list" id="usersList" style="display: none;">
                    <!-- Users will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentRoomId = null;
        let currentRoomType = null;
        let lastMessageId = 0;
        let messagePolling = null;
        let selectedFile = null;

        // Initialize chat
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-select first group chat if available
            const firstGroupChat = document.querySelector('.chat-item');
            if (firstGroupChat) {
                firstGroupChat.click();
            }

            // Update unread counts periodically
            setInterval(updateUnreadCounts, 5000);
        });

        function selectChat(roomId, type, name, description) {
            // Update active chat item
            document.querySelectorAll('.chat-item').forEach(item => {
                item.classList.remove('active');
            });
            event.currentTarget.classList.add('active');

            // Set current room
            currentRoomId = roomId;
            currentRoomType = type;
            lastMessageId = 0;

            // Update chat header
            const chatHeader = document.getElementById('chatHeader');
            const iconClass = type === 'group' ? 'fas fa-users' : 'fas fa-user';
            const iconBg = type === 'group' ? 'background: #e3f2fd; color: #1976d2;' : 'background: #f3e5f5; color: #7b1fa2;';
            
            chatHeader.innerHTML = `
                <div class="chat-header-icon" style="${iconBg}">
                    <i class="${iconClass}"></i>
                </div>
                <div class="chat-header-info">
                    <h3>${name}</h3>
                    <p>${description}</p>
                </div>
                <div class="chat-header-actions">
                    <button class="header-action-btn" onclick="archiveChat(${roomId}, '${type}')" title="Clear Chat">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;

            // Show chat area
            document.getElementById('emptyChat').style.display = 'none';
            document.getElementById('chatArea').style.display = 'flex';

            // Clear messages and load new ones
            document.getElementById('messagesContainer').innerHTML = '';
            loadMessages();

            // Start polling for new messages
            if (messagePolling) {
                clearInterval(messagePolling);
            }
            messagePolling = setInterval(loadMessages, 2000);

            // Clear unread badge for this chat
            const unreadBadge = event.currentTarget.querySelector('.unread-badge');
            if (unreadBadge) {
                unreadBadge.remove();
            }
        }

        function loadMessages() {
            if (!currentRoomId) return;

            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('room_id', currentRoomId);
            formData.append('last_message_id', lastMessageId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    const messagesContainer = document.getElementById('messagesContainer');
                    
                    data.messages.forEach(message => {
                        const messageDiv = createMessageElement(message);
                        messagesContainer.appendChild(messageDiv);
                        lastMessageId = Math.max(lastMessageId, message.id);
                    });

                    // Scroll to bottom
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
            });
        }

        function createMessageElement(message) {
            const messageDiv = document.createElement('div');
            const isOwn = message.sender_id == <?php echo $_SESSION['user_id']; ?>;
            messageDiv.className = `message ${isOwn ? 'own' : ''}`;

            const senderInitial = message.full_name ? message.full_name.charAt(0).toUpperCase() : 'U';
            const messageTime = new Date(message.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

            let fileContent = '';
            if (message.message_type === 'file' && message.file_name) {
                const fileIcon = getFileIcon(message.file_name);
                const fileSize = formatFileSize(message.file_size);
                fileContent = `
                    <div class="message-file">
                        <div class="file-icon">${fileIcon}</div>
                        <div class="file-info">
                            <div class="file-name">${escapeHtml(message.file_name)}</div>
                            <div class="file-size">${fileSize}</div>
                        </div>
                        <button class="file-download" onclick="downloadFile('${message.file_path}', '${message.file_name}')" title="Download">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                `;
            }

            messageDiv.innerHTML = `
                <div class="message-avatar">${senderInitial}</div>
                <div class="message-content">
                    <div class="message-header">
                        <span class="message-sender">${message.full_name}</span>
                        <span class="message-time">${messageTime}</span>
                    </div>
                    <div class="message-text">${escapeHtml(message.message)}</div>
                    ${fileContent}
                </div>
            `;

            return messageDiv;
        }

        function sendMessage() {
            if (!currentRoomId) return;

            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            const fileInput = document.getElementById('fileInput');

            if (!message && !selectedFile) return;

            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('room_id', currentRoomId);
            formData.append('message', message);

            if (selectedFile) {
                formData.append('file', selectedFile);
            }

            // Disable send button
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageInput.value = '';
                    removeFilePreview();
                    loadMessages(); // Reload messages immediately
                } else {
                    alert('Error sending message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                alert('Error sending message');
            })
            .finally(() => {
                sendBtn.disabled = false;
                messageInput.focus();
            });
        }

        function handleKeyDown(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        }

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                selectedFile = file;
                showFilePreview(file);
            }
        }

        function showFilePreview(file) {
            const preview = document.getElementById('filePreview');
            const nameEl = document.getElementById('filePreviewName');
            const sizeEl = document.getElementById('filePreviewSize');

            nameEl.textContent = file.name;
            sizeEl.textContent = formatFileSize(file.size);
            preview.classList.add('show');
        }

        function removeFilePreview() {
            const preview = document.getElementById('filePreview');
            const fileInput = document.getElementById('fileInput');
            
            preview.classList.remove('show');
            fileInput.value = '';
            selectedFile = null;
        }

        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            switch (extension) {
                case 'pdf': return '<i class="fas fa-file-pdf"></i>';
                case 'doc':
                case 'docx': return '<i class="fas fa-file-word"></i>';
                case 'xls':
                case 'xlsx': return '<i class="fas fa-file-excel"></i>';
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif': return '<i class="fas fa-file-image"></i>';
                case 'zip': return '<i class="fas fa-file-archive"></i>';
                case 'txt': return '<i class="fas fa-file-alt"></i>';
                default: return '<i class="fas fa-file"></i>';
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function downloadFile(filePath, fileName) {
            const link = document.createElement('a');
            link.href = filePath;
            link.download = fileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Message form submission
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });

        function openNewChatModal() {
            document.getElementById('newChatModal').classList.add('show');
            loadUsers();
        }

        function closeNewChatModal() {
            document.getElementById('newChatModal').classList.remove('show');
        }

        function loadUsers() {
            const formData = new FormData();
            formData.append('action', 'get_users');

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const usersList = document.getElementById('usersList');
                    const usersLoading = document.getElementById('usersLoading');
                    
                    usersLoading.style.display = 'none';
                    usersList.style.display = 'block';
                    usersList.innerHTML = '';

                    window.allUsers = data.users; // Store for search
                    displayUsers(data.users);
                }
            })
            .catch(error => {
                console.error('Error loading users:', error);
            });
        }

        function displayUsers(users) {
            const usersList = document.getElementById('usersList');
            usersList.innerHTML = '';

            users.forEach(user => {
                const userDiv = document.createElement('div');
                userDiv.className = 'user-item';
                userDiv.onclick = () => startPrivateChat(user.id, user.full_name);

                const initial = user.full_name ? user.full_name.charAt(0).toUpperCase() : 'U';
                const roleText = getRoleDisplayName(user.role);
                const departmentText = user.department ? ` • ${user.department}` : '';

                userDiv.innerHTML = `
                    <div class="user-avatar-small">${initial}</div>
                    <div class="user-info-small">
                        <div class="user-name">${user.full_name}</div>
                        <div class="user-role">${roleText}${departmentText}</div>
                    </div>
                `;

                usersList.appendChild(userDiv);
            });
        }

        function getRoleDisplayName(role) {
            switch (role) {
                case 'compliance_officer': return 'Compliance Officer';
                case 'risk_owner': return 'Risk Owner';
                case 'admin': return 'Administrator';
                case 'manager': return 'Manager';
                default: return role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            }
        }

        // User search functionality
        document.getElementById('userSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            if (window.allUsers) {
                const filteredUsers = window.allUsers.filter(user => 
                    user.full_name.toLowerCase().includes(searchTerm) ||
                    user.email.toLowerCase().includes(searchTerm) ||
                    (user.department && user.department.toLowerCase().includes(searchTerm))
                );
                displayUsers(filteredUsers);
            }
        });

        function startPrivateChat(userId, userName) {
            const formData = new FormData();
            formData.append('action', 'create_private_chat');
            formData.append('other_user_id', userId);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeNewChatModal();
                    // Refresh the page to show the new chat
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Error creating private chat:', error);
            });
        }

        function archiveChat(roomId, chatType) {
            if (!confirm('Are you sure you want to clear this chat? It will be moved to the system bin.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'archive_chat');
            formData.append('room_id', roomId);
            formData.append('chat_type', chatType);

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh the page to update the chat list
                    location.reload();
                } else {
                    alert('Error clearing chat');
                }
            })
            .catch(error => {
                console.error('Error archiving chat:', error);
                alert('Error clearing chat');
            });
        }

        function updateUnreadCounts() {
            const formData = new FormData();
            formData.append('action', 'get_unread_counts');

            fetch('teamchat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    data.counts.forEach(chat => {
                        const chatItems = document.querySelectorAll('.chat-item');
                        chatItems.forEach(item => {
                            const chatId = item.getAttribute('onclick').match(/selectChat\((\d+)/);
                            if (chatId && parseInt(chatId[1]) === chat.room_id) {
                                // Remove existing badge
                                const existingBadge = item.querySelector('.unread-badge');
                                if (existingBadge) {
                                    existingBadge.remove();
                                }

                                // Add new badge if there are unread messages
                                if (chat.unread_count > 0) {
                                    const badge = document.createElement('div');
                                    badge.className = 'unread-badge';
                                    badge.textContent = chat.unread_count;
                                    item.appendChild(badge);
                                }
                            }
                        });
                    });
                }
            })
            .catch(error => {
                console.error('Error updating unread counts:', error);
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-resize textarea
        document.getElementById('messageInput').addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('newChatModal');
            if (event.target === modal) {
                closeNewChatModal();
            }
        });

        // Clean up polling when page unloads
        window.addEventListener('beforeunload', function() {
            if (messagePolling) {
                clearInterval(messagePolling);
            }
        });

        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // Add mobile menu button for smaller screens
        if (window.innerWidth <= 768) {
            const chatHeader = document.getElementById('chatHeader');
            if (chatHeader) {
                const mobileMenuBtn = document.createElement('button');
                mobileMenuBtn.className = 'mobile-menu-btn';
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                mobileMenuBtn.onclick = toggleSidebar;
                chatHeader.prepend(mobileMenuBtn);
            }
        }
    </script>
</body>
</html>