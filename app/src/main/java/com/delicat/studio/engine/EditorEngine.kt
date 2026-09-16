package com.delicat.studio.engine

import android.content.Context
import android.net.Uri
import android.view.Surface
import com.delicat.studio.engine.export.ExportRequest
import com.delicat.studio.engine.export.ExportState
import com.delicat.studio.engine.export.Exporter
import com.delicat.studio.engine.preview.LayerSource
import com.delicat.studio.engine.preview.Playback
import com.delicat.studio.engine.preview.Scene
import com.delicat.studio.engine.preview.SceneLayer
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.CanvasRatio
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind
import com.delicat.studio.model.Project
import com.delicat.studio.model.Transition
import com.delicat.studio.model.TransitionType
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlin.math.roundToLong

/**
 * Everything the editor knows and every way it can change.
 *
 * The timeline is the authority on time. The player is asked where it is
 * inside the one clip it has loaded, and this class turns that into a
 * position on the timeline — never the other way round. Playback across a cut
 * is therefore a decision made here, which is what makes splitting or
 * reordering a clip mid-playback behave instead of fighting the player.
 */
class EditorEngine(
    private val context: Context,
    private val scope: CoroutineScope,
) {

    private val _state = MutableStateFlow(EditorState())
    val state: StateFlow<EditorState> = _state.asStateFlow()

    private val _scene = MutableStateFlow(Scene())
    val scene: StateFlow<Scene> = _scene.asStateFlow()

    val frames = FrameCache(context)
    private val playback = Playback(context)
    private val exporter = Exporter(context)

    private val undo = ArrayDeque<Project>()
    private val redo = ArrayDeque<Project>()

    private var ticker: Job? = null
    private var exportJob: Job? = null

    /**
     * Stills being decoded, and stills that could not be.
     *
     * Both are needed because the scene is rebuilt sixty times a second while
     * playing. Without the first, a transition would start a decode for its
     * outgoing frame, cancel the one for its incoming frame, and the two would
     * take turns cancelling each other forever. Without the second, a frame
     * that cannot be read would be asked for again on every tick.
     */
    private val decodingStills = mutableSetOf<String>()
    private val unreadableStills = mutableSetOf<String>()
    private var lastTickAt = 0L

    init {
        playback.onError = { reason -> notify("This clip would not play ($reason)") }
        playback.onReady = { refreshScene() }
    }

    // ---- surface ----------------------------------------------------------

    fun attachSurface(surface: Surface) {
        playback.attach(surface)
        // The context can be rebuilt at any point, and the clip that was
        // loaded is still loaded — it just has nowhere to draw until now.
        reconcilePlayer(force = true)
        refreshScene()
    }

    // ---- importing --------------------------------------------------------

    fun addMedia(uris: List<Uri>) {
        if (uris.isEmpty()) return
        scope.launch {
            _state.update { it.copy(isImporting = true) }
            val found = withContext(Dispatchers.IO) { MediaProbe.probeAll(context, uris) }

            if (found.isEmpty()) {
                _state.update { it.copy(isImporting = false) }
                notify("Nothing here could be opened")
                return@launch
            }

            pushUndo()
            _state.update { current ->
                val clips = current.project.clips + found.map { info ->
                    Clip(
                        uri = info.uri.toString(),
                        kind = info.kind,
                        sourceDurationUs = info.durationUs,
                        trimEndUs = info.durationUs,
                        sourceWidth = info.width,
                        sourceHeight = info.height,
                        sourceRotationDegrees = info.rotationDegrees,
                    )
                }
                // The canvas takes its shape from the first thing imported.
                // Defaulting to portrait would drop landscape footage into a
                // thin band between two black slabs, which reads as a broken
                // import rather than as a canvas waiting to be changed.
                val aspect = if (current.project.isEmpty) {
                    CanvasRatio.closestTo(found[0].width, found[0].height)
                } else {
                    current.project.aspect
                }
                current.copy(
                    project = current.project.copy(clips = clips, aspect = aspect),
                    isImporting = false,
                    selectedClipId = clips.lastOrNull()?.id,
                )
            }

            val skipped = uris.size - found.size
            if (skipped > 0) notify("$skipped file${if (skipped == 1) "" else "s"} could not be opened")
            reconcilePlayer(force = true)
            refreshScene()
        }
    }

    // ---- transport --------------------------------------------------------

    fun togglePlay() {
        if (_state.value.isEmpty) return
        if (_state.value.isPlaying) pause() else play()
    }

    fun play() {
        val current = _state.value
        if (current.isEmpty) return
        // Starting from the end would look like a dead play button, so it
        // rewinds rather than doing nothing.
        if (current.positionUs >= current.durationUs - 10_000L) seek(0L)

        _state.update { it.copy(isPlaying = true, selectedClipId = null) }
        reconcilePlayer(force = false)
        playback.setPlaying(true)
        startTicker()
    }

    fun pause() {
        _state.update { it.copy(isPlaying = false) }
        playback.setPlaying(false)
        ticker?.cancel()
        ticker = null
    }

    fun seek(positionUs: Long) {
        val clamped = positionUs.coerceIn(0L, _state.value.durationUs)
        _state.update { it.copy(positionUs = clamped) }
        reconcilePlayer(force = false)
        refreshScene()
    }

    /** Scrubbing: the same as a seek, but it cannot start playback. */
    fun scrubTo(positionUs: Long) {
        if (_state.value.isPlaying) pause()
        seek(positionUs)
    }

    fun setZoom(pixelsPerSecond: Float) {
        _state.update { it.copy(pixelsPerSecond = pixelsPerSecond.coerceIn(12f, 420f)) }
    }

    private fun startTicker() {
        ticker?.cancel()
        lastTickAt = System.nanoTime()
        ticker = scope.launch {
            while (_state.value.isPlaying) {
                tick()
                delay(TICK_MS)
            }
        }
    }

    private fun tick() {
        val current = _state.value
        val project = current.project
        if (project.isEmpty) {
            pause()
            return
        }

        val now = System.nanoTime()
        val elapsedUs = ((now - lastTickAt) / 1_000L).coerceIn(0L, 250_000L)
        lastTickAt = now

        val index = project.clipIndexAt(current.positionUs)
        val clip = project.clips.getOrNull(index)

        val next = when {
            clip == null -> current.durationUs
            // A still has no decoder to ask, so its clock is the wall clock.
            clip.isImage -> current.positionUs + elapsedUs
            else -> {
                val into = playback.positionUs().coerceIn(0L, clip.trimmedDurationUs)
                val mapped = project.startOf(index) +
                    (into / clip.speed.coerceAtLeast(0.01f)).roundToLong()
                // While the decoder is still filling, its position sticks; the
                // wall clock keeps the playhead moving so the timeline does
                // not appear to freeze at every cut.
                if (playback.isBuffering) current.positionUs + elapsedUs else mapped
            }
        }

        if (next >= current.durationUs) {
            _state.update { it.copy(positionUs = it.durationUs) }
            pause()
            refreshScene()
            return
        }

        _state.update { it.copy(positionUs = next) }
        if (project.clipIndexAt(next) != index) reconcilePlayer(force = false)
        refreshScene()
    }

    /**
     * Points the player at whatever the playhead is now over.
     *
     * Everything about when to reload lives in [Playback.prepare]; the only
     * decision here is whether the position inside the clip has drifted far
     * enough from where the player is to be worth a seek. Seeking on every
     * tick would make playback stutter, and never seeking would make scrubbing
     * do nothing.
     */
    private fun reconcilePlayer(force: Boolean) {
        val current = _state.value
        val project = current.project
        val index = project.clipIndexAt(current.positionUs)
        val clip = project.clips.getOrNull(index)

        if (clip == null || clip.kind != MediaKind.VIDEO) {
            playback.prepare(clip ?: return, current.isPlaying)
            return
        }

        val reloaded = playback.prepare(clip, current.isPlaying)
        val wanted = (project.sourceTimeFor(index, current.positionUs) - clip.trimStartUs)
            .coerceAtLeast(0L)
        val drift = kotlin.math.abs(playback.positionUs() - wanted)
        if (reloaded || force || drift > SEEK_TOLERANCE_US) {
            playback.seekWithin(clip, wanted)
        }
        playback.applyTrack(clip)
    }

    // ---- the drawn frame --------------------------------------------------

    private fun refreshScene() {
        val current = _state.value
        val project = current.project
        if (project.isEmpty) {
            _scene.value = Scene(ratio = project.aspect, background = project.backgroundColor)
            return
        }

        val composition = project.compositionAt(current.positionUs)
        val index = if (composition.primaryIndex >= 0) {
            composition.primaryIndex
        } else {
            project.clips.lastIndex
        }
        val clip = project.clips.getOrNull(index)

        val front = clip?.let { layerFor(it, project.sourceTimeFor(index, current.positionUs)) }

        var back: SceneLayer? = null
        if (composition.isTransitioning) {
            val outgoing = project.clips.getOrNull(composition.fromIndex)
            if (outgoing != null) {
                // One frozen frame for the whole transition rather than one
                // per tick: the cut point is a fixed time, so this decodes
                // once and then comes straight out of the cache.
                val at = project.sourceTimeFor(composition.fromIndex, project.startOf(index))
                back = layerFor(outgoing, at)
            }
        }

        _scene.value = Scene(
            ratio = project.aspect,
            background = project.backgroundColor,
            back = back,
            front = front,
            transition = if (back != null) composition.transition.type else TransitionType.NONE,
            progress = composition.progress,
        )
    }

    /**
     * Builds one layer, using the video texture for the clip the player holds
     * and a cached still for everything else.
     *
     * When a still is not decoded yet the layer is returned without a source
     * and the decode is started; the scene is rebuilt when it lands. Returning
     * a half-built layer instead would draw whatever texture was there last,
     * which is how a preview ends up showing the previous clip.
     */
    private fun layerFor(clip: Clip, sourceTimeUs: Long): SceneLayer? {
        val playing = _state.value.project.clipIndexAt(_state.value.positionUs)
        val isLiveVideo = clip.kind == MediaKind.VIDEO &&
            _state.value.project.clips.getOrNull(playing)?.id == clip.id

        val width = if (isLiveVideo && playback.videoWidth > 0) playback.videoWidth else clip.sourceWidth
        val height = if (isLiveVideo && playback.videoHeight > 0) playback.videoHeight else clip.sourceHeight

        val turns = clip.rotationTurns + (
            if (isLiveVideo) playback.pendingRotation / 90 else clip.sourceRotationDegrees / 90
            )

        val source = if (isLiveVideo) {
            LayerSource.Video
        } else {
            val at = if (clip.isImage) 0L else sourceTimeUs
            val key = frames.keyFor(clip.uri, at, STILL_EDGE)
            val cached = frames.peek(key)
            if (cached == null) {
                loadStill(clip, at, key)
                return null
            }
            LayerSource.Still(key, cached)
        }

        return SceneLayer(
            source = source,
            adjustments = clip.adjustments,
            fit = clip.fit,
            quarterTurns = turns,
            sourceWidth = width,
            sourceHeight = height,
        )
    }

    private fun loadStill(clip: Clip, atUs: Long, key: String) {
        if (key in unreadableStills) return
        if (!decodingStills.add(key)) return
        scope.launch {
            val bitmap = frames.frame(Uri.parse(clip.uri), clip.kind, atUs, STILL_EDGE)
            decodingStills.remove(key)
            if (bitmap == null) {
                unreadableStills += key
                notify("A frame of this clip could not be read")
            } else {
                refreshScene()
            }
        }
    }

    // ---- editing ----------------------------------------------------------

    fun openTool(tool: Tool?) {
        _state.update { it.copy(tool = if (it.tool == tool) null else tool) }
    }

    fun selectClip(id: String?) {
        if (id == null) {
            _state.update { it.copy(selectedClipId = null) }
            return
        }
        val project = _state.value.project
        val index = project.clips.indexOfFirst { it.id == id }
        if (index < 0) return

        // Selecting moves the playhead into the clip. Editing a clip you
        // cannot see is how a control comes to look broken.
        val start = project.startOf(index)
        val here = _state.value.positionUs
        val inside = here in start until (start + project.clips[index].timelineDurationUs)
        _state.update {
            it.copy(
                selectedClipId = id,
                positionUs = if (inside) here else start,
            )
        }
        reconcilePlayer(force = true)
        refreshScene()
    }

    /** Call once at the start of a gesture, so a drag is one undo step. */
    fun beginChange() = pushUndo()

    fun setAdjustment(id: String, value: Float) = editActive { clip ->
        clip.copy(adjustments = clip.adjustments.set(id, value))
    }

    fun setLook(lookId: String) = editActive { clip ->
        clip.copy(adjustments = clip.adjustments.copy(lookId = lookId))
    }

    fun resetAdjustments() {
        pushUndo()
        editActive { clip -> clip.copy(adjustments = Adjustments()) }
    }

    fun setSpeed(speed: Float) = editActive { clip ->
        clip.copy(speed = speed.coerceIn(0.25f, 4f))
    }

    fun setVolume(volume: Float) = editActive { clip -> clip.copy(volume = volume.coerceIn(0f, 2f)) }

    fun toggleMute() = editActive { clip -> clip.copy(muted = !clip.muted) }

    fun rotate() = editActive { clip -> clip.copy(rotationTurns = (clip.rotationTurns + 1) % 4) }

    fun setFit(fit: com.delicat.studio.model.FitMode) = editActive { clip -> clip.copy(fit = fit) }

    fun setTrim(startUs: Long, endUs: Long) = editActive { clip -> clip.withTrim(startUs, endUs) }

    fun setImageDuration(durationUs: Long) = editActive { clip ->
        if (!clip.isImage) clip else clip.copy(
            sourceDurationUs = durationUs,
            trimStartUs = 0L,
            trimEndUs = durationUs.coerceAtLeast(Clip.MIN_US),
        )
    }

    fun setTransition(type: TransitionType, durationUs: Long) {
        val index = _state.value.activeIndex
        if (index <= 0) {
            notify("A transition needs a clip before this one")
            return
        }
        pushUndo()
        val id = _state.value.project.clips[index].id
        mutate { project ->
            project.updateClip(id) { clip ->
                clip.copy(
                    transition = Transition(
                        type,
                        durationUs.coerceIn(Transition.MIN_US, Transition.MAX_US),
                    ),
                )
            }
        }
    }

    fun setAspect(ratio: CanvasRatio) {
        pushUndo()
        mutate { it.copy(aspect = ratio) }
    }

    fun split() {
        val current = _state.value
        val before = current.project.clips.size
        pushUndo()
        mutate { it.splitAt(current.positionUs) }
        if (_state.value.project.clips.size == before) {
            undo.removeLastOrNull()
            notify("Move the playhead further into the clip to split it")
        }
    }

    fun duplicate() {
        val id = _state.value.activeClip?.id ?: return
        pushUndo()
        mutate { it.duplicateClip(id) }
    }

    fun delete() {
        val id = _state.value.activeClip?.id ?: return
        pushUndo()
        mutate { it.removeClip(id) }
        _state.update { it.copy(selectedClipId = null) }
        reconcilePlayer(force = true)
    }

    fun move(from: Int, to: Int) {
        pushUndo()
        mutate { it.moveClip(from, to) }
    }

    private fun editActive(transform: (Clip) -> Clip) {
        val id = _state.value.activeClip?.id ?: return
        mutate { it.updateClip(id, transform) }
        _state.value.activeClip?.let { playback.applyTrack(it) }
    }

    private fun mutate(transform: (Project) -> Project) {
        _state.update { current ->
            val project = transform(current.project)
            current.copy(
                project = project,
                positionUs = current.positionUs.coerceIn(0L, project.durationUs),
            )
        }
        reconcilePlayer(force = false)
        refreshScene()
    }

    // ---- history ----------------------------------------------------------

    private fun pushUndo() {
        undo.addLast(_state.value.project)
        while (undo.size > HISTORY) undo.removeFirst()
        redo.clear()
        syncHistoryFlags()
    }

    fun undo() {
        val previous = undo.removeLastOrNull() ?: return
        redo.addLast(_state.value.project)
        applyHistory(previous)
    }

    fun redo() {
        val next = redo.removeLastOrNull() ?: return
        undo.addLast(_state.value.project)
        applyHistory(next)
    }

    private fun applyHistory(project: Project) {
        _state.update {
            it.copy(
                project = project,
                positionUs = it.positionUs.coerceIn(0L, project.durationUs),
                selectedClipId = it.selectedClipId?.takeIf { id -> project.clips.any { c -> c.id == id } },
            )
        }
        syncHistoryFlags()
        reconcilePlayer(force = true)
        refreshScene()
    }

    private fun syncHistoryFlags() {
        _state.update { it.copy(canUndo = undo.isNotEmpty(), canRedo = redo.isNotEmpty()) }
    }

    // ---- export -----------------------------------------------------------

    fun export() {
        val current = _state.value
        if (current.isEmpty) return
        if (current.export is ExportState.Running) return

        pause()
        exportJob?.cancel()
        exportJob = scope.launch {
            _state.update { it.copy(export = ExportState.Running(0f, "Preparing")) }
            val result = exporter.run(
                ExportRequest(project = current.project),
                onProgress = { fraction, stage ->
                    _state.update { it.copy(export = ExportState.Running(fraction, stage)) }
                },
            )
            _state.update { it.copy(export = result) }
        }
    }

    fun cancelExport() {
        exporter.cancel()
        exportJob?.cancel()
        _state.update { it.copy(export = ExportState.Idle) }
    }

    fun dismissExport() {
        _state.update { it.copy(export = ExportState.Idle) }
    }

    // ---- notices ----------------------------------------------------------

    private fun notify(message: String) {
        _state.update { it.copy(notice = message) }
    }

    fun clearNotice() {
        _state.update { it.copy(notice = null) }
    }

    fun release() {
        ticker?.cancel()
        exportJob?.cancel()
        exporter.cancel()
        playback.release()
        frames.clear()
    }

    private companion object {
        const val TICK_MS = 16L
        const val HISTORY = 40

        /** Close enough that a correction is invisible, far enough to not thrash. */
        const val SEEK_TOLERANCE_US = 220_000L

        /** Preview stills. Wide enough for a phone screen, small enough to decode fast. */
        const val STILL_EDGE = 1280
    }
}
