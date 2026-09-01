<?php
/**
 * Smart Door Pro - Configuration
 * Copy to config.php and update with your settings
 */

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'smart_door');

// Server Configuration
define('SERVER_NAME', getenv('SERVER_NAME') ?: 'Smart Door Pro');
define('SERVER_URL', getenv('SERVER_URL') ?: 'https://example.com');
define('API_PREFIX', '/api/v1');

// Security
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change_me_in_production');
define('HMAC_KEY', getenv('HMAC_KEY') ?: 'change_me_in_production');

// Device Configuration
define('DEFAULT_UNLOCK_DURATION', 3000);  // milliseconds
define('MIN_UNLOCK_DURATION', 500);
define('MAX_UNLOCK_DURATION', 30000);

// Telegram Configuration (optional)
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_ADMIN_CHAT_ID', getenv('TELEGRAM_ADMIN_CHAT_ID') ?: '');

// Features
define('ENABLE_LOCAL_MODE', true);
define('ENABLE_CLOUD_MODE', true);
define('ENABLE_TELEGRAM_MODE', true);
define('ENABLE_VOICE_CONTROL', true);
define('ENABLE_QR_CODES', true);

// Rate Limiting
define('RATE_LIMIT_DOOR_OPEN', 10);      // Max 10 requests per minute
define('RATE_LIMIT_WINDOW', 60);         // Window in seconds

// Logging
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'INFO');

// Admin Default Credentials (MUST be changed immediately)
define('DEFAULT_ADMIN_EMAIL', 'admin@smartdoor.com');
define('DEFAULT_ADMIN_PASSWORD', 'admin');

// Ensure log directory exists
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}
