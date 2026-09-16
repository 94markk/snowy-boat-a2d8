package com.delicat.studio.model

import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow

/**
 * Looks are pure RGB to RGB functions, baked into a lookup table at runtime.
 *
 * Nothing binary ships with the app, so each look stays readable and editable
 * in source and costs nothing in APK size. It also means one definition serves
 * both consumers: the preview samples the baked table and export samples the
 * same one, so a look cannot render differently on screen and in the file.
 */
class Look(
    val id: String,
    val name: String,
    val category: LookCategory,
    /** Transforms rgb, each 0..1, in place. */
    val apply: (FloatArray) -> Unit,
)

enum class LookCategory(val label: String) {
    BASIC("Basic"),
    PORTRAIT("Portrait"),
    FILM("Film"),
    CINEMA("Cinema"),
    MONO("Mono"),
    VIBE("Vibe"),
}

/** The operations the looks are composed from. */
object Op {
    fun clamp01(v: Float): Float = if (v < 0f) 0f else if (v > 1f) 1f else v

    fun luma(c: FloatArray): Float = 0.2126f * c[0] + 0.7152f * c[1] + 0.0722f * c[2]

    fun contrast(c: FloatArray, amount: Float) {
        for (i in 0..2) c[i] = clamp01((c[i] - 0.5f) * (1f + amount) + 0.5f)
    }

    fun saturate(c: FloatArray, amount: Float) {
        val l = luma(c)
        for (i in 0..2) c[i] = clamp01(l + (c[i] - l) * (1f + amount))
    }

    fun gamma(c: FloatArray, g: Float) {
        for (i in 0..2) c[i] = clamp01(max(c[i], 0f).pow(g))
    }

    fun smoothstep(e0: Float, e1: Float, x: Float): Float {
        val t = ((x - e0) / (e1 - e0)).coerceIn(0f, 1f)
        return t * t * (3f - 2f * t)
    }

    /** Tints shadows and highlights in opposite directions. */
    fun splitTone(c: FloatArray, shadow: FloatArray, highlight: FloatArray, strength: Float) {
        val l = luma(c)
        val hi = smoothstep(0.45f, 1f, l)
        val lo = 1f - smoothstep(0f, 0.55f, l)
        for (i in 0..2) {
            c[i] = clamp01(
                c[i] + (shadow[i] - 0.5f) * lo * strength + (highlight[i] - 0.5f) * hi * strength,
            )
        }
    }

    fun mono(c: FloatArray, rw: Float = 0.299f, gw: Float = 0.587f, bw: Float = 0.114f) {
        val v = clamp01(c[0] * rw + c[1] * gw + c[2] * bw)
        c[0] = v; c[1] = v; c[2] = v
    }

    fun warm(c: FloatArray, amount: Float) {
        c[0] = clamp01(c[0] + amount * 0.14f)
        c[2] = clamp01(c[2] - amount * 0.14f)
    }

    /** Lifts the black point, the defining move of a faded look. */
    fun lift(c: FloatArray, amount: Float) {
        for (i in 0..2) c[i] = clamp01(c[i] * (1f - amount * 0.18f) + amount * 0.16f)
    }

    /** Filmic S-curve; 0 is identity. */
    fun sCurve(c: FloatArray, amount: Float) {
        for (i in 0..2) {
            val x = c[i]
            val s = x * x * (3f - 2f * x)
            c[i] = clamp01(x + (s - x) * amount)
        }
    }

    fun channels(c: FloatArray, r: Float, g: Float, b: Float) {
        c[0] = clamp01(c[0] * r)
        c[1] = clamp01(c[1] * g)
        c[2] = clamp01(c[2] * b)
    }

    fun rgbToHsl(c: FloatArray, out: FloatArray) {
        val maxc = max(c[0], max(c[1], c[2]))
        val minc = min(c[0], min(c[1], c[2]))
        val l = (maxc + minc) * 0.5f
        var h = 0f
        var s = 0f
        val d = maxc - minc
        if (d > 1e-5f) {
            s = if (l > 0.5f) d / (2f - maxc - minc) else d / (maxc + minc)
            h = when (maxc) {
                c[0] -> (c[1] - c[2]) / d + if (c[1] < c[2]) 6f else 0f
                c[1] -> (c[2] - c[0]) / d + 2f
                else -> (c[0] - c[1]) / d + 4f
            } / 6f
        }
        out[0] = h; out[1] = s; out[2] = l
    }

    private fun hueToRgb(p: Float, q: Float, tIn: Float): Float {
        var t = tIn
        if (t < 0f) t += 1f
        if (t > 1f) t -= 1f
        return when {
            t < 1f / 6f -> p + (q - p) * 6f * t
            t < 1f / 2f -> q
            t < 2f / 3f -> p + (q - p) * (2f / 3f - t) * 6f
            else -> p
        }
    }

    fun hslToRgb(hsl: FloatArray, out: FloatArray) {
        val h = hsl[0]; val s = hsl[1]; val l = hsl[2]
        if (s < 1e-5f) {
            out[0] = l; out[1] = l; out[2] = l
            return
        }
        val q = if (l < 0.5f) l * (1f + s) else l + s - l * s
        val p = 2f * l - q
        out[0] = hueToRgb(p, q, h + 1f / 3f)
        out[1] = hueToRgb(p, q, h)
        out[2] = hueToRgb(p, q, h - 1f / 3f)
    }
}

object Looks {
    const val NONE_ID = "none"

    val ALL: List<Look> = listOf(
        Look(NONE_ID, "Original", LookCategory.BASIC) { },

        Look("crisp", "Crisp", LookCategory.BASIC) { c ->
            Op.contrast(c, 0.14f); Op.saturate(c, 0.10f); Op.sCurve(c, 0.25f)
        },
        Look("soft", "Soft", LookCategory.BASIC) { c ->
            Op.contrast(c, -0.10f); Op.lift(c, 0.35f); Op.saturate(c, -0.05f)
        },
        Look("punch", "Punch", LookCategory.BASIC) { c ->
            Op.contrast(c, 0.28f); Op.saturate(c, 0.30f); Op.sCurve(c, 0.35f)
        },

        Look("natural", "Natural", LookCategory.PORTRAIT) { c ->
            Op.warm(c, 0.10f); Op.saturate(c, 0.06f); Op.sCurve(c, 0.15f)
        },
        Look("glow", "Glow", LookCategory.PORTRAIT) { c ->
            Op.warm(c, 0.14f); Op.lift(c, 0.20f); Op.saturate(c, 0.08f)
            Op.splitTone(c, floatArrayOf(0.5f, 0.5f, 0.54f), floatArrayOf(0.56f, 0.52f, 0.46f), 0.35f)
        },
        Look("honey", "Honey", LookCategory.PORTRAIT) { c ->
            Op.warm(c, 0.22f); Op.contrast(c, 0.08f)
            Op.splitTone(c, floatArrayOf(0.48f, 0.49f, 0.55f), floatArrayOf(0.60f, 0.53f, 0.42f), 0.5f)
        },
        Look("porcelain", "Porcelain", LookCategory.PORTRAIT) { c ->
            Op.saturate(c, -0.18f); Op.lift(c, 0.28f); Op.contrast(c, 0.06f); Op.warm(c, 0.06f)
        },

        Look("kodak", "Kodak", LookCategory.FILM) { c ->
            Op.contrast(c, 0.18f); Op.saturate(c, 0.22f)
            Op.channels(c, 1.04f, 0.99f, 0.92f)
            Op.splitTone(c, floatArrayOf(0.47f, 0.50f, 0.56f), floatArrayOf(0.57f, 0.52f, 0.44f), 0.35f)
        },
        Look("portra", "Portra", LookCategory.FILM) { c ->
            Op.lift(c, 0.22f); Op.saturate(c, -0.06f); Op.warm(c, 0.12f)
            Op.splitTone(c, floatArrayOf(0.49f, 0.50f, 0.53f), floatArrayOf(0.55f, 0.51f, 0.47f), 0.4f)
        },
        Look("fuji", "Fuji", LookCategory.FILM) { c ->
            Op.saturate(c, 0.14f); Op.contrast(c, 0.12f); Op.channels(c, 0.97f, 1.03f, 1.02f)
        },
        Look("expired", "Expired", LookCategory.FILM) { c ->
            Op.lift(c, 0.45f); Op.saturate(c, -0.22f)
            Op.splitTone(c, floatArrayOf(0.54f, 0.50f, 0.44f), floatArrayOf(0.48f, 0.52f, 0.55f), 0.55f)
            Op.contrast(c, -0.06f)
        },

        Look("blockbuster", "Blockbuster", LookCategory.CINEMA) { c ->
            Op.contrast(c, 0.16f)
            Op.splitTone(c, floatArrayOf(0.42f, 0.50f, 0.60f), floatArrayOf(0.60f, 0.51f, 0.40f), 0.7f)
            Op.saturate(c, 0.08f)
        },
        Look("midnight", "Midnight", LookCategory.CINEMA) { c ->
            Op.contrast(c, 0.22f); Op.saturate(c, -0.30f)
            Op.splitTone(c, floatArrayOf(0.44f, 0.48f, 0.60f), floatArrayOf(0.50f, 0.51f, 0.55f), 0.6f)
            Op.gamma(c, 1.12f)
        },
        Look("desert", "Desert", LookCategory.CINEMA) { c ->
            Op.warm(c, 0.20f); Op.saturate(c, -0.08f); Op.contrast(c, 0.10f)
            Op.splitTone(c, floatArrayOf(0.52f, 0.50f, 0.46f), floatArrayOf(0.58f, 0.53f, 0.44f), 0.45f)
        },
        Look("neon", "Neon", LookCategory.CINEMA) { c ->
            Op.contrast(c, 0.20f); Op.saturate(c, 0.35f)
            Op.splitTone(c, floatArrayOf(0.46f, 0.46f, 0.62f), floatArrayOf(0.62f, 0.46f, 0.58f), 0.6f)
        },

        Look("mono", "Mono", LookCategory.MONO) { c ->
            Op.mono(c); Op.contrast(c, 0.12f)
        },
        Look("high_key", "High key", LookCategory.MONO) { c ->
            Op.mono(c); Op.contrast(c, 0.42f); Op.sCurve(c, 0.4f)
        },
        Look("silver", "Silver", LookCategory.MONO) { c ->
            Op.mono(c); Op.lift(c, 0.35f); Op.contrast(c, -0.05f)
        },
        Look("sepia", "Sepia", LookCategory.MONO) { c ->
            Op.mono(c)
            c[0] = Op.clamp01(c[0] * 1.12f + 0.04f)
            c[1] = Op.clamp01(c[1] * 1.00f + 0.01f)
            c[2] = Op.clamp01(c[2] * 0.82f)
        },

        Look("vhs", "VHS", LookCategory.VIBE) { c ->
            Op.lift(c, 0.4f); Op.saturate(c, 0.18f)
            Op.splitTone(c, floatArrayOf(0.54f, 0.48f, 0.56f), floatArrayOf(0.48f, 0.54f, 0.52f), 0.5f)
        },
        Look("dream", "Dream", LookCategory.VIBE) { c ->
            Op.lift(c, 0.5f); Op.saturate(c, -0.12f); Op.warm(c, 0.08f); Op.gamma(c, 0.92f)
        },
        Look("cotton", "Cotton", LookCategory.VIBE) { c ->
            Op.lift(c, 0.32f)
            Op.splitTone(c, floatArrayOf(0.55f, 0.49f, 0.53f), floatArrayOf(0.53f, 0.51f, 0.56f), 0.6f)
            Op.saturate(c, 0.05f)
        },
        Look("acid", "Acid", LookCategory.VIBE) { c ->
            Op.saturate(c, 0.55f); Op.contrast(c, 0.24f); Op.channels(c, 1.05f, 1.02f, 0.9f)
        },
    )

    val BY_ID: Map<String, Look> = ALL.associateBy { it.id }

    fun get(id: String): Look = BY_ID[id] ?: ALL.first()

    fun inCategory(category: LookCategory): List<Look> = ALL.filter { it.category == category }
}
