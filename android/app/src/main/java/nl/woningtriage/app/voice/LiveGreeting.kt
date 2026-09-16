package nl.woningtriage.app.voice

internal object LiveGreeting {
    fun spoken(openingQuestion: String): String {
        val opening = openingQuestion.trim().ifBlank { "Wat is er aan de hand in uw huurwoning?" }
        return "Hallo, ik help u een probleem in uw huurwoning te melden. $opening"
    }

    fun instructions(spoken: String): String =
        "Greet immediately in Dutch without waiting for the resident. " +
            "Say this exactly, then pause and listen: $spoken " +
            "After that greeting, follow the resident language. If they speak a clear sentence in English, German, Turkish, Japanese or another language, reply in that language immediately and stay there. " +
            "Do not switch back to Dutch after they change language. Loanwords such as okay do not count as a language switch. " +
            "Keep the same voice and a steady speaking speed; do not change voice, accent or pace. " +
            "This is always a rental home. Never ask whether it is huur or koop."

    fun commentary(spoken: String): String =
        "Begin the conversation now, following the instructions provided. " +
            "Say aloud to the resident: $spoken"
}
