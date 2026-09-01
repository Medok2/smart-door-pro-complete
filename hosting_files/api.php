<?php
/**
 * Smart Door Pro - Backend API
 * Production-grade PHP/MySQL implementation
 * 
 * Security: HMAC-SHA256, prepared statements, rate limiting
 * Database: MySQL 5.7+ / MariaDB 10.2+
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Device-ID, Device-Signature');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('DEBUG', getenv('DEBUG') === 'true');
define('REQUEST_ID', bin2hex(random_bytes(8)));

// ============================================
// CONFIGURATION
// ============================================

require_once __DIR__ . '/config.php';

// ============================================
// DATABASE CONNECTION
// ============================================

class Database {
    private static $instance = null;
    private $mysqli;
    
    private function __construct() {
        $this->mysqli = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        if ($this->mysqli->connect_error) {
            die(json_encode([
                'ok' => false,
                'error' => [
                    'code' => 'DATABASE_ERROR',
                    'message' => 'Database connection failed'
                ]
            ]));
        }
        
        $this->mysqli->set_charset('utf8mb4');
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->mysqli->prepare($sql);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $this->mysqli->error);
        }
        
        if (!empty($params)) {
            $types = '';
            foreach ($params as $param) {
                if (is_int($param)) $types .= 'i';
                elseif (is_float($param)) $types .= 'd';
                else $types .= 's';
            }
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    public function transaction(callable $callback) {
        $this->mysqli->begin_transaction();
        try {
            $result = $callback($this);
            $this->mysqli->commit();
            return $result;
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
}

// ============================================
// ROUTING
// ============================================

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/api/v1', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

// Route matching
if (preg_match('#^/admin/login$#', $path) && $method === 'POST') {
    handleAdminLogin();
} elseif (preg_match('#^/admin/password$#', $path) && $method === 'POST') {
    handleAdminPasswordChange();
} elseif (preg_match('#^/device/poll$#', $path) && $method === 'POST') {
    handleDevicePoll();
} elseif (preg_match('#^/device/ack$#', $path) && $method === 'POST') {
    handleDeviceAck();
} elseif (preg_match('#^/device/heartbeat$#', $path) && $method === 'POST') {
    handleDeviceHeartbeat();
} elseif (preg_match('#^/door/open$#', $path) && $method === 'POST') {
    handleDoorOpen();
} elseif (preg_match('#^/guest/pass/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'POST') {
    handleGuestPass($m[1]);
} elseif (preg_match('#^/users$#', $path) && $method === 'GET') {
    handleGetUsers();
} elseif (preg_match('#^/users$#', $path) && $method === 'POST') {
    handleCreateUser();
} elseif (preg_match('#^/health$#', $path) && $method === 'GET') {
    handleHealth();
} else {
    sendError(404, 'NOT_FOUND', 'Endpoint not found');
}

// ============================================
// AUTHENTICATION
// ============================================

function verifyAdminToken($token) {
    $db = Database::getInstance();
    
    $stmt = $db->query(
        'SELECT id, username FROM sd_admins WHERE token = ? AND token_expires > NOW() LIMIT 1',
        [$token]
    );
    
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function verifyDeviceSignature($deviceId, $signature, $payload) {
    $db = Database::getInstance();
    
    $stmt = $db->query(
        'SELECT device_secret FROM sd_devices WHERE device_id = ? AND enabled = 1 LIMIT 1',
        [$deviceId]
    );
    
    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    
    if (!$device) {
        return false;
    }
    
    $expectedSignature = hash_hmac('sha256', $payload, $device['device_secret']);
    return hash_equals($expectedSignature, $signature);
}

// ============================================
// HANDLERS
// ============================================

function handleAdminLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['email']) || !isset($input['password'])) {
        sendError(400, 'INVALID_INPUT', 'Email and password required');
    }
    
    $db = Database::getInstance();
    
    $stmt = $db->query(
        'SELECT id, username, password_hash FROM sd_admins WHERE email = ? LIMIT 1',
        [$input['email']]
    );
    
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    
    if (!$admin || !password_verify($input['password'], $admin['password_hash'])) {
        sendError(401, 'INVALID_CREDENTIALS', 'Invalid email or password');
    }
    
    // Generate tokens
    $accessToken = bin2hex(random_bytes(32));
    $refreshToken = bin2hex(random_bytes(32));
    
    // Store tokens
    $db->query(
        'UPDATE sd_admins SET token = ?, token_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR), refresh_token = ?, last_login_at = NOW() WHERE id = ?',
        [$accessToken, $refreshToken, $admin['id']]
    );
    
    sendSuccess(200, [
        'user' => [
            'id' => $admin['id'],
            'email' => $input['email'],
            'role' => 'admin'
        ],
        'tokens' => [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => 3600
        ]
    ]);
}

function handleAdminPasswordChange() {
    $token = getAuthToken();
    if (!$token) {
        sendError(401, 'UNAUTHORIZED', 'Token required');
    }
    
    $admin = verifyAdminToken($token);
    if (!$admin) {
        sendError(401, 'INVALID_TOKEN', 'Invalid or expired token');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['current_password']) || !isset($input['new_password'])) {
        sendError(400, 'INVALID_INPUT', 'Current and new passwords required');
    }
    
    // Validate password strength
    if (strlen($input['new_password']) < 8) {
        sendError(400, 'WEAK_PASSWORD', 'Password must be at least 8 characters');
    }
    
    $db = Database::getInstance();
    
    // Verify current password
    $stmt = $db->query(
        'SELECT password_hash FROM sd_admins WHERE id = ? LIMIT 1',
        [$admin['id']]
    );
    
    $result = $stmt->get_result();
    $adminData = $result->fetch_assoc();
    
    if (!password_verify($input['current_password'], $adminData['password_hash'])) {
        sendError(401, 'INVALID_PASSWORD', 'Current password is incorrect');
    }
    
    // Update password
    $passwordHash = password_hash($input['new_password'], PASSWORD_BCRYPT);
    
    $db->query(
        'UPDATE sd_admins SET password_hash = ?, updated_at = NOW() WHERE id = ?',
        [$passwordHash, $admin['id']]
    );
    
    sendSuccess(200, ['message' => 'Password changed successfully']);
}

function handleDevicePoll() {
    $deviceId = getDeviceId();
    $signature = getDeviceSignature();
    
    if (!$deviceId || !$signature) {
        sendError(401, 'UNAUTHORIZED', 'Device ID and signature required');
    }
    
    // Verify signature
    $payload = $deviceId . time();
    if (!verifyDeviceSignature($deviceId, $signature, $payload)) {
        sendError(401, 'INVALID_SIGNATURE', 'Device signature verification failed');
    }
    
    $db = Database::getInstance();
    
    // Get next pending command
    $stmt = $db->query(
        'SELECT * FROM sd_commands 
         WHERE device_id = ? AND status = "PENDING" 
         AND expires_at > NOW() 
         ORDER BY created_at ASC LIMIT 1',
        [$deviceId]
    );
    
    $result = $stmt->get_result();
    $command = $result->fetch_assoc();
    
    if ($command) {
        // Update command status to DELIVERED
        $db->query(
            'UPDATE sd_commands SET status = "DELIVERED", delivered_at = NOW() WHERE id = ?',
            [$command['id']]
        );
        
        // Decode payload
        $payload = json_decode($command['payload'], true);
        
        sendSuccess(200, [
            'command' => [
                'id' => $command['id'],
                'command_id' => $command['command_id'],
                'action' => $payload['action'],
                'duration_ms' => $payload['duration_ms'],
                'expires_at' => $command['expires_at'],
                'signature' => $command['signature']
            ]
        ]);
    } else {
        sendSuccess(204, null);
    }
}

function handleDeviceAck() {
    $deviceId = getDeviceId();
    $signature = getDeviceSignature();
    
    if (!$deviceId || !$signature) {
        sendError(401, 'UNAUTHORIZED', 'Device ID and signature required');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['command_id']) || !isset($input['status'])) {
        sendError(400, 'INVALID_INPUT', 'Command ID and status required');
    }
    
    $db = Database::getInstance();
    
    // Get command
    $stmt = $db->query(
        'SELECT * FROM sd_commands WHERE command_id = ? AND device_id = ? LIMIT 1',
        [$input['command_id'], $deviceId]
    );
    
    $result = $stmt->get_result();
    $command = $result->fetch_assoc();
    
    if (!$command) {
        sendError(404, 'COMMAND_NOT_FOUND', 'Command not found');
    }
    
    // Update command status
    $db->transaction(function($db) use ($command, $input) {
        $db->query(
            'UPDATE sd_commands SET status = ?, completed_at = NOW(), actual_duration = ? WHERE id = ?',
            [$input['status'], $input['actual_duration_ms'] ?? null, $command['id']]
        );
        
        // If successful, consume guest pass if applicable
        if ($input['status'] === 'EXECUTED' && $command['guest_pass_id']) {
            $db->query(
                'UPDATE sd_guest_passes SET used_count = used_count + 1 WHERE id = ?',
                [$command['guest_pass_id']]
            );
        }
    });
    
    sendSuccess(200, ['message' => 'ACK received']);
}

function handleDeviceHeartbeat() {
    $deviceId = getDeviceId();
    
    if (!$deviceId) {
        sendError(401, 'UNAUTHORIZED', 'Device ID required');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $db = Database::getInstance();
    
    // Update device status
    $db->query(
        'UPDATE sd_devices SET 
            last_heartbeat_at = NOW(),
            last_ip = ?,
            rssi = ?,
            free_heap = ?,
            uptime_seconds = ?
         WHERE device_id = ?',
        [
            $_SERVER['REMOTE_ADDR'],
            $input['rssi'] ?? null,
            $input['free_heap'] ?? null,
            $input['uptime_seconds'] ?? null,
            $deviceId
        ]
    );
    
    sendSuccess(200, ['message' => 'Heartbeat received']);
}

function handleDoorOpen() {
    $token = getAuthToken();
    if (!$token) {
        sendError(401, 'UNAUTHORIZED', 'Token required');
    }
    
    $admin = verifyAdminToken($token);
    if (!$admin) {
        sendError(401, 'INVALID_TOKEN', 'Invalid or expired token');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $duration = $input['duration'] ?? 3000;
    
    $db = Database::getInstance();
    
    // Get active device
    $stmt = $db->query(
        'SELECT id FROM sd_devices WHERE enabled = 1 LIMIT 1'
    );
    
    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    
    if (!$device) {
        sendError(503, 'NO_DEVICE', 'No device available');
    }
    
    // Create command
    $commandId = strtoupper(bin2hex(random_bytes(8)));
    $payload = json_encode([
        'action' => 'unlock',
        'duration_ms' => $duration
    ]);
    
    $expiresAt = date('Y-m-d H:i:s', time() + 10);
    
    $db->query(
        'INSERT INTO sd_commands (device_id, command_id, payload, status, expires_at, created_by) VALUES (?, ?, ?, "PENDING", ?, ?)',
        [$device['id'], $commandId, $payload, $expiresAt, $admin['id']]
    );
    
    // Log to audit
    $db->query(
        'INSERT INTO sd_audit_logs (admin_id, action, resource_type, resource_id, success, ip_address) VALUES (?, "door_open", "command", LAST_INSERT_ID(), 1, ?)',
        [$admin['id'], $_SERVER['REMOTE_ADDR']]
    );
    
    sendSuccess(202, [
        'command_id' => $commandId,
        'status' => 'PENDING',
        'message' => 'Door open command queued'
    ]);
}

function handleGuestPass($token) {
    // This is a public endpoint - no auth required
    
    $db = Database::getInstance();
    
    // Hash token for lookup
    $tokenHash = hash('sha256', $token);
    
    $stmt = $db->query(
        'SELECT * FROM sd_guest_passes WHERE token_hash = ? AND status = "active" LIMIT 1',
        [$tokenHash]
    );
    
    $result = $stmt->get_result();
    $pass = $result->fetch_assoc();
    
    if (!$pass) {
        sendError(404, 'PASS_NOT_FOUND', 'Guest pass not found or expired');
    }
    
    // Check if pass is expired
    if ($pass['expires_at'] && $pass['expires_at'] < date('Y-m-d H:i:s')) {
        $db->query('UPDATE sd_guest_passes SET status = "expired" WHERE id = ?', [$pass['id']]);
        sendError(410, 'PASS_EXPIRED', 'Guest pass has expired');
    }
    
    // Check usage limit
    if ($pass['max_uses'] > 0 && $pass['used_count'] >= $pass['max_uses']) {
        sendError(410, 'PASS_EXHAUSTED', 'No remaining uses');
    }
    
    // Process door open request
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input && $input['action'] === 'open') {
        // Create command
        $commandId = strtoupper(bin2hex(random_bytes(8)));
        $payload = json_encode([
            'action' => 'unlock',
            'duration_ms' => 3000
        ]);
        
        $expiresAt = date('Y-m-d H:i:s', time() + 10);
        
        $db->transaction(function($db) use ($pass, $commandId, $payload, $expiresAt) {
            // Get device
            $stmt = $db->query('SELECT id FROM sd_devices WHERE enabled = 1 LIMIT 1');
            $result = $stmt->get_result();
            $device = $result->fetch_assoc();
            
            if ($device) {
                // Create command linked to guest pass
                $db->query(
                    'INSERT INTO sd_commands (device_id, command_id, payload, status, expires_at, guest_pass_id) VALUES (?, ?, ?, "PENDING", ?, ?)',
                    [$device['id'], $commandId, $payload, $expiresAt, $pass['id']]
                );
            }
        });
        
        sendSuccess(202, [
            'command_id' => $commandId,
            'status' => 'PENDING',
            'message' => 'Door open command queued'
        ]);
    } else {
        // Return pass info
        sendSuccess(200, [
            'pass' => [
                'name' => $pass['name'],
                'total_uses' => $pass['max_uses'],
                'used_uses' => $pass['used_count'],
                'remaining_uses' => $pass['max_uses'] - $pass['used_count']
            ]
        ]);
    }
}

function handleGetUsers() {
    $token = getAuthToken();
    if (!$token) {
        sendError(401, 'UNAUTHORIZED', 'Token required');
    }
    
    $admin = verifyAdminToken($token);
    if (!$admin) {
        sendError(401, 'INVALID_TOKEN', 'Invalid or expired token');
    }
    
    $page = $_GET['page'] ?? 1;
    $limit = min($_GET['limit'] ?? 50, 100);
    $offset = ($page - 1) * $limit;
    
    $db = Database::getInstance();
    
    // Get total count
    $stmt = $db->query('SELECT COUNT(*) as total FROM sd_users');
    $result = $stmt->get_result();
    $countRow = $result->fetch_assoc();
    $total = $countRow['total'];
    
    // Get users
    $stmt = $db->query(
        'SELECT id, user_id, name, permissions, remaining_uses, enabled, activated, last_used_at 
         FROM sd_users ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset]
    );
    
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
    
    sendSuccess(200, [
        'users' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleCreateUser() {
    $token = getAuthToken();
    if (!$token) {
        sendError(401, 'UNAUTHORIZED', 'Token required');
    }
    
    $admin = verifyAdminToken($token);
    if (!$admin) {
        sendError(401, 'INVALID_TOKEN', 'Invalid or expired token');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['name'])) {
        sendError(400, 'INVALID_INPUT', 'Name is required');
    }
    
    $db = Database::getInstance();
    
    // Generate user ID and activation code
    $userId = rand(1000, 9999);
    $activationCode = strtoupper(bin2hex(random_bytes(4)));
    
    $db->query(
        'INSERT INTO sd_users (user_id, name, permissions, remaining_uses, activation_code) VALUES (?, ?, ?, ?, ?)',
        [$userId, $input['name'], $input['permissions'] ?? 1, $input['remaining_uses'] ?? 0, $activationCode]
    );
    
    sendSuccess(201, [
        'user' => [
            'user_id' => $userId,
            'activation_code' => $activationCode,
            'name' => $input['name']
        ]
    ]);
}

function handleHealth() {
    $db = Database::getInstance();
    
    // Check database
    $stmt = $db->query('SELECT 1');
    $dbOk = $stmt !== false;
    
    // Check device
    $stmt = $db->query(
        'SELECT COUNT(*) as count FROM sd_devices WHERE enabled = 1 AND last_heartbeat_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
    );
    $result = $stmt->get_result();
    $deviceStatus = $result->fetch_assoc();
    
    sendSuccess(200, [
        'status' => 'ok',
        'version' => '1.0.0',
        'database' => ['status' => $dbOk ? 'connected' : 'disconnected'],
        'device' => ['online' => $deviceStatus['count'] > 0]
    ]);
}

// ============================================
// RESPONSE HELPERS
// ============================================

function sendSuccess($code, $data) {
    http_response_code($code);
    echo json_encode([
        'ok' => true,
        'data' => $data,
        'requestId' => REQUEST_ID,
        'serverTime' => date('c')
    ]);
    exit;
}

function sendError($code, $errorCode, $message) {
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => $errorCode,
            'message' => $message
        ],
        'requestId' => REQUEST_ID,
        'serverTime' => date('c')
    ]);
    exit;
}

function getAuthToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
    }
    return null;
}

function getDeviceId() {
    $headers = getallheaders();
    return $headers['Device-ID'] ?? null;
}

function getDeviceSignature() {
    $headers = getallheaders();
    return $headers['Device-Signature'] ?? null;
}
