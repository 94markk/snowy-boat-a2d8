package com.delicat.studio.ui.editor

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.delicat.studio.model.AdjustGroup
import com.delicat.studio.model.AdjustSpec
import com.delicat.studio.model.CanvasRatio
import com.delicat.studio.model.Clip
import com.delicat.studio.model.FitMode
import com.delicat.studio.model.Look
import com.delicat.studio.model.Looks
import com.delicat.studio.model.Transition
import com.delicat.studio.model.TransitionType
import com.delicat.studio.ui.common.ActionButton
import com.delicat.studio.ui.common.Chip
import com.delicat.studio.ui.common.ValueSlider
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Danger
import com.delicat.studio.ui.theme.Glyphs
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Palette

private val PANEL_HEIGHT = 216.dp

/** Split, duplicate, delete and the two things that change how a clip sits. */
@Composable
fun EditPanel(
    clip: Clip,
    canMoveEarlier: Boolean,
    canMoveLater: Boolean,
    onSplit: () -> Unit,
    onDuplicate: () -> Unit,
    onDelete: () -> Unit,
    onRotate: () -> Unit,
    onMove: (Int) -> Unit,
    onFit: (FitMode) -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .heightIn(min = PANEL_HEIGHT)
            .padding(vertical = 10.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState())
                .padding(horizontal = 8.dp),
        ) {
            ActionButton(Glyphs.Split, "Split", onSplit)
            ActionButton(Glyphs.Duplicate, "Copy", onDuplicate)
            ActionButton(Glyphs.Rotate, "Rotate", onRotate)
            ActionButton(
                Glyphs.Earlier, "Earlier", { onMove(-1) }, enabled = canMoveEarlier,
            )
            ActionButton(
                Glyphs.Later, "Later", { onMove(1) }, enabled = canMoveLater,
            )
            ActionButton(Glyphs.Delete, "Delete", onDelete, tint = Danger)
        }

        Text(
            "Framing",
            style = MaterialTheme.typography.labelMedium,
            color = Palette.Faint,
            modifier = Modifier.padding(start = 18.dp, top = 14.dp, bottom = 6.dp),
        )
        Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier.padding(horizontal = 18.dp),
        ) {
            FitMode.entries.forEach { mode ->
                Chip(mode.label, clip.fit == mode, { onFit(mode) })
            }
        }
    }
}

/**
 * Every colour parameter the engine has, grouped and never filtered.
 *
 * The groups come from the same registry the engine reads, so a slider cannot
 * appear here for something the shader does not implement, and nothing the
 * shader implements can be left without a slider.
 */
@Composable
fun AdjustPanel(
    clip: Clip,
    onBegin: () -> Unit,
    onChange: (String, Float) -> Unit,
) {
    var group by remember { mutableStateOf(AdjustGroup.LIGHT) }

    Column(modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT)) {
        Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 18.dp, vertical = 10.dp),
        ) {
            AdjustGroup.entries.forEach { candidate ->
                Chip(candidate.label, group == candidate, { group = candidate })
            }
        }

        Column(modifier = Modifier.fillMaxWidth().verticalScroll(rememberScrollState())) {
            AdjustSpec.inGroup(group).forEach { spec ->
                val value = clip.adjustments.get(spec.id)
                ValueSlider(
                    label = spec.label,
                    value = value,
                    range = spec.min..spec.max,
                    display = spec.display(value),
                    isDefault = value == spec.default,
                    onBegin = onBegin,
                    onChange = { onChange(spec.id, it) },
                )
            }
        }
    }
}

/** Looks, shown as the colours they produce rather than as a list of names. */
@Composable
fun LooksPanel(
    clip: Clip,
    onBegin: () -> Unit,
    onPick: (String) -> Unit,
    onStrength: (Float) -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT)) {
        Row(
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 16.dp, vertical = 12.dp),
        ) {
            Looks.ALL.forEach { look ->
                LookSwatch(
                    look = look,
                    selected = clip.adjustments.lookId == look.id,
                    onClick = {
                        onBegin()
                        onPick(look.id)
                    },
                )
            }
        }

        if (clip.adjustments.lookId != Looks.NONE_ID) {
            ValueSlider(
                label = "Strength",
                value = clip.adjustments.lookStrength,
                range = 0f..1f,
                display = "${(clip.adjustments.lookStrength * 100).toInt()}",
                isDefault = clip.adjustments.lookStrength == 1f,
                onBegin = onBegin,
                onChange = onStrength,
            )
        }
    }
}

/**
 * A look's own colours, computed by running it over a grey ramp.
 *
 * Cheaper than rendering a thumbnail through the pipeline and more honest
 * than a fixed swatch: what the chip shows is literally what the look does to
 * shadows, midtones and highlights.
 */
@Composable
private fun LookSwatch(look: Look, selected: Boolean, onClick: () -> Unit) {
    val colours = remember(look.id) {
        listOf(0.18f, 0.45f, 0.78f).map { level ->
            val c = floatArrayOf(level, level * 0.98f, level * 0.94f)
            look.apply(c)
            Color(c[0].coerceIn(0f, 1f), c[1].coerceIn(0f, 1f), c[2].coerceIn(0f, 1f))
        }
    }

    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            modifier = Modifier
                .size(56.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(Brush.verticalGradient(colours))
                .border(
                    width = if (selected) 2.dp else 1.dp,
                    color = if (selected) Accent else Ink.Line,
                    shape = RoundedCornerShape(12.dp),
                )
                .clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            if (selected) {
                Icon(
                    Glyphs.Check,
                    contentDescription = null,
                    tint = Ink.Black,
                    modifier = Modifier.size(20.dp),
                )
            }
        }
        Text(
            look.name,
            style = MaterialTheme.typography.labelSmall,
            color = if (selected) Accent else Palette.Secondary,
            maxLines = 1,
            modifier = Modifier.padding(top = 5.dp).width(60.dp),
        )
    }
}

@Composable
fun SpeedPanel(clip: Clip, onBegin: () -> Unit, onSpeed: (Float) -> Unit) {
    Column(
        modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT),
        verticalArrangement = Arrangement.Center,
    ) {
        Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 18.dp),
        ) {
            listOf(0.25f, 0.5f, 1f, 1.5f, 2f, 3f, 4f).forEach { preset ->
                Chip(
                    label = "${preset}x".replace(".0x", "x"),
                    selected = clip.speed == preset,
                    onClick = {
                        onBegin()
                        onSpeed(preset)
                    },
                )
            }
        }
        ValueSlider(
            label = "Speed",
            value = clip.speed,
            range = 0.25f..4f,
            display = "%.2fx".format(clip.speed),
            isDefault = clip.speed == 1f,
            onBegin = onBegin,
            onChange = onSpeed,
            modifier = Modifier.padding(top = 16.dp),
        )
        Text(
            "Faster play shortens the clip on the timeline.",
            style = MaterialTheme.typography.labelSmall,
            color = Palette.Faint,
            modifier = Modifier.padding(horizontal = 18.dp, vertical = 6.dp),
        )
    }
}

@Composable
fun VolumePanel(
    clip: Clip,
    onBegin: () -> Unit,
    onVolume: (Float) -> Unit,
    onMute: () -> Unit,
) {
    Column(
        modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT),
        verticalArrangement = Arrangement.Center,
    ) {
        Row(modifier = Modifier.padding(horizontal = 8.dp)) {
            ActionButton(
                icon = if (clip.muted) Glyphs.VolumeOff else Glyphs.VolumeOn,
                label = if (clip.muted) "Muted" else "Sound on",
                onClick = {
                    onBegin()
                    onMute()
                },
                tint = if (clip.muted) Danger else Accent,
            )
        }
        ValueSlider(
            label = "Volume",
            value = clip.volume,
            range = 0f..2f,
            display = "${(clip.volume * 100).toInt()}",
            isDefault = clip.volume == 1f,
            onBegin = onBegin,
            onChange = onVolume,
            modifier = Modifier.padding(top = 10.dp),
        )
        if (clip.isImage) {
            Text(
                "A photo has no sound of its own.",
                style = MaterialTheme.typography.labelSmall,
                color = Palette.Faint,
                modifier = Modifier.padding(horizontal = 18.dp, vertical = 6.dp),
            )
        }
    }
}

@Composable
fun TransitionPanel(
    clip: Clip,
    isFirst: Boolean,
    onBegin: () -> Unit,
    onPick: (TransitionType, Long) -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT)) {
        if (isFirst) {
            Text(
                "A transition joins a clip to the one before it, so the first clip cannot have one.",
                style = MaterialTheme.typography.bodySmall,
                color = Palette.Secondary,
                modifier = Modifier.padding(18.dp),
            )
            return@Column
        }

        Row(
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 18.dp, vertical = 12.dp),
        ) {
            TransitionType.entries.forEach { type ->
                Chip(
                    label = type.label,
                    selected = clip.transition.type == type,
                    onClick = {
                        onBegin()
                        onPick(type, clip.transition.durationUs.coerceAtLeast(Transition.MIN_US))
                    },
                )
            }
        }

        if (clip.transition.isActive) {
            ValueSlider(
                label = "Length",
                value = clip.transition.durationUs / 1_000_000f,
                range = Transition.MIN_US / 1_000_000f..Transition.MAX_US / 1_000_000f,
                display = "%.1fs".format(clip.transition.durationUs / 1_000_000f),
                isDefault = false,
                onBegin = onBegin,
                onChange = { seconds ->
                    onPick(clip.transition.type, (seconds * 1_000_000f).toLong())
                },
            )
            Text(
                "Long transitions are shortened to fit the clips they join.",
                style = MaterialTheme.typography.labelSmall,
                color = Palette.Faint,
                modifier = Modifier.padding(horizontal = 18.dp, vertical = 4.dp),
            )
        }
    }
}

@Composable
fun CanvasPanel(current: CanvasRatio, onPick: (CanvasRatio) -> Unit) {
    Column(
        modifier = Modifier.fillMaxWidth().height(PANEL_HEIGHT),
        verticalArrangement = Arrangement.Center,
    ) {
        Row(
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 18.dp),
        ) {
            CanvasRatio.entries.forEach { ratio ->
                RatioTile(ratio, ratio == current) { onPick(ratio) }
            }
        }
        Text(
            "Clips keep their own shape; the canvas decides what the file is.",
            style = MaterialTheme.typography.labelSmall,
            color = Palette.Faint,
            modifier = Modifier.padding(horizontal = 18.dp, vertical = 14.dp),
        )
    }
}

@Composable
private fun RatioTile(ratio: CanvasRatio, selected: Boolean, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            modifier = Modifier.size(54.dp).clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            val wide = ratio.ratio >= 1f
            val long = 38.dp
            val short = long * (if (wide) 1f / ratio.ratio else ratio.ratio)
            Box(
                modifier = Modifier
                    .width(if (wide) long else short)
                    .height(if (wide) short else long)
                    .clip(RoundedCornerShape(4.dp))
                    .background(if (selected) Accent else Ink.Raised)
                    .border(
                        width = 1.dp,
                        color = if (selected) Accent else Ink.Line,
                        shape = RoundedCornerShape(4.dp),
                    ),
            )
        }
        Text(
            ratio.label,
            style = MaterialTheme.typography.labelSmall,
            color = if (selected) Accent else Palette.Secondary,
        )
    }
}
