#!/bin/bash

#####################################################################
# Voice Chat Installation Script for Ubuntu 22.04
# This script installs and configures a complete voice chat server
# with PHP, MySQL, and Node.js WebSocket server
#
# Usage: sudo bash install.sh
#####################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
APP_DIR="/var/www/voice-chat"
MYSQL_ROOT_PASSWORD=""
DB_NAME="voice_chat"
DB_USER="voicechat"
DB_PASSWORD=""
ADMIN_PASSWORD="admin123"
SERVER_PORT=4000
WS_PORT=4001

echo -e "${BLUE}"
echo "============================================"
echo "   Voice Chat Installation Script"
echo "   For Ubuntu 22.04"
echo "============================================"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root (sudo bash install.sh)${NC}"
    exit 1
fi

# Generate random password
generate_password() {
    openssl rand -base64 12 | tr -d "=+/" | cut -c1-16
}

# Prompt for configuration
echo -e "${YELLOW}Configuration:${NC}"
read -p "MySQL root password (or press Enter to set one): " MYSQL_ROOT_PASSWORD
read -p "Application directory [$APP_DIR]: " INPUT_APP_DIR
read -p "HTTP Server port [$SERVER_PORT]: " INPUT_SERVER_PORT
read -p "WebSocket port [$WS_PORT]: " INPUT_WS_PORT

# Apply defaults or use input
APP_DIR=${INPUT_APP_DIR:-$APP_DIR}
SERVER_PORT=${INPUT_SERVER_PORT:-$SERVER_PORT}
WS_PORT=${INPUT_WS_PORT:-$WS_PORT}

# Generate database password
DB_PASSWORD=$(generate_password)
echo -e "${GREEN}Generated database password: $DB_PASSWORD${NC}"

echo ""
echo -e "${YELLOW}Installing required packages...${NC}"

# Update system
apt update && apt upgrade -y

# Install Apache
echo -e "${BLUE}Installing Apache...${NC}"
apt install -y apache2

# Install PHP and extensions
echo -e "${BLUE}Installing PHP...${NC}"
apt install -y php8.1 php8.1-mysql php8.1-curl php8.1-json php8.1-mbstring php8.1-xml php8.1-gd

# Install MySQL
echo -e "${BLUE}Installing MySQL...${NC}"
export DEBIAN_FRONTEND=noninteractive
apt install -y mysql-server

# Install Node.js for WebSocket server
echo -e "${BLUE}Installing Node.js...${NC}"
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

# Install other utilities
apt install -y curl wget git unzip ufw

echo -e "${GREEN}Packages installed successfully!${NC}"

# Configure MySQL
echo ""
echo -e "${YELLOW}Configuring MySQL...${NC}"

# Start MySQL
service mysql start

# Set root password if not set
if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    MYSQL_ROOT_PASSWORD=$(generate_password)
    echo -e "${GREEN}Generated MySQL root password: $MYSQL_ROOT_PASSWORD${NC}"
fi

# Configure MySQL root password
mysql -u root <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$MYSQL_ROOT_PASSWORD';
FLUSH PRIVILEGES;
EOF

# Create database and user
mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

echo -e "${GREEN}MySQL configured successfully!${NC}"

# Create application directory
echo ""
echo -e "${YELLOW}Setting up application...${NC}"
mkdir -p $APP_DIR

# Copy application files (assuming they're in the same directory as this script)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

if [ -d "$PARENT_DIR/api" ]; then
    cp -r $PARENT_DIR/* $APP_DIR/
else
    echo -e "${YELLOW}Application files not found. Please copy files manually to $APP_DIR${NC}"
fi

# Create uploads directory
mkdir -p $APP_DIR/uploads
chmod 755 $APP_DIR/uploads

# Update configuration file
echo ""
echo -e "${YELLOW}Updating configuration...${NC}"

CONFIG_FILE="$APP_DIR/includes/config.php"
if [ -f "$CONFIG_FILE" ]; then
    sed -i "s/define('DB_USER', 'root');/define('DB_USER', '$DB_USER');/" $CONFIG_FILE
    sed -i "s/define('DB_PASS', '');/define('DB_PASS', '$DB_PASSWORD');/" $CONFIG_FILE
    sed -i "s/define('WS_PORT', 4001);/define('WS_PORT', $WS_PORT);/" $CONFIG_FILE
fi

# Import database schema
echo ""
echo -e "${YELLOW}Importing database schema...${NC}"
if [ -f "$APP_DIR/database.sql" ]; then
    mysql -u $DB_USER -p"$DB_PASSWORD" $DB_NAME < $APP_DIR/database.sql
    echo -e "${GREEN}Database schema imported!${NC}"
else
    echo -e "${RED}Database schema not found at $APP_DIR/database.sql${NC}"
fi

# Update admin password
ADMIN_HASH=$(php -r "echo password_hash('$ADMIN_PASSWORD', PASSWORD_DEFAULT);")
mysql -u $DB_USER -p"$DB_PASSWORD" $DB_NAME -e "UPDATE admins SET password = '$ADMIN_HASH' WHERE username = 'admin';"

# Set permissions
echo ""
echo -e "${YELLOW}Setting permissions...${NC}"
chown -R www-data:www-data $APP_DIR
chmod -R 755 $APP_DIR
chmod 640 $APP_DIR/includes/config.php

# Configure Apache
echo ""
echo -e "${YELLOW}Configuring Apache...${NC}"

# Create Apache virtual host
cat > /etc/apache2/sites-available/voice-chat.conf <<EOF
<VirtualHost *:$SERVER_PORT>
    ServerName localhost
    DocumentRoot $APP_DIR
    
    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Allow API access
        <FilesMatch "\.php$">
            SetHandler application/x-httpd-php
        </FilesMatch>
    </Directory>
    
    <Directory $APP_DIR/includes>
        Require all denied
    </Directory>
    
    <Directory $APP_DIR/install>
        Require all denied
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/voice-chat-error.log
    CustomLog \${APACHE_LOG_DIR}/voice-chat-access.log combined
</VirtualHost>
EOF

# Configure Apache ports
if ! grep -q "Listen $SERVER_PORT" /etc/apache2/ports.conf; then
    echo "Listen $SERVER_PORT" >> /etc/apache2/ports.conf
fi

# Enable site and modules
a2ensite voice-chat.conf
a2enmod rewrite
a2enmod php8.1

# Restart Apache
systemctl restart apache2

# Setup WebSocket server
echo ""
echo -e "${YELLOW}Setting up WebSocket server...${NC}"

# Install WebSocket dependencies
cd $APP_DIR/websocket
npm init -y
npm install ws

# Create systemd service for WebSocket
cat > /etc/systemd/system/voice-chat-ws.service <<EOF
[Unit]
Description=Voice Chat WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$APP_DIR/websocket
ExecStart=/usr/bin/node server.js
Restart=always
RestartSec=10
StandardOutput=syslog
StandardError=syslog
SyslogIdentifier=voice-chat-ws
Environment=NODE_ENV=production
Environment=WS_PORT=$WS_PORT

[Install]
WantedBy=multi-user.target
EOF

# Enable and start WebSocket service
systemctl daemon-reload
systemctl enable voice-chat-ws.service
systemctl start voice-chat-ws.service

# Configure firewall
echo ""
echo -e "${YELLOW}Configuring firewall...${NC}"
ufw allow $SERVER_PORT/tcp
ufw allow $WS_PORT/tcp
ufw allow ssh
ufw --force enable

echo -e "${GREEN}Firewall configured!${NC}"

# Create log rotation
cat > /etc/logrotate.d/voice-chat <<EOF
/var/log/apache2/voice-chat-*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data adm
    sharedscripts
    postrotate
        systemctl reload apache2 > /dev/null 2>&1 || true
    endscript
}
EOF

# Create backup script
cat > /usr/local/bin/voice-chat-backup.sh <<'EOF'
#!/bin/bash
BACKUP_DIR="/var/backups/voice-chat"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR

# Backup database
mysqldump -u voicechat -p'DB_PASSWORD_PLACEHOLDER' voice_chat > $BACKUP_DIR/db_$DATE.sql

# Backup files
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/voice-chat --exclude='uploads'

# Remove old backups (keep 7 days)
find $BACKUP_DIR -type f -mtime +7 -delete

echo "Backup completed: $DATE"
EOF

chmod +x /usr/local/bin/voice-chat-backup.sh

# Create info file
INFO_FILE="$APP_DIR/install/info.txt"
mkdir -p $APP_DIR/install
cat > $INFO_FILE <<EOF
========================================
Voice Chat Installation Info
========================================
Installation Date: $(date)
Application Directory: $APP_DIR
HTTP Port: $SERVER_PORT
WebSocket Port: $WS_PORT

Database:
  Name: $DB_NAME
  User: $DB_USER
  Password: $DB_PASSWORD

MySQL Root Password: $MYSQL_ROOT_PASSWORD

Admin Credentials:
  Username: admin
  Password: $ADMIN_PASSWORD

Access URLs:
  Main Site: http://YOUR_SERVER_IP:$SERVER_PORT
  Admin Panel: http://YOUR_SERVER_IP:$SERVER_PORT/admin/

Service Commands:
  Apache: systemctl status apache2
  WebSocket: systemctl status voice-chat-ws.service
  MySQL: systemctl status mysql

Log Files:
  Apache Error: /var/log/apache2/voice-chat-error.log
  Apache Access: /var/log/apache2/voice-chat-access.log

========================================
IMPORTANT: Save this information securely!
========================================
EOF

chmod 600 $INFO_FILE

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}   Installation Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "${YELLOW}Access URLs:${NC}"
echo "  Main Site: http://YOUR_SERVER_IP:$SERVER_PORT"
echo "  Admin Panel: http://YOUR_SERVER_IP:$SERVER_PORT/admin/"
echo ""
echo -e "${YELLOW}Admin Credentials:${NC}"
echo "  Username: admin"
echo "  Password: $ADMIN_PASSWORD"
echo ""
echo -e "${YELLOW}Database Credentials:${NC}"
echo "  Database: $DB_NAME"
echo "  User: $DB_USER"
echo "  Password: $DB_PASSWORD"
echo ""
echo -e "${RED}IMPORTANT: Change the admin password immediately after first login!${NC}"
echo ""
echo -e "${YELLOW}Service Status:${NC}"
systemctl is-active apache2
systemctl is-active voice-chat-ws.service
systemctl is-active mysql
echo ""
echo -e "${YELLOW}Installation info saved to: $INFO_FILE${NC}"
echo ""
