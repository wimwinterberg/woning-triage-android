package nl.woningtriage.app.ui

import android.Manifest
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MicOff
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CenterAlignedTopAppBar
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import nl.woningtriage.app.R
import nl.woningtriage.app.domain.Intake

@Composable
fun WoningtriageRoot(viewModel: AppViewModel) {
    val state by viewModel.state.collectAsState()
    when (state.screen) {
        Screen.Activation -> ActivationScreen(state, viewModel)
        Screen.Start -> StartScreen(state, viewModel)
        Screen.Conversation -> ConversationScreen(state, viewModel)
        Screen.Address -> AddressScreen(state, viewModel)
        Screen.Review -> ReviewScreen(state, viewModel)
        Screen.Completed -> CompletedScreen(state, viewModel)
        Screen.ReviewRequired -> ReviewRequiredScreen(state, viewModel)
        Screen.FieldEdit -> FieldEditScreen(state, viewModel)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ActivationScreen(state: AppUiState, viewModel: AppViewModel) {
    ScreenScaffold(stringResource(R.string.activation_title), state.error) {
        Text(stringResource(R.string.activation_explain))
        OutlinedTextField(state.activationCode, viewModel::onCode, label = { Text(stringResource(R.string.activation_code)) }, modifier = Modifier.fillMaxWidth())
        Button(onClick = viewModel::activate, enabled = !state.busy && state.activationCode.isNotBlank(), modifier = Modifier.fillMaxWidth().height(48.dp)) {
            Text(stringResource(R.string.activate))
        }
    }
}

@Composable
private fun StartScreen(state: AppUiState, viewModel: AppViewModel) {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) viewModel.startIntake(true) else viewModel.startIntake(false)
    }
    ScreenScaffold(stringResource(R.string.app_name), state.error) {
        Text(stringResource(R.string.start_explain), style = MaterialTheme.typography.bodyLarge)
        Text(stringResource(R.string.privacy_placeholder), style = MaterialTheme.typography.bodySmall)
        Button(
            onClick = { launcher.launch(Manifest.permission.RECORD_AUDIO) },
            enabled = !state.busy,
            modifier = Modifier.fillMaxWidth().height(48.dp).semantics { contentDescription = context.getString(R.string.report_problem) },
        ) { Text(stringResource(R.string.report_problem)) }
        OutlinedButton(onClick = { viewModel.startIntake(false) }, enabled = !state.busy, modifier = Modifier.fillMaxWidth().height(48.dp)) {
            Text(stringResource(R.string.prefer_typing))
        }
        if (state.intake != null || !state.hasToken) {
            TextButton(onClick = viewModel::resumeIntake) { Text(stringResource(R.string.resume_intake)) }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ConversationScreen(state: AppUiState, viewModel: AppViewModel) {
    val tablet = LocalConfiguration.current.screenWidthDp >= 600
    Scaffold(
        topBar = { CenterAlignedTopAppBar(title = { Text(stringResource(R.string.app_name)) }) },
        bottomBar = {
            Column(Modifier.fillMaxWidth().imePadding().navigationBarsPadding().padding(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(
                        value = state.draft,
                        onValueChange = viewModel::onDraft,
                        modifier = Modifier.weight(1f),
                        label = { Text(stringResource(R.string.type_answer)) },
                    )
                    IconButton(onClick = viewModel::sendDraft, modifier = Modifier.semantics { contentDescription = "send" }) {
                        Icon(Icons.Default.Send, contentDescription = stringResource(R.string.send))
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilledTonalButton(onClick = viewModel::toggleMute, modifier = Modifier.height(48.dp)) {
                        Icon(if (state.micMuted) Icons.Default.MicOff else Icons.Default.Mic, contentDescription = null)
                        Text(if (state.micMuted) stringResource(R.string.unmute) else stringResource(R.string.mute))
                    }
                    Button(onClick = viewModel::stopConversation, modifier = Modifier.height(48.dp)) {
                        Icon(Icons.Default.Stop, contentDescription = null)
                        Text(stringResource(R.string.stop_conversation))
                    }
                }
            }
        },
    ) { padding ->
        val content: @Composable () -> Unit = {
            Text(statusLabel(state), style = MaterialTheme.typography.labelLarge)
            state.intake?.nextQuestion?.text?.let { Text(it, style = MaterialTheme.typography.titleMedium) }
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Transcript(state.transcript)
            LedoCard(state.intake, onField = viewModel::openField)
            OutlinedButton(onClick = viewModel::goAddress) { Text(stringResource(R.string.lookup_address)) }
        }
        if (tablet) {
            Row(Modifier.padding(padding).fillMaxSize().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp), content = { content() })
            }
        } else {
            Column(Modifier.padding(padding).fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) { content() }
        }
    }
}

@Composable
private fun AddressScreen(state: AppUiState, viewModel: AppViewModel) {
    ScreenScaffold(stringResource(R.string.lookup_address), state.error) {
        OutlinedTextField(state.postcode, viewModel::onPostcode, label = { Text(stringResource(R.string.postcode)) }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(state.houseNumber, viewModel::onHouseNumber, label = { Text(stringResource(R.string.house_number)) }, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(state.addition, viewModel::onAddition, label = { Text(stringResource(R.string.addition)) }, modifier = Modifier.fillMaxWidth())
        Button(onClick = viewModel::lookupAddress, enabled = !state.busy, modifier = Modifier.fillMaxWidth().height(48.dp)) {
            Text(stringResource(R.string.lookup_address))
        }
        state.intake?.address?.candidates.orEmpty().forEach { candidate ->
            Card(Modifier.fillMaxWidth().clickable { viewModel.verifyCandidate(candidate.candidateId) }) {
                Column(Modifier.padding(16.dp)) {
                    Text(candidate.displayAddress, style = MaterialTheme.typography.titleMedium)
                    Text(stringResource(R.string.verify_address), style = MaterialTheme.typography.labelLarge)
                }
            }
        }
        if (state.intake?.address?.verificationStatus == "verified") {
            Button(onClick = viewModel::requestSummary, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text(stringResource(R.string.confirm)) }
        }
        TextButton(onClick = viewModel::goConversation) { Text(stringResource(R.string.adjust)) }
    }
}

@Composable
private fun ReviewScreen(state: AppUiState, viewModel: AppViewModel) {
    val intake = state.intake
    ScreenScaffold(stringResource(R.string.confirm), state.error) {
        Text(intake?.summary?.residentText.orEmpty(), style = MaterialTheme.typography.bodyLarge)
        LedoCard(intake, onField = viewModel::openField)
        if (intake?.conversationLanguage?.startsWith("nl") != true) {
            Text(stringResource(R.string.work_description), style = MaterialTheme.typography.titleMedium)
            Text(intake?.summary?.workDescriptionNl.orEmpty())
        }
        intake?.address?.let { Text(listOfNotNull(it.street, it.houseNumber?.toString(), it.addition, it.postcode, it.city).joinToString(" ")) }
        Button(onClick = viewModel::confirm, enabled = !state.busy, modifier = Modifier.fillMaxWidth().height(48.dp).semantics { contentDescription = "confirm" }) {
            Text(stringResource(R.string.confirm))
        }
        OutlinedButton(onClick = viewModel::goConversation, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text(stringResource(R.string.adjust)) }
    }
}

@Composable
private fun CompletedScreen(state: AppUiState, viewModel: AppViewModel) {
    ScreenScaffold(stringResource(R.string.saved), state.error) {
        Text(state.intake?.reportId.orEmpty(), style = MaterialTheme.typography.headlineSmall)
        Text(state.intake?.summary?.workDescriptionNl.orEmpty())
        state.intake?.address?.let { Text(listOfNotNull(it.street, it.houseNumber?.toString(), it.addition, it.postcode, it.city).joinToString(" ")) }
        Button(onClick = viewModel::goStart, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text(stringResource(R.string.new_report)) }
    }
}

@Composable
private fun ReviewRequiredScreen(state: AppUiState, viewModel: AppViewModel) {
    ScreenScaffold(stringResource(R.string.app_name), state.error) {
        Text(state.intake?.nextQuestion?.text.orEmpty())
        Text(stringResource(R.string.demo_no_staff))
        Button(onClick = viewModel::goStart, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text(stringResource(R.string.new_intake)) }
    }
}

@Composable
private fun FieldEditScreen(state: AppUiState, viewModel: AppViewModel) {
    ScreenScaffold(state.editingField.orEmpty(), state.error) {
        Text(stringResource(R.string.dependent_review), style = MaterialTheme.typography.bodySmall)
        OutlinedTextField(state.editingValue, viewModel::onEditValue, modifier = Modifier.fillMaxWidth())
        Button(onClick = { viewModel.submitField("set") }, modifier = Modifier.fillMaxWidth().height(48.dp)) { Text(stringResource(R.string.send)) }
        if (state.editingField == "cause") {
            OutlinedButton(onClick = { viewModel.submitField("mark_unknown") }, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                Text(stringResource(R.string.i_dont_know))
            }
        }
        TextButton(onClick = { viewModel.submitField("clear") }) { Text(stringResource(R.string.clear_field)) }
        TextButton(onClick = viewModel::goConversation) { Text(stringResource(R.string.cancel)) }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ScreenScaffold(title: String, error: String?, content: @Composable () -> Unit) {
    Scaffold(topBar = { CenterAlignedTopAppBarSafe(title) }) { padding ->
        Column(
            Modifier.padding(padding).fillMaxSize().verticalScroll(rememberScrollState()).imePadding().padding(20.dp).widthIn(max = 720.dp).alignWidth(),
            verticalArrangement = Arrangement.spacedBy(16.dp),
            horizontalAlignment = Alignment.Start,
        ) {
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            content()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CenterAlignedTopAppBarSafe(title: String) {
    CenterAlignedTopAppBar(title = { Text(title) })
}

private fun Modifier.alignWidth() = this.fillMaxWidth()

@Composable
private fun Transcript(lines: List<TranscriptLine>) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        lines.forEach { line ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    Text(line.speaker, style = MaterialTheme.typography.labelMedium)
                    Text(line.text)
                }
            }
        }
    }
}

@Composable
private fun LedoCard(intake: Intake?, onField: (String) -> Unit) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.what_we_know), style = MaterialTheme.typography.titleMedium)
            listOf("location" to R.string.location, "element" to R.string.element, "defect" to R.string.defect, "cause" to R.string.cause).forEach { (key, label) ->
                val field = intake?.fields?.get(key)
                Row(Modifier.fillMaxWidth().clickable { onField(key) }.padding(vertical = 4.dp), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(stringResource(label))
                    Text(fieldLabel(field?.state, field?.value))
                }
            }
            intake?.hypotheses.orEmpty().forEach {
                Text(stringResource(R.string.possible_cause) + ": " + (it.text ?: ""), style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun fieldLabel(state: String?, value: String?): String = when (state) {
    "reported" -> value ?: stringResource(R.string.reported)
    "unknown" -> stringResource(R.string.unknown)
    "needs_review" -> stringResource(R.string.needs_review)
    else -> stringResource(R.string.not_known_yet)
}

@Composable
private fun statusLabel(state: AppUiState): String = when (state.connectionLabel) {
    "connecting" -> stringResource(R.string.connecting)
    "connected" -> stringResource(R.string.connected)
    "mic_off" -> stringResource(R.string.mic_off)
    "processing" -> stringResource(R.string.processing)
    else -> stringResource(R.string.disconnected)
}
