<?php
/**
 * Smart Door Pro - Database Schema
 * Run this file via: php install.php
 */

require_once __DIR__ . '/config.php';

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

// Create database
$mysqli->query('CREATE DATABASE IF NOT EXISTS ' . DB_NAME);
$mysqli->select_db(DB_NAME);
$mysqli->set_charset('utf8mb4');

// Create tables
$tables = [
    // Admins
    'CREATE TABLE IF NOT EXISTS sd_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        username VARCHAR(100),
        token VARCHAR(64),
        token_expires DATETIME,
        refresh_token VARCHAR(64),
        enabled BOOLEAN DEFAULT true,
        last_login_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Devices
    'CREATE TABLE IF NOT EXISTS sd_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(64) UNIQUE NOT NULL,
        device_secret VARCHAR(128) NOT NULL,
        public_id VARCHAR(64) UNIQUE,
        name VARCHAR(255),
        enabled BOOLEAN DEFAULT true,
        firmware_version VARCHAR(50),
        status ENUM("online", "offline", "error") DEFAULT "offline",
        last_ip VARCHAR(45),
        last_heartbeat_at DATETIME,
        last_seen_at DATETIME,
        rssi INT,
        free_heap INT,
        uptime_seconds INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_device_id (device_id),
        INDEX idx_status (status),
        INDEX idx_last_heartbeat (last_heartbeat_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Users
    'CREATE TABLE IF NOT EXISTS sd_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNIQUE,
        name VARCHAR(255),
        secret_hash VARCHAR(255),
        permissions INT DEFAULT 1,
        remaining_uses INT DEFAULT 0,
        internally_limited BOOLEAN DEFAULT false,
        enabled BOOLEAN DEFAULT true,
        activated BOOLEAN DEFAULT false,
        activation_code VARCHAR(100),
        activation_expires DATETIME,
        last_counter INT DEFAULT 0,
        last_used_at DATETIME,
        revision INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_enabled (enabled),
        INDEX idx_activated (activated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // User Sessions
    'CREATE TABLE IF NOT EXISTS sd_user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_fingerprint VARCHAR(255),
        token_hash VARCHAR(255),
        refresh_token_hash VARCHAR(255),
        revoked_at DATETIME,
        last_seen_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES sd_users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_revoked (revoked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Commands
    'CREATE TABLE IF NOT EXISTS sd_commands (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        command_id VARCHAR(64) UNIQUE,
        idempotency_key VARCHAR(255) UNIQUE,
        payload JSON,
        status ENUM("PENDING", "DELIVERED", "CLAIMED", "EXECUTED", "FAILED", "EXPIRED", "REJECTED") DEFAULT "PENDING",
        expires_at DATETIME,
        delivered_at DATETIME,
        claimed_at DATETIME,
        completed_at DATETIME,
        actual_duration INT,
        error_code VARCHAR(50),
        guest_pass_id INT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES sd_devices(id) ON DELETE CASCADE,
        FOREIGN KEY (guest_pass_id) REFERENCES sd_guest_passes(id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_device_expires (device_id, expires_at),
        INDEX idx_guest_pass (guest_pass_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Guest Passes
    'CREATE TABLE IF NOT EXISTS sd_guest_passes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        token_hash VARCHAR(255) UNIQUE NOT NULL,
        token_lookup VARCHAR(255) UNIQUE,
        total_uses INT DEFAULT 1,
        used_count INT DEFAULT 0,
        remaining_uses INT DEFAULT 1,
        issuer_id INT,
        issuer_type ENUM("admin", "user") DEFAULT "admin",
        enabled BOOLEAN DEFAULT true,
        status ENUM("active", "used", "expired", "revoked") DEFAULT "active",
        expires_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_token_hash (token_hash),
        INDEX idx_token_lookup (token_lookup),
        INDEX idx_status (status),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Guest Reservations
    'CREATE TABLE IF NOT EXISTS sd_guest_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guest_pass_id INT NOT NULL,
        command_id BIGINT,
        status ENUM("reserved", "confirmed", "released", "expired") DEFAULT "reserved",
        reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        confirmed_at DATETIME,
        expires_at DATETIME,
        FOREIGN KEY (guest_pass_id) REFERENCES sd_guest_passes(id) ON DELETE CASCADE,
        INDEX idx_status (status),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Audit Logs
    'CREATE TABLE IF NOT EXISTS sd_audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT,
        user_id INT,
        action VARCHAR(100),
        resource_type VARCHAR(50),
        resource_id INT,
        success BOOLEAN,
        reason VARCHAR(255),
        ip_address VARCHAR(45),
        user_agent VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES sd_admins(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES sd_users(id) ON DELETE SET NULL,
        INDEX idx_admin (admin_id),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at),
        INDEX idx_resource (resource_type, resource_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    
    // Settings
    'CREATE TABLE IF NOT EXISTS sd_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        `key` VARCHAR(255) UNIQUE NOT NULL,
        `value` LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
];

// Execute table creation
foreach ($tables as $sql) {
    if (!$mysqli->query($sql)) {
        die('Table creation failed: ' . $mysqli->error);
    }
}

echo "[✓] Database tables created successfully\n";

// Insert default admin
$adminEmail = DEFAULT_ADMIN_EMAIL;
$adminPassword = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_BCRYPT);

$stmt = $mysqli->prepare(
    'INSERT IGNORE INTO sd_admins (email, password_hash, username) VALUES (?, ?, ?)'
);
$stmt->bind_param('sss', $adminEmail, $adminPassword, $adminEmail);

if ($stmt->execute()) {
    echo "[✓] Default admin created: $adminEmail\n";
    echo "[⚠] WARNING: Change password immediately after first login!\n";
}

echo "\n[✓] Installation complete!\n";
echo "[→] Next steps:\n";
echo "    1. Copy config.example.php to config.php\n";
echo "    2. Update database credentials in config.php\n";
echo "    3. Set up the device in admin panel\n";

$mysqli->close();
