package com.nuvikoz.edu

import android.content.pm.ApplicationInfo
import android.security.keystore.KeyInfo
import android.util.AtomicFile
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import java.security.KeyStore
import javax.crypto.SecretKey
import javax.crypto.SecretKeyFactory
import org.junit.After
import org.junit.Assert.*
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/** Executes the real vault and AndroidKeyStore provider, never a mock provider. */
@RunWith(AndroidJUnit4::class)
class SecureTokenVaultInstrumentedTest {
    private val context get() = InstrumentationRegistry.getInstrumentation().targetContext
    private val record get() = File(context.noBackupFilesDir, "edu-auth-v1.bin")
    private val alias = "nuvikoz.edu.auth.v1"
    private lateinit var vault: SecureTokenVault

    @Before fun prepare() {
        assertTrue("Disposable debug application required", context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE != 0)
        vault = SecureTokenVault(context)
        vault.clear()
    }

    @After fun cleanup() { vault.clear() }

    private fun rejected(action: () -> Unit) {
        var failed = false
        try { action() } catch (_: Exception) { failed = true }
        assertTrue("Operation must fail closed", failed)
    }

    @Test fun keystoreRoundTripAndReopenedVaultNeverPersistPlaintext() {
        val value = "synthetic-instrumentation-token-only"
        vault.write(value)
        assertEquals(value, vault.read())
        assertEquals(value, SecureTokenVault(context).read())
        val encrypted = record.readBytes()
        assertEquals(1.toByte(), encrypted[0])
        assertFalse(String(encrypted, Charsets.ISO_8859_1).contains(value))
        val keys = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val key = keys.getKey(alias, null) as SecretKey
        val info = SecretKeyFactory.getInstance(key.algorithm, "AndroidKeyStore")
            .getKeySpec(key, KeyInfo::class.java) as KeyInfo
        assertEquals(256, info.keySize)
        assertTrue(info.blockModes.contains("GCM"))
        // Hardware backing is device-dependent and is not inferred from provider use.
        vault.write(value)
        assertFalse(encrypted.contentEquals(record.readBytes()))
    }

    @Test fun tamperingRejectsReadAndOverwriteWithoutPlaintextFallback() {
        vault.write("synthetic-tamper-test")
        val corrupted = record.readBytes()
        corrupted[corrupted.lastIndex] = (corrupted.last().toInt() xor 1).toByte()
        record.writeBytes(corrupted)
        rejected { vault.read() }
        rejected { vault.write("synthetic-replacement") }
        assertArrayEquals(corrupted, record.readBytes())
    }

    @Test fun missingKeyCannotRegenerateOverExistingCiphertext() {
        vault.write("synthetic-missing-key")
        val before = record.readBytes()
        KeyStore.getInstance("AndroidKeyStore").apply { load(null); deleteEntry(alias) }
        rejected { vault.read() }
        rejected { vault.write("synthetic-replacement") }
        assertArrayEquals(before, record.readBytes())
    }

    @Test fun interruptedAtomicWritePreservesCommittedCiphertext() {
        vault.write("synthetic-committed")
        val before = record.readBytes()
        // Model interruption after writing the staging file, before finishWrite.
        AtomicFile(record).startWrite().use { it.write(byteArrayOf(1, 2, 3)) }
        assertEquals("synthetic-committed", SecureTokenVault(context).read())
        assertArrayEquals(before, record.readBytes())
    }

    @Test fun invalidPayloadDoesNotPartiallyReplaceCommittedValue() {
        vault.write("synthetic-original")
        val before = record.readBytes()
        rejected { vault.write("") }
        rejected { vault.write("x".repeat(60001)) }
        assertArrayEquals(before, record.readBytes())
        assertEquals("synthetic-original", vault.read())
    }

    @Test fun secureClearRemovesKeyAndRecordAndIsIdempotent() {
        vault.write("synthetic-clear")
        vault.clear()
        assertNull(vault.read())
        assertFalse(record.exists())
        assertFalse(KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.containsAlias(alias))
        vault.clear()
        assertNull(SecureTokenVault(context).read())
    }
}
