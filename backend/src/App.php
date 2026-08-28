<?php
namespace SmartDoor;

use SmartDoor\Config\Database;
use SmartDoor\Router;
use SmartDoor\Auth\TokenManager;

class App {
    private $router;
    private $db;
    
    public function __construct() {
        // Initialize database
        $this->db = Database::getInstance();
        
        // Initialize router
        $this->router = new Router();
        
        // Register routes
        $this->registerRoutes();
    }
    
    private function registerRoutes() {
        // Auth Routes
        $this->router->post('/auth/login', [$this, 'handleLogin']);
        $this->router->post('/auth/refresh', [$this, 'handleRefresh']);
        $this->router->post('/auth/logout', [$this, 'handleLogout']);
        $this->router->get('/me', [$this, 'handleGetMe']);
        
        // Door Routes
        $this->router->get('/door', [$this, 'handleGetDoor']);
        $this->router->get('/door/status', [$this, 'handleGetDoorStatus']);
        $this->router->post('/door/open', [$this, 'handleOpenDoor']);
        $this->router->patch('/door/settings', [$this, 'handleUpdateDoorSettings']);
        
        // User Routes
        $this->router->get('/users', [$this, 'handleGetUsers']);
        $this->router->post('/users', [$this, 'handleCreateUser']);
        $this->router->get('/users/{id}', [$this, 'handleGetUser']);
        $this->router->patch('/users/{id}', [$this, 'handleUpdateUser']);
        $this->router->delete('/users/{id}', [$this, 'handleDeleteUser']);
        
        // Guest Pass Routes
        $this->router->get('/passes', [$this, 'handleGetPasses']);
        $this->router->post('/passes', [$this, 'handleCreatePass']);
        $this->router->post('/guest/pass/request-open', [$this, 'handleGuestOpen']);
        
        // Device Routes
        $this->router->post('/device/activate', [$this, 'handleActivateDevice']);
        $this->router->get('/device/command/next', [$this, 'handleGetNextCommand']);
        $this->router->post('/device/commands/{id}/ack', [$this, 'handleCommandAck']);
        $this->router->post('/device/heartbeat', [$this, 'handleHeartbeat']);
        
        // Access Logs
        $this->router->get('/access-events', [$this, 'handleGetAccessEvents']);
    }
    
    public function run() {
        $this->router->dispatch();
    }
    
    // Auth Handlers
    public function handleLogin($request, $response) {
        $email = $request->body['email'] ?? null;
        $password = $request->body['password'] ?? null;
        
        if (!$email || !$password) {
            http_response_code(400);
            $response->json(['success' => false, 'error' => 'Email and password required']);
            return;
        }
        
        try {
            $result = \SmartDoor\Auth\Authenticator::login($email, $password);
            $response->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            http_response_code(401);
            $response->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public function handleRefresh($request, $response) {
        $refreshToken = $request->body['refresh_token'] ?? null;
        
        if (!$refreshToken) {
            http_response_code(400);
            $response->json(['success' => false, 'error' => 'Refresh token required']);
            return;
        }
        
        try {
            TokenManager::init();
            $tokens = TokenManager::refresh($refreshToken);
            $response->json(['success' => true, 'data' => $tokens]);
        } catch (\Exception $e) {
            http_response_code(401);
            $response->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public function handleLogout($request, $response) {
        // Token is invalidated by client
        $response->json(['success' => true, 'message' => 'Logged out successfully']);
    }
    
    public function handleGetMe($request, $response) {
        try {
            $user = \SmartDoor\Auth\Authenticator::getCurrentUser($request);
            $response->json(['success' => true, 'data' => $user]);
        } catch (\Exception $e) {
            http_response_code(401);
            $response->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    // Door Handlers
    public function handleGetDoor($request, $response) {
        $stmt = $this->db->query('SELECT * FROM door_device LIMIT 1');
        $door = $stmt->fetch();
        
        if (!$door) {
            http_response_code(404);
            $response->json(['success' => false, 'error' => 'Door not found']);
            return;
        }
        
        $response->json(['success' => true, 'data' => $door]);
    }
    
    public function handleGetDoorStatus($request, $response) {
        $stmt = $this->db->query(
            'SELECT status, last_seen_at FROM door_device LIMIT 1'
        );
        $status = $stmt->fetch();
        
        if (!$status) {
            http_response_code(404);
            $response->json(['success' => false, 'error' => 'Door not found']);
            return;
        }
        
        // Determine if online (last seen within last 2 minutes)
        $status['online'] = (time() - strtotime($status['last_seen_at'])) < 120;
        
        $response->json(['success' => true, 'data' => $status]);
    }
    
    public function handleOpenDoor($request, $response) {
        try {
            $user = \SmartDoor\Auth\Authenticator::getCurrentUser($request);
            
            // TODO: Check permissions
            // TODO: Check access rules
            // TODO: Create command
            // TODO: Queue command
            
            $response->json([
                'success' => true,
                'message' => 'Door open command queued',
                'command_id' => bin2hex(random_bytes(16))
            ]);
        } catch (\Exception $e) {
            http_response_code(401);
            $response->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public function handleUpdateDoorSettings($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'Settings updated']);
    }
    
    // User Handlers
    public function handleGetUsers($request, $response) {
        $page = $request->query['page'] ?? 1;
        $result = \SmartDoor\Models\User::paginate($page, 50);
        $response->json(['success' => true, 'data' => $result]);
    }
    
    public function handleCreateUser($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'User created']);
    }
    
    public function handleGetUser($request, $response, $params) {
        $user = \SmartDoor\Models\User::find($params['id']);
        
        if (!$user) {
            http_response_code(404);
            $response->json(['success' => false, 'error' => 'User not found']);
            return;
        }
        
        $response->json(['success' => true, 'data' => $user]);
    }
    
    public function handleUpdateUser($request, $response, $params) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'User updated']);
    }
    
    public function handleDeleteUser($request, $response, $params) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'User deleted']);
    }
    
    // Guest Pass Handlers
    public function handleGetPasses($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'data' => []]);
    }
    
    public function handleCreatePass($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'Pass created']);
    }
    
    public function handleGuestOpen($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'Door open command sent']);
    }
    
    // Device Handlers
    public function handleActivateDevice($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'Device activated']);
    }
    
    public function handleGetNextCommand($request, $response) {
        // TODO: Implement device authentication
        $response->json(['success' => true, 'data' => null]);
    }
    
    public function handleCommandAck($request, $response, $params) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'ACK received']);
    }
    
    public function handleHeartbeat($request, $response) {
        // TODO: Implement
        $response->json(['success' => true, 'message' => 'Heartbeat received']);
    }
    
    // Log Handlers
    public function handleGetAccessEvents($request, $response) {
        $page = $request->query['page'] ?? 1;
        $db = Database::getInstance();
        
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->query(
            'SELECT * FROM access_events ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        
        $response->json(['success' => true, 'data' => $stmt->fetchAll()]);
    }
}
