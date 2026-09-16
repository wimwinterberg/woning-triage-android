package nl.woningtriage.app.ui

import org.junit.Assert.assertEquals
import org.junit.Test

class UiLocaleTest {
    @Test
    fun mapsConversationLanguageToUiTag() {
        assertEquals("nl-NL", UiLocale.fromConversation("nl-NL"))
        assertEquals("en-GB", UiLocale.fromConversation("en-GB"))
        assertEquals("de-DE", UiLocale.fromConversation("de-DE"))
        assertEquals("tr-TR", UiLocale.fromConversation("tr-TR"))
        assertEquals("ja-JP", UiLocale.fromConversation("ja-JP"))
        assertEquals("pl-PL", UiLocale.fromConversation("pl-PL"))
        assertEquals("ar", UiLocale.fromConversation("ar-SA"))
        assertEquals("fr-FR", UiLocale.fromConversation("fr"))
        assertEquals("en-GB", UiLocale.fromConversation("it-IT"))
    }
}
