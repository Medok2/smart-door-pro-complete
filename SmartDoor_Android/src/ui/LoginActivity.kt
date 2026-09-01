package com.smartdoor.app.ui

import android.content.Context
import android.content.Intent
import android.content.SharedPreferences
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.smartdoor.app.R
import com.smartdoor.app.data.AuthManager
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {

    private lateinit var emailInput: EditText
    private lateinit var passwordInput: EditText
    private lateinit var loginButton: Button
    private lateinit var authManager: AuthManager
    private val scope = MainScope()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_login)

        emailInput = findViewById(R.id.email_input)
        passwordInput = findViewById(R.id.password_input)
        loginButton = findViewById(R.id.login_button)
        authManager = AuthManager(this)

        // Check if already logged in
        if (authManager.isLoggedIn()) {
            navigateToDashboard()
            return
        }

        // Demo credentials
        emailInput.setText("admin@smartdoor.com")
        passwordInput.setText("admin")

        loginButton.setOnClickListener {
            performLogin()
        }
    }

    private fun performLogin() {
        val email = emailInput.text.toString().trim()
        val password = passwordInput.text.toString()

        if (email.isEmpty() || password.isEmpty()) {
            Toast.makeText(this, "الرجاء إدخال البريد وكلمة المرور", Toast.LENGTH_SHORT).show()
            return
        }

        scope.launch {
            try {
                val result = authManager.login(email, password)
                if (result) {
                    navigateToDashboard()
                } else {
                    Toast.makeText(
                        this@LoginActivity,
                        "فشل تسجيل الدخول - بيانات غير صحيحة",
                        Toast.LENGTH_LONG
                    ).show()
                }
            } catch (e: Exception) {
                Toast.makeText(
                    this@LoginActivity,
                    "خطأ: ${e.message}",
                    Toast.LENGTH_LONG
                ).show()
            }
        }
    }

    private fun navigateToDashboard() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }
}
