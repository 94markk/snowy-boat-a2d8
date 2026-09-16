package com.delicat.studio.engine.gl

import com.delicat.studio.model.TransitionType

/** Offset and scale applied to one side of a transition. */
data class LayerMotion(
    val offsetX: Float = 0f,
    val offsetY: Float = 0f,
    val scale: Float = 1f,
)

data class LayerStyle(
    val motion: LayerMotion = LayerMotion(),
    val paint: LayerPaint = LayerPaint.OPAQUE,
    val visible: Boolean = true,
)

data class TransitionStyles(
    val outgoing: LayerStyle,
    val incoming: LayerStyle,
)

/**
 * Turns a transition and its progress into drawing parameters for the two
 * clips involved.
 *
 * Nothing here knows about OpenGL. A transition is a statement about where
 * two frames sit, how opaque they are and what is laid over them, and keeping
 * it in those terms is what lets the preview and the exporter share it
 * without sharing a render target.
 */
object Transitions {

    private const val WIPE_SOFTNESS = 0.012f

    fun styles(type: TransitionType, rawProgress: Float): TransitionStyles {
        val p = rawProgress.coerceIn(0f, 1f)

        return when (type) {
            TransitionType.NONE -> TransitionStyles(
                outgoing = LayerStyle(visible = false),
                incoming = LayerStyle(),
            )

            TransitionType.DISSOLVE -> TransitionStyles(
                outgoing = LayerStyle(),
                incoming = LayerStyle(paint = LayerPaint(alpha = p)),
            )

            TransitionType.FADE_BLACK -> throughColour(p, 0f, 0f, 0f)
            TransitionType.FADE_WHITE -> throughColour(p, 1f, 1f, 1f)

            // Both sides travel together, so the pair reads as one strip of
            // film being pulled across rather than as two images crossing.
            TransitionType.SLIDE_LEFT -> slide(-2f * p, 0f, 2f * (1f - p), 0f)
            TransitionType.SLIDE_RIGHT -> slide(2f * p, 0f, -2f * (1f - p), 0f)
            TransitionType.SLIDE_UP -> slide(0f, 2f * p, 0f, -2f * (1f - p))
            TransitionType.SLIDE_DOWN -> slide(0f, -2f * p, 0f, 2f * (1f - p))

            // The outgoing clip stays put and the incoming one is revealed
            // over it, which is what distinguishes a wipe from a slide.
            TransitionType.WIPE_LEFT -> wipe(1f, 0f, ramp(1f, -1f, p))
            TransitionType.WIPE_RIGHT -> wipe(-1f, 0f, ramp(0f, -1f, p))

            TransitionType.ZOOM_IN -> TransitionStyles(
                outgoing = LayerStyle(motion = LayerMotion(scale = 1f + 0.25f * p)),
                incoming = LayerStyle(
                    motion = LayerMotion(scale = 1.55f - 0.55f * p),
                    paint = LayerPaint(alpha = p),
                ),
            )

            TransitionType.ZOOM_OUT -> TransitionStyles(
                outgoing = LayerStyle(motion = LayerMotion(scale = 1f - 0.22f * p)),
                incoming = LayerStyle(
                    motion = LayerMotion(scale = 0.62f + 0.38f * p),
                    paint = LayerPaint(alpha = p),
                ),
            )
        }
    }

    /**
     * Out to a colour and back again.
     *
     * The two ramps are deliberately offset: the outgoing clip is fully
     * covered at the halfway point before the incoming one starts to appear,
     * so the flat frame in the middle is a real beat rather than a moment
     * where both images are faintly visible through the wash.
     */
    private fun throughColour(p: Float, r: Float, g: Float, b: Float): TransitionStyles {
        val out = (p * 2f).coerceIn(0f, 1f)
        val back = (2f - p * 2f).coerceIn(0f, 1f)
        return TransitionStyles(
            outgoing = LayerStyle(
                paint = LayerPaint(
                    overlayRed = r, overlayGreen = g, overlayBlue = b, overlayAmount = out,
                ),
            ),
            incoming = LayerStyle(
                paint = LayerPaint(
                    alpha = (p * 2f - 1f).coerceIn(0f, 1f),
                    overlayRed = r, overlayGreen = g, overlayBlue = b, overlayAmount = back,
                ),
            ),
        )
    }

    private fun slide(ox: Float, oy: Float, ix: Float, iy: Float) = TransitionStyles(
        outgoing = LayerStyle(motion = LayerMotion(offsetX = ox, offsetY = oy)),
        incoming = LayerStyle(motion = LayerMotion(offsetX = ix, offsetY = iy)),
    )

    /**
     * Where the edge sits, with the soft band's width added at both ends.
     *
     * Without the overshoot a finished wipe leaves the far edge of the canvas
     * half covered, because the smoothstep is only halfway through its ramp
     * where the edge lands exactly on the boundary.
     */
    private fun ramp(from: Float, to: Float, p: Float): Float {
        val start = from + WIPE_SOFTNESS
        val end = to - WIPE_SOFTNESS
        return start + (end - start) * p
    }

    /**
     * The reveal term the fragment shader computes, in the same terms.
     *
     * Kept here so the ramps can be checked against what the GPU will do
     * without needing a GPU: a wipe that stops a hair short of the edge is
     * invisible in review and obvious in the exported file.
     */
    fun reveal(paint: LayerPaint, screenX: Float, screenY: Float): Float {
        if (paint.wipeSoftness < 0f) return 1f
        val along = screenX * paint.wipeDirX + screenY * paint.wipeDirY
        val t = ((along - (paint.wipeEdge - paint.wipeSoftness)) /
            (2f * paint.wipeSoftness)).coerceIn(0f, 1f)
        return t * t * (3f - 2f * t)
    }

    private fun wipe(dirX: Float, dirY: Float, edge: Float) = TransitionStyles(
        outgoing = LayerStyle(),
        incoming = LayerStyle(
            paint = LayerPaint(
                wipeDirX = dirX,
                wipeDirY = dirY,
                wipeEdge = edge,
                wipeSoftness = WIPE_SOFTNESS,
            ),
        ),
    )
}
