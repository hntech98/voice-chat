# Voice Chat Application - Clubhouse Style

A simple, lightweight voice chat web application similar to Clubhouse, built with PHP and MySQL.

## Features

- **Real-time Voice Chat** using WebRTC
- **Admin Panel** for member management
- **Room Management** - Create, join, and manage chat rooms
- **Speaker/Listener Roles** - Control who can speak
- **Hand Raise Feature** - Listeners can request to speak
- **WebSocket Signaling** for real-time communication
- **Simple & Lightweight** - Easy to deploy and maintain

## Requirements

- Ubuntu 22.04 (or similar Linux distribution)
- PHP 8.1+
- MySQL 8.0+
- Node.js 18+ (for WebSocket server)
- Apache2 web server

## Quick Installation

### Option 1: Automatic Installation (Recommended)

```bash
# Download or clone the files
sudo bash install/install.sh
```

### Option 2: Manual Installation

#### 1. Install Required Packages

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php8.1 php8.1-mysql php8.1-curl php8.1-mbstring php8.1-xml nodejs npm
```

#### 2. Configure MySQL

```bash
sudo mysql_secure_installation

# Create database
sudo mysql -u root -p
```

```sql
CREATE DATABASE voice_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'voicechat'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON voice_chat.* TO 'voicechat'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

#### 3. Import Database Schema

```bash
mysql -u voicechat -p voice_chat < database.sql
```

#### 4. Copy Files

```bash
sudo cp -r ./* /var/www/voice-chat/
sudo chown -R www-data:www-data /var/www/voice-chat
```

#### 5. Configure Apache

Create `/etc/apache2/sites-available/voice-chat.conf`:

```apache
Listen 4000

<VirtualHost *:4000>
    ServerName localhost
    DocumentRoot /var/www/voice-chat
    
    <Directory /var/www/voice-chat>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    <Directory /var/www/voice-chat/includes>
        Require all denied
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/voice-chat-error.log
    CustomLog ${APACHE_LOG_DIR}/voice-chat-access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite voice-chat
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### 6. Setup WebSocket Server

```bash
cd /var/www/voice-chat/websocket
npm install ws
```

Create systemd service `/etc/systemd/system/voice-chat-ws.service`:

```ini
[Unit]
Description=Voice Chat WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/voice-chat/websocket
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable voice-chat-ws
sudo systemctl start voice-chat-ws
```

#### 7. Configure Firewall

```bash
sudo ufw allow 4000/tcp
sudo ufw allow 4001/tcp
sudo ufw enable
```

## Configuration

Edit `includes/config.php` to customize:

```php
// Database settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'voice_chat');
define('DB_USER', 'voicechat');
define('DB_PASS', 'your_password');

// WebSocket settings
define('WS_PORT', 4001);
```

## Usage

### Admin Panel

1. Access: `http://your-server:4000/admin/`
2. Default credentials:
   - Username: `admin`
   - Password: `admin123`
3. **Important:** Change the admin password immediately!

### Managing Members

1. Login to Admin Panel
2. Go to "Members" section
3. Click "Add Member"
4. Fill in details (username, password, display name)
5. Check "Is Speaker" if the member should be able to speak in rooms

### Creating Rooms

1. Login to Admin Panel
2. Go to "Rooms" section
3. Click "Create Room"
4. Fill in room name and description
5. Set maximum participants

### Joining Rooms (Members)

1. Login with member credentials
2. Click on any active room to join
3. Speakers can unmute to talk
4. Listeners can raise hand to request speaking

## File Structure

```
voice-chat-php/
├── api/                    # API endpoints
│   ├── auth.php           # Member authentication
│   ├── admin-auth.php     # Admin authentication
│   ├── admin-members.php  # Member management
│   └── rooms.php          # Room management
├── admin/                  # Admin panel pages
│   ├── login.php
│   ├── index.php          # Dashboard
│   ├── members.php        # Member management
│   ├── rooms.php          # Room management
│   ├── activity.php       # Activity log
│   └── settings.php       # Settings
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── app.css
│   └── js/
│       ├── admin.js
│       └── webrtc.js
├── includes/
│   └── config.php         # Configuration
├── websocket/
│   └── server.js          # WebSocket server
├── install/
│   └── install.sh         # Installation script
├── index.php              # Main application
└── database.sql           # Database schema
```

## API Reference

### Authentication

- `POST /api/auth.php?action=login` - Member login
- `POST /api/auth.php?action=logout` - Member logout
- `GET /api/auth.php?action=check` - Check session

### Admin Authentication

- `POST /api/admin-auth.php?action=login` - Admin login
- `POST /api/admin-auth.php?action=logout` - Admin logout

### Member Management (Admin only)

- `GET /api/admin-members.php?action=list` - List members
- `POST /api/admin-members.php?action=add` - Add member
- `POST /api/admin-members.php?action=update` - Update member
- `POST /api/admin-members.php?action=delete` - Delete member
- `POST /api/admin-members.php?action=reset-password` - Reset password

### Rooms

- `GET /api/rooms.php?action=list` - List rooms
- `POST /api/rooms.php?action=join` - Join room
- `POST /api/rooms.php?action=leave` - Leave room
- `GET /api/rooms.php?action=participants` - Get participants

## Troubleshooting

### WebSocket Connection Failed

1. Check if WebSocket service is running:
   ```bash
   sudo systemctl status voice-chat-ws
   ```

2. Check firewall:
   ```bash
   sudo ufw status
   ```

3. Check logs:
   ```bash
   journalctl -u voice-chat-ws -f
   ```

### Audio Not Working

1. Ensure browser has microphone permission
2. Use HTTPS for production (required for WebRTC)
3. Check browser console for errors

### Database Connection Error

1. Verify MySQL is running:
   ```bash
   sudo systemctl status mysql
   ```

2. Check credentials in `includes/config.php`

3. Test connection:
   ```bash
   mysql -u voicechat -p voice_chat
   ```

## Security Recommendations

1. **Change default admin password immediately**
2. Use strong passwords for all accounts
3. Enable HTTPS (use Let's Encrypt for free SSL)
4. Regularly update system packages
5. Monitor activity logs
6. Set up regular database backups
7. Restrict admin panel access by IP if possible

## License

MIT License - Feel free to use and modify.

## Support

For issues and feature requests, please check the logs:
- Apache: `/var/log/apache2/voice-chat-error.log`
- WebSocket: `journalctl -u voice-chat-ws`
