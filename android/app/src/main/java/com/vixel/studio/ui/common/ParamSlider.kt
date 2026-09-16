package com.vixel.studio.ui.common

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.AdjustSpec
import kotlin.math.abs
import kotlin.math.roundToInt

/**
 * One parameter row. The readout is always visible and tapping the label
 * resets that single parameter — so it is obvious at a glance which controls
 * are doing something and what their neutral is.
 */
@Composable
fun ParamSlider(
    spec: AdjustSpec,
    value: Float,
    onValueChange: (Float) -> Unit,
    onValueChangeFinished: () -> Unit,
    onReset: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val isChanged = abs(value - spec.default) > 1e-4f

    Column(modifier = modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 2.dp)) {
        Row(modifier = Modifier.fillMaxWidth().clickable(onClick = onReset)) {
            Text(
                text = spec.label,
                style = MaterialTheme.typography.labelMedium,
                color = if (isChanged) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
                modifier = Modifier.weight(1f),
            )
            Text(
                text = formatValue(spec, value),
                style = MaterialTheme.typography.labelMedium,
                textAlign = TextAlign.End,
                color = if (isChanged) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
            )
        }
        Slider(
            value = value,
            onValueChange = onValueChange,
            onValueChangeFinished = onValueChangeFinished,
            valueRange = spec.min..spec.max,
            colors = SliderDefaults.colors(
                activeTrackColor = MaterialTheme.colorScheme.primary,
                inactiveTrackColor = MaterialTheme.colorScheme.outlineVariant,
            ),
        )
    }
}

fun formatValue(spec: AdjustSpec, value: Float): String {
    val scaled = value * spec.displayScale
    return if (spec.displayScale == 1f && spec.unit == " EV") {
        val rounded = (scaled * 10f).roundToInt() / 10f
        (if (rounded > 0f) "+$rounded" else "$rounded") + spec.unit
    } else {
        val rounded = scaled.roundToInt()
        (if (rounded > 0) "+$rounded" else "$rounded") + spec.unit
    }
}
