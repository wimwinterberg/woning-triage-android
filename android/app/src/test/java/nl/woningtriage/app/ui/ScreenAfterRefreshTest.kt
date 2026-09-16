package nl.woningtriage.app.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class ScreenAfterRefreshTest {
    @Test
    fun staysOnConversationDuringLiveVoice() {
        assertEquals(
            Screen.Conversation,
            screenAfterRefresh(
                status = "collecting",
                addressVerified = false,
                completedLedoCount = 3,
                current = Screen.Conversation,
                stayInVoiceConversation = true,
            ),
        )
    }

    @Test
    fun opensAddressWhenLedoIsReadyWithoutLiveVoice() {
        assertEquals(
            Screen.Address,
            screenAfterRefresh(
                status = "collecting",
                addressVerified = false,
                completedLedoCount = 3,
                current = Screen.Conversation,
                stayInVoiceConversation = false,
            ),
        )
    }

    @Test
    fun opensReviewWhenReadyForConfirmation() {
        assertEquals(
            Screen.Review,
            screenAfterRefresh(
                status = "ready_for_confirmation",
                addressVerified = true,
                completedLedoCount = 4,
                current = Screen.Conversation,
                stayInVoiceConversation = true,
            ),
        )
    }
}
