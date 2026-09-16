package com.delicat.studio.engine.gl

import android.opengl.GLES20
import com.delicat.studio.engine.LutBaker
import com.delicat.studio.model.Adjustments

/**
 * Draws graded frames. Everything the app puts on screen or into a file goes
 * through here.
 *
 * All methods must be called on a thread with a current GL context, and
 * [setup] must have run on that same context. The class holds raw GL names,
 * which belong to the context that made them; using one from elsewhere is
 * undefined behaviour rather than an error the driver reports.
 */
class FrameRenderer {

    private class Program(source: String) {
        val id: Int = GlUtil.link(Shaders.VERTEX, source)

        val position = GLES20.glGetAttribLocation(id, "aPosition")
        val texCoord = GLES20.glGetAttribLocation(id, "aTexCoord")

        val mvp = uniform("uMvp")
        val texMatrix = uniform("uTexMatrix")
        val source = uniform("uSource")
        val lut = uniform("uLut")
        val texel = uniform("uTexel")
        val lutSize = uniform("uLutSize")
        val lutTiles = uniform("uLutTiles")
        val lutRows = uniform("uLutRows")
        val lutMix = uniform("uLutMix")
        val blur = uniform("uBlur")
        val sharpen = uniform("uSharpen")
        val glow = uniform("uGlow")
        val grain = uniform("uGrain")
        val vignette = uniform("uVignette")
        val seed = uniform("uSeed")
        val alpha = uniform("uAlpha")
        val overlay = uniform("uOverlay")
        val wipe = uniform("uWipe")

        private fun uniform(name: String) = GLES20.glGetUniformLocation(id, name)

        fun release() = GlUtil.deleteProgram(id)
    }

    /**
     * One baked colour table on the GPU, remembered by the adjustments that
     * produced it.
     *
     * Two are kept because a transition draws two clips with different grades
     * in the same frame; re-baking between them would put thirty thousand
     * evaluations between one draw call and the next.
     */
    private class Lut {
        val texture: Int = GlUtil.createTexture(GlUtil.FLAT_TEXTURE)
        var key: Adjustments? = null
        var lastUsed: Long = 0L

        fun release() = GlUtil.deleteTexture(texture)
    }

    private var external: Program? = null
    private var flat: Program? = null
    private val luts = mutableListOf<Lut>()

    private val mvp = FloatArray(16)
    private val texMatrix = FloatArray(16)
    private val scratch = FloatArray(16)
    private var clock = 0L

    var isReady: Boolean = false
        private set

    /** Non-null when setup failed, so the UI can say why instead of going black. */
    var failure: String? = null
        private set

    fun setup() {
        if (isReady) return
        try {
            external = Program(Shaders.FRAGMENT_EXTERNAL)
            flat = Program(Shaders.FRAGMENT_FLAT)
            repeat(2) { luts += Lut() }
            GLES20.glDisable(GLES20.GL_DEPTH_TEST)
            GLES20.glDisable(GLES20.GL_CULL_FACE)
            GlUtil.checkGl("setup")
            isReady = true
            failure = null
        } catch (e: Throwable) {
            failure = e.message ?: e.javaClass.simpleName
            release()
        }
    }

    fun release() {
        external?.release()
        flat?.release()
        external = null
        flat = null
        luts.forEach { it.release() }
        luts.clear()
        isReady = false
    }

    /** Clears the canvas to [argb] and points the viewport at it. */
    fun beginFrame(width: Int, height: Int, argb: Int) {
        GLES20.glViewport(0, 0, width, height)
        GLES20.glClearColor(
            ((argb shr 16) and 0xFF) / 255f,
            ((argb shr 8) and 0xFF) / 255f,
            (argb and 0xFF) / 255f,
            ((argb ushr 24) and 0xFF) / 255f,
        )
        GLES20.glClear(GLES20.GL_COLOR_BUFFER_BIT)
        GLES20.glEnable(GLES20.GL_BLEND)
        GLES20.glBlendFunc(GLES20.GL_SRC_ALPHA, GLES20.GL_ONE_MINUS_SRC_ALPHA)
    }

    /**
     * Draws one graded layer.
     *
     * [sourceMatrix] is the transform a SurfaceTexture reports for video, and
     * null for a still. [sourceWidth] and [sourceHeight] are the texture's own
     * pixel size, needed because the neighbourhood taps in the shader are
     * measured in texels and a blur radius that ignored resolution would be
     * twice as strong on a 720p clip as on a 1440p one.
     */
    fun drawLayer(
        texture: Int,
        isExternal: Boolean,
        sourceMatrix: FloatArray?,
        sourceWidth: Int,
        sourceHeight: Int,
        adjustments: Adjustments,
        placement: Placement,
        paint: LayerPaint,
    ) {
        val program = (if (isExternal) external else flat) ?: return
        if (texture == 0 || paint.alpha <= 0f) return

        GLES20.glUseProgram(program.id)

        placement.modelViewProjection(mvp)
        placement.textureMatrix(sourceMatrix, texMatrix, scratch)
        GLES20.glUniformMatrix4fv(program.mvp, 1, false, mvp, 0)
        GLES20.glUniformMatrix4fv(program.texMatrix, 1, false, texMatrix, 0)

        val target = if (isExternal) GlUtil.EXTERNAL_TEXTURE else GlUtil.FLAT_TEXTURE
        GLES20.glActiveTexture(GLES20.GL_TEXTURE0)
        GLES20.glBindTexture(target, texture)
        GLES20.glUniform1i(program.source, 0)

        GLES20.glActiveTexture(GLES20.GL_TEXTURE1)
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, lutFor(adjustments))
        GLES20.glUniform1i(program.lut, 1)

        val w = if (sourceWidth > 0) sourceWidth.toFloat() else 1920f
        val h = if (sourceHeight > 0) sourceHeight.toFloat() else 1080f
        GLES20.glUniform2f(program.texel, 1f / w, 1f / h)

        GLES20.glUniform1f(program.lutSize, LutBaker.SIZE.toFloat())
        GLES20.glUniform1f(program.lutTiles, LutBaker.TILES_ACROSS.toFloat())
        GLES20.glUniform1f(
            program.lutRows,
            (LutBaker.stripHeight / LutBaker.SIZE).toFloat(),
        )
        // Skipping the table when nothing colour-related is set saves two
        // dependent texture reads on every pixel of every frame.
        GLES20.glUniform1f(program.lutMix, if (adjustments.hasColourWork) 1f else 0f)

        GLES20.glUniform1f(program.blur, adjustments.blur)
        GLES20.glUniform1f(program.sharpen, adjustments.sharpen)
        GLES20.glUniform1f(program.glow, adjustments.glow)
        GLES20.glUniform1f(program.grain, adjustments.grain)
        GLES20.glUniform1f(program.vignette, adjustments.vignette)
        GLES20.glUniform1f(program.seed, (clock % 997L).toFloat() * 0.61803f)

        GLES20.glUniform1f(program.alpha, paint.alpha.coerceIn(0f, 1f))
        GLES20.glUniform4f(
            program.overlay,
            paint.overlayRed, paint.overlayGreen, paint.overlayBlue, paint.overlayAmount,
        )
        GLES20.glUniform4f(
            program.wipe,
            paint.wipeDirX, paint.wipeDirY, paint.wipeEdge, paint.wipeSoftness,
        )

        GlUtil.QUAD.position(0)
        GLES20.glVertexAttribPointer(program.position, 2, GLES20.GL_FLOAT, false, 16, GlUtil.QUAD)
        GLES20.glEnableVertexAttribArray(program.position)

        GlUtil.QUAD.position(2)
        GLES20.glVertexAttribPointer(program.texCoord, 2, GLES20.GL_FLOAT, false, 16, GlUtil.QUAD)
        GLES20.glEnableVertexAttribArray(program.texCoord)

        GLES20.glDrawArrays(GLES20.GL_TRIANGLE_STRIP, 0, 4)

        GLES20.glDisableVertexAttribArray(program.position)
        GLES20.glDisableVertexAttribArray(program.texCoord)
        GLES20.glBindTexture(target, 0)
    }

    /** Advances the grain so it moves between frames instead of sitting still. */
    fun tick() {
        clock++
    }

    private fun lutFor(adjustments: Adjustments): Int {
        if (luts.isEmpty()) return 0
        // Only the operations the table actually encodes take part in the
        // comparison, so moving the grain slider does not force a re-bake.
        val key = adjustments.copy(
            sharpen = 0f, blur = 0f, grain = 0f, vignette = 0f, glow = 0f,
        )
        clock++

        luts.firstOrNull { it.key == key }?.let {
            it.lastUsed = clock
            return it.texture
        }

        val slot = luts.minByOrNull { it.lastUsed } ?: luts.first()
        GlUtil.uploadPixels(
            slot.texture,
            LutBaker.bake(adjustments),
            LutBaker.stripWidth,
            LutBaker.stripHeight,
        )
        // Nearest on both axes would step the gradient; linear is what makes
        // a 32-entry cube look continuous.
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, slot.texture)
        GLES20.glTexParameteri(
            GLES20.GL_TEXTURE_2D, GLES20.GL_TEXTURE_MIN_FILTER, GLES20.GL_LINEAR,
        )
        GLES20.glTexParameteri(
            GLES20.GL_TEXTURE_2D, GLES20.GL_TEXTURE_MAG_FILTER, GLES20.GL_LINEAR,
        )
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, 0)

        slot.key = key
        slot.lastUsed = clock
        return slot.texture
    }
}
