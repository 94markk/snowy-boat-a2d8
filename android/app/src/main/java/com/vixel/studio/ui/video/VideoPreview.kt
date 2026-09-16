package com.vixel.studio.ui.video

import android.opengl.GLSurfaceView
import android.view.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.media3.exoplayer.ExoPlayer
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.FitMode
import com.vixel.studio.core.model.Mask
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.engine.video.VideoPreviewRenderer

/**
 * Preview surface for the timeline.
 *
 * ExoPlayer decodes into a SurfaceTexture the renderer owns, so frames pass
 * through the same ColorGrader the exporter uses before they reach the screen.
 *
 * media3's @UnstableApi is enforced by lint rather than by the compiler, so
 * there is no opt-in annotation to carry here.
 *
 * ExoPlayer plays one clip at a time, so a transition cannot be shown exactly
 * here the way the exporter shows it. [opacity] carries an approximation: the
 * incoming clip ramps up over the transition window. The exported file has the
 * real two-source blend.
 */
@Composable
fun VideoPreview(
    player: ExoPlayer,
    clip: Clip?,
    adjustments: Adjustments,
    canvasAspect: Float,
    canvasColor: Int,
    overlays: List<Overlay>,
    timelineUs: Long,
    opacity: Float = 1f,
    motion: PreviewMotion = PreviewMotion(),
    modifier: Modifier = Modifier,
) {
    val holder = remember { PreviewHolder() }
    val lifecycleOwner = LocalLifecycleOwner.current

    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_RESUME -> holder.view?.onResume()
                Lifecycle.Event.ON_PAUSE -> holder.view?.onPause()
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose { lifecycleOwner.lifecycle.removeObserver(observer) }
    }

    DisposableEffect(player) {
        onDispose { player.setVideoSurface(null) }
    }

    AndroidView(
        modifier = modifier,
        factory = { context ->
            GLSurfaceView(context).also { view ->
                view.setEGLContextClientVersion(3)
                val renderer = VideoPreviewRenderer { view.requestRender() }
                renderer.onSurfaceAvailable = { surface: Surface ->
                    // Surface creation happens on the GL thread; hopping to the
                    // main thread keeps every player call on one thread.
                    view.post { player.setVideoSurface(surface) }
                }
                holder.renderer = renderer
                holder.view = view
                view.setRenderer(renderer)
                view.renderMode = GLSurfaceView.RENDERMODE_WHEN_DIRTY
            }
        },
        update = { view ->
            val renderer = holder.renderer ?: return@AndroidView
            renderer.adjustments = adjustments
            renderer.canvasAspect = canvasAspect
            renderer.canvasColor = canvasColor
            renderer.fitMode = clip?.transform?.fit ?: FitMode.FIT
            renderer.sourceWidth = clip?.displayWidth ?: 0
            renderer.sourceHeight = clip?.displayHeight ?: 0
            renderer.overlays = overlays
            renderer.timelineUs = timelineUs
            renderer.opacity = opacity * motion.opacity
            renderer.scale = motion.scale
            renderer.offsetX = motion.offsetX
            renderer.offsetY = motion.offsetY
            renderer.rotationDegrees = motion.rotationDegrees
            renderer.mask = motion.mask
            view.requestRender()
        },
        onRelease = { view ->
            val renderer = holder.renderer
            if (renderer != null) view.queueEvent { renderer.release() }
            holder.renderer = null
            holder.view = null
        },
    )
}

/** Animated state for the clip under the playhead. */
data class PreviewMotion(
    val scale: Float = 1f,
    val offsetX: Float = 0f,
    val offsetY: Float = 0f,
    val rotationDegrees: Float = 0f,
    val opacity: Float = 1f,
    val mask: Mask = Mask(),
)

private class PreviewHolder {
    var view: GLSurfaceView? = null
    var renderer: VideoPreviewRenderer? = null
}
