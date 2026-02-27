<?php
require_once __DIR__ . '/../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = Database::getInstance();

// Handle room actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $maxParticipants = intval($_POST['max_participants'] ?? 50);
            
            if (empty($name)) {
                echo json_encode(['error' => 'Room name is required']);
                exit;
            }
            
            $db->query(
                "INSERT INTO rooms (name, description, created_by, max_participants) VALUES (?, ?, ?, ?)",
                [$name, $description, $_SESSION['admin_id'], $maxParticipants]
            );
            
            $roomId = $db->lastInsertId();
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'room_created', 'room', $roomId, json_encode(['name' => $name])]
            );
            
            echo json_encode(['success' => true, 'room_id' => $roomId]);
            exit;
            
        case 'end':
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid room ID']);
                exit;
            }
            
            // End room
            $db->query("UPDATE rooms SET is_active = 0 WHERE id = ?", [$id]);
            
            // Remove all participants
            $db->query("UPDATE room_participants SET left_at = NOW() WHERE room_id = ? AND left_at IS NULL", [$id]);
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'room_ended', 'room', $id]
            );
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'make-speaker':
            $roomId = intval($_POST['room_id'] ?? 0);
            $memberId = intval($_POST['member_id'] ?? 0);
            $isSpeaker = intval($_POST['is_speaker'] ?? 1);
            
            $db->query(
                "UPDATE room_participants SET is_speaker = ? WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
                [$isSpeaker, $roomId, $memberId]
            );
            
            // Update global speaker status
            $db->query("UPDATE members SET is_speaker = ? WHERE id = ?", [$isSpeaker, $memberId]);
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'kick':
            $roomId = intval($_POST['room_id'] ?? 0);
            $memberId = intval($_POST['member_id'] ?? 0);
            
            $db->query(
                "UPDATE room_participants SET left_at = NOW() WHERE room_id = ? AND member_id = ? AND left_at IS NULL",
                [$roomId, $memberId]
            );
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'member_kicked', 'member', $memberId]
            );
            
            echo json_encode(['success' => true]);
            exit;
    }
}

// Get rooms
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get total count
$stmt = $db->query("SELECT COUNT(*) as total FROM rooms");
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $perPage);

// Get rooms with participant count
$stmt = $db->query(
    "SELECT r.*, a.username as created_by_name,
            (SELECT COUNT(*) FROM room_participants WHERE room_id = r.id AND left_at IS NULL) as participant_count
     FROM rooms r
     JOIN admins a ON r.created_by = a.id
     ORDER BY r.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$rooms = $stmt->fetchAll();

// Check if viewing specific room
$viewRoom = null;
$participants = [];
if (isset($_GET['id'])) {
    $roomId = intval($_GET['id']);
    $stmt = $db->query(
        "SELECT r.*, a.username as created_by_name FROM rooms r
         JOIN admins a ON r.created_by = a.id
         WHERE r.id = ?",
        [$roomId]
    );
    $viewRoom = $stmt->fetch();
    
    if ($viewRoom) {
        $stmt = $db->query(
            "SELECT rp.*, m.username, m.display_name, m.avatar, m.is_speaker as is_global_speaker
             FROM room_participants rp
             JOIN members m ON rp.member_id = m.id
             WHERE rp.room_id = ? AND rp.left_at IS NULL
             ORDER BY rp.is_speaker DESC, rp.joined_at ASC",
            [$roomId]
        );
        $participants = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms - Admin Panel</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="admin-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>🎛️ Voice Chat</h2>
                <span class="badge">Admin</span>
            </div>
            
            <nav class="sidebar-nav">
                <a href="/admin/index.php" class="nav-link">
                    <span class="icon">📊</span>
                    Dashboard
                </a>
                <a href="/admin/members.php" class="nav-link">
                    <span class="icon">👥</span>
                    Members
                </a>
                <a href="/admin/rooms.php" class="nav-link active">
                    <span class="icon">🏠</span>
                    Rooms
                </a>
                <a href="/admin/activity.php" class="nav-link">
                    <span class="icon">📋</span>
                    Activity Log
                </a>
                <a href="/admin/settings.php" class="nav-link">
                    <span class="icon">⚙️</span>
                    Settings
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="/api/admin-auth.php?action=logout" class="nav-link">
                    <span class="icon">🚪</span>
                    Logout
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="content-header">
                <h1><?php echo $viewRoom ? 'Room: ' . htmlspecialchars($viewRoom['name']) : 'Rooms Management'; ?></h1>
                <?php if ($viewRoom): ?>
                    <a href="/admin/rooms.php" class="btn btn-secondary">← Back to Rooms</a>
                <?php else: ?>
                    <button class="btn btn-primary" onclick="showCreateModal()">
                        + Create Room
                    </button>
                <?php endif; ?>
            </header>
            
            <?php if ($viewRoom): ?>
                <!-- Room Details -->
                <div class="room-detail">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h2><?php echo htmlspecialchars($viewRoom['name']); ?></h2>
                                <p><?php echo htmlspecialchars($viewRoom['description'] ?? 'No description'); ?></p>
                            </div>
                            <div>
                                <span class="badge badge-<?php echo $viewRoom['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $viewRoom['is_active'] ? 'Active' : 'Ended'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="room-stats">
                                <div class="stat">
                                    <span class="stat-value"><?php echo count($participants); ?></span>
                                    <span class="stat-label">Participants</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-value"><?php echo $viewRoom['max_participants']; ?></span>
                                    <span class="stat-label">Max Capacity</span>
                                </div>
                                <div class="stat">
                                    <span class="stat-value"><?php echo date('M j, Y H:i', strtotime($viewRoom['created_at'])); ?></span>
                                    <span class="stat-label">Created</span>
                                </div>
                            </div>
                            
                            <?php if ($viewRoom['is_active']): ?>
                                <div class="room-actions">
                                    <button class="btn btn-danger" onclick="endRoom(<?php echo $viewRoom['id']; ?>)">
                                        End Room
                                    </button>
                                    <a href="/room/<?php echo $viewRoom['id']; ?>" class="btn btn-primary" target="_blank">
                                        Join Room
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Participants List -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Participants</h2>
                        </div>
                        <div class="card-body">
                            <?php if (empty($participants)): ?>
                                <p class="empty-state">No participants currently in this room</p>
                            <?php else: ?>
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($participants as $p): ?>
                                            <tr>
                                                <td>
                                                    <div class="member-info">
                                                        <div class="member-avatar">
                                                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <div class="member-name">
                                                                <?php echo htmlspecialchars($p['display_name'] ?: $p['username']); ?>
                                                            </div>
                                                            <div class="member-username">
                                                                @<?php echo htmlspecialchars($p['username']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($p['is_speaker']): ?>
                                                        <span class="badge badge-primary">🎤 Speaker</span>
                                                    <?php else: ?>
                                                        <span class="badge">Listener</span>
                                                        <?php if ($p['hand_raised']): ?>
                                                            <span class="badge badge-warning">✋ Hand Raised</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($p['is_muted']): ?>
                                                        <span class="badge">🔇 Muted</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-success">🔊 Speaking</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('H:i', strtotime($p['joined_at'])); ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if (!$p['is_speaker'] && $p['hand_raised']): ?>
                                                            <button class="btn btn-sm btn-success" onclick="makeSpeaker(<?php echo $viewRoom['id']; ?>, <?php echo $p['member_id']; ?>, 1)">
                                                                Make Speaker
                                                            </button>
                                                        <?php elseif ($p['is_speaker']): ?>
                                                            <button class="btn btn-sm btn-secondary" onclick="makeSpeaker(<?php echo $viewRoom['id']; ?>, <?php echo $p['member_id']; ?>, 0)">
                                                                Make Listener
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-danger" onclick="kickParticipant(<?php echo $viewRoom['id']; ?>, <?php echo $p['member_id']; ?>, '<?php echo htmlspecialchars($p['username']); ?>')">
                                                            Kick
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Rooms List -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($rooms)): ?>
                            <p class="empty-state">No rooms yet. Create one to get started!</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Status</th>
                                        <th>Participants</th>
                                        <th>Created By</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $room): ?>
                                        <tr>
                                            <td>
                                                <a href="?id=<?php echo $room['id']; ?>" class="room-link">
                                                    <?php echo htmlspecialchars($room['name']); ?>
                                                </a>
                                                <?php if ($room['description']): ?>
                                                    <small class="room-desc"><?php echo htmlspecialchars($room['description']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $room['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $room['is_active'] ? 'Active' : 'Ended'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="participant-count">
                                                    <?php echo $room['participant_count']; ?> / <?php echo $room['max_participants']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($room['created_by_name']); ?></td>
                                            <td><?php echo date('M j, H:i', strtotime($room['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="?id=<?php echo $room['id']; ?>" class="btn btn-sm btn-secondary">View</a>
                                                    <?php if ($room['is_active']): ?>
                                                        <button class="btn btn-sm btn-danger" onclick="endRoom(<?php echo $room['id']; ?>)">End</button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?php echo $page - 1; ?>" class="btn btn-sm">&laquo; Prev</a>
                                    <?php endif; ?>
                                    
                                    <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                                    
                                    <?php if ($page < $totalPages): ?>
                                        <a href="?page=<?php echo $page + 1; ?>" class="btn btn-sm">Next &raquo;</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <!-- Create Room Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Room</h2>
                <button class="close-btn" onclick="closeModal('createModal')">&times;</button>
            </div>
            <form id="createForm" class="modal-body">
                <div class="form-group">
                    <label for="create_name">Room Name *</label>
                    <input type="text" id="create_name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="create_description">Description</label>
                    <textarea id="create_description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="create_max_participants">Max Participants</label>
                    <input type="number" id="create_max_participants" name="max_participants" value="50" min="2" max="500">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Room</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('createForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'create');
            
            try {
                const response = await fetch('/admin/rooms.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = '?id=' + data.room_id;
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });
        
        function showCreateModal() {
            document.getElementById('createForm').reset();
            document.getElementById('createModal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function endRoom(roomId) {
            if (confirm('Are you sure you want to end this room? All participants will be disconnected.')) {
                const formData = new FormData();
                formData.append('action', 'end');
                formData.append('id', roomId);
                
                fetch('/admin/rooms.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                });
            }
        }
        
        function makeSpeaker(roomId, memberId, isSpeaker) {
            const formData = new FormData();
            formData.append('action', 'make-speaker');
            formData.append('room_id', roomId);
            formData.append('member_id', memberId);
            formData.append('is_speaker', isSpeaker);
            
            fetch('/admin/rooms.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            });
        }
        
        function kickParticipant(roomId, memberId, username) {
            if (confirm(`Are you sure you want to kick "${username}" from this room?`)) {
                const formData = new FormData();
                formData.append('action', 'kick');
                formData.append('room_id', roomId);
                formData.append('member_id', memberId);
                
                fetch('/admin/rooms.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                });
            }
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
