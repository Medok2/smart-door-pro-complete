package com.smartdoor.app.services

import android.app.Service
import android.content.Intent
import android.os.IBinder
import android.media.AudioRecord
import android.media.MediaRecorder
import androidx.core.app.NotificationCompat
import com.smartdoor.app.R
import com.smartdoor.app.ml.VoiceProcessor
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class VoiceRecognitionService : Service() {

    private lateinit var audioRecord: AudioRecord
    private lateinit var voiceProcessor: VoiceProcessor
    private var isListening = false
    private val scope = CoroutineScope(Dispatchers.Default)

    override fun onCreate() {
        super.onCreate()
        voiceProcessor = VoiceProcessor(this)
        startListening()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForeground(NOTIFICATION_ID, createNotification())
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun startListening() {
        isListening = true
        scope.launch {
            while (isListening) {
                try {
                    // Record audio chunk
                    val audioData = recordAudioChunk()
                    
                    // Process with ASR
                    val result = voiceProcessor.processAudio(audioData)
                    
                    // Check if matches configured phrase
                    if (result != null && matchesPhrase(result)) {
                        triggerDoorOpen()
                    }
                } catch (e: Exception) {
                    e.printStackTrace()
                }
            }
        }
    }

    private fun recordAudioChunk(): ByteArray {
        // Record 1 second of audio
        val bufferSize = AudioRecord.getMinBufferSize(
            SAMPLE_RATE,
            android.media.AudioFormat.CHANNEL_IN_MONO,
            android.media.AudioFormat.ENCODING_PCM_16BIT
        )
        val buffer = ByteArray(bufferSize)
        // TODO: Implement actual recording
        return buffer
    }

    private fun matchesPhrase(text: String): Boolean {
        val normalized = normalizeArabicText(text.toLowerCase())
        val phrase = normalizeArabicText("افتح الباب")
        return normalized.contains(phrase)
    }

    private fun normalizeArabicText(text: String): String {
        return text
            .replace("أ", "ا")
            .replace("إ", "ا")
            .replace("آ", "ا")
            .replace("ى", "ي")
            .replace("ة", "ه")
            .replace(" +".toRegex(), " ")
            .trim()
    }

    private fun triggerDoorOpen() {
        // Send door open command
        scope.launch {
            // TODO: Send authenticated request
        }
    }

    private fun createNotification() = NotificationCompat.Builder(this, CHANNEL_ID)
        .setContentTitle("Smart Door Pro")
        .setContentText("التحكم الصوتي نشط...")
        .setSmallIcon(R.drawable.ic_launcher_foreground)
        .setPriority(NotificationCompat.PRIORITY_LOW)
        .build()

    override fun onDestroy() {
        isListening = false
        super.onDestroy()
    }

    companion object {
        private const val NOTIFICATION_ID = 1001
        private const val CHANNEL_ID = "voice_recognition"
        private const val SAMPLE_RATE = 16000
    }
}
