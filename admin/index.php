<?php
require_once __DIR__ . '/../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

// Get stats
$db = Database::getInstance();

$stats = [];

// Total members
$stmt = $db->query("SELECT COUNT(*) as count FROM members");
$stats['total_members'] = $stmt->fetch()['count'];

// Active members
$stmt = $db->query("SELECT COUNT(*) as count FROM members WHERE is_active = 1");
$stats['active_members'] = $stmt->fetch()['count'];

// Total speakers
$stmt = $db->query("SELECT COUNT(*) as count FROM members WHERE is_speaker = 1");
$stats['speakers'] = $stmt->fetch()['count'];

// Active rooms
$stmt = $db->query("SELECT COUNT(*) as count FROM rooms WHERE is_active = 1");
$stats['active_rooms'] = $stmt->fetch()['count'];

// Current participants
$stmt = $db->query("SELECT COUNT(*) as count FROM room_participants WHERE left_at IS NULL");
$stats['current_participants'] = $stmt->fetch()['count'];

// Recent members
$stmt = $db->query(
    "SELECT id, username, display_name, is_active, is_speaker, created_at 
     FROM members 
     ORDER BY created_at DESC 
     LIMIT 5"
);
$recentMembers = $stmt->fetchAll();

// Active rooms with participants
$stmt = $db->query(
    "SELECT r.id, r.name, r.created_at,
            (SELECT COUNT(*) FROM room_participants WHERE room_id = r.id AND left_at IS NULL) as participants
     FROM rooms r
     WHERE r.is_active = 1
     ORDER BY participants DESC
     LIMIT 5"
);
$activeRooms = $stmt->fetchAll();

// Recent activity
$stmt = $db->query(
    "SELECT al.*, a.username as admin_name
     FROM activity_log al
     LEFT JOIN admins a ON al.admin_id = a.id
     ORDER BY al.created_at DESC
     LIMIT 10"
);
$recentActivity = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin Panel</title>
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
                <a href="/admin/index.php" class="nav-link active">
                    <span class="icon">📊</span>
                    Dashboard
                </a>
                <a href="/admin/members.php" class="nav-link">
                    <span class="icon">👥</span>
                    Members
                </a>
                <a href="/admin/rooms.php" class="nav-link">
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
                <a href="/" class="nav-link" target="_blank">
                    <span class="icon">🌐</span>
                    View Site
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="content-header">
                <h1>Dashboard</h1>
                <div class="header-actions">
                    <a href="/admin/members.php?action=add" class="btn btn-primary">
                        + Add Member
                    </a>
                    <a href="/admin/rooms.php?action=create" class="btn btn-secondary">
                        + Create Room
                    </a>
                </div>
            </header>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['total_members']; ?></h3>
                        <p>Total Members</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['active_members']; ?></h3>
                        <p>Active Members</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🎤</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['speakers']; ?></h3>
                        <p>Speakers</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🏠</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['active_rooms']; ?></h3>
                        <p>Active Rooms</p>
                    </div>
                </div>
                
                <div class="stat-card highlight">
                    <div class="stat-icon">🎧</div>
                    <div class="stat-content">
                        <h3><?php echo $stats['current_participants']; ?></h3>
                        <p>Currently Online</p>
                    </div>
                </div>
            </div>
            
            <!-- Two Column Layout -->
            <div class="dashboard-grid">
                <!-- Recent Members -->
                <div class="card">
                    <div class="card-header">
                        <h2>Recent Members</h2>
                        <a href="/admin/members.php" class="btn btn-sm">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentMembers)): ?>
                            <p class="empty-state">No members yet</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Status</th>
                                        <th>Role</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentMembers as $member): ?>
                                        <tr>
                                            <td>
                                                <span class="member-name">
                                                    <?php echo htmlspecialchars($member['username']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $member['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($member['is_speaker']): ?>
                                                    <span class="badge badge-primary">Speaker</span>
                                                <?php else: ?>
                                                    <span class="badge">Listener</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j', strtotime($member['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Active Rooms -->
                <div class="card">
                    <div class="card-header">
                        <h2>Active Rooms</h2>
                        <a href="/admin/rooms.php" class="btn btn-sm">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($activeRooms)): ?>
                            <p class="empty-state">No active rooms</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Participants</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeRooms as $room): ?>
                                        <tr>
                                            <td>
                                                <a href="/admin/rooms.php?id=<?php echo $room['id']; ?>">
                                                    <?php echo htmlspecialchars($room['name']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="participant-count">
                                                    <?php echo $room['participants']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j', strtotime($room['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h2>Recent Activity</h2>
                    <a href="/admin/activity.php" class="btn btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentActivity)): ?>
                        <p class="empty-state">No activity yet</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Admin</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentActivity as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="action-badge action-<?php echo $log['action']; ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($log['target_type']) {
                                                echo htmlspecialchars($log['target_type']);
                                                if ($log['target_id']) {
                                                    echo ' #' . $log['target_id'];
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?></td>
                                        <td><?php echo date('M j, H:i', strtotime($log['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
