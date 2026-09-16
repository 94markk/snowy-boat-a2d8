package com.delicat.studio.engine.gl

import android.graphics.Bitmap
import android.opengl.GLES11Ext
import android.opengl.GLES20
import android.opengl.GLUtils
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.nio.FloatBuffer

/** Raised when the driver rejects something; carries the call that failed. */
class GlException(message: String) : RuntimeException(message)

/**
 * The small amount of OpenGL plumbing the renderers share.
 *
 * Every entry point checks its own result. GL reports failure by setting a
 * flag rather than by throwing, so an unchecked mistake does not surface where
 * it happened: it surfaces as a black preview several hundred lines later, or
 * as nothing at all on one vendor's driver and a crash on another's.
 */
object GlUtil {

    const val EXTERNAL_TEXTURE = GLES11Ext.GL_TEXTURE_EXTERNAL_OES
    const val FLAT_TEXTURE = GLES20.GL_TEXTURE_2D

    /** A full-screen triangle pair as interleaved position + texture pairs. */
    val QUAD: FloatBuffer = floatBuffer(
        floatArrayOf(
            -1f, -1f, 0f, 0f,
            1f, -1f, 1f, 0f,
            -1f, 1f, 0f, 1f,
            1f, 1f, 1f, 1f,
        ),
    )

    fun floatBuffer(values: FloatArray): FloatBuffer =
        ByteBuffer.allocateDirect(values.size * 4)
            .order(ByteOrder.nativeOrder())
            .asFloatBuffer()
            .apply {
                put(values)
                position(0)
            }

    fun checkGl(where: String) {
        var error = GLES20.glGetError()
        if (error == GLES20.GL_NO_ERROR) return
        val codes = StringBuilder()
        while (error != GLES20.GL_NO_ERROR) {
            codes.append(" 0x").append(Integer.toHexString(error))
            error = GLES20.glGetError()
        }
        throw GlException("$where failed:$codes")
    }

    fun compile(type: Int, source: String): Int {
        val shader = GLES20.glCreateShader(type)
        if (shader == 0) throw GlException("glCreateShader returned 0")
        GLES20.glShaderSource(shader, source)
        GLES20.glCompileShader(shader)

        val status = IntArray(1)
        GLES20.glGetShaderiv(shader, GLES20.GL_COMPILE_STATUS, status, 0)
        if (status[0] == 0) {
            val log = GLES20.glGetShaderInfoLog(shader)
            GLES20.glDeleteShader(shader)
            val kind = if (type == GLES20.GL_VERTEX_SHADER) "vertex" else "fragment"
            throw GlException("$kind shader did not compile: $log")
        }
        return shader
    }

    fun link(vertexSource: String, fragmentSource: String): Int {
        val vertex = compile(GLES20.GL_VERTEX_SHADER, vertexSource)
        val fragment = compile(GLES20.GL_FRAGMENT_SHADER, fragmentSource)
        val program = GLES20.glCreateProgram()
        if (program == 0) throw GlException("glCreateProgram returned 0")

        GLES20.glAttachShader(program, vertex)
        GLES20.glAttachShader(program, fragment)
        GLES20.glLinkProgram(program)

        val status = IntArray(1)
        GLES20.glGetProgramiv(program, GLES20.GL_LINK_STATUS, status, 0)
        // The shaders are reference-counted by the program once attached, so
        // they can be dropped here whether or not the link succeeded.
        GLES20.glDeleteShader(vertex)
        GLES20.glDeleteShader(fragment)
        if (status[0] == 0) {
            val log = GLES20.glGetProgramInfoLog(program)
            GLES20.glDeleteProgram(program)
            throw GlException("program did not link: $log")
        }
        return program
    }

    fun createTexture(target: Int): Int {
        val ids = IntArray(1)
        GLES20.glGenTextures(1, ids, 0)
        if (ids[0] == 0) throw GlException("glGenTextures returned 0")
        GLES20.glBindTexture(target, ids[0])
        // Clamped on both axes: the grading shader samples a neighbourhood for
        // blur and sharpen, and a repeating wrap would fold the opposite edge
        // of the frame into those taps.
        GLES20.glTexParameteri(target, GLES20.GL_TEXTURE_MIN_FILTER, GLES20.GL_LINEAR)
        GLES20.glTexParameteri(target, GLES20.GL_TEXTURE_MAG_FILTER, GLES20.GL_LINEAR)
        GLES20.glTexParameteri(target, GLES20.GL_TEXTURE_WRAP_S, GLES20.GL_CLAMP_TO_EDGE)
        GLES20.glTexParameteri(target, GLES20.GL_TEXTURE_WRAP_T, GLES20.GL_CLAMP_TO_EDGE)
        GLES20.glBindTexture(target, 0)
        checkGl("createTexture")
        return ids[0]
    }

    fun upload(texture: Int, bitmap: Bitmap) {
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, texture)
        GLUtils.texImage2D(GLES20.GL_TEXTURE_2D, 0, bitmap, 0)
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, 0)
        checkGl("upload")
    }

    /**
     * Uploads packed ARGB straight from an int array.
     *
     * The colour table is generated as ints, and routing it through a Bitmap
     * only to hand it back would allocate four megabytes on every slider
     * movement.
     */
    fun uploadPixels(texture: Int, pixels: IntArray, width: Int, height: Int) {
        val bytes = ByteBuffer.allocateDirect(width * height * 4).order(ByteOrder.nativeOrder())
        for (pixel in pixels) {
            bytes.put(((pixel shr 16) and 0xFF).toByte())
            bytes.put(((pixel shr 8) and 0xFF).toByte())
            bytes.put((pixel and 0xFF).toByte())
            bytes.put(((pixel shr 24) and 0xFF).toByte())
        }
        bytes.position(0)

        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, texture)
        // The table is sampled by exact coordinate, and a row that is not a
        // multiple of four bytes wide would be padded by the default alignment
        // and shear the cube. Four bytes per texel makes that safe, but the
        // alignment is set explicitly so the invariant is visible.
        GLES20.glPixelStorei(GLES20.GL_UNPACK_ALIGNMENT, 4)
        GLES20.glTexImage2D(
            GLES20.GL_TEXTURE_2D, 0, GLES20.GL_RGBA,
            width, height, 0,
            GLES20.GL_RGBA, GLES20.GL_UNSIGNED_BYTE, bytes,
        )
        GLES20.glBindTexture(GLES20.GL_TEXTURE_2D, 0)
        checkGl("uploadPixels")
    }

    fun deleteTexture(texture: Int) {
        if (texture == 0) return
        GLES20.glDeleteTextures(1, intArrayOf(texture), 0)
    }

    fun deleteProgram(program: Int) {
        if (program == 0) return
        GLES20.glDeleteProgram(program)
    }
}
