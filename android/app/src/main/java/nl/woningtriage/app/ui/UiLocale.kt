package nl.woningtriage.app.ui

object UiLocale {
    fun fromTag(tag: String): String {
        val normalized = tag.replace('_', '-')
        val lower = normalized.lowercase()
        if (lower.startsWith("pap")) return "pap"
        if (lower.startsWith("zgh")) return "zgh"
        return when (lower.take(2)) {
            "nl" -> "nl-NL"
            "en" -> "en-GB"
            "de" -> "de-DE"
            "fr" -> "fr-FR"
            "es" -> "es-ES"
            "tr" -> "tr-TR"
            "ar" -> "ar"
            "pl" -> "pl-PL"
            "ja" -> "ja-JP"
            else -> "en-GB"
        }
    }

    fun fromConversation(tag: String): String = fromTag(tag)

    fun isSupported(tag: String): Boolean {
        val lower = tag.replace('_', '-').lowercase()
        if (lower.startsWith("pap") || lower.startsWith("zgh")) return true
        return lower.take(2) in setOf("nl", "en", "de", "fr", "es", "tr", "ar", "pl", "ja")
    }

    fun isRtl(tag: String): Boolean = tag.replace('_', '-').lowercase().startsWith("ar")
}
