package nl.woningtriage.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.lightColorScheme
import androidx.compose.ui.graphics.Color
import nl.woningtriage.app.ui.AppViewModel
import nl.woningtriage.app.ui.WoningtriageRoot

class MainActivity : ComponentActivity() {
    private val app get() = application as WoningtriageApp
    private val viewModel: AppViewModel by viewModels {
        AppViewModel.factory(app.api, app.tokenStore, app.voiceClient)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MaterialTheme(
                colorScheme = lightColorScheme(
                    primary = Color(0xFF1B4D3E),
                    secondary = Color(0xFFC4A35A),
                    background = Color(0xFFF4F1EA),
                ),
            ) {
                Surface { WoningtriageRoot(viewModel) }
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
