<?php
require_once __DIR__ . '/../includes/config.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$db = Database::getInstance();

// Handle add member form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    switch ($action) {
        case 'add':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $displayName = trim($_POST['display_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $isSpeaker = isset($_POST['is_speaker']) ? 1 : 0;
            
            // Validation
            if (strlen($username) < 3) {
                echo json_encode(['error' => 'Username must be at least 3 characters']);
                exit;
            }
            
            if (strlen($password) < 6) {
                echo json_encode(['error' => 'Password must be at least 6 characters']);
                exit;
            }
            
            // Check if username exists
            $stmt = $db->query("SELECT id FROM members WHERE username = ?", [$username]);
            if ($stmt->fetch()) {
                echo json_encode(['error' => 'Username already exists']);
                exit;
            }
            
            // Insert member
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $db->query(
                "INSERT INTO members (username, password, display_name, email, is_speaker) VALUES (?, ?, ?, ?, ?)",
                [$username, $hashedPassword, $displayName, $email, $isSpeaker]
            );
            
            $memberId = $db->lastInsertId();
            
            // Log activity
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'member_added', 'member', $memberId, json_encode(['username' => $username])]
            );
            
            echo json_encode(['success' => true, 'member_id' => $memberId]);
            exit;
            
        case 'update':
            $id = intval($_POST['id'] ?? 0);
            $displayName = trim($_POST['display_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $isSpeaker = isset($_POST['is_speaker']) ? 1 : 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid member ID']);
                exit;
            }
            
            $db->query(
                "UPDATE members SET display_name = ?, email = ?, is_speaker = ?, is_active = ? WHERE id = ?",
                [$displayName, $email, $isSpeaker, $isActive, $id]
            );
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'member_updated', 'member', $id]
            );
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid member ID']);
                exit;
            }
            
            $db->query("DELETE FROM members WHERE id = ?", [$id]);
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'member_deleted', 'member', $id]
            );
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'reset-password':
            $id = intval($_POST['id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid member ID']);
                exit;
            }
            
            if (strlen($newPassword) < 6) {
                echo json_encode(['error' => 'Password must be at least 6 characters']);
                exit;
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->query("UPDATE members SET password = ? WHERE id = ?", [$hashedPassword, $id]);
            $db->query("DELETE FROM sessions WHERE member_id = ?", [$id]);
            
            $db->query(
                "INSERT INTO activity_log (admin_id, action, target_type, target_id) VALUES (?, ?, ?, ?)",
                [$_SESSION['admin_id'], 'password_reset', 'member', $id]
            );
            
            echo json_encode(['success' => true]);
            exit;
    }
}

// Get members list
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
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
$totalPages = ceil($total / $perPage);

// Get members
$stmt = $db->query(
    "SELECT id, username, display_name, email, avatar, is_active, is_speaker, created_at, last_seen
     FROM members WHERE $where
     ORDER BY created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);
$members = $stmt->fetchAll();

// Check if we're editing a specific member
$editMember = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $stmt = $db->query("SELECT * FROM members WHERE id = ?", [$editId]);
    $editMember = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members - Admin Panel</title>
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
                <a href="/admin/members.php" class="nav-link active">
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
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <header class="content-header">
                <h1>Members Management</h1>
                <button class="btn btn-primary" onclick="showAddModal()">
                    + Add Member
                </button>
            </header>
            
            <!-- Filters -->
            <div class="filters-bar">
                <form method="GET" class="search-form">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search members..." class="search-input">
                    
                    <select name="status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="speakers" <?php echo $status === 'speakers' ? 'selected' : ''; ?>>Speakers Only</option>
                    </select>
                    
                    <button type="submit" class="btn btn-secondary">Search</button>
                    <a href="/admin/members.php" class="btn btn-outline">Clear</a>
                </form>
            </div>
            
            <!-- Members Table -->
            <div class="card">
                <div class="card-body">
                    <?php if (empty($members)): ?>
                        <p class="empty-state">No members found</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Role</th>
                                    <th>Last Seen</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $member): ?>
                                    <tr>
                                        <td>
                                            <div class="member-info">
                                                <div class="member-avatar">
                                                    <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="member-name">
                                                        <?php echo htmlspecialchars($member['display_name'] ?: $member['username']); ?>
                                                    </div>
                                                    <div class="member-username">
                                                        @<?php echo htmlspecialchars($member['username']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($member['email'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $member['is_active'] ? 'success' : 'danger'; ?>">
                                                <?php echo $member['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($member['is_speaker']): ?>
                                                <span class="badge badge-primary">🎤 Speaker</span>
                                            <?php else: ?>
                                                <span class="badge">Listener</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($member['last_seen']) {
                                                $diff = time() - strtotime($member['last_seen']);
                                                if ($diff < 60) echo 'Just now';
                                                elseif ($diff < 3600) echo floor($diff / 60) . 'm ago';
                                                elseif ($diff < 86400) echo floor($diff / 3600) . 'h ago';
                                                else echo date('M j', strtotime($member['last_seen']));
                                            } else {
                                                echo 'Never';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-secondary" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($member)); ?>)">
                                                    Edit
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="showPasswordModal(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['username']); ?>')">
                                                    Reset PW
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteMember(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['username']); ?>')">
                                                    Delete
                                                </button>
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
                                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="btn btn-sm">&laquo; Prev</a>
                                <?php endif; ?>
                                
                                <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>" class="btn btn-sm">Next &raquo;</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Add Member Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Member</h2>
                <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form id="addForm" class="modal-body">
                <div class="form-group">
                    <label for="add_username">Username *</label>
                    <input type="text" id="add_username" name="username" required pattern="[a-zA-Z0-9_]{3,50}">
                    <small>Letters, numbers, and underscores only (3-50 chars)</small>
                </div>
                
                <div class="form-group">
                    <label for="add_password">Password *</label>
                    <input type="password" id="add_password" name="password" required minlength="6">
                    <small>Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="add_display_name">Display Name</label>
                    <input type="text" id="add_display_name" name="display_name">
                </div>
                
                <div class="form-group">
                    <label for="add_email">Email</label>
                    <input type="email" id="add_email" name="email">
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="is_speaker" value="1">
                        <span class="checkbox-label">Is Speaker (can speak in rooms)</span>
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Member</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Member Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Member</h2>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form id="editForm" class="modal-body">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="edit_username" disabled class="disabled-input">
                </div>
                
                <div class="form-group">
                    <label for="edit_display_name">Display Name</label>
                    <input type="text" id="edit_display_name" name="display_name">
                </div>
                
                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email">
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="is_speaker" id="edit_is_speaker" value="1">
                        <span class="checkbox-label">Is Speaker</span>
                    </label>
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                        <span class="checkbox-label">Active</span>
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Password Reset Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content modal-sm">
            <div class="modal-header">
                <h2>Reset Password</h2>
                <button class="close-btn" onclick="closeModal('passwordModal')">&times;</button>
            </div>
            <form id="passwordForm" class="modal-body">
                <input type="hidden" name="id" id="password_id">
                
                <p>Reset password for: <strong id="password_username"></strong></p>
                
                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                    <small>Minimum 6 characters</small>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('passwordModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="/assets/js/admin.js"></script>
    <script>
        // Form submissions
        document.getElementById('addForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'add');
            
            try {
                const response = await fetch('/admin/members.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Member added successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });
        
        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'update');
            
            try {
                const response = await fetch('/admin/members.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Member updated successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });
        
        document.getElementById('passwordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('action', 'reset-password');
            
            try {
                const response = await fetch('/admin/members.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    alert('Password reset successfully!');
                    closeModal('passwordModal');
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        });
        
        function showAddModal() {
            document.getElementById('addForm').reset();
            document.getElementById('addModal').style.display = 'flex';
        }
        
        function showEditModal(member) {
            document.getElementById('edit_id').value = member.id;
            document.getElementById('edit_username').value = member.username;
            document.getElementById('edit_display_name').value = member.display_name || '';
            document.getElementById('edit_email').value = member.email || '';
            document.getElementById('edit_is_speaker').checked = member.is_speaker == 1;
            document.getElementById('edit_is_active').checked = member.is_active == 1;
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function showPasswordModal(id, username) {
            document.getElementById('password_id').value = id;
            document.getElementById('password_username').textContent = username;
            document.getElementById('new_password').value = '';
            document.getElementById('passwordModal').style.display = 'flex';
        }
        
        function deleteMember(id, username) {
            if (confirm(`Are you sure you want to delete "${username}"? This cannot be undone.`)) {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', id);
                
                fetch('/admin/members.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Member deleted successfully!');
                        window.location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => alert('Error: ' + error.message));
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
