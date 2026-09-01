package com.smartdoor.app.ui

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.constraintlayout.widget.ConstraintLayout
import com.smartdoor.app.R
import com.smartdoor.app.data.AuthManager
import com.smartdoor.app.services.VoiceRecognitionService
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {

    private lateinit var authManager: AuthManager
    private lateinit var doorOpenButton: Button
    private lateinit var adminButton: Button
    private lateinit var voiceButton: Button
    private lateinit var qrButton: Button
    private lateinit var statusText: TextView
    private var isVoiceServiceRunning = false
    private val scope = MainScope()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        authManager = AuthManager(this)

        // Check authentication
        if (!authManager.isLoggedIn()) {
            startActivity(Intent(this, LoginActivity::class.java))
            finish()
            return
        }

        // Initialize UI components
        doorOpenButton = findViewById(R.id.door_open_button)
        adminButton = findViewById(R.id.admin_button)
        voiceButton = findViewById(R.id.voice_button)
        qrButton = findViewById(R.id.qr_button)
        statusText = findViewById(R.id.status_text)

        // Set click listeners
        doorOpenButton.setOnClickListener {
            openDoor()
        }

        adminButton.setOnClickListener {
            startActivity(Intent(this, AdminPanelActivity::class.java))
        }

        voiceButton.setOnClickListener {
            toggleVoiceService()
        }

        qrButton.setOnClickListener {
            startActivity(Intent(this, QRScannerActivity::class.java))
        }

        updateStatus()
    }

    private fun openDoor() {
        scope.launch {
            try {
                val success = authManager.requestDoorOpen()
                if (success) {
                    Toast.makeText(this@MainActivity, "✓ تم فتح الباب", Toast.LENGTH_SHORT).show()
                    playSuccessSound()
                } else {
                    Toast.makeText(this@MainActivity, "✗ فشل فتح الباب", Toast.LENGTH_SHORT).show()
                    playFailureSound()
                }
            } catch (e: Exception) {
                Toast.makeText(this@MainActivity, "خطأ: ${e.message}", Toast.LENGTH_LONG).show()
            }
        }
    }

    private fun toggleVoiceService() {
        if (isVoiceServiceRunning) {
            stopService(Intent(this, VoiceRecognitionService::class.java))
            isVoiceServiceRunning = false
            voiceButton.text = "🎤 بدء التحكم الصوتي"
            Toast.makeText(this, "تم إيقاف التحكم الصوتي", Toast.LENGTH_SHORT).show()
        } else {
            startForegroundService(Intent(this, VoiceRecognitionService::class.java))
            isVoiceServiceRunning = true
            voiceButton.text = "🛑 إيقاف التحكم الصوتي"
            Toast.makeText(this, "تم بدء التحكم الصوتي", Toast.LENGTH_SHORT).show()
        }
    }

    private fun updateStatus() {
        scope.launch {
            val status = authManager.getConnectionStatus()
            statusText.text = when (status) {
                "online" -> "🟢 متصل بالإنترنت"
                "local" -> "🔵 متصل محليًا"
                "offline" -> "🔴 غير متصل"
                else -> "⚪ حالة غير معروفة"
            }
        }
    }

    private fun playSuccessSound() {
        // TODO: Play success beep
    }

    private fun playFailureSound() {
        // TODO: Play failure beep
    }

    override fun onDestroy() {
        if (isVoiceServiceRunning) {
            stopService(Intent(this, VoiceRecognitionService::class.java))
        }
        super.onDestroy()
    }
}
