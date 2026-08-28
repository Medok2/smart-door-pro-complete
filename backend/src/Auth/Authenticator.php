<?php
namespace SmartDoor\Auth;

use SmartDoor\Config\Database;

class Authenticator {
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    public static function login($email, $password) {
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT id, email, password_hash, role, enabled FROM users WHERE email = ? LIMIT 1',
            [$email]
        );
        
        $user = $stmt->fetch();
        
        if (!$user || !self::verifyPassword($password, $user['password_hash'])) {
            throw new \Exception('Invalid email or password');
        }
        
        if (!$user['enabled']) {
            throw new \Exception('User account is disabled');
        }
        
        // Update last login
        $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        
        // Create tokens
        TokenManager::init();
        $tokens = TokenManager::create($user['id'], $user['role']);
        
        return [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role']
            ],
            'tokens' => $tokens
        ];
    }
    
    public static function getCurrentUser($request) {
        $token = $request->getAuthToken();
        
        if (!$token) {
            throw new \Exception('Authorization token required');
        }
        
        TokenManager::init();
        $payload = TokenManager::verify($token);
        
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT id, email, role, enabled FROM users WHERE id = ? AND enabled = 1 LIMIT 1',
            [$payload['sub']]
        );
        
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new \Exception('User not found or disabled');
        }
        
        return $user;
    }
    
    public static function hasPermission($user, $permission) {
        // Owner admin has all permissions
        if ($user['role'] === 'owner_admin') {
            return true;
        }
        
        $db = Database::getInstance();
        $stmt = $db->query(
            'SELECT 1 FROM user_permissions WHERE user_id = ? AND permission = ? LIMIT 1',
            [$user['id'], $permission]
        );
        
        return $stmt->fetch() !== false;
    }
}
