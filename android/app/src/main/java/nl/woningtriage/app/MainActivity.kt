package nl.woningtriage.app

import android.content.Context
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import nl.woningtriage.app.ui.AppViewModel
import nl.woningtriage.app.ui.UiLocaleStore
import nl.woningtriage.app.ui.WoningtriageRoot
import nl.woningtriage.app.ui.WoningtriageTheme

class MainActivity : ComponentActivity() {
    private val app get() = application as WoningtriageApp
    private val viewModel: AppViewModel by viewModels {
        AppViewModel.factory(app.api, app.tokenStore, app.voiceClient)
    }

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(UiLocaleStore.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        viewModel.recreateForLocale = { tag ->
            UiLocaleStore.write(this, tag)
            if (UiLocaleStore.appliedTag(this) != tag) {
                runOnUiThread { recreate() }
            }
        }
        enableEdgeToEdge()
        setContent {
            WoningtriageTheme {
                Surface(color = MaterialTheme.colorScheme.background) { WoningtriageRoot(viewModel) }
            }
        }
    }

    override fun onStop() {
        super.onStop()
        if (!isChangingConfigurations) {
            viewModel.stopConversation()
        }
    }
}
