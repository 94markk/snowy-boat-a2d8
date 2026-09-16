package com.delicat.studio.engine.preview

import android.graphics.SurfaceTexture
import android.opengl.GLES20
import android.opengl.GLSurfaceView
import android.view.Surface
import com.delicat.studio.engine.gl.FrameRenderer
import com.delicat.studio.engine.gl.GlUtil
import com.delicat.studio.engine.gl.LayerStyle
import com.delicat.studio.engine.gl.Placement
import com.delicat.studio.engine.gl.Transitions
import com.delicat.studio.model.TransitionType
import java.util.concurrent.atomic.AtomicBoolean
import javax.microedition.khronos.egl.EGLConfig
import javax.microedition.khronos.opengles.GL10

/**
 * Draws the preview.
 *
 * Runs entirely on the GL thread that [GLSurfaceView] provides. The only
 * things crossing into it are the scene, which is swapped atomically, and the
 * flag a new video frame sets; everything else it owns outright.
 */
class PreviewRenderer(
    /** Called on the GL thread whenever a fresh surface exists for the player. */
    private val onSurface: (Surface) -> Unit,
    /** Asks the host view for another frame, used when the decoder produces one. */
    private val requestRender: () -> Unit,
    private val onError: (String) -> Unit,
    /** Called whenever what the preview knows about itself changes. */
    private val onReport: (PreviewReport) -> Unit = {},
) : GLSurfaceView.Renderer {

    @Volatile
    var scene: Scene = Scene()

    private val renderer = FrameRenderer()
    private val frameReady = AtomicBoolean(false)

    private var videoTexture = 0
    private var surfaceTexture: SurfaceTexture? = null
    private var surface: Surface? = null
    private val sourceMatrix = FloatArray(16)

    private var frontStill = StillSlot()
    private var backStill = StillSlot()

    private var viewWidth = 1
    private var viewHeight = 1

    @Volatile
    private var report = PreviewReport()
    private var drawn = 0L
    private var received = 0L

    private class StillSlot {
        var texture = 0
        var key: String? = null
        var width = 0
        var height = 0
    }

    override fun onSurfaceCreated(unused: GL10?, config: EGLConfig?) {
        // A context can be lost and rebuilt at any time — backgrounding the app
        // is enough on some devices. Everything here is therefore created
        // fresh rather than reused, including the surface the player writes
        // into, which is why the player is told about it again each time.
        //
        // The outgoing surface is held back and released last, so the player
        // is never left pointing at one that has already gone.
        val previous = surface
        surface = null
        releaseGl()

        // The player's surface is built first, and whether grading works has
        // no bearing on it. Colour can fail on hardware the shader does not
        // suit, and playback should not fail alongside it — but worse, a
        // player given no surface at all falls back to a placeholder one, and
        // on a device that cannot make one the decoder refuses to start. A
        // graphics fault then arrives as "this clip would not play", which
        // sends anyone looking in entirely the wrong place.
        videoTexture = GlUtil.createTexture(GlUtil.EXTERNAL_TEXTURE)
        val texture = SurfaceTexture(videoTexture)
        // A SurfaceTexture begins life with a zero-sized buffer. A decoder is
        // within its rights to refuse to configure against that, and the size
        // set here is only a floor: MediaCodec replaces it with the video's
        // own dimensions the moment it configures.
        texture.setDefaultBufferSize(DEFAULT_BUFFER_WIDTH, DEFAULT_BUFFER_HEIGHT)
        texture.setOnFrameAvailableListener {
            received++
            frameReady.set(true)
            requestRender()
        }
        surfaceTexture = texture
        surface = Surface(texture).also(onSurface)

        frontStill = StillSlot()
        backStill = StillSlot()
        previous?.release()

        renderer.setup()

        drawn = 0L
        received = 0L
        report = PreviewReport(
            vendor = GLES20.glGetString(GLES20.GL_VENDOR).orEmpty(),
            renderer = GLES20.glGetString(GLES20.GL_RENDERER).orEmpty(),
            version = GLES20.glGetString(GLES20.GL_VERSION).orEmpty(),
            shaderCompiled = renderer.isReady,
            shaderError = renderer.failure,
            surfaceReady = surface != null,
        )
        onReport(report)

        if (!renderer.isReady) {
            onError(renderer.failure ?: "The graphics pipeline could not start")
        }
    }

    override fun onSurfaceChanged(unused: GL10?, width: Int, height: Int) {
        viewWidth = width.coerceAtLeast(1)
        viewHeight = height.coerceAtLeast(1)
    }

    override fun onDrawFrame(unused: GL10?) {
        if (!renderer.isReady) {
            GLES20.glClearColor(0f, 0f, 0f, 1f)
            GLES20.glClear(GLES20.GL_COLOR_BUFFER_BIT)
            return
        }

        val current = scene

        if (frameReady.compareAndSet(true, false)) {
            // Only ever called when a frame is actually waiting. Calling it
            // otherwise blocks on some drivers and returns the same frame on
            // others, and neither is a good way to find out.
            surfaceTexture?.runCatching { updateTexImage() }
        }
        surfaceTexture?.getTransformMatrix(sourceMatrix)

        try {
            renderer.beginFrame(viewWidth, viewHeight, current.background)
            renderer.tick()
            drawn++
            // Reported sparingly: the counters only need to be roughly right,
            // and a report per frame would cross threads sixty times a second
            // to say almost nothing.
            if (drawn % 30L == 1L) {
                report = report.copy(framesDrawn = drawn, videoFramesReceived = received)
                onReport(report)
            }

            if (current.isEmpty) return

            val styles = Transitions.styles(current.transition, current.progress)
            if (current.back != null && current.transition != TransitionType.NONE) {
                draw(current.back, styles.outgoing, backStill)
            }
            current.front?.let { draw(it, styles.incoming, frontStill) }
        } catch (e: Throwable) {
            // A driver fault here would otherwise repeat sixty times a second.
            renderer.release()
            onError(e.message ?: "The preview stopped drawing")
        }
    }

    private fun draw(layer: SceneLayer, style: LayerStyle, slot: StillSlot) {
        if (!style.visible) return

        val isVideo = layer.source is LayerSource.Video
        val texture: Int
        val width: Int
        val height: Int

        if (isVideo) {
            texture = videoTexture
            width = layer.sourceWidth
            height = layer.sourceHeight
        } else {
            val still = layer.source as LayerSource.Still
            if (slot.key != still.key) {
                if (slot.texture == 0) slot.texture = GlUtil.createTexture(GlUtil.FLAT_TEXTURE)
                if (still.bitmap.isRecycled) return
                GlUtil.upload(slot.texture, still.bitmap)
                slot.key = still.key
                slot.width = still.bitmap.width
                slot.height = still.bitmap.height
            }
            texture = slot.texture
            width = slot.width
            height = slot.height
        }
        if (texture == 0) return

        renderer.drawLayer(
            texture = texture,
            isExternal = isVideo,
            sourceMatrix = if (isVideo) sourceMatrix else null,
            sourceWidth = width,
            sourceHeight = height,
            adjustments = layer.adjustments,
            placement = Placement(
                canvasWidth = viewWidth,
                canvasHeight = viewHeight,
                sourceWidth = if (width > 0) width else layer.sourceWidth,
                sourceHeight = if (height > 0) height else layer.sourceHeight,
                fit = layer.fit,
                quarterTurns = layer.quarterTurns,
                scale = style.motion.scale,
                offsetX = style.motion.offsetX,
                offsetY = style.motion.offsetY,
                // A bitmap's first row is its top, which is the opposite of
                // where the quad's first row lands.
                flipVertically = !isVideo,
            ),
            paint = style.paint,
        )
    }

    private companion object {
        /** Any non-zero size will do; the decoder overrides it on configure. */
        const val DEFAULT_BUFFER_WIDTH = 1920
        const val DEFAULT_BUFFER_HEIGHT = 1080
    }

    /** Called from the host view once the GL thread is finished with it. */
    fun releaseGl() {
        surface?.release()
        surface = null
        surfaceTexture?.let {
            it.setOnFrameAvailableListener(null)
            it.release()
        }
        surfaceTexture = null
        GlUtil.deleteTexture(videoTexture)
        videoTexture = 0
        GlUtil.deleteTexture(frontStill.texture)
        GlUtil.deleteTexture(backStill.texture)
        frontStill = StillSlot()
        backStill = StillSlot()
        renderer.release()
    }
}
