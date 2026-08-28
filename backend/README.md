# Smart Door Pro - Backend API

**Version:** 1.0.0  
**Language:** PHP 7.4+  
**Database:** MySQL/MariaDB  

---

## Installation

### 1. Prerequisites
```bash
PHP 7.4 or higher
MySQL 5.7 or MariaDB 10.3+
Composer
OpenSSL extension
PDO extension
```

### 2. Setup
```bash
# Clone repository
git clone https://github.com/Medok2/smart-door-pro-complete.git
cd smart-door-pro-complete/backend

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Configure .env with your settings
nano .env

# Create storage directories
mkdir -p storage/logs
mkdir -p storage/uploads
chmod -R 755 storage/

# Run migrations
php bin/migrate.php

# Seed initial data (optional)
php bin/seed.php
```

### 3. Web Server Configuration

#### Apache
```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/smart-door-pro/backend/public
    
    <Directory /var/www/smart-door-pro/backend/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    SSLEngine on
    SSLCertificateFile /path/to/cert.crt
    SSLCertificateKeyFile /path/to/key.key
SSLCertificateChainFile /path/to/chain.crt
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /var/www/smart-door-pro/backend/public;
    
    ssl_certificate /path/to/cert.crt;
    ssl_certificate_key /path/to/key.key;
    
    location / {
        try_files $uri $uri/ /index.php?url=$uri&$args;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## API Endpoints

### Authentication
```
POST   /api/v1/auth/login
POST   /api/v1/auth/refresh
POST   /api/v1/auth/logout
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password
GET    /api/v1/me
```

### Door Control
```
GET    /api/v1/door
GET    /api/v1/door/status
POST   /api/v1/door/open
PATCH  /api/v1/door/settings
```

### User Management
```
GET    /api/v1/users
POST   /api/v1/users
GET    /api/v1/users/{id}
PATCH  /api/v1/users/{id}
DELETE /api/v1/users/{id}
POST   /api/v1/users/{id}/suspend
GET    /api/v1/users/{id}/access-rules
PUT    /api/v1/users/{id}/access-rules
```

### Guest Passes
```
GET    /api/v1/passes
POST   /api/v1/passes
GET    /api/v1/passes/{id}
PATCH  /api/v1/passes/{id}
POST   /api/v1/passes/{id}/revoke
POST   /api/v1/passes/{id}/regenerate
GET    /api/v1/guest/pass/preview
POST   /api/v1/guest/pass/request-open
```

### Device Management
```
POST   /api/v1/device/activate
GET    /api/v1/device/bootstrap
GET    /api/v1/device/command/next
POST   /api/v1/device/commands/{id}/ack
POST   /api/v1/device/heartbeat
GET    /api/v1/device/config
GET    /api/v1/admin/device
POST   /api/v1/admin/device/activation-code
POST   /api/v1/admin/device/rotate-secret
```

### Access Logs
```
GET    /api/v1/access-events
GET    /api/v1/audit-logs
GET    /api/v1/reports/access
POST   /api/v1/reports/export
```

---

## Database Schema

### Users Table
```sql
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
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_enabled (enabled)
);
```

### Guest Passes Table
```sql
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
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_valid_until (valid_until),
    INDEX idx_created_by (created_by)
);
```

### Commands Table
```sql
CREATE TABLE device_commands (
    id INT PRIMARY KEY AUTO_INCREMENT,
    command_id VARCHAR(255) UNIQUE NOT NULL,
    device_id INT NOT NULL,
    action ENUM('unlock') DEFAULT 'unlock',
    duration_ms INT DEFAULT 3000,
    status ENUM('pending', 'sent', 'executed', 'failed', 'expired') DEFAULT 'pending',
    source VARCHAR(50),
    actor_id INT,
    request_id VARCHAR(255),
    issued_at DATETIME,
    expires_at DATETIME,
    executed_at DATETIME,
    error_code VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES door_device(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    INDEX idx_status (status),
    INDEX idx_device_id (device_id),
    INDEX idx_expires_at (expires_at)
);
```

### Access Events Table
```sql
CREATE TABLE access_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    device_id INT,
    action VARCHAR(50),
    status ENUM('success', 'denied', 'failed') DEFAULT 'denied',
    method ENUM('button', 'voice', 'qr', 'biometric', 'admin') DEFAULT 'button',
    reason VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (device_id) REFERENCES door_device(id),
    INDEX idx_user_id (user_id),
    INDEX idx_device_id (device_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
);
```

---

## Testing

```bash
# Run all tests
php bin/test.php

# Run specific test
php bin/test.php UserAuthTest

# Load testing
php bin/load_test.php --users=100 --duration=60
```

---

## Security

- ✅ All passwords hashed with Bcrypt (cost 12)
- ✅ JWT tokens with 1-hour expiry
- ✅ HMAC-SHA256 device authentication
- ✅ SQL Injection prevention (Prepared statements)
- ✅ XSS protection (Output encoding)
- ✅ Rate limiting enabled
- ✅ CORS configured
- ✅ CSRF tokens for forms
- ✅ Secure headers
- ✅ Audit logging

---

## Troubleshooting

### 500 Error
1. Check `.env` configuration
2. Verify database connection
3. Check `storage/logs/` for error details
4. Ensure write permissions on `storage/` directory

### Database Connection Error
```bash
mysql -h {DB_HOST} -u {DB_USER} -p {DB_NAME}
```

### Permission Denied
```bash
chmod -R 755 storage/
chown -R www-data:www-data storage/
```

---

## Deployment

See `DEPLOYMENT.md` for production deployment guide.

---

**Status:** Development  
**Last Updated:** 2024
