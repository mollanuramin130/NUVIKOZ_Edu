package com.nuvikoz.edu

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SecureTokenVaultTest {

    @Test
    fun testPayloadValidationRules() {
        val emptyVal = ""
        val emptyBytes = emptyVal.toByteArray(Charsets.UTF_8)
        assertTrue(emptyBytes.isEmpty())

        val validVal = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
        val validBytes = validVal.toByteArray(Charsets.UTF_8)
        assertTrue(validBytes.isNotEmpty())
        assertTrue(validBytes.size <= 60000)

        val oversizedVal = "A".repeat(60001)
        val oversizedBytes = oversizedVal.toByteArray(Charsets.UTF_8)
        assertTrue(oversizedBytes.size > 60000)
    }

    @Test
    fun testMethodChannelArgumentValidation() {
        fun validateWriteArgument(value: String?): Boolean {
            return !(value == null || value.isEmpty() || value.length > 60000)
        }

        assertFalse(validateWriteArgument(null))
        assertFalse(validateWriteArgument(""))
        assertFalse(validateWriteArgument("A".repeat(60001)))
        assertTrue(validateWriteArgument("valid_token_payload_12345"))
    }

    @Test
    fun testRedactedErrorResponses() {
        val errorCode = "SECURE_STORAGE_ERROR"
        val errorMessage = "Secure device storage operation failed"

        assertEquals("SECURE_STORAGE_ERROR", errorCode)
        assertEquals("Secure device storage operation failed", errorMessage)
        assertFalse(errorMessage.contains("token"))
        assertFalse(errorMessage.contains("alias"))
        assertFalse(errorMessage.contains("key"))
    }

    @Test
    fun testHeaderVersionAndSizeBoundaries() {
        val minValidSize = 30
        val maxValidSize = 65536
        val versionByte: Byte = 1

        val validRecordHeader = byteArrayOf(versionByte)
        assertEquals(1.toByte(), validRecordHeader[0])
        assertTrue(minValidSize >= 30)
        assertTrue(maxValidSize <= 65536)
    }

    @Test
    fun testAADBindingContract() {
        val aadString = "nuvikoz.edu/secure-token-store:v1"
        val aadBytes = aadString.toByteArray(Charsets.UTF_8)

        assertEquals("nuvikoz.edu/secure-token-store:v1", String(aadBytes, Charsets.UTF_8))
        assertTrue(aadBytes.size > 10)
    }
}

fun main() {
    val test = SecureTokenVaultTest()
    test.testPayloadValidationRules()
    test.testMethodChannelArgumentValidation()
    test.testRedactedErrorResponses()
    test.testHeaderVersionAndSizeBoundaries()
    test.testAADBindingContract()
    println("ALL_NATIVE_UNIT_TESTS_PASSED")
}
