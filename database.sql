-- Voice Chat Application Database Schema
-- Run this script to initialize the database

CREATE DATABASE IF NOT EXISTS voice_chat;
USE voice_chat;

-- Admin table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- Members table
CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    email VARCHAR(100),
    avatar VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_speaker TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NULL,
    UNIQUE KEY unique_username (username)
);

-- Rooms table
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    max_participants INT DEFAULT 50,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE CASCADE
);

-- Room participants table
CREATE TABLE IF NOT EXISTS room_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    member_id INT NOT NULL,
    is_speaker TINYINT(1) DEFAULT 0,
    is_muted TINYINT(1) DEFAULT 1,
    hand_raised TINYINT(1) DEFAULT 0,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at TIMESTAMP NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participant (room_id, member_id)
);

-- Activity log table
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50),
    target_id INT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

-- Sessions table for member authentication
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    member_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);

-- Insert default admin (password: admin123 - change this immediately!)
-- Password is hashed using password_hash() in PHP
INSERT INTO admins (username, password, email) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com')
ON DUPLICATE KEY UPDATE username = username;

-- Create indexes for better performance
CREATE INDEX idx_members_active ON members(is_active);
CREATE INDEX idx_rooms_active ON rooms(is_active);
CREATE INDEX idx_participants_room ON room_participants(room_id);
CREATE INDEX idx_participants_member ON room_participants(member_id);
CREATE INDEX idx_sessions_member ON sessions(member_id);
CREATE INDEX idx_sessions_expires ON sessions(expires_at);
