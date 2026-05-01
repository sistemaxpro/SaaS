package pro.sistemax.main

import android.content.Context
import android.media.projection.MediaProjection
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import org.json.JSONObject
import org.webrtc.AudioSource
import org.webrtc.AudioTrack
import org.webrtc.CapturerObserver
import org.webrtc.DefaultVideoDecoderFactory
import org.webrtc.DefaultVideoEncoderFactory
import org.webrtc.EglBase
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStream
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpReceiver
import org.webrtc.RtpTransceiver
import org.webrtc.ScreenCapturerAndroid
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import org.webrtc.SurfaceTextureHelper
import org.webrtc.VideoSource
import org.webrtc.VideoTrack
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

class AssistWebRtcManager(
    context: Context,
    private val scope: CoroutineScope,
    private val apiUrlProvider: () -> String,
    private val agentTokenProvider: () -> String,
    private val onStatus: (String) -> Unit
) {

    private val appContext = context.applicationContext
    private val eglBase: EglBase = EglBase.create()
    private val factory: PeerConnectionFactory

    private var peerConnection: PeerConnection? = null
    private var surfaceTextureHelper: SurfaceTextureHelper? = null
    private var screenCapturer: ScreenCapturerAndroid? = null
    private var videoSource: VideoSource? = null
    private var videoTrack: VideoTrack? = null
    private var audioSource: AudioSource? = null
    private var audioTrack: AudioTrack? = null
    private var currentSessionId: Long = 0L

    init {
        PeerConnectionFactory.initialize(
            PeerConnectionFactory.InitializationOptions.builder(appContext)
                .createInitializationOptions()
        )
        factory = PeerConnectionFactory.builder()
            .setVideoEncoderFactory(DefaultVideoEncoderFactory(eglBase.eglBaseContext, true, true))
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(eglBase.eglBaseContext))
            .createPeerConnectionFactory()
    }

    suspend fun handleOffer(sessionId: Long, payload: JSONObject) {
        val sdp = payload.optString("sdp").trim()
        if (sdp.isBlank()) throw IllegalStateException("Offer sin SDP")
        ensurePeerConnection(sessionId)
        val pc = peerConnection ?: throw IllegalStateException("PeerConnection no disponible")
        onStatus("Aplicando offer de la sesión #$sessionId")
        setRemoteDescription(pc, SessionDescription(SessionDescription.Type.OFFER, sdp))
        val answer = createAnswer(pc)
        setLocalDescription(pc, answer)
        pushSignal(sessionId, "answer", JSONObject().apply {
            put("type", "answer")
            put("sdp", answer.description ?: "")
        })
        onStatus("Answer enviada para sesión #$sessionId")
    }

    suspend fun addIceCandidate(payload: JSONObject) {
        val candidate = payload.optString("candidate").trim()
        if (candidate.isBlank()) return
        val sdpMid = payload.optString("sdpMid").ifBlank { null }
        peerConnection?.addIceCandidate(
            IceCandidate(
                sdpMid,
                payload.optInt("sdpMLineIndex", 0),
                candidate
            )
        )
    }

    suspend fun ensurePeerConnection(sessionId: Long) {
        if (peerConnection != null && currentSessionId == sessionId) return
        stop()
        currentSessionId = sessionId
        peerConnection = buildPeerConnection(sessionId)
        startLocalTracks(peerConnection ?: throw IllegalStateException("No se pudo crear peer"))
    }

    fun stop() {
        runCatching { screenCapturer?.stopCapture() }
        runCatching { screenCapturer?.dispose() }
        runCatching { videoTrack?.dispose() }
        runCatching { videoSource?.dispose() }
        runCatching { audioTrack?.dispose() }
        runCatching { audioSource?.dispose() }
        runCatching { surfaceTextureHelper?.dispose() }
        runCatching { peerConnection?.close() }
        screenCapturer = null
        videoTrack = null
        videoSource = null
        audioTrack = null
        audioSource = null
        surfaceTextureHelper = null
        peerConnection = null
        currentSessionId = 0L
    }

    private fun buildPeerConnection(sessionId: Long): PeerConnection {
        val config = PeerConnection.RTCConfiguration(
            listOf(PeerConnection.IceServer.builder("stun:stun.l.google.com:19302").createIceServer())
        ).apply {
            sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN
        }

        return factory.createPeerConnection(config, object : PeerConnection.Observer {
            override fun onSignalingChange(newState: PeerConnection.SignalingState?) = Unit
            override fun onIceConnectionChange(newState: PeerConnection.IceConnectionState?) {
                onStatus("ICE: ${newState?.name ?: "unknown"}")
            }
            override fun onIceConnectionReceivingChange(receiving: Boolean) = Unit
            override fun onIceGatheringChange(newState: PeerConnection.IceGatheringState?) = Unit
            override fun onIceCandidate(candidate: IceCandidate?) {
                if (candidate == null) return
                scope.launch(Dispatchers.IO) {
                    runCatching {
                        pushSignal(sessionId, "ice-candidate", JSONObject().apply {
                            put("candidate", candidate.sdp)
                            put("sdpMid", candidate.sdpMid ?: JSONObject.NULL)
                            put("sdpMLineIndex", candidate.sdpMLineIndex)
                        })
                    }.onFailure {
                        onStatus("No se pudo enviar ICE: ${it.message ?: "error"}")
                    }
                }
            }
            override fun onIceCandidatesRemoved(candidates: Array<out IceCandidate>?) = Unit
            override fun onAddStream(stream: MediaStream?) = Unit
            override fun onRemoveStream(stream: MediaStream?) = Unit
            override fun onDataChannel(dataChannel: org.webrtc.DataChannel?) = Unit
            override fun onRenegotiationNeeded() = Unit
            override fun onAddTrack(receiver: RtpReceiver?, mediaStreams: Array<out MediaStream>?) = Unit
            override fun onTrack(transceiver: RtpTransceiver?) = Unit
            override fun onConnectionChange(newState: PeerConnection.PeerConnectionState?) {
                onStatus("Peer: ${newState?.name ?: "unknown"}")
            }
            override fun onSelectedCandidatePairChanged(event: org.webrtc.CandidatePairChangeEvent?) = Unit
            override fun onStandardizedIceConnectionChange(newState: PeerConnection.IceConnectionState?) = Unit
        }) ?: throw IllegalStateException("No se pudo crear PeerConnection")
    }

    private fun startLocalTracks(pc: PeerConnection) {
        val resultData = AssistMediaProjectionStore.resultData
            ?: throw IllegalStateException("Primero habilitá Compartir pantalla")

        surfaceTextureHelper = SurfaceTextureHelper.create("AssistCaptureThread", eglBase.eglBaseContext)
        screenCapturer = ScreenCapturerAndroid(
            resultData,
            object : MediaProjection.Callback() {}
        )
        videoSource = factory.createVideoSource(true)
        val capturerObserver: CapturerObserver = videoSource!!.capturerObserver
        screenCapturer!!.initialize(surfaceTextureHelper, appContext, capturerObserver)
        screenCapturer!!.startCapture(720, 1280, 12)
        videoTrack = factory.createVideoTrack("assist-video-$currentSessionId", videoSource)
        audioSource = factory.createAudioSource(MediaConstraints())
        audioTrack = factory.createAudioTrack("assist-audio-$currentSessionId", audioSource)
        pc.addTrack(videoTrack, listOf("assist-stream"))
        pc.addTrack(audioTrack, listOf("assist-stream"))
        videoTrack?.setEnabled(true)
        audioTrack?.setEnabled(false)
        onStatus("Captura WebRTC lista para sesión #$currentSessionId")
    }

    private suspend fun createAnswer(pc: PeerConnection): SessionDescription {
        return suspendCancellableCoroutine { continuation ->
            pc.createAnswer(object : SdpObserver {
                override fun onCreateSuccess(sessionDescription: SessionDescription?) {
                    if (sessionDescription == null) {
                        continuation.resumeWithException(IllegalStateException("No se generó answer"))
                    } else {
                        continuation.resume(sessionDescription)
                    }
                }
                override fun onSetSuccess() = Unit
                override fun onCreateFailure(error: String?) {
                    continuation.resumeWithException(IllegalStateException(error ?: "No se pudo crear answer"))
                }
                override fun onSetFailure(error: String?) = Unit
            }, MediaConstraints())
        }
    }

    private suspend fun setRemoteDescription(pc: PeerConnection, description: SessionDescription) {
        return suspendCancellableCoroutine { continuation ->
            pc.setRemoteDescription(object : SdpObserver {
                override fun onSetSuccess() {
                    continuation.resume(Unit)
                }
                override fun onSetFailure(error: String?) {
                    continuation.resumeWithException(IllegalStateException(error ?: "No se pudo aplicar offer"))
                }
                override fun onCreateSuccess(sessionDescription: SessionDescription?) = Unit
                override fun onCreateFailure(error: String?) = Unit
            }, description)
        }
    }

    private suspend fun setLocalDescription(pc: PeerConnection, description: SessionDescription) {
        return suspendCancellableCoroutine { continuation ->
            pc.setLocalDescription(object : SdpObserver {
                override fun onSetSuccess() {
                    continuation.resume(Unit)
                }
                override fun onSetFailure(error: String?) {
                    continuation.resumeWithException(IllegalStateException(error ?: "No se pudo aplicar answer"))
                }
                override fun onCreateSuccess(sessionDescription: SessionDescription?) = Unit
                override fun onCreateFailure(error: String?) = Unit
            }, description)
        }
    }

    private fun pushSignal(sessionId: Long, signalType: String, payload: JSONObject) {
        val token = agentTokenProvider().trim()
        if (token.isBlank()) throw IllegalStateException("Agente sin token")
        TrackingApiClient(apiUrlProvider()).pushSignal(token, sessionId, signalType, payload)
    }
}
