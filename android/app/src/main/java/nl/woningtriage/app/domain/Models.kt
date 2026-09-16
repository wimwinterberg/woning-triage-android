package nl.woningtriage.app.domain

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class Intake(
    val id: String,
    val revision: Int,
    val status: String,
    @SerialName("tree_version") val treeVersion: String,
    val demo: Boolean = true,
    @SerialName("conversation_language") val conversationLanguage: String,
    @SerialName("language_mode") val languageMode: String,
    val fields: Map<String, LedoField>,
    val answers: List<AnswerSlot> = emptyList(),
    val hypotheses: List<Hypothesis> = emptyList(),
    val risk: Risk = Risk(),
    @SerialName("next_question") val nextQuestion: NextQuestion? = null,
    val summary: Summary? = null,
    val address: AddressState? = null,
    @SerialName("report_id") val reportId: String? = null,
    @SerialName("active_tasks") val activeTasks: List<TaskStatus> = emptyList(),
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
    @SerialName("confirmed_at") val confirmedAt: String? = null,
)

@Serializable
data class LedoField(
    val value: String? = null,
    val state: String,
    val source: String? = null,
    @SerialName("evidence_ids") val evidenceIds: List<String> = emptyList(),
)

@Serializable
data class AnswerSlot(
    @SerialName("slot_id") val slotId: String? = null,
    val value: String? = null,
)

@Serializable
data class Hypothesis(
    val id: String? = null,
    val text: String? = null,
)

@Serializable
data class Risk(
    val state: String = "unassessed",
    @SerialName("rule_ids") val ruleIds: List<String> = emptyList(),
)

@Serializable
data class NextQuestion(
    val id: String,
    val target: String? = null,
    val text: String,
)

@Serializable
data class Summary(
    val id: String,
    @SerialName("source_revision") val sourceRevision: Int,
    val language: String,
    @SerialName("resident_text") val residentText: String,
    @SerialName("work_description_nl") val workDescriptionNl: String,
)

@Serializable
data class AddressState(
    val postcode: String? = null,
    @SerialName("house_number") val houseNumber: Int? = null,
    val addition: String? = null,
    val street: String? = null,
    val city: String? = null,
    @SerialName("country_code") val countryCode: String? = null,
    @SerialName("lookup_id") val lookupId: String? = null,
    @SerialName("candidate_id") val candidateId: String? = null,
    @SerialName("address_revision") val addressRevision: Int? = null,
    @SerialName("verification_status") val verificationStatus: String = "missing",
    val candidates: List<AddressCandidate> = emptyList(),
)

@Serializable
data class AddressCandidate(
    @SerialName("candidate_id") val candidateId: String,
    val postcode: String,
    @SerialName("house_number") val houseNumber: Int,
    val addition: String? = null,
    val street: String,
    val city: String,
    @SerialName("country_code") val countryCode: String = "NL",
    @SerialName("display_address") val displayAddress: String,
)

@Serializable
data class TaskStatus(
    @SerialName("task_id") val taskId: String? = null,
    val status: String,
    @SerialName("base_revision") val baseRevision: Int? = null,
    @SerialName("result_revision") val resultRevision: Int? = null,
)

@Serializable
data class VoiceSession(
    val id: String,
    @SerialName("intake_id") val intakeId: String,
    val status: String,
    val transport: String,
    @SerialName("sdp_answer") val sdpAnswer: String? = null,
    @SerialName("expires_at") val expiresAt: String? = null,
    val live: Boolean = false,
)

@Serializable
data class ApiErrorEnvelope(val error: ApiError)

@Serializable
data class ApiError(
    val code: String,
    val message: String,
    @SerialName("request_id") val requestId: String? = null,
    @SerialName("current_revision") val currentRevision: Int? = null,
)

fun LedoField.uiStateLabel(): String = when (state) {
    "reported" -> "reported"
    "unknown" -> "unknown"
    "needs_review" -> "needs_review"
    else -> "missing"
}
