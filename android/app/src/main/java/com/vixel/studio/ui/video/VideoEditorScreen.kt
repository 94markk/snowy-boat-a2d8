package com.vixel.studio.ui.video

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.ContentCut
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Redo
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import com.vixel.studio.core.model.AdjustSpec
import com.vixel.studio.core.model.AspectRatio
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.FilterPreset
import com.vixel.studio.core.model.Filters
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.engine.video.ExportConfig
import com.vixel.studio.engine.video.ExportResult
import com.vixel.studio.engine.video.MediaProbe
import com.vixel.studio.engine.video.VideoExporter
import com.vixel.studio.engine.video.VideoIo
import com.vixel.studio.ui.common.Chip
import com.vixel.studio.ui.common.ParamSlider
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

private enum class VideoTab(val label: String) {
    CLIP("Clip"),
    ADJUST("Adjust"),
    FILTERS("Filters"),
    CANVAS("Canvas"),
    EXPORT("Export"),
}

@Composable
fun VideoEditorScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember { VideoEditorState() }
    val snackbar = remember { SnackbarHostState() }

    var tab by remember { mutableStateOf(VideoTab.CLIP) }
    var group by remember { mutableStateOf(AdjustSpec.Group.LIGHT) }

    val player = remember {
        ExoPlayer.Builder(context).build().apply { repeatMode = Player.REPEAT_MODE_OFF }
    }
    DisposableEffect(Unit) {
        onDispose { player.release() }
    }

    // Rebuild the playlist whenever the cut changes.
    LaunchedEffect(state.project.clips) {
        val items = state.project.clips.map { clip -> clip.toMediaItem() }
        val wasPlaying = player.isPlaying
        player.setMediaItems(items)
        player.prepare()
        if (wasPlaying) player.play()
    }

    // Per-clip speed: ExoPlayer's rate is global, so follow item transitions.
    LaunchedEffect(player, state.project.clips) {
        while (true) {
            val index = player.currentMediaItemIndex
            val clip = state.project.clips.getOrNull(index)
            if (clip != null) {
                val target = clip.speed.coerceIn(0.1f, 8f)
                if (player.playbackParameters.speed != target) player.setPlaybackSpeed(target)
                if (state.selectedClipId == null) state.selectedClipId = clip.id
            }
            if (player.isPlaying) {
                state.positionUs = state.project.startOf(index) + player.currentPosition * 1000L
            }
            state.isPlaying = player.isPlaying
            delay(60)
        }
    }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(20),
    ) { uris ->
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        scope.launch {
            val clips = withContext(Dispatchers.IO) {
                uris.mapNotNull { uri -> MediaProbe.clipFor(context, uri) }
            }
            if (clips.isEmpty()) {
                snackbar.showSnackbar("Those files could not be read")
            } else {
                state.addClips(clips)
            }
        }
    }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }) { inner ->
        Column(modifier = Modifier.fillMaxSize().padding(inner)) {

            Row(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                }
                Text(
                    "Video",
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
                IconButton(onClick = { state.undo() }, enabled = state.canUndo) {
                    Icon(Icons.Rounded.Undo, contentDescription = "Undo")
                }
                IconButton(onClick = { state.redo() }, enabled = state.canRedo) {
                    Icon(Icons.Rounded.Redo, contentDescription = "Redo")
                }
                IconButton(
                    onClick = {
                        picker.launch(
                            PickVisualMediaRequest(
                                ActivityResultContracts.PickVisualMedia.ImageAndVideo,
                            ),
                        )
                    },
                ) {
                    Icon(Icons.Rounded.Add, contentDescription = "Add media")
                }
            }

            Box(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth()
                    .background(MaterialTheme.colorScheme.background),
                contentAlignment = Alignment.Center,
            ) {
                if (state.project.clips.isEmpty()) {
                    EmptyTimeline(
                        onPick = {
                            picker.launch(
                                PickVisualMediaRequest(
                                    ActivityResultContracts.PickVisualMedia.ImageAndVideo,
                                ),
                            )
                        },
                    )
                } else {
                    VideoPreview(
                        player = player,
                        clip = state.selectedClip ?: state.project.clips.firstOrNull(),
                        adjustments = (state.selectedClip ?: state.project.clips.first()).adjustments,
                        canvasAspect = state.project.aspect.ratio,
                        canvasColor = state.project.backgroundColor,
                        modifier = Modifier.fillMaxSize(),
                    )
                }
            }

            if (state.project.clips.isNotEmpty()) {
                TransportBar(state, player)

                Timeline(
                    project = state.project,
                    selectedClipId = state.selectedClipId,
                    positionUs = state.positionUs,
                    onSelect = { id ->
                        state.selectedClipId = id
                        val index = state.project.clips.indexOfFirst { it.id == id }
                        if (index >= 0) player.seekTo(index, 0L)
                    },
                    onSeek = { state.positionUs = it },
                    onSeekFinished = {
                        val index = state.project.clipIndexAt(state.positionUs)
                            .takeIf { it >= 0 } ?: 0
                        val offsetMs =
                            (state.positionUs - state.project.startOf(index)) / 1000L
                        player.seekTo(index, offsetMs)
                    },
                )

                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 12.dp, vertical = 6.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    VideoTab.entries.forEach { entry ->
                        Chip(
                            label = entry.label,
                            selected = entry == tab,
                            onClick = { tab = entry },
                        )
                    }
                }

                Box(modifier = Modifier.heightIn(min = 170.dp, max = 290.dp)) {
                    when (tab) {
                        VideoTab.CLIP -> ClipPanel(state)
                        VideoTab.ADJUST -> AdjustPanel(state, group) { group = it }
                        VideoTab.FILTERS -> FilterPanel(state)
                        VideoTab.CANVAS -> CanvasPanel(state)
                        VideoTab.EXPORT -> ExportPanel(state) { message ->
                            scope.launch { snackbar.showSnackbar(message) }
                        }
                    }
                }
            }
        }
    }
}

private fun Clip.toMediaItem(): MediaItem {
    val builder = MediaItem.Builder().setUri(Uri.parse(uri))
    if (kind == MediaKind.IMAGE) {
        builder.setImageDurationMs(timelineDurationUs / 1000L)
    } else {
        builder.setClippingConfiguration(
            MediaItem.ClippingConfiguration.Builder()
                .setStartPositionMs(trimStartUs / 1000L)
                .setEndPositionMs(trimEndUs / 1000L)
                .build(),
        )
    }
    return builder.build()
}

@Composable
private fun EmptyTimeline(onPick: () -> Unit) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
        modifier = Modifier.padding(32.dp),
    ) {
        Text("Timeline is empty", style = MaterialTheme.typography.titleMedium)
        Text(
            "Add video or photos to start. The canvas is 9:16 by default.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Button(onClick = onPick) { Text("Add media") }
    }
}

@Composable
private fun TransportBar(state: VideoEditorState, player: ExoPlayer) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(
            onClick = { if (player.isPlaying) player.pause() else player.play() },
        ) {
            Icon(
                if (state.isPlaying) Icons.Rounded.Pause else Icons.Rounded.PlayArrow,
                contentDescription = if (state.isPlaying) "Pause" else "Play",
            )
        }
        IconButton(onClick = { state.splitAtPlayhead() }) {
            Icon(Icons.Rounded.ContentCut, contentDescription = "Split at playhead")
        }
        IconButton(
            onClick = { state.duplicateSelected() },
            enabled = state.selectedClipId != null,
        ) {
            Icon(Icons.Rounded.ContentCopy, contentDescription = "Duplicate clip")
        }
        IconButton(
            onClick = { state.removeSelected() },
            enabled = state.selectedClipId != null,
        ) {
            Icon(Icons.Rounded.Delete, contentDescription = "Delete clip")
        }
    }
}

@Composable
private fun ClipPanel(state: VideoEditorState) {
    val clip = state.selectedClip
    if (clip == null) {
        PanelHint("Tap a clip on the timeline to edit it")
        return
    }

    LazyColumn(contentPadding = PaddingValues(bottom = 16.dp)) {
        item {
            SimpleSlider(
                label = "Trim start",
                value = clip.trimStartUs.toFloat(),
                range = 0f..(clip.sourceDurationUs - Clip.MIN_CLIP_US).coerceAtLeast(1L).toFloat(),
                display = formatTime(clip.trimStartUs),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.withTrim(v.toLong(), it.trimEndUs) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            SimpleSlider(
                label = "Trim end",
                value = clip.trimEndUs.toFloat(),
                range = Clip.MIN_CLIP_US.toFloat()..clip.sourceDurationUs.coerceAtLeast(1L).toFloat(),
                display = formatTime(clip.trimEndUs),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.withTrim(it.trimStartUs, v.toLong()) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            SimpleSlider(
                label = "Speed",
                value = clip.speed,
                range = 0.25f..4f,
                display = "%.2fx".format(clip.speed),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.copy(speed = v) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            SimpleSlider(
                label = "Volume",
                value = clip.volume,
                range = 0f..2f,
                display = "${(clip.volume * 100).toInt()}%",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.copy(volume = v) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            SimpleSlider(
                label = "Fade in",
                value = clip.fadeInUs.toFloat(),
                range = 0f..2_000_000f,
                display = formatTime(clip.fadeInUs),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.copy(fadeInUs = v.toLong()) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            SimpleSlider(
                label = "Fade out",
                value = clip.fadeOutUs.toFloat(),
                range = 0f..2_000_000f,
                display = formatTime(clip.fadeOutUs),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected { it.copy(fadeOutUs = v.toLong()) }
                },
                onFinished = { state.endGesture() },
            )
        }
        item {
            Row(
                modifier = Modifier.fillMaxWidth().padding(16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Chip(
                    label = if (clip.muted) "Unmute" else "Mute",
                    selected = clip.muted,
                    onClick = { state.commitSelected { it.copy(muted = !it.muted) } },
                )
                Chip(
                    label = "Move left",
                    selected = false,
                    onClick = { state.moveSelected(-1) },
                )
                Chip(
                    label = "Move right",
                    selected = false,
                    onClick = { state.moveSelected(1) },
                )
            }
        }
    }
}

@Composable
private fun AdjustPanel(
    state: VideoEditorState,
    group: AdjustSpec.Group,
    onGroupChange: (AdjustSpec.Group) -> Unit,
) {
    val clip = state.selectedClip
    if (clip == null) {
        PanelHint("Select a clip to grade it")
        return
    }
    Column {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp, vertical = 4.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            AdjustSpec.Group.entries.forEach { entry ->
                Chip(
                    label = entry.name.lowercase().replaceFirstChar { it.uppercase() },
                    selected = entry == group,
                    onClick = { onGroupChange(entry) },
                )
            }
        }
        LazyColumn(contentPadding = PaddingValues(bottom = 16.dp)) {
            items(AdjustSpec.group(group)) { spec ->
                ParamSlider(
                    spec = spec,
                    value = clip.adjustments.get(spec.id),
                    onValueChange = { value ->
                        state.beginGesture()
                        state.updateSelected { it.copy(adjustments = it.adjustments.set(spec.id, value)) }
                    },
                    onValueChangeFinished = { state.endGesture() },
                    onReset = {
                        state.commitSelected {
                            it.copy(adjustments = it.adjustments.set(spec.id, spec.default))
                        }
                    },
                )
            }
        }
    }
}

@Composable
private fun FilterPanel(state: VideoEditorState) {
    val clip = state.selectedClip
    if (clip == null) {
        PanelHint("Select a clip to apply a look")
        return
    }
    Column {
        LazyRow(
            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            items(Filters.ALL) { preset: FilterPreset ->
                Chip(
                    label = preset.name,
                    selected = preset.id == clip.adjustments.filterId,
                    onClick = {
                        state.commitSelected {
                            it.copy(adjustments = it.adjustments.copy(filterId = preset.id))
                        }
                    },
                )
            }
        }
        if (clip.adjustments.filterId != Filters.NONE_ID) {
            SimpleSlider(
                label = "Strength",
                value = clip.adjustments.filterStrength,
                range = 0f..1f,
                display = "${(clip.adjustments.filterStrength * 100).toInt()}%",
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected {
                        it.copy(adjustments = it.adjustments.copy(filterStrength = v))
                    }
                },
                onFinished = { state.endGesture() },
            )
        }
    }
}

@Composable
private fun CanvasPanel(state: VideoEditorState) {
    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Aspect ratio", style = MaterialTheme.typography.labelMedium)
        Row(
            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            AspectRatio.entries.forEach { aspect ->
                Chip(
                    label = aspect.label,
                    selected = aspect == state.project.aspect,
                    onClick = { state.commit { it.copy(aspect = aspect) } },
                )
            }
        }
        Text("Fit", style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FitMode.entries.forEach { mode ->
                Chip(
                    label = mode.label,
                    selected = state.selectedClip?.transform?.fit == mode,
                    onClick = {
                        state.commitSelected { it.copy(transform = it.transform.copy(fit = mode)) }
                    },
                )
            }
        }
    }
}

@Composable
private fun ExportPanel(state: VideoEditorState, onMessage: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var shortEdge by remember { mutableStateOf(1080) }
    var fps by remember { mutableStateOf(30) }

    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Resolution", style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(720, 1080, 1440, 2160).forEach { value ->
                Chip(
                    label = "${value}p",
                    selected = value == shortEdge,
                    onClick = { shortEdge = value },
                )
            }
        }
        Text("Frame rate", style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(24, 30, 60).forEach { value ->
                Chip(label = "$value fps", selected = value == fps, onClick = { fps = value })
            }
        }

        if (state.exporting) {
            LinearProgressIndicator(
                progress = { state.exportProgress },
                modifier = Modifier.fillMaxWidth(),
            )
            Text(
                "Exporting ${(state.exportProgress * 100).toInt()}%",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Button(
            enabled = !state.exporting && state.project.clips.isNotEmpty(),
            onClick = {
                state.exporting = true
                state.exportProgress = 0f
                val project = state.project
                scope.launch {
                    val result = withContext(Dispatchers.IO) {
                        val exporter = VideoExporter(
                            context = context,
                            project = project,
                            config = ExportConfig(shortEdge = shortEdge, fps = fps),
                        )
                        exporter.export(VideoIo.newExportFile(context)) { p ->
                            state.exportProgress = p
                        }
                    }
                    state.exporting = false
                    when (result) {
                        is ExportResult.Success -> onMessage(
                            result.warning ?: "Saved to Movies/${VideoIo.ALBUM}",
                        )
                        is ExportResult.Failure -> onMessage("Export failed: ${result.message}")
                    }
                }
            },
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text("Export MP4")
        }
    }
}

@Composable
private fun PanelHint(text: String) {
    Box(modifier = Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
        Text(
            text,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
    }
}

@Composable
private fun SimpleSlider(
    label: String,
    value: Float,
    range: ClosedFloatingPointRange<Float>,
    display: String,
    onChange: (Float) -> Unit,
    onFinished: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 2.dp)) {
        Row(modifier = Modifier.fillMaxWidth()) {
            Text(
                label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
            )
            Text(
                display,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
            )
        }
        androidx.compose.material3.Slider(
            value = value.coerceIn(range.start, range.endInclusive),
            onValueChange = onChange,
            onValueChangeFinished = onFinished,
            valueRange = range,
        )
    }
}
