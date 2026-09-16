package com.vixel.studio.ui.video

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.calculateZoom
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.VolumeOff
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.Project
import com.vixel.studio.core.model.StickerOverlay
import com.vixel.studio.core.model.TextOverlay
import com.vixel.studio.engine.video.ThumbnailCache
import com.vixel.studio.ui.theme.Aqua
import com.vixel.studio.ui.theme.Ink500
import com.vixel.studio.ui.theme.Ink600
import com.vixel.studio.ui.theme.Ink700
import com.vixel.studio.ui.theme.Mist200
import com.vixel.studio.ui.theme.Mist400
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.max
import kotlin.math.roundToInt
import kotlin.math.roundToLong

private val CLIP_ROW_HEIGHT = 54.dp
private val TRACK_ROW_HEIGHT = 28.dp
private val RULER_HEIGHT = 20.dp

private const val MIN_DP_PER_SECOND = 6f
private const val MAX_DP_PER_SECOND = 200f
private const val DEFAULT_DP_PER_SECOND = 34f

/**
 * The timeline, built the way a phone editor has to be: the playhead is fixed
 * at the centre and the media scrolls underneath it.
 *
 * Dragging a playhead along a static strip does not work at this size — the
 * finger covers the frame it is aiming at, and the end of the timeline sits
 * under the screen edge where it cannot be grabbed. Pinning the playhead puts
 * the current frame in the middle of the screen, directly below the preview
 * showing that same frame, and lets a clip be trimmed to its final frame.
 *
 * Scroll position is the source of truth while a finger is down; [positionUs]
 * is the source of truth the rest of the time. Without that split the two
 * would drive each other in a loop during playback.
 */
@Composable
fun Filmstrip(
    project: Project,
    positionUs: Long,
    selectedClipId: String?,
    onScrub: (Long) -> Unit,
    onScrubFinished: () -> Unit,
    onSelectClip: (String?) -> Unit,
    onAddMedia: () -> Unit,
    onAddAudio: () -> Unit,
    onAddText: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val scroll = rememberScrollState()
    var dpPerSecond by remember { mutableFloatStateOf(DEFAULT_DP_PER_SECOND) }

    BoxWithConstraints(modifier = modifier.fillMaxWidth()) {
        val halfViewport = maxWidth / 2
        // ScrollState works in pixels, everything laid out here is in dp.
        val pxPerSecond = with(density) { dpPerSecond.dp.toPx() }

        fun offsetForTime(timeUs: Long): Int =
            (timeUs / 1_000_000f * pxPerSecond).roundToInt()

        fun timeForOffset(offsetPx: Int): Long =
            if (pxPerSecond <= 0f) 0L else (offsetPx / pxPerSecond * 1_000_000f).roundToLong()

        LaunchedEffect(scroll, pxPerSecond, project.durationUs) {
            snapshotFlow { scroll.isScrollInProgress to scroll.value }
                .collect { (dragging, value) ->
                    if (dragging) {
                        onScrub(timeForOffset(value).coerceIn(0L, project.durationUs))
                    }
                }
        }

        LaunchedEffect(scroll) {
            snapshotFlow { scroll.isScrollInProgress }
                .collect { dragging -> if (!dragging) onScrubFinished() }
        }

        // Playback and zoom both re-anchor the strip under the playhead.
        LaunchedEffect(positionUs, pxPerSecond) {
            if (scroll.isScrollInProgress) return@LaunchedEffect
            val target = offsetForTime(positionUs).coerceIn(0, scroll.maxValue)
            if (abs(scroll.value - target) > 1) scroll.scrollTo(target)
        }

        Column(modifier = Modifier.fillMaxWidth()) {
            TimeReadout(positionUs = positionUs, durationUs = project.durationUs)

            Box(modifier = Modifier.fillMaxWidth()) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .horizontalScroll(scroll)
                        .pinchToZoom { factor ->
                            dpPerSecond = (dpPerSecond * factor)
                                .coerceIn(MIN_DP_PER_SECOND, MAX_DP_PER_SECOND)
                        },
                ) {
                    Ruler(
                        durationUs = project.durationUs,
                        dpPerSecond = dpPerSecond,
                        leadIn = halfViewport,
                    )
                    ClipRow(
                        project = project,
                        selectedClipId = selectedClipId,
                        dpPerSecond = dpPerSecond,
                        leadIn = halfViewport,
                        onSelectClip = onSelectClip,
                        onAddMedia = onAddMedia,
                    )
                    AudioTrack(
                        project = project,
                        dpPerSecond = dpPerSecond,
                        leadIn = halfViewport,
                        onAddAudio = onAddAudio,
                    )
                    TextTrack(
                        project = project,
                        dpPerSecond = dpPerSecond,
                        leadIn = halfViewport,
                        onAddText = onAddText,
                    )
                }

                Playhead(modifier = Modifier.align(Alignment.TopCenter))
            }
        }
    }
}

/**
 * Pinch handling that leaves one-finger drags alone.
 *
 * The obvious `detectTransformGestures` swallows single-pointer drags too,
 * which would stop the strip scrolling at all. Events are only consumed once a
 * second pointer is down, so scrolling and zooming can share the same area.
 */
private fun Modifier.pinchToZoom(onZoom: (Float) -> Unit): Modifier =
    pointerInput(Unit) {
        awaitEachGesture {
            awaitFirstDown(requireUnconsumed = false)
            do {
                val event = awaitPointerEvent()
                if (event.changes.size >= 2) {
                    val factor = event.calculateZoom()
                    if (factor != 1f) {
                        onZoom(factor)
                        event.changes.forEach { it.consume() }
                    }
                }
            } while (event.changes.any { it.pressed })
        }
    }

@Composable
private fun TimeReadout(positionUs: Long, durationUs: Long) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 14.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            formatTimePrecise(positionUs),
            style = MaterialTheme.typography.labelSmall,
            color = Mist200,
        )
        Text(
            " / ${formatTime(durationUs)}",
            style = MaterialTheme.typography.labelSmall,
            color = Mist400,
        )
    }
}

/**
 * Tick labels, spaced by an interval chosen from the current zoom.
 *
 * A fixed interval breaks at both ends: every second smears into a grey band
 * when zoomed out, every thirty leaves nothing on screen when zoomed in.
 */
@Composable
private fun Ruler(durationUs: Long, dpPerSecond: Float, leadIn: Dp) {
    val step = remember(dpPerSecond) { rulerStepSeconds(dpPerSecond) }
    val totalSeconds = (durationUs / 1_000_000L).toInt()

    Row(
        modifier = Modifier.height(RULER_HEIGHT),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Spacer(Modifier.width(leadIn))
        var second = 0
        while (second <= totalSeconds + step) {
            Box(modifier = Modifier.width((dpPerSecond * step).dp)) {
                Text(
                    formatTime(second * 1_000_000L),
                    style = MaterialTheme.typography.labelSmall,
                    color = Mist400,
                    maxLines = 1,
                )
            }
            second += step
        }
        Spacer(Modifier.width(leadIn))
    }
}

private fun rulerStepSeconds(dpPerSecond: Float): Int = when {
    dpPerSecond >= 110f -> 1
    dpPerSecond >= 55f -> 2
    dpPerSecond >= 28f -> 5
    dpPerSecond >= 14f -> 10
    else -> 30
}

@Composable
private fun ClipRow(
    project: Project,
    selectedClipId: String?,
    dpPerSecond: Float,
    leadIn: Dp,
    onSelectClip: (String?) -> Unit,
    onAddMedia: () -> Unit,
) {
    Row(
        modifier = Modifier.height(CLIP_ROW_HEIGHT),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Spacer(Modifier.width(leadIn))

        project.clips.forEachIndexed { index, clip ->
            if (index > 0) TransitionMarker(active = clip.transition.isActive)
            ClipCell(
                clip = clip,
                selected = clip.id == selectedClipId,
                widthDp = clipWidth(clip, dpPerSecond),
                onClick = { onSelectClip(clip.id) },
            )
        }

        AddMediaButton(onClick = onAddMedia)
        Spacer(Modifier.width(leadIn))
    }
}

/** A floor keeps a very short cut tappable instead of a hairline. */
private fun clipWidth(clip: Clip, dpPerSecond: Float): Dp =
    max(30f, clip.timelineDurationUs / 1_000_000f * dpPerSecond).dp

@Composable
private fun ClipCell(
    clip: Clip,
    selected: Boolean,
    widthDp: Dp,
    onClick: () -> Unit,
) {
    Box(
        modifier = Modifier
            .padding(horizontal = 1.dp)
            .width(widthDp)
            .fillMaxHeight()
            .clip(RoundedCornerShape(6.dp))
            .background(Ink600)
            .then(
                if (selected) Modifier.border(2.dp, Aqua, RoundedCornerShape(6.dp)) else Modifier,
            )
            .clickable(onClick = onClick),
    ) {
        ClipThumbnails(
            clip = clip,
            widthDp = widthDp,
            heightDp = CLIP_ROW_HEIGHT,
            modifier = Modifier.fillMaxSize(),
        )

        if (clip.muted || clip.volume == 0f) {
            Icon(
                Icons.Rounded.VolumeOff,
                contentDescription = "Muted",
                tint = Mist200,
                modifier = Modifier
                    .align(Alignment.TopStart)
                    .padding(3.dp)
                    .size(12.dp),
            )
        }

        if (clip.speed != 1f) {
            Text(
                "${clip.speed}x",
                style = MaterialTheme.typography.labelSmall,
                color = Mist200,
                maxLines = 1,
                overflow = TextOverflow.Clip,
                modifier = Modifier
                    .align(Alignment.BottomEnd)
                    .background(ScrimBlack, RoundedCornerShape(3.dp))
                    .padding(horizontal = 3.dp),
            )
        }
    }
}

/**
 * Frames along the length of a clip.
 *
 * Cells are laid out first and each fetches its own frame, so the strip has
 * its final shape immediately and fills in as decodes land. Building the
 * layout from decoded frames instead would make the timeline jump around while
 * they arrive.
 */
@Composable
private fun ClipThumbnails(
    clip: Clip,
    widthDp: Dp,
    heightDp: Dp,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val density = LocalDensity.current
    val cellWidth = heightDp * 16f / 9f
    val count = ceil(widthDp / cellWidth).toInt().coerceIn(1, 32)
    val heightPx = with(density) { heightDp.roundToPx() }
    val isImage = clip.kind == MediaKind.IMAGE

    Row(modifier = modifier) {
        repeat(count) { index ->
            val timeUs = clip.trimStartUs + clip.trimmedDurationUs * index / count
            val frame by produceState<ImageBitmap?>(null, clip.uri, timeUs, heightPx, isImage) {
                value = runCatching {
                    ThumbnailCache.frame(context, clip.uri, timeUs, isImage, heightPx)
                        ?.asImageBitmap()
                }.getOrNull()
            }

            Box(modifier = Modifier.width(cellWidth).fillMaxHeight()) {
                frame?.let {
                    Image(
                        bitmap = it,
                        contentDescription = null,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize(),
                    )
                }
            }
        }
    }
}

@Composable
private fun TransitionMarker(active: Boolean) {
    Box(
        modifier = Modifier
            .size(15.dp)
            .clip(RoundedCornerShape(3.dp))
            .background(if (active) Aqua else Ink500),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            "⋈",
            style = MaterialTheme.typography.labelSmall,
            color = if (active) Color.Black else Mist400,
        )
    }
}

@Composable
private fun AddMediaButton(onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .padding(horizontal = 4.dp)
            .size(CLIP_ROW_HEIGHT)
            .clip(RoundedCornerShape(6.dp))
            .background(Ink500)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(Icons.Rounded.Add, contentDescription = "Add media", tint = Mist200)
    }
}

@Composable
private fun AudioTrack(
    project: Project,
    dpPerSecond: Float,
    leadIn: Dp,
    onAddAudio: () -> Unit,
) {
    Row(
        modifier = Modifier.height(TRACK_ROW_HEIGHT).padding(top = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Spacer(Modifier.width(leadIn))
        if (project.audio.isEmpty()) {
            TrackPlaceholder(
                icon = Icons.Rounded.MusicNote,
                label = "Add audio",
                onClick = onAddAudio,
            )
        } else {
            project.audio.forEach { audio ->
                TrackChip(
                    label = audio.title.ifBlank { "Audio" },
                    widthDp = max(
                        30f,
                        audio.timelineDurationUs / 1_000_000f * dpPerSecond,
                    ).dp,
                    color = AudioTrackColor,
                )
            }
        }
        Spacer(Modifier.width(leadIn))
    }
}

@Composable
private fun TextTrack(
    project: Project,
    dpPerSecond: Float,
    leadIn: Dp,
    onAddText: () -> Unit,
) {
    Row(
        modifier = Modifier.height(TRACK_ROW_HEIGHT).padding(top = 3.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Spacer(Modifier.width(leadIn))
        if (project.overlays.isEmpty()) {
            TrackPlaceholder(
                icon = Icons.Rounded.TextFields,
                label = "Add text",
                onClick = onAddText,
            )
        } else {
            project.overlays.forEach { overlay ->
                TrackChip(
                    label = overlay.trackLabel(),
                    widthDp = max(
                        30f,
                        overlay.durationUs / 1_000_000f * dpPerSecond,
                    ).dp,
                    color = TextTrackColor,
                )
            }
        }
        Spacer(Modifier.width(leadIn))
    }
}

private fun Overlay.trackLabel(): String = when (this) {
    is TextOverlay -> text.ifBlank { "Text" }
    is StickerOverlay -> "Sticker"
}

@Composable
private fun TrackChip(label: String, widthDp: Dp, color: Color) {
    Box(
        modifier = Modifier
            .padding(horizontal = 1.dp)
            .width(widthDp)
            .fillMaxHeight()
            .clip(RoundedCornerShape(4.dp))
            .background(color),
        contentAlignment = Alignment.CenterStart,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = Mist200,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.padding(horizontal = 5.dp),
        )
    }
}

@Composable
private fun TrackPlaceholder(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxHeight()
            .clip(RoundedCornerShape(4.dp))
            .background(Ink700)
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, contentDescription = null, tint = Mist400, modifier = Modifier.size(14.dp))
        Text(label, style = MaterialTheme.typography.labelSmall, color = Mist400)
    }
}

@Composable
private fun Playhead(modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.fillMaxHeight(),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(
            modifier = Modifier
                .size(9.dp)
                .clip(RoundedCornerShape(2.dp))
                .background(Aqua),
        )
        Box(
            modifier = Modifier
                .width(2.dp)
                .fillMaxHeight()
                .background(Aqua),
        )
    }
}

private val ScrimBlack = Color(0x99000000)
private val AudioTrackColor = Color(0xFF1E4D3F)
private val TextTrackColor = Color(0xFF3A2F5C)
