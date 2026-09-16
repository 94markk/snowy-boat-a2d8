package com.vixel.studio.engine.gl

import android.graphics.Bitmap
import android.opengl.GLES11Ext
import android.opengl.GLES20
import android.opengl.GLES30
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.Filters
import kotlin.math.max
import kotlin.math.roundToInt

/**
 * Runs the colour stack as a small render graph:
 *
 *   source (2D or external) -> [copy] -> sharp
 *   sharp -> [blur H] -> [blur V] -> soft        (only when something needs it)
 *   sharp + soft -> [colour] -> target
 *
 * One instance owns its programs and render targets and must only be touched
 * from the thread that owns the GL context.
 */
class ColorGrader {

    private var copy2dProgram = 0
    private var copyExtProgram = 0
    private var blurProgram = 0
    private var colorProgram = 0

    private val sharp = GlFramebuffer()
    private val blurA = GlFramebuffer()
    private val blurB = GlFramebuffer()

    private var curveTexture = 0
    private var curveKey: Int? = null
    private var lutTexture = 0
    private var lutKey: String? = null

    private var initialised = false

    fun init() {
        if (initialised) return
        copy2dProgram = GlUtils.linkProgram(Shaders.VERTEX, Shaders.copyFragment(external = false))
        copyExtProgram = GlUtils.linkProgram(Shaders.VERTEX, Shaders.copyFragment(external = true))
        blurProgram = GlUtils.linkProgram(Shaders.VERTEX, Shaders.BLUR_FRAGMENT)
        colorProgram = GlUtils.linkProgram(Shaders.VERTEX, Shaders.colorFragment(external = false))
        initialised = true
    }

    /**
     * Grades [sourceTexture] into whatever framebuffer is currently bound.
     *
     * @param texMatrix 4x4 transform for the source coordinates. SurfaceTexture
     *   hands one of these back for video; pass [IDENTITY] for bitmaps.
     * @param targetWidth/targetHeight viewport of the bound framebuffer.
     */
    fun render(
        sourceTexture: Int,
        isExternal: Boolean,
        texMatrix: FloatArray,
        sourceWidth: Int,
        sourceHeight: Int,
        targetWidth: Int,
        targetHeight: Int,
        adjustments: Adjustments,
        targetX: Int = 0,
        targetY: Int = 0,
        opacity: Float = 1f,
        seed: Float = 0f,
    ) {
        init()
        if (sourceWidth <= 0 || sourceHeight <= 0) return

        // Pass 1 - normalise the source into a plain 2D texture. Doing this
        // unconditionally means every later pass has identical sampling rules
        // whether the frame came from a bitmap or a video decoder.
        sharp.ensure(sourceWidth, sourceHeight)
        sharp.use {
            val program = if (isExternal) copyExtProgram else copy2dProgram
            GLES30.glUseProgram(program)
            bindTexture(program, "uTexture", 0, sourceTexture, isExternal)
            setMatrix(program, texMatrix)
            drawQuad(program)
        }

        // Pass 2 - blurred copy, at half resolution. Only built when a
        // parameter actually reads it.
        val needsBlur = adjustments.blur > 0f || adjustments.glow > 0f || adjustments.sharpen > 0f
        var softTexture = 0
        if (needsBlur) {
            val scale = 2
            val bw = max(1, sourceWidth / scale)
            val bh = max(1, sourceHeight / scale)
            blurA.ensure(bw, bh)
            blurB.ensure(bw, bh)

            // Sharpen wants a tight radius; blur/glow want a wide one.
            val radius = if (adjustments.blur > 0f || adjustments.glow > 0f) {
                1f + adjustments.blur * 18f + adjustments.glow * 6f
            } else {
                1f
            }

            GLES30.glUseProgram(blurProgram)
            blurA.use {
                bindTexture(blurProgram, "uTexture", 0, sharp.textureId, false)
                setMatrix(blurProgram, IDENTITY)
                GLES30.glUniform2f(
                    GLES30.glGetUniformLocation(blurProgram, "uDirection"),
                    radius / bw, 0f,
                )
                drawQuad(blurProgram)
            }
            blurB.use {
                bindTexture(blurProgram, "uTexture", 0, blurA.textureId, false)
                setMatrix(blurProgram, IDENTITY)
                GLES30.glUniform2f(
                    GLES30.glGetUniformLocation(blurProgram, "uDirection"),
                    0f, radius / bh,
                )
                drawQuad(blurProgram)
            }
            softTexture = blurB.textureId
        }

        // Pass 3 - the colour stack, straight into the caller's target.
        GLES20.glViewport(targetX, targetY, targetWidth, targetHeight)
        GLES30.glUseProgram(colorProgram)
        bindTexture(colorProgram, "uTexture", 0, sharp.textureId, false)
        bindTexture(colorProgram, "uBlurred", 1, if (needsBlur) softTexture else sharp.textureId, false)
        setMatrix(colorProgram, IDENTITY)
        bindAdjustments(
            program = colorProgram,
            adjustments = adjustments,
            sourceWidth = sourceWidth,
            sourceHeight = sourceHeight,
            hasBlur = needsBlur,
            opacity = opacity,
            seed = seed,
        )
        drawQuad(colorProgram)
    }

    private fun bindAdjustments(
        program: Int,
        adjustments: Adjustments,
        sourceWidth: Int,
        sourceHeight: Int,
        hasBlur: Boolean,
        opacity: Float,
        seed: Float,
    ) {
        fun f(name: String, value: Float) =
            GLES30.glUniform1f(GLES30.glGetUniformLocation(program, name), value)

        f("uExposure", adjustments.exposure)
        f("uBrightness", adjustments.brightness)
        f("uContrast", adjustments.contrast)
        f("uHighlights", adjustments.highlights)
        f("uShadows", adjustments.shadows)
        f("uWhites", adjustments.whites)
        f("uBlacks", adjustments.blacks)
        f("uSaturation", adjustments.saturation)
        f("uVibrance", adjustments.vibrance)
        f("uTemperature", adjustments.temperature)
        f("uTint", adjustments.tint)
        f("uHueShift", adjustments.hueShift)
        f("uSharpen", adjustments.sharpen)
        f("uBlur", adjustments.blur)
        f("uFade", adjustments.fade)
        f("uVignette", adjustments.vignette)
        f("uGrain", adjustments.grain)
        f("uGlow", adjustments.glow)
        f("uOpacity", opacity)
        f("uSeed", seed)
        f("uHasBlur", if (hasBlur) 1f else 0f)

        GLES30.glUniform2f(
            GLES30.glGetUniformLocation(program, "uTexelSize"),
            1f / sourceWidth, 1f / sourceHeight,
        )

        GLES30.glUniform1fv(
            GLES30.glGetUniformLocation(program, "uHslHue"), 8, adjustments.hslHue, 0,
        )
        GLES30.glUniform1fv(
            GLES30.glGetUniformLocation(program, "uHslSat"), 8, adjustments.hslSat, 0,
        )
        GLES30.glUniform1fv(
            GLES30.glGetUniformLocation(program, "uHslLum"), 8, adjustments.hslLum, 0,
        )

        // Tone curve
        val hasCurve = !CurveLut.isIdentity(
            adjustments.curveMaster, adjustments.curveRed,
            adjustments.curveGreen, adjustments.curveBlue,
        )
        if (hasCurve) {
            val key = listOf(
                adjustments.curveMaster, adjustments.curveRed,
                adjustments.curveGreen, adjustments.curveBlue,
            ).hashCode()
            if (curveKey != key || curveTexture == 0) {
                val bitmap = CurveLut.build(
                    adjustments.curveMaster, adjustments.curveRed,
                    adjustments.curveGreen, adjustments.curveBlue,
                )
                if (curveTexture == 0) curveTexture = GlUtils.createTexture()
                GlUtils.uploadBitmap(curveTexture, bitmap)
                bitmap.recycle()
                curveKey = key
            }
            bindTexture(program, "uCurve", 2, curveTexture, false)
        }
        f("uHasCurve", if (hasCurve) 1f else 0f)

        // Look / LUT
        val preset = Filters.get(adjustments.filterId)
        val hasLut = preset.id != Filters.NONE_ID && adjustments.filterStrength > 0f
        if (hasLut) {
            if (lutKey != preset.id || lutTexture == 0) {
                val bitmap = LutGenerator.lutFor(preset)
                if (bitmap != null) {
                    if (lutTexture == 0) lutTexture = GlUtils.createTexture()
                    GlUtils.uploadBitmap(lutTexture, bitmap)
                    lutKey = preset.id
                }
            }
            bindTexture(program, "uLut", 3, lutTexture, false)
        }
        f("uHasLut", if (hasLut && lutTexture != 0) 1f else 0f)
        f("uLutStrength", adjustments.filterStrength)
    }

    private fun bindTexture(program: Int, name: String, unit: Int, texture: Int, external: Boolean) {
        val target = if (external) GLES11Ext.GL_TEXTURE_EXTERNAL_OES else GLES30.GL_TEXTURE_2D
        GLES30.glActiveTexture(GLES30.GL_TEXTURE0 + unit)
        GLES30.glBindTexture(target, texture)
        GLES30.glUniform1i(GLES30.glGetUniformLocation(program, name), unit)
    }

    private fun setMatrix(program: Int, matrix: FloatArray) {
        GLES30.glUniformMatrix4fv(
            GLES30.glGetUniformLocation(program, "uTexMatrix"), 1, false, matrix, 0,
        )
    }

    private fun drawQuad(@Suppress("UNUSED_PARAMETER") program: Int) {
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

    /** Reads the currently bound framebuffer back into a bitmap. */
    fun readPixels(width: Int, height: Int): Bitmap {
        val buffer = java.nio.ByteBuffer.allocateDirect(width * height * 4)
            .order(java.nio.ByteOrder.nativeOrder())
        GLES30.glReadPixels(
            0, 0, width, height, GLES30.GL_RGBA, GLES30.GL_UNSIGNED_BYTE, buffer,
        )
        buffer.rewind()
        val bitmap = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        bitmap.copyPixelsFromBuffer(buffer)
        // GL's origin is bottom-left; Bitmap's is top-left.
        return flipVertically(bitmap)
    }

    private fun flipVertically(source: Bitmap): Bitmap {
        val matrix = android.graphics.Matrix().apply { preScale(1f, -1f) }
        val flipped = Bitmap.createBitmap(
            source, 0, 0, source.width, source.height, matrix, false,
        )
        if (flipped !== source) source.recycle()
        return flipped
    }

    fun release() {
        if (copy2dProgram != 0) GLES30.glDeleteProgram(copy2dProgram)
        if (copyExtProgram != 0) GLES30.glDeleteProgram(copyExtProgram)
        if (blurProgram != 0) GLES30.glDeleteProgram(blurProgram)
        if (colorProgram != 0) GLES30.glDeleteProgram(colorProgram)
        copy2dProgram = 0; copyExtProgram = 0; blurProgram = 0; colorProgram = 0
        sharp.release(); blurA.release(); blurB.release()
        GlUtils.deleteTexture(curveTexture); curveTexture = 0; curveKey = null
        GlUtils.deleteTexture(lutTexture); lutTexture = 0; lutKey = null
        initialised = false
    }

    companion object {
        private const val STRIDE = 4 * 4

        val IDENTITY = floatArrayOf(
            1f, 0f, 0f, 0f,
            0f, 1f, 0f, 0f,
            0f, 0f, 1f, 0f,
            0f, 0f, 0f, 1f,
        )

        /** Fits [sourceW]x[sourceH] inside [maxEdge] without changing aspect. */
        fun fit(sourceW: Int, sourceH: Int, maxEdge: Int): Pair<Int, Int> {
            if (sourceW <= maxEdge && sourceH <= maxEdge) return sourceW to sourceH
            val scale = maxEdge.toFloat() / max(sourceW, sourceH)
            return max(1, (sourceW * scale).roundToInt()) to max(1, (sourceH * scale).roundToInt())
        }
    }
}
