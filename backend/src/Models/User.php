<?php
namespace SmartDoor\Models;

class User extends BaseModel {
    protected static $table = 'users';
    
    public static function findByEmail($email) {
        return self::findBy('email', $email);
    }
    
    public static function findByPhone($phone) {
        return self::findBy('phone', $phone);
    }
    
    public static function getAccessRules($userId) {
        $db = \SmartDoor\Config\Database::getInstance();
        $stmt = $db->query(
            'SELECT * FROM user_access_rules WHERE user_id = ? LIMIT 1',
            [$userId]
        );
        return $stmt->fetch();
    }
    
    public static function setAccessRules($userId, $rules) {
        $db = \SmartDoor\Config\Database::getInstance();
        
        // Check if exists
        $existing = self::getAccessRules($userId);
        
        if ($existing) {
            $db->update('user_access_rules', $rules, 'user_id = ?', [$userId]);
        } else {
            $rules['user_id'] = $userId;
            $db->insert('user_access_rules', $rules);
        }
    }
}
