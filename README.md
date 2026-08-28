# SMART DOOR PRO - Complete Control System
## Professional Single Door Access Control with Multi-User Management

**Version:** 1.0.0  
**Developer:** A.K  
**Phone:** +201117614245  
**Logo:** A.K (Bottom Right)  
**License:** MIT

---

## 🎯 Project Overview

**SMART DOOR PRO** is a professional, enterprise-grade access control system designed to manage **single door access** for up to **90 concurrent users**. The system operates in **3 independent modes**:

1. **Local Mode** - Offline operation using WiFi network
2. **Server Mode** - Cloud-based REST API control
3. **Telegram Bot Mode** - Control via Telegram Bot integration

---

## ✨ Key Features

### 🔐 Access Control
- ✅ Support for **90+ users** with individual permissions
- ✅ Role-based access control (Owner Admin, Admin, User, Guest)
- ✅ Time-based access schedules
- ✅ Daily/Weekly usage limits
- ✅ Biometric support (Fingerprint)
- ✅ Voice command recognition (Arabic + English)

### 🎟️ Guest Pass System
- ✅ QR Code generation with multiple usage options
- ✅ One-time, limited-use, or unlimited passes
- ✅ Time-window based access (today, specific hours, date range)
- ✅ Pass revocation and regeneration
- ✅ Offline emergency QR codes stored locally

### 🌐 3 Operating Modes
- ✅ **Local Mode**: Offline-first architecture with local WiFi network
- ✅ **Server Mode**: Central cloud control with real-time updates
- ✅ **Telegram Bot Mode**: Control door via Telegram bot commands

### 🛡️ Security
- ✅ HMAC-SHA256 device authentication
- ✅ JWT token-based user auth with refresh rotation
- ✅ End-to-end encrypted communications
- ✅ Replay attack prevention (Nonce + Sequence + Timestamp)
- ✅ SQL injection & XSS protection
- ✅ Rate limiting & Brute force protection
- ✅ Atomic QR usage counting (prevents race conditions)

### ⚙️ Hardware Control
- ✅ ESP8266 D1 Mini - single relay control
- ✅ Finite State Machine architecture (no blocking delay)
- ✅ Non-blocking millis() based timing
- ✅ Boot safety (D1 LOW on startup)
- ✅ Active High / Active Low configurable relay mode
- ✅ Automatic relay return to inactive after unlock duration

### 📊 Admin Dashboard
- ✅ Real-time device status monitoring
- ✅ User management and permission control
- ✅ Guest pass creation and management
- ✅ Access logs with filtering & pagination
- ✅ Network WiFi selection and management
- ✅ Operating mode selection (Local/Server/Telegram)
- ✅ Device diagnostics and health monitoring

### 📱 Mobile & Web
- ✅ Android App (Kotlin) - Native experience
- ✅ Guest PWA - Accessible via QR code
- ✅ Web Admin Dashboard - Full control panel
- ✅ Arabic (RTL) + English support

---

## 📁 Project Structure

```
smart-door-pro-complete/
├── docs/                          # Complete documentation
│   ├── PRODUCT_REQUIREMENTS.md
│   ├── ARCHITECTURE.md
│   ├── HARDWARE.md
│   ├── DATABASE.md
│   ├── SECURITY.md
│   ├── API.md (OpenAPI 3.0)
│   ├── THREE_MODES_GUIDE.md
│   ├── USER_MANAGEMENT_GUIDE.md
│   ├── INSTALLATION.md
│   ├── TESTING.md
│   └── ALGORITHMS.md
│
├── firmware/                      # ESP8266 Firmware
│   ├── platformio.ini
│   ├── src/
│   │   ├── main.cpp
│   │   ├── RelayController.cpp/h
│   │   ├── StateManager.cpp/h
│   │   ├── LocalMode.cpp/h
│   │   ├── ServerMode.cpp/h
│   │   ├── TelegramMode.cpp/h
│   │   ├── DeviceAuth.cpp/h
│   │   ├── CommandProcessor.cpp/h
│   │   ├── ConfigStorage.cpp/h
│   │   ├── WiFiManager.cpp/h
│   │   └── OTA.cpp/h
│
├── backend/                       # PHP REST API
│   ├── .env.example
│   ├── composer.json
│   ├── public/
│   │   ├── index.php
│   │   └── .htaccess
│   ├── src/
│   │   ├── Config.php
│   │   ├── Router.php
│   │   ├── Database.php
│   │   ├── Auth/
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Middleware/
│   │   └── Utils/
│   └── migrations/
│
├── web-admin/                     # React Admin Dashboard
│   ├── package.json
│   ├── public/
│   └── src/
│       ├── components/
│       ├── pages/
│       ├── services/
│       └── styles/
│
├── guest-pwa/                     # Guest QR PWA
│   ├── public/
│   │   ├── index.html
│   │   ├── manifest.json
│   │   └── service-worker.js
│   └── src/
│
├── android/                       # Kotlin Android App
│   ├── app/
│   ├── build.gradle.kts
│   └── settings.gradle.kts
│
└── tests/                         # Test suites
    ├── firmware/
    ├── backend/
    ├── android/
    └── integration/
```

---

## 🚀 Quick Start

### Prerequisites
- PHP 7.4+ with MySQL/MariaDB
- Node.js 16+ for admin dashboard
- Arduino IDE + PlatformIO for firmware
- Android Studio for mobile app
- Python 3.8+ for testing

### Installation
1. Clone the repository
2. Follow `INSTALLATION.md`
3. Configure `.env` files
4. Run database migrations
5. Deploy firmware to ESP8266

---

## 👤 Developer Credits

**Developer:** A.K  
**Contact:** +201117614245  
**Version:** 1.0.0

---

## 📄 License

MIT License - See LICENSE file for details

