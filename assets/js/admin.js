/**
 * Admin Panel JavaScript
 */

// Utility functions
function sanitize(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Modal functions
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

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});

// Close modal with Escape key
window.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }
});

// Confirmation dialogs
function confirmDelete(type, name) {
    return confirm(`Are you sure you want to delete ${type} "${name}"? This action cannot be undone.`);
}

// Toast notifications (simple implementation)
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        background: ${type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#6366f1'};
        color: white;
        font-weight: 500;
        z-index: 2000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
});

// Form validation helpers
function validateUsername(username) {
    if (username.length < 3 || username.length > 50) {
        return 'Username must be between 3 and 50 characters';
    }
    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        return 'Username can only contain letters, numbers, and underscores';
    }
    return null;
}

function validatePassword(password) {
    if (password.length < 6) {
        return 'Password must be at least 6 characters';
    }
    return null;
}

function validateEmail(email) {
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        return 'Invalid email format';
    }
    return null;
}

// Export functions for use in other scripts
window.adminUtils = {
    sanitize,
    showAddModal,
    showEditModal,
    showPasswordModal,
    closeModal,
    confirmDelete,
    showToast,
    validateUsername,
    validatePassword,
    validateEmail
};
