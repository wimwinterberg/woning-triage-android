package nl.woningtriage.app.ui

import android.Manifest
import android.content.res.Configuration
import android.os.LocaleList
import androidx.activity.compose.LocalActivityResultRegistryOwner
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Language
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.MicOff
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.Send
import androidx.compose.material.icons.filled.Stop
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import nl.woningtriage.app.R
import nl.woningtriage.app.domain.Intake
import nl.woningtriage.app.location.DeviceAddressLocator

@Composable
fun WoningtriageRoot(viewModel: AppViewModel) {
    val state by viewModel.state.collectAsState()
    AppLocale(state.uiLocale) {
        Box {
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
            if (state.showLanguagePicker) {
                LanguagePickerDialog(state.uiLocale, viewModel::selectUiLanguage, viewModel::closeLanguagePicker)
            }
            state.uiOffer?.let { offer ->
                UiLanguageOfferDialog(
                    question = offer.question ?: stringResource(R.string.switch_ui_question),
                    onYes = viewModel::acceptUiOffer,
                    onNo = viewModel::declineUiOffer,
                )
            }
        }
    }
}

@Composable
private fun AppLocale(tag: String, content: @Composable () -> Unit) {
    val locale = remember(tag) { java.util.Locale.forLanguageTag(tag.replace('_', '-')) }
    val context = LocalContext.current
    val wrapped = remember(tag, context) {
        val config = Configuration(context.resources.configuration)
        config.setLocale(locale)
        config.setLocales(LocaleList(locale))
        context.createConfigurationContext(config)
    }
    // createConfigurationContext() is not the Activity. rememberLauncherForActivityResult
    // looks up LocalActivityResultRegistryOwner from LocalContext, so keep the Activity owner.
    val layoutDirection = if (UiLocale.isRtl(tag)) LayoutDirection.Rtl else LayoutDirection.Ltr
    val registryOwner = LocalActivityResultRegistryOwner.current
    if (registryOwner != null) {
        CompositionLocalProvider(
            LocalContext provides wrapped,
            LocalConfiguration provides wrapped.resources.configuration,
            LocalLayoutDirection provides layoutDirection,
            LocalActivityResultRegistryOwner provides registryOwner,
            content = content,
        )
    } else {
        CompositionLocalProvider(
            LocalContext provides wrapped,
            LocalConfiguration provides wrapped.resources.configuration,
            LocalLayoutDirection provides layoutDirection,
            content = content,
        )
    }
}

@Composable
private fun ActivationScreen(state: AppUiState, viewModel: AppViewModel) {
    PaperScaffold {
        BrandMark()
        Text(stringResource(R.string.activation_title), style = MaterialTheme.typography.headlineLarge)
        Text(stringResource(R.string.activation_explain), style = MaterialTheme.typography.bodyLarge)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        OutlinedTextField(
            state.activationCode,
            viewModel::onCode,
            label = { Text(stringResource(R.string.activation_code)) },
            modifier = Modifier.fillMaxWidth(),
            colors = paperFieldColors(),
        )
        PrimaryAction(
            text = stringResource(R.string.activate),
            onClick = viewModel::activate,
            enabled = !state.busy && state.activationCode.isNotBlank(),
        )
    }
}

@Composable
private fun StartScreen(state: AppUiState, viewModel: AppViewModel) {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) viewModel.startIntake(true) else viewModel.startIntake(false)
    }
    PaperScaffold(footer = { PrivacyFooter() }) {
        TextButton(
            onClick = viewModel::openLanguagePicker,
            colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.primary),
        ) {
            Icon(Icons.Default.Language, contentDescription = null, modifier = Modifier.size(18.dp))
            Text(
                stringResource(R.string.change_language) + " · " + SupportedLanguages.nativeName(state.uiLocale),
                modifier = Modifier.padding(start = 8.dp),
            )
        }
        BrandMark()
        Text(stringResource(R.string.start_headline), style = MaterialTheme.typography.displaySmall)
        Icon(
            painter = painterResource(R.drawable.ic_house_outline),
            contentDescription = null,
            tint = MaterialTheme.colorScheme.primary,
            modifier = Modifier.size(120.dp).padding(top = 12.dp, bottom = 4.dp),
        )
        Text(stringResource(R.string.start_explain), style = MaterialTheme.typography.bodyLarge)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Spacer(Modifier.height(8.dp))
        PrimaryAction(
            text = stringResource(R.string.report_problem),
            onClick = { launcher.launch(Manifest.permission.RECORD_AUDIO) },
            enabled = !state.busy,
            contentDescription = context.getString(R.string.report_problem),
        )
        Row(
            Modifier
                .clickable(enabled = !state.busy) { viewModel.startIntake(false) }
                .padding(vertical = 8.dp)
                .semantics { contentDescription = context.getString(R.string.prefer_typing) },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.prefer_typing), style = MaterialTheme.typography.titleMedium)
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null)
        }
        if (state.hasStoredIntake) {
            TextButton(onClick = viewModel::resumeIntake, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.onSurfaceVariant)) {
                Text(stringResource(R.string.resume_intake))
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ConversationScreen(state: AppUiState, viewModel: AppViewModel) {
    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = { BrandTopBar() },
        bottomBar = {
            Column(
                Modifier.fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 20.dp, vertical = 12.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                OutlinedTextField(
                    value = state.draft,
                    onValueChange = viewModel::onDraft,
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text(stringResource(R.string.type_answer)) },
                    trailingIcon = {
                        IconButton(onClick = viewModel::sendDraft, modifier = Modifier.semantics { contentDescription = "send" }) {
                            Icon(Icons.Default.Send, contentDescription = stringResource(R.string.send))
                        }
                    },
                    colors = paperFieldColors(),
                )
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FooterAction(
                        onClick = viewModel::toggleMute,
                        icon = if (state.micMuted) Icons.Default.MicOff else Icons.Default.Mic,
                        label = if (state.micMuted) stringResource(R.string.unmute) else stringResource(R.string.mute),
                        outlined = true,
                        modifier = Modifier.weight(1f),
                    )
                    FooterAction(
                        onClick = viewModel::stopConversation,
                        icon = Icons.Default.Stop,
                        label = stringResource(R.string.stop_conversation),
                        outlined = false,
                        modifier = Modifier.weight(1f),
                    )
                }
            }
        },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp, vertical = 16.dp)
                .widthIn(max = 720.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            StatusChip(statusLabel(state))
            state.intake?.nextQuestion?.text?.let { Text(it, style = MaterialTheme.typography.headlineSmall) }
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Transcript(state.transcript)
            LedoCard(state.intake, onField = viewModel::openField)
            AddressCandidatesCard(state.intake, state.selectedCandidateId, onVerify = viewModel::verifyCandidate)
            UseMyLocationButton(state, viewModel)
            TextButton(onClick = viewModel::goAddress, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.primary)) {
                Text(stringResource(R.string.lookup_address))
                Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null)
            }
        }
    }
}

@Composable
private fun AddressScreen(state: AppUiState, viewModel: AppViewModel) {
    PaperScaffold {
        BrandMark()
        Text(stringResource(R.string.lookup_address), style = MaterialTheme.typography.headlineLarge)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        UseMyLocationButton(state, viewModel)
        Text(stringResource(R.string.or_type_address), style = MaterialTheme.typography.bodySmall)
        OutlinedTextField(state.postcode, viewModel::onPostcode, label = { Text(stringResource(R.string.postcode)) }, modifier = Modifier.fillMaxWidth(), colors = paperFieldColors())
        OutlinedTextField(state.houseNumber, viewModel::onHouseNumber, label = { Text(stringResource(R.string.house_number)) }, modifier = Modifier.fillMaxWidth(), colors = paperFieldColors())
        OutlinedTextField(state.addition, viewModel::onAddition, label = { Text(stringResource(R.string.addition)) }, modifier = Modifier.fillMaxWidth(), colors = paperFieldColors())
        PrimaryAction(text = stringResource(R.string.lookup_address), onClick = viewModel::lookupAddress, enabled = !state.busy)
        AddressCandidatesCard(state.intake, state.selectedCandidateId, onVerify = viewModel::verifyCandidate)
        val savedAddress = state.intake?.address
        if (savedAddress?.verificationStatus == "verified") {
            val display = savedAddress.candidates.firstOrNull { it.candidateId == savedAddress.candidateId }?.displayAddress
                ?: listOfNotNull(
                    listOfNotNull(savedAddress.street, savedAddress.houseNumber?.toString(), savedAddress.addition)
                        .joinToString(" ")
                        .ifBlank { null },
                    listOfNotNull(savedAddress.postcode, savedAddress.city).joinToString(" ").ifBlank { null },
                ).joinToString(", ")
            if (display.isNotBlank()) {
                Text(display, style = MaterialTheme.typography.bodyLarge)
            }
            Text(stringResource(R.string.address_can_still_change), style = MaterialTheme.typography.bodyMedium)
        }
        TextButton(onClick = viewModel::goConversation, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.onSurfaceVariant)) {
            Text(stringResource(R.string.adjust))
        }
    }
}

@Composable
private fun ReviewScreen(state: AppUiState, viewModel: AppViewModel) {
    val intake = state.intake
    PaperScaffold {
        BrandMark()
        Text(stringResource(R.string.confirm), style = MaterialTheme.typography.headlineLarge)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(intake?.summary?.residentText.orEmpty(), style = MaterialTheme.typography.bodyLarge)
        LedoCard(intake, onField = viewModel::openField)
        if (intake?.conversationLanguage?.startsWith("nl") != true) {
            Text(stringResource(R.string.work_description), style = MaterialTheme.typography.titleMedium)
            Text(intake?.summary?.workDescriptionNl.orEmpty())
        }
        intake?.address?.let { Text(listOfNotNull(it.street, it.houseNumber?.toString(), it.addition, it.postcode, it.city).joinToString(" "), style = MaterialTheme.typography.bodyLarge) }
        PrimaryAction(text = stringResource(R.string.confirm), onClick = viewModel::confirm, enabled = !state.busy, contentDescription = "confirm")
        TextButton(onClick = viewModel::goConversation, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.onSurfaceVariant)) {
            Text(stringResource(R.string.adjust))
        }
    }
}

@Composable
private fun CompletedScreen(state: AppUiState, viewModel: AppViewModel) {
    PaperScaffold {
        BrandMark()
        Text(stringResource(R.string.saved), style = MaterialTheme.typography.headlineLarge)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Text(state.intake?.reportId.orEmpty(), style = MaterialTheme.typography.titleMedium)
        Text(state.intake?.summary?.workDescriptionNl.orEmpty(), style = MaterialTheme.typography.bodyLarge)
        state.intake?.address?.let { Text(listOfNotNull(it.street, it.houseNumber?.toString(), it.addition, it.postcode, it.city).joinToString(" ")) }
        PrimaryAction(text = stringResource(R.string.new_report), onClick = viewModel::goStart)
    }
}

@Composable
private fun ReviewRequiredScreen(state: AppUiState, viewModel: AppViewModel) {
    PaperScaffold {
        BrandMark()
        Text(state.intake?.nextQuestion?.text.orEmpty(), style = MaterialTheme.typography.headlineSmall)
        Text(stringResource(R.string.demo_no_staff), style = MaterialTheme.typography.bodyLarge)
        PrimaryAction(text = stringResource(R.string.new_intake), onClick = viewModel::goStart)
    }
}

@Composable
private fun FieldEditScreen(state: AppUiState, viewModel: AppViewModel) {
    PaperScaffold {
        BrandMark()
        Text(fieldTitle(state.editingField), style = MaterialTheme.typography.headlineLarge)
        Text(stringResource(R.string.dependent_review), style = MaterialTheme.typography.bodySmall)
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        OutlinedTextField(state.editingValue, viewModel::onEditValue, modifier = Modifier.fillMaxWidth(), colors = paperFieldColors())
        PrimaryAction(text = stringResource(R.string.send), onClick = { viewModel.submitField("set") })
        if (state.editingField == "cause") {
            TextButton(onClick = { viewModel.submitField("mark_unknown") }) { Text(stringResource(R.string.i_dont_know)) }
        }
        TextButton(onClick = { viewModel.submitField("clear") }, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.onSurfaceVariant)) {
            Text(stringResource(R.string.clear_field))
        }
        TextButton(onClick = viewModel::goConversation, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.onSurfaceVariant)) {
            Text(stringResource(R.string.cancel))
        }
    }
}

@Composable
private fun PaperScaffold(
    footer: (@Composable ColumnScope.() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Scaffold(containerColor = MaterialTheme.colorScheme.background) { padding ->
        Box(
            Modifier
                .padding(padding)
                .fillMaxSize()
                .imePadding()
                .navigationBarsPadding(),
            contentAlignment = Alignment.TopCenter,
        ) {
            Column(
                Modifier
                    .widthIn(max = 720.dp)
                    .fillMaxSize()
                    .padding(horizontal = 24.dp, vertical = 20.dp),
            ) {
                Column(
                    Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(16.dp),
                    horizontalAlignment = Alignment.Start,
                    content = content,
                )
                if (footer != null) {
                    Spacer(Modifier.height(16.dp))
                    footer()
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun BrandTopBar() {
    Column {
        TopAppBar(
            title = { BrandMark() },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.background,
                titleContentColor = MaterialTheme.colorScheme.onBackground,
            ),
        )
        HorizontalDivider(color = MaterialTheme.colorScheme.outline)
    }
}

@Composable
private fun PrivacyFooter() {
    Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Icon(
            Icons.Outlined.Lock,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(18.dp).padding(top = 2.dp),
        )
        Text(stringResource(R.string.privacy_safe), style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun BrandMark() {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Icon(
            painter = painterResource(R.drawable.ic_house_mark),
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onBackground,
            modifier = Modifier.size(22.dp),
        )
        Text(stringResource(R.string.app_name), style = MaterialTheme.typography.titleMedium)
    }
}

@Composable
private fun FooterAction(
    onClick: () -> Unit,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    outlined: Boolean,
    modifier: Modifier = Modifier,
) {
    val body: @Composable () -> Unit = {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 4.dp),
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                textAlign = TextAlign.Center,
            )
        }
    }
    if (outlined) {
        OutlinedButton(
            onClick = onClick,
            modifier = modifier.height(56.dp),
            colors = paperOutlineButton(),
            border = BorderStroke(1.dp, MaterialTheme.colorScheme.outline),
            contentPadding = PaddingValues(0.dp),
            shape = MaterialTheme.shapes.medium,
        ) { body() }
    } else {
        Button(
            onClick = onClick,
            modifier = modifier.height(56.dp),
            colors = paperPrimaryButton(),
            contentPadding = PaddingValues(0.dp),
            shape = MaterialTheme.shapes.medium,
        ) { body() }
    }
}

@Composable
private fun PrimaryAction(
    text: String,
    onClick: () -> Unit,
    enabled: Boolean = true,
    contentDescription: String? = null,
) {
    Button(
        onClick = onClick,
        enabled = enabled,
        modifier = Modifier
            .fillMaxWidth()
            .height(52.dp)
            .then(if (contentDescription != null) Modifier.semantics { this.contentDescription = contentDescription } else Modifier),
        shape = MaterialTheme.shapes.medium,
        colors = paperPrimaryButton(),
        contentPadding = ButtonDefaults.ContentPadding,
    ) {
        Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
            Text(text, style = MaterialTheme.typography.labelLarge)
            Icon(
                Icons.AutoMirrored.Filled.KeyboardArrowRight,
                contentDescription = null,
                modifier = Modifier.align(Alignment.CenterEnd),
            )
        }
    }
}

@Composable
private fun paperPrimaryButton() = ButtonDefaults.buttonColors(
    containerColor = MaterialTheme.colorScheme.primary,
    contentColor = MaterialTheme.colorScheme.onPrimary,
    disabledContainerColor = MaterialTheme.colorScheme.outline,
    disabledContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
)

@Composable
private fun paperOutlineButton() = ButtonDefaults.outlinedButtonColors(
    contentColor = MaterialTheme.colorScheme.onBackground,
)

@Composable
private fun paperFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = MaterialTheme.colorScheme.primary,
    unfocusedBorderColor = MaterialTheme.colorScheme.outline,
    focusedContainerColor = MaterialTheme.colorScheme.surface,
    unfocusedContainerColor = MaterialTheme.colorScheme.surface,
)

@Composable
private fun Transcript(lines: List<TranscriptLine>) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        lines.forEach { line ->
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    if (line.speaker == "assistant") stringResource(R.string.app_name) else line.speaker,
                    style = MaterialTheme.typography.labelMedium,
                )
                Text(line.text, style = MaterialTheme.typography.bodyLarge)
            }
        }
    }
}

@Composable
private fun AddressCandidatesCard(intake: Intake?, selectedId: String?, onVerify: (String) -> Unit) {
    val candidates = intake?.address?.candidates.orEmpty()
    if (candidates.isEmpty()) {
        return
    }
    val verifiedId = intake?.address?.candidateId
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (candidates.size > 1) {
            Text(stringResource(R.string.several_addresses), style = MaterialTheme.typography.titleMedium)
        } else {
            Text(stringResource(R.string.pick_address), style = MaterialTheme.typography.titleMedium)
        }
        candidates.forEach { candidate ->
            val chosen = candidate.candidateId == selectedId || candidate.candidateId == verifiedId
            Surface(
                modifier = Modifier.fillMaxWidth().clickable { onVerify(candidate.candidateId) },
                shape = MaterialTheme.shapes.medium,
                color = if (chosen) MaterialTheme.colorScheme.primary.copy(alpha = 0.12f) else MaterialTheme.colorScheme.surface,
                border = BorderStroke(1.dp, if (chosen) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline),
            ) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(candidate.displayAddress, style = MaterialTheme.typography.titleMedium)
                    Text(
                        if (chosen && intake?.address?.verificationStatus == "verified") {
                            stringResource(R.string.address_saved)
                        } else {
                            stringResource(R.string.verify_address)
                        },
                        style = MaterialTheme.typography.labelLarge,
                        color = MaterialTheme.colorScheme.primary,
                    )
                }
            }
        }
    }
}

@Composable
private fun UseMyLocationButton(state: AppUiState, viewModel: AppViewModel) {
    val context = LocalContext.current
    val locator = remember(context) { DeviceAddressLocator(context.applicationContext) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { grants ->
        if (grants[Manifest.permission.ACCESS_FINE_LOCATION] == true || grants[Manifest.permission.ACCESS_COARSE_LOCATION] == true) {
            viewModel.lookupFromGps(locator)
        } else {
            viewModel.locationDenied()
        }
    }
    Button(
        onClick = {
            if (locator.hasPermission()) {
                viewModel.lookupFromGps(locator)
            } else {
                launcher.launch(
                    arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
                )
            }
        },
        enabled = !state.busy,
        modifier = Modifier.fillMaxWidth().height(52.dp),
        shape = MaterialTheme.shapes.medium,
        colors = paperPrimaryButton(),
    ) {
        Icon(Icons.Default.MyLocation, contentDescription = null)
        Text(stringResource(R.string.use_my_location), modifier = Modifier.padding(start = 8.dp))
    }
}

@Composable
private fun LedoCard(intake: Intake?, onField: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(stringResource(R.string.what_we_know), style = MaterialTheme.typography.titleMedium)
        listOf("location" to R.string.location, "element" to R.string.element, "defect" to R.string.defect, "cause" to R.string.cause).forEachIndexed { index, (key, label) ->
            if (index > 0) {
                HorizontalDivider(color = MaterialTheme.colorScheme.outline)
            }
            val field = intake?.fields?.get(key)
            Row(
                Modifier.fillMaxWidth().clickable { onField(key) }.padding(vertical = 12.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(label), style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(fieldLabel(field?.state, field?.displayValue ?: field?.value), style = MaterialTheme.typography.titleMedium)
            }
        }
        intake?.hypotheses.orEmpty().forEach {
            Text(stringResource(R.string.possible_cause) + ": " + (it.text ?: ""), style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun StatusChip(label: String) {
    Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
}

@Composable
private fun fieldTitle(key: String?): String = when (key) {
    "location" -> stringResource(R.string.location)
    "element" -> stringResource(R.string.element)
    "defect" -> stringResource(R.string.defect)
    "cause" -> stringResource(R.string.cause)
    else -> key.orEmpty()
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
    "idle_closed" -> stringResource(R.string.idle_closed)
    else -> stringResource(R.string.disconnected)
}

@Composable
private fun LanguagePickerDialog(current: String, onSelect: (String) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.language_picker_title)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                SupportedLanguages.all.forEach { language ->
                    val selected = UiLocale.fromTag(current) == language.tag
                    Text(
                        language.nativeName + "  ·  " + language.nameNl,
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable { onSelect(language.tag) }
                            .padding(vertical = 10.dp),
                        style = if (selected) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge,
                        color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onBackground,
                    )
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

@Composable
private fun UiLanguageOfferDialog(question: String, onYes: () -> Unit, onNo: () -> Unit) {
    AlertDialog(
        onDismissRequest = onNo,
        title = { Text(stringResource(R.string.change_language)) },
        text = { Text(question) },
        confirmButton = {
            TextButton(onClick = onYes) { Text(stringResource(R.string.switch_ui_yes)) }
        },
        dismissButton = {
            TextButton(onClick = onNo) { Text(stringResource(R.string.switch_ui_no)) }
        },
    )
}
