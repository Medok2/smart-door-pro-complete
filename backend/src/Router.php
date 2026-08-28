<?php
namespace SmartDoor;

use SmartDoor\Config\Database;
use SmartDoor\Auth\TokenManager;
use SmartDoor\Auth\Authenticator;

class Router {
    private $routes = [];
    private $middlewares = [];
    private $notFoundCallback;
    private $errorCallback;
    
    public function __construct() {
        $this->setupDefaultMiddlewares();
    }
    
    private function setupDefaultMiddlewares() {
        // CORS Middleware
        $this->middlewares[] = function($req, $res, $next) {
            $res->setHeader('Access-Control-Allow-Origin', getenv('CORS_ORIGINS') ?: '*');
            $res->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $res->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $res->setHeader('Access-Control-Max-Age', '3600');
            
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit(0);
            }
            
            return $next($req, $res);
        };
        
        // Security Headers Middleware
        $this->middlewares[] = function($req, $res, $next) {
            $res->setHeader('X-Content-Type-Options', 'nosniff');
            $res->setHeader('X-Frame-Options', 'DENY');
            $res->setHeader('X-XSS-Protection', '1; mode=block');
            $res->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            return $next($req, $res);
        };
    }
    
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
    }
    
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
    }
    
    public function put($path, $handler) {
        $this->addRoute('PUT', $path, $handler);
    }
    
    public function patch($path, $handler) {
        $this->addRoute('PATCH', $path, $handler);
    }
    
    public function delete($path, $handler) {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    private function addRoute($method, $path, $handler) {
        $path = str_replace('{id}', '(?P<id>\\d+)', $path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $path . '$#',
            'handler' => $handler
        ];
    }
    
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = str_replace('/api/v1', '', $path);
        
        $request = new Request($method, $path, $_GET, $_POST, getallheaders());
        $response = new Response();
        
        // Execute middlewares
        $index = 0;
        $next = function() use (&$index, &$next, $request, $response) {
            if ($index >= count($this->middlewares)) {
                return;
            }
            $middleware = $this->middlewares[$index++];
            return $middleware($request, $response, function() use (&$index, &$next, $request, $response) {
                return $next();
            });
        };
        $next();
        
        // Find and execute route
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['pattern'], $path, $matches)) {
                try {
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    call_user_func($route['handler'], $request, $response, $params);
                    $response->send();
                    return;
                } catch (\Exception $e) {
                    $this->handleError($response, $e);
                    return;
                }
            }
        }
        
        // 404 Not Found
        http_response_code(404);
        $response->json([
            'success' => false,
            'error' => 'Endpoint not found'
        ]);
        $response->send();
    }
    
    private function handleError($response, \Exception $e) {
        http_response_code(500);
        $response->json([
            'success' => false,
            'error' => getenv('APP_DEBUG') === 'true' ? $e->getMessage() : 'Internal Server Error'
        ]);
        $response->send();
    }
}

class Request {
    public $method;
    public $path;
    public $query;
    public $post;
    public $headers;
    public $body;
    public $user;
    
    public function __construct($method, $path, $query, $post, $headers) {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->headers = $headers;
        $this->body = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    
    public function getHeader($name) {
        return $this->headers[$name] ?? null;
    }
    
    public function getAuthToken() {
        $auth = $this->getHeader('Authorization');
        if (preg_match('/Bearer\s+(\S+)/', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

class Response {
    private $headers = [];
    private $data = null;
    private $statusCode = 200;
    
    public function json($data, $statusCode = 200) {
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/json';
        $this->data = json_encode($data);
    }
    
    public function setHeader($name, $value) {
        $this->headers[$name] = $value;
    }
    
    public function send() {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        if ($this->data !== null) {
            echo $this->data;
        }
    }
}
