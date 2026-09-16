package com.delicat.studio.model

import java.util.UUID
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.roundToLong

/** Canvas shapes the editor offers. Portrait leads, because phones do. */
enum class CanvasRatio(
    val id: String,
    val label: String,
    val w: Int,
    val h: Int,
) {
    PORTRAIT_9_16("9:16", "9:16", 9, 16),
    PORTRAIT_4_5("4:5", "4:5", 4, 5),
    PORTRAIT_3_4("3:4", "3:4", 3, 4),
    SQUARE("1:1", "1:1", 1, 1),
    LANDSCAPE_16_9("16:9", "16:9", 16, 9),
    LANDSCAPE_4_3("4:3", "4:3", 4, 3),
    CINEMA_21_9("21:9", "21:9", 21, 9),
    ;

    val ratio: Float get() = w.toFloat() / h

    /**
     * Pixel size for a target short edge, rounded to even numbers because
     * H.264 encoders reject odd dimensions outright.
     */
    fun sizeFor(shortEdge: Int): Pair<Int, Int> {
        val width: Int
        val height: Int
        if (w <= h) {
            width = shortEdge
            height = (shortEdge * h.toFloat() / w).roundToInt()
        } else {
            height = shortEdge
            width = (shortEdge * w.toFloat() / h).roundToInt()
        }
        return even(width) to even(height)
    }

    private fun even(v: Int): Int = if (v % 2 == 0) v else v + 1

    companion object {
        val DEFAULT = PORTRAIT_9_16

        fun fromId(id: String): CanvasRatio = entries.firstOrNull { it.id == id } ?: DEFAULT

        /**
         * Nearest listed ratio to a given size.
         *
         * Used to shape the canvas from the first clip imported. A fixed
         * default is wrong half the time whichever way it points: landscape
         * footage in a 9:16 canvas arrives as a thin band between two black
         * slabs, which reads as a broken import rather than as a canvas
         * waiting to be changed.
         */
        fun closestTo(width: Int, height: Int): CanvasRatio {
            if (width <= 0 || height <= 0) return DEFAULT
            val target = width.toFloat() / height
            return entries.minByOrNull { abs(it.ratio - target) } ?: DEFAULT
        }
    }
}

enum class MediaKind { VIDEO, IMAGE }

enum class FitMode(val label: String) {
    FIT("Fit"),
    FILL("Fill"),
}

enum class TransitionType(val label: String) {
    NONE("None"),
    DISSOLVE("Dissolve"),
    FADE_BLACK("To black"),
    FADE_WHITE("To white"),
    SLIDE_LEFT("Slide left"),
    SLIDE_RIGHT("Slide right"),
    SLIDE_UP("Slide up"),
    SLIDE_DOWN("Slide down"),
    WIPE_LEFT("Wipe left"),
    WIPE_RIGHT("Wipe right"),
    ZOOM_IN("Zoom in"),
    ZOOM_OUT("Zoom out"),
    ;

    val isNone: Boolean get() = this == NONE
}

data class Transition(
    val type: TransitionType = TransitionType.NONE,
    val durationUs: Long = 500_000L,
) {
    val isActive: Boolean get() = !type.isNone && durationUs > 0L

    companion object {
        val NONE = Transition(TransitionType.NONE, 0L)
        const val MIN_US = 100_000L
        const val MAX_US = 2_000_000L
    }
}

/**
 * One item on the timeline.
 *
 * Times are microseconds throughout, matching what MediaExtractor and
 * MediaCodec report, so no unit conversion sits between the model and the
 * engine that consumes it.
 */
data class Clip(
    val id: String = UUID.randomUUID().toString(),
    val uri: String,
    val kind: MediaKind,
    val sourceDurationUs: Long,
    val trimStartUs: Long = 0L,
    val trimEndUs: Long = sourceDurationUs,
    val speed: Float = 1f,
    val volume: Float = 1f,
    val muted: Boolean = false,
    val adjustments: Adjustments = Adjustments(),
    val fit: FitMode = FitMode.FIT,
    val rotationTurns: Int = 0,
    val fadeInUs: Long = 0L,
    val fadeOutUs: Long = 0L,
    /** Transition into this clip from the one before it. */
    val transition: Transition = Transition.NONE,
    val sourceWidth: Int = 0,
    val sourceHeight: Int = 0,
    val sourceRotationDegrees: Int = 0,
) {
    val isImage: Boolean get() = kind == MediaKind.IMAGE

    /** Length of the trimmed region before speed is applied. */
    val trimmedDurationUs: Long get() = max(0L, trimEndUs - trimStartUs)

    /** Length this clip occupies on the timeline. */
    val timelineDurationUs: Long
        get() = if (speed <= 0f) trimmedDurationUs else (trimmedDurationUs / speed).roundToLong()

    /** Size after the source's own rotation metadata is applied. */
    val displayWidth: Int
        get() = if ((sourceRotationDegrees + rotationTurns * 90) % 180 == 0) sourceWidth else sourceHeight

    val displayHeight: Int
        get() = if ((sourceRotationDegrees + rotationTurns * 90) % 180 == 0) sourceHeight else sourceWidth

    fun withTrim(startUs: Long, endUs: Long): Clip {
        val safeStart = startUs.coerceIn(0L, max(0L, sourceDurationUs - MIN_US))
        val safeEnd = endUs.coerceIn(safeStart + MIN_US, sourceDurationUs)
        return copy(trimStartUs = safeStart, trimEndUs = safeEnd)
    }

    companion object {
        const val DEFAULT_IMAGE_DURATION_US = 3_000_000L

        /** Nothing shorter is useful, and it keeps the trim maths safe. */
        const val MIN_US = 100_000L
    }
}

/** What to draw at a moment, including any transition in progress. */
data class Composition(
    val primaryIndex: Int,
    val fromIndex: Int = -1,
    val progress: Float = 0f,
    val transition: Transition = Transition.NONE,
) {
    val isTransitioning: Boolean get() = fromIndex >= 0 && transition.isActive
}

data class Project(
    val id: String = UUID.randomUUID().toString(),
    val name: String = "Untitled",
    val aspect: CanvasRatio = CanvasRatio.DEFAULT,
    val clips: List<Clip> = emptyList(),
    val backgroundColor: Int = 0xFF000000.toInt(),
    val createdAt: Long = System.currentTimeMillis(),
) {
    val isEmpty: Boolean get() = clips.isEmpty()

    val durationUs: Long
        get() = if (clips.isEmpty()) 0L
        else startOf(clips.size - 1) + clips.last().timelineDurationUs

    /**
     * How far clip [index] overlaps the one before it.
     *
     * Clamped to half of the shorter neighbour so a long transition between
     * two short clips cannot consume either of them entirely.
     */
    fun overlapBefore(index: Int): Long {
        if (index <= 0 || index >= clips.size) return 0L
        val transition = clips[index].transition
        if (!transition.isActive) return 0L
        val shorter = min(
            clips[index - 1].timelineDurationUs,
            clips[index].timelineDurationUs,
        )
        return transition.durationUs.coerceIn(0L, shorter / 2)
    }

    /** Timeline start of [index], in microseconds. */
    fun startOf(index: Int): Long {
        var acc = 0L
        val limit = index.coerceIn(0, clips.size)
        for (i in 0 until limit) {
            acc += clips[i].timelineDurationUs
            acc -= overlapBefore(i + 1)
        }
        return acc
    }

    /**
     * Index of the clip covering [positionUs], or -1 past the end.
     *
     * Searched backwards so that where two clips overlap in a transition the
     * later one wins: the playhead belongs to the incoming clip as soon as it
     * starts.
     */
    fun clipIndexAt(positionUs: Long): Int {
        for (index in clips.indices.reversed()) {
            val start = startOf(index)
            if (positionUs >= start && positionUs < start + clips[index].timelineDurationUs) {
                return index
            }
        }
        return -1
    }

    fun compositionAt(positionUs: Long): Composition {
        val index = clipIndexAt(positionUs)
        if (index < 0) return Composition(primaryIndex = -1)

        val overlap = overlapBefore(index)
        if (overlap <= 0L || index == 0) return Composition(primaryIndex = index)

        val into = positionUs - startOf(index)
        if (into >= overlap) return Composition(primaryIndex = index)

        return Composition(
            primaryIndex = index,
            fromIndex = index - 1,
            progress = (into.toFloat() / overlap).coerceIn(0f, 1f),
            transition = clips[index].transition,
        )
    }

    /** Source position inside clip [index] for timeline time [positionUs]. */
    fun sourceTimeFor(index: Int, positionUs: Long): Long {
        val clip = clips.getOrNull(index) ?: return 0L
        val into = max(0L, positionUs - startOf(index))
        val scaled = (into * clip.speed).toLong()
        return (clip.trimStartUs + scaled).coerceIn(clip.trimStartUs, clip.trimEndUs)
    }

    fun updateClip(id: String, transform: (Clip) -> Clip): Project =
        copy(clips = clips.map { if (it.id == id) transform(it) else it })

    fun removeClip(id: String): Project = copy(clips = clips.filterNot { it.id == id })

    fun duplicateClip(id: String): Project {
        val index = clips.indexOfFirst { it.id == id }
        if (index < 0) return this
        val copy = clips[index].copy(id = UUID.randomUUID().toString())
        return copy(clips = clips.toMutableList().apply { add(index + 1, copy) })
    }

    fun moveClip(from: Int, to: Int): Project {
        if (from !in clips.indices || to !in clips.indices || from == to) return this
        val list = clips.toMutableList()
        list.add(to, list.removeAt(from))
        return copy(clips = list)
    }

    /**
     * Splits the clip covering [positionUs] at that point.
     *
     * A split at the very start or end is a no-op rather than creating a
     * zero-length clip that can never be selected or removed.
     */
    fun splitAt(positionUs: Long): Project {
        val index = clipIndexAt(positionUs)
        if (index < 0) return this
        val clip = clips[index]
        val intoTimeline = positionUs - startOf(index)
        val cut = clip.trimStartUs + (intoTimeline * clip.speed).roundToLong()

        if (cut - clip.trimStartUs < Clip.MIN_US) return this
        if (clip.trimEndUs - cut < Clip.MIN_US) return this

        val head = clip.copy(trimEndUs = cut, fadeOutUs = 0L)
        val tail = clip.copy(
            id = UUID.randomUUID().toString(),
            trimStartUs = cut,
            fadeInUs = 0L,
            // A split is a hard cut; inheriting the head's transition would
            // insert one in the middle of what was continuous footage.
            transition = Transition.NONE,
        )
        return copy(
            clips = clips.toMutableList().apply {
                set(index, head)
                add(index + 1, tail)
            },
        )
    }
}
