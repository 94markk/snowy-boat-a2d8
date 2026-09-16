package com.vixel.studio.engine.photo

import android.graphics.Bitmap
import android.opengl.GLES30
import android.opengl.GLSurfaceView
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.gl.GlUtils
import javax.microedition.khronos.egl.EGLConfig
import javax.microedition.khronos.opengles.GL10
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Live preview for the photo editor. Holds the source at preview resolution so
 * dragging a slider stays smooth on a mid-range phone; export re-renders from
 * the original at full size through [PhotoEngine].
 */
class PhotoPreviewRenderer(
    private val requestRender: () -> Unit,
) : GLSurfaceView.Renderer {

    /** Longest edge kept for preview. 2048 is sharp on any phone screen. */
    private val previewMaxEdge = 2048

    private val grader = ColorGrader()

    @Volatile
    private var pendingBitmap: Bitmap? = null

    @Volatile
    var adjustments: Adjustments = Adjustments()
        set(value) {
            field = value
            requestRender()
        }

    /** Shown behind the image; matches the editor canvas colour. */
    @Volatile
    var backgroundColor: Int = 0xFF07070B.toInt()

    private var texture = 0
    private var textureWidth = 0
    private var textureHeight = 0

    private var viewWidth = 0
    private var viewHeight = 0

    fun setBitmap(bitmap: Bitmap?) {
        pendingBitmap = bitmap
        requestRender()
    }

    override fun onSurfaceCreated(gl: GL10?, config: EGLConfig?) {
        grader.init()
        // A surface loss invalidates every GL name we held.
        texture = 0
        textureWidth = 0
        textureHeight = 0
    }

    override fun onSurfaceChanged(gl: GL10?, width: Int, height: Int) {
        viewWidth = width
        viewHeight = height
    }

    override fun onDrawFrame(gl: GL10?) {
        consumePendingBitmap()

        val r = ((backgroundColor shr 16) and 0xFF) / 255f
        val g = ((backgroundColor shr 8) and 0xFF) / 255f
        val b = (backgroundColor and 0xFF) / 255f
        GLES30.glClearColor(r, g, b, 1f)
        GLES30.glClear(GLES30.GL_COLOR_BUFFER_BIT)

        if (texture == 0 || textureWidth == 0 || viewWidth == 0) return

        // Letterbox: fit the image inside the view, centred.
        val scale = min(
            viewWidth.toFloat() / textureWidth,
            viewHeight.toFloat() / textureHeight,
        )
        val drawW = (textureWidth * scale).roundToInt().coerceAtLeast(1)
        val drawH = (textureHeight * scale).roundToInt().coerceAtLeast(1)
        val x = (viewWidth - drawW) / 2
        val y = (viewHeight - drawH) / 2

        grader.render(
            sourceTexture = texture,
            isExternal = false,
            texMatrix = ColorGrader.IDENTITY,
            sourceWidth = textureWidth,
            sourceHeight = textureHeight,
            targetWidth = drawW,
            targetHeight = drawH,
            adjustments = adjustments,
            targetX = x,
            targetY = y,
        )
    }

    private fun consumePendingBitmap() {
        val bitmap = pendingBitmap ?: return
        pendingBitmap = null

        if (bitmap.isRecycled) return
        val (w, h) = ColorGrader.fit(bitmap.width, bitmap.height, previewMaxEdge)
        val upload = if (w == bitmap.width && h == bitmap.height) {
            bitmap
        } else {
            Bitmap.createScaledBitmap(bitmap, w, h, true)
        }

        GlUtils.deleteTexture(texture)
        texture = GlUtils.createTextureFromBitmap(upload)
        textureWidth = w
        textureHeight = h
        if (upload !== bitmap) upload.recycle()
    }

    fun release() {
        GlUtils.deleteTexture(texture)
        texture = 0
        grader.release()
    }
}
