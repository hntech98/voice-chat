<?php
require_once __DIR__ . '/../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            // Get current admin
            $stmt = $db->query("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            
            if (!password_verify($currentPassword, $admin['password'])) {
                $error = 'Current password is incorrect';
            } elseif (strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match';
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $db->query("UPDATE admins SET password = ? WHERE id = ?", [$hashedPassword, $_SESSION['admin_id']]);
                $message = 'Password changed successfully';
            }
            break;
            
        case 'update_email':
            $email = trim($_POST['email'] ?? '');
            
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email format';
            } else {
                // Check if email exists for another admin
                $stmt = $db->query("SELECT id FROM admins WHERE email = ? AND id != ?", [$email, $_SESSION['admin_id']]);
                if ($stmt->fetch()) {
                    $error = 'Email already in use';
                } else {
                    $db->query("UPDATE admins SET email = ? WHERE id = ?", [$email, $_SESSION['admin_id']]);
                    $message = 'Email updated successfully';
                }
            }
            break;
    }
}

// Get current admin info
$stmt = $db->query("SELECT * FROM admins WHERE id = ?", [$_SESSION['admin_id']]);
$admin = $stmt->fetch();

// Get system stats
$stmt = $db->query("SELECT COUNT(*) as count FROM members WHERE is_active = 1");
$stats['active_members'] = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM rooms WHERE is_active = 1");
$stats['active_rooms'] = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM sessions");
$stats['active_sessions'] = $stmt->fetch()['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM activity_log");
$stats['total_activity'] = $stmt->fetch()['count'];

// Database size
$stmt = $db->query("
    SELECT 
        SUM(data_length + index_length) as size 
    FROM information_schema.tables 
    WHERE table_schema = '" . DB_NAME . "'
");
$dbSize = $stmt->fetch()['size'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin Panel</title>
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
                <a href="/admin/activity.php" class="nav-link">
                    <span class="icon">📋</span>
                    Activity Log
                </a>
                <a href="/admin/settings.php" class="nav-link active">
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
                <h1>Settings</h1>
            </header>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="settings-grid">
                <!-- Account Settings -->
                <div class="card">
                    <div class="card-header">
                        <h2>🔐 Account Settings</h2>
                    </div>
                    <div class="card-body">
                        <p class="info-text">
                            <strong>Username:</strong> <?php echo htmlspecialchars($admin['username']); ?><br>
                            <strong>Last Login:</strong> <?php echo $admin['last_login'] ? date('M j, Y H:i', strtotime($admin['last_login'])) : 'Never'; ?>
                        </p>
                        
                        <hr>
                        
                        <!-- Change Email -->
                        <h3>Change Email</h3>
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="action" value="update_email">
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>">
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Update Email</button>
                        </form>
                        
                        <hr>
                        
                        <!-- Change Password -->
                        <h3>Change Password</h3>
                        <form method="POST" class="settings-form">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required minlength="6">
                                <small>Minimum 6 characters</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Change Password</button>
                        </form>
                    </div>
                </div>
                
                <!-- System Information -->
                <div class="card">
                    <div class="card-header">
                        <h2>📊 System Information</h2>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <tbody>
                                <tr>
                                    <td><strong>App Version</strong></td>
                                    <td>1.0.0</td>
                                </tr>
                                <tr>
                                    <td><strong>PHP Version</strong></td>
                                    <td><?php echo phpversion(); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Database</strong></td>
                                    <td>MySQL</td>
                                </tr>
                                <tr>
                                    <td><strong>Database Size</strong></td>
                                    <td><?php echo round($dbSize / 1024 / 1024, 2); ?> MB</td>
                                </tr>
                                <tr>
                                    <td><strong>Active Members</strong></td>
                                    <td><?php echo $stats['active_members']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Active Rooms</strong></td>
                                    <td><?php echo $stats['active_rooms']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Active Sessions</strong></td>
                                    <td><?php echo $stats['active_sessions']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Total Activity Logs</strong></td>
                                    <td><?php echo $stats['total_activity']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>WebSocket Port</strong></td>
                                    <td>4001</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h2>⚡ Quick Actions</h2>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="/admin/members.php" class="btn btn-secondary btn-block">
                                👥 Manage Members
                            </a>
                            <a href="/admin/rooms.php" class="btn btn-secondary btn-block">
                                🏠 Manage Rooms
                            </a>
                            <a href="/" class="btn btn-outline btn-block" target="_blank">
                                🌐 View Public Site
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        
        .settings-form {
            max-width: 400px;
        }
        
        .info-text {
            color: var(--text-muted);
            line-height: 2;
        }
        
        hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 1.5rem 0;
        }
        
        h3 {
            font-size: 1rem;
            margin-bottom: 1rem;
            color: var(--text);
        }
        
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }
        
        .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
    </style>
</body>
</html>
