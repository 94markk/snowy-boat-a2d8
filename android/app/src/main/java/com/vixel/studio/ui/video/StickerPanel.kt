package com.vixel.studio.ui.video

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.vixel.studio.core.model.StickerArt
import com.vixel.studio.core.model.StickerLibrary
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.StickerShape
import com.vixel.studio.ui.common.Chip
import com.vixel.studio.ui.common.OverlayHost

@Composable
fun StickerPanel(state: OverlayHost) {
    val stickers = state.overlays.filterIsInstance<StickerOverlay>()
    val selected = state.selectedOverlay as? StickerOverlay

    LazyColumn(contentPadding = PaddingValues(bottom = 24.dp)) {
        item {
            SectionLabel("Emoji")
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                StickerLibrary.emoji.forEach { glyph ->
                    Box(
                        modifier = Modifier
                            .size(44.dp)
                            .clip(RoundedCornerShape(12.dp))
                            .background(MaterialTheme.colorScheme.surface)
                            .border(
                                1.dp,
                                MaterialTheme.colorScheme.outlineVariant,
                                RoundedCornerShape(12.dp),
                            )
                            .clickable {
                                state.addOverlay(
                                    newStickerAt(state.playheadUs, state.timelineDurationUs)
                                        .copy(art = StickerArt.Emoji(glyph)),
                                )
                            },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(glyph, fontSize = 22.sp)
                    }
                }
            }
        }

        item {
            SectionLabel("Shapes")
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                StickerShape.entries.forEach { shape ->
                    Chip(
                        label = shape.label,
                        selected = false,
                        onClick = {
                            state.addOverlay(
                                newStickerAt(state.playheadUs, state.timelineDurationUs)
                                    .copy(
                                        art = StickerArt.Shape(shape, 0xFFFFFFFF.toInt()),
                                    ),
                            )
                        },
                    )
                }
            }
        }

        if (stickers.isNotEmpty()) {
            item {
                SectionLabel("On the timeline")
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    stickers.forEach { sticker ->
                        Chip(
                            label = when (val art = sticker.art) {
                                is StickerArt.Emoji -> art.glyph
                                is StickerArt.Shape -> art.shape.label
                            },
                            selected = sticker.id == state.selectedOverlayId,
                            onClick = { state.selectedOverlayId = sticker.id },
                        )
                    }
                }
            }
        }

        if (selected == null) {
            item {
                Text(
                    "Tap an emoji or shape to drop it at the playhead.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(16.dp),
                )
            }
            return@LazyColumn
        }

        item {
            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                TextButton(onClick = { state.removeSelectedOverlay() }) { Text("Delete") }
            }
        }

        val art = selected.art
        if (art is StickerArt.Shape) {
            item {
                SectionLabel("Colour")
                ColorRow(StickerLibrary.shapeColors, art.color) { value ->
                    state.commitSelectedOverlay {
                        (it as StickerOverlay).copy(art = StickerArt.Shape(art.shape, value))
                    }
                }
            }
        }

        item {
            OverlaySlider(
                label = "Size",
                value = selected.sizeFraction,
                range = 0.05f..0.7f,
                display = "${(selected.sizeFraction * 100).toInt()}%",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelectedOverlay { (it as StickerOverlay).copy(sizeFraction = v) }
                },
                onFinished = { state.endGesture() },
            )
        }

        item { PlacementControls(selected, state) }
        item { OverlayMotionControls(selected, state) }
        item { TimingControls(selected, state) }
    }
}
