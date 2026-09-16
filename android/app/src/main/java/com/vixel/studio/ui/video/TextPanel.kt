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
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.TextAlignment
import com.vixel.studio.core.model.TextFont
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.ui.common.Chip
import com.vixel.studio.ui.common.OverlayHost

private val textColors = listOf(
    0xFFFFFFFF.toInt(), 0xFF000000.toInt(), 0xFF6FE3C9.toInt(), 0xFFB08CFF.toInt(),
    0xFF5AA9FF.toInt(), 0xFFFFC46B.toInt(), 0xFFFF7A90.toInt(), 0xFF3DDC84.toInt(),
)

private val plateColors = listOf(
    0x00000000, 0xCC000000.toInt(), 0xCCFFFFFF.toInt(), 0xCC6FE3C9.toInt(),
    0xCCB08CFF.toInt(), 0xCCFF7A90.toInt(),
)

@Composable
fun TextPanel(state: OverlayHost, onMessage: (String) -> Unit = {}) {
    val texts = state.overlays.filterIsInstance<TextOverlay>()
    val selected = state.selectedOverlay as? TextOverlay

    LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
        (state as? VideoEditorState)?.let { videoState ->
            item { CaptionControls(videoState, onMessage) }
        }

        item {
            Row(
                modifier = Modifier.fillMaxWidth().padding(16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Button(
                    onClick = {
                        // New text starts at the playhead and runs three
                        // seconds, clamped to the end of the timeline.
                        val start = state.playheadUs
                        val end = minOf(
                            start + 3_000_000L,
                            state.timelineDurationUs.coerceAtLeast(start + MIN_OVERLAY_US),
                        )
                        state.addOverlay(TextOverlay(startUs = start, endUs = end))
                    },
                ) { Text("Add text") }

                if (selected != null) {
                    TextButton(onClick = { state.removeSelectedOverlay() }) { Text("Delete") }
                }
            }
        }

        if (texts.isNotEmpty()) {
            item {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    texts.forEach { overlay ->
                        Chip(
                            label = overlay.text.take(14).ifEmpty { "(empty)" },
                            selected = overlay.id == state.selectedOverlayId,
                            onClick = { state.selectedOverlayId = overlay.id },
                        )
                    }
                }
            }
        }

        if (selected == null) {
            item {
                Text(
                    "Add a caption, then style and time it here.",
                    style = androidx.compose.material3.MaterialTheme.typography.bodySmall,
                    color = androidx.compose.material3.MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(16.dp),
                )
            }
            return@LazyColumn
        }

        item {
            OutlinedTextField(
                value = selected.text,
                onValueChange = { value ->
                    state.updateSelectedOverlay { (it as TextOverlay).copy(text = value) }
                },
                label = { Text("Text") },
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
            )
        }

        item {
            SectionLabel("Font")
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                TextFont.entries.forEach { font ->
                    Chip(
                        label = font.label,
                        selected = font == selected.style.font,
                        onClick = {
                            state.commitSelectedOverlay {
                                (it as TextOverlay).copy(style = it.style.copy(font = font))
                            }
                        },
                    )
                }
            }
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Chip(
                    label = "Bold",
                    selected = selected.style.bold,
                    onClick = {
                        state.commitSelectedOverlay {
                            (it as TextOverlay).copy(style = it.style.copy(bold = !it.style.bold))
                        }
                    },
                )
                Chip(
                    label = "Italic",
                    selected = selected.style.italic,
                    onClick = {
                        state.commitSelectedOverlay {
                            (it as TextOverlay).copy(style = it.style.copy(italic = !it.style.italic))
                        }
                    },
                )
                TextAlignment.entries.forEach { alignment ->
                    Chip(
                        label = alignment.label,
                        selected = alignment == selected.style.alignment,
                        onClick = {
                            state.commitSelectedOverlay {
                                (it as TextOverlay).copy(style = it.style.copy(alignment = alignment))
                            }
                        },
                    )
                }
            }
        }

        item {
            OverlaySlider(
                label = "Size",
                value = selected.style.sizeFraction,
                range = 0.02f..0.25f,
                display = "${(selected.style.sizeFraction * 1000).toInt()}",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelectedOverlay {
                        (it as TextOverlay).copy(style = it.style.copy(sizeFraction = v))
                    }
                },
                onFinished = { state.endGesture() },
            )
        }

        item {
            SectionLabel("Colour")
            ColorRow(textColors, selected.style.color) { value ->
                state.commitSelectedOverlay {
                    (it as TextOverlay).copy(style = it.style.copy(color = value))
                }
            }
        }

        item {
            SectionLabel("Outline")
            OverlaySlider(
                label = "Width",
                value = selected.style.strokeWidth,
                range = 0f..0.12f,
                display = "${(selected.style.strokeWidth * 1000).toInt()}",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelectedOverlay {
                        (it as TextOverlay).copy(style = it.style.copy(strokeWidth = v))
                    }
                },
                onFinished = { state.endGesture() },
            )
            ColorRow(textColors, selected.style.strokeColor) { value ->
                state.commitSelectedOverlay {
                    (it as TextOverlay).copy(style = it.style.copy(strokeColor = value))
                }
            }
        }

        item {
            SectionLabel("Shadow")
            OverlaySlider(
                label = "Blur",
                value = selected.style.shadowRadius,
                range = 0f..0.4f,
                display = "${(selected.style.shadowRadius * 1000).toInt()}",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelectedOverlay {
                        (it as TextOverlay).copy(style = it.style.copy(shadowRadius = v))
                    }
                },
                onFinished = { state.endGesture() },
            )
        }

        item {
            SectionLabel("Plate behind text")
            ColorRow(plateColors, selected.style.backgroundColor) { value ->
                state.commitSelectedOverlay {
                    (it as TextOverlay).copy(style = it.style.copy(backgroundColor = value))
                }
            }
        }

        item { PlacementControls(selected, state) }
        item { OverlayMotionControls(selected, state) }
        (state as? VideoEditorState)?.let { videoState ->
            item { TrackingControls(selected, videoState) }
        }
        item { TimingControls(selected, state) }
    }
}

/** Kept so the sticker panel can share the empty-state wording. */
internal fun defaultStickerTiming(startUs: Long, projectDurationUs: Long): Pair<Long, Long> {
    val end = minOf(
        startUs + 3_000_000L,
        projectDurationUs.coerceAtLeast(startUs + MIN_OVERLAY_US),
    )
    return startUs to end
}

internal fun newStickerAt(startUs: Long, projectDurationUs: Long): StickerOverlay {
    val (start, end) = defaultStickerTiming(startUs, projectDurationUs)
    return StickerOverlay(startUs = start, endUs = end)
}
