#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <time.h>

// Pin Definitions
#define RELAY_PIN D1  // GPIO5
#define BUILTIN_LED D4  // GPIO2

// Relay Modes
#define RELAY_ACTIVE_HIGH 1
#define RELAY_ACTIVE_LOW 0

// Operating Modes
#define MODE_LOCAL 1
#define MODE_SERVER 2
#define MODE_TELEGRAM 3

// State Machine States
enum DeviceState {
    STATE_BOOT_SAFE,
    STATE_LOAD_CONFIG,
    STATE_WIFI_CONNECTING,
    STATE_CLOUD_CONNECTING,
    STATE_IDLE_LOCKED,
    STATE_COMMAND_RECEIVED,
    STATE_COMMAND_VALIDATING,
    STATE_RELAY_ACTIVE,
    STATE_RELAY_RETURNING_INACTIVE,
    STATE_SENDING_ACK,
    STATE_OFFLINE,
    STATE_ERROR_RECOVERY,
    STATE_OTA_UPDATING
};

// Configuration Structure
struct DeviceConfig {
    String deviceId;
    String deviceSecret;
    String apiUrl;
    String localSSID;
    String localPassword;
    int operatingMode;
    int relayActiveLevel;
    int unlockDuration;
    int minUnlockDuration;
    int maxUnlockDuration;
    bool localModeEnabled;
    bool serverModeEnabled;
    bool telegramModeEnabled;
};

// Command Structure
struct Command {
    String commandId;
    String action;
    int durationMs;
    String issuedAt;
    String expiresAt;
    String source;
    int actorId;
    String requestId;
    String signature;
    int sequenceNumber;
    String nonce;
};

// Global Variables
DeviceState currentState = STATE_BOOT_SAFE;
DeviceConfig config;
Command pendingCommand;
unsigned long lastHeartbeat = 0;
unsigned long lastPollTime = 0;
unsigned long relayActivateTime = 0;
unsigned long stateStartTime = 0;
int pollInterval = 2000;  // 2 seconds normal
int lastSequence = 0;
bool relayIsActive = false;
int reconnectAttempts = 0;
const int MAX_RECONNECT_ATTEMPTS = 10;
const int HEARTBEAT_INTERVAL = 30000;  // 30 seconds
const int COMMAND_EXPIRY = 10000;  // 10 seconds
const int RELAY_MAX_DURATION = 15000;  // 15 seconds absolute max
const int BOOT_SAFE_DELAY = 100;  // 100ms before any relay operation

void setup() {
    Serial.begin(115200);
    delay(100);
    
    Serial.println("\n\n=== Smart Door Pro ESP8266 ===");
    Serial.println("Starting boot sequence...");
    
    // CRITICAL: Set relay to safe state immediately
    pinMode(RELAY_PIN, OUTPUT);
    pinMode(BUILTIN_LED, OUTPUT);
    
    // Set safe state based on configured mode
    int safeLevel = config.relayActiveLevel == RELAY_ACTIVE_HIGH ? LOW : HIGH;
    digitalWrite(RELAY_PIN, safeLevel);
    digitalWrite(BUILTIN_LED, HIGH);  // LED off
    
    delay(BOOT_SAFE_DELAY);
    
    Serial.println("✓ Boot safety: Relay set to safe state");
    
    // Load configuration from SPIFFS
    loadConfig();
    
    // Initialize WiFi
    initWiFi();
    
    // Set timezone
    configTime(0, 0, "pool.ntp.org", "time.nist.gov");
    
    // Transition to next state
    transitionState(STATE_LOAD_CONFIG);
}

void loop() {
    // Feed watchdog
    yield();
    
    // Process state machine
    handleStateTransition();
    
    // Non-blocking relay control
    updateRelayControl();
    
    // Periodic heartbeat
    if (millis() - lastHeartbeat > HEARTBEAT_INTERVAL) {
        sendHeartbeat();
        lastHeartbeat = millis();
    }
    
    // Handle WiFi reconnection
    if (!WiFi.isConnected() && currentState != STATE_OFFLINE) {
        transitionState(STATE_OFFLINE);
    }
}

void handleStateTransition() {
    unsigned long now = millis();
    unsigned long stateElapsed = now - stateStartTime;
    
    switch (currentState) {
        case STATE_BOOT_SAFE:
            transitionState(STATE_LOAD_CONFIG);
            break;
            
        case STATE_LOAD_CONFIG:
            Serial.println("Loading configuration...");
            // Config should be pre-loaded in setup
            transitionState(STATE_WIFI_CONNECTING);
            break;
            
        case STATE_WIFI_CONNECTING:
            Serial.println("Attempting WiFi connection...");
            if (WiFi.isConnected()) {
                Serial.println("✓ WiFi connected: " + WiFi.SSID() + " (" + WiFi.localIP().toString() + ")");
                if (config.operatingMode == MODE_SERVER || config.serverModeEnabled) {
                    transitionState(STATE_CLOUD_CONNECTING);
                } else {
                    transitionState(STATE_IDLE_LOCKED);
                }
            } else if (stateElapsed > 30000) {
                Serial.println("⚠ WiFi timeout, switching to local mode...");
                transitionState(STATE_IDLE_LOCKED);  // Fall back to local mode
            }
            break;
            
        case STATE_CLOUD_CONNECTING:
            Serial.println("Connecting to cloud server...");
            if (testServerConnection()) {
                Serial.println("✓ Cloud connected");
                transitionState(STATE_IDLE_LOCKED);
            } else if (stateElapsed > 10000) {
                Serial.println("⚠ Cloud timeout, falling back to local mode...");
                transitionState(STATE_IDLE_LOCKED);
            }
            break;
            
        case STATE_IDLE_LOCKED:
            // Poll for commands
            if (config.operatingMode == MODE_SERVER && WiFi.isConnected()) {
                if (millis() - lastPollTime > pollInterval) {
                    pollNextCommand();
                    lastPollTime = millis();
                }
            }
            break;
            
        case STATE_COMMAND_RECEIVED:
            transitionState(STATE_COMMAND_VALIDATING);
            break;
            
        case STATE_COMMAND_VALIDATING:
            if (validateCommand(pendingCommand)) {
                Serial.println("✓ Command validated: " + pendingCommand.commandId);
                transitionState(STATE_RELAY_ACTIVE);
            } else {
                Serial.println("✗ Command validation failed");
                sendCommandAck(pendingCommand.commandId, "rejected");
                transitionState(STATE_IDLE_LOCKED);
            }
            break;
            
        case STATE_RELAY_ACTIVE:
            // Relay pulse duration is handled in updateRelayControl()
            if (!relayIsActive) {
                transitionState(STATE_RELAY_RETURNING_INACTIVE);
            }
            break;
            
        case STATE_RELAY_RETURNING_INACTIVE:
            transitionState(STATE_SENDING_ACK);
            break;
            
        case STATE_SENDING_ACK:
            sendCommandAck(pendingCommand.commandId, "executed");
            transitionState(STATE_IDLE_LOCKED);
            break;
            
        case STATE_OFFLINE:
            Serial.println("Device offline - retrying connection...");
            if (WiFi.isConnected()) {
                transitionState(STATE_IDLE_LOCKED);
            } else if (stateElapsed > 5000) {
                tryReconnect();
            }
            break;
            
        case STATE_ERROR_RECOVERY:
            Serial.println("Error recovery - resetting...");
            // Ensure relay is safe
            deactivateRelay();
            delay(1000);
            transitionState(STATE_IDLE_LOCKED);
            break;
            
        default:
            break;
    }
}

void transitionState(DeviceState newState) {
    if (currentState == newState) {
        return;  // Already in this state
    }
    
    currentState = newState;
    stateStartTime = millis();
    
    // Log state change
    Serial.printf("[State] Transitioning to state %d\n", newState);
    
    // LED indication
    if (newState == STATE_IDLE_LOCKED) {
        digitalWrite(BUILTIN_LED, HIGH);  // LED off
    } else if (newState == STATE_RELAY_ACTIVE) {
        digitalWrite(BUILTIN_LED, LOW);   // LED on
    }
}

void updateRelayControl() {
    if (relayIsActive) {
        unsigned long elapsed = millis() - relayActivateTime;
        
        // Enforce absolute maximum
        if (elapsed > RELAY_MAX_DURATION) {
            Serial.println("✗ Relay emergency deactivation (exceeded max duration)");
            deactivateRelay();
            return;
        }
        
        // Check if configured duration reached
        if (elapsed > pendingCommand.durationMs) {
            deactivateRelay();
        }
    }
}

void activateRelay() {
    int activeLevel = config.relayActiveLevel == RELAY_ACTIVE_HIGH ? HIGH : LOW;
    digitalWrite(RELAY_PIN, activeLevel);
    relayIsActive = true;
    relayActivateTime = millis();
    
    Serial.printf("✓ Relay activated (Duration: %dms)\n", pendingCommand.durationMs);
}

void deactivateRelay() {
    int inactiveLevel = config.relayActiveLevel == RELAY_ACTIVE_HIGH ? LOW : HIGH;
    digitalWrite(RELAY_PIN, inactiveLevel);
    relayIsActive = false;
    
    Serial.println("✓ Relay deactivated");
}

bool validateCommand(const Command& cmd) {
    // Check action is UNLOCK
    if (cmd.action != "unlock") {
        return false;
    }
    
    // Check duration limits
    if (cmd.durationMs < config.minUnlockDuration || cmd.durationMs > config.maxUnlockDuration) {
        return false;
    }
    
    // Check expiry
    unsigned long now = time(nullptr);
    unsigned long expiresAtTime = parseISO8601(cmd.expiresAt);
    if (now > expiresAtTime) {
        return false;
    }
    
    // Check signature (simplified - use HMAC-SHA256 in production)
    // TODO: Verify HMAC-SHA256 signature
    
    // Check sequence number
    if (cmd.sequenceNumber <= lastSequence) {
        return false;  // Replay protection
    }
    lastSequence = cmd.sequenceNumber;
    
    return true;
}

void pollNextCommand() {
    if (!WiFi.isConnected()) {
        return;
    }
    
    WiFiClientSecure client;
    client.setInsecure();  // TODO: Use proper certificate validation
    
    HTTPClient http;
    String url = config.apiUrl + "/device/command/next";
    
    // Add device headers
    String timestamp = String(time(nullptr));
    String signature = generateSignature(timestamp);
    
    http.begin(client, url);
    http.addHeader("Device-ID", config.deviceId);
    http.addHeader("Device-Signature", signature);
    http.addHeader("Timestamp", timestamp);
    
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        String payload = http.getString();
        
        DynamicJsonDocument doc(1024);
        deserializeJson(doc, payload);
        
        if (doc["data"] != nullptr && doc["data"]["command_id"] != nullptr) {
            pendingCommand.commandId = doc["data"]["command_id"].as<String>();
            pendingCommand.action = doc["data"]["action"].as<String>();
            pendingCommand.durationMs = doc["data"]["duration_ms"].as<int>();
            pendingCommand.expiresAt = doc["data"]["expires_at"].as<String>();
            pendingCommand.sequenceNumber = doc["data"]["sequence_number"].as<int>();
            pendingCommand.nonce = doc["data"]["nonce"].as<String>();
            
            Serial.println("✓ Command received: " + pendingCommand.commandId);
            transitionState(STATE_COMMAND_RECEIVED);
        }
    } else if (httpCode == 204) {
        // No command available
        pollInterval = 3000;  // Increase poll interval
    } else {
        Serial.printf("✗ Poll failed: HTTP %d\n", httpCode);
        pollInterval = 2000;  // Resume normal polling
    }
    
    http.end();
}

void sendCommandAck(const String& commandId, const String& status) {
    if (!WiFi.isConnected()) {
        return;
    }
    
    WiFiClientSecure client;
    client.setInsecure();
    
    HTTPClient http;
    String url = config.apiUrl + "/device/commands/" + commandId + "/ack";
    
    DynamicJsonDocument doc(512);
    doc["status"] = status;
    doc["executed_at"] = getISO8601Time();
    doc["actual_duration_ms"] = relayActivateTime > 0 ? millis() - relayActivateTime : 0;
    
    // Device diagnostics
    uint32_t freeHeap = ESP.getFreeHeap();
    int32_t rssi = WiFi.RSSI();
    
    doc["free_heap"] = freeHeap;
    doc["rssi"] = rssi;
    doc["firmware_version"] = "1.0.0";
    
    String payload;
    serializeJson(doc, payload);
    
    String timestamp = String(time(nullptr));
    String signature = generateSignature(timestamp + payload);
    
    http.begin(client, url);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Device-ID", config.deviceId);
    http.addHeader("Device-Signature", signature);
    http.addHeader("Timestamp", timestamp);
    
    int httpCode = http.POST(payload);
    
    if (httpCode == 200) {
        Serial.println("✓ ACK sent successfully");
    } else {
        Serial.printf("✗ ACK send failed: HTTP %d\n", httpCode);
    }
    
    http.end();
}

void sendHeartbeat() {
    if (!WiFi.isConnected()) {
        return;
    }
    
    WiFiClientSecure client;
    client.setInsecure();
    
    HTTPClient http;
    String url = config.apiUrl + "/device/heartbeat";
    
    DynamicJsonDocument doc(256);
    doc["device_id"] = config.deviceId;
    doc["status"] = "online";
    doc["rssi"] = WiFi.RSSI();
    doc["free_heap"] = ESP.getFreeHeap();
    doc["uptime_seconds"] = millis() / 1000;
    doc["timestamp"] = getISO8601Time();
    
    String payload;
    serializeJson(doc, payload);
    
    String timestamp = String(time(nullptr));
    String signature = generateSignature(timestamp + payload);
    
    http.begin(client, url);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Device-ID", config.deviceId);
    http.addHeader("Device-Signature", signature);
    http.addHeader("Timestamp", timestamp);
    
    int httpCode = http.POST(payload);
    http.end();
}

bool testServerConnection() {
    WiFiClientSecure client;
    client.setInsecure();
    
    HTTPClient http;
    http.begin(client, config.apiUrl + "/device/bootstrap");
    
    int httpCode = http.GET();
    http.end();
    
    return httpCode == 200;
}

void tryReconnect() {
    reconnectAttempts++;
    
    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        Serial.println("Max reconnection attempts reached");
        transitionState(STATE_ERROR_RECOVERY);
        reconnectAttempts = 0;
        return;
    }
    
    Serial.printf("Reconnecting... Attempt %d/%d\n", reconnectAttempts, MAX_RECONNECT_ATTEMPTS);
    WiFi.reconnect();
}

void initWiFi() {
    WiFi.mode(WIFI_STA);
    WiFi.begin(config.localSSID.c_str(), config.localPassword.c_str());
}

void loadConfig() {
    // Load from SPIFFS or hardcoded defaults
    config.deviceId = "DEVICE_" + String(ESP.getChipId(), HEX);
    config.deviceSecret = "secret_key_here";
    config.apiUrl = "https://yourdomain.com/api/v1";
    config.localSSID = "MyNetwork";
    config.localPassword = "password";
    config.operatingMode = MODE_SERVER;
    config.relayActiveLevel = RELAY_ACTIVE_HIGH;
    config.unlockDuration = 3000;
    config.minUnlockDuration = 500;
    config.maxUnlockDuration = 15000;
    config.localModeEnabled = true;
    config.serverModeEnabled = true;
    config.telegramModeEnabled = false;
}

String generateSignature(const String& data) {
    // TODO: Implement HMAC-SHA256
    return "signature_placeholder";
}

String getISO8601Time() {
    time_t now = time(nullptr);
    struct tm* timeinfo = gmtime(&now);
    char buffer[25];
    strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%SZ", timeinfo);
    return String(buffer);
}

unsigned long parseISO8601(const String& timeStr) {
    // TODO: Parse ISO 8601 format
    return time(nullptr);
}
