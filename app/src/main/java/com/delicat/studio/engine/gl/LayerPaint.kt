package com.delicat.studio.engine.gl

/**
 * How one layer is mixed into the canvas underneath it.
 *
 * Every transition the app offers is expressible as two draws with different
 * values here, which is why there is no transition shader and no intermediate
 * buffer: dissolve is an alpha ramp, a fade through black is an overlay ramp
 * on each side, a slide is an offset in [Placement], and a wipe is the edge
 * below. Adding a transition means adding parameters, not a render pass.
 */
data class LayerPaint(
    val alpha: Float = 1f,
    /** Colour blended over the graded frame, and how much of it. */
    val overlayRed: Float = 0f,
    val overlayGreen: Float = 0f,
    val overlayBlue: Float = 0f,
    val overlayAmount: Float = 0f,
    /** Direction the wipe travels across the canvas, in 0..1 screen space. */
    val wipeDirX: Float = 0f,
    val wipeDirY: Float = 0f,
    val wipeEdge: Float = 0f,
    /** Negative disables the wipe entirely; the shader tests for it. */
    val wipeSoftness: Float = -1f,
) {
    companion object {
        val OPAQUE = LayerPaint()
    }
}
