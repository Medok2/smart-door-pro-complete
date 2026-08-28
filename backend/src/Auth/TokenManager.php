<?php
namespace SmartDoor\Auth;

use SmartDoor\Config\Database;

class TokenManager {
    private static $secret;
    private static $algorithm = 'HS256';
    private static $expiry = 3600; // 1 hour
    private static $refreshExpiry = 604800; // 7 days
    
    public static function init() {
        self::$secret = getenv('JWT_SECRET');
        self::$algorithm = getenv('JWT_ALGORITHM') ?: 'HS256';
        self::$expiry = intval(getenv('JWT_EXPIRY') ?: 3600);
        self::$refreshExpiry = intval(getenv('JWT_REFRESH_EXPIRY') ?: 604800);
    }
    
    public static function create($userId, $role, $permissions = []) {
        if (!self::$secret) {
            self::init();
        }
        
        $now = time();
        $payload = [
            'iat' => $now,
            'exp' => $now + self::$expiry,
            'sub' => $userId,
            'role' => $role,
            'permissions' => $permissions
        ];
        
        $token = self::encode($payload);
        $refreshToken = self::encodeRefresh($userId, $role);
        
        return [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::$expiry
        ];
    }
    
    public static function verify($token) {
        if (!self::$secret) {
            self::init();
        }
        
        try {
            $payload = self::decode($token);
            
            if ($payload['exp'] < time()) {
                throw new \Exception('Token expired');
            }
            
            return $payload;
        } catch (\Exception $e) {
            throw new \Exception('Invalid token: ' . $e->getMessage());
        }
    }
    
    public static function refresh($refreshToken) {
        try {
            $payload = self::decodeRefresh($refreshToken);
            return self::create($payload['sub'], $payload['role'], $payload['permissions'] ?? []);
        } catch (\Exception $e) {
            throw new \Exception('Invalid refresh token');
        }
    }
    
    private static function encode($payload) {
        $header = [
            'alg' => self::$algorithm,
            'typ' => 'JWT'
        ];
        
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::$secret, true)
        );
        
        return "$headerEncoded.$payloadEncoded.$signature";
    }
    
    private static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid token format');
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::$secret, true)
        );
        
        if (!hash_equals($signatureEncoded, $signature)) {
            throw new \Exception('Invalid signature');
        }
        
        return json_decode(self::base64UrlDecode($payloadEncoded), true);
    }
    
    private static function encodeRefresh($userId, $role) {
        $payload = [
            'iat' => time(),
            'exp' => time() + self::$refreshExpiry,
            'sub' => $userId,
            'role' => $role,
            'type' => 'refresh'
        ];
        return self::encode($payload);
    }
    
    private static function decodeRefresh($token) {
        $payload = self::decode($token);
        if (($payload['type'] ?? null) !== 'refresh') {
            throw new \Exception('Not a refresh token');
        }
        return $payload;
    }
    
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
    }
}
