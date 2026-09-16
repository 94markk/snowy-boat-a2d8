package com.delicat.studio.engine

import com.delicat.studio.engine.export.ExportState
import com.delicat.studio.model.Clip
import com.delicat.studio.model.Project

/** The panels the tool rail can open. */
enum class Tool(val label: String) {
    EDIT("Edit"),
    ADJUST("Adjust"),
    LOOKS("Looks"),
    SPEED("Speed"),
    VOLUME("Volume"),
    TRANSITION("Transition"),
    CANVAS("Canvas"),
}

data class EditorState(
    val project: Project = Project(),
    val selectedClipId: String? = null,
    val positionUs: Long = 0L,
    val isPlaying: Boolean = false,
    val tool: Tool? = null,
    /** Timeline zoom. Sixty pixels a second shows a clip without a magnifier. */
    val pixelsPerSecond: Float = 60f,
    val isImporting: Boolean = false,
    val export: ExportState = ExportState.Idle,
    val notice: String? = null,
    val canUndo: Boolean = false,
    val canRedo: Boolean = false,
) {
    /**
     * The clip an edit applies to.
     *
     * Selection first, then whatever is under the playhead. Requiring an
     * explicit selection is what made every slider in the previous version of
     * this app appear to do nothing: the user would open Adjust with a clip
     * plainly visible in the preview, drag, and the edit would go nowhere
     * because no clip had been tapped.
     */
    val activeClip: Clip?
        get() = project.clips.firstOrNull { it.id == selectedClipId }
            ?: project.clips.getOrNull(project.clipIndexAt(positionUs))
            ?: project.clips.lastOrNull()

    val activeIndex: Int
        get() = activeClip?.let { clip -> project.clips.indexOfFirst { it.id == clip.id } } ?: -1

    val durationUs: Long get() = project.durationUs

    val isEmpty: Boolean get() = project.isEmpty
}
