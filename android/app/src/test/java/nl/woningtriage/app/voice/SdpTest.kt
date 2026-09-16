package nl.woningtriage.app.voice

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SdpTest {
    private val offer = """
        v=0
        m=audio 9 UDP/TLS/RTP/SAVPF 111
        m=application 9 UDP/DTLS/SCTP webrtc-datachannel
    """.trimIndent()

    @Test
    fun rejectsFakeAnswer() {
        val fake = """
            v=0
            s=WoningtriageFake
            m=audio 9 UDP/TLS/RTP/SAVPF 111
            m=application 9 UDP/DTLS/SCTP webrtc-datachannel
        """.trimIndent()
        assertFalse(Sdp.canApplyAnswer(offer, fake))
    }

    @Test
    fun acceptsMatchingLiveAnswer() {
        val answer = """
            v=0
            s=GPTLive
            m=audio 9 UDP/TLS/RTP/SAVPF 111
            m=application 9 UDP/DTLS/SCTP webrtc-datachannel
        """.trimIndent()
        assertTrue(Sdp.canApplyAnswer(offer, answer))
    }

    @Test
    fun rejectsMismatchedMLines() {
        val answer = """
            v=0
            m=audio 9 UDP/TLS/RTP/SAVPF 111
        """.trimIndent()
        assertFalse(Sdp.canApplyAnswer(offer, answer))
    }
}
