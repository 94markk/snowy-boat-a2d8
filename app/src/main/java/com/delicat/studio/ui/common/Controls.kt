package com.delicat.studio.ui.common

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.DragInteraction
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.PressInteraction
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Glyphs
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Palette

/**
 * A labelled slider that shows its value and says when it has been moved.
 *
 * The accent on the label answers "what have I changed here": a glance down a
 * panel of twelve parameters shows which ones are off their default without
 * reading a single number.
 *
 * [onBegin] fires once when the gesture starts rather than on every value, so
 * a drag from one end of the track to the other is a single step of undo
 * instead of two hundred.
 */
@Composable
fun ValueSlider(
    label: String,
    value: Float,
    range: ClosedFloatingPointRange<Float>,
    display: String,
    isDefault: Boolean,
    onBegin: () -> Unit,
    onChange: (Float) -> Unit,
    modifier: Modifier = Modifier,
) {
    val interactions = remember { MutableInteractionSource() }
    val begin by rememberUpdatedState(onBegin)
    LaunchedEffect(interactions) {
        interactions.interactions.collect { interaction ->
            if (interaction is DragInteraction.Start || interaction is PressInteraction.Press) {
                begin()
            }
        }
    }

    Column(modifier = modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 2.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = if (isDefault) Palette.Secondary else Palette.Primary,
            )
            Text(
                display,
                style = MaterialTheme.typography.labelMedium,
                color = if (isDefault) Palette.Faint else Accent,
            )
        }
        Slider(
            value = value.coerceIn(range.start, range.endInclusive),
            onValueChange = onChange,
            valueRange = range,
            interactionSource = interactions,
            colors = SliderDefaults.colors(
                thumbColor = Accent,
                activeTrackColor = Accent,
                inactiveTrackColor = Ink.Chip,
            ),
            modifier = Modifier.fillMaxWidth().padding(top = 2.dp),
        )
    }
}

/** A pill. Selected ones fill with the accent, the rest sit on a raised panel. */
@Composable
fun Chip(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(50))
            .background(if (selected) Accent else Ink.Raised)
            .border(
                width = 1.dp,
                color = if (selected) Accent else Ink.Line,
                shape = RoundedCornerShape(50),
            )
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 9.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelMedium,
            color = if (selected) Ink.Black else Palette.Secondary,
        )
    }
}

/** An icon with its name under it, used for anything that performs an action. */
@Composable
fun ActionButton(
    icon: ImageVector,
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    tint: Color = Palette.Primary,
    enabled: Boolean = true,
) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = modifier
            .clip(RoundedCornerShape(14.dp))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 8.dp),
    ) {
        Box(
            modifier = Modifier
                .size(38.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(Ink.Raised),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                icon,
                contentDescription = label,
                tint = if (enabled) tint else Palette.Faint,
                modifier = Modifier.size(19.dp),
            )
        }
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = if (enabled) Palette.Secondary else Palette.Faint,
            modifier = Modifier.padding(top = 5.dp),
        )
    }
}

/** The container every tool panel sits in: a titled sheet with a done button. */
@Composable
fun Sheet(
    title: String,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
    trailing: @Composable () -> Unit = {},
    content: @Composable () -> Unit,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(topStart = 18.dp, topEnd = 18.dp))
            .background(Ink.Panel),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 18.dp, end = 10.dp, top = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(
                title,
                style = MaterialTheme.typography.titleMedium,
                color = Palette.Primary,
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                trailing()
                Box(
                    modifier = Modifier
                        .size(34.dp)
                        .clip(RoundedCornerShape(50))
                        .clickable(onClick = onClose),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Glyphs.Check,
                        contentDescription = "Done",
                        tint = Accent,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }
        }
        content()
    }
}

/** Seconds as `m:ss.t`, which is what a timeline needs and a clock is not. */
fun formatTime(microseconds: Long): String {
    val total = (microseconds.coerceAtLeast(0L) / 100_000L)
    val tenths = total % 10
    val seconds = (total / 10) % 60
    val minutes = total / 600
    return "%d:%02d.%d".format(minutes, seconds, tenths)
}
