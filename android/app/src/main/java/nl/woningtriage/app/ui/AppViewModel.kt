package nl.woningtriage.app.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import nl.woningtriage.app.data.api.AddressLookupRequest
import nl.woningtriage.app.data.api.AddressLookupResponse
import nl.woningtriage.app.data.api.AddressVerifyRequest
import nl.woningtriage.app.location.DeviceAddressLocator
import nl.woningtriage.app.data.api.ConfirmationRequest
import nl.woningtriage.app.data.api.CreateIntakeRequest
import nl.woningtriage.app.data.api.FieldChange
import nl.woningtriage.app.data.api.FieldPatchRequest
import nl.woningtriage.app.data.api.MessageRequest
import nl.woningtriage.app.data.api.RevisionRequest
import nl.woningtriage.app.data.api.TokenStore
import nl.woningtriage.app.data.api.VoiceStartRequest
import nl.woningtriage.app.data.api.WoningtriageApi
import nl.woningtriage.app.data.api.userFacingApiError
import nl.woningtriage.app.domain.AddressState
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
    val selectedCandidateId: String? = null,
    val uiLocale: String = "nl-NL",
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
    private var watchJob: Job? = null

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
        if (voiceMode) {
            coroutineScope {
                val offerJob = async { voice.prepareOffer() }
                val intake = api.createIntake(UUID.randomUUID().toString(), CreateIntakeRequest("voice"))
                tokens.activeIntakeId = intake.id
                _state.value = _state.value.copy(
                    intake = intake,
                    preferTyping = false,
                    screen = Screen.Conversation,
                    transcript = listOfNotNull(intake.nextQuestion?.text?.let { TranscriptLine("assistant", it) }),
                    connectionLabel = "connecting",
                    uiLocale = UiLocale.fromConversation(intake.conversationLanguage),
                )
                watchIntake(intake.id)
                runCatching {
                    completeVoiceStart(intake.id, offerJob.await())
                }.onFailure { error ->
                    _state.value = _state.value.copy(
                        voiceConnected = false,
                        connectionLabel = "disconnected",
                        error = friendlyVoiceError(error),
                    )
                }
            }
            return@run
        }
        val intake = api.createIntake(UUID.randomUUID().toString(), CreateIntakeRequest("text"))
        tokens.activeIntakeId = intake.id
        _state.value = _state.value.copy(
            intake = intake,
            preferTyping = true,
            screen = Screen.Conversation,
            transcript = listOfNotNull(intake.nextQuestion?.text?.let { TranscriptLine("assistant", it) }),
            connectionLabel = "disconnected",
            uiLocale = UiLocale.fromConversation(intake.conversationLanguage),
        )
        watchIntake(intake.id)
    }

    fun resumeIntake() = run("resume") {
        val id = tokens.activeIntakeId ?: return@run
        val intake = api.getIntake(id)
        _state.value = _state.value.copy(
            intake = intake,
            screen = screenFor(intake),
            error = null,
            uiLocale = UiLocale.fromConversation(intake.conversationLanguage),
        )
        if (intake.status == "collecting" || intake.status == "ready_for_confirmation") {
            watchIntake(intake.id)
        }
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
        val postcode = _state.value.postcode.trim()
        val number = _state.value.houseNumber.toIntOrNull()
        if (postcode.isEmpty() || number == null) {
            _state.value = _state.value.copy(error = "Vul postcode en huisnummer in.")
            return@run
        }
        val lookup = api.lookupAddress(
            intake.id,
            UUID.randomUUID().toString(),
            AddressLookupRequest(
                expectedRevision = intake.revision,
                postcode = postcode,
                houseNumber = number,
                addition = _state.value.addition.ifBlank { null },
            ),
        )
        applyLookup(intake.id, lookup)
    }

    fun locationDenied() {
        _state.value = _state.value.copy(error = "Locatie geweigerd. Vul de postcode in.")
    }

    fun lookupFromGps(locator: DeviceAddressLocator) = run("gps") {
        val intake = _state.value.intake ?: return@run
        if (!locator.hasPermission()) {
            locationDenied()
            return@run
        }
        runCatching { refresh(intake.id) }
        val latest = _state.value.intake ?: return@run
        val fix = runCatching { locator.currentLocation() }.getOrElse { error ->
            throw IllegalStateException(friendlyGpsError(error), error)
        }
        val hints = locator.nearbyAddresses(fix.latitude, fix.longitude)
        val lookup = api.lookupAddress(
            latest.id,
            UUID.randomUUID().toString(),
            AddressLookupRequest(
                expectedRevision = latest.revision,
                latitude = fix.latitude,
                longitude = fix.longitude,
                nearby = hints,
            ),
        )
        applyLookup(latest.id, lookup)
    }

    fun verifyCandidate(candidateId: String) = run("verify") {
        _state.value = _state.value.copy(selectedCandidateId = candidateId)
        val verified = postVerify(candidateId)
        if (_state.value.voiceConnected) {
            voice.speakFollowUp(
                verified.spokenFollowUp.orEmpty(),
                verified.nextQuestion?.text.orEmpty(),
                verified.conversationLanguage,
            )
        }
        val transcript = _state.value.transcript.toMutableList()
        verified.spokenFollowUp?.takeIf { it.isNotBlank() && transcript.none { line -> line.text == it } }?.let {
            transcript += TranscriptLine("assistant", it)
        }
        verified.nextQuestion?.text?.takeIf { it.isNotBlank() && transcript.none { line -> line.text == it } }?.let {
            transcript += TranscriptLine("assistant", it)
        }
        _state.value = _state.value.copy(
            intake = verified,
            selectedCandidateId = candidateId,
            screen = Screen.Address,
            error = null,
            transcript = transcript,
            uiLocale = UiLocale.fromConversation(verified.conversationLanguage),
        )
    }

    private suspend fun applyLookup(intakeId: String, lookup: AddressLookupResponse) {
        runCatching { refresh(intakeId) }
        val current = _state.value.intake
        val merged = if (current != null) {
            current.copy(
                revision = lookup.revision,
                address = (current.address ?: AddressState()).copy(
                    lookupId = lookup.lookupId,
                    addressRevision = lookup.addressRevision,
                    verificationStatus = "unverified",
                    candidates = lookup.candidates,
                ),
            )
        } else {
            null
        }
        _state.value = _state.value.copy(
            intake = merged ?: current,
            selectedCandidateId = null,
            screen = Screen.Address,
            error = null,
        )
    }

    private suspend fun postVerify(candidateId: String, retried: Boolean = false): Intake {
        val intake = _state.value.intake ?: throw IllegalStateException("Geen intake.")
        val address = intake.address ?: throw IllegalStateException("Zoek eerst een adres op.")
        val lookupId = address.lookupId.orEmpty()
        return try {
            api.verifyAddress(
                intake.id,
                UUID.randomUUID().toString(),
                AddressVerifyRequest(
                    expectedRevision = intake.revision,
                    lookupId = lookupId,
                    candidateId = candidateId,
                    addressRevision = address.addressRevision ?: 0,
                    confirmationChannel = "ui",
                ),
            )
        } catch (error: retrofit2.HttpException) {
            if (!retried && error.code() == 409) {
                refresh(intake.id)
                return postVerify(candidateId, retried = true)
            }
            throw IllegalStateException(userFacingApiError(error), error)
        }
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
        watchJob?.cancel()
        stopVoiceInternal()
        _state.value = _state.value.copy(voiceConnected = false, connectionLabel = "disconnected", screen = Screen.Start)
    }

    fun goStart() {
        watchJob?.cancel()
        stopVoiceInternal()
        confirmKey = null
        _state.value = _state.value.copy(screen = Screen.Start, intake = null, transcript = emptyList(), error = null)
    }

    fun goAddress() { _state.value = _state.value.copy(screen = Screen.Address) }
    fun goConversation() { _state.value = _state.value.copy(screen = Screen.Conversation, editingField = null) }

    private suspend fun completeVoiceStart(intakeId: String, offer: String) {
        _state.value = _state.value.copy(connectionLabel = "connecting")
        val session = api.startVoice(intakeId, UUID.randomUUID().toString(), VoiceStartRequest(offer))
        val answer = session.sdpAnswer
        if (session.live && answer != null && nl.woningtriage.app.voice.Sdp.canApplyAnswer(offer, answer)) {
            val opening = _state.value.intake?.nextQuestion?.text.orEmpty()
            voice.requestOpeningGreeting(opening)
            voice.setOnConnectionLost {
                if (_state.value.connectionLabel == "idle_closed") {
                    return@setOnConnectionLost
                }
                if (_state.value.voiceConnected) {
                    _state.value = _state.value.copy(
                        voiceConnected = false,
                        connectionLabel = "disconnected",
                    )
                }
            }
            voice.applyRemoteAnswer(answer)
            voice.requestOpeningGreeting(opening)
            _state.value = _state.value.copy(voiceConnected = true, connectionLabel = "connected", voiceSessionId = session.id)
        } else {
            _state.value = _state.value.copy(
                voiceConnected = false,
                connectionLabel = "disconnected",
                voiceSessionId = session.id,
                error = "Spraak is niet live verbonden (geen OPENAI_API_KEY). U kunt typen.",
            )
        }
    }

    private fun friendlyGpsError(error: Throwable): String {
        val message = error.message.orEmpty()
        return when {
            message.contains("location_denied", ignoreCase = true) -> "Locatie geweigerd. Vul de postcode in."
            else -> "Locatie is niet beschikbaar. Vul de postcode in."
        }
    }

    private fun friendlyVoiceError(error: Throwable): String {
        val message = error.message.orEmpty()
        return if (message.contains("m-lines", ignoreCase = true) || message.contains("SDP", ignoreCase = true)) {
            "Spraakverbinding mislukt. Zet OPENAI_API_KEY in backend/.env of typ uw antwoord."
        } else {
            message.ifBlank { "Spraakverbinding mislukt. U kunt typen." }
        }
    }

    private fun watchIntake(id: String) {
        watchJob?.cancel()
        watchJob = viewModelScope.launch {
            while (isActive) {
                delay(700)
                val current = _state.value
                if (current.intake?.id != id || current.busy) {
                    continue
                }
                if (current.screen !in listOf(Screen.Conversation, Screen.Address, Screen.Review, Screen.FieldEdit)) {
                    return@launch
                }
                runCatching { refresh(id, fromWatch = true) }
            }
        }
    }

    private suspend fun refresh(id: String, fromWatch: Boolean = false) {
        val intake = api.getIntake(id)
        val question = intake.nextQuestion?.text
        val transcript = _state.value.transcript.toMutableList()
        intake.spokenFollowUp?.takeIf { it.isNotBlank() && transcript.none { line -> line.text == it } }?.let {
            transcript += TranscriptLine("assistant", it)
        }
        intake.idleNotice?.takeIf { it.isNotBlank() && transcript.none { line -> line.text == it } }?.let {
            transcript += TranscriptLine("assistant", it)
        }
        if (question != null && transcript.none { it.speaker == "assistant" && it.text == question }) {
            transcript += TranscriptLine("assistant", question)
        }
        val stayInVoice = fromWatch && _state.value.voiceConnected && _state.value.screen == Screen.Conversation
        val screen = screenAfterRefresh(
            status = intake.status,
            addressVerified = intake.address?.verificationStatus == "verified",
            completedLedoCount = completedLedoCount(intake),
            current = _state.value.screen,
            stayInVoiceConversation = stayInVoice,
        )
        val idleClosed = isIdleTimeoutClose(intake.voice?.closeReason)
        if (idleClosed && (_state.value.voiceConnected || _state.value.connectionLabel != "idle_closed")) {
            runCatching { voice.stop() }
        }
        _state.value = _state.value.copy(
            intake = intake,
            transcript = transcript,
            screen = screen,
            voiceConnected = if (idleClosed) false else _state.value.voiceConnected,
            voiceSessionId = if (idleClosed) null else _state.value.voiceSessionId,
            connectionLabel = when {
                idleClosed -> "idle_closed"
                _state.value.busy -> "processing"
                else -> _state.value.connectionLabel
            },
            uiLocale = UiLocale.fromConversation(intake.conversationLanguage),
        )
        if (screen == Screen.Completed || screen == Screen.ReviewRequired) {
            watchJob?.cancel()
        }
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
                _state.value = _state.value.copy(error = userFacingApiError(error))
            } finally {
                _state.value = _state.value.copy(busy = false)
            }
        }
    }

    override fun onCleared() {
        watchJob?.cancel()
        super.onCleared()
    }

    companion object {
        fun factory(api: WoningtriageApi, tokens: TokenStore, voice: VoiceSessionClient) =
            object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T = AppViewModel(api, tokens, voice) as T
            }
    }
}

internal fun isIdleTimeoutClose(closeReason: String?): Boolean = closeReason == "idle_timeout"

internal fun completedLedoCount(intake: Intake): Int =
    listOf("location", "element", "defect", "cause").count { key ->
        val state = intake.fields[key]?.state
        state == "reported" || state == "unknown"
    }

internal fun screenAfterRefresh(
    status: String,
    addressVerified: Boolean,
    completedLedoCount: Int,
    current: Screen,
    stayInVoiceConversation: Boolean,
): Screen = when {
    status == "confirmed" -> Screen.Completed
    status == "review_required" -> Screen.ReviewRequired
    status == "ready_for_confirmation" -> Screen.Review
    stayInVoiceConversation -> current
    !addressVerified && completedLedoCount >= 3 -> Screen.Address
    else -> current
}
