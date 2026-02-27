<?php
require_once __DIR__ . '/../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = Database::getInstance();

// Get activity log
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filter by action type
$action = trim($_GET['action'] ?? '');
$where = "1=1";
$params = [];

if (!empty($action)) {
    $where .= " AND action = ?";
    $params[] = $action;
}

// Get total count
$stmt = $db->query("SELECT COUNT(*) as total FROM activity_log WHERE $where", $params);
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $perPage);

// Get logs
$stmt = $db->query(
    "SELECT al.*, a.username as admin_name
     FROM activity_log al
     LEFT JOIN admins a ON al.admin_id = a.id
     WHERE $where
     ORDER BY al.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);
$logs = $stmt->fetchAll();

// Get unique actions for filter
$stmt = $db->query("SELECT DISTINCT action FROM activity_log ORDER BY action");
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - Admin Panel</title>
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
                <a href="/admin/rooms.php" class="nav-link">
                    <span class="icon">🏠</span>
                    Rooms
                </a>
                <a href="/admin/activity.php" class="nav-link active">
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
                <h1>Activity Log</h1>
            </header>
            
            <!-- Filters -->
            <div class="filters-bar">
                <form method="GET" class="search-form">
                    <select name="action" class="filter-select">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $a): ?>
                            <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action === $a ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="btn btn-secondary">Filter</button>
                    <a href="/admin/activity.php" class="btn btn-outline">Clear</a>
                </form>
            </div>
            
            <!-- Activity Table -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($logs)): ?>
                        <p class="empty-state">No activity logs found</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Action</th>
                                    <th>Target</th>
                                    <th>Admin</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M j, H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td>
                                            <span class="action-badge action-<?php echo $log['action']; ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($log['target_type']) {
                                                echo '<span class="badge">' . htmlspecialchars($log['target_type']) . '</span>';
                                                if ($log['target_id']) {
                                                    echo ' #' . $log['target_id'];
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['admin_name'] ?? 'System'); ?></td>
                                        <td>
                                            <?php 
                                            if ($log['details']) {
                                                $details = json_decode($log['details'], true);
                                                if ($details) {
                                                    $detailStr = [];
                                                    foreach ($details as $k => $v) {
                                                        if (is_scalar($v)) {
                                                            $detailStr[] = "$k: $v";
                                                        }
                                                    }
                                                    echo htmlspecialchars(implode(', ', $detailStr));
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&action=<?php echo urlencode($action); ?>" class="btn btn-sm">&laquo; Prev</a>
                                <?php endif; ?>
                                
                                <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&action=<?php echo urlencode($action); ?>" class="btn btn-sm">Next &raquo;</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
