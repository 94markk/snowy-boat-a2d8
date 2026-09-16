package com.vixel.studio.ui.video

import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.vixel.studio.core.model.AspectRatio
import com.vixel.studio.core.model.AudioClip
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.Project
import com.vixel.studio.ui.common.OverlayHost

/**
 * Timeline editing state. The [Project] itself is immutable; every edit swaps
 * in a new one, which makes undo a matter of keeping the old references.
 */
@Stable
class VideoEditorState : OverlayHost {

    var project: Project by mutableStateOf(Project())
        private set

    var selectedClipId: String? by mutableStateOf(null)

    override var selectedOverlayId: String? by mutableStateOf(null)

    /** Playhead, in microseconds from the start of the timeline. */
    var positionUs: Long by mutableStateOf(0L)

    var isPlaying: Boolean by mutableStateOf(false)

    var exporting: Boolean by mutableStateOf(false)

    var exportProgress: Float by mutableStateOf(0f)

    /**
     * Export settings live here rather than inside the export panel so the top
     * bar can show the target the file will actually be written at. Kept in
     * the panel, they reset every time it closed and the header had nothing to
     * read.
     */
    var exportShortEdge: Int by mutableStateOf(1080)

    var exportFps: Int by mutableStateOf(30)

    var canUndo: Boolean by mutableStateOf(false)
        private set

    var canRedo: Boolean by mutableStateOf(false)
        private set

    private val undoStack = ArrayDeque<Project>()
    private val redoStack = ArrayDeque<Project>()
    private var gestureSnapshot: Project? = null

    val selectedClip: Clip?
        get() = project.clips.firstOrNull { it.id == selectedClipId }

    val selectedIndex: Int
        get() = project.clips.indexOfFirst { it.id == selectedClipId }

    /**
     * The clip the tools act on, and the clip the preview grades with.
     *
     * Falling back to whatever sits under the playhead is what makes the
     * controls work at all. Every edit used to be addressed to
     * [selectedClipId] and dropped when that was null, so a slider moved, the
     * preview stayed put, and the app looked like none of its parameters were
     * connected to anything.
     */
    val activeClip: Clip?
        get() = selectedClip ?: clipUnderPlayhead

    val activeClipId: String? get() = activeClip?.id

    val clipUnderPlayhead: Clip?
        get() = project.clips.getOrNull(project.clipIndexAt(positionUs))
            ?: project.clips.firstOrNull()

    /**
     * Selects [id] and brings the playhead into that clip.
     *
     * Editing a clip you cannot see is the same bug as editing nothing: the
     * change lands and still looks ignored. Moving the playhead keeps the
     * frame on screen and the clip being edited the same one.
     */
    fun selectClip(id: String?) {
        selectedClipId = id
        if (id == null) return
        selectedOverlayId = null
        val index = project.clips.indexOfFirst { it.id == id }
        if (index < 0) return
        val start = project.startOf(index)
        val end = start + project.clips[index].timelineDurationUs
        if (positionUs < start || positionUs >= end) {
            positionUs = start
        }
    }

    override val overlays: List<Overlay> get() = project.overlays

    override val supportsTiming: Boolean = true

    override val timelineDurationUs: Long get() = project.durationUs

    override val playheadUs: Long get() = positionUs

    /** Replaces the whole project, as when opening one from disk. */
    fun replaceProject(next: Project) {
        project = next
        undoStack.clear()
        redoStack.clear()
        gestureSnapshot = null
        selectedClipId = next.clips.firstOrNull()?.id
        selectedOverlayId = next.overlays.firstOrNull()?.id
        positionUs = 0L
        syncFlags()
    }

    fun rename(name: String) = edit { it.copy(name = name) }

    /** Clip ids whose media could not be opened when the project was loaded. */
    var missingMediaIds: Set<String> by mutableStateOf(emptySet())

    override fun beginGesture() {
        if (gestureSnapshot == null) gestureSnapshot = project
    }

    override fun endGesture() {
        val snapshot = gestureSnapshot ?: return
        gestureSnapshot = null
        if (snapshot != project) push(snapshot)
    }

    /** Live edit during a drag; no undo entry of its own. */
    fun edit(transform: (Project) -> Project) {
        project = transform(project)
    }

    /** Discrete edit; gets its own undo entry. */
    fun commit(transform: (Project) -> Project) {
        val previous = project
        val next = transform(previous)
        if (next == previous) return
        push(previous)
        project = next
    }

    /**
     * Appends [clips], and on the first import shapes the canvas to fit them.
     *
     * The canvas stays put on every later import, because by then it is a
     * choice the user has made — possibly deliberately, and possibly by
     * cropping everything to suit it. Only the empty-project case is a guess
     * worth making.
     */
    fun addClips(clips: List<Clip>) {
        if (clips.isEmpty()) return
        val first = clips.first()
        val wasEmpty = project.clips.isEmpty()

        commit { current ->
            val next = current.copy(clips = current.clips + clips)
            if (wasEmpty) {
                next.copy(aspect = AspectRatio.closestTo(first.displayWidth, first.displayHeight))
            } else {
                next
            }
        }

        if (selectedClipId == null) selectedClipId = first.id
    }

    fun addAudio(audio: AudioClip) = commit { it.copy(audio = it.audio + audio) }

    /** Adds an overlay and selects it, so the controls act on it immediately. */
    override fun addOverlay(overlay: Overlay) {
        commit { it.addOverlay(overlay) }
        selectedOverlayId = overlay.id
    }

    override fun removeSelectedOverlay() {
        val id = selectedOverlayId ?: return
        commit { it.removeOverlay(id) }
        selectedOverlayId = project.overlays.lastOrNull()?.id
    }

    /** Live overlay edit during a drag; no undo entry of its own. */
    override fun updateSelectedOverlay(transform: (Overlay) -> Overlay) {
        val id = selectedOverlayId ?: return
        edit { it.updateOverlay(id, transform) }
    }

    override fun commitSelectedOverlay(transform: (Overlay) -> Overlay) {
        val id = selectedOverlayId ?: return
        commit { it.updateOverlay(id, transform) }
    }

    fun removeSelected() {
        val id = activeClipId ?: return
        commit { it.removeClip(id) }
        selectedClipId = project.clips.firstOrNull()?.id
        positionUs = positionUs.coerceIn(0L, project.durationUs)
    }

    fun duplicateSelected() {
        val id = activeClipId ?: return
        commit { it.duplicateClip(id) }
    }

    fun splitAtPlayhead() = commit { it.splitAt(positionUs) }

    fun moveSelected(offset: Int) {
        val id = activeClipId ?: return
        val from = project.clips.indexOfFirst { it.id == id }
        if (from < 0) return
        val to = (from + offset).coerceIn(0, project.clips.lastIndex)
        commit { it.moveClip(from, to) }
    }

    fun updateSelected(transform: (Clip) -> Clip) {
        val id = activeClipId ?: return
        edit { it.updateClip(id, transform) }
    }

    fun commitSelected(transform: (Clip) -> Clip) {
        val id = activeClipId ?: return
        commit { it.updateClip(id, transform) }
    }

    fun undo() {
        val previous = undoStack.removeLastOrNull() ?: return
        redoStack.addLast(project)
        project = previous
        clampSelection()
        syncFlags()
    }

    fun redo() {
        val next = redoStack.removeLastOrNull() ?: return
        undoStack.addLast(project)
        project = next
        clampSelection()
        syncFlags()
    }

    private fun push(value: Project) {
        undoStack.addLast(value)
        while (undoStack.size > MAX_HISTORY) undoStack.removeFirst()
        redoStack.clear()
        syncFlags()
    }

    private fun clampSelection() {
        if (project.clips.none { it.id == selectedClipId }) {
            selectedClipId = project.clips.firstOrNull()?.id
        }
        if (project.overlays.none { it.id == selectedOverlayId }) {
            selectedOverlayId = project.overlays.firstOrNull()?.id
        }
        positionUs = positionUs.coerceIn(0L, project.durationUs)
    }

    private fun syncFlags() {
        canUndo = undoStack.isNotEmpty()
        canRedo = redoStack.isNotEmpty()
    }

    private companion object {
        const val MAX_HISTORY = 80
    }
}
