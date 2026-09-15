package nl.woningtriage.app.voice

import android.content.Context
import android.media.AudioManager
import org.webrtc.AudioSource
import org.webrtc.AudioTrack
import org.webrtc.DataChannel
import org.webrtc.DefaultVideoDecoderFactory
import org.webrtc.DefaultVideoEncoderFactory
import org.webrtc.EglBase
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStream
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpReceiver
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlin.coroutines.suspendCoroutine

/**
 * GPT-Live WebRTC adapter. Audio is on media tracks; JSON events on `oai-events`.
 * @see https://developers.openai.com/api/docs/guides/voice-webrtc
 */
class GptLiveVoiceClient(private val context: Context) : VoiceSessionClient {
    private var factory: PeerConnectionFactory? = null
    private var peerConnection: PeerConnection? = null
    private var audioTrack: AudioTrack? = null
    private var audioSource: AudioSource? = null
    override var isSendingAudio: Boolean = false
        private set

    override suspend fun prepareOffer(): String {
        stop()
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(context).createInitializationOptions(),
        )
        val egl = EglBase.create()
        factory = PeerConnectionFactory.builder()
            .setVideoEncoderFactory(DefaultVideoEncoderFactory(egl.eglBaseContext, true, true))
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext))
            .createPeerConnectionFactory()
        val rtcConfig = PeerConnection.RTCConfiguration(emptyList())
        peerConnection = factory?.createPeerConnection(rtcConfig, EmptyObserver)
        peerConnection?.createDataChannel("oai-events", DataChannel.Init())
        audioSource = factory?.createAudioSource(MediaConstraints())
        audioTrack = factory?.createAudioTrack("audio0", audioSource)
        audioTrack?.setEnabled(true)
        peerConnection?.addTrack(audioTrack)
        val offer = awaitSdp { observer -> peerConnection?.createOffer(observer, MediaConstraints()) }
        awaitSet { observer -> peerConnection?.setLocalDescription(observer, offer) }
        isSendingAudio = true
        val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
        return offer.description
    }

    override suspend fun applyRemoteAnswer(sdpAnswer: String) {
        val answer = SessionDescription(SessionDescription.Type.ANSWER, sdpAnswer)
        awaitSet { observer -> peerConnection?.setRemoteDescription(observer, answer) }
    }

    override fun setMuted(muted: Boolean) {
        audioTrack?.setEnabled(!muted)
        isSendingAudio = !muted && audioTrack != null
    }

    override fun stop() {
        isSendingAudio = false
        audioTrack?.setEnabled(false)
        runCatching { audioTrack?.dispose() }
        runCatching { audioSource?.dispose() }
        runCatching { peerConnection?.close() }
        runCatching { peerConnection?.dispose() }
        runCatching { factory?.dispose() }
        audioTrack = null
        audioSource = null
        peerConnection = null
        factory = null
        val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        audioManager.mode = AudioManager.MODE_NORMAL
    }

    private suspend fun awaitSdp(block: (SdpObserver) -> Unit): SessionDescription =
        suspendCoroutine { cont ->
            block(object : SdpObserver {
                override fun onCreateSuccess(sdp: SessionDescription?) {
                    if (sdp != null) cont.resume(sdp) else cont.resumeWithException(IllegalStateException("SDP missing"))
                }
                override fun onSetSuccess() {}
                override fun onCreateFailure(error: String?) {
                    cont.resumeWithException(IllegalStateException(error ?: "SDP create failed"))
                }
                override fun onSetFailure(error: String?) {}
            })
        }

    private suspend fun awaitSet(block: (SdpObserver) -> Unit) = suspendCoroutine { cont ->
        block(object : SdpObserver {
            override fun onCreateSuccess(sdp: SessionDescription?) {}
            override fun onSetSuccess() { cont.resume(Unit) }
            override fun onCreateFailure(error: String?) {}
            override fun onSetFailure(error: String?) {
                cont.resumeWithException(IllegalStateException(error ?: "SDP set failed"))
            }
        })
    }

    private object EmptyObserver : PeerConnection.Observer {
        override fun onSignalingChange(state: PeerConnection.SignalingState?) {}
        override fun onIceConnectionChange(state: PeerConnection.IceConnectionState?) {}
        override fun onIceConnectionReceivingChange(receiving: Boolean) {}
        override fun onIceGatheringChange(state: PeerConnection.IceGatheringState?) {}
        override fun onIceCandidate(candidate: IceCandidate?) {}
        override fun onIceCandidatesRemoved(candidates: Array<out IceCandidate>?) {}
        override fun onAddStream(stream: MediaStream?) {}
        override fun onRemoveStream(stream: MediaStream?) {}
        override fun onDataChannel(channel: DataChannel?) {}
        override fun onRenegotiationNeeded() {}
        override fun onAddTrack(receiver: RtpReceiver?, streams: Array<out MediaStream>?) {}
    }
}
