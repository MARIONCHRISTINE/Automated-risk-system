<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Get user info
$user_name = $_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'admin';

// Database connection
require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chat') {
    header('Content-Type: application/json');
    
    $user_message = trim($_POST['message'] ?? '');
    $response = ['success' => true, 'message' => '', 'intent' => '', 'entities' => []];
    
    try {
        // Process the user's message and generate a response
        $nlp_result = processMessageWithNLP($user_message);
        $response['intent'] = $nlp_result['intent'];
        $response['entities'] = $nlp_result['entities'];
        $response['message'] = generateResponse($nlp_result, $db);
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = "I'm sorry, I encountered an error while processing your request. Please try again later.";
    }
    
    echo json_encode($response);
    exit();
}

// Natural Language Processing function
function processMessageWithNLP($message) {
    // Initialize result
    $result = [
        'intent' => 'unknown',
        'entities' => [],
        'sentiment' => 'neutral'
    ];
    
    // Convert to lowercase for processing
    $lower_message = strtolower($message);
    
    // Simple sentiment analysis
    $positive_words = ['good', 'great', 'excellent', 'awesome', 'fantastic', 'happy', 'love', 'like', 'thanks', 'thank you'];
    $negative_words = ['bad', 'terrible', 'awful', 'hate', 'dislike', 'angry', 'frustrated', 'disappointed', 'sad'];
    
    $positive_count = 0;
    $negative_count = 0;
    
    foreach ($positive_words as $word) {
        if (strpos($lower_message, $word) !== false) {
            $positive_count++;
        }
    }
    
    foreach ($negative_words as $word) {
        if (strpos($lower_message, $word) !== false) {
            $negative_count++;
        }
    }
    
    if ($positive_count > $negative_count) {
        $result['sentiment'] = 'positive';
    } elseif ($negative_count > $positive_count) {
        $result['sentiment'] = 'negative';
    }
    
    // Intent recognition with improved patterns
    $intents = [
        'greeting' => [
            'patterns' => ['/^(hello|hi|hey|greetings|good morning|good afternoon|good evening)/i'],
            'response_type' => 'greeting'
        ],
        'goodbye' => [
            'patterns' => ['/^(bye|goodbye|see you|farewell|have a good day)/i'],
            'response_type' => 'goodbye'
        ],
        'thanks' => [
            'patterns' => ['/^(thanks|thank you|appreciate it)/i'],
            'response_type' => 'thanks'
        ],
        'help' => [
            'patterns' => ['/^(help|support|what can you do|how do you|assist me|guide me)/i'],
            'response_type' => 'help'
        ],
        'system_status' => [
            'patterns' => [
                '/(system status|how is the system|system health|overall status)/i',
                '/(how are you|how do you do)/i'
            ],
            'response_type' => 'system_status'
        ],
        'risk_count' => [
            'patterns' => [
                '/(how many|count|number of).*\b(risk|risks)\b/i',
                '/\b(risk|risks).*\b(how many|count|number of)\b/i'
            ],
            'response_type' => 'risk_count'
        ],
        'risk_by_status' => [
            'patterns' => [
                '/\b(how many|count|number of)\b.*\b(open|closed|in progress|mitigated)\b.*\b(risk|risks)\b/i',
                '/\b(open|closed|in progress|mitigated)\b.*\b(risk|risks)\b.*\b(how many|count|number of)\b/i'
            ],
            'response_type' => 'risk_by_status'
        ],
        'risk_by_department' => [
            'patterns' => [
                '/\b(risk|risks)\b.*\b(department|dept)\b/i',
                '/\b(department|dept)\b.*\b(risk|risks)\b/i',
                '/\b(risks by|risks per)\b/i'
            ],
            'response_type' => 'risk_by_department'
        ],
        'high_risk' => [
            'patterns' => [
                '/\b(high risk|critical|severe|top risks)\b/i',
                '/\b(risk|risks)\b.*\b(high|critical|severe)\b/i'
            ],
            'response_type' => 'high_risk'
        ],
        'recent_risks' => [
            'patterns' => [
                '/\b(recent|new|latest)\b.*\b(risk|risks)\b/i',
                '/\b(risk|risks)\b.*\b(recent|new|latest)\b/i'
            ],
            'response_type' => 'recent_risks'
        ],
        'user_count' => [
            'patterns' => [
                '/\b(how many|count|number of)\b.*\b(user|users)\b/i',
                '/\b(user|users)\b.*\b(how many|count|number of)\b/i'
            ],
            'response_type' => 'user_count'
        ],
        'user_by_role' => [
            'patterns' => [
                '/\b(how many|count|number of)\b.*\b(admin|staff|risk owner)\b/i',
                '/\b(admin|staff|risk owner)\b.*\b(how many|count|number of)\b/i'
            ],
            'response_type' => 'user_by_role'
        ],
        'pending_users' => [
            'patterns' => [
                '/\b(pending|awaiting approval)\b.*\b(user|users)\b/i',
                '/\b(user|users)\b.*\b(pending|awaiting approval)\b/i'
            ],
            'response_type' => 'pending_users'
        ],
        'recent_activities' => [
            'patterns' => [
                '/\b(recent|latest|today)\b.*\b(activity|activities)\b/i',
                '/\b(activity|activities)\b.*\b(recent|latest|today)\b/i'
            ],
            'response_type' => 'recent_activities'
        ],
        'suspicious_activities' => [
            'patterns' => [
                '/\b(suspicious|unusual|alert|security)\b.*\b(activity|activities)\b/i',
                '/\b(activity|activities)\b.*\b(suspicious|unusual|alert|security)\b/i'
            ],
            'response_type' => 'suspicious_activities'
        ],
        'create_risk' => [
            'patterns' => [
                '/\b(create|add|new|report)\b.*\b(risk|risks)\b/i',
                '/\b(risk|risks)\b.*\b(create|add|new|report)\b/i'
            ],
            'response_type' => 'create_risk'
        ],
        'update_risk' => [
            'patterns' => [
                '/\b(update|modify|change|edit)\b.*\b(risk|risks)\b/i',
                '/\b(risk|risks)\b.*\b(update|modify|change|edit)\b/i'
            ],
            'response_type' => 'update_risk'
        ],
        'delete_risk' => [
            'patterns' => [
                '/\b(delete|remove)\b.*\b(risk|risks)\b/i',
                '/\b(risk|risks)\b.*\b(delete|remove)\b/i'
            ],
            'response_type' => 'delete_risk'
        ]
    ];
    
    // Check each intent pattern
    foreach ($intents as $intent_name => $intent_data) {
        foreach ($intent_data['patterns'] as $pattern) {
            if (preg_match($pattern, $lower_message)) {
                $result['intent'] = $intent_name;
                
                // Extract entities based on intent
                extractEntities($message, $intent_name, $result);
                
                // Return the first matching intent
                return $result;
            }
        }
    }
    
    // If no specific intent is found, check for general conversation
    if (preg_match('/\b(how are you|how do you do|how are you doing)\b/i', $lower_message)) {
        $result['intent'] = 'how_are_you';
    } elseif (preg_match('/\b(what is your name|who are you)\b/i', $lower_message)) {
        $result['intent'] = 'who_are_you';
    } elseif (preg_match('/\b(what can you do|help me|assist me)\b/i', $lower_message)) {
        $result['intent'] = 'help';
    }
    
    return $result;
}

// Extract entities from message based on intent
function extractEntities($message, $intent, &$result) {
    $lower_message = strtolower($message);
    
    switch ($intent) {
        case 'risk_by_status':
            // Extract risk status
            if (preg_match('/\b(open|closed|in progress|mitigated)\b/i', $lower_message, $matches)) {
                $result['entities']['status'] = $matches[1];
            }
            break;
            
        case 'risk_by_department':
            // Extract department name
            if (preg_match('/\b(it|finance|hr|operations|marketing|legal|compliance)\b/i', $lower_message, $matches)) {
                $result['entities']['department'] = ucwords($matches[1]);
            }
            break;
            
        case 'user_by_role':
            // Extract user role
            if (preg_match('/\b(admin|staff|risk owner|compliance)\b/i', $lower_message, $matches)) {
                $result['entities']['role'] = strtolower($matches[1]);
            }
            break;
            
        case 'create_risk':
        case 'update_risk':
        case 'delete_risk':
            // Extract risk name or ID
            if (preg_match('/\b(risk[-\s]?\d+|named\s+[a-z\s]+|called\s+[a-z\s]+|about\s+[a-z\s]+)\b/i', $lower_message, $matches)) {
                $result['entities']['risk_identifier'] = trim($matches[1]);
            }
            break;
    }
    
    // Extract time-related entities
    if (preg_match('/\b(today|yesterday|this week|last week|this month|last month)\b/i', $lower_message, $matches)) {
        $result['entities']['timeframe'] = $matches[1];
    }
    
    // Extract number entities
    if (preg_match('/\b(\d+)\b/i', $lower_message, $matches)) {
        $result['entities']['number'] = (int)$matches[1];
    }
}

// Generate response based on NLP result
function generateResponse($nlp_result, $db) {
    $intent = $nlp_result['intent'];
    $entities = $nlp_result['entities'];
    $sentiment = $nlp_result['sentiment'];
    
    // Adjust response based on sentiment
    $sentiment_prefix = '';
    if ($sentiment === 'positive') {
        $sentiment_prefix = "I'm glad you're feeling positive! ";
    } elseif ($sentiment === 'negative') {
        $sentiment_prefix = "I understand your concern. ";
    }
    
    switch ($intent) {
        case 'greeting':
            $greetings = [
                "Hello! I'm your AI assistant for the Airtel Risk Management System. How can I help you today?",
                "Hi there! I'm here to assist you with risk management queries. What can I do for you?",
                "Greetings! I'm your virtual assistant for the Airtel Risk Management System. How may I assist you today?"
            ];
            return $greetings[array_rand($greetings)];
            
        case 'goodbye':
            $goodbyes = [
                "Goodbye! Feel free to come back if you need any assistance with risk management.",
                "See you later! Don't hesitate to return if you have more questions.",
                "Farewell! I'm here whenever you need help with risk management."
            ];
            return $goodbyes[array_rand($goodbyes)];
            
        case 'thanks':
            $thanks = [
                "You're welcome! Is there anything else I can help you with?",
                "My pleasure! Let me know if you need any further assistance.",
                "Happy to help! Don't hesitate to ask if you have more questions."
            ];
            return $thanks[array_rand($thanks)];
            
        case 'how_are_you':
            $responses = [
                "I'm functioning optimally, thank you for asking! I'm here to help you with any risk management queries you might have.",
                "I'm operating at full capacity and ready to assist you! How can I help with your risk management needs today?",
                "I'm doing great, thanks for asking! I'm here to provide you with information about the risk management system."
            ];
            return $responses[array_rand($responses)];
            
        case 'who_are_you':
            return "I'm an AI assistant designed specifically for the Airtel Risk Management System. I can help you with information about risks, users, system activities, and more. Feel free to ask me anything about the system!";
            
        case 'help':
            return "I can help you with information about:
- System status and health
- Risk statistics and details
- User information and counts
- Audit logs and activities
- Suspicious activities and alerts

Just ask me a question like:
- \"How many open risks are there?\"
- \"Show me risks by department\"
- \"What are the recent system activities?\"
- \"Are there any suspicious activities?\"
- \"How many users are in the system?\"";
            
        case 'system_status':
            try {
                // Get system stats
                $stats_query = "SELECT 
                    (SELECT COUNT(*) FROM users) as total_users,
                    (SELECT COUNT(*) FROM users WHERE status = 'pending') as pending_users,
                    (SELECT COUNT(*) FROM risk_incidents) as total_risks,
                    (SELECT COUNT(*) FROM risk_incidents WHERE status = 'Open') as open_risks,
                    (SELECT COUNT(*) FROM system_audit_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as recent_activities";
                $stmt = $db->prepare($stats_query);
                $stmt->execute();
                $stats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "System Status: 
- Total Users: {$stats['total_users']}
- Pending Users: {$stats['pending_users']}
- Total Risks: {$stats['total_risks']}
- Open Risks: {$stats['open_risks']}
- Recent Activities (24h): {$stats['recent_activities']}";
            } catch (Exception $e) {
                return "I'm having trouble retrieving the system status right now. Please try again later.";
            }
            
        case 'risk_count':
            try {
                $query = "SELECT COUNT(*) as count FROM risk_incidents";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "There are currently {$result['count']} risks in the system.";
            } catch (Exception $e) {
                return "I'm having trouble retrieving risk information right now.";
            }
            
        case 'risk_by_status':
            $status = $entities['status'] ?? 'Open';
            
            try {
                $query = "SELECT COUNT(*) as count FROM risk_incidents WHERE status = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$status]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "There are currently {$result['count']} risks with status '{$status}'.";
            } catch (Exception $e) {
                return "I'm having trouble retrieving risk information right now.";
            }
            
        case 'risk_by_department':
            try {
                $query = "SELECT department, COUNT(*) as count 
                         FROM risk_incidents 
                         GROUP BY department 
                         ORDER BY count DESC";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    return "There are no risks reported in the system yet.";
                }
                
                $response = $sentiment_prefix . "Risk distribution by department:\n";
                foreach ($results as $row) {
                    $response .= "- {$row['department']}: {$row['count']} risks\n";
                }
                
                return $response;
            } catch (Exception $e) {
                return "I'm having trouble retrieving department risk information right now.";
            }
            
        case 'high_risk':
            try {
                $query = "SELECT risk_name, department, (probability * impact) as risk_score 
                         FROM risk_incidents 
                         WHERE (probability * impact) > 15 
                         ORDER BY risk_score DESC 
                         LIMIT 5";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    return "There are currently no high-risk items in the system.";
                }
                
                $response = $sentiment_prefix . "Top high-risk items:\n";
                foreach ($results as $row) {
                    $response .= "- {$row['risk_name']} (Department: {$row['department']}, Risk Score: {$row['risk_score']})\n";
                }
                
                return $response;
            } catch (Exception $e) {
                return "I'm having trouble retrieving high-risk information right now.";
            }
            
        case 'recent_risks':
            try {
                $query = "SELECT risk_name, department, created_at 
                         FROM risk_incidents 
                         ORDER BY created_at DESC 
                         LIMIT 5";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    return "There are no risks reported in the system yet.";
                }
                
                $response = $sentiment_prefix . "Recent risks reported:\n";
                foreach ($results as $row) {
                    $date = date('M d, Y', strtotime($row['created_at']));
                    $response .= "- {$row['risk_name']} (Department: {$row['department']}, Date: {$date})\n";
                }
                
                return $response;
            } catch (Exception $e) {
                return "I'm having trouble retrieving recent risk information right now.";
            }
            
        case 'user_count':
            try {
                $query = "SELECT COUNT(*) as count FROM users";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "There are currently {$result['count']} users in the system.";
            } catch (Exception $e) {
                return "I'm having trouble retrieving user information right now.";
            }
            
        case 'user_by_role':
            $role = $entities['role'] ?? 'admin';
            
            try {
                $query = "SELECT COUNT(*) as count FROM users WHERE role = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$role]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "There are currently {$result['count']} users with the role '{$role}'.";
            } catch (Exception $e) {
                return "I'm having trouble retrieving user information right now.";
            }
            
        case 'pending_users':
            try {
                $query = "SELECT COUNT(*) as count FROM users WHERE status = 'pending'";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $sentiment_prefix . "There are currently {$result['count']} users pending approval.";
            } catch (Exception $e) {
                return "I'm having trouble retrieving pending user information right now.";
            }
            
        case 'recent_activities':
            try {
                $query = "SELECT user, action, timestamp 
                         FROM system_audit_logs 
                         WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                         ORDER BY timestamp DESC 
                         LIMIT 5";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    return "There are no recent activities in the audit logs.";
                }
                
                $response = $sentiment_prefix . "Recent system activities:\n";
                foreach ($results as $row) {
                    $time = date('H:i', strtotime($row['timestamp']));
                    $response .= "- {$time}: {$row['user']} - {$row['action']}\n";
                }
                
                return $response;
            } catch (Exception $e) {
                return "I'm having trouble retrieving audit log information right now.";
            }
            
        case 'suspicious_activities':
            try {
                // Define suspicious activity patterns
                $suspiciousPatterns = [
                    "action LIKE '%failed%'",
                    "action LIKE '%error%'",
                    "action LIKE '%unauthorized%'",
                    "details LIKE '%failed%'",
                    "details LIKE '%error%'",
                    "details LIKE '%suspicious%'",
                    "details LIKE '%multiple attempts%'"
                ];
                
                $query = "SELECT user, action, details, timestamp 
                         FROM system_audit_logs 
                         WHERE (" . implode(' OR ', $suspiciousPatterns) . ") 
                         AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY timestamp DESC 
                         LIMIT 5";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($results)) {
                    return "No suspicious activities have been detected in the past 7 days.";
                }
                
                $response = $sentiment_prefix . "Recent suspicious activities:\n";
                foreach ($results as $row) {
                    $date = date('M d, H:i', strtotime($row['timestamp']));
                    $response .= "- {$date}: {$row['user']} - {$row['action']}\n";
                    if (!empty($row['details'])) {
                        $response .= "  Details: {$row['details']}\n";
                    }
                }
                
                return $response;
            } catch (Exception $e) {
                return "I'm having trouble retrieving suspicious activity information right now.";
            }
            
        case 'create_risk':
            return $sentiment_prefix . "I'd be happy to help you create a new risk report. However, for security and data integrity reasons, I can't directly create risks in the system. Please use the 'Report Risk' feature in the main application to create a new risk entry. Is there anything else I can help you with?";
            
        case 'update_risk':
            return $sentiment_prefix . "I understand you want to update a risk. For security and data integrity reasons, I can't directly modify risks in the system. Please use the risk management interface in the main application to update risk information. Is there anything else I can help you with?";
            
        case 'delete_risk':
            return $sentiment_prefix . "I understand you want to delete a risk. For security and data integrity reasons, I can't directly delete risks in the system. Please use the risk management interface in the main application to delete risk entries. Is there anything else I can help you with?";
            
        default:
            // For unknown intents, try to provide a helpful response
            $unknown_responses = [
                "I'm not sure I understand what you're looking for. Could you please rephrase your question or ask about system status, risks, users, or activities?",
                "I'm not sure how to respond to that. You can ask me about system status, risks, users, or audit logs. Type 'help' for more information on what I can do.",
                "I didn't quite understand that. I can help you with information about the risk management system, such as system status, risk statistics, user information, or audit logs. How can I assist you?"
            ];
            
            return $unknown_responses[array_rand($unknown_responses)];
    }
}

// The rest of the HTML and JavaScript code remains the same as in the previous version
// Include the complete HTML and JavaScript from the previous implementation here
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Airtel Risk Management - AI Assistant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #E60012;
            --primary-dark: #B8000E;
            --secondary-color: #007bff;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-bg: #f5f5f5;
            --dark-bg: #1a1a1a;
            --light-text: #333;
            --dark-text: #e0e0e0;
            --light-card: #ffffff;
            --dark-card: #2d2d2d;
            --light-header: #f8f9fa;
            --dark-header: #3d3d3d;
            --light-border: #dee2e6;
            --dark-border: #495057;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body {
            background: var(--light-bg);
            color: var(--light-text);
            height: 100vh;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
        }
        
        body.dark-mode {
            background: var(--dark-bg);
            color: var(--dark-text);
        }
        
        .header {
            background: var(--primary-color);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
            z-index: 100;
            position: relative;
        }
        
        body.dark-mode .header {
            background: var(--dark-card);
            border-bottom: 1px solid var(--dark-border);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .logo-circle {
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .header-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .header-controls {
            display: flex;
            gap: 0.5rem;
        }
        
        .header-button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
        }
        
        body.dark-mode .header-button {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .header-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .user-avatar {
            width: 35px;
            height: 35px;
            background: white;
            color: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .user-role {
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 1.5rem;
            overflow: hidden;
        }
        
        .chat-box {
            background: var(--light-card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: var(--transition);
        }
        
        body.dark-mode .chat-box {
            background: var(--dark-card);
        }
        
        .chat-header {
            background: var(--light-header);
            padding: 1rem;
            border-bottom: 1px solid var(--light-border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
        }
        
        body.dark-mode .chat-header {
            background: var(--dark-header);
            border-bottom: 1px solid var(--dark-border);
        }
        
        .chat-header img {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 2px solid var(--primary-color);
        }
        
        .chat-header-info {
            flex: 1;
        }
        
        .chat-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--light-text);
            margin-bottom: 0.2rem;
        }
        
        body.dark-mode .chat-header h2 {
            color: var(--dark-text);
        }
        
        .chat-header-subtitle {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .chat-header-controls {
            display: flex;
            gap: 0.5rem;
        }
        
        .chat-header-button {
            background: transparent;
            color: #6c757d;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .chat-header-button:hover {
            background: rgba(108, 117, 125, 0.1);
            color: #495057;
        }
        
        body.dark-mode .chat-header-button:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #e0e0e0;
        }
        
        .status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--success-color);
            font-size: 0.9rem;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            background: var(--success-color);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
            }
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            scroll-behavior: smooth;
        }
        
        .message {
            display: flex;
            gap: 0.75rem;
            max-width: 80%;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .message.user .message-avatar {
            background: var(--primary-color);
            color: white;
        }
        
        .message.bot .message-avatar {
            background: var(--secondary-color);
            color: white;
        }
        
        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            max-width: 100%;
        }
        
        .message-content {
            background: #f1f1f1;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            position: relative;
            white-space: pre-line;
            word-wrap: break-word;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: var(--transition);
        }
        
        body.dark-mode .message-content {
            background: #3d3d3d;
            color: #e0e0e0;
        }
        
        .message.user .message-content {
            background: var(--primary-color);
            color: white;
        }
        
        .message-time {
            font-size: 0.7rem;
            color: #999;
            margin-top: 0.25rem;
            text-align: right;
            opacity: 0;
            transition: var(--transition);
        }
        
        .message:hover .message-time {
            opacity: 1;
        }
        
        .message.user .message-time {
            color: rgba(255, 255, 255, 0.7);
        }
        
        .message-reactions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            opacity: 0;
            transition: var(--transition);
        }
        
        .message:hover .message-reactions {
            opacity: 1;
        }
        
        .reaction-button {
            background: transparent;
            border: 1px solid #ddd;
            border-radius: 16px;
            padding: 0.2rem 0.5rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        body.dark-mode .reaction-button {
            border-color: #555;
            color: #ccc;
        }
        
        .reaction-button:hover {
            background: #f8f9fa;
            transform: scale(1.1);
        }
        
        body.dark-mode .reaction-button:hover {
            background: #4d4d4d;
        }
        
        .typing-indicator {
            display: flex;
            padding: 0.75rem 1rem;
            background: #f1f1f1;
            border-radius: 18px;
            width: fit-content;
            margin-left: 3.25rem;
        }
        
        body.dark-mode .typing-indicator {
            background: #3d3d3d;
        }
        
        .typing-indicator span {
            height: 10px;
            width: 10px;
            background: #999;
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: typing 1.4s infinite;
        }
        
        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-10px);
            }
        }
        
        .chat-input-container {
            padding: 1rem;
            border-top: 1px solid var(--light-border);
            display: flex;
            gap: 0.75rem;
            background: var(--light-card);
            transition: var(--transition);
        }
        
        body.dark-mode .chat-input-container {
            border-top: 1px solid var(--dark-border);
            background: var(--dark-card);
        }
        
        .chat-input-wrapper {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border-radius: 24px;
            border: 1px solid #ddd;
            padding: 0.5rem 1rem;
            transition: var(--transition);
        }
        
        body.dark-mode .chat-input-wrapper {
            background: #3d3d3d;
            border-color: #555;
        }
        
        .chat-input-wrapper:focus-within {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .chat-input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 0.5rem;
            font-size: 1rem;
            outline: none;
            color: var(--light-text);
        }
        
        body.dark-mode .chat-input {
            color: var(--dark-text);
        }
        
        .chat-input::placeholder {
            color: #999;
        }
        
        .input-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .input-button {
            background: transparent;
            color: #6c757d;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .input-button:hover {
            color: #495057;
            transform: scale(1.1);
        }
        
        body.dark-mode .input-button {
            color: #aaa;
        }
        
        body.dark-mode .input-button:hover {
            color: #e0e0e0;
        }
        
        .send-button {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 2px 5px rgba(230, 0, 18, 0.3);
        }
        
        .send-button:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.4);
        }
        
        .send-button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .voice-button.active {
            background: var(--danger-color);
            color: white;
            animation: pulse 1.5s infinite;
        }
        
        .quick-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            padding: 0 1.5rem;
        }
        
        .quick-action {
            background: var(--light-header);
            border: 1px solid var(--light-border);
            border-radius: 16px;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        body.dark-mode .quick-action {
            background: var(--dark-header);
            border-color: var(--dark-border);
            color: #ccc;
        }
        
        .quick-action:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(230, 0, 18, 0.2);
        }
        
        .quick-action i {
            font-size: 0.9rem;
        }
        
        .suggestions-container {
            background: var(--light-header);
            border-radius: 8px;
            padding: 1rem;
            margin: 0 1.5rem 1rem;
            border: 1px solid var(--light-border);
            transition: var(--transition);
        }
        
        body.dark-mode .suggestions-container {
            background: var(--dark-header);
            border-color: var(--dark-border);
        }
        
        .suggestions-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #666;
        }
        
        body.dark-mode .suggestions-title {
            color: #aaa;
        }
        
        .suggestions-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .suggestion-chip {
            background: white;
            border: 1px solid #ddd;
            border-radius: 16px;
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        body.dark-mode .suggestion-chip {
            background: #3d3d3d;
            border-color: #555;
            color: #ccc;
        }
        
        .suggestion-chip:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: scale(1.05);
        }
        
        .search-container {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--light-border);
            background: var(--light-card);
            transition: var(--transition);
            display: none;
        }
        
        body.dark-mode .search-container {
            border-bottom: 1px solid var(--dark-border);
            background: var(--dark-card);
        }
        
        .search-container.active {
            display: block;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: 24px;
            font-size: 0.9rem;
            outline: none;
            transition: var(--transition);
        }
        
        body.dark-mode .search-input {
            background: #3d3d3d;
            border-color: #555;
            color: #e0e0e0;
        }
        
        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(230, 0, 18, 0.1);
        }
        
        .keyboard-shortcuts {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.8rem;
            max-width: 300px;
            z-index: 1000;
            display: none;
        }
        
        .keyboard-shortcuts.show {
            display: block;
        }
        
        .keyboard-shortcuts h4 {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .shortcut-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.3rem;
        }
        
        .shortcut-key {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-family: monospace;
        }
        
        .floating-menu {
            position: fixed;
            bottom: 20px;
            left: 20px;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            z-index: 100;
        }
        
        .floating-button {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            border: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.2rem;
        }
        
        .floating-button:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }
        
        .export-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .export-modal.show {
            display: flex;
        }
        
        .export-modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        }
        
        body.dark-mode .export-modal-content {
            background: var(--dark-card);
            color: var(--dark-text);
        }
        
        .export-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .export-modal-title {
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .export-modal-close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #999;
        }
        
        .export-modal-close:hover {
            color: #333;
        }
        
        body.dark-mode .export-modal-close:hover {
            color: #e0e0e0;
        }
        
        .export-options {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .export-option {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        body.dark-mode .export-option {
            border-color: #555;
        }
        
        .export-option:hover {
            background: #f8f9fa;
            border-color: var(--primary-color);
        }
        
        body.dark-mode .export-option:hover {
            background: #3d3d3d;
        }
        
        .export-option-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f1f1;
            color: var(--primary-color);
        }
        
        body.dark-mode .export-option-icon {
            background: #3d3d3d;
        }
        
        .export-option-info h4 {
            margin-bottom: 0.2rem;
            font-size: 1rem;
        }
        
        .export-option-info p {
            font-size: 0.85rem;
            color: #666;
        }
        
        body.dark-mode .export-option-info p {
            color: #aaa;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 1rem;
            max-width: 400px;
            z-index: 1000;
            transform: translateX(120%);
            transition: transform 0.3s ease;
        }
        
        body.dark-mode .notification {
            background: var(--dark-card);
            color: var(--dark-text);
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .notification.success .notification-icon {
            background: var(--success-color);
            color: white;
        }
        
        .notification.error .notification-icon {
            background: var(--danger-color);
            color: white;
        }
        
        .notification.info .notification-icon {
            background: var(--secondary-color);
            color: white;
        }
        
        .notification-content h4 {
            margin-bottom: 0.2rem;
            font-size: 1rem;
        }
        
        .notification-content p {
            font-size: 0.85rem;
            color: #666;
        }
        
        body.dark-mode .notification-content p {
            color: #aaa;
        }
        
        .notification-close {
            background: transparent;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #999;
            margin-left: auto;
        }
        
        .notification-close:hover {
            color: #333;
        }
        
        body.dark-mode .notification-close:hover {
            color: #e0e0e0;
        }
        
        .intent-indicator {
            font-size: 0.7rem;
            color: #666;
            margin-top: 0.25rem;
            font-style: italic;
        }
        
        body.dark-mode .intent-indicator {
            color: #aaa;
        }
        
        @media (max-width: 768px) {
            .header {
                padding: 0.75rem 1rem;
            }
            
            .header-title {
                font-size: 1rem;
            }
            
            .chat-container {
                padding: 1rem;
            }
            
            .message {
                max-width: 90%;
            }
            
            .quick-actions {
                padding: 0 1rem;
            }
            
            .suggestions-container {
                margin: 0 1rem 1rem;
                padding: 0.75rem;
            }
            
            .floating-menu {
                bottom: 10px;
                left: 10px;
            }
            
            .floating-button {
                width: 45px;
                height: 45px;
                font-size: 1rem;
            }
            
            .keyboard-shortcuts {
                bottom: 10px;
                right: 10px;
                max-width: 250px;
            }
            
            .export-modal-content {
                width: 95%;
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="logo-circle">
                <img src="image.png" alt="Airtel Logo" />
            </div>
            <div class="header-title">Airtel Risk Management - AI Assistant</div>
        </div>
        <div class="header-right">
            <div class="header-controls">
                <button class="header-button" onclick="toggleSearch()" title="Search Chat">
                    <i class="fas fa-search"></i>
                </button>
                <button class="header-button" onclick="toggleKeyboardShortcuts()" title="Keyboard Shortcuts">
                    <i class="fas fa-keyboard"></i>
                </button>
                <button class="header-button" onclick="exportChat()" title="Export Chat">
                    <i class="fas fa-download"></i>
                </button>
                <button class="header-button" onclick="toggleTheme()" title="Toggle Dark Mode">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="user-role"><?php echo ucfirst($user_role); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="chat-container">
        <div class="chat-box">
            <div class="chat-header">
                <img src="chatbot.png" alt="AI Assistant">
                <div class="chat-header-info">
                    <h2>AI Assistant</h2>
                    <div class="chat-header-subtitle">Your intelligent risk management companion</div>
                </div>
                <div class="chat-header-controls">
                    <div class="status">
                        <div class="status-dot"></div>
                        <span>Online</span>
                    </div>
                    <button class="chat-header-button" onclick="clearChat()" title="Clear Chat">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
            
            <div class="search-container" id="searchContainer">
                <input type="text" class="search-input" id="searchInput" placeholder="Search in chat history..." onkeyup="searchChat()">
            </div>
            
            <div class="quick-actions">
                <div class="quick-action" onclick="sendQuickMessage('System status')">
                    <i class="fas fa-chart-line"></i>
                    <span>System Status</span>
                </div>
                <div class="quick-action" onclick="sendQuickMessage('How many open risks are there?')">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Open Risks</span>
                </div>
                <div class="quick-action" onclick="sendQuickMessage('Show me risks by department')">
                    <i class="fas fa-building"></i>
                    <span>Risks by Dept</span>
                </div>
                <div class="quick-action" onclick="sendQuickMessage('Recent activities')">
                    <i class="fas fa-history"></i>
                    <span>Activities</span>
                </div>
                <div class="quick-action" onclick="sendQuickMessage('Suspicious activities')">
                    <i class="fas fa-shield-alt"></i>
                    <span>Security</span>
                </div>
                <div class="quick-action" onclick="sendQuickMessage('Help')">
                    <i class="fas fa-question-circle"></i>
                    <span>Help</span>
                </div>
            </div>
            
            <div class="chat-messages" id="chatMessages">
                <div class="message bot">
                    <div class="message-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content-wrapper">
                        <div class="message-content">
                            Hello <?php echo htmlspecialchars($user_name); ?>! I'm your AI assistant for the Airtel Risk Management System. How can I help you today?
                        </div>
                        <div class="message-time"><?php echo date('h:i A'); ?></div>
                    </div>
                </div>
                
                <div class="suggestions-container" id="suggestionsContainer">
                    <div class="suggestions-title">Try asking:</div>
                    <div class="suggestions-list">
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many users are in the system?')">User Count</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me high risk items')">High Risks</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('What are the recent system activities?')">Recent Activities</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many pending users?')">Pending Users</div>
                    </div>
                </div>
            </div>
            
            <div class="chat-input-container">
                <div class="chat-input-wrapper">
                    <input type="text" class="chat-input" id="chatInput" placeholder="Type your message here..." onkeypress="handleKeyPress(event)">
                    <div class="input-buttons">
                        <button class="input-button" id="voiceButton" onclick="toggleVoiceInput()" title="Voice Input">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button class="input-button" onclick="attachFile()" title="Attach File">
                            <i class="fas fa-paperclip"></i>
                        </button>
                    </div>
                </div>
                <button class="send-button" id="sendButton" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
    
    <div class="floating-menu">
        <button class="floating-button" onclick="scrollToBottom()" title="Scroll to Bottom">
            <i class="fas fa-arrow-down"></i>
        </button>
    </div>
    
    <div class="keyboard-shortcuts" id="keyboardShortcuts">
        <h4>Keyboard Shortcuts</h4>
        <div class="shortcut-item">
            <span>Send Message</span>
            <span class="shortcut-key">Enter</span>
        </div>
        <div class="shortcut-item">
            <span>New Line</span>
            <span class="shortcut-key">Shift + Enter</span>
        </div>
        <div class="shortcut-item">
            <span>Clear Chat</span>
            <span class="shortcut-key">Ctrl + L</span>
        </div>
        <div class="shortcut-item">
            <span>Search Chat</span>
            <span class="shortcut-key">Ctrl + F</span>
        </div>
        <div class="shortcut-item">
            <span>Dark Mode</span>
            <span class="shortcut-key">Ctrl + D</span>
        </div>
        <div class="shortcut-item">
            <span>Voice Input</span>
            <span class="shortcut-key">Ctrl + M</span>
        </div>
        <div class="shortcut-item">
            <span>Export Chat</span>
            <span class="shortcut-key">Ctrl + E</span>
        </div>
    </div>
    
    <div class="export-modal" id="exportModal">
        <div class="export-modal-content">
            <div class="export-modal-header">
                <h3 class="export-modal-title">Export Chat History</h3>
                <button class="export-modal-close" onclick="closeExportModal()">&times;</button>
            </div>
            <div class="export-options">
                <div class="export-option" onclick="exportChatAs('txt')">
                    <div class="export-option-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="export-option-info">
                        <h4>Text File (.txt)</h4>
                        <p>Plain text format, easy to read and share</p>
                    </div>
                </div>
                <div class="export-option" onclick="exportChatAs('json')">
                    <div class="export-option-icon">
                        <i class="fas fa-code"></i>
                    </div>
                    <div class="export-option-info">
                        <h4>JSON File (.json)</h4>
                        <p>Structured data format for developers</p>
                    </div>
                </div>
                <div class="export-option" onclick="exportChatAs('pdf')">
                    <div class="export-option-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="export-option-info">
                        <h4>PDF Document (.pdf)</h4>
                        <p>Professional format for reports and printing</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="notification" id="notification">
        <div class="notification-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="notification-content">
            <h4>Success</h4>
            <p>Your action was completed successfully.</p>
        </div>
        <button class="notification-close" onclick="closeNotification()">&times;</button>
    </div>
    
    <script>
        // Global variables
        let recognition;
        let isListening = false;
        let isDarkMode = false;
        let messageIdCounter = 0;
        let conversationHistory = [];
        
        // Initialize voice recognition
        function initVoiceRecognition() {
            if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
                document.getElementById('voiceButton').style.display = 'none';
                return;
            }
            
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.lang = 'en-US';
            
            recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                document.getElementById('chatInput').value = transcript;
                sendMessage();
                stopVoiceInput();
            };
            
            recognition.onerror = function(event) {
                console.error('Speech recognition error', event.error);
                stopVoiceInput();
                showNotification('error', 'Voice Recognition Error', 'Please try again or check your microphone permissions.');
            };
            
            recognition.onend = function() {
                stopVoiceInput();
            };
        }
        
        // Toggle voice input
        function toggleVoiceInput() {
            if (!recognition) {
                initVoiceRecognition();
            }
            
            if (!isListening) {
                recognition.start();
                isListening = true;
                document.getElementById('voiceButton').classList.add('active');
                document.getElementById('voiceButton').innerHTML = '<i class="fas fa-stop"></i>';
            } else {
                recognition.stop();
                stopVoiceInput();
            }
        }
        
        // Stop voice input
        function stopVoiceInput() {
            isListening = false;
            document.getElementById('voiceButton').classList.remove('active');
            document.getElementById('voiceButton').innerHTML = '<i class="fas fa-microphone"></i>';
        }
        
        // Handle keyboard events
        document.addEventListener('keydown', function(event) {
            // Ctrl/Cmd + L: Clear chat
            if ((event.ctrlKey || event.metaKey) && event.key === 'l') {
                event.preventDefault();
                clearChat();
            }
            
            // Ctrl/Cmd + F: Search chat
            if ((event.ctrlKey || event.metaKey) && event.key === 'f') {
                event.preventDefault();
                toggleSearch();
            }
            
            // Ctrl/Cmd + D: Toggle dark mode
            if ((event.ctrlKey || event.metaKey) && event.key === 'd') {
                event.preventDefault();
                toggleTheme();
            }
            
            // Ctrl/Cmd + M: Toggle voice input
            if ((event.ctrlKey || event.metaKey) && event.key === 'm') {
                event.preventDefault();
                toggleVoiceInput();
            }
            
            // Ctrl/Cmd + E: Export chat
            if ((event.ctrlKey || event.metaKey) && event.key === 'e') {
                event.preventDefault();
                exportChat();
            }
            
            // Escape: Close modals and panels
            if (event.key === 'Escape') {
                closeExportModal();
                document.getElementById('searchContainer').classList.remove('active');
                document.getElementById('keyboardShortcuts').classList.remove('show');
            }
        });
        
        // Handle enter key press
        function handleKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            }
        }
        
        // Send quick message
        function sendQuickMessage(message) {
            document.getElementById('chatInput').value = message;
            sendMessage();
        }
        
        // Send message
        function sendMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            
            if (message === '') return;
            
            // Add user message to chat
            addMessage(message, 'user');
            
            // Add to conversation history
            conversationHistory.push({
                sender: 'user',
                message: message,
                timestamp: new Date()
            });
            
            // Clear input
            input.value = '';
            
            // Disable send button
            document.getElementById('sendButton').disabled = true;
            
            // Show typing indicator
            showTypingIndicator();
            
            // Send message to server
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'chatbot.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // Remove typing indicator
                        removeTypingIndicator();
                        
                        // Add bot response to chat
                        addMessage(response.message, 'bot', response.intent);
                        
                        // Add to conversation history
                        conversationHistory.push({
                            sender: 'bot',
                            message: response.message,
                            intent: response.intent,
                            entities: response.entities,
                            timestamp: new Date()
                        });
                        
                        // Update suggestions based on context
                        updateSuggestions(message, response.message, response.intent);
                    } else {
                        removeTypingIndicator();
                        addMessage("I'm sorry, I encountered an error. Please try again.", 'bot');
                    }
                } else {
                    removeTypingIndicator();
                    addMessage("I'm having trouble connecting to the server. Please try again later.", 'bot');
                }
                
                // Re-enable send button
                document.getElementById('sendButton').disabled = false;
            };
            xhr.onerror = function() {
                removeTypingIndicator();
                addMessage("I'm having trouble connecting to the server. Please check your internet connection.", 'bot');
                document.getElementById('sendButton').disabled = false;
            };
            xhr.send('action=chat&message=' + encodeURIComponent(message));
        }
        
        // Add message to chat
        function addMessage(text, sender, intent = '') {
            const messagesContainer = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${sender}`;
            messageDiv.id = `message-${messageIdCounter++}`;
            
            const now = new Date();
            const timeString = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            
            if (sender === 'user') {
                messageDiv.innerHTML = `
                    <div class="message-avatar">${getInitials('<?php echo htmlspecialchars($user_name); ?>')}</div>
                    <div class="message-content-wrapper">
                        <div class="message-content">${escapeHtml(text)}</div>
                        <div class="message-time">${timeString}</div>
                        <div class="message-reactions">
                            <button class="reaction-button" onclick="addReaction(${messageIdCounter - 1}, '👍')">👍</button>
                            <button class="reaction-button" onclick="addReaction(${messageIdCounter - 1}, '👎')">👎</button>
                        </div>
                    </div>
                `;
            } else {
                let intentIndicator = '';
                if (intent && intent !== 'unknown') {
                    intentIndicator = `<div class="intent-indicator">Intent: ${intent}</div>`;
                }
                
                messageDiv.innerHTML = `
                    <div class="message-avatar"><i class="fas fa-robot"></i></div>
                    <div class="message-content-wrapper">
                        <div class="message-content">${escapeHtml(text)}</div>
                        ${intentIndicator}
                        <div class="message-time">${timeString}</div>
                        <div class="message-reactions">
                            <button class="reaction-button" onclick="addReaction(${messageIdCounter - 1}, '👍')">👍</button>
                            <button class="reaction-button" onclick="addReaction(${messageIdCounter - 1}, '👎')">👎</button>
                        </div>
                    </div>
                `;
            }
            
            messagesContainer.appendChild(messageDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            
            // Scroll to bottom after a short delay to ensure smooth scrolling
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }
        
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        // Show typing indicator
        function showTypingIndicator() {
            const messagesContainer = document.getElementById('chatMessages');
            const typingDiv = document.createElement('div');
            typingDiv.className = 'message bot';
            typingDiv.id = 'typingIndicator';
            
            typingDiv.innerHTML = `
                <div class="message-avatar"><i class="fas fa-robot"></i></div>
                <div class="typing-indicator">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            `;
            
            messagesContainer.appendChild(typingDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Remove typing indicator
        function removeTypingIndicator() {
            const typingIndicator = document.getElementById('typingIndicator');
            if (typingIndicator) {
                typingIndicator.remove();
            }
        }
        
        // Clear chat
        function clearChat() {
            const messagesContainer = document.getElementById('chatMessages');
            
            // Keep only the initial greeting and quick actions
            const messages = messagesContainer.querySelectorAll('.message');
            const suggestionsContainer = messagesContainer.querySelector('.suggestions-container');
            
            // Remove all messages except the first one (the initial greeting)
            for (let i = messages.length - 1; i > 0; i--) {
                messages[i].remove();
            }
            
            // Reset message counter and conversation history
            messageIdCounter = 1;
            conversationHistory = [];
            
            // Scroll to top
            messagesContainer.scrollTop = 0;
            
            // Focus on input
            document.getElementById('chatInput').focus();
            
            // Show notification
            showNotification('success', 'Chat Cleared', 'Your chat history has been cleared successfully.');
        }
        
        // Toggle search
        function toggleSearch() {
            const searchContainer = document.getElementById('searchContainer');
            searchContainer.classList.toggle('active');
            
            if (searchContainer.classList.contains('active')) {
                document.getElementById('searchInput').focus();
            }
        }
        
        // Search in chat
        function searchChat() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const messages = document.querySelectorAll('.message');
            
            messages.forEach(message => {
                const content = message.querySelector('.message-content').textContent.toLowerCase();
                if (content.includes(searchTerm) || searchTerm === '') {
                    message.style.display = 'flex';
                } else {
                    message.style.display = 'none';
                }
            });
        }
        
        // Toggle theme
        function toggleTheme() {
            isDarkMode = !isDarkMode;
            document.body.classList.toggle('dark-mode');
            
            const themeIcon = document.getElementById('themeIcon');
            if (isDarkMode) {
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
            
            // Save theme preference
            localStorage.setItem('darkMode', isDarkMode);
        }
        
        // Toggle keyboard shortcuts
        function toggleKeyboardShortcuts() {
            const shortcutsPanel = document.getElementById('keyboardShortcuts');
            shortcutsPanel.classList.toggle('show');
        }
        
        // Export chat
        function exportChat() {
            document.getElementById('exportModal').classList.add('show');
        }
        
        // Close export modal
        function closeExportModal() {
            document.getElementById('exportModal').classList.remove('show');
        }
        
        // Export chat as specific format
        function exportChatAs(format) {
            const messages = document.querySelectorAll('.message');
            let content = '';
            
            if (format === 'txt') {
                content = 'Airtel Risk Management - AI Assistant Chat History\n';
                content += 'Generated on: ' + new Date().toLocaleString() + '\n\n';
                
                messages.forEach(message => {
                    const sender = message.classList.contains('user') ? 'You' : 'AI Assistant';
                    const time = message.querySelector('.message-time').textContent;
                    const text = message.querySelector('.message-content').textContent;
                    
                    content += `[${time}] ${sender}: ${text}\n\n`;
                });
                
                downloadFile(content, 'chat-history.txt', 'text/plain');
            } else if (format === 'json') {
                const chatData = {
                    title: 'Airtel Risk Management - AI Assistant Chat History',
                    generated: new Date().toISOString(),
                    messages: []
                };
                
                messages.forEach(message => {
                    const sender = message.classList.contains('user') ? 'user' : 'bot';
                    const time = message.querySelector('.message-time').textContent;
                    const text = message.querySelector('.message-content').textContent;
                    
                    chatData.messages.push({
                        sender: sender,
                        time: time,
                        text: text
                    });
                });
                
                content = JSON.stringify(chatData, null, 2);
                downloadFile(content, 'chat-history.json', 'application/json');
            } else if (format === 'pdf') {
                // For PDF, we'll create a simple HTML representation and let the user print as PDF
                content = '<!DOCTYPE html><html><head><title>Chat History</title>';
                content += '<style>body { font-family: Arial, sans-serif; margin: 20px; } .message { margin-bottom: 15px; } .sender { font-weight: bold; } .time { color: #666; font-size: 0.8em; }</style>';
                content += '</head><body>';
                content += '<h1>Airtel Risk Management - AI Assistant Chat History</h1>';
                content += '<p>Generated on: ' + new Date().toLocaleString() + '</p>';
                
                messages.forEach(message => {
                    const sender = message.classList.contains('user') ? 'You' : 'AI Assistant';
                    const time = message.querySelector('.message-time').textContent;
                    const text = message.querySelector('.message-content').textContent;
                    
                    content += `<div class="message">`;
                    content += `<div><span class="sender">${sender}</span> <span class="time">[${time}]</span></div>`;
                    content += `<div>${text}</div>`;
                    content += `</div>`;
                });
                
                content += '</body></html>';
                
                const newWindow = window.open('', '_blank');
                newWindow.document.write(content);
                newWindow.document.close();
                newWindow.print();
            }
            
            closeExportModal();
            showNotification('success', 'Export Successful', `Your chat history has been exported as ${format.toUpperCase()}.`);
        }
        
        // Download file
        function downloadFile(content, filename, contentType) {
            const blob = new Blob([content], { type: contentType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Attach file (placeholder function)
        function attachFile() {
            showNotification('info', 'File Attachment', 'File attachment feature coming soon!');
        }
        
        // Add reaction to message
        function addReaction(messageId, reaction) {
            // This is a placeholder function for adding reactions
            showNotification('info', 'Reaction Added', `You reacted with ${reaction} to the message.`);
        }
        
        // Update suggestions based on context
        function updateSuggestions(userMessage, botResponse, intent) {
            const suggestionsContainer = document.getElementById('suggestionsContainer');
            const suggestionsList = suggestionsContainer.querySelector('.suggestions-list');
            
            // Clear existing suggestions
            suggestionsList.innerHTML = '';
            
            // Add context-aware suggestions based on intent
            switch (intent) {
                case 'risk_count':
                    suggestionsList.innerHTML = `
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me risks by status')">Risks by Status</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me risks by department')">Risks by Department</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me high risk items')">High Risk Items</div>
                    `;
                    break;
                    
                case 'user_count':
                    suggestionsList.innerHTML = `
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many admin users are there?')">Admin Users</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many staff users are there?')">Staff Users</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many pending users?')">Pending Users</div>
                    `;
                    break;
                    
                case 'recent_activities':
                    suggestionsList.innerHTML = `
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me login activities')">Login Activities</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me failed login attempts')">Failed Logins</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Show me user management activities')">User Activities</div>
                    `;
                    break;
                    
                case 'system_status':
                    suggestionsList.innerHTML = `
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many open risks are there?')">Open Risks</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many users are in the system?')">User Count</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Recent activities')">Recent Activities</div>
                    `;
                    break;
                    
                default:
                    // Default suggestions
                    suggestionsList.innerHTML = `
                        <div class="suggestion-chip" onclick="sendQuickMessage('System status')">System Status</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('How many open risks are there?')">Open Risks</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Recent activities')">Recent Activities</div>
                        <div class="suggestion-chip" onclick="sendQuickMessage('Help')">Help</div>
                    `;
            }
        }
        
        // Scroll to bottom
        function scrollToBottom() {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Show notification
        function showNotification(type, title, message) {
            const notification = document.getElementById('notification');
            const icon = notification.querySelector('.notification-icon i');
            const titleElement = notification.querySelector('h4');
            const messageElement = notification.querySelector('p');
            
            // Set notification type
            notification.className = `notification ${type}`;
            
            // Set icon based on type
            if (type === 'success') {
                icon.className = 'fas fa-check-circle';
            } else if (type === 'error') {
                icon.className = 'fas fa-exclamation-circle';
            } else if (type === 'info') {
                icon.className = 'fas fa-info-circle';
            }
            
            // Set content
            titleElement.textContent = title;
            messageElement.textContent = message;
            
            // Show notification
            notification.classList.add('show');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                closeNotification();
            }, 5000);
        }
        
        // Close notification
        function closeNotification() {
            const notification = document.getElementById('notification');
            notification.classList.remove('show');
        }
        
        // Get user initials
        function getInitials(name) {
            return name.split(' ').map(n => n[0]).join('').toUpperCase();
        }
        
        // Initialize on page load
        window.onload = function() {
            // Load theme preference
            const savedTheme = localStorage.getItem('darkMode');
            if (savedTheme === 'true') {
                toggleTheme();
            }
            
            // Initialize voice recognition
            initVoiceRecognition();
            
            // Focus on input
            document.getElementById('chatInput').focus();
            
            // Hide suggestions after initial message
            setTimeout(() => {
                const suggestionsContainer = document.getElementById('suggestionsContainer');
                suggestionsContainer.style.display = 'none';
            }, 10000);
        };
    </script>
</body>
</html>
