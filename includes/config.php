<?php
/**
 * Voice Chat Application Configuration
 * Edit this file to match your server settings
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'voice_chat');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'Voice Chat');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost:4000');
define('APP_DEBUG', true);

// Security Settings
define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// WebSocket Server Settings
define('WS_HOST', '0.0.0.0');
define('WS_PORT', 4001);

// File Upload Settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif']);

// Room Settings
define('DEFAULT_MAX_PARTICIPANTS', 50);
define('ENABLE_RECORDING', false);

// Timezone
date_default_timezone_set('UTC');

// Error Reporting (disable in production)
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Database Connection Class
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die("Database connection failed: " . $e->getMessage());
            }
            die("Database connection failed. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
}

/**
 * Helper Functions
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function isLoggedIn() {
    return isset($_SESSION['member_id']) && !empty($_SESSION['member_id']);
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
}

function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function logActivity($action, $targetType = null, $targetId = null, $details = null) {
    $db = Database::getInstance();
    $adminId = $_SESSION['admin_id'] ?? null;
    
    $db->query(
        "INSERT INTO activity_log (admin_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?",
        [$adminId, $action, $targetType, $targetId, $details]
    );
}
