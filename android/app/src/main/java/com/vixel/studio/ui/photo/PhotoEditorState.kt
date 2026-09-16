package com.vixel.studio.ui.photo

import android.graphics.Bitmap
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.vixel.studio.core.model.Adjustments

/**
 * Editor state plus undo history.
 *
 * Undo is grouped per gesture: dragging a slider from 0 to 60 is one step, not
 * sixty. [beginGesture] snapshots on the first change of a drag and
 * [endGesture] commits it when the finger lifts.
 */
@Stable
class PhotoEditorState {

    var source: Bitmap? by mutableStateOf(null)
        private set

    var adjustments: Adjustments by mutableStateOf(Adjustments())
        private set

    /** True while the user holds the compare button; preview shows the original. */
    var showOriginal: Boolean by mutableStateOf(false)

    var busy: Boolean by mutableStateOf(false)

    var canUndo: Boolean by mutableStateOf(false)
        private set

    var canRedo: Boolean by mutableStateOf(false)
        private set

    private val undoStack = ArrayDeque<Adjustments>()
    private val redoStack = ArrayDeque<Adjustments>()
    private var gestureSnapshot: Adjustments? = null

    /** Adjustments the preview should actually draw right now. */
    val effective: Adjustments
        get() = if (showOriginal) NEUTRAL.copy(filterStrength = 0f) else adjustments

    fun setSource(bitmap: Bitmap?) {
        source?.takeIf { it !== bitmap && !it.isRecycled }?.recycle()
        source = bitmap
        adjustments = Adjustments()
        undoStack.clear()
        redoStack.clear()
        syncFlags()
    }

    /** Replaces the bitmap without touching the colour edits (rotate, flip, crop). */
    fun replaceSource(bitmap: Bitmap) {
        val previous = source
        source = bitmap
        if (previous != null && previous !== bitmap && !previous.isRecycled) previous.recycle()
    }

    fun beginGesture() {
        if (gestureSnapshot == null) gestureSnapshot = adjustments
    }

    fun endGesture() {
        val snapshot = gestureSnapshot ?: return
        gestureSnapshot = null
        if (snapshot != adjustments) pushUndo(snapshot)
    }

    /** Live update during a drag — no undo entry of its own. */
    fun update(transform: (Adjustments) -> Adjustments) {
        adjustments = transform(adjustments)
    }

    /** A discrete change (tapping a filter, resetting) — its own undo entry. */
    fun commit(transform: (Adjustments) -> Adjustments) {
        val previous = adjustments
        val next = transform(previous)
        if (next == previous) return
        pushUndo(previous)
        adjustments = next
    }

    fun undo() {
        val previous = undoStack.removeLastOrNull() ?: return
        redoStack.addLast(adjustments)
        adjustments = previous
        syncFlags()
    }

    fun redo() {
        val next = redoStack.removeLastOrNull() ?: return
        undoStack.addLast(adjustments)
        adjustments = next
        syncFlags()
    }

    private fun pushUndo(value: Adjustments) {
        undoStack.addLast(value)
        while (undoStack.size > MAX_HISTORY) undoStack.removeFirst()
        redoStack.clear()
        syncFlags()
    }

    private fun syncFlags() {
        canUndo = undoStack.isNotEmpty()
        canRedo = redoStack.isNotEmpty()
    }

    private companion object {
        const val MAX_HISTORY = 60
        val NEUTRAL = Adjustments()
    }
}
