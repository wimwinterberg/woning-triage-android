package nl.woningtriage.app.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Sage = Color(0xFF1B4D3E)
private val SageContainer = Color(0xFFDDE8E2)
private val Gold = Color(0xFFC4A35A)
private val Sand = Color(0xFFF4F1EA)
private val Ink = Color(0xFF1C1915)
private val Surface = Color(0xFFFFFCF7)

private val WoningtriageColors = lightColorScheme(
    primary = Sage,
    onPrimary = Color.White,
    primaryContainer = SageContainer,
    onPrimaryContainer = Sage,
    secondary = Gold,
    onSecondary = Ink,
    background = Sand,
    onBackground = Ink,
    surface = Surface,
    onSurface = Ink,
    surfaceVariant = Color(0xFFEDE6D8),
    onSurfaceVariant = Color(0xFF5C574E),
    outline = Color(0xFFD4CBBA),
)

@Composable
fun WoningtriageTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = WoningtriageColors, content = content)
}
