package com.vixel.studio.ui.theme

import androidx.compose.ui.graphics.Color

/**
 * Editor palette. Deliberately dark and low-chroma so that the media on the
 * canvas — not the chrome around it — is what the eye grades against.
 *
 * The shell sits at true black rather than a dark grey. On the OLED panel of
 * most phones that makes the chrome disappear at the edge of the preview, so
 * the frame reads as the only lit thing on screen, and the black bars beside a
 * portrait clip stop looking like part of the UI.
 *
 * The steps are close together on purpose: surfaces are separated by one or
 * two values, which is enough to read as layered without turning the timeline
 * into a stack of competing grey boxes.
 */
val Ink900 = Color(0xFF000000)
val Ink800 = Color(0xFF0A0A0C)
val Ink700 = Color(0xFF131316)
val Ink600 = Color(0xFF1C1C20)
val Ink500 = Color(0xFF2A2A30)
val Ink400 = Color(0xFF3A3A42)

val Mist200 = Color(0xFFF5F5F7)
val Mist400 = Color(0xFF9B9BA6)
val Mist600 = Color(0xFF66666F)

/**
 * One accent, used for anything live: the playhead, a selected clip, a slider
 * that is off its default. Keeping it to a single hue means a glance at the
 * screen answers "what have I changed" without reading any labels.
 */
val Aqua = Color(0xFF1FE0E8)
val AquaDim = Color(0xFF0E6F74)
val Violet = Color(0xFFB08CFF)
val Azure = Color(0xFF5AA9FF)
val Amber = Color(0xFFFFC46B)
val Rose = Color(0xFFFF6B81)
