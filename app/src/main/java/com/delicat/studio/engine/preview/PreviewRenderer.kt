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
        renderer.setup()
        if (!renderer.isReady) {
            onError(renderer.failure ?: "The graphics pipeline could not start")
            return
        }

        videoTexture = GlUtil.createTexture(GlUtil.EXTERNAL_TEXTURE)
        val texture = SurfaceTexture(videoTexture)
        texture.setOnFrameAvailableListener {
            frameReady.set(true)
            requestRender()
        }
        surfaceTexture = texture
        surface = Surface(texture).also(onSurface)

        frontStill = StillSlot()
        backStill = StillSlot()
        previous?.release()
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
