package com.vixel.studio.engine.photo

import android.graphics.Bitmap
import android.opengl.GLES30
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.gl.EglCore
import com.vixel.studio.engine.gl.GlFramebuffer
import com.vixel.studio.engine.gl.GlUtils

/**
 * Renders a still through the same colour stack the video path uses, off the
 * main thread, at full resolution.
 */
object PhotoEngine {

    /** Hard ceiling so a 108MP phone photo cannot blow the GL texture limit. */
    const val MAX_EDGE = 4096

    /**
     * @return a new bitmap; the caller owns it. [source] is left untouched.
     */
    fun render(source: Bitmap, adjustments: Adjustments): Bitmap {
        if (source.isRecycled) throw IllegalArgumentException("source bitmap is recycled")

        val (width, height) = ColorGrader.fit(source.width, source.height, MAX_EDGE)

        val egl = EglCore()
        var surface = egl.createOffscreenSurface(width, height)
        val grader = ColorGrader()
        val target = GlFramebuffer()
        var texture = 0
        try {
            egl.makeCurrent(surface)
            grader.init()
            target.ensure(width, height)

            val upload = if (width == source.width && height == source.height) {
                source
            } else {
                Bitmap.createScaledBitmap(source, width, height, true)
            }
            texture = GlUtils.createTextureFromBitmap(upload)
            if (upload !== source) upload.recycle()

            var result: Bitmap? = null
            target.use {
                GLES30.glClearColor(0f, 0f, 0f, 0f)
                GLES30.glClear(GLES30.GL_COLOR_BUFFER_BIT)
                grader.render(
                    sourceTexture = texture,
                    isExternal = false,
                    texMatrix = ColorGrader.IDENTITY,
                    sourceWidth = width,
                    sourceHeight = height,
                    targetWidth = width,
                    targetHeight = height,
                    adjustments = adjustments,
                )
                result = grader.readPixels(width, height)
            }
            return result ?: throw IllegalStateException("render produced no output")
        } finally {
            GlUtils.deleteTexture(texture)
            target.release()
            grader.release()
            egl.releaseSurface(surface)
            egl.release()
        }
    }
}
