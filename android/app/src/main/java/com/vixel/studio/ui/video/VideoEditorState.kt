package com.vixel.studio.ui.video

import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.vixel.studio.core.model.AudioClip
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.Project

/**
 * Timeline editing state. The [Project] itself is immutable; every edit swaps
 * in a new one, which makes undo a matter of keeping the old references.
 */
@Stable
class VideoEditorState {

    var project: Project by mutableStateOf(Project())
        private set

    var selectedClipId: String? by mutableStateOf(null)

    /** Playhead, in microseconds from the start of the timeline. */
    var positionUs: Long by mutableStateOf(0L)

    var isPlaying: Boolean by mutableStateOf(false)

    var exporting: Boolean by mutableStateOf(false)

    var exportProgress: Float by mutableStateOf(0f)

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

    fun beginGesture() {
        if (gestureSnapshot == null) gestureSnapshot = project
    }

    fun endGesture() {
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

    fun addClips(clips: List<Clip>) {
        if (clips.isEmpty()) return
        commit { it.copy(clips = it.clips + clips) }
        if (selectedClipId == null) selectedClipId = clips.first().id
    }

    fun addAudio(audio: AudioClip) = commit { it.copy(audio = it.audio + audio) }

    fun removeSelected() {
        val id = selectedClipId ?: return
        commit { it.removeClip(id) }
        selectedClipId = project.clips.firstOrNull()?.id
    }

    fun duplicateSelected() {
        val id = selectedClipId ?: return
        commit { it.duplicateClip(id) }
    }

    fun splitAtPlayhead() = commit { it.splitAt(positionUs) }

    fun moveSelected(offset: Int) {
        val from = selectedIndex
        if (from < 0) return
        val to = (from + offset).coerceIn(0, project.clips.lastIndex)
        commit { it.moveClip(from, to) }
    }

    fun updateSelected(transform: (Clip) -> Clip) {
        val id = selectedClipId ?: return
        edit { it.updateClip(id, transform) }
    }

    fun commitSelected(transform: (Clip) -> Clip) {
        val id = selectedClipId ?: return
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
