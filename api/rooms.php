<?php
/**
 * Rooms API
 * Handles room creation, listing, and management
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? '';
$db = Database::getInstance();

// Check authentication for write operations
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
    requireLogin();
}

switch ($action) {
    case 'list':
        listRooms($db);
        break;
    case 'get':
        getRoom($db);
        break;
    case 'create':
        createRoom($db);
        break;
    case 'join':
        joinRoom($db);
        break;
    case 'leave':
        leaveRoom($db);
        break;
    case 'participants':
        getParticipants($db);
        break;
    case 'update-status':
        updateStatus($db);
        break;
    case 'raise-hand':
        raiseHand($db);
        break;
    case 'delete':
        deleteRoom($db);
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function listRooms($db) {
    $stmt = $db->query(
        "SELECT r.*, a.username as created_by_name,
                (SELECT COUNT(*) FROM room_participants WHERE room_id = r.id AND left_at IS NULL) as participant_count
         FROM rooms r
         JOIN admins a ON r.created_by = a.id
         WHERE r.is_active = 1
         ORDER BY r.created_at DESC"
    );
    
    $rooms = $stmt->fetchAll();
    
    jsonResponse(['rooms' => $rooms]);
}

function getRoom($db) {
    $roomId = intval($_GET['id'] ?? 0);
    
    if ($roomId <= 0) {
        jsonResponse(['error' => 'Invalid room ID'], 400);
    }
    
    $stmt = $db->query(
        "SELECT r.*, a.username as created_by_name FROM rooms r
         JOIN admins a ON r.created_by = a.id
         WHERE r.id = ? AND r.is_active = 1",
        [$roomId]
    );
    
    $room = $stmt->fetch();
    
    if (!$room) {
        jsonResponse(['error' => 'Room not found'], 404);
    }
    
    // Get participants
    $stmt = $db->query(
        "SELECT rp.*, m.username, m.display_name, m.avatar, m.is_speaker as is_global_speaker
         FROM room_participants rp
         JOIN members m ON rp.member_id = m.id
         WHERE rp.room_id = ? AND rp.left_at IS NULL
         ORDER BY rp.is_speaker DESC, rp.joined_at ASC",
        [$roomId]
    );
    
    $room['participants'] = $stmt->fetchAll();
    
    jsonResponse(['room' => $room]);
}

function createRoom($db) {
    // Only admins can create rooms
    if (!isAdminLoggedIn()) {
        jsonResponse(['error' => 'Only admins can create rooms'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = sanitize($input['name'] ?? '');
    $description = sanitize($input['description'] ?? '');
    $maxParticipants = intval($input['max_participants'] ?? DEFAULT_MAX_PARTICIPANTS);
    
    if (empty($name)) {
        jsonResponse(['error' => 'Room name is required'], 400);
    }
    
    $stmt = $db->query(
        "INSERT INTO rooms (name, description, created_by, max_participants) VALUES (?, ?, ?, ?)",
        [$name, $description, $_SESSION['admin_id'], $maxParticipants]
    );
    
    $roomId = $db->lastInsertId();
    
    logActivity('room_created', 'room', $roomId, json_encode(['name' => $name]));
    
    jsonResponse([
        'success' => true,
        'room' => [
            'id' => $roomId,
            'name' => $name,
            'description' => $description,
            'max_participants' => $maxParticipants
        ]
    ]);
}

function joinRoom($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = intval($input['room_id'] ?? 0);
    $memberId = $_SESSION['member_id'];
    
    if ($roomId <= 0) {
        jsonResponse(['error' => 'Invalid room ID'], 400);
    }
    
    // Check if room exists and is active
    $stmt = $db->query("SELECT * FROM rooms WHERE id = ? AND is_active = 1", [$roomId]);
    $room = $stmt->fetch();
    
    if (!$room) {
        jsonResponse(['error' => 'Room not found'], 404);
    }
    
    // Check if already in room
    $stmt = $db->query(
        "SELECT * FROM room_participants WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
        [$roomId, $memberId]
    );
    
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Already in room'], 400);
    }
    
    // Check participant limit
    $stmt = $db->query(
        "SELECT COUNT(*) as count FROM room_participants WHERE room_id = ? AND left_at IS NULL",
        [$roomId]
    );
    $count = $stmt->fetch()['count'];
    
    if ($count >= $room['max_participants']) {
        jsonResponse(['error' => 'Room is full'], 403);
    }
    
    // Check if member is a speaker
    $stmt = $db->query("SELECT is_speaker FROM members WHERE id = ?", [$memberId]);
    $member = $stmt->fetch();
    $isSpeaker = $member['is_speaker'] ?? 0;
    
    // Join room
    $db->query(
        "INSERT INTO room_participants (room_id, member_id, is_speaker, is_muted) VALUES (?, ?, ?, 1)",
        [$roomId, $memberId, $isSpeaker]
    );
    
    jsonResponse([
        'success' => true,
        'is_speaker' => $isSpeaker,
        'message' => 'Joined room successfully'
    ]);
}

function leaveRoom($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = intval($input['room_id'] ?? 0);
    $memberId = $_SESSION['member_id'];
    
    $db->query(
        "UPDATE room_participants SET left_at = NOW() WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
        [$roomId, $memberId]
    );
    
    jsonResponse(['success' => true, 'message' => 'Left room successfully']);
}

function getParticipants($db) {
    $roomId = intval($_GET['room_id'] ?? 0);
    
    if ($roomId <= 0) {
        jsonResponse(['error' => 'Invalid room ID'], 400);
    }
    
    $stmt = $db->query(
        "SELECT rp.*, m.username, m.display_name, m.avatar 
         FROM room_participants rp
         JOIN members m ON rp.member_id = m.id
         WHERE rp.room_id = ? AND rp.left_at IS NULL
         ORDER BY rp.is_speaker DESC, rp.joined_at ASC",
        [$roomId]
    );
    
    $participants = $stmt->fetchAll();
    
    // Group by role
    $result = [
        'speakers' => [],
        'listeners' => []
    ];
    
    foreach ($participants as $p) {
        if ($p['is_speaker']) {
            $result['speakers'][] = $p;
        } else {
            $result['listeners'][] = $p;
        }
    }
    
    jsonResponse($result);
}

function updateStatus($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = intval($input['room_id'] ?? 0);
    $memberId = $_SESSION['member_id'];
    $isMuted = intval($input['is_muted'] ?? 1);
    
    $db->query(
        "UPDATE room_participants SET is_muted = ? WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
        [$isMuted, $roomId, $memberId]
    );
    
    jsonResponse(['success' => true]);
}

function raiseHand($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $roomId = intval($input['room_id'] ?? 0);
    $memberId = $_SESSION['member_id'];
    $raised = intval($input['raised'] ?? 1);
    
    $db->query(
        "UPDATE room_participants SET hand_raised = ? WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
        [$raised, $roomId, $memberId]
    );
    
    jsonResponse(['success' => true, 'hand_raised' => $raised]);
}

function deleteRoom($db) {
    // Only admins can delete rooms
    if (!isAdminLoggedIn()) {
        jsonResponse(['error' => 'Only admins can delete rooms'], 403);
    }
    
    $roomId = intval($_GET['id'] ?? 0);
    
    if ($roomId <= 0) {
        jsonResponse(['error' => 'Invalid room ID'], 400);
    }
    
    // Mark room as inactive
    $db->query("UPDATE rooms SET is_active = 0 WHERE id = ?", [$roomId]);
    
    // Remove all participants
    $db->query("UPDATE room_participants SET left_at = NOW() WHERE room_id = ? AND left_at IS NULL", [$roomId]);
    
    logActivity('room_deleted', 'room', $roomId);
    
    jsonResponse(['success' => true, 'message' => 'Room deleted successfully']);
}
