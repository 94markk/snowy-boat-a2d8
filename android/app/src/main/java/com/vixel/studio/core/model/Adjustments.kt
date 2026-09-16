package com.vixel.studio.core.model

/**
 * Every colour parameter the engine understands.
 *
 * This class is the single source of truth: the UI builds its sliders from
 * [AdjustSpec.ALL], the GL pipeline binds uniforms from the same ids, and the
 * exporter reuses the identical struct. There is no second path where a value
 * can be read from somewhere the slider never wrote to — which is what makes a
 * control that appears to do nothing impossible by construction.
 *
 * All values are normalised to their neutral at 0f except [saturation]-style
 * multipliers, which are documented individually.
 */
data class Adjustments(
    // Tone
    val exposure: Float = 0f,       // EV, -3..3
    val brightness: Float = 0f,     // -1..1
    val contrast: Float = 0f,       // -1..1
    val highlights: Float = 0f,     // -1..1
    val shadows: Float = 0f,        // -1..1
    val whites: Float = 0f,         // -1..1
    val blacks: Float = 0f,         // -1..1

    // Colour
    val saturation: Float = 0f,     // -1..1  (-1 = greyscale)
    val vibrance: Float = 0f,       // -1..1  (saturation weighted to muted tones)
    val temperature: Float = 0f,    // -1..1  (blue -> amber)
    val tint: Float = 0f,           // -1..1  (green -> magenta)
    val hueShift: Float = 0f,       // -180..180 degrees

    // Texture / atmosphere
    val sharpen: Float = 0f,        // 0..1
    val blur: Float = 0f,           // 0..1 (radius, in % of the short edge)
    val fade: Float = 0f,           // 0..1 (lifted blacks / matte)
    val vignette: Float = 0f,       // -1..1 (negative brightens the edge)
    val grain: Float = 0f,          // 0..1
    val glow: Float = 0f,           // 0..1 (bloom on highlights)

    // Per-band HSL. Index order matches [HslBand].
    val hslHue: FloatArray = FloatArray(8),
    val hslSat: FloatArray = FloatArray(8),
    val hslLum: FloatArray = FloatArray(8),

    // Tone curve control points, per channel. Empty = identity.
    val curveMaster: List<CurvePoint> = emptyList(),
    val curveRed: List<CurvePoint> = emptyList(),
    val curveGreen: List<CurvePoint> = emptyList(),
    val curveBlue: List<CurvePoint> = emptyList(),

    // Look/LUT
    val filterId: String = Filters.NONE_ID,
    val filterStrength: Float = 1f,  // 0..1
) {
    fun get(id: String): Float = when (id) {
        "exposure" -> exposure
        "brightness" -> brightness
        "contrast" -> contrast
        "highlights" -> highlights
        "shadows" -> shadows
        "whites" -> whites
        "blacks" -> blacks
        "saturation" -> saturation
        "vibrance" -> vibrance
        "temperature" -> temperature
        "tint" -> tint
        "hueShift" -> hueShift
        "sharpen" -> sharpen
        "blur" -> blur
        "fade" -> fade
        "vignette" -> vignette
        "grain" -> grain
        "glow" -> glow
        "filterStrength" -> filterStrength
        else -> 0f
    }

    fun set(id: String, value: Float): Adjustments = when (id) {
        "exposure" -> copy(exposure = value)
        "brightness" -> copy(brightness = value)
        "contrast" -> copy(contrast = value)
        "highlights" -> copy(highlights = value)
        "shadows" -> copy(shadows = value)
        "whites" -> copy(whites = value)
        "blacks" -> copy(blacks = value)
        "saturation" -> copy(saturation = value)
        "vibrance" -> copy(vibrance = value)
        "temperature" -> copy(temperature = value)
        "tint" -> copy(tint = value)
        "hueShift" -> copy(hueShift = value)
        "sharpen" -> copy(sharpen = value)
        "blur" -> copy(blur = value)
        "fade" -> copy(fade = value)
        "vignette" -> copy(vignette = value)
        "grain" -> copy(grain = value)
        "glow" -> copy(glow = value)
        "filterStrength" -> copy(filterStrength = value)
        else -> this
    }

    /** True when nothing in the colour stack would change a pixel. */
    val isNeutral: Boolean
        get() = AdjustSpec.ALL.all { spec -> get(spec.id) == spec.default } &&
            hslHue.all { it == 0f } && hslSat.all { it == 0f } && hslLum.all { it == 0f } &&
            curveMaster.isEmpty() && curveRed.isEmpty() &&
            curveGreen.isEmpty() && curveBlue.isEmpty() &&
            filterId == Filters.NONE_ID

    fun resetColor(): Adjustments = Adjustments(
        filterId = filterId,
        filterStrength = filterStrength,
    )

    // FloatArray members mean the generated equals/hashCode compare identity.
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is Adjustments) return false
        return exposure == other.exposure && brightness == other.brightness &&
            contrast == other.contrast && highlights == other.highlights &&
            shadows == other.shadows && whites == other.whites && blacks == other.blacks &&
            saturation == other.saturation && vibrance == other.vibrance &&
            temperature == other.temperature && tint == other.tint &&
            hueShift == other.hueShift && sharpen == other.sharpen && blur == other.blur &&
            fade == other.fade && vignette == other.vignette && grain == other.grain &&
            glow == other.glow &&
            hslHue.contentEquals(other.hslHue) && hslSat.contentEquals(other.hslSat) &&
            hslLum.contentEquals(other.hslLum) &&
            curveMaster == other.curveMaster && curveRed == other.curveRed &&
            curveGreen == other.curveGreen && curveBlue == other.curveBlue &&
            filterId == other.filterId && filterStrength == other.filterStrength
    }

    override fun hashCode(): Int {
        var r = exposure.hashCode()
        for (v in listOf(
            brightness, contrast, highlights, shadows, whites, blacks, saturation,
            vibrance, temperature, tint, hueShift, sharpen, blur, fade, vignette,
            grain, glow, filterStrength,
        )) r = 31 * r + v.hashCode()
        r = 31 * r + hslHue.contentHashCode()
        r = 31 * r + hslSat.contentHashCode()
        r = 31 * r + hslLum.contentHashCode()
        r = 31 * r + curveMaster.hashCode()
        r = 31 * r + curveRed.hashCode()
        r = 31 * r + curveGreen.hashCode()
        r = 31 * r + curveBlue.hashCode()
        r = 31 * r + filterId.hashCode()
        return r
    }
}

data class CurvePoint(val x: Float, val y: Float)

enum class HslBand(val label: String, val centerHue: Float) {
    RED("Red", 0f),
    ORANGE("Orange", 30f),
    YELLOW("Yellow", 60f),
    GREEN("Green", 120f),
    AQUA("Aqua", 180f),
    BLUE("Blue", 220f),
    PURPLE("Purple", 280f),
    MAGENTA("Magenta", 320f),
}

/** UI description of one scalar parameter. */
data class AdjustSpec(
    val id: String,
    val label: String,
    val min: Float,
    val max: Float,
    val default: Float,
    val group: Group,
    /** Value shown to the user is `value * displayScale`. */
    val displayScale: Float = 100f,
    val unit: String = "",
) {
    enum class Group { LIGHT, COLOR, TEXTURE, EFFECT }

    companion object {
        val ALL: List<AdjustSpec> = listOf(
            AdjustSpec("exposure", "Exposure", -3f, 3f, 0f, Group.LIGHT, displayScale = 1f, unit = " EV"),
            AdjustSpec("brightness", "Brightness", -1f, 1f, 0f, Group.LIGHT),
            AdjustSpec("contrast", "Contrast", -1f, 1f, 0f, Group.LIGHT),
            AdjustSpec("highlights", "Highlights", -1f, 1f, 0f, Group.LIGHT),
            AdjustSpec("shadows", "Shadows", -1f, 1f, 0f, Group.LIGHT),
            AdjustSpec("whites", "Whites", -1f, 1f, 0f, Group.LIGHT),
            AdjustSpec("blacks", "Blacks", -1f, 1f, 0f, Group.LIGHT),

            AdjustSpec("saturation", "Saturation", -1f, 1f, 0f, Group.COLOR),
            AdjustSpec("vibrance", "Vibrance", -1f, 1f, 0f, Group.COLOR),
            AdjustSpec("temperature", "Temperature", -1f, 1f, 0f, Group.COLOR),
            AdjustSpec("tint", "Tint", -1f, 1f, 0f, Group.COLOR),
            AdjustSpec("hueShift", "Hue", -180f, 180f, 0f, Group.COLOR, displayScale = 1f, unit = "°"),

            AdjustSpec("sharpen", "Sharpen", 0f, 1f, 0f, Group.TEXTURE),
            AdjustSpec("blur", "Blur", 0f, 1f, 0f, Group.TEXTURE),
            AdjustSpec("grain", "Grain", 0f, 1f, 0f, Group.TEXTURE),

            AdjustSpec("fade", "Fade", 0f, 1f, 0f, Group.EFFECT),
            AdjustSpec("vignette", "Vignette", -1f, 1f, 0f, Group.EFFECT),
            AdjustSpec("glow", "Glow", 0f, 1f, 0f, Group.EFFECT),
        )

        val BY_ID: Map<String, AdjustSpec> = ALL.associateBy { it.id }

        fun group(group: Group): List<AdjustSpec> = ALL.filter { it.group == group }
    }
}
