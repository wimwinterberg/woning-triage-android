package nl.woningtriage.app.data.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import nl.woningtriage.app.domain.AddressCandidate
import nl.woningtriage.app.domain.Intake
import nl.woningtriage.app.domain.TaskStatus
import nl.woningtriage.app.domain.VoiceSession
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path

interface WoningtriageApi {
    @POST("api/v1/auth/session")
    suspend fun openSession(
        @Header("Idempotency-Key") key: String,
        @Body body: SessionRequest,
    ): TokenResponse

    @POST("api/v1/intakes")
    suspend fun createIntake(
        @Header("Idempotency-Key") key: String,
        @Body body: CreateIntakeRequest,
    ): Intake

    @GET("api/v1/intakes/{id}")
    suspend fun getIntake(@Path("id") id: String): Intake

    @POST("api/v1/intakes/{id}/messages")
    suspend fun sendMessage(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: MessageRequest,
    ): TaskStatus

    @PATCH("api/v1/intakes/{id}/fields")
    suspend fun patchFields(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: FieldPatchRequest,
    ): Intake

    @PATCH("api/v1/intakes/{id}/language")
    suspend fun changeLanguage(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: LanguagePatchRequest,
    ): Intake

    @POST("api/v1/intakes/{id}/summaries")
    suspend fun requestSummary(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: RevisionRequest,
    ): TaskStatus

    @POST("api/v1/intakes/{id}/confirmations")
    suspend fun confirm(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: ConfirmationRequest,
    ): Intake

    @POST("api/v1/intakes/{id}/address-lookups")
    suspend fun lookupAddress(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: AddressLookupRequest,
    ): AddressLookupResponse

    @POST("api/v1/intakes/{id}/address-verifications")
    suspend fun verifyAddress(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: AddressVerifyRequest,
    ): Intake

    @POST("api/v1/intakes/{id}/cancel")
    suspend fun cancel(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: RevisionRequest,
    ): Intake

    @POST("api/v1/intakes/{id}/voice-sessions")
    suspend fun startVoice(
        @Path("id") id: String,
        @Header("Idempotency-Key") key: String,
        @Body body: VoiceStartRequest,
    ): VoiceSession

    @POST("api/v1/intakes/{id}/voice-sessions/{sessionId}/stop")
    suspend fun stopVoice(
        @Path("id") id: String,
        @Path("sessionId") sessionId: String,
        @Header("Idempotency-Key") key: String,
        @Body body: JsonElement,
    ): VoiceSession
}

@Serializable data class SessionRequest(val client: String = "android")
@Serializable data class TokenResponse(
    @SerialName("access_token") val accessToken: String,
    @SerialName("user_id") val userId: String,
)
@Serializable data class CreateIntakeRequest(
    @SerialName("input_mode") val inputMode: String,
    val language: String? = null,
)
@Serializable data class LanguagePatchRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    val mode: String = "auto",
    val language: String? = null,
    @SerialName("accept_ui_offer") val acceptUiOffer: Boolean? = null,
)
@Serializable data class MessageRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    @SerialName("client_message_id") val clientMessageId: String,
    val text: String,
)
@Serializable data class FieldChange(val field: String, val action: String, val value: String? = null)
@Serializable data class FieldPatchRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    val changes: List<FieldChange>,
)
@Serializable data class RevisionRequest(@SerialName("expected_revision") val expectedRevision: Int)
@Serializable data class ConfirmationRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    @SerialName("summary_id") val summaryId: String,
    @SerialName("confirmation_channel") val confirmationChannel: String = "ui",
)
@Serializable data class NearbyAddressHint(
    val postcode: String,
    @SerialName("house_number") val houseNumber: Int,
    val addition: String? = null,
)
@Serializable data class AddressLookupRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    val postcode: String? = null,
    @SerialName("house_number") val houseNumber: Int? = null,
    val addition: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    val nearby: List<NearbyAddressHint> = emptyList(),
)
@Serializable data class AddressLookupResponse(
    @SerialName("lookup_id") val lookupId: String,
    val revision: Int,
    @SerialName("address_revision") val addressRevision: Int,
    val candidates: List<AddressCandidate>,
)
@Serializable data class AddressVerifyRequest(
    @SerialName("expected_revision") val expectedRevision: Int,
    @SerialName("lookup_id") val lookupId: String,
    @SerialName("candidate_id") val candidateId: String,
    @SerialName("address_revision") val addressRevision: Int,
    @SerialName("confirmation_channel") val confirmationChannel: String = "ui",
    @SerialName("evidence_message_id") val evidenceMessageId: String? = null,
)
@Serializable data class VoiceStartRequest(@SerialName("sdp_offer") val sdpOffer: String)
