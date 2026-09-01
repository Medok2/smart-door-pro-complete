# Smart Door Pro - Android App

**Platform:** Android 11+ (API 30+)  
**Language:** Kotlin + Material Design 3  
**Architecture:** MVVM + Coroutines  

## Features

### ✅ Authentication
- Secure login with encrypted token storage (Android Keystore)
- Session management with auto-refresh
- Biometric option (future)

### ✅ Door Control
- One-tap door open button
- Real-time door status (Online/Offline)
- Visual + audio feedback

### ✅ Voice Control
- Offline Arabic ASR (Vosk or Sherpa-ONNX)
- Phrase normalization (ا/أ/إ, ي/ى, ة/ه)
- 5-sample voice training for personalization
- Foreground Service with persistent notification
- VAD + confidence-based filtering

### ✅ QR Code
- Camera-based QR scanner
- Photo gallery QR import
- One-time use passes
- Unlimited passes
- Public QR links

### ✅ Admin Panel (6 Tabs)

**1. Account & Security**
- Admin name
- Password change
- Relay polarity (Active HIGH/LOW)
- Guest password option
- Voice control permission

**2. Networks**
- Device SSID + Password (Local AP)
- Router SSID + Password
- Connection status
- Auto-save

**3. External Connection**
- Mode: Local / Server / Telegram
- Server URL (HTTP/HTTPS)
- Device ID + Key
- Self-signed cert warning
- Test connection button

**4. Users & Permissions**
- Unlimited cloud users
- 30 local users max (EEPROM)
- Pagination + search
- Name, status, ID, activation, usage count
- Permissions: Manual/Voice/QR/Logs
- Limits: Permanent (0) or N uses
- Create / Edit / Block / Unblock / Reset Code / Delete

**5. Devices**
- Known + connected devices
- MAC, Name, Last seen
- 50-device SoftAP limit (documented)
- Rename / Block / Unblock

**6. Logs & QR**
- Date + Time + Actor + Source + Success/Fail + Reason
- Pagination
- No passwords/secrets logged
- Create / Cancel / Share QR

### ✅ Network Path Selection
- `ConnectivityManager.VALIDATED` for cloud
- WiFi + Local for ESP
- Auto-fallback on server error
- No accidental redirect to insecure network

## Project Structure

```
SmartDoor_Android/
├── src/
│   ├── ui/
│   │   ├── LoginActivity.kt
│   │   ├── MainActivity.kt
│   │   ├── AdminPanelActivity.kt
│   │   ├── VoiceRecordingActivity.kt
│   │   └── QRScannerActivity.kt
│   ├── services/
│   │   ├── VoiceRecognitionService.kt
│   │   ├── BackgroundSyncService.kt
│   │   └── SyncJobService.kt
│   ├── data/
│   │   ├── AuthManager.kt
│   │   ├── DeviceManager.kt
│   │   └── UserPreferences.kt
│   ├── api/
│   │   └── ApiClient.kt
│   ├── ml/
│   │   └── VoiceProcessor.kt
│   └── receivers/
│       └── NetworkChangeReceiver.kt
├── res/
│   ├── layout/
│   ├── values/
│   ├── drawable/
│   └── mipmap/
└── AndroidManifest.xml
```

## Key Dependencies

```gradle
// Core
implementation 'androidx.appcompat:appcompat:1.6.1'
implementation 'androidx.constraintlayout:constraintlayout:2.1.4'

// Security
implementation 'androidx.security:security-crypto:1.1.0-alpha06'
implementation 'com.google.android.material:material:1.9.0'

// Network
implementation 'com.squareup.okhttp3:okhttp:4.11.0'
implementation 'com.squareup.retrofit2:retrofit:2.10.0'

// Coroutines
implementation 'org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3'

// QR & Camera
implementation 'com.google.mlkit:barcode-scanning:17.1.0'
implementation 'androidx.camera:camera-core:1.3.0'

// Voice (Offline ASR)
implementation 'org.vosk:vosk-android:0.3.34'
// OR
implementation 'com.k2fsa.sherpa.onnx:sherpa-onnx-android:0.1.0'

// Serialization
implementation 'com.google.code.gson:gson:2.10.1'
```

## Building

```bash
# Clone and setup
git clone <repo>
cd SmartDoor_Android

# Build APK
./gradlew assembleRelease

# Output: app/release/app-release.apk
```

## Security

✅ No secrets in code  
✅ Encrypted shared preferences (Android Keystore)  
✅ TLS certificate pinning (optional)  
✅ HMAC-SHA256 request signing  
✅ Biometric support ready  
✅ No password logging  
✅ Foreground service for transparency  

## Permissions

- `INTERNET` - API calls
- `ACCESS_NETWORK_STATE` - WiFi detection
- `CAMERA` - QR scanning
- `RECORD_AUDIO` - Voice control
- `POST_NOTIFICATIONS` - Alerts

## Configuration

Create `local.properties`:

```properties
sdk.dir=/path/to/Android/sdk
keystore.file=/path/to/smartdoor.keystore
keystore.password=KEYSTORE_PASSWORD
key.alias=smartdoor
key.password=KEY_PASSWORD
```

## Testing

```bash
# Run instrumented tests
./gradlew connectedAndroidTest

# Run unit tests
./gradlew testDebugUnitTest

# Lint check
./gradlew lint
```

## First Launch

1. **Login:** admin@smartdoor.com / admin
2. **Change password** immediately
3. **Setup admin panel:**
   - Configure WiFi network
   - Set server URL (if cloud mode)
   - Add users
4. **Grant permissions:** Camera, Audio, Storage, Notifications
5. **Test door open**

## Deployment

Sign APK:

```bash
jarsigner -verbose -sigalg SHA256withRSA -digestalg SHA-256 \
  -keystore smartdoor.keystore \
  app-release-unsigned.apk smartdoor

zipalign -v 4 app-release-unsigned.apk app-release.apk
```

## Troubleshooting

**Voice not working?**
- Check microphone permission
- Restart Foreground Service
- Verify language setting (Arabic)

**Can't connect to device?**
- Check WiFi SSID/password
- Verify server URL format
- Try local mode if cloud fails

**Battery drain?**
- Disable continuous voice listening when not needed
- Reduce polling interval in admin panel
- Check for stuck HTTP requests

## Version History

**v1.0.0** (2024)
- Initial stable release
- Full admin panel
- Voice control
- QR support
- Foreground service

## Support

Designed by AHMED KHALED  
Phone: 01117614245

---

**Status:** Production Ready ✅
