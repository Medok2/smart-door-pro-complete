<?php
/**
 * Smart Door Pro - Main Application Entry Point
 * 
 * @author A.K
 * @version 1.0.0
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === 'true' ? '1' : '0');

// Define paths
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');

// Load environment variables
require_once BASE_PATH . '/src/Config/DotEnv.php';
\SmartDoor\Config\DotEnv::load(BASE_PATH);

// Autoloader
require_once BASE_PATH . '/vendor/autoload.php';

// Set timezone
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

// Initialize application
try {
    $app = new \SmartDoor\App();
    $app->run();
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    
    $response = [
        'success' => false,
        'error' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : 'Internal Server Error',
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
    exit(1);
}
