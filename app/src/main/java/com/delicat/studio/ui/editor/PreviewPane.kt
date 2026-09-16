package com.delicat.studio.ui.editor

import android.opengl.GLSurfaceView
import android.view.Surface
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.delicat.studio.engine.preview.PreviewRenderer
import com.delicat.studio.engine.preview.Scene
import com.delicat.studio.ui.theme.Ink
import kotlinx.coroutines.launch

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
    modifier: Modifier = Modifier,
) {
    val holder = remember { mutableStateOf<GLSurfaceView?>(null) }
    val scope = rememberCoroutineScope()
    val surfaceCallback by rememberUpdatedState(onSurface)
    val errorCallback by rememberUpdatedState(onError)

    val renderer = remember {
        PreviewRenderer(
            // Both callbacks arrive on the GL thread. The player is a
            // main-thread object and will refuse a call from anywhere else,
            // so they are handed across rather than invoked where they land.
            onSurface = { surface -> scope.launch { surfaceCallback(surface) } },
            requestRender = { holder.value?.requestRender() },
            onError = { reason -> scope.launch { errorCallback(reason) } },
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
