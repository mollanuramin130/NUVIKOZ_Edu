package com.nuvikoz.edu

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.AtomicFile
import java.io.File
import java.nio.ByteBuffer
import java.nio.charset.CodingErrorAction
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey

/** Android-only encrypted persistence. Errors propagate; no plaintext fallback. */
class SecureTokenVault(context: Context) {
    private val file = AtomicFile(File(context.noBackupFilesDir, "edu-auth-v1.bin"))
    private val alias = "nuvikoz.edu.auth.v1"
    private val aad = "nuvikoz.edu/secure-token-store:v1".toByteArray(Charsets.UTF_8)
    private fun keys() = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    private fun key(create: Boolean): SecretKey {
        val store = keys()
        val existing = store.getKey(alias, null)
        if (existing != null) return existing as SecretKey
        check(create) { "Secure storage key unavailable" }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
            init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true).setKeySize(256).build())
            generateKey()
        }
    }
    @Synchronized fun read(): String? {
        val bytes = try { file.openRead().use { it.readNBytes(65537) } }
            catch (e: java.io.FileNotFoundException) { return null }
        check(bytes.size in 30..65536 && bytes[0] == 1.toByte()) { "Invalid secure storage" }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key(false), javax.crypto.spec.GCMParameterSpec(128, bytes.copyOfRange(1, 13)))
        cipher.updateAAD(aad)
        val plain = cipher.doFinal(bytes.copyOfRange(13, bytes.size))
        return try {
            Charsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT).decode(ByteBuffer.wrap(plain)).toString()
        } finally { plain.fill(0) }
    }
    @Synchronized fun write(value: String) {
        val plain = value.toByteArray(Charsets.UTF_8)
        require(plain.isNotEmpty() && plain.size <= 60000)
        // Never silently generate a replacement key over an existing unreadable record.
        if (file.baseFile.exists()) read()
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key(true))
        cipher.updateAAD(aad)
        val record = try { byteArrayOf(1) + cipher.iv + cipher.doFinal(plain) }
            finally { plain.fill(0) }
        check(cipher.iv.size == 12)
        val stream = file.startWrite()
        try { stream.write(record); stream.fd.sync(); file.finishWrite(stream) }
        catch (e: Exception) { file.failWrite(stream); throw e }
    }
    @Synchronized fun clear() {
        // Delete the key first: any interrupted clear leaves ciphertext unusable.
        keys().deleteEntry(alias)
        file.delete()
        check(!file.baseFile.exists()) { "Secure storage deletion failed" }
    }
}
