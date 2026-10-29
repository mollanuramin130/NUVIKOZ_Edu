package com.nuvikoz.edu

import android.content.pm.ApplicationInfo
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith

/** Run twice in separate instrumentation processes: -e phase write, then read. */
@RunWith(AndroidJUnit4::class)
class VaultProcessPersistenceTest {
    @Test fun persistedRecordSurvivesInstrumentationProcessRestart() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        assertTrue(context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE != 0)
        val phase = InstrumentationRegistry.getArguments().getString("phase")
        assumeTrue("Explicit two-process device procedure required", phase == "write" || phase == "read")
        val vault = SecureTokenVault(context)
        val synthetic = "synthetic-two-process-persistence"
        if (phase == "write") {
            vault.clear()
            vault.write(synthetic)
            assertEquals(synthetic, vault.read())
        } else {
            try { assertEquals(synthetic, vault.read()) } finally { vault.clear() }
        }
    }
}
