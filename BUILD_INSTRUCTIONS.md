# Smart Door Pro - Quick Start Guide

## 🚀 Installation Summary

### 1. Web Server (5 minutes)
```bash
unzip hosting_files.zip
cd hosting
php install.php
```

### 2. ESP8266 (10 minutes)
```
- Arduino IDE > Boards Manager > ESP8266
- Upload SmartDoorESP8266.ino
- Serial Monitor @ 115200 baud
- Verify: "Boot complete - Entering SAFE_OFF state"
```

### 3. Android App (2 minutes)
```
- adb install smart-door.apk
- Login: admin@smartdoor.com / admin
- Grant permissions (WiFi, Camera, Mic)
- Configure server URL in Settings
```

## 📱 Key Shortcuts

**Admin Panel:**
- 6 separate sections (Account, Networks, Connection, Users, Devices, Logs)
- Each section has independent save button
- No cross-section interference

**Door Control:**
- Manual unlock: 1 tap [🔑 Open]
- Voice: [🔊 Start listening] → "افتح الباب"
- QR: Scan from camera or gallery
- Telegram: /open command

## 🔐 Security Defaults

- D2 (GPIO4) = OFF at boot (safe)
- HMAC-SHA256 on all commands
- Nonce protection (no replays)
- Per-user revisions (instant revocation)
- No passwords in logs

## ✅ Quick Verification

```bash
# Server health
curl https://yourdomain.com/api/v1/health

# Device online
Status shows 🟢 Online in app

# Door opens
Manual: 3 seconds pulse
Voice: Same duration
QR: Controlled per-pass
```

## 📖 Full Documentation

See `README_AR.md` for complete Arabic instructions (11 sections)

---

**Need help?**
Contact: 01117614245
