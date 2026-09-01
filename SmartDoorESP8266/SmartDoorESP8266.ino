#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <EEPROM.h>
#include <LittleFS.h>
#include <time.h>
#include <ArduinoJson.h>
#include <crypto.h>

// ============================================
// SMART DOOR PRO - ESP8266 D1 MINI FIRMWARE
// Secure State Machine Implementation
// ============================================

#define RELAY_PIN D2  // GPIO4 - CRITICAL: D2 ONLY, NO D1 OR D4
#define FLASH_BTN D3  // GPIO0 - Flash button for reset
#define BUILTIN_LED D4  // GPIO2 - Status LED

// STATE MACHINE STATES
enum DoorState {
    STATE_SAFE_OFF = 0,
    STATE_OPEN_ACTIVE = 1,
    STATE_RETURN_TO_SAFE = 2,
    STATE_ERROR = 3,
    STATE_BOOT = 4
};

// CONFIGURATION STRUCTURE
struct Config {
    char deviceId[32];
    char deviceSecret[65];
    char wifiSSID[32];
    char wifiPassword[64];
    char serverUrl[128];
    uint16_t relayDuration;  // milliseconds
    uint8_t relayPolarity;   // 0=LOW active, 1=HIGH active
    uint32_t auth_epoch;
    uint16_t user_revision[30];  // Track per-user revisions
};

// GLOBAL VARIABLES
Config config;
DoorState currentState = STATE_BOOT;
DoorState previousState = STATE_BOOT;
unsigned long stateChangeTime = 0;
unsigned long relayActivateTime = 0;
volatile bool flashButtonPressed = false;
volatile unsigned long flashPressTime = 0;

const uint16_t RELAY_MIN_DURATION = 500;    // 0.5s
const uint16_t RELAY_MAX_DURATION = 30000;  // 30s
const uint32_t EEPROM_CONFIG_ADDR = 0;
const uint32_t EEPROM_USER_REV_ADDR = 256;  // User revisions storage
const uint32_t SAFE_BOOT_DELAY = 100;       // 100ms before any GPIO
const uint32_t BOOT_SAFE_TIMEOUT = 5000;    // 5s to reach IDLE
const uint32_t WATCHDOG_TIMEOUT = 8000;     // 8s hardware watchdog

// ============================================
// SETUP & BOOT SAFETY CRITICAL
// ============================================
void setup() {
    Serial.begin(115200);
    delay(100);
    
    Serial.println("\n\n╔════════════════════════════════════════╗");
    Serial.println("║  SMART DOOR PRO - ESP8266 D1 MINI     ║");
    Serial.println("║  Boot Safety Phase Starting...         ║");
    Serial.println("╚════════════════════════════════════════╝\n");
    
    // CRITICAL: Set GPIO modes BEFORE any WiFi init
    pinMode(RELAY_PIN, OUTPUT);
    pinMode(BUILTIN_LED, OUTPUT);
    pinMode(FLASH_BTN, INPUT_PULLUP);
    
    // CRITICAL: Ensure relay is in safe state
    safeRelayOff();
    delay(SAFE_BOOT_DELAY);
    Serial.println("✓ Relay set to SAFE state (OFF)");
    
    // LED indication - ON during boot
    digitalWrite(BUILTIN_LED, LOW);
    
    // Load configuration from EEPROM
    loadConfig();
    Serial.println("✓ Configuration loaded");
    
    // Initialize LittleFS for user revisions and cache
    if (!LittleFS.begin()) {
        Serial.println("✗ LittleFS init failed, formatting...");
        LittleFS.format();
        LittleFS.begin();
    }
    Serial.println("✓ LittleFS initialized");
    
    // Setup WiFi
    initWiFi();
    
    // Setup time from NTP
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    
    // Setup flash button interrupt
    attachInterrupt(digitalPinToInterrupt(FLASH_BTN), handleFlashButton, CHANGE);
    
    // Enable watchdog
    ESP.wdtEnable(WATCHDOG_TIMEOUT);
    
    Serial.println("✓ Boot complete - Entering SAFE_OFF state");
    transitionState(STATE_SAFE_OFF);
    
    // LED off - boot complete
    digitalWrite(BUILTIN_LED, HIGH);
}

void loop() {
    // Feed watchdog FIRST
    ESP.wdtFeed();
    yield();
    
    // Handle flash button
    handleFlashButtonPress();
    
    // Process state machine
    updateStateMachine();
    
    // Update relay control (non-blocking)
    updateRelayControl();
    
    // Poll commands from server
    if (WiFi.isConnected()) {
        pollServerCommands();
    }
    
    // Send heartbeat periodically
    static unsigned long lastHeartbeat = 0;
    if (millis() - lastHeartbeat > 30000) {
        sendHeartbeat();
        lastHeartbeat = millis();
    }
    
    delay(100);
}

// ============================================
// STATE MACHINE CORE
// ============================================
void updateStateMachine() {
    unsigned long now = millis();
    unsigned long elapsed = now - stateChangeTime;
    
    switch (currentState) {
        case STATE_BOOT:
            // Should never reach here
            transitionState(STATE_SAFE_OFF);
            break;
            
        case STATE_SAFE_OFF:
            // Door locked - waiting for command
            // LED blinking slow when idle
            if ((now / 1000) % 4 < 2) {
                digitalWrite(BUILTIN_LED, LOW);
            } else {
                digitalWrite(BUILTIN_LED, HIGH);
            }
            break;
            
        case STATE_OPEN_ACTIVE:
            // Relay is active - check if duration expired
            if (elapsed > config.relayDuration) {
                Serial.printf("[Relay] Duration expired (%lu ms), returning to SAFE\n", elapsed);
                transitionState(STATE_RETURN_TO_SAFE);
            } else {
                // LED solid on during unlock
                digitalWrite(BUILTIN_LED, LOW);
            }
            break;
            
        case STATE_RETURN_TO_SAFE:
            // Relay just turned off - immediate transition
            transitionState(STATE_SAFE_OFF);
            break;
            
        case STATE_ERROR:
            // Error state - ensure relay is off
            safeRelayOff();
            if (elapsed > 3000) {
                // Recovery: return to safe after 3s
                Serial.println("[Error] Recovery timeout - returning to SAFE_OFF");
                transitionState(STATE_SAFE_OFF);
            }
            break;
    }
}

void transitionState(DoorState newState) {
    if (currentState == newState) {
        return;  // Already in this state
    }
    
    previousState = currentState;
    currentState = newState;
    stateChangeTime = millis();
    
    Serial.printf("[State] %d -> %d\n", previousState, newState);
    
    // Execute state entry actions
    switch (newState) {
        case STATE_SAFE_OFF:
            safeRelayOff();
            Serial.println("  → Door LOCKED (safe state)");
            break;
            
        case STATE_OPEN_ACTIVE:
            relayActivateTime = millis();
            activateRelay();
            Serial.printf("  → Door UNLOCKED for %u ms\n", config.relayDuration);
            break;
            
        case STATE_RETURN_TO_SAFE:
            safeRelayOff();
            Serial.println("  → Relay returning to safe state");
            break;
            
        case STATE_ERROR:
            safeRelayOff();
            Serial.println("  → ERROR state entered");
            break;
            
        case STATE_BOOT:
            break;
    }
}

// ============================================
// RELAY CONTROL - CRITICAL SAFETY
// ============================================
void safeRelayOff() {
    // Determine safe level based on polarity
    uint8_t safeLevel = (config.relayPolarity == 1) ? LOW : HIGH;
    digitalWrite(RELAY_PIN, safeLevel);
    
    if (digitalRead(RELAY_PIN) != safeLevel) {
        Serial.println("✗ CRITICAL: Relay did not set to safe state!");
        // Force again
        digitalWrite(RELAY_PIN, safeLevel);
    }
}

void activateRelay() {
    // Check we're in correct state
    if (currentState != STATE_OPEN_ACTIVE) {
        Serial.println("✗ CRITICAL: activateRelay called from non-OPEN state!");
        safeRelayOff();
        return;
    }
    
    uint8_t activeLevel = (config.relayPolarity == 1) ? HIGH : LOW;
    digitalWrite(RELAY_PIN, activeLevel);
    
    if (digitalRead(RELAY_PIN) != activeLevel) {
        Serial.println("✗ CRITICAL: Relay did not activate!");
        // Safety: transition to error
        transitionState(STATE_ERROR);
    }
}

void updateRelayControl() {
    if (currentState == STATE_OPEN_ACTIVE) {
        unsigned long elapsed = millis() - relayActivateTime;
        
        // Enforce absolute maximum duration
        if (elapsed > RELAY_MAX_DURATION) {
            Serial.printf("✗ CRITICAL: Relay exceeded max duration (%lu ms > %u ms)\n",
                         elapsed, RELAY_MAX_DURATION);
            transitionState(STATE_RETURN_TO_SAFE);
        }
    }
}

// ============================================
// COMMAND PROCESSING
// ============================================
struct DoorCommand {
    char commandId[32];
    uint32_t duration;
    uint32_t issuedAt;
    uint32_t expiresAt;
    uint8_t userId;
    uint32_t nonce;
    char signature[64];
};

bool processCommand(const DoorCommand& cmd) {
    Serial.printf("[Command] Processing ID: %s\n", cmd.commandId);
    
    // Check if already processing
    if (currentState == STATE_OPEN_ACTIVE || currentState == STATE_RETURN_TO_SAFE) {
        Serial.println("✗ Command rejected: Door already unlocked");
        return false;
    }
    
    // Validate duration
    if (cmd.duration < RELAY_MIN_DURATION || cmd.duration > RELAY_MAX_DURATION) {
        Serial.printf("✗ Duration invalid: %lu (min: %u, max: %u)\n",
                     cmd.duration, RELAY_MIN_DURATION, RELAY_MAX_DURATION);
        return false;
    }
    
    // Check expiry
    time_t now = time(nullptr);
    if (now > cmd.expiresAt) {
        Serial.println("✗ Command expired");
        return false;
    }
    
    // Verify signature (HMAC-SHA256)
    if (!verifyCommandSignature(cmd)) {
        Serial.println("✗ Command signature invalid");
        return false;
    }
    
    // Verify user permission
    if (!verifyUserPermission(cmd.userId)) {
        Serial.println("✗ User not authorized");
        return false;
    }
    
    // Store duration and execute
    config.relayDuration = cmd.duration;
    
    // Transition to OPEN state
    transitionState(STATE_OPEN_ACTIVE);
    
    Serial.printf("✓ Command accepted: %s\n", cmd.commandId);
    return true;
}

bool verifyCommandSignature(const DoorCommand& cmd) {
    // TODO: Implement HMAC-SHA256 verification
    // For now, basic check
    return strlen(cmd.signature) > 0;
}

bool verifyUserPermission(uint8_t userId) {
    // Load user revision from LittleFS
    if (userId > 29) {
        return false;  // Invalid user ID
    }
    
    // Check if user is revoked (TODO: implement revision check)
    return true;
}

// ============================================
// WIFI & CONNECTIVITY
// ============================================
void initWiFi() {
    Serial.println("[WiFi] Initializing...");
    
    WiFi.mode(WIFI_STA);
    WiFi.begin(config.wifiSSID, config.wifiPassword);
    
    uint32_t startTime = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - startTime < 10000) {
        delay(500);
        Serial.print(".");
    }
    
    if (WiFi.isConnected()) {
        Serial.printf("\n✓ WiFi connected to: %s\n", config.wifiSSID);
        Serial.printf("  IP: %s\n", WiFi.localIP().toString().c_str());
    } else {
        Serial.println("\n✗ WiFi connection failed - starting AP mode");
        startAccessPoint();
    }
}

void startAccessPoint() {
    WiFi.mode(WIFI_AP);
    String apSSID = String("SmartDoor-") + String(ESP.getChipId(), HEX);
    WiFi.softAP(apSSID.c_str(), "12345678");
    
    Serial.printf("✓ Access Point started: %s\n", apSSID.c_str());
    Serial.printf("  IP: %s\n", WiFi.softAPIP().toString().c_str());
}

void pollServerCommands() {
    static unsigned long lastPoll = 0;
    if (millis() - lastPoll < 2000) {
        return;  // Poll every 2 seconds
    }
    lastPoll = millis();
    
    if (!WiFi.isConnected()) {
        return;
    }
    
    // TODO: HTTP GET request to server for pending commands
    // URL: /api/device/poll
    // Response: DoorCommand in JSON
}

void sendHeartbeat() {
    if (!WiFi.isConnected()) {
        return;
    }
    
    // TODO: HTTP POST heartbeat
    // Payload: device state, uptime, free heap, etc.
}

// ============================================
// FLASH BUTTON - RESET & RECOVERY
// ============================================
void ICACHE_RAM_ATTR handleFlashButton() {
    if (digitalRead(FLASH_BTN) == LOW) {
        flashPressTime = millis();
        flashButtonPressed = true;
    } else {
        flashButtonPressed = false;
    }
}

void handleFlashButtonPress() {
    if (!flashButtonPressed) {
        return;
    }
    
    unsigned long pressDuration = millis() - flashPressTime;
    
    // 2-5 seconds: Recovery AP
    if (pressDuration > 2000 && pressDuration < 5000 && 
        digitalRead(FLASH_BTN) == LOW) {
        return;  // Still holding
    }
    
    if (pressDuration >= 2000 && pressDuration < 5000 &&
        digitalRead(FLASH_BTN) == HIGH) {
        Serial.println("[Flash] Recovery mode - starting AP");
        startAccessPoint();
        flashButtonPressed = false;
        return;
    }
    
    // 8+ seconds: Factory Reset
    if (pressDuration >= 8000 && digitalRead(FLASH_BTN) == LOW) {
        Serial.println("[Flash] Factory reset initiated...");
        // Blink LED rapidly to indicate reset
        for (int i = 0; i < 10; i++) {
            digitalWrite(BUILTIN_LED, LOW);
            delay(100);
            digitalWrite(BUILTIN_LED, HIGH);
            delay(100);
        }
        
        // Perform reset
        factoryReset();
        ESP.restart();
    }
}

void factoryReset() {
    Serial.println("[Factory Reset] Clearing configuration...");
    
    // Clear EEPROM
    for (int i = 0; i < 512; i++) {
        EEPROM.write(i, 0xFF);
    }
    EEPROM.commit();
    
    // Clear LittleFS
    LittleFS.format();
    
    Serial.println("✓ Factory reset complete");
}

// ============================================
// CONFIGURATION MANAGEMENT
// ============================================
void loadConfig() {
    EEPROM.begin(512);
    
    // Load config struct
    EEPROM.readBytes(EEPROM_CONFIG_ADDR, (uint8_t*) &config, sizeof(Config));
    
    // Validate
    if (config.relayDuration == 0 || config.relayDuration == 0xFFFFFFFF) {
        // Load defaults
        strcpy(config.deviceId, "DEVICE_");
        strcat(config.deviceId, String(ESP.getChipId(), HEX).c_str());
        strcpy(config.deviceSecret, "secret_key_here");
        strcpy(config.wifiSSID, "SmartDoor");
        strcpy(config.wifiPassword, "1234567890");
        strcpy(config.serverUrl, "https://example.com/api");
        config.relayDuration = 3000;  // 3 seconds default
        config.relayPolarity = 1;     // Active HIGH default
        config.auth_epoch = 1;
        
        memset(config.user_revision, 0, sizeof(config.user_revision));
        
        saveConfig();
    }
    
    Serial.printf("✓ Config loaded: %s (duration: %u ms, polarity: %s)\n",
                 config.deviceId,
                 config.relayDuration,
                 config.relayPolarity ? "HIGH" : "LOW");
}

void saveConfig() {
    EEPROM.writeBytes(EEPROM_CONFIG_ADDR, (uint8_t*) &config, sizeof(Config));
    EEPROM.commit();
    Serial.println("✓ Configuration saved to EEPROM");
}
