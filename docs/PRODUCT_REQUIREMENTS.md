# SMART DOOR PRO - Product Requirements Document

**Version:** 1.0.0  
**Date:** 2024  
**Developer:** A.K (01117614245)  

---

## Executive Summary

**SMART DOOR PRO** is an enterprise-grade access control system for single door management supporting up to **90 concurrent users** with three independent operating modes: Local (Offline), Server (Cloud), and Telegram Bot.

---

## 1. System Requirements

### 1.1 Hardware Requirements
- **Microcontroller:** ESP8266 D1 Mini
- **Relay Control:** Single relay module (3.3V compatible)
- **Lock Type:** Electric Strike / Maglock (12V DC)
- **Door Sensor:** Optional (motion, state, temperature)
- **Power Supply:** 5V for ESP8266, 12V for lock
- **Connectivity:** WiFi 802.11b/g/n

### 1.2 Software Requirements
- **Backend:** PHP 7.4+ with MySQL/MariaDB
- **Frontend Admin:** React/TypeScript
- **Mobile:** Android (Kotlin) / iOS ready
- **Guest Access:** PWA (Progressive Web App)
- **Firmware:** C++ with PlatformIO

### 1.3 Network Requirements
- HTTPS only for server communication
- Local WiFi network for offline mode
- Optional Telegram Bot API integration

---

## 2. Three Operating Modes

### 2.1 Local Mode (Offline)
**Operates without internet connection**

- Device broadcasts WiFi network
- Users connect to ESP8266 WiFi directly
- All operations stored locally
- Cached QR codes work offline
- Cached user credentials (hashed)
- No dependency on cloud

```
User Phone (WiFi Connected to ESP8266)
        ↓
  Local REST API on ESP8266
        ↓
  Local Command Queue
        ↓
  Relay Control (GPIO5)
```

**Features:**
- Emergency unlock codes
- Offline QR scanning
- Local access logs (stored on flash)
- Time-based access (from local RTC if available)

### 2.2 Server Mode (Cloud)
**Central cloud-based control**

- ESP8266 polls for commands via HTTPS
- Commands signed with HMAC-SHA256
- Real-time command delivery
- Centralized access logs
- Multi-user management
- Guest pass generation

```
User Phone (Internet)
        ↓
  Backend REST API (Cloud)
        ↓
  Command Queue (DB)
        ↓
  ESP8266 Long Poll (Every 2-5 seconds)
        ↓
  Relay Control (GPIO5)
```

**Features:**
- Unlimited user management
- Real-time notifications
- Audit trails
- Analytics and reporting

### 2.3 Telegram Bot Mode
**Control via Telegram messenger**

- Admin sends commands via Telegram
- Bot forwards to backend API
- Backend controls ESP8266
- Optional webhook mode

```
Admin (Telegram App)
        ↓
  Telegram Bot API
        ↓
  Backend Handler
        ↓
  Server Mode Control
```

**Features:**
- `/open` - Open door
- `/status` - Device status
- `/users` - List users
- `/logs` - Recent access logs
- `/mode` - Switch operating mode

---

## 3. User Management (90 Users)

### 3.1 User Roles

**OWNER_ADMIN**
- Full system control
- Create/delete admins
- Manage operating modes
- Configure WiFi networks
- System settings
- Device secrets rotation

**ADMIN**
- User management (create, suspend)
- Guest pass generation
- Access logs viewing
- Manual door opening
- Cannot modify Owner or critical settings

**USER**
- Open door (if permitted)
- QR code scanning
- Voice commands
- View personal logs
- Biometric setup

**GUEST (QR-Based)**
- Single QR use
- Limited-use QR
- Time-window access
- No account creation needed

### 3.2 User Permissions Matrix

| Permission | Owner | Admin | User | Guest |
|---|---|---|---|---|
| Open Door | ✅ | ✅ | ✅* | ✅* |
| Manage Users | ✅ | ✅ | ❌ | ❌ |
| Create QR | ✅ | ✅ | ❌ | ❌ |
| View Logs | ✅ | ✅ | ✅* | ❌ |
| Change Settings | ✅ | ❌ | ❌ | ❌ |
| Manage WiFi | ✅ | ❌ | ❌ | ❌ |
| Switch Modes | ✅ | ❌ | ❌ | ❌ |

*Conditional based on permissions

### 3.3 Access Control Rules per User

```php
[
    'enabled' => true,
    'valid_from' => '2024-01-01',
    'valid_until' => '2024-12-31',
    'unlimited_access' => false,
    'max_total_uses' => 30,
    'max_daily_uses' => 5,
    'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    'allowed_start_time' => '08:00',
    'allowed_end_time' => '18:00',
    'allow_manual_open' => true,
    'allow_voice_open' => true,
    'allow_qr_open' => true,
    'require_biometric' => false,
    'cooldown_seconds' => 10,
    'suspended_at' => null,
    'suspension_reason' => null
]
```

---

## 4. Guest QR System

### 4.1 QR Types

**One-Time Use QR**
```
QR → User scans → Backend verifies → Open door → QR disabled
```

**Limited-Use QR (e.g., 5 times)**
```
QR → Scan 1 → Open (4 left) → Scan 2 → Open (3 left) → ... → Scan 5 → Disabled
```

**Time-Window QR**
```
QR → Valid today only → At midnight → Disabled
or
QR → Valid 9:00-17:00 → Outside → Rejected
```

### 4.2 QR Content & Security

**What QR Contains:**
```
qr_token=abc123def456 (random, opaque, 32+ chars)
```

**What QR Does NOT Contain:**
- ❌ Device Secret
- ❌ API Key
- ❌ WiFi Password
- ❌ User credentials
- ❌ GPIO commands
- ❌ Unlock duration

**QR Token Storage:**
```
QR DB Entry:
{
    token_hash: SHA256(qr_token),  // Public in DB
    status: 'active',
    used_count: 2,
    max_uses: 5,
    expires_at: '2024-12-31 23:59:59',
    access_start_time: '09:00',
    access_end_time: '17:00',
    allowed_days: ['Mon', 'Tue', 'Wed'],
    created_by: user_id,
    created_at: '2024-01-01 10:00:00'
}
```

### 4.3 QR Reservation Flow (Race Condition Prevention)

```
Scenario: 20 phones try to use QR with 1 remaining use

1. Phone-1 sends request
2. Backend: START TRANSACTION
3. Check QR status → valid, 1 use left
4. Lock row (SELECT FOR UPDATE)
5. Reserve use (used_count = 2, reserved = 1)
6. Generate command
7. COMMIT
8. Phone-2-19: Row locked, wait...
9. Phone-1: ACK received, update used_count = 3
10. Phone-2: Row now available, check... 0 uses left → REJECT
11. Phone-3-19: Similar, all rejected

Result: Only Phone-1 succeeds ✓
```

---

## 5. Offline Emergency QR Codes

**Feature:** Backup QR codes stored locally on each user's phone

```
Setup Phase (Connected to Server):
1. Admin generates 5 emergency QR codes
2. Each QR: sha256(device_id + user_id + random_salt)
3. Admin stores in secure local storage
4. Also uploaded to cloud as backup

Offline Phase (No Internet):
1. User can still scan local QR backup
2. Local validation using stored hash
3. Counter incremented locally
4. Synced to server when online
```

---

## 6. WiFi Network Management

**Admin can:**
- Scan available WiFi networks
- Select network from dropdown
- Enter password
- Connect ESP8266

**Flow:**
```
Admin Dashboard (Web/App)
        ↓
Scan available networks
        ↓
Display list (SSID, Signal strength, Security)
        ↓
Admin selects + enters password
        ↓
Backend sends config to ESP8266
        ↓
ESP8266 attempts connection
        ↓
Report status (success/fail)
```

**Three scenarios:**

1. **WiFi with Internet:** Connect → Server mode active
2. **WiFi without Internet:** Connect → Local mode active (fallback)
3. **Direct ESP AP:** No WiFi → Pure local mode

---

## 7. Operating Mode Selection

**Admin Dashboard displays:**

```
┌─ Operating Mode ─────────────┐
│ ○ Local Mode (Offline)        │
│   └ Settings for Local        │
│                               │
│ ○ Server Mode (Cloud)         │
│   ├ API URL: ___________       │
│   ├ Device ID: ___________    │
│   └ Device Secret: •••••••    │
│                               │
│ ○ Telegram Bot Mode           │
│   ├ Bot Token: ___________    │
│   └ Chat ID: ___________     │
│                               │
│ [Save] [Test Connection]      │
└──────────────────────────────┘
```

**Mode Switching:**
- Owner can switch modes anytime
- Device reboots to apply new mode
- Previous mode configs saved
- Seamless transition with user notification

---

## 8. Relay Control

### 8.1 Active High Mode
```
Rest State:    D1/GPIO5 = LOW
Unlock:        D1/GPIO5 = HIGH (3000ms)
Return:        D1/GPIO5 = LOW (automatic)
```

### 8.2 Active Low Mode
```
Rest State:    D1/GPIO5 = HIGH
Unlock:        D1/GPIO5 = LOW (3000ms)
Return:        D1/GPIO5 = HIGH (automatic)
```

### 8.3 Boot Safety (Critical)
```
On Startup:
1. IMMEDIATELY (before WiFi) set D1 to SAFE state
   if ActiveHigh → D1 = LOW
   if ActiveLow → D1 = HIGH

2. Never leave relay active
3. Even on crash, watchdog resets to safe state
4. No possibility of stuck-open lock
```

---

## 9. Command Execution Pipeline

### 9.1 Server Mode
```
1. User clicks "Open Door" in app
2. App sends: POST /api/v1/door/open
3. Backend verifies user permissions
4. Backend checks access rules (time, usage, status)
5. Backend creates command: UUID, expires_at +10s
6. Command stored in DB with status='pending'
7. Backend signs command with device_secret
8. ESP8266 polls every 2-5 seconds
9. Backend sends command in response
10. ESP validates signature, timestamp, sequence
11. ESP executes: GPIO5 = HIGH for 3000ms
12. ESP sends ACK: EXECUTED, actual_duration=3000ms
13. Backend updates command: status='executed'
14. Backend creates access_event
15. App notified (real-time): "Door opened"
16. Admin dashboard: Log entry appears
```

### 9.2 Local Mode
```
1. User connects phone to ESP8266 WiFi
2. User opens app, sees Local Mode UI
3. User clicks "Open Door"
4. App sends: http://192.168.4.1:8080/api/v1/door/open
5. ESP processes locally (no backend)
6. GPIO5 = HIGH for 3000ms
7. ESP replies: ACK with execution time
8. App displays "Door opened"
9. Access log stored in ESP flash
```

### 9.3 QR Mode (Server)
```
1. User (guest) scans QR code
2. QR contains: token=abc123def
3. Opens PWA: /guest/pass/abc123def
4. PWA fetches pass details from Backend
5. Backend verifies token, usage count, time window
6. Shows: "This QR allows 2 more uses"
7. Guest clicks "Open Door"
8. Backend: START TRANSACTION
9. Check QR again (prevent race)
10. Reserve usage: used_count++
11. Create command for ESP8266
12. COMMIT TRANSACTION
13. ESP executes
14. Backend confirms: usage incremented, ACK logged
15. Guest app: "Door opened (1 use remaining)"
```

---

## 10. Security Requirements

### 10.1 Authentication
- ✅ Bcrypt password hashing (cost 12)
- ✅ JWT tokens (1 hour expiry)
- ✅ Refresh token rotation
- ✅ Session revocation
- ✅ Optional 2FA (TOTP)
- ✅ Biometric (Android)

### 10.2 Device Authentication
- ✅ HMAC-SHA256 signing
- ✅ Device ID + Device Secret
- ✅ Sequence counter
- ✅ Nonce
- ✅ Timestamp validation (±30 seconds)
- ✅ Replay prevention

### 10.3 API Security
- ✅ HTTPS only
- ✅ Rate limiting (10 req/sec per IP)
- ✅ Account lockout (5 failures → 15 min lockout)
- ✅ CORS allowlist
- ✅ CSRF tokens for forms
- ✅ Input validation
- ✅ SQL injection prevention (PDO prepared statements)
- ✅ XSS prevention (output encoding)
- ✅ Secure cookies (HttpOnly, SameSite=Strict)

### 10.4 Data Protection
- ✅ Passwords: Bcrypt + salt
- ✅ Sensitive fields: AES-256-CBC encryption
- ✅ QR tokens: SHA256 hashing
- ✅ API keys: SHA256 hashing
- ✅ Audit logs: Immutable write-append

---

## 11. Performance Requirements

### 11.1 Response Times
- User login: < 2 seconds
- Door open request: < 500ms (backend)
- Command delivery: < 5 seconds (including ESP poll)
- QR verification: < 1 second
- Admin dashboard load: < 3 seconds

### 11.2 Scalability
- Support 90+ concurrent users
- 1000+ access events per day
- 100+ guest passes active simultaneously
- Pagination for logs (50 per page)
- Database indexes on frequently queried fields

### 11.3 Reliability
- 99.5% uptime target
- Automatic reconnection on network loss
- Command retry with exponential backoff
- Graceful degradation to local mode

---

## 12. Deliverables

- ✅ Complete Firmware (ESP8266)
- ✅ Backend REST API (PHP)
- ✅ Web Admin Dashboard (React)
- ✅ Guest QR PWA
- ✅ Android Application (Kotlin)
- ✅ Complete Documentation
- ✅ OpenAPI 3.0 Specification
- ✅ Database Migrations
- ✅ Security Audit Report
- ✅ Test Suite
- ✅ Installation & Deployment Guide

---

**Status:** Active Development  
**Version:** 1.0.0  
**Last Updated:** 2024