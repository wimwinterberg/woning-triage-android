package nl.woningtriage.app.ui

data class SupportedLanguage(
    val tag: String,
    val nativeName: String,
    val nameNl: String,
)

object SupportedLanguages {
    val all: List<SupportedLanguage> = listOf(
        SupportedLanguage("nl-NL", "Nederlands", "Nederlands"),
        SupportedLanguage("en-GB", "English", "Engels"),
        SupportedLanguage("de-DE", "Deutsch", "Duits"),
        SupportedLanguage("fr-FR", "Français", "Frans"),
        SupportedLanguage("es-ES", "Español", "Spaans"),
        SupportedLanguage("tr-TR", "Türkçe", "Turks"),
        SupportedLanguage("ar", "العربية", "Arabisch"),
        SupportedLanguage("pl-PL", "Polski", "Pools"),
        SupportedLanguage("pap", "Papiamentu", "Papiaments"),
        SupportedLanguage("zgh", "Tamazight", "Berbers (Tamazight)"),
        SupportedLanguage("ja-JP", "日本語", "Japans"),
    )

    fun nativeName(tag: String): String =
        all.firstOrNull { it.tag.equals(UiLocale.fromTag(tag), ignoreCase = true) }?.nativeName ?: tag
}
