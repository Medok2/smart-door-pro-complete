<?php
/**
 * QR Service - Advanced Race Condition Prevention
 * 
 * Handles:
 * - QR token generation
 * - Atomic usage counting
 * - Reservation system
 * - Concurrent request handling
 */

namespace SmartDoor\Services;

use SmartDoor\Config\Database;

class QrService {
    
    /**
     * Generate new guest pass with QR code
     */
    public static function generatePass($config) {
        $db = Database::getInstance();
        
        // Generate secure random token
        $token = bin2hex(random_bytes(16)); // 32 chars
        $tokenHash = hash('sha256', $token);
        
        // Calculate expiry time
        $expiresAt = null;
        if ($config['valid_until']) {
            $expiresAt = $config['valid_until'];
        } elseif ($config['expires_in_hours']) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($config['expires_in_hours'] * 3600));
        }
        
        $passData = [
            'token_hash' => $tokenHash,
            'status' => 'active',
            'used_count' => 0,
            'max_uses' => $config['max_uses'] ?? 1,
            'unlimited_uses' => $config['unlimited_uses'] ?? false,
            'valid_from' => $config['valid_from'] ?? date('Y-m-d H:i:s'),
            'valid_until' => $expiresAt,
            'access_start_time' => $config['access_start_time'] ?? null,
            'access_end_time' => $config['access_end_time'] ?? null,
            'allowed_days' => $config['allowed_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
            'require_otp' => $config['require_otp'] ?? false,
            'require_door_qr' => $config['require_door_qr'] ?? false,
            'cooldown_seconds' => $config['cooldown_seconds'] ?? 0,
            'created_by' => $config['created_by'] ?? 1
        ];
        
        $passId = $db->insert('guest_passes', $passData);
        
        // Generate QR code content
        $qrContent = "https://" . $_SERVER['HTTP_HOST'] . "/api/v1/guest/pass/" . $token;
        
        return [
            'pass_id' => $passId,
            'token' => $token,
            'token_hash' => $tokenHash,
            'qr_content' => $qrContent,
            'max_uses' => $passData['max_uses'],
            'expires_at' => $expiresAt
        ];
    }
    
    /**
     * Verify QR pass and reserve usage
     * 
     * This implements atomic reservation pattern to prevent race conditions:
     * 1. Begin transaction
     * 2. Lock the pass row
     * 3. Check validity and usage count
     * 4. Reserve usage (increment counter)
     * 5. Create command
     * 6. Commit transaction
     * 
     * If 20 phones try to use same QR simultaneously:
     * - Phone-1: Acquires lock, reserves, creates command, commits
     * - Phone-2-20: Wait for lock, check updated count, find 0 remaining, reject
     */
    public static function reserveUsage($token) {
        $db = Database::getInstance();
        $tokenHash = hash('sha256', $token);
        
        try {
            return $db->transaction(function($db) use ($tokenHash) {
                // Lock row (SELECT FOR UPDATE)
                $stmt = $db->query(
                    'SELECT id, status, used_count, max_uses, unlimited_uses, 
                            valid_until, access_start_time, access_end_time, cooldown_seconds 
                     FROM guest_passes 
                     WHERE token_hash = ? 
                     FOR UPDATE',
                    [$tokenHash]
                );
                
                $pass = $stmt->fetch();
                
                if (!$pass) {
                    throw new \Exception('QR code not found');
                }
                
                // Check status
                if ($pass['status'] !== 'active') {
                    throw new \Exception('QR code is ' . $pass['status']);
                }
                
                // Check expiry
                if ($pass['valid_until'] && $pass['valid_until'] < date('Y-m-d H:i:s')) {
                    throw new \Exception('QR code expired');
                }
                
                // Check time window
                $currentTime = date('H:i:s');
                if ($pass['access_start_time'] && $currentTime < $pass['access_start_time']) {
                    throw new \Exception('Access not yet available');
                }
                if ($pass['access_end_time'] && $currentTime > $pass['access_end_time']) {
                    throw new \Exception('Access window closed');
                }
                
                // Check usage limit
                if (!$pass['unlimited_uses'] && $pass['used_count'] >= $pass['max_uses']) {
                    throw new \Exception('QR code usage limit reached');
                }
                
                // Reserve usage
                $newCount = $pass['used_count'] + 1;
                $db->update(
                    'guest_passes',
                    ['used_count' => $newCount],
                    'id = ?',
                    [$pass['id']]
                );
                
                // Create reservation record
                $expiresAt = date('Y-m-d H:i:s', time() + 30); // 30 second timeout
                $reservationId = $db->insert('guest_pass_reservations', [
                    'guest_pass_id' => $pass['id'],
                    'expires_at' => $expiresAt,
                    'status' => 'reserved'
                ]);
                
                return [
                    'success' => true,
                    'pass_id' => $pass['id'],
                    'reservation_id' => $reservationId,
                    'used_count' => $newCount,
                    'max_uses' => $pass['max_uses'],
                    'unlimited_uses' => $pass['unlimited_uses'],
                    'cooldown_seconds' => $pass['cooldown_seconds']
                ];
            });
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Confirm QR usage after command execution
     */
    public static function confirmUsage($reservationId, $commandId) {
        $db = Database::getInstance();
        
        $db->update(
            'guest_pass_reservations',
            [
                'confirmed_at' => date('Y-m-d H:i:s'),
                'status' => 'confirmed'
            ],
            'id = ?',
            [$reservationId]
        );
    }
    
    /**
     * Cancel QR reservation if command fails
     */
    public static function cancelReservation($reservationId) {
        $db = Database::getInstance();
        
        // Get reservation details
        $stmt = $db->query(
            'SELECT guest_pass_id FROM guest_pass_reservations WHERE id = ?',
            [$reservationId]
        );
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            return false;
        }
        
        return $db->transaction(function($db) use ($reservationId, $reservation) {
            // Mark reservation as cancelled
            $db->update(
                'guest_pass_reservations',
                ['status' => 'cancelled'],
                'id = ?',
                [$reservationId]
            );
            
            // Decrement pass usage count
            $db->query(
                'UPDATE guest_passes SET used_count = used_count - 1 WHERE id = ?',
                [$reservation['guest_pass_id']]
            );
            
            return true;
        });
    }
    
    /**
     * Revoke QR pass
     */
    public static function revokePass($passId) {
        $db = Database::getInstance();
        
        $db->update(
            'guest_passes',
            ['status' => 'revoked'],
            'id = ?',
            [$passId]
        );
    }
    
    /**
     * Get pass info by token
     */
    public static function getPassByToken($token) {
        $db = Database::getInstance();
        $tokenHash = hash('sha256', $token);
        
        $stmt = $db->query(
            'SELECT * FROM guest_passes WHERE token_hash = ? LIMIT 1',
            [$tokenHash]
        );
        
        return $stmt->fetch();
    }
    
    /**
     * Clean expired QR codes (run as cron job)
     */
    public static function cleanupExpired() {
        $db = Database::getInstance();
        
        // Mark expired passes
        $db->query(
            'UPDATE guest_passes SET status = "expired" 
             WHERE status = "active" AND valid_until IS NOT NULL 
             AND valid_until < NOW()'
        );
        
        // Clean old reservations
        $db->query(
            'DELETE FROM guest_pass_reservations 
             WHERE expires_at < NOW() AND status = "reserved"'
        );
    }
}
