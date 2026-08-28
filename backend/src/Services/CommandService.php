<?php
/**
 * Command Service - Door Control Commands
 */

namespace SmartDoor\Services;

use SmartDoor\Config\Database;

class CommandService {
    
    /**
     * Queue door open command
     */
    public static function queueOpenCommand($userId, $source = 'manual_app', $duration = null) {
        $db = Database::getInstance();
        
        // Get door settings
        $stmt = $db->query('SELECT unlock_duration FROM door_settings LIMIT 1');
        $settings = $stmt->fetch();
        
        if (!$duration) {
            $duration = $settings['unlock_duration'] ?? 3000;
        }
        
        // Validate duration
        $stmt = $db->query('SELECT min_unlock_duration, max_unlock_duration FROM door_settings LIMIT 1');
        $limits = $stmt->fetch();
        
        if ($duration < $limits['min_unlock_duration'] || $duration > $limits['max_unlock_duration']) {
            throw new \Exception(
                'Duration must be between ' . $limits['min_unlock_duration'] . 
                ' and ' . $limits['max_unlock_duration'] . ' ms'
            );
        }
        
        // Get device
        $stmt = $db->query('SELECT id FROM door_device WHERE status = "active" LIMIT 1');
        $device = $stmt->fetch();
        
        if (!$device) {
            throw new \Exception('Door device not configured');
        }
        
        // Generate command
        $commandId = strtoupper(bin2hex(random_bytes(8)));
        $requestId = bin2hex(random_bytes(8));
        $expiresAt = date('Y-m-d H:i:s', time() + 10); // 10 second expiry
        
        $id = $db->insert('device_commands', [
            'command_id' => $commandId,
            'device_id' => $device['id'],
            'action' => 'unlock',
            'duration_ms' => $duration,
            'status' => 'pending',
            'source' => $source,
            'actor_id' => $userId,
            'request_id' => $requestId,
            'issued_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'sequence_number' => self::getNextSequence($device['id']),
            'nonce' => bin2hex(random_bytes(8))
        ]);
        
        return [
            'id' => $id,
            'command_id' => $commandId,
            'request_id' => $requestId,
            'device_id' => $device['id']
        ];
    }
    
    /**
     * Get next command for device
     */
    public static function getNextCommand($deviceId) {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT * FROM device_commands 
             WHERE device_id = ? AND status IN ("pending", "sent") 
             AND expires_at > NOW() 
             ORDER BY created_at ASC 
             LIMIT 1',
            [$deviceId]
        );
        
        $command = $stmt->fetch();
        
        if ($command) {
            // Update status to sent
            $db->update(
                'device_commands',
                ['status' => 'sent'],
                'id = ?',
                [$command['id']]
            );
        }
        
        return $command;
    }
    
    /**
     * Handle command acknowledgment from device
     */
    public static function handleAck($commandId, $ackData) {
        $db = Database::getInstance();
        
        // Get command
        $stmt = $db->query(
            'SELECT * FROM device_commands WHERE command_id = ? LIMIT 1',
            [$commandId]
        );
        $command = $stmt->fetch();
        
        if (!$command) {
            throw new \Exception('Command not found');
        }
        
        if ($command['status'] === 'executed') {
            // Already executed, ignore duplicate ACK
            return ['duplicate' => true];
        }
        
        // Update command
        $db->update(
            'device_commands',
            [
                'status' => $ackData['status'],
                'executed_at' => date('Y-m-d H:i:s'),
                'actual_duration_ms' => $ackData['actual_duration_ms'] ?? null,
                'error_code' => $ackData['error_code'] ?? null,
                'error_message' => $ackData['error_message'] ?? null
            ],
            'id = ?',
            [$command['id']]
        );
        
        // Log access event if successful
        if ($ackData['status'] === 'executed') {
            $db->insert('access_events', [
                'user_id' => $command['actor_id'],
                'device_id' => $command['device_id'],
                'action' => 'open',
                'status' => 'success',
                'method' => self::getMethodFromSource($command['source']),
                'duration_ms' => $ackData['actual_duration_ms'] ?? $command['duration_ms'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        return [
            'success' => true,
            'command_id' => $commandId,
            'status' => $ackData['status']
        ];
    }
    
    /**
     * Clean expired commands
     */
    public static function cleanupExpired() {
        $db = Database::getInstance();
        
        // Mark expired commands
        $db->query(
            'UPDATE device_commands SET status = "expired" 
             WHERE status IN ("pending", "sent") 
             AND expires_at < NOW()'
        );
    }
    
    /**
     * Get next sequence number for device
     */
    private static function getNextSequence($deviceId) {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT last_sequence FROM door_device WHERE id = ?',
            [$deviceId]
        );
        $device = $stmt->fetch();
        
        $nextSeq = ($device['last_sequence'] ?? 0) + 1;
        
        // Update device
        $db->update(
            'door_device',
            ['last_sequence' => $nextSeq],
            'id = ?',
            [$deviceId]
        );
        
        return $nextSeq;
    }
    
    /**
     * Get method from source
     */
    private static function getMethodFromSource($source) {
        $map = [
            'manual_app' => 'button',
            'voice_command' => 'voice',
            'qr_scan' => 'qr',
            'biometric' => 'biometric',
            'admin' => 'admin',
            'auto_arrival' => 'auto_arrival'
        ];
        
        return $map[$source] ?? 'button';
    }
}
