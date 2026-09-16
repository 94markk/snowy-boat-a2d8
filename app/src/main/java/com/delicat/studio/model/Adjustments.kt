package com.delicat.studio.model

/**
 * Every colour parameter the engine understands.
 *
 * This is the single source of truth. The UI builds its sliders from
 * [AdjustSpec.ALL], the LUT baker reads the same ids, and export reuses the
 * identical struct. There is no second path a value can travel, which is what
 * makes a control that appears to do nothing impossible by construction rather
 * than merely unlikely.
 *
 * Adding a parameter means one entry in [AdjustSpec.ALL] and one line in the
 * bake function. The slider appears on its own.
 */
data class Adjustments(
    // Light
    val exposure: Float = 0f,       // EV, -3..3
    val brightness: Float = 0f,     // -1..1
    val contrast: Float = 0f,       // -1..1
    val highlights: Float = 0f,     // -1..1
    val shadows: Float = 0f,        // -1..1
    val whites: Float = 0f,         // -1..1
    val blacks: Float = 0f,         // -1..1

    // Colour
    val saturation: Float = 0f,     // -1..1, -1 is greyscale
    val vibrance: Float = 0f,       // -1..1, weighted to muted tones
    val temperature: Float = 0f,    // -1..1, blue to amber
    val tint: Float = 0f,           // -1..1, green to magenta
    val hue: Float = 0f,            // -180..180 degrees

    // Texture and atmosphere. These depend on where a pixel sits rather than
    // what colour it is, so they cannot be baked into a lookup table and are
    // applied by the shader directly.
    val sharpen: Float = 0f,        // 0..1
    val blur: Float = 0f,           // 0..1
    val grain: Float = 0f,          // 0..1
    val fade: Float = 0f,           // 0..1, lifted blacks
    val vignette: Float = 0f,       // -1..1, negative brightens the edge
    val glow: Float = 0f,           // 0..1

    val lookId: String = Looks.NONE_ID,
    val lookStrength: Float = 1f,   // 0..1
) {
    fun get(id: String): Float = when (id) {
        EXPOSURE -> exposure
        BRIGHTNESS -> brightness
        CONTRAST -> contrast
        HIGHLIGHTS -> highlights
        SHADOWS -> shadows
        WHITES -> whites
        BLACKS -> blacks
        SATURATION -> saturation
        VIBRANCE -> vibrance
        TEMPERATURE -> temperature
        TINT -> tint
        HUE -> hue
        SHARPEN -> sharpen
        BLUR -> blur
        GRAIN -> grain
        FADE -> fade
        VIGNETTE -> vignette
        GLOW -> glow
        LOOK_STRENGTH -> lookStrength
        else -> 0f
    }

    fun set(id: String, value: Float): Adjustments = when (id) {
        EXPOSURE -> copy(exposure = value)
        BRIGHTNESS -> copy(brightness = value)
        CONTRAST -> copy(contrast = value)
        HIGHLIGHTS -> copy(highlights = value)
        SHADOWS -> copy(shadows = value)
        WHITES -> copy(whites = value)
        BLACKS -> copy(blacks = value)
        SATURATION -> copy(saturation = value)
        VIBRANCE -> copy(vibrance = value)
        TEMPERATURE -> copy(temperature = value)
        TINT -> copy(tint = value)
        HUE -> copy(hue = value)
        SHARPEN -> copy(sharpen = value)
        BLUR -> copy(blur = value)
        GRAIN -> copy(grain = value)
        FADE -> copy(fade = value)
        VIGNETTE -> copy(vignette = value)
        GLOW -> copy(glow = value)
        LOOK_STRENGTH -> copy(lookStrength = value)
        else -> this
    }

    /** True when applying this would change nothing. */
    val isNeutral: Boolean
        get() = AdjustSpec.ALL.all { get(it.id) == it.default } &&
            lookId == Looks.NONE_ID

    /**
     * True when only the spatial parameters differ from neutral.
     *
     * Worth knowing because those are the ones a lookup table cannot express:
     * if everything else is neutral the table can be skipped entirely.
     */
    val hasColourWork: Boolean
        get() = lookId != Looks.NONE_ID || AdjustSpec.COLOUR_IDS.any { id ->
            get(id) != (AdjustSpec.BY_ID[id]?.default ?: 0f)
        }

    companion object {
        const val EXPOSURE = "exposure"
        const val BRIGHTNESS = "brightness"
        const val CONTRAST = "contrast"
        const val HIGHLIGHTS = "highlights"
        const val SHADOWS = "shadows"
        const val WHITES = "whites"
        const val BLACKS = "blacks"
        const val SATURATION = "saturation"
        const val VIBRANCE = "vibrance"
        const val TEMPERATURE = "temperature"
        const val TINT = "tint"
        const val HUE = "hue"
        const val SHARPEN = "sharpen"
        const val BLUR = "blur"
        const val GRAIN = "grain"
        const val FADE = "fade"
        const val VIGNETTE = "vignette"
        const val GLOW = "glow"
        const val LOOK_STRENGTH = "lookStrength"
    }
}

enum class AdjustGroup(val label: String) {
    LIGHT("Light"),
    COLOUR("Colour"),
    TEXTURE("Texture"),
    EFFECT("Effect"),
}

/** How one scalar parameter is presented and bounded. */
data class AdjustSpec(
    val id: String,
    val label: String,
    val min: Float,
    val max: Float,
    val default: Float,
    val group: AdjustGroup,
    /** The number shown to the user is `value * displayScale`. */
    val displayScale: Float = 100f,
    val unit: String = "",
) {
    fun display(value: Float): String {
        val scaled = value * displayScale
        return if (displayScale == 1f) {
            "${"%.1f".format(scaled)}$unit"
        } else {
            "${scaled.toInt()}$unit"
        }
    }

    companion object {
        val ALL: List<AdjustSpec> = listOf(
            AdjustSpec(Adjustments.EXPOSURE, "Exposure", -3f, 3f, 0f, AdjustGroup.LIGHT, 1f, " EV"),
            AdjustSpec(Adjustments.BRIGHTNESS, "Brightness", -1f, 1f, 0f, AdjustGroup.LIGHT),
            AdjustSpec(Adjustments.CONTRAST, "Contrast", -1f, 1f, 0f, AdjustGroup.LIGHT),
            AdjustSpec(Adjustments.HIGHLIGHTS, "Highlights", -1f, 1f, 0f, AdjustGroup.LIGHT),
            AdjustSpec(Adjustments.SHADOWS, "Shadows", -1f, 1f, 0f, AdjustGroup.LIGHT),
            AdjustSpec(Adjustments.WHITES, "Whites", -1f, 1f, 0f, AdjustGroup.LIGHT),
            AdjustSpec(Adjustments.BLACKS, "Blacks", -1f, 1f, 0f, AdjustGroup.LIGHT),

            AdjustSpec(Adjustments.SATURATION, "Saturation", -1f, 1f, 0f, AdjustGroup.COLOUR),
            AdjustSpec(Adjustments.VIBRANCE, "Vibrance", -1f, 1f, 0f, AdjustGroup.COLOUR),
            AdjustSpec(Adjustments.TEMPERATURE, "Temperature", -1f, 1f, 0f, AdjustGroup.COLOUR),
            AdjustSpec(Adjustments.TINT, "Tint", -1f, 1f, 0f, AdjustGroup.COLOUR),
            AdjustSpec(Adjustments.HUE, "Hue", -180f, 180f, 0f, AdjustGroup.COLOUR, 1f, "°"),

            AdjustSpec(Adjustments.SHARPEN, "Sharpen", 0f, 1f, 0f, AdjustGroup.TEXTURE),
            AdjustSpec(Adjustments.BLUR, "Blur", 0f, 1f, 0f, AdjustGroup.TEXTURE),
            AdjustSpec(Adjustments.GRAIN, "Grain", 0f, 1f, 0f, AdjustGroup.TEXTURE),

            AdjustSpec(Adjustments.FADE, "Fade", 0f, 1f, 0f, AdjustGroup.EFFECT),
            AdjustSpec(Adjustments.VIGNETTE, "Vignette", -1f, 1f, 0f, AdjustGroup.EFFECT),
            AdjustSpec(Adjustments.GLOW, "Glow", 0f, 1f, 0f, AdjustGroup.EFFECT),
        )

        val BY_ID: Map<String, AdjustSpec> = ALL.associateBy { it.id }

        fun inGroup(group: AdjustGroup): List<AdjustSpec> = ALL.filter { it.group == group }

        /** Ids whose effect is purely a colour-to-colour map. */
        val COLOUR_IDS: List<String> = ALL
            .filter { it.group == AdjustGroup.LIGHT || it.group == AdjustGroup.COLOUR }
            .map { it.id } + Adjustments.FADE
    }
}
