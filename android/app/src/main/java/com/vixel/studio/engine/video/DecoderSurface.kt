package com.vixel.studio.engine.video

import android.graphics.SurfaceTexture
import android.opengl.GLES11Ext
import android.opengl.GLES30
import android.view.Surface
import com.vixel.studio.engine.gl.GlException

/**
 * The bridge from MediaCodec to GL: the decoder writes into a [Surface] backed
 * by a [SurfaceTexture], which shows up as an external OES texture the grader
 * can sample.
 *
 * Must be created on the thread holding the GL context.
 */
class DecoderSurface {

    val textureId: Int
    private val surfaceTexture: SurfaceTexture
    val surface: Surface

    private val frameSyncObject = Object()
    private var frameAvailable = false

    /**
     * Notified whenever the decoder posts a frame. Live preview uses this to
     * schedule a repaint; the export path ignores it and blocks on
     * [awaitNewImage] instead.
     */
    @Volatile
    var onFrameAvailable: (() -> Unit)? = null

    private val transformMatrix = FloatArray(16)

    init {
        val ids = IntArray(1)
        GLES30.glGenTextures(1, ids, 0)
        textureId = ids[0]
        if (textureId == 0) throw GlException("could not create an OES texture")

        val target = GLES11Ext.GL_TEXTURE_EXTERNAL_OES
        GLES30.glBindTexture(target, textureId)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_MIN_FILTER, GLES30.GL_LINEAR)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_MAG_FILTER, GLES30.GL_LINEAR)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_WRAP_S, GLES30.GL_CLAMP_TO_EDGE)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_WRAP_T, GLES30.GL_CLAMP_TO_EDGE)

        surfaceTexture = SurfaceTexture(textureId)
        surfaceTexture.setOnFrameAvailableListener {
            synchronized(frameSyncObject) {
                frameAvailable = true
                frameSyncObject.notifyAll()
            }
            onFrameAvailable?.invoke()
        }
        surface = Surface(surfaceTexture)
    }

    /**
     * Blocks until the decoder has pushed a frame.
     *
     * @return false on timeout, which the caller treats as "stop waiting on
     *   this clip" rather than as a fatal error — some decoders drop the last
     *   frame at end of stream.
     */
    fun awaitNewImage(timeoutMs: Long = 2500L): Boolean {
        synchronized(frameSyncObject) {
            val deadline = System.currentTimeMillis() + timeoutMs
            while (!frameAvailable) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) return false
                try {
                    frameSyncObject.wait(remaining)
                } catch (e: InterruptedException) {
                    Thread.currentThread().interrupt()
                    return false
                }
            }
            frameAvailable = false
        }
        surfaceTexture.updateTexImage()
        surfaceTexture.getTransformMatrix(transformMatrix)
        return true
    }

    /**
     * Non-blocking variant for live preview: consumes a frame if one has
     * arrived, otherwise leaves the texture showing the previous frame.
     *
     * @return true when the texture was updated.
     */
    fun updateIfAvailable(): Boolean {
        synchronized(frameSyncObject) {
            if (!frameAvailable) return false
            frameAvailable = false
        }
        surfaceTexture.updateTexImage()
        surfaceTexture.getTransformMatrix(transformMatrix)
        return true
    }

    /** Preview surfaces must match the view, not the decoder's natural size. */
    fun setDefaultBufferSize(width: Int, height: Int) {
        if (width > 0 && height > 0) surfaceTexture.setDefaultBufferSize(width, height)
    }

    /** Valid only after a successful [awaitNewImage]. */
    fun transform(): FloatArray = transformMatrix

    fun release() {
        surface.release()
        surfaceTexture.release()
        if (textureId != 0) GLES30.glDeleteTextures(1, intArrayOf(textureId), 0)
    }
}
