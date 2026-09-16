package com.vixel.studio.ui.video

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.Keyframes
import com.vixel.studio.core.model.KeyframeProperty
import com.vixel.studio.core.model.Mask
import com.vixel.studio.core.model.MaskShape
import com.vixel.studio.ui.common.Chip

/**
 * Keyframes and masking for the selected clip.
 *
 * Keys are placed at the playhead rather than through a curve editor: on a
 * phone, "put the value I can see right here" is both easier to hit and easier
 * to reason about than dragging control points on a small graph.
 */
@Composable
fun MotionPanel(state: VideoEditorState) {
    val clip = state.selectedClip
    if (clip == null) {
        PanelHintText("Select a clip to animate or mask it")
        return
    }

    val index = state.project.clips.indexOfFirst { it.id == clip.id }
    val localUs = (state.positionUs - state.project.startOf(index)).coerceAtLeast(0L)

    LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
        item { KenBurnsRow(state, clip) }
        item { KeyframeRows(state, clip, localUs) }
        item { MaskSection(state, clip) }
        item { BlendNote() }
    }
}

@Composable
private fun KenBurnsRow(state: VideoEditorState, clip: Clip) {
    SectionLabel("Quick motion")
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        val duration = clip.timelineDurationUs
        Chip("Zoom in", selected = false, onClick = {
            state.commitSelected { it.copy(keyframes = Keyframes.kenBurns(duration, 1f, 1.25f)) }
        })
        Chip("Zoom out", selected = false, onClick = {
            state.commitSelected { it.copy(keyframes = Keyframes.kenBurns(duration, 1.25f, 1f)) }
        })
        Chip("Pan left", selected = false, onClick = {
            state.commitSelected {
                it.copy(keyframes = Keyframes.kenBurns(duration, 1.2f, 1.2f, panX = 0.2f))
            }
        })
        Chip("Pan right", selected = false, onClick = {
            state.commitSelected {
                it.copy(keyframes = Keyframes.kenBurns(duration, 1.2f, 1.2f, panX = -0.2f))
            }
        })
        Chip("Pan up", selected = false, onClick = {
            state.commitSelected {
                it.copy(keyframes = Keyframes.kenBurns(duration, 1.2f, 1.2f, panY = 0.2f))
            }
        })
    }
    if (Keyframes.hasAny(clip.keyframes)) {
        TextButton(
            onClick = { state.commitSelected { it.copy(keyframes = emptyList()) } },
            modifier = Modifier.padding(horizontal = 12.dp),
        ) { Text("Clear all keyframes") }
    }
}

@Composable
private fun KeyframeRows(state: VideoEditorState, clip: Clip, localUs: Long) {
    SectionLabel("Keyframes at playhead · ${formatTime(localUs)} into the clip")

    KeyframeProperty.entries.forEach { property ->
        val track = Keyframes.track(clip.keyframes, property)
        val current = Keyframes.valueAt(
            clip.keyframes, property, localUs, staticValue(clip, property),
        )

        Column(modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
            ) {
                Text(
                    property.label,
                    style = MaterialTheme.typography.labelMedium,
                    color = if (track != null) {
                        MaterialTheme.colorScheme.primary
                    } else {
                        MaterialTheme.colorScheme.onSurfaceVariant
                    },
                    modifier = Modifier.weight(1f),
                )
                Text(
                    "${(current * 100).toInt() / 100f}" +
                        (track?.let { "  ·  ${it.keys.size} key${if (it.keys.size == 1) "" else "s"}" } ?: ""),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            OverlaySlider(
                label = "",
                value = current,
                range = property.min..property.max,
                display = "",
                onChange = { v ->
                    state.beginGesture()
                    // Moving the slider while a track exists writes a key here;
                    // otherwise it edits the static transform.
                    state.updateSelected { c ->
                        if (track != null) {
                            c.copy(keyframes = Keyframes.setKey(c.keyframes, property, localUs, v))
                        } else {
                            c.copy(transform = applyStatic(c, property, v))
                        }
                    }
                },
                onFinished = { state.endGesture() },
            )

            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 2.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Chip("Add key", selected = false, onClick = {
                    state.commitSelected { c ->
                        c.copy(
                            keyframes = Keyframes.setKey(c.keyframes, property, localUs, current),
                        )
                    }
                })
                if (track != null) {
                    Chip("Remove key", selected = false, onClick = {
                        state.commitSelected { c ->
                            c.copy(keyframes = Keyframes.removeKey(c.keyframes, property, localUs))
                        }
                    })
                    Chip("Clear", selected = false, onClick = {
                        state.commitSelected { c ->
                            c.copy(keyframes = Keyframes.clear(c.keyframes, property))
                        }
                    })
                }
            }
        }
    }
}

private fun staticValue(clip: Clip, property: KeyframeProperty): Float = when (property) {
    KeyframeProperty.SCALE -> clip.transform.scale
    KeyframeProperty.OFFSET_X -> clip.transform.offsetX
    KeyframeProperty.OFFSET_Y -> clip.transform.offsetY
    KeyframeProperty.ROTATION -> clip.transform.rotationDegrees
    KeyframeProperty.OPACITY -> 1f
}

private fun applyStatic(clip: Clip, property: KeyframeProperty, value: Float) =
    when (property) {
        KeyframeProperty.SCALE -> clip.transform.copy(scale = value)
        KeyframeProperty.OFFSET_X -> clip.transform.copy(offsetX = value)
        KeyframeProperty.OFFSET_Y -> clip.transform.copy(offsetY = value)
        KeyframeProperty.ROTATION -> clip.transform.copy(rotationDegrees = value)
        KeyframeProperty.OPACITY -> clip.transform
    }

@Composable
private fun MaskSection(state: VideoEditorState, clip: Clip) {
    val mask = clip.mask

    SectionLabel("Mask")
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        MaskShape.entries.forEach { shape ->
            Chip(
                label = shape.label,
                selected = shape == mask.shape,
                onClick = { state.commitSelected { it.copy(mask = it.mask.copy(shape = shape)) } },
            )
        }
    }

    if (!mask.isActive) {
        Text(
            "A mask hides part of the clip, showing the canvas colour through it.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(horizontal = 16.dp),
        )
        return
    }

    MaskSliders(state, mask)
}

@Composable
private fun MaskSliders(state: VideoEditorState, mask: Mask) {
    OverlaySlider(
        label = "Centre X",
        value = mask.centerX,
        range = 0f..1f,
        display = "${(mask.centerX * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(centerX = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Centre Y",
        value = mask.centerY,
        range = 0f..1f,
        display = "${(mask.centerY * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(centerY = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Width",
        value = mask.width,
        range = 0.02f..1f,
        display = "${(mask.width * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(width = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Height",
        value = mask.height,
        range = 0.02f..1f,
        display = "${(mask.height * 100).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(height = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Rotation",
        value = mask.rotationDegrees,
        range = -180f..180f,
        display = "${mask.rotationDegrees.toInt()}°",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(rotationDegrees = v)) }
        },
        onFinished = { state.endGesture() },
    )
    OverlaySlider(
        label = "Feather",
        value = mask.feather,
        range = 0f..0.4f,
        display = "${(mask.feather * 250).toInt()}%",
        onChange = { v ->
            state.beginGesture()
            state.updateSelected { it.copy(mask = it.mask.copy(feather = v)) }
        },
        onFinished = { state.endGesture() },
    )
    Row(modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
        Chip(
            label = "Invert",
            selected = mask.invert,
            onClick = {
                state.commitSelected { it.copy(mask = it.mask.copy(invert = !it.mask.invert)) }
            },
        )
    }
}

@Composable
private fun BlendNote() {
    SectionLabel("Blend")
    Text(
        "Blend modes apply to text and stickers, on their own tabs. " +
            "Clips play one after another rather than stacking, so there is " +
            "nothing underneath a clip to blend with.",
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(horizontal = 16.dp, vertical = 2.dp),
    )
}

@Composable
internal fun PanelHintText(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(24.dp),
    )
}
