package com.nuvikoz.edu

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID
import java.util.concurrent.TimeUnit

/** Real Flutter engine/Dart -> existing Kotlin handler -> AndroidKeyStore. No mocks. */
@RunWith(AndroidJUnit4::class)
class FlutterMethodChannelRuntimeTest {
    @Test fun realEngineChannelContract() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val context = instrumentation.targetContext
        assertEquals("com.nuvikoz.edu.sec01test", context.packageName)
        assertEquals(PackageManager.PERMISSION_DENIED, context.checkSelfPermission(Manifest.permission.INTERNET))
        val phase = InstrumentationRegistry.getArguments().getString("phase") ?: "contract"
        assertTrue("Explicit valid phase required", phase in listOf("contract", "persist-write", "persist-read"))
        val nonce = UUID.randomUUID().toString()
        SecureStoreRuntimeActivity.reset()
        val activity = instrumentation.startActivitySync(Intent(context, SecureStoreRuntimeActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK).putExtra("phase", phase).putExtra("nonce", nonce))
        try {
            assertTrue("Flutter engine did not return a test result", SecureStoreRuntimeActivity.completed.await(90, TimeUnit.SECONDS))
            val report = SecureStoreRuntimeActivity.report ?: error("Missing test report")
            assertEquals(nonce, report["nonce"])
            assertEquals(phase, report["phase"])
            assertEquals("Dart check failed: " + report["failure"], true, report["ok"])
            val checks = report["checks"] as? List<*> ?: error("Missing executed checks")
            val expected = when (phase) { "contract" -> 15; else -> 2 }
            assertEquals("Unexpected executed check count", expected, checks.size)
            println("SEC01_REAL_CHANNEL phase=$phase checks=${checks.size} PASS")
        } finally {
            instrumentation.runOnMainSync { activity.finish() }
        }
    }
}
