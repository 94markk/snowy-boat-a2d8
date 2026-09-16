package com.vixel.studio.engine.overlay

import android.graphics.Bitmap
import android.opengl.GLES30
import android.opengl.Matrix
import com.vixel.studio.core.model.BlendMode
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.core.model.OverlayAnimation
import com.vixel.studio.engine.gl.GlUtils
import kotlin.math.max

/**
 * Draws text and sticker overlays on top of a rendered frame.
 *
 * Rasterised overlays are cached by content, so a caption costs one Canvas
 * pass for the whole clip rather than one per frame. Everything is positioned
 * in canvas pixels through an orthographic projection, which keeps placement
 * identical between the preview and the export.
 *
 * Must be used only from the thread owning the GL context.
 */
class OverlayCompositor {

    private var program = 0
    private var initialised = false

    private val textures = LinkedHashMap<String, CachedTexture>(8, 0.75f, true)

    private val mvp = FloatArray(16)
    private val projection = FloatArray(16)
    private val model = FloatArray(16)
    private val scratch = FloatArray(16)

    private class CachedTexture(val id: Int, val width: Int, val height: Int)

    fun init() {
        if (initialised) return
        program = GlUtils.linkProgram(VERTEX, FRAGMENT)
        initialised = true
    }

    /**
     * Composites every overlay active at [timeUs] onto the bound framebuffer.
     *
     * [canvasX]/[canvasY]/[canvasWidth]/[canvasHeight] describe the project
     * canvas within that framebuffer, so overlays stay inside the letterbox
     * rather than drifting onto the surround.
     */
    fun draw(
        overlays: List<Overlay>,
        timeUs: Long,
        canvasX: Int,
        canvasY: Int,
        canvasWidth: Int,
        canvasHeight: Int,
    ) {
        if (overlays.isEmpty() || canvasWidth <= 0 || canvasHeight <= 0) return
        val active = overlays.filter { it.isActiveAt(timeUs) }
        if (active.isEmpty()) return

        init()

        GLES30.glViewport(canvasX, canvasY, canvasWidth, canvasHeight)
        GLES30.glEnable(GLES30.GL_BLEND)
        GLES30.glUseProgram(program)

        // Pixel space with y up, matching the framebuffer's own origin.
        Matrix.orthoM(projection, 0, 0f, canvasWidth.toFloat(), 0f, canvasHeight.toFloat(), -1f, 1f)

        for (overlay in active) {
            applyBlend(overlay.blend)
            drawOne(overlay, timeUs, canvasWidth, canvasHeight)
        }

        // Leave the pipeline as we found it for whatever draws next.
        GLES30.glBlendEquation(GLES30.GL_FUNC_ADD)
        GLES30.glDisable(GLES30.GL_BLEND)
    }

    /**
     * Sets the fixed-function state for a blend mode.
     *
     * Bitmaps arrive premultiplied, so the source factor is ONE for the
     * ordinary case. Darken and lighten use a min/max equation rather than a
     * weighted sum, which is why the equation is reset each time.
     */
    private fun applyBlend(mode: BlendMode) {
        GLES30.glBlendEquation(GLES30.GL_FUNC_ADD)
        when (mode) {
            BlendMode.NORMAL ->
                GLES30.glBlendFunc(GLES30.GL_ONE, GLES30.GL_ONE_MINUS_SRC_ALPHA)
            BlendMode.MULTIPLY ->
                GLES30.glBlendFunc(GLES30.GL_DST_COLOR, GLES30.GL_ONE_MINUS_SRC_ALPHA)
            BlendMode.SCREEN ->
                GLES30.glBlendFunc(GLES30.GL_ONE, GLES30.GL_ONE_MINUS_SRC_COLOR)
            BlendMode.ADD ->
                GLES30.glBlendFunc(GLES30.GL_ONE, GLES30.GL_ONE)
            BlendMode.DARKEN -> {
                GLES30.glBlendEquation(GLES30.GL_MIN)
                GLES30.glBlendFunc(GLES30.GL_ONE, GLES30.GL_ONE)
            }
            BlendMode.LIGHTEN -> {
                GLES30.glBlendEquation(GLES30.GL_MAX)
                GLES30.glBlendFunc(GLES30.GL_ONE, GLES30.GL_ONE)
            }
        }
    }

    private fun drawOne(overlay: Overlay, timeUs: Long, canvasWidth: Int, canvasHeight: Int) {
        val cached = textureFor(overlay, canvasWidth, canvasHeight) ?: return

        val enter = animationFor(overlay.animationIn, overlay.enterProgress(timeUs))
        val exit = animationFor(overlay.animationOut, overlay.exitProgress(timeUs))

        // Keyframes override the static transform; the entry and exit
        // animations then multiply on top of whatever they produce.
        val animated = overlay.transformAt(timeUs)

        val opacity = (animated.opacity * enter.alpha * exit.alpha).coerceIn(0f, 1f)
        if (opacity <= 0.001f) return

        val scale = animated.scale * enter.scale * exit.scale
        val halfWidth = cached.width * scale / 2f
        val halfHeight = cached.height * scale / 2f
        if (halfWidth <= 0f || halfHeight <= 0f) return

        // transform.y is measured from the top; the projection is y-up.
        val centreX = (animated.x + enter.dx + exit.dx) * canvasWidth
        val centreY = (1f - (animated.y + enter.dy + exit.dy)) * canvasHeight

        Matrix.setIdentityM(model, 0)
        Matrix.translateM(model, 0, centreX, centreY, 0f)
        // Positive rotation reads as clockwise on screen, which is what the
        // control in the editor implies.
        Matrix.rotateM(model, 0, -animated.rotationDegrees, 0f, 0f, 1f)
        Matrix.scaleM(model, 0, halfWidth, halfHeight, 1f)

        Matrix.multiplyMM(mvp, 0, projection, 0, model, 0)

        GLES30.glUniformMatrix4fv(GLES30.glGetUniformLocation(program, "uMvp"), 1, false, mvp, 0)
        GLES30.glUniformMatrix4fv(
            GLES30.glGetUniformLocation(program, "uTexMatrix"), 1, false, FLIP_V, 0,
        )
        GLES30.glUniform1f(GLES30.glGetUniformLocation(program, "uOpacity"), opacity)

        GLES30.glActiveTexture(GLES30.GL_TEXTURE0)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, cached.id)
        GLES30.glUniform1i(GLES30.glGetUniformLocation(program, "uTexture"), 0)

        drawQuad()
    }

    private fun drawQuad() {
        val buffer = GlUtils.QUAD
        buffer.position(0)
        GLES30.glEnableVertexAttribArray(0)
        GLES30.glVertexAttribPointer(0, 2, GLES30.GL_FLOAT, false, STRIDE, buffer)
        buffer.position(2)
        GLES30.glEnableVertexAttribArray(1)
        GLES30.glVertexAttribPointer(1, 2, GLES30.GL_FLOAT, false, STRIDE, buffer)
        GLES30.glDrawArrays(GLES30.GL_TRIANGLE_STRIP, 0, 4)
        GLES30.glDisableVertexAttribArray(0)
        GLES30.glDisableVertexAttribArray(1)
        buffer.position(0)
    }

    private fun textureFor(overlay: Overlay, canvasWidth: Int, canvasHeight: Int): CachedTexture? {
        val key = OverlayBitmaps.cacheKey(overlay, canvasWidth, canvasHeight)
        textures[key]?.let { return it }

        val bitmap: Bitmap = OverlayBitmaps.render(overlay, canvasWidth, canvasHeight) ?: return null
        val entry = CachedTexture(
            id = GlUtils.createTextureFromBitmap(bitmap),
            width = bitmap.width,
            height = bitmap.height,
        )
        bitmap.recycle()

        textures[key] = entry
        while (textures.size > MAX_CACHED) {
            val oldest = textures.keys.firstOrNull() ?: break
            textures.remove(oldest)?.let { GlUtils.deleteTexture(it.id) }
        }
        return entry
    }

    /** Values an animation contributes at a given progress. */
    private class AnimationState(
        val alpha: Float = 1f,
        val scale: Float = 1f,
        val dx: Float = 0f,
        val dy: Float = 0f,
    )

    private fun animationFor(animation: OverlayAnimation, progress: Float): AnimationState {
        if (progress >= 1f || animation == OverlayAnimation.NONE) return AnimationState()
        val p = progress.coerceIn(0f, 1f)
        val eased = p * p * (3f - 2f * p)
        val remaining = 1f - eased

        return when (animation) {
            OverlayAnimation.NONE -> AnimationState()
            OverlayAnimation.FADE -> AnimationState(alpha = eased)
            OverlayAnimation.SLIDE_UP -> AnimationState(alpha = eased, dy = remaining * 0.25f)
            OverlayAnimation.SLIDE_DOWN -> AnimationState(alpha = eased, dy = -remaining * 0.25f)
            OverlayAnimation.SLIDE_LEFT -> AnimationState(alpha = eased, dx = remaining * 0.3f)
            OverlayAnimation.SLIDE_RIGHT -> AnimationState(alpha = eased, dx = -remaining * 0.3f)
            OverlayAnimation.ZOOM -> AnimationState(alpha = eased, scale = 0.6f + 0.4f * eased)
            OverlayAnimation.POP -> {
                // Overshoot past 1 and settle, which reads as a snap rather
                // than a plain grow.
                val overshoot = 1f + 0.35f * kotlin.math.sin(p * Math.PI.toFloat())
                AnimationState(alpha = eased, scale = max(0.01f, overshoot * (0.5f + 0.5f * eased)))
            }
        }
    }

    fun release() {
        textures.values.forEach { GlUtils.deleteTexture(it.id) }
        textures.clear()
        if (program != 0) GLES30.glDeleteProgram(program)
        program = 0
        initialised = false
    }

    companion object {
        /**
         * Time used when compositing onto a still. Past any entry animation,
         * so an overlay created with one is drawn fully formed rather than at
         * frame zero of its own fade.
         */
        const val STILL_TIME_US = 10_000_000L

        private const val STRIDE = 4 * 4
        private const val MAX_CACHED = 24

        /**
         * Bitmap row 0 is the top of the image but maps to t = 0, which the
         * quad places at the bottom. Flipping v here puts overlays the right
         * way up.
         */
        private val FLIP_V = floatArrayOf(
            1f, 0f, 0f, 0f,
            0f, -1f, 0f, 0f,
            0f, 0f, 1f, 0f,
            0f, 1f, 0f, 1f,
        )

        private const val VERTEX = """#version 300 es
layout(location = 0) in vec4 aPosition;
layout(location = 1) in vec2 aTexCoord;
uniform mat4 uMvp;
uniform mat4 uTexMatrix;
out vec2 vTex;
void main() {
    gl_Position = uMvp * aPosition;
    vTex = (uTexMatrix * vec4(aTexCoord, 0.0, 1.0)).xy;
}
"""

        private const val FRAGMENT = """#version 300 es
precision mediump float;
in vec2 vTex;
out vec4 fragColor;
uniform sampler2D uTexture;
uniform float uOpacity;
void main() {
    // Premultiplied: scaling the whole texel keeps colour and alpha in step.
    fragColor = texture(uTexture, vTex) * uOpacity;
}
"""
    }
}
