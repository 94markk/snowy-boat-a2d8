package com.delicat.studio.engine.preview

import android.graphics.Bitmap
import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.CanvasRatio
import com.delicat.studio.model.FitMode
import com.delicat.studio.model.TransitionType

/**
 * Where a layer's pixels come from.
 *
 * Video arrives as an external image the player writes into, which only one
 * layer can be at a time because there is one player. Anything else is a
 * decoded still, identified by [key] so the GL thread can tell whether the
 * bitmap it uploaded last is the one it has been handed now.
 */
sealed interface LayerSource {
    data object Video : LayerSource
    data class Still(val key: String, val bitmap: Bitmap) : LayerSource
}

data class SceneLayer(
    val source: LayerSource,
    val adjustments: Adjustments,
    val fit: FitMode,
    val quarterTurns: Int,
    val sourceWidth: Int,
    val sourceHeight: Int,
)

/**
 * A complete description of one preview frame.
 *
 * Built on the main thread and read on the GL thread, so it is immutable and
 * published by replacing the whole value rather than by mutating one. That is
 * the entire synchronisation strategy: a renderer either sees the old scene or
 * the new one, never half of each.
 *
 * During a transition the outgoing clip is a still taken at the cut. One
 * player cannot decode two clips at once, and a second one costs a decoder
 * and its memory for half a second of preview. The exported file does run
 * both sides live — this is the preview accepting a frozen frame where the
 * file will have motion.
 */
data class Scene(
    val ratio: CanvasRatio = CanvasRatio.DEFAULT,
    val background: Int = 0xFF000000.toInt(),
    val back: SceneLayer? = null,
    val front: SceneLayer? = null,
    val transition: TransitionType = TransitionType.NONE,
    val progress: Float = 0f,
) {
    val isEmpty: Boolean get() = front == null && back == null
}
