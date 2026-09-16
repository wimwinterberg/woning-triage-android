package nl.woningtriage.app.data.api

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class NgrokHostTest {
    @Test
    fun detectsNgrokHosts() {
        assertTrue(isNgrokHost("abc123.ngrok-free.app"))
        assertTrue(isNgrokHost("abc123.ngrok.app"))
        assertFalse(isNgrokHost("10.0.2.2"))
        assertFalse(isNgrokHost("woningtriage.ondigitalocean.app"))
    }
}
