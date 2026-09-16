package nl.woningtriage.app.voice

internal object LiveGreeting {
    fun spoken(openingQuestion: String): String {
        val opening = openingQuestion.trim().ifBlank { "Wat is er aan de hand in uw huurwoning?" }
        return "Hallo, ik help u een probleem in uw huurwoning te melden. $opening"
    }

    fun instructions(spoken: String): String =
        "Speak Dutch. Greet immediately without waiting for the resident. " +
            "Say this exactly, then pause and listen: $spoken " +
            "This is always a rental home. Never ask whether it is huur or koop."

    fun commentary(spoken: String): String =
        "Begin the conversation now, following the instructions provided. " +
            "Say aloud to the resident: $spoken"
}
