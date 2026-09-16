package com.vixel.studio.engine.gl

import android.graphics.Bitmap
import android.opengl.GLES20
import android.opengl.GLES30
import android.opengl.GLUtils
import android.util.Log
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.nio.FloatBuffer

private const val TAG = "VixelGL"

class GlException(message: String) : RuntimeException(message)

object GlUtils {

    /** Full-screen triangle strip: position (x, y) + texture coord (u, v). */
    val QUAD: FloatBuffer = floatBufferOf(
        -1f, -1f, 0f, 0f,
        1f, -1f, 1f, 0f,
        -1f, 1f, 0f, 1f,
        1f, 1f, 1f, 1f,
    )

    fun floatBufferOf(vararg values: Float): FloatBuffer =
        ByteBuffer.allocateDirect(values.size * 4)
            .order(ByteOrder.nativeOrder())
            .asFloatBuffer()
            .apply {
                put(values)
                position(0)
            }

    fun compileShader(type: Int, source: String): Int {
        val shader = GLES30.glCreateShader(type)
        if (shader == 0) throw GlException("glCreateShader failed")
        GLES30.glShaderSource(shader, source)
        GLES30.glCompileShader(shader)
        val status = IntArray(1)
        GLES30.glGetShaderiv(shader, GLES30.GL_COMPILE_STATUS, status, 0)
        if (status[0] == 0) {
            val log = GLES30.glGetShaderInfoLog(shader)
            GLES30.glDeleteShader(shader)
            throw GlException("Shader compile failed: $log\n--- source ---\n${numbered(source)}")
        }
        return shader
    }

    fun linkProgram(vertexSource: String, fragmentSource: String): Int {
        val vs = compileShader(GLES30.GL_VERTEX_SHADER, vertexSource)
        val fs = compileShader(GLES30.GL_FRAGMENT_SHADER, fragmentSource)
        val program = GLES30.glCreateProgram()
        if (program == 0) throw GlException("glCreateProgram failed")
        GLES30.glAttachShader(program, vs)
        GLES30.glAttachShader(program, fs)
        GLES30.glLinkProgram(program)
        // Shaders are reference-counted by the program; drop our handles.
        GLES30.glDeleteShader(vs)
        GLES30.glDeleteShader(fs)
        val status = IntArray(1)
        GLES30.glGetProgramiv(program, GLES30.GL_LINK_STATUS, status, 0)
        if (status[0] == 0) {
            val log = GLES30.glGetProgramInfoLog(program)
            GLES30.glDeleteProgram(program)
            throw GlException("Program link failed: $log")
        }
        return program
    }

    fun createTexture(target: Int = GLES30.GL_TEXTURE_2D, clampToEdge: Boolean = true): Int {
        val ids = IntArray(1)
        GLES30.glGenTextures(1, ids, 0)
        val id = ids[0]
        GLES30.glBindTexture(target, id)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_MIN_FILTER, GLES30.GL_LINEAR)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_MAG_FILTER, GLES30.GL_LINEAR)
        val wrap = if (clampToEdge) GLES30.GL_CLAMP_TO_EDGE else GLES30.GL_REPEAT
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_WRAP_S, wrap)
        GLES30.glTexParameteri(target, GLES30.GL_TEXTURE_WRAP_T, wrap)
        GLES30.glBindTexture(target, 0)
        return id
    }

    fun createTextureFromBitmap(bitmap: Bitmap): Int {
        val id = createTexture()
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, id)
        GLUtils.texImage2D(GLES30.GL_TEXTURE_2D, 0, bitmap, 0)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, 0)
        return id
    }

    fun uploadBitmap(textureId: Int, bitmap: Bitmap) {
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, textureId)
        GLUtils.texImage2D(GLES30.GL_TEXTURE_2D, 0, bitmap, 0)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, 0)
    }

    fun deleteTexture(id: Int) {
        if (id != 0) GLES30.glDeleteTextures(1, intArrayOf(id), 0)
    }

    fun checkError(op: String) {
        var error = GLES30.glGetError()
        while (error != GLES30.GL_NO_ERROR) {
            Log.e(TAG, "$op: glError 0x${Integer.toHexString(error)}")
            error = GLES30.glGetError()
        }
    }

    private fun numbered(source: String): String =
        source.lineSequence().mapIndexed { i, l -> "${i + 1}: $l" }.joinToString("\n")
}

/**
 * Colour render target used for the multi-pass chain (blur, glow, compositing).
 * Reallocates only when the requested size actually changes.
 */
class GlFramebuffer {
    var textureId: Int = 0
        private set
    var framebufferId: Int = 0
        private set
    var width: Int = 0
        private set
    var height: Int = 0
        private set

    fun ensure(width: Int, height: Int) {
        if (width <= 0 || height <= 0) return
        if (this.width == width && this.height == height && framebufferId != 0) return
        release()

        this.width = width
        this.height = height

        textureId = GlUtils.createTexture()
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, textureId)
        GLES30.glTexImage2D(
            GLES30.GL_TEXTURE_2D, 0, GLES30.GL_RGBA8, width, height, 0,
            GLES30.GL_RGBA, GLES30.GL_UNSIGNED_BYTE, null,
        )

        val fb = IntArray(1)
        GLES30.glGenFramebuffers(1, fb, 0)
        framebufferId = fb[0]
        GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, framebufferId)
        GLES30.glFramebufferTexture2D(
            GLES30.GL_FRAMEBUFFER, GLES30.GL_COLOR_ATTACHMENT0,
            GLES30.GL_TEXTURE_2D, textureId, 0,
        )
        val status = GLES30.glCheckFramebufferStatus(GLES30.GL_FRAMEBUFFER)
        GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, 0)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, 0)
        if (status != GLES30.GL_FRAMEBUFFER_COMPLETE) {
            throw GlException("Framebuffer incomplete: 0x${Integer.toHexString(status)}")
        }
    }

    fun use(block: () -> Unit) {
        GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, framebufferId)
        GLES20.glViewport(0, 0, width, height)
        block()
        GLES30.glBindFramebuffer(GLES30.GL_FRAMEBUFFER, 0)
    }

    fun release() {
        if (framebufferId != 0) {
            GLES30.glDeleteFramebuffers(1, intArrayOf(framebufferId), 0)
            framebufferId = 0
        }
        GlUtils.deleteTexture(textureId)
        textureId = 0
        width = 0
        height = 0
    }
}
