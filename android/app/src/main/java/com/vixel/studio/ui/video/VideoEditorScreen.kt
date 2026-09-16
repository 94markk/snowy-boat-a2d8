package com.vixel.studio.ui.video

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.horizontalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.ContentCut
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.SeekParameters
import com.vixel.studio.core.model.AdjustSpec
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.AspectRatio
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.FilterPreset
import com.vixel.studio.core.model.Filters
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Transition
import com.vixel.studio.core.model.TransitionType
import com.vixel.studio.core.store.ProjectStore
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

/**
 * The timeline editor.
 *
 * Laid out the way phone editors are: preview on top, transport under it, the
 * timeline below that, and a scrolling rail of tools along the bottom. Opening
 * a tool covers the timeline but never the preview, so the frame being graded
 * stays on screen while it is being graded.
 */
@Composable
fun VideoEditorScreen(projectId: String? = null, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember { VideoEditorState() }
    val snackbar = remember { SnackbarHostState() }

    var openTool by remember { mutableStateOf<EditorTool?>(null) }
    var showExport by remember { mutableStateOf(false) }
    var immersive by remember { mutableStateOf(false) }
    var group by remember { mutableStateOf(AdjustSpec.Group.LIGHT) }

    // Open an existing project, and report any clip whose media has gone.
    LaunchedEffect(projectId) {
        if (projectId == null) return@LaunchedEffect
        val loaded = withContext(Dispatchers.IO) { ProjectStore.load(context, projectId) }
        if (loaded == null) {
            snackbar.showSnackbar("That project could not be opened")
            return@LaunchedEffect
        }
        state.replaceProject(loaded)
        val missing = withContext(Dispatchers.IO) { ProjectStore.missingMedia(context, loaded) }
        state.missingMediaIds = missing.toSet()
        if (missing.isNotEmpty()) {
            snackbar.showSnackbar(
                "${missing.size} clip(s) can no longer be opened. Re-add the media to fix them.",
            )
        }
    }

    // Autosave, debounced: a slider drag produces a burst of states and only
    // the settled one is worth writing.
    LaunchedEffect(state.project) {
        if (state.project.isEmpty) return@LaunchedEffect
        delay(1200)
        withContext(Dispatchers.IO) { ProjectStore.save(context, state.project) }
    }

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
            }
            if (player.isPlaying) {
                state.positionUs = state.project.startOf(index) + player.currentPosition * 1000L
            }
            state.isPlaying = player.isPlaying
            delay(60)
        }
    }

    /**
     * Moves the player to a timeline time.
     *
     * Scrubbing asks for a seek on every frame of the drag, and an exact seek
     * decodes forward from the previous keyframe each time, which is far too
     * slow to keep up with a finger. Dragging therefore snaps to keyframes and
     * the settle at the end is exact, so the preview stays live under the
     * finger and still lands on the right frame.
     */
    fun seekPlayer(timeUs: Long, exact: Boolean) {
        val clips = state.project.clips
        if (clips.isEmpty()) return
        val index = state.project.clipIndexAt(timeUs).coerceIn(0, clips.lastIndex)
        val offsetMs = ((timeUs - state.project.startOf(index)) / 1000L).coerceAtLeast(0L)
        player.setSeekParameters(
            if (exact) SeekParameters.EXACT else SeekParameters.CLOSEST_SYNC,
        )
        player.seekTo(index, offsetMs)
    }

    val picker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(20),
    ) { uris ->
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        scope.launch {
            val clips = withContext(Dispatchers.IO) {
                uris.mapNotNull { uri -> MediaProbe.clipFor(context, uri) }
            }
            state.addClips(clips)

            // Say how many failed rather than treating any failure as total
            // failure. Picking ten files and having one unsupported clip is a
            // very different thing from none of them loading.
            val failed = uris.size - clips.size
            if (failed > 0) {
                snackbar.showSnackbar(
                    if (clips.isEmpty()) {
                        "Could not read those $failed file(s)"
                    } else {
                        "Added ${clips.size}, skipped $failed unreadable file(s)"
                    },
                )
            }
        }
    }

    fun pickMedia() {
        picker.launch(
            PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo),
        )
    }

    // The preview is graded with the clip under the playhead, not the selected
    // one. Those can differ, and grading by selection meant the screen showed
    // one clip's frames through another clip's colour.
    val previewClip = state.clipUnderPlayhead

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        containerColor = Color.Black,
    ) { inner ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(inner)
                .background(Color.Black),
        ) {
            EditorTopBar(
                resolutionLabel = "${state.exportShortEdge}P",
                exportEnabled = state.project.clips.isNotEmpty() && !state.exporting,
                onClose = onBack,
                onResolution = { openTool = null; showExport = true },
                onExport = { openTool = null; showExport = true },
            )

            Box(
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentAlignment = Alignment.Center,
            ) {
                if (state.project.clips.isEmpty()) {
                    EmptyTimeline(onPick = { pickMedia() })
                } else {
                    VideoPreview(
                        player = player,
                        clip = previewClip,
                        adjustments = previewClip?.adjustments ?: Adjustments(),
                        canvasAspect = state.project.aspect.ratio,
                        canvasColor = state.project.backgroundColor,
                        overlays = state.project.overlays,
                        timelineUs = state.positionUs,
                        opacity = previewOpacity(state),
                        motion = previewMotion(state),
                        modifier = Modifier.fillMaxSize(),
                    )
                }
            }

            if (state.project.clips.isNotEmpty()) {
                TransportRow(
                    isPlaying = state.isPlaying,
                    canUndo = state.canUndo,
                    canRedo = state.canRedo,
                    onPlayPause = { if (player.isPlaying) player.pause() else player.play() },
                    onUndo = {
                        state.undo()
                        seekPlayer(state.positionUs, exact = true)
                    },
                    onRedo = {
                        state.redo()
                        seekPlayer(state.positionUs, exact = true)
                    },
                    onFullscreen = { immersive = !immersive },
                )

                when {
                    // Fullscreen hands the whole screen to the frame. Nothing
                    // below the transport is drawn until it is turned off.
                    immersive -> Unit

                    showExport -> ToolSheet(
                        title = "Export",
                        onBack = { showExport = false },
                    ) {
                        ExportPanel(state) { message ->
                            scope.launch { snackbar.showSnackbar(message) }
                        }
                    }

                    openTool != null -> {
                        val tool = openTool
                        ToolSheet(
                            title = tool?.label.orEmpty(),
                            onBack = { openTool = null },
                        ) {
                            when (tool) {
                                EditorTool.EDIT -> ClipPanel(state)
                                EditorTool.ADJUST -> AdjustPanel(state, group) { group = it }
                                EditorTool.FILTERS -> FilterPanel(state)
                                EditorTool.EFFECTS -> MotionPanel(state)
                                EditorTool.TEXT -> TextPanel(state) { message ->
                                    scope.launch { snackbar.showSnackbar(message) }
                                }
                                EditorTool.CAPTIONS -> CaptionControls(state) { message ->
                                    scope.launch { snackbar.showSnackbar(message) }
                                }
                                EditorTool.STICKERS -> StickerPanel(state)
                                EditorTool.AUDIO -> AudioPanel(state) { message ->
                                    scope.launch { snackbar.showSnackbar(message) }
                                }
                                EditorTool.CANVAS -> CanvasPanel(state)
                                null -> Unit
                            }
                        }
                    }

                    else -> {
                        Filmstrip(
                            project = state.project,
                            positionUs = state.positionUs,
                            selectedClipId = state.selectedClipId,
                            onScrub = { timeUs ->
                                state.positionUs = timeUs
                                seekPlayer(timeUs, exact = false)
                            },
                            onScrubFinished = { seekPlayer(state.positionUs, exact = true) },
                            onSelectClip = { id ->
                                state.selectClip(id)
                                seekPlayer(state.positionUs, exact = true)
                            },
                            onAddMedia = { pickMedia() },
                            onAddAudio = { openTool = EditorTool.AUDIO },
                            onAddText = { openTool = EditorTool.TEXT },
                        )
                        ChromeDivider()
                        ToolRail(
                            tools = EditorTool.entries,
                            onSelect = { openTool = it },
                        )
                    }
                }
            }
        }
    }
}

private fun previewOpacity(state: VideoEditorState): Float {
    val composition = state.project.compositionAt(state.positionUs)
    return if (composition.isTransitioning) composition.progress.coerceIn(0.05f, 1f) else 1f
}

/**
 * Keyframed transform and mask for the clip under the playhead, so the preview
 * shows the animation rather than only the static transform.
 */
private fun previewMotion(state: VideoEditorState): PreviewMotion {
    val index = state.project.clipIndexAt(state.positionUs)
    val clip = state.project.clips.getOrNull(index) ?: return PreviewMotion()
    val localUs = (state.positionUs - state.project.startOf(index)).coerceAtLeast(0L)
    val transform = clip.transformAt(localUs)
    return PreviewMotion(
        scale = transform.scale,
        offsetX = transform.offsetX,
        offsetY = transform.offsetY,
        rotationDegrees = transform.rotationDegrees,
        opacity = clip.keyedOpacityAt(localUs),
        mask = clip.mask,
    )
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
private fun ClipPanel(state: VideoEditorState) {
    val clip = state.activeClip
    if (clip == null) {
        PanelHint("Tap a clip on the timeline to edit it")
        return
    }

    LazyColumn(contentPadding = PaddingValues(bottom = 16.dp)) {
        item { TransitionSection(state, clip) }
        item {
            SimpleSlider(
                label = "Trim start",
                value = clip.trimStartUs.toFloat(),
                range = 0f..(clip.sourceDurationUs - Clip.MIN_CLIP_US).coerceAtLeast(1L).toFloat(),
                display = formatTimePrecise(clip.trimStartUs),
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
                // Guard the range: a clip barely longer than MIN_CLIP_US would
                // otherwise hand Slider a start >= end and blow up.
                range = Clip.MIN_CLIP_US.toFloat()..
                    maxOf(clip.sourceDurationUs, Clip.MIN_CLIP_US + 1L).toFloat(),
                display = formatTimePrecise(clip.trimEndUs),
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
                display = formatTimePrecise(clip.fadeInUs),
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
                display = formatTimePrecise(clip.fadeOutUs),
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

/**
 * A transition belongs to the clip it plays *into*, so the first clip on the
 * timeline has nothing to configure.
 */
@Composable
private fun TransitionSection(state: VideoEditorState, clip: Clip) {
    val index = state.project.clips.indexOfFirst { it.id == clip.id }

    Column(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Text(
            "Transition from previous clip",
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(start = 16.dp, bottom = 4.dp),
        )

        if (index <= 0) {
            Text(
                "The first clip has nothing before it. Select a later clip to add one.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
            return
        }

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            TransitionType.entries.forEach { type ->
                Chip(
                    label = type.label,
                    selected = type == clip.transition.type,
                    onClick = {
                        state.commitSelected {
                            it.copy(
                                transition = it.transition.copy(
                                    type = type,
                                    durationUs = it.transition.durationUs
                                        .coerceIn(Transition.MIN_US, Transition.MAX_US),
                                ),
                            )
                        }
                    },
                )
            }
        }

        if (clip.transition.type != TransitionType.NONE) {
            SimpleSlider(
                label = "Transition length",
                value = clip.transition.durationUs.toFloat(),
                range = Transition.MIN_US.toFloat()..Transition.MAX_US.toFloat(),
                display = formatTimePrecise(state.project.overlapBefore(index)),
                onChange = { v ->
                    state.beginGesture()
                    state.updateSelected {
                        it.copy(transition = it.transition.copy(durationUs = v.toLong()))
                    }
                },
                onFinished = { state.endGesture() },
            )
            Text(
                "Clips overlap for this long, so a transition shortens the timeline.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 2.dp),
            )
        }
    }
}

@Composable
private fun AdjustPanel(
    state: VideoEditorState,
    group: AdjustSpec.Group,
    onGroupChange: (AdjustSpec.Group) -> Unit,
) {
    val clip = state.activeClip
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
    val clip = state.activeClip
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
        OutlinedTextField(
            value = state.project.name,
            onValueChange = { state.rename(it) },
            label = { Text("Project name") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
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
                    selected = state.activeClip?.transform?.fit == mode,
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

    Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Text("Resolution", style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(720, 1080, 1440, 2160).forEach { value ->
                Chip(
                    label = "${value}p",
                    selected = value == state.exportShortEdge,
                    onClick = { state.exportShortEdge = value },
                )
            }
        }
        Text("Frame rate", style = MaterialTheme.typography.labelMedium)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(24, 30, 60).forEach { value ->
                Chip(
                    label = "$value fps",
                    selected = value == state.exportFps,
                    onClick = { state.exportFps = value },
                )
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
                            config = ExportConfig(
                                shortEdge = state.exportShortEdge,
                                fps = state.exportFps,
                            ),
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
