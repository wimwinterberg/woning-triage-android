package nl.woningtriage.app.voice

internal object LiveFollowUp {
    fun addressVerified(thankYou: String, nextQuestion: String, language: String): String {
        val pace = "Keep the same voice and a steady speaking speed. Do not change voice, accent or pace."
        val lock = if (language.startsWith("nl")) {
            "Reply in the language the resident is using."
        } else {
            "Speak only in the resident language now. Do not speak Dutch."
        }
        return "$pace $lock First say this thank-you exactly, do not skip it: $thankYou Then ask this next question: $nextQuestion"
    }
}
