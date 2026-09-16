package com.delicat.studio.ui.editor

import android.opengl.GLSurfaceView
import android.os.Handler
import android.os.Looper
import android.view.Surface
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.delicat.studio.engine.preview.PreviewRenderer
import com.delicat.studio.engine.preview.PreviewReport
import com.delicat.studio.engine.preview.Scene
import com.delicat.studio.ui.theme.Ink

/**
 * The graded frame, drawn by the same shader the exporter uses.
 *
 * The surface is shaped to the project's canvas rather than to the space
 * available, so the black around a portrait clip is the page it sits on and
 * not part of the picture. What the user frames here is what comes out.
 */
@Composable
fun PreviewPane(
    scene: Scene,
    onSurface: (Surface) -> Unit,
    onError: (String) -> Unit,
    onReport: (PreviewReport) -> Unit,
    modifier: Modifier = Modifier,
) {
    val holder = remember { mutableStateOf<GLSurfaceView?>(null) }
    val surfaceCallback by rememberUpdatedState(onSurface)
    val errorCallback by rememberUpdatedState(onError)
    val reportCallback by rememberUpdatedState(onReport)

    // Both callbacks arrive on the GL thread, and the player refuses to be
    // touched from anywhere but the main one. Launching a coroutine on the
    // composition's scope looks like it moves the work and does not reliably:
    // whether it hops threads is up to whatever interceptor is in force, and
    // under one of them it ran inline on the GL thread and took the editor
    // down on launch. Posting says what is meant and leaves nothing to a
    // dispatcher's discretion.
    val toMainThread = remember { Handler(Looper.getMainLooper()) }

    val renderer = remember {
        PreviewRenderer(
            onSurface = { surface -> toMainThread.post { surfaceCallback(surface) } },
            requestRender = { holder.value?.requestRender() },
            onError = { reason -> toMainThread.post { errorCallback(reason) } },
            onReport = { report -> toMainThread.post { reportCallback(report) } },
        )
    }

    val lifecycleOwner = LocalLifecycleOwner.current
    DisposableEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_RESUME -> holder.value?.onResume()
                Lifecycle.Event.ON_PAUSE -> holder.value?.onPause()
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            holder.value?.queueEvent { renderer.releaseGl() }
        }
    }

    Box(
        modifier = modifier.background(Ink.Black),
        contentAlignment = Alignment.Center,
    ) {
        AndroidView(
            factory = { context ->
                GLSurfaceView(context).apply {
                    setEGLContextClientVersion(2)
                    // Eight bits a channel and no alpha. The default chooser
                    // settles for 565, which puts visible steps through every
                    // sky and gradient the user is here to grade.
                    setEGLConfigChooser(8, 8, 8, 0, 0, 0)
                    setRenderer(renderer)
                    // Drawing on demand rather than sixty times a second. A
                    // paused editor showing a still frame should cost nothing,
                    // and the decoder asks for a redraw whenever it has
                    // something new.
                    renderMode = GLSurfaceView.RENDERMODE_WHEN_DIRTY
                    holder.value = this
                }
            },
            update = { view ->
                renderer.scene = scene
                view.requestRender()
            },
            modifier = Modifier.aspectRatio(scene.ratio.ratio),
        )
    }
}
