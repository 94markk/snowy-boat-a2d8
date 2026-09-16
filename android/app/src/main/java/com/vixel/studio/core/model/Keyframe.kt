package com.vixel.studio.core.model

import kotlin.math.pow

/** How a value travels from one keyframe to the next. */
enum class Easing(val label: String) {
    LINEAR("Linear"),
    EASE_IN("Ease in"),
    EASE_OUT("Ease out"),
    EASE_IN_OUT("Smooth"),
    HOLD("Hold"),
    ;

    /** Maps 0..1 linear progress onto the eased curve. */
    fun apply(t: Float): Float {
        val p = t.coerceIn(0f, 1f)
        return when (this) {
            LINEAR -> p
            EASE_IN -> p * p
            EASE_OUT -> 1f - (1f - p).pow(2)
            EASE_IN_OUT -> p * p * (3f - 2f * p)
            // Holds the outgoing value until the next key is reached.
            HOLD -> 0f
        }
    }
}

/** Animatable properties. Each maps onto one field of a transform. */
enum class KeyframeProperty(val label: String, val min: Float, val max: Float, val default: Float) {
    SCALE("Scale", 0.2f, 4f, 1f),
    OFFSET_X("Position X", -1f, 1f, 0f),
    OFFSET_Y("Position Y", -1f, 1f, 0f),
    ROTATION("Rotation", -180f, 180f, 0f),
    OPACITY("Opacity", 0f, 1f, 1f),
}

/**
 * One key. [timeUs] is relative to the start of whatever owns the track — a
 * clip or an overlay — so trimming or moving the owner carries its animation
 * along instead of leaving it behind on the timeline.
 */
data class Keyframe(
    val timeUs: Long,
    val value: Float,
    val easing: Easing = Easing.EASE_IN_OUT,
)

/** The keys for a single property, kept sorted by time. */
data class KeyframeTrack(
    val property: KeyframeProperty,
    val keys: List<Keyframe> = emptyList(),
) {
    val isEmpty: Boolean get() = keys.isEmpty()

    /**
     * Value at [timeUs], or [fallback] when the track is empty.
     *
     * Before the first key and after the last, the value is held flat rather
     * than extrapolated — extrapolating would send scale or opacity somewhere
     * the user never asked for.
     */
    fun valueAt(timeUs: Long, fallback: Float): Float {
        if (keys.isEmpty()) return fallback
        val sorted = keys.sortedBy { it.timeUs }
        if (timeUs <= sorted.first().timeUs) return sorted.first().value
        if (timeUs >= sorted.last().timeUs) return sorted.last().value

        for (i in 0 until sorted.size - 1) {
            val a = sorted[i]
            val b = sorted[i + 1]
            if (timeUs < a.timeUs || timeUs > b.timeUs) continue
            val span = (b.timeUs - a.timeUs).coerceAtLeast(1L)
            val t = (timeUs - a.timeUs).toFloat() / span
            return a.value + (b.value - a.value) * a.easing.apply(t)
        }
        return sorted.last().value
    }

    /** Adds a key at [timeUs], replacing any key already at that instant. */
    fun withKey(timeUs: Long, value: Float, easing: Easing = Easing.EASE_IN_OUT): KeyframeTrack {
        val kept = keys.filterNot { kotlin.math.abs(it.timeUs - timeUs) < SNAP_US }
        return copy(keys = (kept + Keyframe(timeUs, value, easing)).sortedBy { it.timeUs })
    }

    fun withoutKeyNear(timeUs: Long): KeyframeTrack =
        copy(keys = keys.filterNot { kotlin.math.abs(it.timeUs - timeUs) < SNAP_US })

    companion object {
        /** Two keys closer than this are treated as the same instant. */
        const val SNAP_US = 40_000L
    }
}

/** Helpers over a list of tracks, which is how clips and overlays store them. */
object Keyframes {

    fun valueAt(
        tracks: List<KeyframeTrack>,
        property: KeyframeProperty,
        timeUs: Long,
        fallback: Float,
    ): Float = tracks.firstOrNull { it.property == property }?.valueAt(timeUs, fallback) ?: fallback

    fun hasAny(tracks: List<KeyframeTrack>): Boolean = tracks.any { !it.isEmpty }

    fun track(tracks: List<KeyframeTrack>, property: KeyframeProperty): KeyframeTrack? =
        tracks.firstOrNull { it.property == property }

    fun setKey(
        tracks: List<KeyframeTrack>,
        property: KeyframeProperty,
        timeUs: Long,
        value: Float,
        easing: Easing = Easing.EASE_IN_OUT,
    ): List<KeyframeTrack> {
        val existing = track(tracks, property)
        val updated = (existing ?: KeyframeTrack(property)).withKey(timeUs, value, easing)
        return if (existing == null) tracks + updated else tracks.map {
            if (it.property == property) updated else it
        }
    }

    fun removeKey(
        tracks: List<KeyframeTrack>,
        property: KeyframeProperty,
        timeUs: Long,
    ): List<KeyframeTrack> = tracks
        .map { if (it.property == property) it.withoutKeyNear(timeUs) else it }
        .filterNot { it.isEmpty }

    fun clear(tracks: List<KeyframeTrack>, property: KeyframeProperty): List<KeyframeTrack> =
        tracks.filterNot { it.property == property }

    /**
     * Two keys that slowly push in, the classic still-photo move.
     *
     * [panX] and [panY] drift the frame while it zooms, so a portrait shot can
     * travel across a face rather than just growing.
     */
    fun kenBurns(
        durationUs: Long,
        fromScale: Float,
        toScale: Float,
        panX: Float = 0f,
        panY: Float = 0f,
    ): List<KeyframeTrack> {
        val end = durationUs.coerceAtLeast(1L)
        val tracks = mutableListOf(
            KeyframeTrack(
                KeyframeProperty.SCALE,
                listOf(
                    Keyframe(0L, fromScale, Easing.EASE_IN_OUT),
                    Keyframe(end, toScale, Easing.EASE_IN_OUT),
                ),
            ),
        )
        if (panX != 0f) {
            tracks += KeyframeTrack(
                KeyframeProperty.OFFSET_X,
                listOf(
                    Keyframe(0L, -panX / 2f, Easing.EASE_IN_OUT),
                    Keyframe(end, panX / 2f, Easing.EASE_IN_OUT),
                ),
            )
        }
        if (panY != 0f) {
            tracks += KeyframeTrack(
                KeyframeProperty.OFFSET_Y,
                listOf(
                    Keyframe(0L, -panY / 2f, Easing.EASE_IN_OUT),
                    Keyframe(end, panY / 2f, Easing.EASE_IN_OUT),
                ),
            )
        }
        return tracks
    }
}
