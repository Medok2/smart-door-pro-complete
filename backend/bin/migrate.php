<?php
/**
 * Database Migration Script
 * Creates all necessary tables for Smart Door Pro
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Config/DotEnv.php';

\SmartDoor\Config\DotEnv::load(__DIR__ . '/..');

$db = \SmartDoor\Config\Database::getInstance();
$pdo = $db->getConnection();

echo "\n=== Smart Door Pro - Database Migration ===\n\n";

// 1. App Settings
echo "Creating app_settings table...";
$pdo->exec("DROP TABLE IF EXISTS app_settings");
$pdo->exec("
CREATE TABLE app_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    `key` VARCHAR(255) UNIQUE NOT NULL,
    `value` LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 2. Door Settings
echo "Creating door_settings table...";
$pdo->exec("DROP TABLE IF EXISTS door_settings");
$pdo->exec("
CREATE TABLE door_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    door_name VARCHAR(255) DEFAULT 'Main Door',
    unlock_duration INT DEFAULT 3000,
    relay_active_level ENUM('HIGH', 'LOW') DEFAULT 'HIGH',
    min_unlock_duration INT DEFAULT 500,
    max_unlock_duration INT DEFAULT 15000,
    allow_remote_admin_open BOOLEAN DEFAULT true,
    require_admin_open_reason BOOLEAN DEFAULT false,
    require_user_biometric BOOLEAN DEFAULT false,
    voice_enabled BOOLEAN DEFAULT false,
    auto_arrival_enabled BOOLEAN DEFAULT false,
    guest_pass_enabled BOOLEAN DEFAULT true,
    lockdown_enabled BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("INSERT INTO door_settings (door_name) VALUES ('Main Door')");
echo " ✓\n";

// 3. Roles
echo "Creating roles table...";
$pdo->exec("DROP TABLE IF EXISTS roles");
$pdo->exec("
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("INSERT INTO roles (name, description) VALUES 
    ('owner_admin', 'Owner - Full System Control'),
    ('admin', 'Administrator - User Management'),
    ('user', 'Regular User - Door Access'),
    ('guest', 'Guest - Limited QR Access')
");
echo " ✓\n";

// 4. Users
echo "Creating users table...";
$pdo->exec("DROP TABLE IF EXISTS users");
$pdo->exec("
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('owner_admin', 'admin', 'user', 'guest') DEFAULT 'user',
    enabled BOOLEAN DEFAULT true,
    two_factor_enabled BOOLEAN DEFAULT false,
    two_factor_secret VARCHAR(255),
    last_login DATETIME,
    login_attempts INT DEFAULT 0,
    locked_until DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_enabled (enabled),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 5. User Access Rules
echo "Creating user_access_rules table...";
$pdo->exec("DROP TABLE IF EXISTS user_access_rules");
$pdo->exec("
CREATE TABLE user_access_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    enabled BOOLEAN DEFAULT true,
    valid_from DATE,
    valid_until DATE,
    unlimited_access BOOLEAN DEFAULT false,
    max_total_uses INT,
    max_daily_uses INT DEFAULT 5,
    allowed_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
    allowed_start_time TIME DEFAULT '00:00:00',
    allowed_end_time TIME DEFAULT '23:59:59',
    allow_manual_open BOOLEAN DEFAULT true,
    allow_voice_open BOOLEAN DEFAULT false,
    allow_qr_open BOOLEAN DEFAULT true,
    allow_auto_arrival BOOLEAN DEFAULT false,
    require_biometric BOOLEAN DEFAULT false,
    cooldown_seconds INT DEFAULT 10,
    suspended_at DATETIME,
    suspension_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 6. Door Device
echo "Creating door_device table...";
$pdo->exec("DROP TABLE IF EXISTS door_device");
$pdo->exec("
CREATE TABLE door_device (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id VARCHAR(255) UNIQUE NOT NULL,
    device_secret VARCHAR(255) NOT NULL,
    activation_code VARCHAR(255),
    firmware_version VARCHAR(50),
    config_version INT DEFAULT 1,
    status ENUM('active', 'inactive', 'error', 'offline') DEFAULT 'inactive',
    last_seen_at DATETIME,
    last_heartbeat_at DATETIME,
    last_sequence INT DEFAULT 0,
    online_status BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_device_id (device_id),
    INDEX idx_status (status),
    INDEX idx_online_status (online_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 7. Device Commands
echo "Creating device_commands table...";
$pdo->exec("DROP TABLE IF EXISTS device_commands");
$pdo->exec("
CREATE TABLE device_commands (
    id INT PRIMARY KEY AUTO_INCREMENT,
    command_id VARCHAR(255) UNIQUE NOT NULL,
    device_id INT NOT NULL,
    action ENUM('unlock') DEFAULT 'unlock',
    duration_ms INT DEFAULT 3000,
    status ENUM('pending', 'sent', 'executed', 'failed', 'expired', 'rejected') DEFAULT 'pending',
    source VARCHAR(50),
    actor_id INT,
    request_id VARCHAR(255),
    issued_at DATETIME,
    expires_at DATETIME,
    executed_at DATETIME,
    actual_duration_ms INT,
    error_code VARCHAR(50),
    error_message TEXT,
    signature VARCHAR(255),
    sequence_number INT,
    nonce VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES door_device(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_device_id (device_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_actor_id (actor_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 8. Device Heartbeats
echo "Creating device_heartbeats table...";
$pdo->exec("DROP TABLE IF EXISTS device_heartbeats");
$pdo->exec("
CREATE TABLE device_heartbeats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT NOT NULL,
    rssi INT,
    free_heap INT,
    reset_reason VARCHAR(100),
    uptime_seconds INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES door_device(id) ON DELETE CASCADE,
    INDEX idx_device_id (device_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 9. Guest Passes
echo "Creating guest_passes table...";
$pdo->exec("DROP TABLE IF EXISTS guest_passes");
$pdo->exec("
CREATE TABLE guest_passes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    token_hash VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('active', 'used', 'expired', 'revoked') DEFAULT 'active',
    used_count INT DEFAULT 0,
    max_uses INT DEFAULT 1,
    unlimited_uses BOOLEAN DEFAULT false,
    valid_from DATETIME,
    valid_until DATETIME,
    access_start_time TIME,
    access_end_time TIME,
    allowed_days VARCHAR(100),
    require_otp BOOLEAN DEFAULT false,
    require_door_qr BOOLEAN DEFAULT false,
    cooldown_seconds INT DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_valid_until (valid_until),
    INDEX idx_created_by (created_by),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 10. Guest Pass Reservations
echo "Creating guest_pass_reservations table...";
$pdo->exec("DROP TABLE IF EXISTS guest_pass_reservations");
$pdo->exec("
CREATE TABLE guest_pass_reservations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    guest_pass_id INT NOT NULL,
    reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    confirmed_at DATETIME,
    status ENUM('reserved', 'confirmed', 'expired', 'cancelled') DEFAULT 'reserved',
    FOREIGN KEY (guest_pass_id) REFERENCES guest_passes(id) ON DELETE CASCADE,
    INDEX idx_guest_pass_id (guest_pass_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 11. Access Events
echo "Creating access_events table...";
$pdo->exec("DROP TABLE IF EXISTS access_events");
$pdo->exec("
CREATE TABLE access_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    guest_pass_id INT,
    device_id INT,
    action VARCHAR(50),
    status ENUM('success', 'denied', 'failed') DEFAULT 'denied',
    method ENUM('button', 'voice', 'qr', 'biometric', 'admin', 'auto_arrival') DEFAULT 'button',
    reason VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    duration_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (guest_pass_id) REFERENCES guest_passes(id) ON DELETE SET NULL,
    FOREIGN KEY (device_id) REFERENCES door_device(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_device_id (device_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status),
    INDEX idx_method (method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 12. Audit Logs
echo "Creating audit_logs table...";
$pdo->exec("DROP TABLE IF EXISTS audit_logs");
$pdo->exec("
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    actor_id INT,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50),
    resource_id INT,
    changes JSON,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_actor_id (actor_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_resource (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 13. User Sessions
echo "Creating user_sessions table...";
$pdo->exec("DROP TABLE IF EXISTS user_sessions");
$pdo->exec("
CREATE TABLE user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    device_id VARCHAR(255),
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 14. Refresh Tokens
echo "Creating refresh_tokens table...";
$pdo->exec("DROP TABLE IF EXISTS refresh_tokens");
$pdo->exec("
CREATE TABLE refresh_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 15. Notifications
echo "Creating notifications table...";
$pdo->exec("DROP TABLE IF EXISTS notifications");
$pdo->exec("
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    type VARCHAR(50),
    title VARCHAR(255),
    message TEXT,
    data JSON,
    read_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 16. Voice Preferences
echo "Creating voice_preferences table...";
$pdo->exec("DROP TABLE IF EXISTS voice_preferences");
$pdo->exec("
CREATE TABLE voice_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    voice_enabled BOOLEAN DEFAULT false,
    language ENUM('ar', 'en') DEFAULT 'ar',
    model_language ENUM('ar', 'en') DEFAULT 'ar',
    sensitivity ENUM('low', 'medium', 'high') DEFAULT 'medium',
    model_hash VARCHAR(255),
    model_size INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

// 17. Device Activations
echo "Creating device_activations table...";
$pdo->exec("DROP TABLE IF EXISTS device_activations");
$pdo->exec("
CREATE TABLE device_activations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    activation_code VARCHAR(255) UNIQUE NOT NULL,
    device_name VARCHAR(255),
    used BOOLEAN DEFAULT false,
    used_at DATETIME,
    used_by INT,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activation_code (activation_code),
    INDEX idx_used (used),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo " ✓\n";

echo "\n✅ All tables created successfully!\n\n";
