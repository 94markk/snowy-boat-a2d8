package com.vixel.studio.engine.gl

import android.graphics.Bitmap
import com.vixel.studio.core.model.CurvePoint
import com.vixel.studio.core.model.FilterPreset

/**
 * Bakes a [FilterPreset] into a 64x64x64 colour cube stored as a 512x512 strip
 * (8x8 tiles of 64x64, blue selects the tile). The shader's sampleLut() reads
 * the exact same layout.
 */
object LutGenerator {

    const val SIZE = 64
    const val TEXTURE_DIM = 512
    private const val TILES_PER_ROW = 8

    private val cache = LinkedHashMap<String, Bitmap>(8, 0.75f, true)
    private const val MAX_CACHED = 6

    @Synchronized
    fun lutFor(preset: FilterPreset): Bitmap? {
        if (preset.id == com.vixel.studio.core.model.Filters.NONE_ID) return null
        cache[preset.id]?.let { if (!it.isRecycled) return it }
        val bitmap = generate(preset)
        cache[preset.id] = bitmap
        while (cache.size > MAX_CACHED) {
            val oldestKey = cache.keys.firstOrNull() ?: break
            cache.remove(oldestKey)?.recycle()
        }
        return bitmap
    }

    @Synchronized
    fun clearCache() {
        cache.values.forEach { if (!it.isRecycled) it.recycle() }
        cache.clear()
    }

    fun generate(preset: FilterPreset): Bitmap {
        val pixels = IntArray(TEXTURE_DIM * TEXTURE_DIM)
        val rgb = FloatArray(3)
        val last = (SIZE - 1).toFloat()

        for (b in 0 until SIZE) {
            val tileX = (b % TILES_PER_ROW) * SIZE
            val tileY = (b / TILES_PER_ROW) * SIZE
            val blue = b / last
            for (g in 0 until SIZE) {
                val green = g / last
                val rowBase = (tileY + g) * TEXTURE_DIM + tileX
                for (r in 0 until SIZE) {
                    rgb[0] = r / last
                    rgb[1] = green
                    rgb[2] = blue
                    preset.look(rgb)
                    pixels[rowBase + r] =
                        (0xFF shl 24) or
                            (to8(rgb[0]) shl 16) or
                            (to8(rgb[1]) shl 8) or
                            to8(rgb[2])
                }
            }
        }

        return Bitmap.createBitmap(TEXTURE_DIM, TEXTURE_DIM, Bitmap.Config.ARGB_8888).also {
            it.setPixels(pixels, 0, TEXTURE_DIM, 0, 0, TEXTURE_DIM, TEXTURE_DIM)
        }
    }

    private fun to8(v: Float): Int = ((v.coerceIn(0f, 1f) * 255f) + 0.5f).toInt()
}

/**
 * Builds the 256x1 curve texture. Channels carry the red, green and blue
 * curves; alpha carries the master curve, which the shader applies last.
 */
object CurveLut {

    const val WIDTH = 256

    fun isIdentity(
        master: List<CurvePoint>,
        red: List<CurvePoint>,
        green: List<CurvePoint>,
        blue: List<CurvePoint>,
    ): Boolean = master.isEmpty() && red.isEmpty() && green.isEmpty() && blue.isEmpty()

    fun build(
        master: List<CurvePoint>,
        red: List<CurvePoint>,
        green: List<CurvePoint>,
        blue: List<CurvePoint>,
    ): Bitmap {
        val r = ramp(red)
        val g = ramp(green)
        val b = ramp(blue)
        val m = ramp(master)

        val pixels = IntArray(WIDTH)
        for (i in 0 until WIDTH) {
            pixels[i] = (to8(m[i]) shl 24) or (to8(r[i]) shl 16) or (to8(g[i]) shl 8) or to8(b[i])
        }
        return Bitmap.createBitmap(WIDTH, 1, Bitmap.Config.ARGB_8888).also {
            it.setPixels(pixels, 0, WIDTH, 0, 0, WIDTH, 1)
        }
    }

    /**
     * Monotone cubic (Fritsch-Carlson) interpolation through the control
     * points. Monotone matters here: a plain spline overshoots and puts kinks
     * or inversions in a tone curve the user did not ask for.
     */
    fun ramp(points: List<CurvePoint>): FloatArray {
        val out = FloatArray(WIDTH)
        if (points.size < 2) {
            for (i in 0 until WIDTH) out[i] = i / (WIDTH - 1f)
            return out
        }

        val sorted = points.sortedBy { it.x }
        val n = sorted.size
        val xs = FloatArray(n) { sorted[it].x }
        val ys = FloatArray(n) { sorted[it].y }

        val slope = FloatArray(n - 1)
        for (i in 0 until n - 1) {
            val dx = (xs[i + 1] - xs[i]).takeIf { it > 1e-6f } ?: 1e-6f
            slope[i] = (ys[i + 1] - ys[i]) / dx
        }

        val tangent = FloatArray(n)
        tangent[0] = slope[0]
        tangent[n - 1] = slope[n - 2]
        for (i in 1 until n - 1) {
            tangent[i] = if (slope[i - 1] * slope[i] <= 0f) 0f else (slope[i - 1] + slope[i]) * 0.5f
        }
        // Clamp tangents so each segment stays monotone.
        for (i in 0 until n - 1) {
            if (slope[i] == 0f) {
                tangent[i] = 0f
                tangent[i + 1] = 0f
            } else {
                val a = tangent[i] / slope[i]
                val b = tangent[i + 1] / slope[i]
                val s = a * a + b * b
                if (s > 9f) {
                    val t = 3f / kotlin.math.sqrt(s)
                    tangent[i] = t * a * slope[i]
                    tangent[i + 1] = t * b * slope[i]
                }
            }
        }

        for (i in 0 until WIDTH) {
            val x = i / (WIDTH - 1f)
            out[i] = when {
                x <= xs[0] -> ys[0]
                x >= xs[n - 1] -> ys[n - 1]
                else -> {
                    var k = 0
                    while (k < n - 2 && x > xs[k + 1]) k++
                    val h = (xs[k + 1] - xs[k]).takeIf { it > 1e-6f } ?: 1e-6f
                    val t = (x - xs[k]) / h
                    val t2 = t * t
                    val t3 = t2 * t
                    val h00 = 2f * t3 - 3f * t2 + 1f
                    val h10 = t3 - 2f * t2 + t
                    val h01 = -2f * t3 + 3f * t2
                    val h11 = t3 - t2
                    h00 * ys[k] + h10 * h * tangent[k] + h01 * ys[k + 1] + h11 * h * tangent[k + 1]
                }
            }.coerceIn(0f, 1f)
        }
        return out
    }

    private fun to8(v: Float): Int = ((v.coerceIn(0f, 1f) * 255f) + 0.5f).toInt()
}
