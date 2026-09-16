package com.vixel.studio.core.model

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow

/**
 * Looks are defined as pure RGB -> RGB functions and baked into a 64^3 LUT at
 * runtime (see LutGenerator). No binary LUT assets ship with the app, so every
 * look is readable, tweakable, and costs nothing in APK size.
 */
data class FilterPreset(
    val id: String,
    val name: String,
    val category: Category,
    /** Transforms `rgb` (0..1) in place. */
    val look: (FloatArray) -> Unit,
) {
    enum class Category(val label: String) {
        BASIC("Basic"),
        PORTRAIT("Portrait"),
        FILM("Film"),
        CINEMA("Cinema"),
        MONO("B&W"),
        VIBE("Vibe"),
    }
}

/** Small library of colour operations the presets are composed from. */
object Look {
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
        for (i in 0..2) c[i] = clamp01(c[i].coerceAtLeast(0f).pow(g))
    }

    /** Lift/gamma/gain per channel — the classic three-way colour balance. */
    fun lgg(c: FloatArray, lift: FloatArray, gamma: FloatArray, gain: FloatArray) {
        for (i in 0..2) {
            var v = c[i] * gain[i] + lift[i]
            v = clamp01(v).pow(gamma[i])
            c[i] = clamp01(v)
        }
    }

    /** Tints shadows and highlights in opposite directions. */
    fun splitTone(c: FloatArray, shadow: FloatArray, highlight: FloatArray, strength: Float) {
        val l = luma(c)
        val hi = smoothstep(0.45f, 1f, l)
        val lo = 1f - smoothstep(0f, 0.55f, l)
        for (i in 0..2) {
            c[i] = clamp01(c[i] + (shadow[i] - 0.5f) * lo * strength + (highlight[i] - 0.5f) * hi * strength)
        }
    }

    fun mono(c: FloatArray, rw: Float = 0.299f, gw: Float = 0.587f, bw: Float = 0.114f) {
        val v = clamp01(c[0] * rw + c[1] * gw + c[2] * bw)
        c[0] = v; c[1] = v; c[2] = v
    }

    fun temperature(c: FloatArray, amount: Float) {
        c[0] = clamp01(c[0] + amount * 0.14f)
        c[2] = clamp01(c[2] - amount * 0.14f)
    }

    /** Lifts the black point, the defining move of a faded/matte look. */
    fun fade(c: FloatArray, amount: Float) {
        for (i in 0..2) c[i] = clamp01(c[i] * (1f - amount * 0.18f) + amount * 0.16f)
    }

    fun smoothstep(e0: Float, e1: Float, x: Float): Float {
        val t = ((x - e0) / (e1 - e0)).coerceIn(0f, 1f)
        return t * t * (3f - 2f * t)
    }

    /** Filmic S-curve; `amount` 0 is identity. */
    fun sCurve(c: FloatArray, amount: Float) {
        for (i in 0..2) {
            val x = c[i]
            val s = x * x * (3f - 2f * x)
            c[i] = clamp01(x + (s - x) * amount)
        }
    }

    fun channelMix(c: FloatArray, rr: Float, gg: Float, bb: Float) {
        c[0] = clamp01(c[0] * rr)
        c[1] = clamp01(c[1] * gg)
        c[2] = clamp01(c[2] * bb)
    }
}

object Filters {
    const val NONE_ID = "none"

    private fun f(id: String, name: String, cat: FilterPreset.Category, look: (FloatArray) -> Unit) =
        FilterPreset(id, name, cat, look)

    val ALL: List<FilterPreset> = listOf(
        f(NONE_ID, "Original", FilterPreset.Category.BASIC) { },

        f("crisp", "Crisp", FilterPreset.Category.BASIC) { c ->
            Look.contrast(c, 0.14f); Look.saturate(c, 0.10f); Look.sCurve(c, 0.25f)
        },
        f("soft", "Soft", FilterPreset.Category.BASIC) { c ->
            Look.contrast(c, -0.10f); Look.fade(c, 0.35f); Look.saturate(c, -0.05f)
        },
        f("punch", "Punch", FilterPreset.Category.BASIC) { c ->
            Look.contrast(c, 0.28f); Look.saturate(c, 0.30f); Look.sCurve(c, 0.35f)
        },

        f("natural", "Natural", FilterPreset.Category.PORTRAIT) { c ->
            Look.temperature(c, 0.10f); Look.saturate(c, 0.06f); Look.sCurve(c, 0.15f)
        },
        f("glow", "Glow", FilterPreset.Category.PORTRAIT) { c ->
            Look.temperature(c, 0.14f); Look.fade(c, 0.20f); Look.saturate(c, 0.08f)
            Look.splitTone(c, floatArrayOf(0.5f, 0.5f, 0.54f), floatArrayOf(0.56f, 0.52f, 0.46f), 0.35f)
        },
        f("honey", "Honey", FilterPreset.Category.PORTRAIT) { c ->
            Look.temperature(c, 0.22f); Look.contrast(c, 0.08f)
            Look.splitTone(c, floatArrayOf(0.48f, 0.49f, 0.55f), floatArrayOf(0.60f, 0.53f, 0.42f), 0.5f)
        },
        f("porcelain", "Porcelain", FilterPreset.Category.PORTRAIT) { c ->
            Look.saturate(c, -0.18f); Look.fade(c, 0.28f); Look.contrast(c, 0.06f)
            Look.temperature(c, 0.06f)
        },

        f("kodachrome", "Kodak", FilterPreset.Category.FILM) { c ->
            Look.contrast(c, 0.18f); Look.saturate(c, 0.22f)
            Look.channelMix(c, 1.04f, 0.99f, 0.92f)
            Look.splitTone(c, floatArrayOf(0.47f, 0.50f, 0.56f), floatArrayOf(0.57f, 0.52f, 0.44f), 0.35f)
        },
        f("portra", "Portra", FilterPreset.Category.FILM) { c ->
            Look.fade(c, 0.22f); Look.saturate(c, -0.06f); Look.temperature(c, 0.12f)
            Look.splitTone(c, floatArrayOf(0.49f, 0.50f, 0.53f), floatArrayOf(0.55f, 0.51f, 0.47f), 0.4f)
        },
        f("fuji", "Fuji", FilterPreset.Category.FILM) { c ->
            Look.saturate(c, 0.14f); Look.contrast(c, 0.12f)
            Look.channelMix(c, 0.97f, 1.03f, 1.02f)
        },
        f("expired", "Expired", FilterPreset.Category.FILM) { c ->
            Look.fade(c, 0.45f); Look.saturate(c, -0.22f)
            Look.splitTone(c, floatArrayOf(0.54f, 0.50f, 0.44f), floatArrayOf(0.48f, 0.52f, 0.55f), 0.55f)
            Look.contrast(c, -0.06f)
        },

        f("teal_orange", "Blockbuster", FilterPreset.Category.CINEMA) { c ->
            Look.contrast(c, 0.16f)
            Look.splitTone(c, floatArrayOf(0.42f, 0.50f, 0.60f), floatArrayOf(0.60f, 0.51f, 0.40f), 0.7f)
            Look.saturate(c, 0.08f)
        },
        f("noir_blue", "Midnight", FilterPreset.Category.CINEMA) { c ->
            Look.contrast(c, 0.22f); Look.saturate(c, -0.30f)
            Look.splitTone(c, floatArrayOf(0.44f, 0.48f, 0.60f), floatArrayOf(0.50f, 0.51f, 0.55f), 0.6f)
            Look.gamma(c, 1.12f)
        },
        f("desert", "Desert", FilterPreset.Category.CINEMA) { c ->
            Look.temperature(c, 0.20f); Look.saturate(c, -0.08f); Look.contrast(c, 0.10f)
            Look.splitTone(c, floatArrayOf(0.52f, 0.50f, 0.46f), floatArrayOf(0.58f, 0.53f, 0.44f), 0.45f)
        },
        f("neon", "Neon", FilterPreset.Category.CINEMA) { c ->
            Look.contrast(c, 0.20f); Look.saturate(c, 0.35f)
            Look.splitTone(c, floatArrayOf(0.46f, 0.46f, 0.62f), floatArrayOf(0.62f, 0.46f, 0.58f), 0.6f)
        },

        f("mono", "Mono", FilterPreset.Category.MONO) { c ->
            Look.mono(c); Look.contrast(c, 0.12f)
        },
        f("mono_hard", "High key", FilterPreset.Category.MONO) { c ->
            Look.mono(c); Look.contrast(c, 0.42f); Look.sCurve(c, 0.4f)
        },
        f("mono_soft", "Silver", FilterPreset.Category.MONO) { c ->
            Look.mono(c); Look.fade(c, 0.35f); Look.contrast(c, -0.05f)
        },
        f("sepia", "Sepia", FilterPreset.Category.MONO) { c ->
            Look.mono(c)
            c[0] = Look.clamp01(c[0] * 1.12f + 0.04f)
            c[1] = Look.clamp01(c[1] * 1.00f + 0.01f)
            c[2] = Look.clamp01(c[2] * 0.82f)
        },

        f("vhs", "VHS", FilterPreset.Category.VIBE) { c ->
            Look.fade(c, 0.4f); Look.saturate(c, 0.18f)
            Look.splitTone(c, floatArrayOf(0.54f, 0.48f, 0.56f), floatArrayOf(0.48f, 0.54f, 0.52f), 0.5f)
        },
        f("dream", "Dream", FilterPreset.Category.VIBE) { c ->
            Look.fade(c, 0.5f); Look.saturate(c, -0.12f); Look.temperature(c, 0.08f)
            Look.gamma(c, 0.92f)
        },
        f("cotton", "Cotton", FilterPreset.Category.VIBE) { c ->
            Look.fade(c, 0.32f)
            Look.splitTone(c, floatArrayOf(0.55f, 0.49f, 0.53f), floatArrayOf(0.53f, 0.51f, 0.56f), 0.6f)
            Look.saturate(c, 0.05f)
        },
        f("acid", "Acid", FilterPreset.Category.VIBE) { c ->
            Look.saturate(c, 0.55f); Look.contrast(c, 0.24f)
            Look.channelMix(c, 1.05f, 1.02f, 0.9f)
        },
    )

    val BY_ID: Map<String, FilterPreset> = ALL.associateBy { it.id }

    fun get(id: String): FilterPreset = BY_ID[id] ?: ALL.first()

    fun categories(): List<FilterPreset.Category> = FilterPreset.Category.entries.toList()
}
