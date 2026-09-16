package com.delicat.studio.engine

import com.delicat.studio.model.Adjustments
import com.delicat.studio.model.Looks
import com.delicat.studio.model.Op
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow

/**
 * Bakes every colour-only operation into one lookup table.
 *
 * This is the single place colour is defined. The preview samples the table
 * and export samples the same table, so the screen and the file cannot
 * disagree: there is one implementation of the maths and two consumers of its
 * output, rather than two implementations kept in step by hand.
 *
 * Only operations of the form "colour in, colour out" belong here. Blur,
 * sharpen, vignette, grain and glow depend on where a pixel sits rather than
 * what colour it is, so the shader applies those directly.
 *
 * The table is laid out as a strip of NxN tiles, [TILES_ACROSS] wide: blue
 * picks the tile, red and green index within it. That shape is what lets a
 * three-dimensional table live in an ordinary two-dimensional texture.
 */
object LutBaker {

    /** Edge length of the cube. 32 is indistinguishable from 64 at phone size
     *  and costs an eighth of the work, which is the difference between a
     *  slider that tracks the finger and one that lurches. */
    const val SIZE = 32
    const val TILES_ACROSS = 8

    val stripWidth: Int get() = SIZE * TILES_ACROSS
    val stripHeight: Int get() = SIZE * ((SIZE + TILES_ACROSS - 1) / TILES_ACROSS)

    /**
     * Applies the full colour chain to one RGB triple, each 0..1, in place.
     *
     * The order follows what a photographer expects, and it matters: white
     * balance before exposure, tonal ranges before contrast, vibrance before
     * saturation so it can protect pixels that are already saturated, and the
     * look last so it sits on a corrected image rather than fighting the
     * corrections.
     *
     * Writes into [c] and allocates nothing: this runs once per cube entry,
     * so an allocation here is thirty thousand allocations per bake.
     */
    fun gradePixel(a: Adjustments, c: FloatArray, hsl: FloatArray, tmp: FloatArray) {
        if (a.temperature != 0f) {
            c[0] += a.temperature * 0.16f
            c[1] += a.temperature * 0.02f
            c[2] -= a.temperature * 0.16f
        }
        if (a.tint != 0f) {
            c[0] += a.tint * 0.08f
            c[1] -= a.tint * 0.10f
            c[2] += a.tint * 0.08f
        }

        if (a.exposure != 0f) {
            val gain = 2f.pow(a.exposure)
            for (i in 0..2) c[i] *= gain
        }

        val l = Op.luma(c)
        if (a.highlights != 0f) {
            val w = a.highlights * 0.45f * Op.smoothstep(0.45f, 1f, l)
            for (i in 0..2) c[i] += w
        }
        if (a.shadows != 0f) {
            val w = a.shadows * 0.45f * (1f - Op.smoothstep(0f, 0.55f, l))
            for (i in 0..2) c[i] += w
        }
        if (a.whites != 0f) {
            val w = a.whites * 0.30f * Op.smoothstep(0.65f, 1f, l)
            for (i in 0..2) c[i] += w
        }
        if (a.blacks != 0f) {
            val w = a.blacks * 0.30f * (1f - Op.smoothstep(0f, 0.35f, l))
            for (i in 0..2) c[i] += w
        }

        if (a.contrast != 0f) Op.contrast(c, a.contrast)
        if (a.brightness != 0f) for (i in 0..2) c[i] += a.brightness * 0.35f
        for (i in 0..2) c[i] = Op.clamp01(c[i])

        if (a.hue != 0f) {
            Op.rgbToHsl(c, hsl)
            hsl[0] = ((hsl[0] + a.hue / 360f) % 1f + 1f) % 1f
            Op.hslToRgb(hsl, c)
        }

        if (a.vibrance != 0f) {
            val mx = max(c[0], max(c[1], c[2]))
            val mn = min(c[0], min(c[1], c[2]))
            val sat = mx - mn
            val g = Op.luma(c)
            val amount = 1f + a.vibrance * (1f - sat)
            for (i in 0..2) c[i] = Op.clamp01(g + (c[i] - g) * amount)
        }
        if (a.saturation != 0f) Op.saturate(c, a.saturation)
        for (i in 0..2) c[i] = Op.clamp01(c[i])

        if (a.lookId != Looks.NONE_ID && a.lookStrength > 0f) {
            tmp[0] = c[0]; tmp[1] = c[1]; tmp[2] = c[2]
            Looks.get(a.lookId).apply(tmp)
            val t = a.lookStrength.coerceIn(0f, 1f)
            for (i in 0..2) c[i] += (tmp[i] - c[i]) * t
        }

        if (a.fade > 0f) {
            val t = a.fade.coerceIn(0f, 1f)
            for (i in 0..2) c[i] += ((c[i] * 0.82f + 0.16f) - c[i]) * t
        }

        for (i in 0..2) c[i] = Op.clamp01(c[i])
    }

    /**
     * The table as an RGBA strip, ready to upload as a texture.
     *
     * Reuses three small scratch arrays across every entry rather than
     * allocating per pixel; at thirty-odd thousand entries that is the
     * difference between a bake the garbage collector notices and one it does
     * not.
     */
    fun bake(a: Adjustments): IntArray {
        val width = stripWidth
        val height = stripHeight
        val pixels = IntArray(width * height)

        val c = FloatArray(3)
        val hsl = FloatArray(3)
        val tmp = FloatArray(3)
        val scale = (SIZE - 1).toFloat()

        for (b in 0 until SIZE) {
            val tileX = (b % TILES_ACROSS) * SIZE
            val tileY = (b / TILES_ACROSS) * SIZE
            for (g in 0 until SIZE) {
                for (r in 0 until SIZE) {
                    c[0] = r / scale
                    c[1] = g / scale
                    c[2] = b / scale
                    gradePixel(a, c, hsl, tmp)

                    val red = (c[0] * 255f + 0.5f).toInt().coerceIn(0, 255)
                    val green = (c[1] * 255f + 0.5f).toInt().coerceIn(0, 255)
                    val blue = (c[2] * 255f + 0.5f).toInt().coerceIn(0, 255)
                    // Packed the way GLUtils.texImage2D expects from an
                    // ARGB_8888 bitmap.
                    pixels[(tileY + g) * width + tileX + r] =
                        (0xFF shl 24) or (red shl 16) or (green shl 8) or blue
                }
            }
        }
        return pixels
    }

    /** Convenience for tests and diagnostics: one graded colour. */
    fun gradeOnce(a: Adjustments, r: Float, g: Float, b: Float): FloatArray {
        val c = floatArrayOf(r, g, b)
        gradePixel(a, c, FloatArray(3), FloatArray(3))
        return c
    }
}
