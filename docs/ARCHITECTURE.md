# SMART DOOR PRO - System Architecture

**Version:** 1.0.0

---

## High-Level System Design

```
┌──────────────────────────────────────────────────────────────────┐
│                    SMART DOOR PRO ECOSYSTEM                      │
└──────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                      CLOUD INFRASTRUCTURE                        │
│  ┌─────────────────┐  ┌────────────────────────┐               │
│  │  Web Server     │  │   Backend API (PHP)    │               │
│  │  (Apache/Nginx) │  │   REST Endpoints       │               │
│  └────────┬────────┘  └────────┬───────────────┘               │
│           │                    │                                │
│           └────────┬───────────┘                                │
│                    │                                             │
│           ┌────────▼─────────┐                                  │
│           │   MySQL/MariaDB  │                                  │
│           │                  │                                  │
│           │ Users, Doors     │                                  │
│           │ Commands, QR,    │                                  │
│           │ Access Logs      │                                  │
│           └──────────────────┘                                  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                      CLIENT APPLICATIONS                        │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         │
│  │ Web Admin    │  │ Guest PWA    │  │ Android App  │         │
│  │ Dashboard    │  │ (QR Scanner) │  │ (Kotlin)     │         │
│  │ (React)      │  │              │  │              │         │
│  └──────────────┘  └──────────────┘  └──────────────┘         │
│         │                │                    │                 │
│         └────────────────┼────────────────────┘                 │
│                          │ HTTPS                                │
└──────────────────────���───┼─────────────────────────────────────┘
                           │
               ┌───────────▼────────────┐
               │   BACKEND API          │
               │   /api/v1/*            │
               └───────────┬────────────┘
                           │
            ┌──────────────┼──────────────┐
            │              │              │
      ┌─────▼────────┐  ┌──▼──────────┐  ┌──▼──────────────┐
      │ Server Mode  │  │ Local Mode  │  │ Telegram Mode  │
      │ (Internet)   │  │ (WiFi AP)   │  │ (Webhook)      │
      └─────┬────────┘  └──┬──────────┘  └──┬──────────────┘
            │              │                 │
            └──────────────┼─────────────────┘
                           │ HTTPS (Long Poll)
                     ┌─────▼───────────┐
                     │  ESP8266 D1     │
                     │  Mini           │
                     └─────┬───────────┘
                           │
              ┌────────────▼────────────┐
              │  Relay Module           │
              │  GPIO5/D1               │
              └────────────┬────────────┘
                           │
              ┌────────────▼────────────┐
              │  Electric Strike /      │
              │  Maglock (12V DC)       │
              └─────────────────────────┘
```

---

## Component Architecture

### 1. Backend API (PHP)

**Structure:**
```
backend/
├── public/
│   └── index.php          # Entry point
├── src/
│   ├── Config.php
│   ├── Router.php         # Route dispatcher
│   ├── Database.php       # DB connection
│   ├── Auth/
│   │   ├── Authenticator.php
│   │   ├── TokenManager.php
│   │   └── PermissionChecker.php
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── DoorController.php
│   │   ├── UserController.php
│   │   ├── PassController.php
│   │   ├── DeviceController.php
│   │   ├── AdminController.php
│   │   └── LogController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Door.php
│   │   ├── Device.php
│   │   ├── Command.php
│   │   ├── GuestPass.php
│   │   ├── AccessEvent.php
│   │   └── AuditLog.php
│   ├── Services/
│   │   ├── CommandService.php
│   │   ├── QrService.php
│   │   ├── AccessControlService.php
│   │   ├── DeviceService.php
│   │   ├── NotificationService.php
│   │   └── UserManagementService.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── RateLimitMiddleware.php
│   │   ├── CorsMiddleware.php
│   │   └── PermissionMiddleware.php
│   ├── Database/
│   │   ├── Migrations/
│   │   └── Seeders/
│   └── Utils/
│       ├── Logger.php
│       ├── Validator.php
│       ├── Crypto.php
│       └── ResponseBuilder.php
├── .env.example
├── composer.json
└── README.md
```

**Key Classes:**

```php
// Router.php - URL Routing
class Router {
    private $routes = [];
    
    public function get($path, $callback) { ... }
    public function post($path, $callback) { ... }
    public function dispatch($method, $path) { ... }
}

// Database.php - Connection Pool
class Database {
    private static $pdo;
    public static function getInstance() { ... }
    public static function query($sql, $params = []) { ... }
    public static function transaction($callback) { ... }
}

// TokenManager.php - JWT Management
class TokenManager {
    public static function create($userId, $role) { ... }
    public static function verify($token) { ... }
    public static function refresh($refreshToken) { ... }
}

// CommandService.php - Command Queue
class CommandService {
    public static function queue($deviceId, $command) { ... }
    public static function getNext($deviceId) { ... }
    public static function acknowledge($commandId, $status) { ... }
}

// QrService.php - QR Management
class QrService {
    public static function generate($config) { ... }
    public static function verify($token) { ... }
    public static function reserve($token) { ... }
    public static function commit($token, $commandId) { ... }
}
```

### 2. ESP8266 Firmware

**State Machine:**
```
BOOT_SAFE
    ↓
LOAD_CONFIG
    ↓
WIFI_CONNECTING
    ├→ SUCCESS → CLOUD_CONNECTING
    └→ TIMEOUT → IDLE_LOCKED (Local Mode)
    ↓
CLOUD_CONNECTING
    ├→ SUCCESS → IDLE_LOCKED
    └→ TIMEOUT → IDLE_LOCKED (Local Mode)
    ↓
IDLE_LOCKED
    ├→ COMMAND_RECEIVED → COMMAND_VALIDATING
    ├→ HEARTBEAT_TIME → SENDING_HEARTBEAT
    └→ ERROR → ERROR_RECOVERY
    ↓
COMMAND_VALIDATING
    ├→ VALID → RELAY_ACTIVE
    └→ INVALID → IDLE_LOCKED
    ↓
RELAY_ACTIVE (GPIO5 = HIGH/LOW for 3000ms)
    ├→ DURATION_COMPLETE → RELAY_RETURNING_INACTIVE
    └→ ERROR → ERROR_RECOVERY
    ↓
RELAY_RETURNING_INACTIVE (GPIO5 returns to safe state)
    ↓
SENDING_ACK
    ↓
IDLE_LOCKED
```

**Non-Blocking Execution:**

```cpp
// Example: 3000ms relay pulse without blocking

class RelayController {
    enum State { INACTIVE, ACTIVE, RETURNING };
    State state = INACTIVE;
    unsigned long pulseStart = 0;
    const unsigned long PULSE_DURATION = 3000;
    
    void update() {
        unsigned long now = millis();
        
        if (state == ACTIVE) {
            if (now - pulseStart >= PULSE_DURATION) {
                digitalWrite(RELAY_PIN, RELAY_INACTIVE_LEVEL);
                state = RETURNING;
            }
        }
    }
    
    void activate() {
        digitalWrite(RELAY_PIN, RELAY_ACTIVE_LEVEL);
        pulseStart = millis();
        state = ACTIVE;
    }
};

// Main loop
void loop() {
    relayController.update();
    networkManager.update();
    commandProcessor.update();
    
    yield();  // Feed watchdog
}
```

### 3. Web Admin Dashboard (React)

**Component Hierarchy:**
```
App.tsx
├── LoginPage
│   ├── EmailInput
│   ├── PasswordInput
│   ├── TwoFactorAuth
│   └── LoginButton
├── Dashboard
│   ├── Sidebar
│   │   ├── NavLink
│   │   └── LogoutButton
│   └── MainContent
│       ├── DoorControl
│       │   ├── DoorStatus
│       │   ├── OpenButton
│       │   ├── SettingsPanel
│       │   └── RelayModeToggle
│       ├── UserManagement
│       │   ├── UserList
│       │   ├── UserForm
│       │   ├── PermissionsEditor
│       │   └── AccessRulesForm
│       ├── GuestPasses
│       │   ├── PassList
│       │   ├── PassGenerator
│       │   └── QRDisplay
│       ├── AccessLogs
│       │   ├── LogTable
│       │   ├── FilterPanel
│       │   └── ExportButton
│       ├── DeviceStatus
│       │   ├── OnlineIndicator
│       │   ├── FirmwareVersion
│       │   ├── LastHeartbeat
│       │   └── DiagnosticsPanel
│       ├── WiFiManager
│       │   ├── NetworkScanner
│       │   ├── NetworkList
│       │   ├── PasswordInput
│       │   └── ConnectButton
│       └── ModeSelector
│           ├── LocalModeConfig
│           ├── ServerModeConfig
│           └── TelegramModeConfig
└── ProfilePage
    └── SettingsForm
```

### 4. Guest QR PWA

**Technology Stack:**
- HTML5 (static template with server-side rendering)
- JavaScript (QR scanning via jsQR library)
- Service Worker (offline support)
- CSS (responsive, mobile-first)

**Pages:**
- `/` - QR Scanner
- `/guest/pass/:token` - Pass Details & Open Button
- `/guest/success` - Success Message
- `/guest/error` - Error Message
- `/guest/offline` - Offline Mode

### 5. Android Application (Kotlin)

**Architecture: MVVM + Clean Architecture**

```
app/
├── data/
│   ├── api/
│   │   ├── ApiClient.kt
│   │   ├── ApiService.kt
│   │   └── RetrofitClient.kt
│   ├── db/
│   │   ├── AppDatabase.kt
│   │   ├── UserDao.kt
│   │   ├── CommandDao.kt
│   │   └── LocalQrDao.kt
│   ├── prefs/
│   │   ├── PreferencesManager.kt
│   │   └── EncryptedPreferences.kt
│   └── repository/
│       ├── UserRepository.kt
│       ├── DoorRepository.kt
│       └── QrRepository.kt
├── domain/
│   ├── models/
│   │   ├── User.kt
│   │   ├── Door.kt
│   │   ├── Command.kt
│   │   └── GuestPass.kt
│   └── usecases/
│       ├── LoginUseCase.kt
│       ├── OpenDoorUseCase.kt
│       └── ScanQrUseCase.kt
├── ui/
│   ├── screens/
│   │   ├── LoginScreen.kt
│   │   ├── HomeScreen.kt
│   │   ├── OpenDoorScreen.kt
│   │   ├── QrScannerScreen.kt
│   │   ├── VoiceControlScreen.kt
│   │   └── ProfileScreen.kt
│   ├── components/
│   │   ├── DoorButton.kt
│   │   ├── StatusIndicator.kt
│   │   └── QrScanner.kt
│   └── viewmodels/
│       ├── LoginViewModel.kt
│       ├── HomeViewModel.kt
│       └── QrScannerViewModel.kt
└── utils/
    ├── BiometricHelper.kt
    ├── VoiceHelper.kt
    ├── QrHelper.kt
    ├── Constants.kt
    └── Extensions.kt
```

---

## Data Flow Diagrams

### Server Mode Flow

```
1. User clicks "Open Door"
   |
   v
2. Android sends: POST /api/v1/door/open
   ├ Headers: Authorization: Bearer {jwt_token}
   └ Body: {}
   |
   v
3. Backend validates
   ├ Check JWT signature
   ├ Check user exists & enabled
   ├ Check access rules (time, daily limit, etc.)
   └ Check device online
   |
   v
4. Create Command
   ├ command_id = UUID
   ├ action = UNLOCK
   ├ duration_ms = 3000
   ├ issued_at = now
   ├ expires_at = now + 10s
   └ signature = HMAC-SHA256(body, device_secret)
   |
   v
5. Store in commands queue
   └ status = pending
   |
   v
6. ESP8266 Long Poll
   ├ Every 2 seconds: GET /api/v1/device/command/next
   ├ Headers: Device-ID, Device-Signature, Timestamp
   └ Backend responds with command
   |
   v
7. ESP validates command
   ├ Check signature
   ├ Check timestamp (±30s)
   ├ Check sequence counter
   ├ Check not previously executed
   └ Check not expired
   |
   v
8. Execute relay
   ├ GPIO5 = HIGH (3000ms)
   ├ Non-blocking using millis()
   ├ Monitor free heap
   └ Monitor RSSI
   |
   v
9. After 3000ms
   ├ GPIO5 = LOW
   ├ Prepare ACK response
   └ status = EXECUTED
   |
   v
10. Send ACK
    ├ POST /api/v1/device/commands/{id}/ack
    ├ Body:
    │ {
    │   status: EXECUTED,
    │   executed_at: timestamp,
    │   actual_duration_ms: 3001,
    │   free_heap: 45000,
    │   rssi: -65
    │ }
    └ Signature: HMAC-SHA256(...)
    |
    v
11. Backend processes ACK
    ├ Update command: status = executed
    ├ Create access_event
    ├ Send notification to user
    └ Update device: last_seen_at = now
    |
    v
12. User app receives notification
    └ Display: "Door opened successfully"
```

### Local Mode Flow

```
1. User connects phone to ESP8266 WiFi
   └ SSID: SmartDoor-XXXXX, IP: 192.168.4.1
   |
   v
2. User app detects local mode
   └ Shows "Local Mode" indicator
   |
   v
3. User clicks "Open Door"
   |
   v
4. Android sends: POST http://192.168.4.1:8080/api/v1/door/open
   ├ Headers: Authorization: Bearer {local_token}
   └ Body: {}
   |
   v
5. ESP processes locally (no cloud)
   ├ Check local user cache
   ├ Check access rules
   └ Validate request
   |
   v
6. Execute relay immediately
   ├ GPIO5 = HIGH (3000ms)
   └ No waiting for cloud
   |
   v
7. After 3000ms
   ├ GPIO5 = LOW
   └ Send local ACK
   |
   v
8. User app receives response
   └ Display: "Door opened"
   |
   v
9. Log stored in ESP flash
   └ Synced to cloud when online
```

### QR Reservation Flow

```
20 phones try to use QR with 1 use remaining

Phone-1:     Phone-2:     Phone-3...20:
  |            |            |
  v            v            v
 POST request  WAIT         WAIT
  |            |            |
  v            v            v
BEGIN TRANS   (blocked)     (blocked)
  |            |            |
  v            v            v
LOCK ROW      (waiting)     (waiting)
  |            |            |
  v            v            v
RESERVE USE   (lock)        (waiting)
used_count++   |            |
  |            v            v
  v          REJECTED      REJECTED
CREATE CMD    (0 uses left) (0 uses left)
  |            |            |
  v            v            v
COMMIT TRANS  ACK: FAIL     ACK: FAIL
  |
Result: Only Phone-1 succeeds ✓
```

---

## Communication Protocols

### Server Mode
- **Protocol:** HTTPS + JSON
- **Port:** 443
- **Polling:** Long polling, 2-5 second interval
- **Signature:** HMAC-SHA256

### Local Mode
- **Protocol:** HTTP + JSON
- **Port:** 8080 (ESP8266)
- **IP:** 192.168.4.1 (ESP AP) or router IP
- **Signature:** HMAC-SHA256

### Telegram Mode
- **Protocol:** Telegram Bot API (HTTPS)
- **Webhook:** Backend → Telegram API
- **Commands:** /open, /status, /users, etc.

---

## Database Schema Overview

```sql
-- User Management
USERS (id, email, password_hash, role, enabled, created_at)
USER_ACCESS_RULES (id, user_id, valid_from, valid_until, max_daily_uses, ...)
USER_SESSIONS (id, user_id, token, expires_at, created_at)

-- Device & Commands
DOOR_DEVICE (id, device_id, device_secret, status, firmware_version, ...)
DOOR_COMMANDS (id, device_id, command_id, action, status, expires_at, ...)
DOOR_HEARTBEATS (id, device_id, timestamp, rssi, free_heap, ...)

-- Access Control
GUEST_PASSES (id, token_hash, status, used_count, max_uses, created_by, ...)
ACCESS_EVENTS (id, user_id, door_id, action, status, timestamp, ...)
AUDIT_LOGS (id, actor_id, action, resource, timestamp, ...)
```

---

## Security Layers

```
┌─────────────────────────────────────────────┐
│         USER APPLICATION LAYER              │
│  ├─ Login Authentication                    │
│  ├─ Permission Checking                     │
│  └─ Session Management                      │
└────────────┬────────────────────────────────┘
             │
┌────────────▼────────────────────────────────┐
│        API SECURITY LAYER                   │
│  ├─ HTTPS/TLS Encryption                    │
│  ├─ Input Validation                        │
│  ├─ Rate Limiting                           │
│  ├─ CORS Protection                         │
│  └─ CSRF Tokens                             │
└────────────┬────────────────────────────────┘
             │
┌────────────▼────────────────────────────────┐
│      DEVICE AUTHENTICATION LAYER            │
│  ├─ HMAC-SHA256 Signing                     │
│  ├─ Device ID Verification                  │
│  ├─ Timestamp Validation                    │
│  ├─ Sequence Counter                        │
│  └─ Nonce Check                             │
└────────────┬────────────────────────────────┘
             │
┌────────────▼────────────────────────────────┐
│      COMMAND EXECUTION LAYER                │
│  ├─ Signature Validation                    │
│  ├─ Expiry Check                            │
│  ├─ Duplicate Prevention                    │
│  └─ State Validation                        │
└────────────┬────────────────────────────────┘
             │
┌────────────▼────────────────────────────────┐
│      HARDWARE CONTROL LAYER                 │
│  ├─ Relay State Machine                     │
│  ├─ Non-Blocking Execution                  │
│  ├─ Boot Safety                             │
│  └─ Watchdog Protection                     │
└────────────────────────────────────────────┘
```

---

**Status:** Architecture Finalized  
**Last Updated:** 2024