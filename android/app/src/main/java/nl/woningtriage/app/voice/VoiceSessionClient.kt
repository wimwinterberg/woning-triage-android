package nl.woningtriage.app.voice

interface VoiceSessionClient {
    suspend fun prepareOffer(): String
    suspend fun applyRemoteAnswer(sdpAnswer: String)
    fun requestOpeningGreeting(openingQuestion: String, language: String = "nl-NL") {}
    fun speakFollowUp(thankYou: String, nextQuestion: String, language: String) {}
    fun setOnConnectionLost(listener: (() -> Unit)?) {}
    fun setMuted(muted: Boolean)
    fun stop()
    val isSendingAudio: Boolean
}

class FakeVoiceSessionClient : VoiceSessionClient {
    override var isSendingAudio: Boolean = false
        private set

    override suspend fun prepareOffer(): String {
        isSendingAudio = true
        return "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=Woningtriage\r\nt=0 0\r\n"
    }

    override suspend fun applyRemoteAnswer(sdpAnswer: String) {
        isSendingAudio = true
    }

    override fun setMuted(muted: Boolean) {
        isSendingAudio = !muted
    }

    override fun stop() {
        isSendingAudio = false
    }
}
