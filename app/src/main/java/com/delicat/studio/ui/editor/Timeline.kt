package com.delicat.studio.ui.editor

import android.graphics.Bitmap
import android.net.Uri
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.delicat.studio.engine.EditorState
import com.delicat.studio.engine.FrameCache
import com.delicat.studio.model.Clip
import com.delicat.studio.ui.theme.Accent
import com.delicat.studio.ui.theme.Glyphs
import com.delicat.studio.ui.theme.Ink
import com.delicat.studio.ui.theme.Palette
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.roundToInt
import kotlin.math.roundToLong

private val TRACK_HEIGHT = 58.dp
private val CELL_WIDTH = 46.dp
private val HANDLE_WIDTH = 14.dp

/** Small enough to decode quickly, large enough not to look like porridge. */
private const val THUMB_EDGE = 160

/**
 * The timeline: a strip of frames that scrolls under a playhead which does not
 * move.
 *
 * Fixing the playhead and moving the film is what phone editors do, and it is
 * not a stylistic choice. A moving playhead means the thing being cut is
 * somewhere under the thumb that is also dragging it; a fixed one at the
 * centre means the cut always happens in the middle of the screen, where the
 * user is already looking.
 *
 * Nothing is allowed into the scrolling row that is not time. Every pixel of
 * width is duration multiplied by the zoom, which is what keeps the playhead
 * honest — a decoration with a width of its own would shift everything after
 * it and the preview would stop agreeing with the strip.
 */
@Composable
fun Timeline(
    state: EditorState,
    frames: FrameCache,
    onScrub: (Long) -> Unit,
    onSelect: (String?) -> Unit,
    onBeginChange: () -> Unit,
    onTrim: (Long, Long) -> Unit,
    onTransitionTap: (String) -> Unit,
    onAdd: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val scroll = rememberScrollState()
    var viewportPx by remember { mutableIntStateOf(0) }

    val pxPerUs = state.pixelsPerSecond * density.density / 1_000_000f
    val cellPx = with(density) { CELL_WIDTH.toPx() }

    // Scrolling drives time, and time drives scrolling. Which direction is
    // live is decided by whether a finger is involved: a previous version
    // keyed this on whether a scroll was in progress, which is also true of
    // the scroll this code performs itself, and the two ends chased each
    // other for as long as playback lasted.
    var touching by remember { mutableStateOf(false) }
    var userDriven by remember { mutableStateOf(false) }
    LaunchedEffect(touching, scroll.isScrollInProgress) {
        if (touching) userDriven = true else if (!scroll.isScrollInProgress) userDriven = false
    }

    val scrub by rememberUpdatedState(onScrub)
    val scale by rememberUpdatedState(pxPerUs)
    LaunchedEffect(Unit) {
        snapshotFlow { scroll.value }.collect { offset ->
            if (userDriven) scrub((offset / scale).roundToLong())
        }
    }

    val position by rememberUpdatedState(state.positionUs)
    LaunchedEffect(Unit) {
        snapshotFlow { position to scale }.collect { (us, factor) ->
            if (userDriven) return@collect
            val target = (us * factor).roundToInt()
            if (abs(scroll.value - target) > 1) scroll.scrollTo(target)
        }
    }

    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(TRACK_HEIGHT + 22.dp)
            .background(Ink.Near)
            .onSizeChanged { viewportPx = it.width },
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxSize()
                .horizontalScroll(scroll)
                .pointerInput(Unit) {
                    // Watched on the initial pass, so the gesture still
                    // reaches the scroll modifier untouched.
                    awaitPointerEventScope {
                        while (true) {
                            val event = awaitPointerEvent(PointerEventPass.Initial)
                            touching = event.changes.any { it.pressed }
                        }
                    }
                },
        ) {
            val edge = with(density) { (viewportPx / 2).toDp() }
            Spacer(Modifier.width(edge))

            var left = 0f
            state.project.clips.forEachIndexed { index, clip ->
                val overlapUs = state.project.overlapBefore(index)
                val visibleUs = (clip.timelineDurationUs - overlapUs).coerceAtLeast(0L)
                val widthPx = visibleUs * pxPerUs
                val startPx = left
                left += widthPx

                ClipStrip(
                    clip = clip,
                    frames = frames,
                    widthDp = with(density) { widthPx.toDp() },
                    visibleUs = visibleUs,
                    skipUs = overlapUs,
                    selected = clip.id == state.selectedClipId,
                    showTransition = index > 0,
                    startPx = startPx,
                    scrollPx = scroll.value.toFloat(),
                    viewportPx = viewportPx.toFloat(),
                    cellPx = cellPx,
                    cellDp = CELL_WIDTH,
                    pxPerUs = pxPerUs,
                    onSelect = { onSelect(clip.id) },
                    onBeginChange = onBeginChange,
                    onTrim = onTrim,
                    onTransitionTap = { onTransitionTap(clip.id) },
                )
            }

            AddTile(onAdd)
            Spacer(Modifier.width(edge))
        }

        Playhead()
    }
}

@Composable
private fun BoxScope.Playhead() {
    Box(
        modifier = Modifier
            .align(Alignment.Center)
            .fillMaxHeight()
            .width(2.dp)
            .background(Accent),
    )
}

@Composable
private fun AddTile(onAdd: () -> Unit) {
    Box(
        modifier = Modifier
            .padding(start = 8.dp)
            .size(width = 44.dp, height = TRACK_HEIGHT)
            .clip(RoundedCornerShape(8.dp))
            .background(Ink.Raised)
            .clickable(onClick = onAdd),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Glyphs.Add,
            contentDescription = "Add media",
            tint = Palette.Secondary,
            modifier = Modifier.size(20.dp),
        )
    }
}

/**
 * One clip as a run of frames, with trim handles when it is selected.
 *
 * [skipUs] is the part of the clip hidden underneath the transition into it.
 * Laying clips out at their full length would make the strip longer than the
 * project, and the playhead would then disagree with the preview by the sum
 * of every transition on the timeline.
 */
@Composable
private fun ClipStrip(
    clip: Clip,
    frames: FrameCache,
    widthDp: Dp,
    visibleUs: Long,
    skipUs: Long,
    selected: Boolean,
    showTransition: Boolean,
    startPx: Float,
    scrollPx: Float,
    viewportPx: Float,
    cellPx: Float,
    cellDp: Dp,
    pxPerUs: Float,
    onSelect: () -> Unit,
    onBeginChange: () -> Unit,
    onTrim: (Long, Long) -> Unit,
    onTransitionTap: () -> Unit,
) {
    val widthPx = visibleUs * pxPerUs
    val cells = ceil(widthPx / cellPx).toInt().coerceIn(1, 400)

    Box(
        modifier = Modifier
            .width(widthDp.coerceAtLeast(20.dp))
            .height(TRACK_HEIGHT)
            .clip(RoundedCornerShape(7.dp))
            .background(Ink.Panel)
            .border(
                width = if (selected) 2.dp else 1.dp,
                color = if (selected) Accent else Ink.Line,
                shape = RoundedCornerShape(7.dp),
            )
            .clickable(onClick = onSelect),
    ) {
        Row(modifier = Modifier.fillMaxSize()) {
            repeat(cells) { index ->
                val cellStart = startPx + index * cellPx
                // Only frames on screen are asked for. Everything else would
                // be a decode for a thumbnail nobody can see, and a long
                // timeline has hundreds of them.
                val onScreen = cellStart + cellPx >= scrollPx - cellPx &&
                    cellStart <= scrollPx + viewportPx + cellPx
                val into = skipUs + (visibleUs * (index + 0.5f) / cells).toLong()
                val sourceUs = if (clip.isImage) {
                    0L
                } else {
                    clip.trimStartUs + (into * clip.speed.coerceAtLeast(0.01f)).roundToLong()
                }

                ThumbCell(
                    clip = clip,
                    frames = frames,
                    sourceUs = sourceUs,
                    load = onScreen,
                    modifier = Modifier.width(cellDp).fillMaxHeight(),
                )
            }
        }

        Label(clip, modifier = Modifier.align(Alignment.BottomEnd))

        if (showTransition) {
            // Offset rather than laid out, so it straddles the seam without
            // taking any width away from the time it sits on.
            TransitionMark(
                active = clip.transition.isActive,
                onClick = onTransitionTap,
                modifier = Modifier.align(Alignment.TopStart).offset(x = (-9).dp),
            )
        }

        if (selected) {
            TrimHandle(
                atStart = true,
                onBegin = onBeginChange,
                modifier = Modifier.align(Alignment.CenterStart),
            ) { deltaPx ->
                val delta = (deltaPx / pxPerUs * clip.speed).roundToLong()
                onTrim(clip.trimStartUs + delta, clip.trimEndUs)
            }
            TrimHandle(
                atStart = false,
                onBegin = onBeginChange,
                modifier = Modifier.align(Alignment.CenterEnd),
            ) { deltaPx ->
                val delta = (deltaPx / pxPerUs * clip.speed).roundToLong()
                onTrim(clip.trimStartUs, clip.trimEndUs + delta)
            }
        }
    }
}

@Composable
private fun ThumbCell(
    clip: Clip,
    frames: FrameCache,
    sourceUs: Long,
    load: Boolean,
    modifier: Modifier = Modifier,
) {
    val key = remember(clip.uri, sourceUs) { frames.keyFor(clip.uri, sourceUs, THUMB_EDGE) }
    var decoded by remember(key) { mutableStateOf<Bitmap?>(null) }

    // Keyed on the frame wanted, and guarded against starting again for one
    // already in hand. Scrolling flips visibility constantly, and an
    // unguarded effect here is how an earlier version of this app spawned
    // extractions faster than they could finish.
    LaunchedEffect(key, load) {
        if (!load || decoded != null) return@LaunchedEffect
        decoded = frames.frame(Uri.parse(clip.uri), clip.kind, sourceUs, THUMB_EDGE)
    }

    val bitmap = frames.peek(key) ?: decoded
    Box(modifier = modifier.background(Ink.Raised)) {
        if (bitmap != null && !bitmap.isRecycled) {
            Image(
                bitmap = remember(bitmap) { bitmap.asImageBitmap() },
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

@Composable
private fun TransitionMark(active: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .size(width = 18.dp, height = 20.dp)
            .clip(RoundedCornerShape(5.dp))
            .background(if (active) Accent else Ink.Chip)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Glyphs.Transition,
            contentDescription = "Transition",
            tint = if (active) Ink.Black else Palette.Secondary,
            modifier = Modifier.size(12.dp),
        )
    }
}

@Composable
private fun Label(clip: Clip, modifier: Modifier = Modifier) {
    val text = buildString {
        append(if (clip.isImage) "Photo" else "Video")
        if (clip.speed != 1f) append(" · %.2fx".format(clip.speed).trimEnd('0').trimEnd('.'))
        if (clip.muted) append(" · muted")
    }
    Text(
        text,
        style = MaterialTheme.typography.labelSmall,
        color = Palette.Primary,
        maxLines = 1,
        modifier = modifier
            .padding(3.dp)
            .clip(RoundedCornerShape(4.dp))
            .background(Ink.Black.copy(alpha = 0.62f))
            .padding(horizontal = 5.dp, vertical = 1.dp),
    )
}

@Composable
private fun TrimHandle(
    atStart: Boolean,
    onBegin: () -> Unit,
    modifier: Modifier = Modifier,
    onDrag: (Float) -> Unit,
) {
    val drag by rememberUpdatedState(onDrag)
    val begin by rememberUpdatedState(onBegin)
    Box(
        modifier = modifier
            .width(HANDLE_WIDTH)
            .fillMaxHeight()
            .background(
                Accent,
                if (atStart) {
                    RoundedCornerShape(topStart = 7.dp, bottomStart = 7.dp)
                } else {
                    RoundedCornerShape(topEnd = 7.dp, bottomEnd = 7.dp)
                },
            )
            .pointerInput(atStart) {
                detectDragGestures(
                    // Once per gesture, so dragging a handle across the whole
                    // clip is one step of undo rather than four hundred.
                    onDragStart = { begin() },
                    onDrag = { change, dragged ->
                        change.consume()
                        drag(dragged.x)
                    },
                )
            },
        contentAlignment = Alignment.Center,
    ) {
        Box(
            modifier = Modifier
                .width(2.dp)
                .height(18.dp)
                .background(Ink.Black, RoundedCornerShape(1.dp)),
        )
    }
}
