<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Voice Chat</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <h1>🎛️ Voice Chat</h1>
                <p>Admin Panel</p>
            </div>
            
            <form id="loginForm" class="login-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                
                <div class="error-message" id="errorMessage"></div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    Login
                </button>
            </form>
            
            <div class="login-footer">
                <p>Default credentials: admin / admin123</p>
                <p class="warning">⚠️ Change password after first login!</p>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const errorDiv = document.getElementById('errorMessage');
            
            errorDiv.textContent = '';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/api/admin-auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = '/admin/index.php';
                } else {
                    errorDiv.textContent = data.error || 'Login failed';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                errorDiv.textContent = 'Connection error. Please try again.';
                errorDiv.style.display = 'block';
            }
        });
    </script>
</body>
</html>
