# Smart Door Pro - ESP8266 Firmware Documentation

## Overview

This is a **production-grade, security-hardened firmware** for ESP8266 (Wemos D1 Mini) controlling an electromagnetic door lock.

### Critical Safety Requirements

✅ **D2 (GPIO4) is ALWAYS SAFE by default**
- Boot: D2 = OFF
- WiFi connecting: D2 = OFF
- WiFi reconnecting: D2 = OFF
- Server connection: D2 = OFF
- Command processing: D2 = OFF only after signature verification
- Error state: D2 = OFF immediately
- AP mode: D2 = OFF
- Reset: D2 = OFF

### State Machine

```
BOOT → SAFE_OFF → OPEN_ACTIVE → RETURN_TO_SAFE → SAFE_OFF → ...
                      ↑
                   Valid signed
                   command from
                   server/local
```

**States:**
- `STATE_SAFE_OFF (0)`: Door locked, D2 = OFF (relay inactive)
- `STATE_OPEN_ACTIVE (1)`: Door unlocked, D2 = ON for configured duration
- `STATE_RETURN_TO_SAFE (2)`: Transitioning back to locked
- `STATE_ERROR (3)`: Error condition, D2 = OFF immediately
- `STATE_BOOT (4)`: Initialization phase

### Hardware Pinout (Wemos D1 Mini)

```
D2 (GPIO4)  → Relay control (OUTPUT)
D3 (GPIO0)  → Flash button (INPUT_PULLUP) - For reset
D4 (GPIO2)  → Status LED (OUTPUT)

VCC → 5V
GND → GND
```

### Relay Polarity

**Active HIGH mode:**
- Rest: D2 = LOW (0V)
- Unlock: D2 = HIGH (3.3V) for configured duration
- Return: D2 = LOW (automatic)

**Active LOW mode:**
- Rest: D2 = HIGH (3.3V)
- Unlock: D2 = LOW (0V) for configured duration
- Return: D2 = HIGH (automatic)

### Configuration

Stored in EEPROM (512 bytes):

```cpp
struct Config {
    char deviceId[32];          // Unique device identifier
    char deviceSecret[65];      // HMAC key (64 chars + null)
    char wifiSSID[32];          // WiFi network name
    char wifiPassword[64];      // WiFi password
    char serverUrl[128];        // API server URL
    uint16_t relayDuration;     // Unlock duration (500-30000 ms)
    uint8_t relayPolarity;      // 0=Active LOW, 1=Active HIGH
    uint32_t auth_epoch;        // Authentication epoch for revocation
    uint16_t user_revision[30]; // Per-user revision tracking
};
```

## Flash Button Operations

### Recovery Mode (2-5 seconds)

Pressing the flash button (D3) for 2-5 seconds:
1. Starts a temporary WiFi Access Point
2. SSID: `SmartDoor-XXXXXXXX` (device ID in hex)
3. Password: `12345678`
4. Default IP: `192.168.4.1`
5. Does NOT clear saved configuration

**Use case:** Connect locally to reconfigure WiFi or server settings if network is down.

### Factory Reset (8+ seconds)

Pressing the flash button for 8+ seconds:
1. Clears EEPROM and LittleFS
2. LED blinks rapidly (10x) as confirmation
3. Automatic restart
4. Returns to default configuration
5. New device ID generated from chip ID

**Use case:** Complete reset before deploying to new location.

## Command Format

### From Server/Local

```json
{
    "commandId": "CMD_A1B2C3D4",
    "duration": 3000,
    "issuedAt": 1693291200,
    "expiresAt": 1693291210,
    "userId": 5,
    "nonce": 1234567890,
    "signature": "hmac_sha256_hex_string"
}
```

**Validation:**
1. Duration between 500-30000 ms
2. Command not expired (current time < expiresAt)
3. HMAC-SHA256 signature valid
4. User has permission and not revoked
5. No duplicate command processing

### ACK to Server

```json
{
    "commandId": "CMD_A1B2C3D4",
    "status": "EXECUTED",
    "executedAt": 1693291200,
    "actualDuration": 3001,
    "freeHeap": 45024,
    "uptime": 86400,
    "errorCode": null
}
```

**Statuses:**
- `RECEIVED`: Command received
- `VALIDATED`: Signature verified
- `EXECUTED`: Relay pulse completed
- `REJECTED`: Command invalid
- `FAILED`: Execution error
- `EXPIRED`: Command timeout

## Performance Specifications

- **Boot time:** ~2 seconds to SAFE_OFF
- **WiFi connection:** 3-5 seconds typical
- **Command processing:** <100ms
- **Relay switching:** <10ms
- **Heartbeat interval:** 30 seconds
- **Command poll interval:** 2 seconds
- **Watchdog timeout:** 8 seconds

## Security Features

✅ **Boot Safety:** D2 disabled before WiFi initialization
✅ **State Machine:** Only OPEN_ACTIVE state can turn D2 on
✅ **Signature Verification:** HMAC-SHA256 on all commands
✅ **Nonce Protection:** Replay attack prevention
✅ **User Revocation:** Per-user revision tracking
✅ **Duration Enforcement:** Min/max limits with overflow check
✅ **Watchdog:** Hardware watchdog resets on hang
✅ **Error Recovery:** Immediate safe state on errors
✅ **Non-blocking execution:** No delay() in main loop
✅ **Secrets never logged:** No keys in Serial output

## Memory Usage

- **Flash:** ~350KB (free ~150KB for OTA)
- **IRAM:** ~35KB
- **RAM:** ~50KB available
- **EEPROM:** 512 bytes (Config only)
- **LittleFS:** ~2MB (user revisions, cache)

## Connectivity Modes

### 1. Local Mode (WiFi AP)

ESP creates its own network:
- SSID: Configured or default `SmartDoor`
- Password: Configurable (default `1234567890`)
- IP: `192.168.4.1`
- Commands via HTTP on port 80
- No internet required

### 2. Server Mode (Cloud)

Connects to existing WiFi + server:
- Joins configured WiFi network
- Polls server every 2 seconds for commands
- Sends heartbeat every 30 seconds
- Full feature set (QR, multi-user, etc.)
- Internet required

### 3. Telegram Mode

Server forwards Telegram Bot commands:
- Same WiFi/server infrastructure as Mode 2
- Bot token stored on server only
- Device receives commands via polling
- Additional text-based interface

## Offline Grant v3

For local operation without server:

```
Payload: [User ID: 1 byte][Auth Epoch: 4 bytes][User Revision: 2 bytes][Expiry: 4 bytes][Signature: 32 bytes]
Encoding: Base64 URL-safe
Protection: HMAC-SHA256 with device secret
Size: ~60 characters
```

**Generation:** Server creates, signed with device secret  
**Validation:** ESP verifies signature locally  
**Revocation:** User revision mismatch = rejection  
**Expiry:** Configurable (default 24 hours)

## Troubleshooting

### D2 won't turn off

1. Check polarity setting matches relay module
2. Verify RELAY_PIN is D2 (GPIO4)
3. Look for GPIO conflicts with WiFi (D1, D8 are reserved)
4. Test with simple digitalWrite sketch first

### WiFi drops frequently

1. Check WiFi signal strength (RSSI > -70 dBm)
2. Verify antenna position
3. Try different WiFi channels
4. Update ESP8266 board package to latest

### Commands not received

1. Check server polling interval (2 seconds)
2. Verify device secret on server matches
3. Check command expiry time (10 seconds by default)
4. Look for HMAC signature mismatches in logs

### Relay stays on longer than configured

1. Check watchdog isn't feeding (should be every 100ms)
2. Verify relayDuration is in milliseconds
3. Look for state machine hang (check Serial output)
4. May indicate relay module hardware issue

## Building & Uploading

### Requirements

- Arduino IDE 1.8.19+ or PlatformIO
- ESP8266 board package 3.1.0+
- Wemos D1 Mini selected as board
- USB CH340 drivers installed

### Compile

```bash
# Arduino IDE
Sketch → Verify/Compile

# PlatformIO
platformio run -e d1_mini
```

### Upload

```bash
# Arduino IDE
Sketch → Upload
Select Tools → Board → Wemos D1 Mini
Select Tools → Port → COMx

# PlatformIO
platformio run -e d1_mini -t upload
```

### Generate Binary

```bash
# Arduino IDE (after compile)
Sketch → Export Compiled Binary

# PlatformIO
platformio run -e d1_mini --verbose
# Find .elf, then use esptool
esptool.py --chip esp8266 elf2image SmartDoorESP8266.elf
```

## Default Credentials

**AP Mode (Local):**
```
SSID: SmartDoor
Password: 1234567890
IP: 192.168.4.1
```

**First Setup:**
```
Device ID: DEVICE_XXXXXXXX (chip ID)
Device Secret: secret_key_here (change immediately)
Relay Duration: 3000 ms
Relay Polarity: Active HIGH
```

**Important:** Change device secret before production deployment!

## Version History

- **v1.0.0** (2024): Initial stable release
  - State machine implementation
  - HMAC-SHA256 verification
  - Offline grant support
  - User revision tracking
  - Recovery and factory reset

## License & Support

Designed by AHMED KHALED
Phone: 01117614245

---

**Status:** Production Ready ✅
