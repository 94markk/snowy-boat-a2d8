package com.vixel.studio.ui.video

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.BlendMode
import com.vixel.studio.core.model.KeyframeProperty
import com.vixel.studio.core.model.Keyframes
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.core.model.OverlayAnimation
import com.vixel.studio.ui.common.Chip
import com.vixel.studio.ui.common.OverlayHost

/** Labelled slider used across the overlay panels. */
@Composable
fun OverlaySlider(
    label: String,
    value: Float,
    range: ClosedFloatingPointRange<Float>,
    display: String,
    onChange: (Float) -> Unit,
    onFinished: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 2.dp)) {
        Row(modifier = Modifier.fillMaxWidth()) {
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
            )
            Text(
                display,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
        }
        Slider(
            value = value.coerceIn(range.start, range.endInclusive),
            onValueChange = onChange,
            onValueChangeFinished = onFinished,
            valueRange = range,
        )
    }
}

@Composable
fun SectionLabel(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(start = 16.dp, top = 10.dp, bottom = 2.dp),
    )
}

@Composable
fun ColorRow(colors: List<Int>, selected: Int, onPick: (Int) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        colors.forEach { value ->
            Box(
                modifier = Modifier
                    .size(30.dp)
                    .clip(CircleShape)
                    .background(Color(value))
                    .border(
                        if (value == selected) 3.dp else 1.dp,
                        if (value == selected) {
                            MaterialTheme.colorScheme.primary
                        } else {
                            MaterialTheme.colorScheme.outlineVariant
                        },
                        CircleShape,
                    )
                    .clickable { onPick(value) },
            )
        }
    }
}

/** Position, rotation and opacity — shared by text and stickers. */
@Composable
fun PlacementControls(overlay: Overlay, state: OverlayHost) {
    val t = overlay.transform
    SectionLabel("Placement")
    OverlaySlider(
        label = "Horizontal",
        value = t.x,
        range = 0f..1f,
        display = "${(t.x * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay { it.withTransform(it.transform.copy(x = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Vertical",
        value = t.y,
        range = 0f..1f,
        display = "${(t.y * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay { it.withTransform(it.transform.copy(y = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Rotation",
        value = t.rotationDegrees,
        range = -180f..180f,
        display = "${t.rotationDegrees.toInt()}°",
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay { it.withTransform(it.transform.copy(rotationDegrees = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Opacity",
        value = t.opacity,
        range = 0f..1f,
        display = "${(t.opacity * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay { it.withTransform(it.transform.copy(opacity = v)) }
        },
        onFinished = { state.endGesture() },
    )
}

/**
 * Blend mode and keyframes.
 *
 * Only the modes expressible with fixed-function GL blending are listed; the
 * rest would each cost a full-canvas read-back pass.
 */
@Composable
fun OverlayMotionControls(overlay: Overlay, state: OverlayHost) {
    SectionLabel("Blend")
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 2.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        BlendMode.entries.forEach { mode ->
            Chip(
                label = mode.label,
                selected = mode == overlay.blend,
                onClick = {
                    state.commitSelectedOverlay { current ->
                        when (current) {
                            is TextOverlay -> current.copy(blend = mode)
                            is StickerOverlay -> current.copy(blend = mode)
                        }
                    }
                },
            )
        }
    }

    if (!state.supportsTiming) return

    SectionLabel("Motion keyframes")
    Text(
        "Keys are placed at the playhead, timed from where this overlay starts.",
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(horizontal = 16.dp),
    )

    val localUs = (state.playheadUs - overlay.startUs).coerceAtLeast(0L)
    val animated = overlay.transformAt(state.playheadUs)

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        KeyframeProperty.entries.forEach { property ->
            val existing = Keyframes.track(overlay.keyframes, property)
            Chip(
                label = existing?.let { "${property.label} (${it.keys.size})" } ?: property.label,
                selected = existing != null,
                onClick = {
                    val value = when (property) {
                        KeyframeProperty.SCALE -> animated.scale
                        KeyframeProperty.OFFSET_X -> animated.x
                        KeyframeProperty.OFFSET_Y -> animated.y
                        KeyframeProperty.ROTATION -> animated.rotationDegrees
                        KeyframeProperty.OPACITY -> animated.opacity
                    }
                    state.commitSelectedOverlay {
                        it.withKeyframes(Keyframes.setKey(it.keyframes, property, localUs, value))
                    }
                },
            )
        }
    }

    if (Keyframes.hasAny(overlay.keyframes)) {
        Row(modifier = Modifier.padding(horizontal = 12.dp)) {
            TextButton(
                onClick = { state.commitSelectedOverlay { it.withKeyframes(emptyList()) } },
            ) { Text("Clear keyframes") }
        }
    }
}

/** Start/end on the timeline, plus entry and exit animations. */
@Composable
fun TimingControls(overlay: Overlay, state: OverlayHost) {
    if (!state.supportsTiming) {
        AnimationControls(overlay, state)
        return
    }
    val projectDuration = state.timelineDurationUs.coerceAtLeast(1_000_000L)

    SectionLabel("Timing")
    OverlaySlider(
        label = "Start",
        value = overlay.startUs.toFloat(),
        range = 0f..projectDuration.toFloat(),
        display = formatTimePrecise(overlay.startUs),
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay {
                val start = v.toLong()
                it.withTiming(start, maxOf(it.endUs, start + MIN_OVERLAY_US))
            }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "End",
        value = overlay.endUs.toFloat(),
        range = MIN_OVERLAY_US.toFloat()..(projectDuration + MIN_OVERLAY_US).toFloat(),
        display = formatTimePrecise(overlay.endUs),
        onChange = { v ->
            state.beginGesture()
            state.updateSelectedOverlay {
                val end = v.toLong()
                it.withTiming(minOf(it.startUs, end - MIN_OVERLAY_US), end)
            }
        },
        onFinished = { state.endGesture() },
    )
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Chip(
            label = "Set start to playhead",
            selected = false,
            onClick = {
                state.commitSelectedOverlay {
                    val start = state.playheadUs
                    it.withTiming(start, maxOf(it.endUs, start + MIN_OVERLAY_US))
                }
            },
        )
        Chip(
            label = "End at playhead",
            selected = false,
            onClick = {
                state.commitSelectedOverlay {
                    val end = maxOf(state.playheadUs, it.startUs + MIN_OVERLAY_US)
                    it.withTiming(it.startUs, end)
                }
            },
        )
    }

    AnimationControls(overlay, state)
}

@Composable
private fun AnimationControls(overlay: Overlay, state: OverlayHost) {
    SectionLabel("Animation in")
    AnimationRow(overlay.animationIn) { animation ->
        state.commitSelectedOverlay { current ->
            when (current) {
                is com.vixel.studio.core.model.TextOverlay -> current.copy(animationIn = animation)
                is com.vixel.studio.core.model.StickerOverlay -> current.copy(animationIn = animation)
            }
        }
    }

    SectionLabel("Animation out")
    AnimationRow(overlay.animationOut) { animation ->
        state.commitSelectedOverlay { current ->
            when (current) {
                is com.vixel.studio.core.model.TextOverlay -> current.copy(animationOut = animation)
                is com.vixel.studio.core.model.StickerOverlay -> current.copy(animationOut = animation)
            }
        }
    }
}

@Composable
private fun AnimationRow(selected: OverlayAnimation, onPick: (OverlayAnimation) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 2.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        OverlayAnimation.entries.forEach { animation ->
            Chip(
                label = animation.label,
                selected = animation == selected,
                onClick = { onPick(animation) },
            )
        }
    }
}

/** An overlay shorter than this is not useful and breaks the timing sliders. */
const val MIN_OVERLAY_US = 200_000L
