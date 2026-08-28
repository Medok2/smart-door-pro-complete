<?php
/**
 * Device Service - ESP8266 Device Management
 */

namespace SmartDoor\Services;

use SmartDoor\Config\Database;

class DeviceService {
    
    /**
     * Activate device with activation code
     */
    public static function activateDevice($activationCode, $deviceId, $deviceData) {
        $db = Database::getInstance();
        
        // Verify activation code
        $stmt = $db->query(
            'SELECT * FROM device_activations 
             WHERE activation_code = ? 
             AND used = 0 
             AND expires_at > NOW() 
             LIMIT 1',
            [$activationCode]
        );
        
        $activation = $stmt->fetch();
        
        if (!$activation) {
            throw new \Exception('Invalid or expired activation code');
        }
        
        // Generate device secret
        $deviceSecret = bin2hex(random_bytes(32));
        
        // Check if device exists
        $stmt = $db->query(
            'SELECT id FROM door_device WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        if ($stmt->fetch()) {
            // Update existing device
            $db->update(
                'door_device',
                [
                    'device_secret' => $deviceSecret,
                    'status' => 'active',
                    'firmware_version' => $deviceData['firmware_version'] ?? '1.0.0',
                    'last_seen_at' => date('Y-m-d H:i:s')
                ],
                'device_id = ?',
                [$deviceId]
            );
        } else {
            // Create new device
            $db->insert('door_device', [
                'device_id' => $deviceId,
                'device_secret' => $deviceSecret,
                'status' => 'active',
                'firmware_version' => $deviceData['firmware_version'] ?? '1.0.0',
                'last_seen_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Mark activation code as used
        $db->update(
            'device_activations',
            [
                'used' => true,
                'used_at' => date('Y-m-d H:i:s'),
                'used_by' => $deviceData['used_by'] ?? null
            ],
            'id = ?',
            [$activation['id']]
        );
        
        return [
            'device_id' => $deviceId,
            'device_secret' => $deviceSecret,
            'status' => 'active'
        ];
    }
    
    /**
     * Verify device signature (HMAC-SHA256)
     */
    public static function verifyDeviceSignature($deviceId, $timestamp, $payload, $signature) {
        $db = Database::getInstance();
        
        // Get device secret
        $stmt = $db->query(
            'SELECT device_secret FROM door_device WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        $device = $stmt->fetch();
        
        if (!$device) {
            return false;
        }
        
        // Check timestamp (±30 seconds)
        $requestTime = intval($timestamp);
        $currentTime = time();
        
        if (abs($currentTime - $requestTime) > 30) {
            return false;  // Replay attack prevention
        }
        
        // Verify HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $payload, $device['device_secret']);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Record device heartbeat
     */
    public static function recordHeartbeat($deviceId, $heartbeatData) {
        $db = Database::getInstance();
        
        // Get device
        $stmt = $db->query(
            'SELECT id FROM door_device WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        $device = $stmt->fetch();
        
        if (!$device) {
            throw new \Exception('Device not found');
        }
        
        // Update device status
        $db->update(
            'door_device',
            [
                'last_seen_at' => date('Y-m-d H:i:s'),
                'last_heartbeat_at' => date('Y-m-d H:i:s'),
                'online_status' => true,
                'firmware_version' => $heartbeatData['firmware_version'] ?? null
            ],
            'id = ?',
            [$device['id']]
        );
        
        // Store heartbeat data
        $db->insert('device_heartbeats', [
            'device_id' => $device['id'],
            'rssi' => $heartbeatData['rssi'] ?? null,
            'free_heap' => $heartbeatData['free_heap'] ?? null,
            'reset_reason' => $heartbeatData['reset_reason'] ?? null,
            'uptime_seconds' => $heartbeatData['uptime_seconds'] ?? null
        ]);
    }
    
    /**
     * Get device status
     */
    public static function getDeviceStatus($deviceId) {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT * FROM door_device WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        $device = $stmt->fetch();
        
        if (!$device) {
            return null;
        }
        
        // Determine online status (last seen within 2 minutes)
        $lastSeen = strtotime($device['last_seen_at']);
        $device['online'] = (time() - $lastSeen) < 120;
        
        // Get last heartbeat details
        $stmt = $db->query(
            'SELECT rssi, free_heap FROM device_heartbeats 
             WHERE device_id = ? 
             ORDER BY created_at DESC LIMIT 1',
            [$device['id']]
        );
        
        $lastHeartbeat = $stmt->fetch();
        if ($lastHeartbeat) {
            $device['last_rssi'] = $lastHeartbeat['rssi'];
            $device['last_free_heap'] = $lastHeartbeat['free_heap'];
        }
        
        // Get pending commands count
        $stmt = $db->query(
            'SELECT COUNT(*) as count FROM device_commands 
             WHERE device_id = ? AND status IN ("pending", "sent") 
             AND expires_at > NOW()',
            [$device['id']]
        );
        
        $pending = $stmt->fetch();
        $device['pending_commands'] = $pending['count'];
        
        return $device;
    }
    
    /**
     * Rotate device secret
     */
    public static function rotateSecret($deviceId) {
        $db = Database::getInstance();
        
        $newSecret = bin2hex(random_bytes(32));
        
        $db->update(
            'door_device',
            ['device_secret' => $newSecret],
            'device_id = ?',
            [$deviceId]
        );
        
        return ['device_secret' => $newSecret];
    }
    
    /**
     * Get device diagnostics
     */
    public static function getDiagnostics($deviceId) {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT * FROM door_device WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        $device = $stmt->fetch();
        
        if (!$device) {
            return null;
        }
        
        // Get last 10 heartbeats
        $stmt = $db->query(
            'SELECT created_at, rssi, free_heap, uptime_seconds 
             FROM device_heartbeats 
             WHERE device_id = ? 
             ORDER BY created_at DESC LIMIT 10',
            [$device['id']]
        );
        
        $heartbeats = $stmt->fetchAll();
        
        // Get last commands
        $stmt = $db->query(
            'SELECT command_id, status, executed_at, error_code 
             FROM device_commands 
             WHERE device_id = ? 
             ORDER BY created_at DESC LIMIT 10',
            [$device['id']]
        );
        
        $recentCommands = $stmt->fetchAll();
        
        // Calculate statistics
        $stmt = $db->query(
            'SELECT COUNT(*) as total, 
                    SUM(CASE WHEN status = "executed" THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed 
             FROM device_commands WHERE device_id = ?',
            [$device['id']]
        );
        
        $stats = $stmt->fetch();
        
        return [
            'device' => $device,
            'heartbeats' => $heartbeats,
            'recent_commands' => $recentCommands,
            'statistics' => [
                'total_commands' => $stats['total'],
                'successful_commands' => $stats['successful'],
                'failed_commands' => $stats['failed'],
                'success_rate' => $stats['total'] > 0 ? 
                    round(($stats['successful'] / $stats['total']) * 100, 2) : 0
            ]
        ];
    }
}
