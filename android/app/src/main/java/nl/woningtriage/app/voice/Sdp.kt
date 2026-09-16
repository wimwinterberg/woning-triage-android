package nl.woningtriage.app.voice

internal object Sdp {
    fun mLineKinds(sdp: String): List<String> =
        sdp.lineSequence()
            .map { it.trim() }
            .filter { it.startsWith("m=") }
            .map { it.removePrefix("m=").substringBefore(" ") }
            .toList()

    fun canApplyAnswer(offer: String, answer: String): Boolean {
        val offerKinds = mLineKinds(offer)
        val answerKinds = mLineKinds(answer)
        return offerKinds.isNotEmpty() && offerKinds == answerKinds && !answer.contains("WoningtriageFake")
    }
}
