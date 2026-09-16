package nl.woningtriage.app.data.api

import org.junit.Assert.assertEquals
import org.junit.Test

class FirstJsonDocumentTest {
    @Test
    fun keepsASingleObject() {
        val json = """{"id":"intake_1","confirmed_at":null}"""
        assertEquals(json, firstJsonDocument(json))
    }

    @Test
    fun dropsASecondObjectFromPhpBuiltInServer() {
        val first = """{"id":"intake_1","status":"collecting","confirmed_at":null}"""
        val raw = first + """{"error":{"code":"internal_error","message":"Er ging iets mis."}}"""
        assertEquals(first, firstJsonDocument(raw))
    }

    @Test
    fun ignoresBracesInsideStrings() {
        val first = """{"text":"abc } def"}"""
        assertEquals(first, firstJsonDocument(first + """{"error":true}"""))
    }

    @Test
    fun readsApiErrorMessage() {
        val raw = """{"error":{"code":"invalid_value","message":"Ongeldige postcode.","request_id":"abc"}}"""
        assertEquals("Ongeldige postcode.", parseApiErrorMessage(raw))
    }
}
