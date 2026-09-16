package nl.woningtriage.app.voice

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFocusRequest
import android.media.AudioManager
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.withTimeoutOrNull
import org.json.JSONObject
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
import org.webrtc.audio.JavaAudioDeviceModule
import java.nio.ByteBuffer
import java.nio.charset.StandardCharsets
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlin.coroutines.suspendCoroutine

/**
 * GPT-Live WebRTC adapter. Microphone first, then `oai-events`, then the offer.
 * @see https://developers.openai.com/api/docs/guides/voice-webrtc
 */
class GptLiveVoiceClient(private val context: Context) : VoiceSessionClient {
    private var factory: PeerConnectionFactory? = null
    private var peerConnection: PeerConnection? = null
    private var audioTrack: AudioTrack? = null
    private var audioSource: AudioSource? = null
    private var audioDeviceModule: JavaAudioDeviceModule? = null
    private var audioFocusRequest: AudioFocusRequest? = null
    private var observer: ConnectionObserver? = null
    private var eventsChannel: DataChannel? = null
    private var pendingOpeningQuestion: String? = null
    private var greetingSent: Boolean = false
    override var isSendingAudio: Boolean = false
        private set

    override suspend fun prepareOffer(): String {
        stop()
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(context).createInitializationOptions(),
        )
        val egl = EglBase.create()
        audioDeviceModule = JavaAudioDeviceModule.builder(context)
            .setUseHardwareAcousticEchoCanceler(true)
            .setUseHardwareNoiseSuppressor(true)
            .createAudioDeviceModule()
            .also { it.setSpeakerMute(false) }
        factory = PeerConnectionFactory.builder()
            .setAudioDeviceModule(audioDeviceModule)
            .setVideoEncoderFactory(DefaultVideoEncoderFactory(egl.eglBaseContext, true, true))
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext))
            .createPeerConnectionFactory()
        val iceServers = listOf(
            PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer(),
        )
        observer = ConnectionObserver()
        peerConnection = factory?.createPeerConnection(PeerConnection.RTCConfiguration(iceServers), observer)
        audioSource = factory?.createAudioSource(MediaConstraints())
        audioTrack = factory?.createAudioTrack("audio0", audioSource)
        audioTrack?.setEnabled(true)
        peerConnection?.addTrack(audioTrack)
        eventsChannel = peerConnection?.createDataChannel("oai-events", DataChannel.Init())
        eventsChannel?.registerObserver(object : DataChannel.Observer {
            override fun onBufferedAmountChange(previousAmount: Long) {}
            override fun onStateChange() {
                trySendGreeting()
            }
            override fun onMessage(buffer: DataChannel.Buffer?) {}
        })
        val offer = awaitSdp { sdpObserver -> peerConnection?.createOffer(sdpObserver, MediaConstraints()) }
        awaitSet { sdpObserver -> peerConnection?.setLocalDescription(sdpObserver, offer) }
        withTimeoutOrNull(1_000) { observer?.iceComplete?.await() }
        isSendingAudio = true
        routePlaybackToSpeaker()
        return peerConnection?.localDescription?.description ?: offer.description
    }

    override suspend fun applyRemoteAnswer(sdpAnswer: String) {
        val answer = SessionDescription(SessionDescription.Type.ANSWER, sdpAnswer)
        awaitSet { sdpObserver -> peerConnection?.setRemoteDescription(sdpObserver, answer) }
        routePlaybackToSpeaker()
        trySendGreeting()
    }

    override fun requestOpeningGreeting(openingQuestion: String) {
        pendingOpeningQuestion = openingQuestion
        trySendGreeting()
    }

    override fun setMuted(muted: Boolean) {
        audioTrack?.setEnabled(!muted)
        isSendingAudio = !muted && audioTrack != null
    }

    override fun stop() {
        isSendingAudio = false
        audioTrack?.setEnabled(false)
        runCatching { eventsChannel?.dispose() }
        runCatching { audioTrack?.dispose() }
        runCatching { audioSource?.dispose() }
        runCatching { peerConnection?.close() }
        runCatching { peerConnection?.dispose() }
        runCatching { factory?.dispose() }
        runCatching { audioDeviceModule?.release() }
        audioTrack = null
        audioSource = null
        peerConnection = null
        factory = null
        audioDeviceModule = null
        observer = null
        eventsChannel = null
        pendingOpeningQuestion = null
        greetingSent = false
        releasePlayback()
    }

    @Synchronized
    private fun trySendGreeting() {
        val channel = eventsChannel ?: return
        val opening = pendingOpeningQuestion ?: return
        if (greetingSent || channel.state() != DataChannel.State.OPEN) {
            return
        }
        val spoken = LiveGreeting.spoken(opening)
        sendLiveEvent(channel, "session.instructions.append", "android_greet_instructions", LiveGreeting.instructions(spoken))
        sendLiveEvent(channel, "session.commentary.append", "android_greet_commentary", LiveGreeting.commentary(spoken))
        greetingSent = true
    }

    private fun sendLiveEvent(channel: DataChannel, type: String, eventId: String, content: String) {
        val json = JSONObject()
            .put("type", type)
            .put("event_id", eventId)
            .put("delegation_id", JSONObject.NULL)
            .put("content", content)
            .toString()
        val buffer = DataChannel.Buffer(ByteBuffer.wrap(json.toByteArray(StandardCharsets.UTF_8)), false)
        channel.send(buffer)
    }

    private fun routePlaybackToSpeaker() {
        val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        val request = AudioFocusRequest.Builder(AudioManager.AUDIOFOCUS_GAIN)
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                    .build(),
            )
            .setAcceptsDelayedFocusGain(false)
            .build()
        audioFocusRequest = request
        audioManager.requestAudioFocus(request)
        audioManager.mode = AudioManager.MODE_IN_COMMUNICATION
        audioManager.isSpeakerphoneOn = true
        audioDeviceModule?.setSpeakerMute(false)
    }

    private fun releasePlayback() {
        val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        audioFocusRequest?.let { runCatching { audioManager.abandonAudioFocusRequest(it) } }
        audioFocusRequest = null
        audioManager.mode = AudioManager.MODE_NORMAL
        audioManager.isSpeakerphoneOn = false
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

    private class ConnectionObserver : PeerConnection.Observer {
        val iceComplete = CompletableDeferred<Unit>()

        override fun onSignalingChange(state: PeerConnection.SignalingState?) {}
        override fun onIceConnectionChange(state: PeerConnection.IceConnectionState?) {}
        override fun onIceConnectionReceivingChange(receiving: Boolean) {}
        override fun onIceGatheringChange(state: PeerConnection.IceGatheringState?) {
            if (state == PeerConnection.IceGatheringState.COMPLETE) {
                iceComplete.complete(Unit)
            }
        }
        override fun onIceCandidate(candidate: IceCandidate?) {}
        override fun onIceCandidatesRemoved(candidates: Array<out IceCandidate>?) {}
        override fun onAddStream(stream: MediaStream?) {
            stream?.audioTracks?.forEach { track ->
                track.setEnabled(true)
                track.setVolume(1.0)
            }
        }
        override fun onRemoveStream(stream: MediaStream?) {}
        override fun onDataChannel(channel: DataChannel?) {}
        override fun onRenegotiationNeeded() {}
        override fun onAddTrack(receiver: RtpReceiver?, streams: Array<out MediaStream>?) {
            val track = receiver?.track() ?: return
            track.setEnabled(true)
            if (track is AudioTrack) {
                track.setVolume(1.0)
            }
        }
    }
}
