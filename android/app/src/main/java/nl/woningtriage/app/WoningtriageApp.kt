package nl.woningtriage.app

import android.app.Application
import nl.woningtriage.app.data.api.TokenStore
import nl.woningtriage.app.data.api.WoningtriageApi
import nl.woningtriage.app.data.api.createApi
import nl.woningtriage.app.voice.FakeVoiceSessionClient
import nl.woningtriage.app.voice.GptLiveVoiceClient
import nl.woningtriage.app.voice.VoiceSessionClient

class WoningtriageApp : Application() {
    lateinit var tokenStore: TokenStore
        private set
    lateinit var api: WoningtriageApi
        private set
    lateinit var voiceClient: VoiceSessionClient
        private set

    override fun onCreate() {
        super.onCreate()
        tokenStore = TokenStore(this)
        api = createApi(tokenStore)
        voiceClient = runCatching { GptLiveVoiceClient(this) }.getOrElse { FakeVoiceSessionClient() }
    }
}
