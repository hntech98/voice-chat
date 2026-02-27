<?php
/**
 * Authentication API
 * Handles member login, logout, and session management
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$db = Database::getInstance();

switch ($action) {
    case 'login':
        handleLogin($db);
        break;
    case 'logout':
        handleLogout($db);
        break;
    case 'check':
        checkSession($db);
        break;
    case 'register':
        // Registration is disabled - only admin can add members
        jsonResponse(['error' => 'Registration is disabled. Contact admin.'], 403);
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function handleLogin($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    // Check login attempts (simple rate limiting)
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $db->query(
        "SELECT COUNT(*) as attempts FROM activity_log 
         WHERE action = 'login_failed' 
         AND details LIKE ? 
         AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
        ['%' . $ip . '%', LOGIN_LOCKOUT_TIME]
    );
    $result = $stmt->fetch();
    
    if ($result['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        jsonResponse(['error' => 'Too many login attempts. Please try again later.'], 429);
    }
    
    // Find member
    $stmt = $db->query(
        "SELECT * FROM members WHERE username = ? AND is_active = 1",
        [$username]
    );
    $member = $stmt->fetch();
    
    if (!$member || !verifyPassword($password, $member['password'])) {
        // Log failed attempt
        $db->query(
            "INSERT INTO activity_log (action, target_type, details) VALUES (?, ?, ?)",
            ['login_failed', 'member', json_encode(['ip' => $ip, 'username' => $username])]
        );
        jsonResponse(['error' => 'Invalid username or password'], 401);
    }
    
    // Create session
    $sessionId = generateToken(64);
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    
    $db->query(
        "INSERT INTO sessions (id, member_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)",
        [$sessionId, $member['id'], $ip, $_SERVER['HTTP_USER_AGENT'], $expiresAt]
    );
    
    // Update last seen
    $db->query("UPDATE members SET last_seen = NOW() WHERE id = ?", [$member['id']]);
    
    // Set session
    $_SESSION['member_id'] = $member['id'];
    $_SESSION['session_id'] = $sessionId;
    
    // Log successful login
    $db->query(
        "INSERT INTO activity_log (action, target_type, target_id) VALUES (?, ?, ?)",
        ['login_success', 'member', $member['id']]
    );
    
    jsonResponse([
        'success' => true,
        'member' => [
            'id' => $member['id'],
            'username' => $member['username'],
            'display_name' => $member['display_name'],
            'avatar' => $member['avatar'],
            'is_speaker' => $member['is_speaker']
        ],
        'token' => $sessionId
    ]);
}

function handleLogout($db) {
    $sessionId = $_SESSION['session_id'] ?? null;
    
    if ($sessionId) {
        $db->query("DELETE FROM sessions WHERE id = ?", [$sessionId]);
    }
    
    session_destroy();
    
    jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}

function checkSession($db) {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    
    if (empty($token)) {
        $token = $_SESSION['session_id'] ?? '';
    }
    
    if (empty($token)) {
        jsonResponse(['authenticated' => false]);
    }
    
    $stmt = $db->query(
        "SELECT m.*, s.expires_at FROM sessions s 
         JOIN members m ON s.member_id = m.id 
         WHERE s.id = ? AND s.expires_at > NOW() AND m.is_active = 1",
        [$token]
    );
    
    $session = $stmt->fetch();
    
    if (!$session) {
        jsonResponse(['authenticated' => false]);
    }
    
    $_SESSION['member_id'] = $session['id'];
    $_SESSION['session_id'] = $token;
    
    jsonResponse([
        'authenticated' => true,
        'member' => [
            'id' => $session['id'],
            'username' => $session['username'],
            'display_name' => $session['display_name'],
            'avatar' => $session['avatar'],
            'is_speaker' => $session['is_speaker']
        ]
    ]);
}
