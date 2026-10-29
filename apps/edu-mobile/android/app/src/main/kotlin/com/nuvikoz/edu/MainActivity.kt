package com.nuvikoz.edu

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class MainActivity : FlutterActivity() {
    private val channelName = "nuvikoz.edu/secure-token-store"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private lateinit var vault: SecureTokenVault

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        vault = SecureTokenVault(applicationContext)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channelName).setMethodCallHandler { call, result ->
            when (call.method) {
                "read" -> {
                    scope.launch {
                        try {
                            val data = withContext(Dispatchers.IO) { vault.read() }
                            result.success(data)
                        } catch (e: Exception) {
                            result.error("SECURE_STORAGE_ERROR", "Secure device storage operation failed", null)
                        }
                    }
                }
                "write" -> {
                    val value = call.argument<String>("value")
                    if (value == null || value.isEmpty() || value.length > 60000) {
                        result.error("INVALID_ARGUMENT", "Invalid token payload", null)
                        return@setMethodCallHandler
                    }
                    scope.launch {
                        try {
                            withContext(Dispatchers.IO) { vault.write(value) }
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("SECURE_STORAGE_ERROR", "Secure device storage operation failed", null)
                        }
                    }
                }
                "clear" -> {
                    scope.launch {
                        try {
                            withContext(Dispatchers.IO) { vault.clear() }
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("SECURE_STORAGE_ERROR", "Secure device storage operation failed", null)
                        }
                    }
                }
                else -> result.notImplemented()
            }
        }
    }
}
