package nl.woningtriage.app.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import nl.woningtriage.app.data.api.AddressLookupRequest
import nl.woningtriage.app.data.api.AddressVerifyRequest
import nl.woningtriage.app.data.api.ConfirmationRequest
import nl.woningtriage.app.data.api.CreateIntakeRequest
import nl.woningtriage.app.data.api.FieldChange
import nl.woningtriage.app.data.api.FieldPatchRequest
import nl.woningtriage.app.data.api.MessageRequest
import nl.woningtriage.app.data.api.RevisionRequest
import nl.woningtriage.app.data.api.TokenStore
import nl.woningtriage.app.data.api.VoiceStartRequest
import nl.woningtriage.app.data.api.WoningtriageApi
import nl.woningtriage.app.domain.Intake
import nl.woningtriage.app.voice.VoiceSessionClient
import java.util.UUID

data class TranscriptLine(val speaker: String, val text: String)

data class AppUiState(
    val hasToken: Boolean,
    val screen: Screen = Screen.Start,
    val intake: Intake? = null,
    val transcript: List<TranscriptLine> = emptyList(),
    val draft: String = "",
    val error: String? = null,
    val busy: Boolean = false,
    val micMuted: Boolean = false,
    val voiceConnected: Boolean = false,
    val connectionLabel: String = "disconnected",
    val activationCode: String = "",
    val postcode: String = "",
    val houseNumber: String = "",
    val addition: String = "",
    val editingField: String? = null,
    val editingValue: String = "",
    val preferTyping: Boolean = false,
    val voiceSessionId: String? = null,
)

enum class Screen { Activation, Start, Conversation, Address, Review, Completed, ReviewRequired, FieldEdit }

class AppViewModel(
    private val api: WoningtriageApi,
    private val tokens: TokenStore,
    private val voice: VoiceSessionClient,
) : ViewModel() {
    private val _state = MutableStateFlow(
        AppUiState(hasToken = tokens.accessToken != null, screen = if (tokens.accessToken == null) Screen.Activation else Screen.Start),
    )
    val state: StateFlow<AppUiState> = _state
    private var confirmKey: String? = null

    fun onCode(value: String) { _state.value = _state.value.copy(activationCode = value) }
    fun onDraft(value: String) { _state.value = _state.value.copy(draft = value) }
    fun onPostcode(value: String) { _state.value = _state.value.copy(postcode = value) }
    fun onHouseNumber(value: String) { _state.value = _state.value.copy(houseNumber = value) }
    fun onAddition(value: String) { _state.value = _state.value.copy(addition = value) }
    fun onEditValue(value: String) { _state.value = _state.value.copy(editingValue = value) }

    fun activate() = run("activation") {
        val result = api.activate(UUID.randomUUID().toString(), nl.woningtriage.app.data.api.ActivationRequest(_state.value.activationCode.trim()))
        tokens.accessToken = result.accessToken
        _state.value = _state.value.copy(hasToken = true, screen = Screen.Start, error = null)
    }

    fun startIntake(voiceMode: Boolean) = run("start") {
        stopVoiceInternal()
        val intake = api.createIntake(UUID.randomUUID().toString(), CreateIntakeRequest(if (voiceMode) "voice" else "text"))
        tokens.activeIntakeId = intake.id
        _state.value = _state.value.copy(
            intake = intake,
            preferTyping = !voiceMode,
            screen = Screen.Conversation,
            transcript = listOfNotNull(intake.nextQuestion?.text?.let { TranscriptLine("assistant", it) }),
            connectionLabel = if (voiceMode) "connecting" else "disconnected",
        )
        if (voiceMode) {
            startVoice(intake.id)
        }
    }

    fun resumeIntake() = run("resume") {
        val id = tokens.activeIntakeId ?: return@run
        val intake = api.getIntake(id)
        _state.value = _state.value.copy(intake = intake, screen = screenFor(intake), error = null)
    }

    fun sendDraft() = run("message") {
        val intake = _state.value.intake ?: return@run
        val text = _state.value.draft.trim()
        if (text.isEmpty()) return@run
        _state.value = _state.value.copy(transcript = _state.value.transcript + TranscriptLine("resident", text), draft = "", connectionLabel = "processing")
        api.sendMessage(
            intake.id,
            UUID.randomUUID().toString(),
            MessageRequest(intake.revision, "message_" + UUID.randomUUID().toString().replace("-", "").take(16), text),
        )
        refresh(intake.id)
    }

    fun lookupAddress() = run("address") {
        val intake = _state.value.intake ?: return@run
        val number = _state.value.houseNumber.toIntOrNull() ?: return@run
        api.lookupAddress(
            intake.id,
            UUID.randomUUID().toString(),
            AddressLookupRequest(intake.revision, _state.value.postcode, number, _state.value.addition.ifBlank { null }),
        )
        refresh(intake.id)
        _state.value = _state.value.copy(screen = Screen.Address)
    }

    fun verifyCandidate(candidateId: String) = run("verify") {
        val intake = _state.value.intake ?: return@run
        val address = intake.address ?: return@run
        api.verifyAddress(
            intake.id,
            UUID.randomUUID().toString(),
            AddressVerifyRequest(
                expectedRevision = intake.revision,
                lookupId = address.lookupId.orEmpty(),
                candidateId = candidateId,
                addressRevision = address.addressRevision ?: 0,
            ),
        )
        refresh(intake.id)
    }

    fun requestSummary() = run("summary") {
        val intake = _state.value.intake ?: return@run
        api.requestSummary(intake.id, UUID.randomUUID().toString(), RevisionRequest(intake.revision))
        refresh(intake.id)
        _state.value = _state.value.copy(screen = Screen.Review)
    }

    fun confirm() = run("confirm") {
        val intake = _state.value.intake ?: return@run
        val summaryId = intake.summary?.id ?: return@run
        val key = confirmKey ?: UUID.randomUUID().toString().also { confirmKey = it }
        api.confirm(intake.id, key, ConfirmationRequest(intake.revision, summaryId, "ui"))
        refresh(intake.id)
        _state.value = _state.value.copy(screen = Screen.Completed, voiceConnected = false, connectionLabel = "disconnected")
        stopVoiceInternal()
    }

    fun openField(field: String) {
        val current = _state.value.intake?.fields?.get(field)?.value.orEmpty()
        _state.value = _state.value.copy(editingField = field, editingValue = current, screen = Screen.FieldEdit)
    }

    fun submitField(action: String) = run("field") {
        val intake = _state.value.intake ?: return@run
        val field = _state.value.editingField ?: return@run
        val value = _state.value.editingValue.trim()
        api.patchFields(
            intake.id,
            UUID.randomUUID().toString(),
            FieldPatchRequest(intake.revision, listOf(FieldChange(field, action, if (action == "set") value else null))),
        )
        refresh(intake.id)
        _state.value = _state.value.copy(screen = Screen.Conversation, editingField = null)
    }

    fun toggleMute() {
        val muted = !_state.value.micMuted
        voice.setMuted(muted)
        _state.value = _state.value.copy(
            micMuted = muted,
            connectionLabel = if (muted) "mic_off" else if (_state.value.voiceConnected) "connected" else _state.value.connectionLabel,
        )
    }

    fun stopConversation() = run("stop") {
        stopVoiceInternal()
        _state.value = _state.value.copy(voiceConnected = false, connectionLabel = "disconnected", screen = Screen.Start)
    }

    fun goStart() {
        stopVoiceInternal()
        confirmKey = null
        _state.value = _state.value.copy(screen = Screen.Start, intake = null, transcript = emptyList(), error = null)
    }

    fun goAddress() { _state.value = _state.value.copy(screen = Screen.Address) }
    fun goConversation() { _state.value = _state.value.copy(screen = Screen.Conversation, editingField = null) }

    private suspend fun startVoice(intakeId: String) {
        _state.value = _state.value.copy(connectionLabel = "connecting")
        val offer = voice.prepareOffer()
        val session = api.startVoice(intakeId, UUID.randomUUID().toString(), VoiceStartRequest(offer))
        session.sdpAnswer?.let { voice.applyRemoteAnswer(it) }
        _state.value = _state.value.copy(voiceConnected = true, connectionLabel = "connected", voiceSessionId = session.id)
    }

    private suspend fun refresh(id: String) {
        val intake = api.getIntake(id)
        val question = intake.nextQuestion?.text
        val transcript = _state.value.transcript.toMutableList()
        if (question != null && transcript.none { it.speaker == "assistant" && it.text == question }) {
            transcript += TranscriptLine("assistant", question)
        }
        val screen = when {
            intake.status == "confirmed" -> Screen.Completed
            intake.status == "review_required" -> Screen.ReviewRequired
            intake.status == "ready_for_confirmation" -> Screen.Review
            intake.address?.verificationStatus != "verified" && intake.fields.values.count { it.state == "reported" || it.state == "unknown" } >= 3 -> Screen.Address
            else -> _state.value.screen
        }
        _state.value = _state.value.copy(intake = intake, transcript = transcript, screen = screen, connectionLabel = if (_state.value.busy) "processing" else _state.value.connectionLabel)
    }

    private fun screenFor(intake: Intake): Screen = when (intake.status) {
        "confirmed" -> Screen.Completed
        "review_required" -> Screen.ReviewRequired
        "ready_for_confirmation" -> Screen.Review
        else -> Screen.Conversation
    }

    private fun stopVoiceInternal() {
        runCatching { voice.stop() }
        val intake = _state.value.intake
        val sessionId = _state.value.voiceSessionId
        if (intake != null && sessionId != null) {
            viewModelScope.launch {
                runCatching { api.stopVoice(intake.id, sessionId, UUID.randomUUID().toString(), kotlinx.serialization.json.JsonObject(emptyMap())) }
            }
        }
        _state.value = _state.value.copy(voiceSessionId = null, voiceConnected = false)
    }

    private fun run(label: String, block: suspend () -> Unit) {
        viewModelScope.launch {
            _state.value = _state.value.copy(busy = true, error = null)
            try {
                block()
            } catch (error: Exception) {
                _state.value = _state.value.copy(error = error.message ?: label)
            } finally {
                _state.value = _state.value.copy(busy = false)
            }
        }
    }

    companion object {
        fun factory(api: WoningtriageApi, tokens: TokenStore, voice: VoiceSessionClient) =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T = AppViewModel(api, tokens, voice) as T
            }
    }
}
