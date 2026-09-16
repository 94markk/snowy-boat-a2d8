package com.vixel.studio.engine.gl

import android.opengl.EGL14
import android.opengl.EGLConfig
import android.opengl.EGLContext
import android.opengl.EGLDisplay
import android.opengl.EGLExt
import android.opengl.EGLSurface
import android.view.Surface

/**
 * Minimal EGL 1.4 setup for rendering off the UI thread — used by the image
 * exporter (pbuffer) and the video exporter (a MediaCodec input Surface).
 */
class EglCore(sharedContext: EGLContext? = null, recordable: Boolean = false) {

    private var display: EGLDisplay = EGL14.EGL_NO_DISPLAY
    private var config: EGLConfig? = null
    var context: EGLContext = EGL14.EGL_NO_CONTEXT
        private set

    init {
        display = EGL14.eglGetDisplay(EGL14.EGL_DEFAULT_DISPLAY)
        if (display === EGL14.EGL_NO_DISPLAY) throw GlException("eglGetDisplay failed")

        val version = IntArray(2)
        if (!EGL14.eglInitialize(display, version, 0, version, 1)) {
            display = EGL14.EGL_NO_DISPLAY
            throw GlException("eglInitialize failed")
        }

        val attribs = mutableListOf(
            EGL14.EGL_RED_SIZE, 8,
            EGL14.EGL_GREEN_SIZE, 8,
            EGL14.EGL_BLUE_SIZE, 8,
            EGL14.EGL_ALPHA_SIZE, 8,
            EGL14.EGL_RENDERABLE_TYPE, EGLExt.EGL_OPENGL_ES3_BIT_KHR,
            EGL14.EGL_SURFACE_TYPE, EGL14.EGL_WINDOW_BIT or EGL14.EGL_PBUFFER_BIT,
        )
        if (recordable) {
            // Tells the driver the surface may be consumed by a video encoder.
            attribs += listOf(EGL_RECORDABLE_ANDROID, 1)
        }
        attribs += EGL14.EGL_NONE

        val configs = arrayOfNulls<EGLConfig>(1)
        val numConfigs = IntArray(1)
        if (!EGL14.eglChooseConfig(
                display, attribs.toIntArray(), 0, configs, 0, 1, numConfigs, 0,
            ) || numConfigs[0] <= 0
        ) {
            throw GlException("eglChooseConfig found no ES3 config")
        }
        config = configs[0]

        val contextAttribs = intArrayOf(EGL14.EGL_CONTEXT_CLIENT_VERSION, 3, EGL14.EGL_NONE)
        context = EGL14.eglCreateContext(
            display, config, sharedContext ?: EGL14.EGL_NO_CONTEXT, contextAttribs, 0,
        )
        if (context === EGL14.EGL_NO_CONTEXT) throw GlException("eglCreateContext failed")
    }

    fun createWindowSurface(surface: Surface): EGLSurface {
        val attribs = intArrayOf(EGL14.EGL_NONE)
        val eglSurface = EGL14.eglCreateWindowSurface(display, config, surface, attribs, 0)
            ?: throw GlException("eglCreateWindowSurface returned null")
        if (eglSurface === EGL14.EGL_NO_SURFACE) throw GlException("eglCreateWindowSurface failed")
        return eglSurface
    }

    fun createOffscreenSurface(width: Int, height: Int): EGLSurface {
        val attribs = intArrayOf(
            EGL14.EGL_WIDTH, width,
            EGL14.EGL_HEIGHT, height,
            EGL14.EGL_NONE,
        )
        val eglSurface = EGL14.eglCreatePbufferSurface(display, config, attribs, 0)
            ?: throw GlException("eglCreatePbufferSurface returned null")
        if (eglSurface === EGL14.EGL_NO_SURFACE) throw GlException("eglCreatePbufferSurface failed")
        return eglSurface
    }

    fun makeCurrent(surface: EGLSurface) {
        if (!EGL14.eglMakeCurrent(display, surface, surface, context)) {
            throw GlException("eglMakeCurrent failed")
        }
    }

    fun swapBuffers(surface: EGLSurface): Boolean = EGL14.eglSwapBuffers(display, surface)

    /** Stamps the presentation time the encoder will use, in nanoseconds. */
    fun setPresentationTime(surface: EGLSurface, nsecs: Long) {
        EGLExt.eglPresentationTimeANDROID(display, surface, nsecs)
    }

    fun releaseSurface(surface: EGLSurface) {
        EGL14.eglDestroySurface(display, surface)
    }

    fun release() {
        if (display !== EGL14.EGL_NO_DISPLAY) {
            EGL14.eglMakeCurrent(
                display, EGL14.EGL_NO_SURFACE, EGL14.EGL_NO_SURFACE, EGL14.EGL_NO_CONTEXT,
            )
            EGL14.eglDestroyContext(display, context)
            EGL14.eglReleaseThread()
            EGL14.eglTerminate(display)
        }
        display = EGL14.EGL_NO_DISPLAY
        context = EGL14.EGL_NO_CONTEXT
        config = null
    }

    private companion object {
        const val EGL_RECORDABLE_ANDROID = 0x3142
    }
}
