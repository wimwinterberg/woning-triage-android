package nl.woningtriage.app.voice

internal object LiveGreeting {
    fun spoken(openingQuestion: String, language: String = "nl-NL"): String {
        val opening = openingQuestion.trim().ifBlank { "Wat is er aan de hand in uw huurwoning?" }
        val hello = when (language.take(2).lowercase()) {
            "en" -> "Hello, I will help you report a problem in your rental home. "
            "de" -> "Hallo, ich helfe Ihnen, ein Problem in Ihrer Mietwohnung zu melden. "
            "fr" -> "Bonjour, je vous aide à signaler un problème dans votre logement locatif. "
            "es" -> "Hola, le ayudo a informar de un problema en su vivienda de alquiler. "
            "tr" -> "Merhaba, kiralık evinizdeki bir sorunu bildirmenize yardımcı olurum. "
            "ar" -> "مرحباً، سأساعدك في الإبلاغ عن مشكلة في مسكنك المستأجر. "
            "pl" -> "Dzień dobry, pomogę zgłosić problem w Pana/Pani mieszkaniu na wynajem. "
            "ja" -> "こんにちは。賃貸住宅の不具合の届出をお手伝いします。"
            else -> when {
                language.lowercase().startsWith("pap") -> "Bon dia, mi ta yuda bo raporta un problema den bo cas di hür. "
                language.lowercase().startsWith("zgh") -> "Azul, ad k-ɛawneɣ ad tmelḍ ugur deg taddart-nnek n ukru. "
                else -> "Hallo, ik help u een probleem in uw huurwoning te melden. "
            }
        }
        return hello + opening
    }

    fun instructions(spoken: String, language: String = "nl-NL"): String {
        val greet = if (language.startsWith("nl")) {
            "Greet immediately in Dutch without waiting for the resident. "
        } else {
            "Greet immediately in the resident language without waiting. Do not greet in Dutch. "
        }
        return greet +
            "Say this exactly, then pause and listen: $spoken " +
            "After that greeting, follow the resident language. If they speak a clear sentence in another language, reply in that language immediately and stay there. " +
            "Do not switch back to Dutch after they change language. Loanwords such as okay do not count as a language switch. " +
            "If the backend asks whether to switch the app screens, say that question and wait for yes or no. " +
            "Keep the same voice and a steady speaking speed; do not change voice, accent or pace. " +
            "This is always a rental home. Never ask whether it is huur or koop."
    }

    fun commentary(spoken: String): String =
        "Begin the conversation now, following the instructions provided. " +
            "Say aloud to the resident: $spoken"
}
