# Quick Start Guide

## First Time Setup

### 1. Install on Ubuntu 22.04

```bash
# Upload all files to your server
scp -r voice-chat-php/* user@your-server:/tmp/voice-chat/

# SSH into your server
ssh user@your-server

# Run installation
sudo bash /tmp/voice-chat/install/install.sh
```

### 2. Change Admin Password

After installation, immediately change the admin password:

1. Go to `http://your-server:4000/admin/`
2. Login with admin / admin123
3. Click Settings → Change Password

### 3. Add Your First Member

1. In Admin Panel, go to Members
2. Click "Add Member"
3. Fill in:
   - Username: `john_doe`
   - Password: `secure_password`
   - Display Name: `John Doe`
   - Check "Is Speaker" if they should talk
4. Click "Add Member"

### 4. Create a Room

1. In Admin Panel, go to Rooms
2. Click "Create Room"
3. Fill in:
   - Name: `General Chat`
   - Description: `Open discussion room`
   - Max Participants: `50`
4. Click "Create Room"

### 5. Test Voice Chat

1. Open `http://your-server:4000/`
2. Login with the member credentials
3. Click on the room to join
4. Click the microphone button to unmute and talk

## Configuration Checklist

- [ ] Change admin password
- [ ] Change MySQL password in `includes/config.php`
- [ ] Set up SSL/HTTPS (recommended)
- [ ] Configure firewall (ports 4000, 4001)
- [ ] Set up database backups
- [ ] Review activity logs regularly

## Common Commands

```bash
# Check service status
sudo systemctl status apache2
sudo systemctl status voice-chat-ws
sudo systemctl status mysql

# Restart services
sudo systemctl restart apache2
sudo systemctl restart voice-chat-ws

# View logs
tail -f /var/log/apache2/voice-chat-error.log
journalctl -u voice-chat-ws -f

# Backup database
mysqldump -u voicechat -p voice_chat > backup.sql

# Restore database
mysql -u voicechat -p voice_chat < backup.sql
```

## Port Reference

| Port | Service | Purpose |
|------|---------|---------|
| 4000 | Apache | HTTP Server (Main App) |
| 4001 | Node.js | WebSocket Server (Signaling) |
| 3306 | MySQL | Database |

## URLs

| URL | Description |
|-----|-------------|
| `http://server:4000/` | Main voice chat app |
| `http://server:4000/admin/` | Admin panel |
| `http://server:4000/api/` | API endpoints |
