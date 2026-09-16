package nl.woningtriage.app.ui

object UiLocale {
    fun fromConversation(tag: String): String {
        val prefix = tag.take(2).lowercase()
        return when (prefix) {
            "nl" -> "nl-NL"
            "en" -> "en-GB"
            "de" -> "de-DE"
            "tr" -> "tr-TR"
            "ja" -> "ja-JP"
            else -> "en-GB"
        }
    }

    fun isSupported(tag: String): Boolean {
        val prefix = tag.take(2).lowercase()
        return prefix in setOf("nl", "en", "de", "tr", "ja")
    }
}
