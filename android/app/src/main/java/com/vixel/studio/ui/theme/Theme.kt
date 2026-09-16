package com.vixel.studio.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

private val VixelColors = darkColorScheme(
    primary = Aqua,
    onPrimary = Ink900,
    primaryContainer = AquaDim,
    onPrimaryContainer = Mist200,
    secondary = Violet,
    onSecondary = Ink900,
    tertiary = Azure,
    onTertiary = Ink900,
    background = Ink900,
    onBackground = Mist200,
    surface = Ink800,
    onSurface = Mist200,
    surfaceVariant = Ink600,
    onSurfaceVariant = Mist400,
    outline = Ink400,
    outlineVariant = Ink500,
    error = Rose,
    onError = Ink900,
)

private val VixelTypography = Typography(
    titleLarge = TextStyle(fontSize = 22.sp, fontWeight = FontWeight.SemiBold, letterSpacing = (-0.2).sp),
    titleMedium = TextStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold),
    bodyMedium = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.Normal),
    bodySmall = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Normal),
    labelMedium = TextStyle(fontSize = 12.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.2.sp),
    labelSmall = TextStyle(fontSize = 10.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.3.sp),
)

@Composable
fun VixelTheme(
    // The editor is dark-only on purpose: a light chrome shifts how you judge
    // exposure and colour on the canvas.
    @Suppress("UNUSED_PARAMETER") darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = VixelColors,
        typography = VixelTypography,
        content = content,
    )
}
