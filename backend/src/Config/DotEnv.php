<?php
namespace SmartDoor\Config;

class DotEnv {
    public static function load($path) {
        $env_file = $path . '/.env';
        
        if (!file_exists($env_file)) {
            throw new \Exception('.env file not found at ' . $env_file);
        }
        
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos($line, '=') === false || strpos($line, '#') === 0) {
                continue;
            }
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}
