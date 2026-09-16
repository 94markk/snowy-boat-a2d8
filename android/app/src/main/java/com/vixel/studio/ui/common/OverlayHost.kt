package com.vixel.studio.ui.common

import com.vixel.studio.core.model.Overlay

/**
 * What the text and sticker panels need from whatever is hosting overlays.
 *
 * The video editor and the photo editor keep very different state, but the
 * overlay controls are identical, so they talk to this instead of to either
 * one directly.
 */
interface OverlayHost {

    val overlays: List<Overlay>

    var selectedOverlayId: String?

    /**
     * False for a still, where an overlay is simply always visible. The timing
     * controls hide themselves rather than offering a start and end that would
     * mean nothing.
     */
    val supportsTiming: Boolean

    /** Timeline length; 0 when [supportsTiming] is false. */
    val timelineDurationUs: Long

    /** Where the playhead is, used to place a newly added overlay. */
    val playheadUs: Long

    fun addOverlay(overlay: Overlay)

    fun removeSelectedOverlay()

    /** Live edit during a drag; no undo entry of its own. */
    fun updateSelectedOverlay(transform: (Overlay) -> Overlay)

    /** Discrete edit; gets its own undo entry. */
    fun commitSelectedOverlay(transform: (Overlay) -> Overlay)

    fun beginGesture()

    fun endGesture()

    val selectedOverlay: Overlay?
        get() = overlays.firstOrNull { it.id == selectedOverlayId }
}
