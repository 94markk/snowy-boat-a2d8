package com.vixel.studio.ui.video

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.vixel.studio.core.model.Keyframe
import com.vixel.studio.core.model.KeyframeProperty
import com.vixel.studio.core.model.KeyframeTrack
import com.vixel.studio.core.model.Easing
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.engine.video.MotionTracker
import com.vixel.studio.ui.common.Chip
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlin.math.roundToLong

/**
 * Makes an overlay follow something in the footage.
 *
 * The overlay's current position is the thing being tracked, so the workflow
 * is: put the sticker on the subject, then tap Track. That avoids a separate
 * region-picker UI and means what you tracked is always what you placed.
 */
@Composable
fun TrackingControls(overlay: Overlay, state: VideoEditorState) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var progress by remember { mutableStateOf<Float?>(null) }
    var message by remember { mutableStateOf<String?>(null) }

    val index = state.project.clipIndexAt(overlay.startUs)
    val clip = state.project.clips.getOrNull(index)
    val trackable = clip != null && clip.kind == MediaKind.VIDEO

    Column(modifier = Modifier.fillMaxWidth()) {
        SectionLabel("Motion tracking")

        if (!trackable) {
            Text(
                "Place this overlay over a video clip to track it. Stills have " +
                    "nothing to follow.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
            return
        }

        Text(
            "Position the overlay on your subject, then track. Keys are written " +
                "into its position, so you can still nudge it afterwards.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(horizontal = 16.dp),
        )

        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Chip(
                label = if (progress != null) "Tracking..." else "Track subject",
                selected = progress != null,
                onClick = {
                    if (progress != null) return@Chip
                    val source = clip ?: return@Chip
                    progress = 0f
                    message = null

                    scope.launch {
                        val clipStart = state.project.startOf(index)
                        val clipEnd = clipStart + source.timelineDurationUs
                        val fromUs = overlay.startUs.coerceIn(clipStart, clipEnd)
                        val toUs = overlay.endUs.coerceIn(fromUs, clipEnd)

                        // Tracking walks the source file, so the overlay's
                        // timeline window is mapped through the clip's trim
                        // and speed before sampling.
                        val sourceFrom = state.project.sourceTimeFor(index, fromUs)
                        val sourceTo = state.project.sourceTimeFor(index, toUs)

                        val result = withContext(Dispatchers.Default) {
                            MotionTracker.track(
                                context = context,
                                uri = android.net.Uri.parse(source.uri),
                                startUs = sourceFrom,
                                endUs = sourceTo,
                                startX = overlay.transform.x,
                                startY = overlay.transform.y,
                                onProgress = { p -> progress = p },
                            )
                        }

                        progress = null
                        if (!result.isUsable) {
                            message = "Could not follow that spot. Try a higher-contrast subject."
                            return@launch
                        }

                        // Source times back to overlay-relative times.
                        val speed = source.speed.coerceAtLeast(0.01f)
                        val tracks = listOf(
                            KeyframeProperty.OFFSET_X to { p: com.vixel.studio.engine.video.TrackPoint -> p.x },
                            KeyframeProperty.OFFSET_Y to { p: com.vixel.studio.engine.video.TrackPoint -> p.y },
                        ).map { (property, pick) ->
                            KeyframeTrack(
                                property,
                                result.points.map { point ->
                                    val timelineUs = clipStart +
                                        ((point.timeUs - source.trimStartUs) / speed).roundToLong()
                                    val local = (timelineUs - overlay.startUs).coerceAtLeast(0L)
                                    val base = if (property == KeyframeProperty.OFFSET_X) {
                                        overlay.transform.x
                                    } else {
                                        overlay.transform.y
                                    }
                                    Keyframe(local, base + pick(point), Easing.LINEAR)
                                }.distinctBy { it.timeUs },
                            )
                        }

                        state.commitSelectedOverlay { it.withKeyframes(tracks) }
                        message = "Tracked ${result.points.size} points"
                    }
                },
            )
        }

        progress?.let { value ->
            LinearProgressIndicator(
                progress = { value },
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
            )
        }

        message?.let { text ->
            Text(
                text,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 2.dp),
            )
        }
    }
}
