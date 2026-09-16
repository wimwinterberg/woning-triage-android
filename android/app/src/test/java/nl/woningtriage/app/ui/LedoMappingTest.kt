package nl.woningtriage.app.ui

import kotlinx.coroutines.test.runTest
import nl.woningtriage.app.domain.LedoField
import nl.woningtriage.app.domain.uiStateLabel
import nl.woningtriage.app.voice.FakeVoiceSessionClient
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

class LedoMappingTest {
    @Test
    fun reportedFieldMapsToReportedLabel() {
        assertEquals("reported", LedoField("Keuken", "reported").uiStateLabel())
        assertEquals("unknown", LedoField(null, "unknown").uiStateLabel())
        assertEquals("needs_review", LedoField("Kraan", "needs_review").uiStateLabel())
        assertEquals("missing", LedoField(null, "missing").uiStateLabel())
    }
}

class FakeVoiceMuteTest {
    @Test
    fun muteStopsSendingAudio() = runTest {
        val voice = FakeVoiceSessionClient()
        voice.prepareOffer()
        assert(voice.isSendingAudio)
        voice.setMuted(true)
        assertFalse(voice.isSendingAudio)
        voice.stop()
        assertFalse(voice.isSendingAudio)
    }
}

class IdleTimeoutLabelTest {
    @Test
    fun idleTimeoutReasonClosesPaidSession() {
        assert(isIdleTimeoutClose("idle_timeout"))
        assertFalse(isIdleTimeoutClose("user_stop"))
        assertFalse(isIdleTimeoutClose(null))
    }
}
