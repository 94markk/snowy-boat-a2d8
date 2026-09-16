package com.delicat.studio.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * Editor palette.
 *
 * True black rather than a dark grey, because on the OLED panel of most phones
 * that makes the chrome disappear at the edge of the preview: the frame reads
 * as the only lit thing on screen, and the black bars beside a portrait clip
 * stop looking like part of the interface.
 *
 * The greys sit close together on purpose. Surfaces are separated by one or
 * two steps, enough to read as layered without turning the timeline into a
 * stack of competing boxes.
 */
object Ink {
    val Black = Color(0xFF000000)
    val Near = Color(0xFF0A0A0C)
    val Panel = Color(0xFF131316)
    val Raised = Color(0xFF1C1C20)
    val Chip = Color(0xFF2A2A30)
    val Line = Color(0xFF3A3A42)
}

object Palette {
    val Primary = Color(0xFFF5F5F7)
    val Secondary = Color(0xFF9B9BA6)
    val Faint = Color(0xFF66666F)
}

/**
 * One accent, used for anything live: the playhead, a selected clip, a slider
 * that is off its default. Keeping to a single hue means a glance answers
 * "what have I changed" without reading a single label.
 */
val Accent = Color(0xFF1FE0E8)
val AccentDim = Color(0xFF0E6F74)
val Danger = Color(0xFFFF6B81)

private val DelicatColors = darkColorScheme(
    primary = Accent,
    onPrimary = Ink.Black,
    primaryContainer = AccentDim,
    onPrimaryContainer = Palette.Primary,
    secondary = Color(0xFFB08CFF),
    onSecondary = Ink.Black,
    background = Ink.Black,
    onBackground = Palette.Primary,
    surface = Ink.Near,
    onSurface = Palette.Primary,
    surfaceVariant = Ink.Raised,
    onSurfaceVariant = Palette.Secondary,
    outline = Ink.Line,
    outlineVariant = Ink.Chip,
    error = Danger,
    onError = Ink.Black,
)

private val DelicatType = Typography(
    titleLarge = TextStyle(fontSize = 24.sp, fontWeight = FontWeight.SemiBold, letterSpacing = (-0.4).sp),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold),
    bodyMedium = TextStyle(fontSize = 14.sp),
    bodySmall = TextStyle(fontSize = 12.sp),
    labelLarge = TextStyle(fontSize = 13.sp, fontWeight = FontWeight.SemiBold),
    labelMedium = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.1.sp),
    labelSmall = TextStyle(fontSize = 10.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.2.sp),
)

@Composable
fun DelicatTheme(content: @Composable () -> Unit) {
    // Dark only, deliberately. A light chrome changes how the eye judges
    // exposure and colour on the canvas, which makes grading unreliable.
    MaterialTheme(
        colorScheme = DelicatColors,
        typography = DelicatType,
        content = content,
    )
}
