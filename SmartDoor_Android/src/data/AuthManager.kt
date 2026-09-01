package com.smartdoor.app.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.smartdoor.app.api.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class AuthManager(private val context: Context) {

    private val masterKey = MasterKey.Builder(context)
        .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
        .build()

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "auth_prefs",
        masterKey,
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    private val apiClient = ApiClient(context)

    suspend fun login(email: String, password: String): Boolean = withContext(Dispatchers.IO) {
        return@withContext try {
            val response = apiClient.login(email, password)
            if (response.isSuccessful && response.body()?.ok == true) {
                val tokens = response.body()?.data?.tokens
                tokens?.let {
                    prefs.edit().apply {
                        putString(KEY_ACCESS_TOKEN, it.access_token)
                        putString(KEY_REFRESH_TOKEN, it.refresh_token)
                        putString(KEY_EMAIL, email)
                        putLong(KEY_LOGIN_TIME, System.currentTimeMillis())
                        apply()
                    }
                }
                true
            } else {
                false
            }
        } catch (e: Exception) {
            e.printStackTrace()
            false
        }
    }

    fun isLoggedIn(): Boolean {
        val token = prefs.getString(KEY_ACCESS_TOKEN, null)
        return token != null && token.isNotEmpty()
    }

    fun getAccessToken(): String? = prefs.getString(KEY_ACCESS_TOKEN, null)

    suspend fun requestDoorOpen(): Boolean = withContext(Dispatchers.IO) {
        return@withContext try {
            val token = getAccessToken() ?: return@withContext false
            val response = apiClient.openDoor(token, 3000)
            response.isSuccessful && response.body()?.ok == true
        } catch (e: Exception) {
            e.printStackTrace()
            false
        }
    }

    suspend fun getConnectionStatus(): String = withContext(Dispatchers.IO) {
        return@withContext try {
            val response = apiClient.health()
            when {
                response.isSuccessful -> "online"
                else -> "offline"
            }
        } catch (e: Exception) {
            "offline"
        }
    }

    fun logout() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val KEY_ACCESS_TOKEN = "access_token"
        private const val KEY_REFRESH_TOKEN = "refresh_token"
        private const val KEY_EMAIL = "email"
        private const val KEY_LOGIN_TIME = "login_time"
    }
}
