package com.vixel.studio.ui.common

import android.graphics.Bitmap
import android.opengl.GLSurfaceView
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.vixel.studio.core.model.Adjustments
import com.vixel.studio.core.model.Overlay
import com.vixel.studio.engine.photo.PhotoPreviewRenderer

/**
 * Live GL preview. Rendering is on-demand (RENDERMODE_WHEN_DIRTY) so an idle
 * editor costs no battery, and every state change explicitly asks for a frame.
 */
@Composable
fun PhotoPreview(
    bitmap: Bitmap?,
    adjustments: Adjustments,
    overlays: List<Overlay> = emptyList(),
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

    AndroidView(
        modifier = modifier,
        factory = { context ->
            GLSurfaceView(context).also { view ->
                view.setEGLContextClientVersion(3)
                view.setEGLConfigChooser(8, 8, 8, 8, 0, 0)
                val renderer = PhotoPreviewRenderer { view.requestRender() }
                holder.renderer = renderer
                holder.view = view
                view.setRenderer(renderer)
                view.renderMode = GLSurfaceView.RENDERMODE_WHEN_DIRTY
            }
        },
        update = { view ->
            val renderer = holder.renderer ?: return@AndroidView
            if (holder.lastBitmap !== bitmap) {
                holder.lastBitmap = bitmap
                renderer.setBitmap(bitmap)
            }
            renderer.adjustments = adjustments
            renderer.overlays = overlays
            view.requestRender()
        },
        onRelease = { view ->
            val renderer = holder.renderer
            if (renderer != null) view.queueEvent { renderer.release() }
            holder.renderer = null
            holder.view = null
            holder.lastBitmap = null
        },
    )
}

private class PreviewHolder {
    var view: GLSurfaceView? = null
    var renderer: PhotoPreviewRenderer? = null
    var lastBitmap: Bitmap? = null
}
