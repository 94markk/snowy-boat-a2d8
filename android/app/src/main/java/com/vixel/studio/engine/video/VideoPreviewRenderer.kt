package com.vixel.studio.engine.video

import android.opengl.GLES30
import android.opengl.GLSurfaceView
import android.opengl.Matrix
import android.view.Surface
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.Mask
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.overlay.OverlayCompositor
import javax.microedition.khronos.egl.EGLConfig
import javax.microedition.khronos.opengles.GL10
import kotlin.math.max
import kotlin.math.roundToInt

/**
 * Draws decoded video frames through the colour stack.
 *
 * Two levels of fitting happen here: the project canvas is letterboxed inside
 * the view, then the clip is fitted inside that canvas. Keeping them separate
 * is what lets a 16:9 source sit correctly inside a 9:16 project instead of
 * being silently stretched.
 */
class VideoPreviewRenderer(
    private val requestRender: () -> Unit,
) : GLSurfaceView.Renderer {

    private val grader = ColorGrader()
    private val overlayCompositor = OverlayCompositor()
    private var decoderSurface: DecoderSurface? = null

    /** Called on the GL thread once the decoder surface exists. */
    @Volatile
    var onSurfaceAvailable: ((Surface) -> Unit)? = null

    @Volatile
    var adjustments: Adjustments = Adjustments()

    @Volatile
    var fitMode: FitMode = FitMode.FIT

    /** Project canvas shape, width / height. */
    @Volatile
    var canvasAspect: Float = 9f / 16f

    @Volatile
    var sourceWidth: Int = 0

    @Volatile
    var sourceHeight: Int = 0

    @Volatile
    var opacity: Float = 1f

    /** Extra scale/offset/rotation from the clip's keyframes at the playhead. */
    @Volatile
    var scale: Float = 1f

    @Volatile
    var offsetX: Float = 0f

    @Volatile
    var offsetY: Float = 0f

    @Volatile
    var rotationDegrees: Float = 0f

    @Volatile
    var mask: Mask = Mask()

    /** Overlays to draw above the frame, and where the playhead is. */
    @Volatile
    var overlays: List<Overlay> = emptyList()

    @Volatile
    var timelineUs: Long = 0L

    /** Colour behind the canvas (the editor surround). */
    @Volatile
    var surroundColor: Int = 0xFF07070B.toInt()

    /** Colour of the canvas itself, from the project. */
    @Volatile
    var canvasColor: Int = 0xFF000000.toInt()

    private var viewWidth = 0
    private var viewHeight = 0

    override fun onSurfaceCreated(gl: GL10?, config: EGLConfig?) {
        grader.init()
        overlayCompositor.init()
        val surface = DecoderSurface()
        surface.onFrameAvailable = { requestRender() }
        decoderSurface = surface
        onSurfaceAvailable?.invoke(surface.surface)
    }

    override fun onSurfaceChanged(gl: GL10?, width: Int, height: Int) {
        viewWidth = width
        viewHeight = height
        decoderSurface?.setDefaultBufferSize(width, height)
    }

    override fun onDrawFrame(gl: GL10?) {
        val surface = decoderSurface ?: return
        surface.updateIfAvailable()

        clear(surroundColor, 0, 0, viewWidth, viewHeight)
        if (viewWidth == 0 || viewHeight == 0) return

        // Canvas letterboxed inside the view.
        val viewAspect = viewWidth.toFloat() / max(1, viewHeight)
        var canvasW = viewWidth
        var canvasH = viewHeight
        if (canvasAspect > viewAspect) {
            canvasH = (viewWidth / canvasAspect).roundToInt().coerceAtLeast(1)
        } else {
            canvasW = (viewHeight * canvasAspect).roundToInt().coerceAtLeast(1)
        }
        val canvasX = (viewWidth - canvasW) / 2
        val canvasY = (viewHeight - canvasH) / 2

        GLES30.glEnable(GLES30.GL_SCISSOR_TEST)
        clear(canvasColor, canvasX, canvasY, canvasW, canvasH)
        GLES30.glDisable(GLES30.GL_SCISSOR_TEST)

        val srcW = sourceWidth
        val srcH = sourceHeight
        if (srcW <= 0 || srcH <= 0) {
            drawOverlays(canvasX, canvasY, canvasW, canvasH)
            return
        }

        // Clip fitted inside the canvas.
        val sourceAspect = srcW.toFloat() / srcH
        val canvasRectAspect = canvasW.toFloat() / max(1, canvasH)

        var drawW = canvasW
        var drawH = canvasH
        val texMatrix = FloatArray(16)
        System.arraycopy(surface.transform(), 0, texMatrix, 0, 16)

        when (fitMode) {
            FitMode.FIT -> {
                if (sourceAspect > canvasRectAspect) {
                    drawH = (canvasW / sourceAspect).roundToInt().coerceAtLeast(1)
                } else {
                    drawW = (canvasH * sourceAspect).roundToInt().coerceAtLeast(1)
                }
            }
            FitMode.FILL -> {
                val scaleX: Float
                val scaleY: Float
                if (sourceAspect > canvasRectAspect) {
                    scaleX = canvasRectAspect / sourceAspect
                    scaleY = 1f
                } else {
                    scaleX = 1f
                    scaleY = sourceAspect / canvasRectAspect
                }
                val crop = FloatArray(16)
                Matrix.setIdentityM(crop, 0)
                Matrix.translateM(crop, 0, (1f - scaleX) / 2f, (1f - scaleY) / 2f, 0f)
                Matrix.scaleM(crop, 0, scaleX, scaleY, 1f)
                val result = FloatArray(16)
                Matrix.multiplyMM(result, 0, texMatrix, 0, crop, 0)
                System.arraycopy(result, 0, texMatrix, 0, 16)
            }
            FitMode.STRETCH -> Unit
        }

        val scaled = scale.coerceIn(0.1f, 8f)
        drawW = (drawW * scaled).roundToInt().coerceAtLeast(1)
        drawH = (drawH * scaled).roundToInt().coerceAtLeast(1)

        val x = canvasX + (canvasW - drawW) / 2 + (offsetX * canvasW).roundToInt()
        val y = canvasY + (canvasH - drawH) / 2 - (offsetY * canvasH).roundToInt()

        // Keep the clip inside the canvas letterbox.
        GLES30.glEnable(GLES30.GL_SCISSOR_TEST)
        GLES30.glScissor(canvasX, canvasY, canvasW, canvasH)
        grader.render(
            sourceTexture = surface.textureId,
            isExternal = true,
            texMatrix = texMatrix,
            sourceWidth = srcW,
            sourceHeight = srcH,
            targetWidth = drawW,
            targetHeight = drawH,
            adjustments = adjustments,
            targetX = x,
            targetY = y,
            mask = mask,
            opacity = opacity,
        )
        GLES30.glDisable(GLES30.GL_SCISSOR_TEST)

        drawOverlays(canvasX, canvasY, canvasW, canvasH)
    }

    private fun drawOverlays(canvasX: Int, canvasY: Int, canvasW: Int, canvasH: Int) {
        val list = overlays
        if (list.isEmpty()) return
        GLES30.glEnable(GLES30.GL_SCISSOR_TEST)
        GLES30.glScissor(canvasX, canvasY, canvasW, canvasH)
        overlayCompositor.draw(
            overlays = list,
            timeUs = timelineUs,
            canvasX = canvasX,
            canvasY = canvasY,
            canvasWidth = canvasW,
            canvasHeight = canvasH,
        )
        GLES30.glDisable(GLES30.GL_SCISSOR_TEST)
    }

    private fun clear(color: Int, x: Int, y: Int, width: Int, height: Int) {
        if (width <= 0 || height <= 0) return
        GLES30.glScissor(x, y, width, height)
        GLES30.glClearColor(
            ((color shr 16) and 0xFF) / 255f,
            ((color shr 8) and 0xFF) / 255f,
            (color and 0xFF) / 255f,
            1f,
        )
        GLES30.glClear(GLES30.GL_COLOR_BUFFER_BIT)
    }

    fun release() {
        decoderSurface?.release()
        decoderSurface = null
        overlayCompositor.release()
        grader.release()
    }
}
