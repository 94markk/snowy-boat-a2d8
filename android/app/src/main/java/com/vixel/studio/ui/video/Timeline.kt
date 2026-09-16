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
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Project
import kotlin.math.max
import kotlin.math.roundToInt

/**
 * Clip strip plus a scrub bar.
 *
 * Clip widths are proportional to their timeline duration with a floor, so a
 * half-second cut is still large enough to tap on a phone.
 */
@Composable
fun Timeline(
    project: Project,
    selectedClipId: String?,
    positionUs: Long,
    onSelect: (String) -> Unit,
    onSeek: (Long) -> Unit,
    onSeekFinished: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val duration = max(1L, project.durationUs)

    Column(modifier = modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(
                formatTime(positionUs),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.primary,
            )
            Text(
                formatTime(project.durationUs),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Slider(
            value = positionUs.toFloat().coerceIn(0f, duration.toFloat()),
            onValueChange = { onSeek(it.toLong()) },
            onValueChangeFinished = onSeekFinished,
            valueRange = 0f..duration.toFloat(),
            modifier = Modifier.padding(horizontal = 12.dp),
        )

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 4.dp),
            horizontalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            project.clips.forEach { clip ->
                ClipBlock(
                    clip = clip,
                    selected = clip.id == selectedClipId,
                    onClick = { onSelect(clip.id) },
                )
            }
        }
    }
}

@Composable
private fun ClipBlock(clip: Clip, selected: Boolean, onClick: () -> Unit) {
    // 26dp per second, floored at 56dp so short cuts stay tappable.
    val seconds = clip.timelineDurationUs / 1_000_000f
    val width = (seconds * 26f).roundToInt().coerceIn(56, 260)

    Box(
        modifier = Modifier
            .width(width.dp)
            .height(56.dp)
            .clip(RoundedCornerShape(8.dp))
            .background(
                if (selected) {
                    MaterialTheme.colorScheme.primary.copy(alpha = 0.22f)
                } else {
                    MaterialTheme.colorScheme.surfaceVariant
                },
            )
            .border(
                if (selected) 2.dp else 1.dp,
                if (selected) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.outlineVariant
                },
                RoundedCornerShape(8.dp),
            )
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier.padding(horizontal = 6.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                if (clip.kind == MediaKind.IMAGE) "Photo" else "Video",
                style = MaterialTheme.typography.labelSmall,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                formatTime(clip.timelineDurationUs),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
            )
        }
    }
}

fun formatTime(us: Long): String {
    val totalSeconds = us / 1_000_000
    val minutes = totalSeconds / 60
    val seconds = totalSeconds % 60
    val tenths = (us % 1_000_000) / 100_000
    return if (minutes > 0) {
        "%d:%02d.%d".format(minutes, seconds, tenths)
    } else {
        "%d.%ds".format(seconds, tenths)
    }
}
