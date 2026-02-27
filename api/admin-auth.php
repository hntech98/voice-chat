<?php
/**
 * Admin Authentication API
 * Handles admin login, logout, and session management
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
        handleAdminLogin($db);
        break;
    case 'logout':
        handleAdminLogout();
        break;
    case 'check':
        checkAdminSession();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function handleAdminLogin($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    // Find admin
    $stmt = $db->query(
        "SELECT * FROM admins WHERE username = ?",
        [$username]
    );
    $admin = $stmt->fetch();
    
    if (!$admin || !verifyPassword($password, $admin['password'])) {
        jsonResponse(['error' => 'Invalid username or password'], 401);
    }
    
    // Update last login
    $db->query("UPDATE admins SET last_login = NOW() WHERE id = ?", [$admin['id']]);
    
    // Set session
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    
    jsonResponse([
        'success' => true,
        'admin' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'email' => $admin['email']
        ]
    ]);
}

function handleAdminLogout() {
    unset($_SESSION['admin_id']);
    unset($_SESSION['admin_username']);
    session_destroy();
    
    jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}

function checkAdminSession() {
    if (!isAdminLoggedIn()) {
        jsonResponse(['authenticated' => false]);
    }
    
    $db = Database::getInstance();
    $stmt = $db->query("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        unset($_SESSION['admin_id']);
        jsonResponse(['authenticated' => false]);
    }
    
    jsonResponse([
        'authenticated' => true,
        'admin' => [
            'id' => $admin['id'],
            'username' => $admin['username'],
            'email' => $admin['email']
        ]
    ]);
}
