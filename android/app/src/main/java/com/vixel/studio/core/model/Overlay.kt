package com.vixel.studio.core.model

import java.util.UUID
import kotlin.math.max

/** How an overlay enters or leaves. */
enum class OverlayAnimation(val label: String) {
    NONE("None"),
    FADE("Fade"),
    SLIDE_UP("Slide up"),
    SLIDE_DOWN("Slide down"),
    SLIDE_LEFT("Slide left"),
    SLIDE_RIGHT("Slide right"),
    POP("Pop"),
    ZOOM("Zoom"),
}

/**
 * Placement on the canvas.
 *
 * Position is a fraction of the canvas so an overlay lands in the same place
 * whether the project is previewed at 540p or exported at 2160p, and [scale]
 * multiplies a size that is itself expressed relative to canvas height.
 */
data class OverlayTransform(
    /** 0 = left edge, 1 = right edge. */
    val x: Float = 0.5f,
    /** 0 = top edge, 1 = bottom edge. */
    val y: Float = 0.5f,
    val scale: Float = 1f,
    val rotationDegrees: Float = 0f,
    val opacity: Float = 1f,
)

sealed interface Overlay {
    val id: String
    val startUs: Long
    val endUs: Long
    val transform: OverlayTransform
    val animationIn: OverlayAnimation
    val animationOut: OverlayAnimation
    val animationDurationUs: Long

    val durationUs: Long get() = max(0L, endUs - startUs)

    fun isActiveAt(timeUs: Long): Boolean = timeUs >= startUs && timeUs < endUs

    /** Progress through the entry animation, 1 once it has finished. */
    fun enterProgress(timeUs: Long): Float {
        if (animationDurationUs <= 0L || animationIn == OverlayAnimation.NONE) return 1f
        val elapsed = timeUs - startUs
        if (elapsed >= animationDurationUs) return 1f
        return (elapsed.toFloat() / animationDurationUs).coerceIn(0f, 1f)
    }

    /** Progress through the exit animation, 1 while it has not started. */
    fun exitProgress(timeUs: Long): Float {
        if (animationDurationUs <= 0L || animationOut == OverlayAnimation.NONE) return 1f
        val remaining = endUs - timeUs
        if (remaining >= animationDurationUs) return 1f
        return (remaining.toFloat() / animationDurationUs).coerceIn(0f, 1f)
    }

    fun withTiming(startUs: Long, endUs: Long): Overlay
    fun withTransform(transform: OverlayTransform): Overlay
}

// ------------------------------------------------------------------- text

enum class TextFont(val label: String) {
    SANS("Sans"),
    SANS_CONDENSED("Condensed"),
    SERIF("Serif"),
    MONOSPACE("Mono"),
    CURSIVE("Cursive"),
}

enum class TextAlignment(val label: String) { START("Left"), CENTER("Center"), END("Right") }

data class TextStyle(
    val font: TextFont = TextFont.SANS,
    val bold: Boolean = true,
    val italic: Boolean = false,
    /** Cap height as a fraction of canvas height, so it scales with output. */
    val sizeFraction: Float = 0.07f,
    val color: Int = 0xFFFFFFFF.toInt(),
    val alignment: TextAlignment = TextAlignment.CENTER,
    val letterSpacing: Float = 0f,
    val lineSpacing: Float = 1.1f,

    /** Outline. 0 disables it. */
    val strokeWidth: Float = 0f,
    val strokeColor: Int = 0xFF000000.toInt(),

    /** Drop shadow. 0 radius disables it. */
    val shadowRadius: Float = 0f,
    val shadowDx: Float = 0f,
    val shadowDy: Float = 0.12f,
    val shadowColor: Int = 0xB3000000.toInt(),

    /** Plate behind the text. Fully transparent disables it. */
    val backgroundColor: Int = 0x00000000,
    val backgroundPadding: Float = 0.35f,
    val backgroundRadius: Float = 0.25f,
)

data class TextOverlay(
    override val id: String = UUID.randomUUID().toString(),
    val text: String = "Your text",
    val style: TextStyle = TextStyle(),
    override val startUs: Long = 0L,
    override val endUs: Long = 3_000_000L,
    override val transform: OverlayTransform = OverlayTransform(),
    override val animationIn: OverlayAnimation = OverlayAnimation.FADE,
    override val animationOut: OverlayAnimation = OverlayAnimation.FADE,
    override val animationDurationUs: Long = 400_000L,
) : Overlay {
    override fun withTiming(startUs: Long, endUs: Long): Overlay =
        copy(startUs = startUs, endUs = endUs)

    override fun withTransform(transform: OverlayTransform): Overlay =
        copy(transform = transform)
}

// ---------------------------------------------------------------- stickers

/**
 * Sticker artwork.
 *
 * Emoji come from the system font and shapes are drawn as paths, so the
 * library costs nothing in APK size and renders crisply at any resolution.
 */
sealed interface StickerArt {
    data class Emoji(val glyph: String) : StickerArt
    data class Shape(val shape: StickerShape, val color: Int) : StickerArt
}

enum class StickerShape(val label: String) {
    CIRCLE("Circle"),
    SQUARE("Square"),
    TRIANGLE("Triangle"),
    STAR("Star"),
    HEART("Heart"),
    ARROW("Arrow"),
    SPEECH_BUBBLE("Bubble"),
    BURST("Burst"),
}

data class StickerOverlay(
    override val id: String = UUID.randomUUID().toString(),
    val art: StickerArt = StickerArt.Emoji("⭐"),
    /** Size as a fraction of canvas height. */
    val sizeFraction: Float = 0.2f,
    override val startUs: Long = 0L,
    override val endUs: Long = 3_000_000L,
    override val transform: OverlayTransform = OverlayTransform(),
    override val animationIn: OverlayAnimation = OverlayAnimation.POP,
    override val animationOut: OverlayAnimation = OverlayAnimation.FADE,
    override val animationDurationUs: Long = 350_000L,
) : Overlay {
    override fun withTiming(startUs: Long, endUs: Long): Overlay =
        copy(startUs = startUs, endUs = endUs)

    override fun withTransform(transform: OverlayTransform): Overlay =
        copy(transform = transform)
}

/** Sticker choices offered in the picker. */
object StickerLibrary {

    val emoji: List<String> = listOf(
        "⭐", "❤️", "🔥", "✨", "👍", "😂",
        "😍", "🤩", "😎", "🎉", "💯", "👀",
        "🎵", "📸", "🌈", "⚡", "🌙", "☀️",
        "🌿", "🌺", "🍕", "☕", "👋", "🙌",
    )

    val shapeColors: List<Int> = listOf(
        0xFFFFFFFF.toInt(), 0xFF000000.toInt(), 0xFF6FE3C9.toInt(), 0xFFB08CFF.toInt(),
        0xFF5AA9FF.toInt(), 0xFFFFC46B.toInt(), 0xFFFF7A90.toInt(),
    )
}
