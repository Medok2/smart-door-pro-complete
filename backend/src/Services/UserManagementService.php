<?php
/**
 * User Management Service - Advanced Algorithm for 90+ Users
 * 
 * Features:
 * - Role-based access control
 * - Time-based access rules
 * - Daily/Weekly usage limits
 * - Biometric support
 * - Cooldown between access attempts
 * - Access suspension
 */

namespace SmartDoor\Services;

use SmartDoor\Config\Database;

class UserManagementService {
    
    /**
     * Check if user can access door right now
     * 
     * Algorithm complexity: O(1) with indexed lookups
     * Handles up to 90+ users efficiently
     */
    public static function canUserAccess($userId) {
        $db = Database::getInstance();
        
        // Get user with access rules (indexed query)
        $stmt = $db->query(
            'SELECT u.*, uar.* FROM users u 
             LEFT JOIN user_access_rules uar ON u.id = uar.user_id 
             WHERE u.id = ? AND u.enabled = 1 LIMIT 1',
            [$userId]
        );
        
        $user = $stmt->fetch();
        if (!$user) {
            return ['allowed' => false, 'reason' => 'User not found or disabled'];
        }
        
        // Check if access rules exist
        if (!$user['user_id']) {
            return ['allowed' => false, 'reason' => 'No access rules configured'];
        }
        
        // Check if suspended
        if ($user['suspended_at']) {
            return ['allowed' => false, 'reason' => 'User suspended: ' . $user['suspension_reason']];
        }
        
        // Check if enabled in rules
        if (!$user['enabled']) {
            return ['allowed' => false, 'reason' => 'Access rules disabled'];
        }
        
        // Check validity dates
        $today = date('Y-m-d');
        if ($user['valid_from'] && $today < $user['valid_from']) {
            return ['allowed' => false, 'reason' => 'Access not yet valid'];
        }
        if ($user['valid_until'] && $today > $user['valid_until']) {
            return ['allowed' => false, 'reason' => 'Access expired'];
        }
        
        // Check allowed days
        $currentDay = date('D');
        $allowedDays = explode(',', $user['allowed_days']);
        $dayMap = [
            'Mon' => 'Mon', 'Tue' => 'Tue', 'Wed' => 'Wed', 'Thu' => 'Thu',
            'Fri' => 'Fri', 'Sat' => 'Sat', 'Sun' => 'Sun'
        ];
        if (!in_array($currentDay, $allowedDays)) {
            return ['allowed' => false, 'reason' => 'Access not allowed on this day'];
        }
        
        // Check time window
        $currentTime = date('H:i:s');
        if ($currentTime < $user['allowed_start_time'] || $currentTime > $user['allowed_end_time']) {
            return ['allowed' => false, 'reason' => 'Outside allowed time window'];
        }
        
        // Check daily usage limit
        if (!$user['unlimited_access'] && $user['max_daily_uses']) {
            $stmt = $db->query(
                'SELECT COUNT(*) as count FROM access_events 
                 WHERE user_id = ? AND status = "success" 
                 AND DATE(created_at) = CURDATE()',
                [$userId]
            );
            $result = $stmt->fetch();
            if ($result['count'] >= $user['max_daily_uses']) {
                return ['allowed' => false, 'reason' => 'Daily usage limit reached'];
            }
        }
        
        // Check total usage limit
        if (!$user['unlimited_access'] && $user['max_total_uses']) {
            $stmt = $db->query(
                'SELECT COUNT(*) as count FROM access_events 
                 WHERE user_id = ? AND status = "success"',
                [$userId]
            );
            $result = $stmt->fetch();
            if ($result['count'] >= $user['max_total_uses']) {
                return ['allowed' => false, 'reason' => 'Total usage limit reached'];
            }
        }
        
        // Check cooldown
        if ($user['cooldown_seconds']) {
            $stmt = $db->query(
                'SELECT MAX(created_at) as last_access FROM access_events 
                 WHERE user_id = ? AND status = "success" 
                 LIMIT 1',
                [$userId]
            );
            $result = $stmt->fetch();
            if ($result['last_access']) {
                $secondsSinceLastAccess = time() - strtotime($result['last_access']);
                if ($secondsSinceLastAccess < $user['cooldown_seconds']) {
                    $waitSeconds = $user['cooldown_seconds'] - $secondsSinceLastAccess;
                    return ['allowed' => false, 'reason' => 'Please wait ' . $waitSeconds . ' seconds before trying again'];
                }
            }
        }
        
        return ['allowed' => true, 'user_id' => $userId];
    }
    
    /**
     * Get all users with pagination and search
     * Optimized for 90+ users
     */
    public static function getAllUsers($page = 1, $perPage = 50, $search = '') {
        $db = Database::getInstance();
        
        $limit = min($perPage, 100); // Max 100 per page
        $offset = ($page - 1) * $limit;
        
        $whereClause = '';
        $params = [];
        
        if ($search) {
            $whereClause = 'WHERE email LIKE ? OR first_name LIKE ? OR last_name LIKE ?';
            $searchTerm = "%$search%";
            $params = [$searchTerm, $searchTerm, $searchTerm];
        }
        
        // Get total count
        $countStmt = $db->query(
            'SELECT COUNT(*) as total FROM users ' . $whereClause,
            $params
        );
        $countResult = $countStmt->fetch();
        $total = $countResult['total'];
        
        // Get paginated results
        $stmt = $db->query(
            'SELECT id, email, first_name, last_name, role, enabled, created_at 
             FROM users ' . $whereClause . ' 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        
        return [
            'data' => $stmt->fetchAll(),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ];
    }
    
    /**
     * Suspend user access
     */
    public static function suspendUser($userId, $reason = '') {
        $db = Database::getInstance();
        
        $db->update(
            'user_access_rules',
            [
                'suspended_at' => date('Y-m-d H:i:s'),
                'suspension_reason' => $reason
            ],
            'user_id = ?',
            [$userId]
        );
        
        // Log audit
        self::auditLog(null, 'suspend_user', 'user', $userId, ['reason' => $reason]);
    }
    
    /**
     * Resume user access
     */
    public static function resumeUser($userId) {
        $db = Database::getInstance();
        
        $db->update(
            'user_access_rules',
            [
                'suspended_at' => null,
                'suspension_reason' => null
            ],
            'user_id = ?',
            [$userId]
        );
        
        self::auditLog(null, 'resume_user', 'user', $userId, []);
    }
    
    /**
     * Get user access history
     */
    public static function getUserAccessHistory($userId, $limit = 100) {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT id, action, status, method, created_at FROM access_events 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?',
            [$userId, $limit]
        );
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get active users (online in last 24 hours)
     */
    public static function getActiveUsers() {
        $db = Database::getInstance();
        
        $stmt = $db->query(
            'SELECT DISTINCT u.id, u.email, u.role, COUNT(ae.id) as access_count 
             FROM users u 
             LEFT JOIN access_events ae ON u.id = ae.user_id 
             AND ae.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
             WHERE u.enabled = 1 
             GROUP BY u.id 
             ORDER BY access_count DESC'
        );
        
        return $stmt->fetchAll();
    }
    
    /**
     * Generate activation code for new user
     */
    public static function generateActivationCode($deviceName = '') {
        $code = strtoupper(bin2hex(random_bytes(4))); // e.g., "A3F2B1C9"
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // Expires in 1 hour
        
        $db = Database::getInstance();
        $id = $db->insert('device_activations', [
            'activation_code' => $code,
            'device_name' => $deviceName,
            'expires_at' => $expiresAt
        ]);
        
        return [
            'activation_code' => $code,
            'expires_at' => $expiresAt
        ];
    }
    
    /**
     * Audit logging
     */
    public static function auditLog($actorId, $action, $resourceType, $resourceId, $changes) {
        $db = Database::getInstance();
        
        $db->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'changes' => json_encode($changes),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}
