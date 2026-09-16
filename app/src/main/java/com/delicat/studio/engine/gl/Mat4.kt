package com.delicat.studio.engine.gl

import kotlin.math.cos
import kotlin.math.sin

/**
 * The four-by-four matrix operations the renderers need.
 *
 * `android.opengl.Matrix` does all of this already, but its methods are among
 * those the unit-test android.jar replaces with stubs: under Gradle's test
 * task they quietly do nothing and every matrix comes back as zeroes. Geometry
 * that cannot be tested is geometry that gets silently wrong, and the whole
 * frame hangs off it, so it lives here instead.
 *
 * Column-major, matching OpenGL and the platform class this replaces: element
 * `m[column * 4 + row]`, translation in the last column. Every operation
 * post-multiplies, so the call written last is the one applied first to a
 * vector.
 */
object Mat4 {

    fun identity(m: FloatArray) {
        for (i in 0 until 16) m[i] = 0f
        m[0] = 1f
        m[5] = 1f
        m[10] = 1f
        m[15] = 1f
    }

    /** `out = a * b`. [out] must not alias [a] or [b]. */
    fun multiply(out: FloatArray, a: FloatArray, b: FloatArray) {
        for (column in 0 until 4) {
            for (row in 0 until 4) {
                var sum = 0f
                for (k in 0 until 4) sum += a[k * 4 + row] * b[column * 4 + k]
                out[column * 4 + row] = sum
            }
        }
    }

    fun transform(out: FloatArray, m: FloatArray, x: Float, y: Float, z: Float, w: Float) {
        for (row in 0 until 4) {
            out[row] = m[row] * x + m[4 + row] * y + m[8 + row] * z + m[12 + row] * w
        }
    }

    /** `m = m * translate(x, y, z)`, which only moves the last column. */
    fun translate(m: FloatArray, x: Float, y: Float, z: Float) {
        for (row in 0 until 4) {
            m[12 + row] += m[row] * x + m[4 + row] * y + m[8 + row] * z
        }
    }

    /** `m = m * scale(x, y, z)`. */
    fun scale(m: FloatArray, x: Float, y: Float, z: Float) {
        for (row in 0 until 4) {
            m[row] *= x
            m[4 + row] *= y
            m[8 + row] *= z
        }
    }

    /** `m = m * rotateZ(degrees)`, anticlockwise for a positive angle. */
    fun rotateZ(m: FloatArray, degrees: Float) {
        val radians = degrees * DEGREES_TO_RADIANS
        val c = cos(radians)
        val s = sin(radians)
        for (row in 0 until 4) {
            val first = m[row]
            val second = m[4 + row]
            m[row] = first * c + second * s
            m[4 + row] = second * c - first * s
        }
    }

    private const val DEGREES_TO_RADIANS = 0.017453292f
}
