package com.nuvikoz.edu

import android.Manifest
import android.content.pm.ApplicationInfo
import android.content.pm.PackageManager
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File
import java.security.KeyStore
import java.util.concurrent.CountDownLatch

/** Included only by the explicit debug harness build, never normal or release builds. */
class SecureStoreRuntimeActivity : MainActivity() {
    companion object {
        @Volatile var report: Map<*, *>? = null
        @Volatile var completed = CountDownLatch(1)
        fun reset() { report = null; completed = CountDownLatch(1) }
    }

    override fun getDartEntrypointArgs(): List<String> = listOf(
        intent.getStringExtra("phase") ?: "invalid",
        intent.getStringExtra("nonce") ?: ""
    )

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        check(packageName == "com.nuvikoz.edu.sec01test")
        check(applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE != 0)
        check(checkSelfPermission(Manifest.permission.INTERNET) == PackageManager.PERMISSION_DENIED)
        super.configureFlutterEngine(flutterEngine) // Actual production secure-token-store handler.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "nuvikoz.edu/sec01-harness-control")
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "deleteSyntheticKey" -> {
                        KeyStore.getInstance("AndroidKeyStore").apply { load(null); deleteEntry("nuvikoz.edu.auth.v1") }
                        result.success(true)
                    }
                    "assertCiphertextOnly" -> {
                        val bytes = File(noBackupFilesDir, "edu-auth-v1.bin").readBytes()
                        val synthetic = "SEC01_SYNTHETIC_CHANNEL_TOKEN_V1"
                        result.success(bytes.size >= 30 && bytes[0] == 1.toByte() && !String(bytes, Charsets.ISO_8859_1).contains(synthetic))
                    }
                    "report" -> {
                        val value = call.arguments as? Map<*, *>
                        if (value == null || value["nonce"] != intent.getStringExtra("nonce") || value["phase"] != intent.getStringExtra("phase")) {
                            result.error("INVALID_TEST_REPORT", "Unbound test result", null)
                        } else {
                            report = value
                            result.success(true)
                            completed.countDown()
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }
}
