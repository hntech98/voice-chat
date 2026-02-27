<?php
/**
 * Admin Members Management API
 * Handles adding, removing, and managing members
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Require admin login for all operations
session_start();
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    jsonResponse(['error' => 'Unauthorized - Admin login required'], 401);
}

$action = $_GET['action'] ?? '';
$db = Database::getInstance();

switch ($action) {
    case 'list':
        listMembers($db);
        break;
    case 'get':
        getMember($db);
        break;
    case 'add':
        addMember($db);
        break;
    case 'update':
        updateMember($db);
        break;
    case 'delete':
        deleteMember($db);
        break;
    case 'toggle-active':
        toggleMemberActive($db);
        break;
    case 'toggle-speaker':
        toggleMemberSpeaker($db);
        break;
    case 'reset-password':
        resetMemberPassword($db);
        break;
    case 'bulk-import':
        bulkImportMembers($db);
        break;
    case 'activity':
        getActivityLog($db);
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function listMembers($db) {
    $page = intval($_GET['page'] ?? 1);
    $perPage = intval($_GET['per_page'] ?? 20);
    $search = sanitize($_GET['search'] ?? '');
    $status = sanitize($_GET['status'] ?? '');
    
    $offset = ($page - 1) * $perPage;
    $where = "1=1";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (username LIKE ? OR display_name LIKE ? OR email LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }
    
    if ($status === 'active') {
        $where .= " AND is_active = 1";
    } elseif ($status === 'inactive') {
        $where .= " AND is_active = 0";
    } elseif ($status === 'speakers') {
        $where .= " AND is_speaker = 1";
    }
    
    // Get total count
    $stmt = $db->query("SELECT COUNT(*) as total FROM members WHERE $where", $params);
    $total = $stmt->fetch()['total'];
    
    // Get members
    $stmt = $db->query(
        "SELECT id, username, display_name, email, avatar, is_active, is_speaker, created_at, last_seen
         FROM members WHERE $where
         ORDER BY created_at DESC
         LIMIT $perPage OFFSET $offset",
        $params
    );
    $members = $stmt->fetchAll();
    
    jsonResponse([
        'members' => $members,
        'pagination' => [
            'total' => intval($total),
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);
}

function getMember($db) {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    $stmt = $db->query(
        "SELECT id, username, display_name, email, avatar, is_active, is_speaker, created_at, last_seen
         FROM members WHERE id = ?",
        [$id]
    );
    
    $member = $stmt->fetch();
    
    if (!$member) {
        jsonResponse(['error' => 'Member not found'], 404);
    }
    
    // Get room participation history
    $stmt = $db->query(
        "SELECT r.name as room_name, rp.joined_at, rp.left_at, rp.is_speaker
         FROM room_participants rp
         JOIN rooms r ON rp.room_id = r.id
         WHERE rp.member_id = ?
         ORDER BY rp.joined_at DESC
         LIMIT 20",
        [$id]
    );
    $member['room_history'] = $stmt->fetchAll();
    
    jsonResponse(['member' => $member]);
}

function addMember($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $displayName = sanitize($input['display_name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $isSpeaker = intval($input['is_speaker'] ?? 0);
    
    // Validation
    if (empty($username)) {
        jsonResponse(['error' => 'Username is required'], 400);
    }
    
    if (strlen($username) < 3 || strlen($username) > 50) {
        jsonResponse(['error' => 'Username must be between 3 and 50 characters'], 400);
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        jsonResponse(['error' => 'Username can only contain letters, numbers, and underscores'], 400);
    }
    
    if (empty($password)) {
        jsonResponse(['error' => 'Password is required'], 400);
    }
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        jsonResponse(['error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'], 400);
    }
    
    // Check if username exists
    $stmt = $db->query("SELECT id FROM members WHERE username = ?", [$username]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Username already exists'], 409);
    }
    
    // Check if email exists (if provided)
    if (!empty($email)) {
        $stmt = $db->query("SELECT id FROM members WHERE email = ?", [$email]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Email already exists'], 409);
        }
    }
    
    // Hash password
    $hashedPassword = hashPassword($password);
    
    // Insert member
    $stmt = $db->query(
        "INSERT INTO members (username, password, display_name, email, is_speaker) VALUES (?, ?, ?, ?, ?)",
        [$username, $hashedPassword, $displayName, $email, $isSpeaker]
    );
    
    $memberId = $db->lastInsertId();
    
    logActivity('member_added', 'member', $memberId, json_encode([
        'username' => $username,
        'display_name' => $displayName,
        'is_speaker' => $isSpeaker
    ]));
    
    jsonResponse([
        'success' => true,
        'message' => 'Member added successfully',
        'member' => [
            'id' => $memberId,
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'is_speaker' => $isSpeaker
        ]
    ]);
}

function updateMember($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $displayName = sanitize($input['display_name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    // Check if member exists
    $stmt = $db->query("SELECT * FROM members WHERE id = ?", [$id]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Member not found'], 404);
    }
    
    // Check if email exists for another member
    if (!empty($email)) {
        $stmt = $db->query("SELECT id FROM members WHERE email = ? AND id != ?", [$email, $id]);
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Email already exists'], 409);
        }
    }
    
    $db->query(
        "UPDATE members SET display_name = ?, email = ? WHERE id = ?",
        [$displayName, $email, $id]
    );
    
    logActivity('member_updated', 'member', $id);
    
    jsonResponse(['success' => true, 'message' => 'Member updated successfully']);
}

function deleteMember($db) {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    // Get member info before deletion
    $stmt = $db->query("SELECT username FROM members WHERE id = ?", [$id]);
    $member = $stmt->fetch();
    
    if (!$member) {
        jsonResponse(['error' => 'Member not found'], 404);
    }
    
    // Delete member (cascade will handle related records)
    $db->query("DELETE FROM members WHERE id = ?", [$id]);
    
    logActivity('member_deleted', 'member', $id, json_encode(['username' => $member['username']]));
    
    jsonResponse(['success' => true, 'message' => 'Member deleted successfully']);
}

function toggleMemberActive($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $isActive = intval($input['is_active'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    $db->query("UPDATE members SET is_active = ? WHERE id = ?", [$isActive, $id]);
    
    // If deactivating, end all their sessions
    if (!$isActive) {
        $db->query("DELETE FROM sessions WHERE member_id = ?", [$id]);
        $db->query("UPDATE room_participants SET left_at = NOW() WHERE member_id = ? AND left_at IS NULL", [$id]);
    }
    
    logActivity('member_toggled', 'member', $id, json_encode(['is_active' => $isActive]));
    
    jsonResponse(['success' => true, 'message' => $isActive ? 'Member activated' : 'Member deactivated']);
}

function toggleMemberSpeaker($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $isSpeaker = intval($input['is_speaker'] ?? 0);
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    $db->query("UPDATE members SET is_speaker = ? WHERE id = ?", [$isSpeaker, $id]);
    
    // Update in active rooms
    $db->query(
        "UPDATE room_participants SET is_speaker = ? WHERE member_id = ? AND left_at IS NULL",
        [$isSpeaker, $id]
    );
    
    logActivity('speaker_toggled', 'member', $id, json_encode(['is_speaker' => $isSpeaker]));
    
    jsonResponse(['success' => true, 'message' => $isSpeaker ? 'Member is now a speaker' : 'Member is now a listener']);
}

function resetMemberPassword($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $newPassword = $input['new_password'] ?? '';
    
    if ($id <= 0) {
        jsonResponse(['error' => 'Invalid member ID'], 400);
    }
    
    if (empty($newPassword)) {
        jsonResponse(['error' => 'New password is required'], 400);
    }
    
    if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        jsonResponse(['error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'], 400);
    }
    
    $hashedPassword = hashPassword($newPassword);
    
    $db->query("UPDATE members SET password = ? WHERE id = ?", [$hashedPassword, $id]);
    
    // End all sessions for this member
    $db->query("DELETE FROM sessions WHERE member_id = ?", [$id]);
    
    logActivity('password_reset', 'member', $id);
    
    jsonResponse(['success' => true, 'message' => 'Password reset successfully']);
}

function bulkImportMembers($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $members = $input['members'] ?? [];
    
    if (empty($members) || !is_array($members)) {
        jsonResponse(['error' => 'No members provided'], 400);
    }
    
    $results = ['success' => 0, 'failed' => 0, 'errors' => []];
    
    foreach ($members as $index => $member) {
        $username = sanitize($member['username'] ?? '');
        $password = $member['password'] ?? '';
        $displayName = sanitize($member['display_name'] ?? '');
        
        if (empty($username) || empty($password)) {
            $results['failed']++;
            $results['errors'][] = "Row $index: Missing username or password";
            continue;
        }
        
        // Check if exists
        $stmt = $db->query("SELECT id FROM members WHERE username = ?", [$username]);
        if ($stmt->fetch()) {
            $results['failed']++;
            $results['errors'][] = "Row $index: Username '$username' already exists";
            continue;
        }
        
        $hashedPassword = hashPassword($password);
        
        try {
            $db->query(
                "INSERT INTO members (username, password, display_name) VALUES (?, ?, ?)",
                [$username, $hashedPassword, $displayName]
            );
            $results['success']++;
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = "Row $index: " . $e->getMessage();
        }
    }
    
    logActivity('bulk_import', 'member', null, json_encode($results));
    
    jsonResponse($results);
}

function getActivityLog($db) {
    $page = intval($_GET['page'] ?? 1);
    $perPage = intval($_GET['per_page'] ?? 50);
    $offset = ($page - 1) * $perPage;
    
    $stmt = $db->query(
        "SELECT al.*, a.username as admin_name
         FROM activity_log al
         LEFT JOIN admins a ON al.admin_id = a.id
         ORDER BY al.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    
    $logs = $stmt->fetchAll();
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM activity_log");
    $total = $stmt->fetch()['total'];
    
    jsonResponse([
        'logs' => $logs,
        'pagination' => [
            'total' => intval($total),
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);
}
