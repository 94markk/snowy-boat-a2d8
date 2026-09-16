package com.delicat.studio.engine.gl

import android.opengl.Matrix
import com.delicat.studio.model.FitMode

/**
 * Where a frame lands on the canvas, and which way up.
 *
 * Rotation is applied to the texture coordinates rather than to the quad.
 * Rotating the quad would mean doing the fit maths in a turned frame of
 * reference; rotating the sampling keeps the quad axis-aligned and lets the
 * fit be computed once, from the size the frame presents after turning.
 */
data class Placement(
    val canvasWidth: Int,
    val canvasHeight: Int,
    val sourceWidth: Int,
    val sourceHeight: Int,
    val fit: FitMode = FitMode.FIT,
    /** Ninety-degree steps applied clockwise, from the clip and its metadata. */
    val quarterTurns: Int = 0,
    val scale: Float = 1f,
    /** Offsets in half-canvases: 2.0 moves the frame exactly off screen. */
    val offsetX: Float = 0f,
    val offsetY: Float = 0f,
    /** True for a still uploaded with the first row at the top. */
    val flipVertically: Boolean = false,
) {
    private val turns: Int get() = ((quarterTurns % 4) + 4) % 4

    /** Source size as it reads after rotation. */
    val displayWidth: Int get() = if (turns % 2 == 0) sourceWidth else sourceHeight
    val displayHeight: Int get() = if (turns % 2 == 0) sourceHeight else sourceWidth

    fun modelViewProjection(out: FloatArray) {
        require(out.size >= 16) { "matrix needs 16 floats" }
        Matrix.setIdentityM(out, 0)

        val cw = canvasWidth.toFloat()
        val ch = canvasHeight.toFloat()
        val sw = displayWidth.toFloat()
        val sh = displayHeight.toFloat()
        if (cw <= 0f || ch <= 0f || sw <= 0f || sh <= 0f) return

        val canvasAspect = cw / ch
        val sourceAspect = sw / sh

        // Fit shrinks until both edges are inside; fill grows until neither
        // gap remains and lets the GPU clip whatever hangs over.
        val wider = sourceAspect > canvasAspect
        val inside = if (fit == FitMode.FIT) wider else !wider

        var sx = 1f
        var sy = 1f
        if (inside) sy = canvasAspect / sourceAspect else sx = sourceAspect / canvasAspect

        Matrix.translateM(out, 0, offsetX, offsetY, 0f)
        Matrix.scaleM(out, 0, sx * scale, sy * scale, 1f)
    }

    /**
     * Combines the source's own sampling transform with the user's rotation.
     *
     * [sourceMatrix] is what a SurfaceTexture reports: the crop and flip the
     * decoder wants applied. It has to come last, so the rotation is written
     * into [out] first and the source transform multiplied over it.
     */
    fun textureMatrix(sourceMatrix: FloatArray?, out: FloatArray, scratch: FloatArray) {
        require(out.size >= 16 && scratch.size >= 16) { "matrices need 16 floats" }

        Matrix.setIdentityM(scratch, 0)
        // Rotation is about the middle of the unit square, so a quarter turn
        // maps the square onto itself and no content leaves the frame.
        Matrix.translateM(scratch, 0, 0.5f, 0.5f, 0f)
        Matrix.rotateM(scratch, 0, -90f * turns, 0f, 0f, 1f)
        if (flipVertically) Matrix.scaleM(scratch, 0, 1f, -1f, 1f)
        Matrix.translateM(scratch, 0, -0.5f, -0.5f, 0f)

        if (sourceMatrix == null) {
            System.arraycopy(scratch, 0, out, 0, 16)
        } else {
            Matrix.multiplyMM(out, 0, sourceMatrix, 0, scratch, 0)
        }
    }
}
