package com.vixel.studio.core.model

import java.util.UUID
import kotlin.math.max
import kotlin.math.roundToInt
import kotlin.math.roundToLong

/** Canvas shapes offered by the editor. Portrait leads, because phones do. */
enum class AspectRatio(
    val id: String,
    val label: String,
    val widthRatio: Int,
    val heightRatio: Int,
) {
    PORTRAIT_9_16("9:16", "9:16", 9, 16),
    PORTRAIT_4_5("4:5", "4:5", 4, 5),
    PORTRAIT_3_4("3:4", "3:4", 3, 4),
    PORTRAIT_2_3("2:3", "2:3", 2, 3),
    SQUARE("1:1", "1:1", 1, 1),
    LANDSCAPE_16_9("16:9", "16:9", 16, 9),
    LANDSCAPE_4_3("4:3", "4:3", 4, 3),
    CINEMA_21_9("21:9", "21:9", 21, 9);

    val ratio: Float get() = widthRatio.toFloat() / heightRatio

    val isPortrait: Boolean get() = heightRatio > widthRatio

    /**
     * Pixel size for a target short-edge resolution, rounded to even numbers —
     * H.264 encoders reject odd dimensions.
     */
    fun sizeFor(shortEdge: Int): Pair<Int, Int> {
        val w: Int
        val h: Int
        if (widthRatio <= heightRatio) {
            w = shortEdge
            h = (shortEdge * heightRatio.toFloat() / widthRatio).roundToInt()
        } else {
            h = shortEdge
            w = (shortEdge * widthRatio.toFloat() / heightRatio).roundToInt()
        }
        return even(w) to even(h)
    }

    private fun even(v: Int): Int = if (v % 2 == 0) v else v + 1

    companion object {
        val DEFAULT = PORTRAIT_9_16
        fun fromId(id: String): AspectRatio = entries.firstOrNull { it.id == id } ?: DEFAULT
    }
}

enum class MediaKind { VIDEO, IMAGE, AUDIO }

/** How a clip is fitted into the canvas. */
enum class FitMode(val label: String) {
    FIT("Fit"),
    FILL("Fill"),
    STRETCH("Stretch"),
}

data class Transform(
    val scale: Float = 1f,
    val offsetX: Float = 0f,   // fraction of canvas width
    val offsetY: Float = 0f,   // fraction of canvas height
    val rotationDegrees: Float = 0f,
    val flipHorizontal: Boolean = false,
    val flipVertical: Boolean = false,
    val fit: FitMode = FitMode.FIT,
)

/**
 * One item on the timeline. Times are microseconds to match the MediaCodec and
 * MediaExtractor APIs the engine talks to.
 */
data class Clip(
    val id: String = UUID.randomUUID().toString(),
    val uri: String,
    val kind: MediaKind,
    /** Full length of the underlying file. Images get [DEFAULT_IMAGE_DURATION_US]. */
    val sourceDurationUs: Long,
    val trimStartUs: Long = 0L,
    val trimEndUs: Long = sourceDurationUs,
    val speed: Float = 1f,
    val volume: Float = 1f,
    val muted: Boolean = false,
    val reversed: Boolean = false,
    val adjustments: Adjustments = Adjustments(),
    val transform: Transform = Transform(),
    val fadeInUs: Long = 0L,
    val fadeOutUs: Long = 0L,
    /** Cross-fade into this clip from the previous one. */
    val transitionUs: Long = 0L,
    val sourceWidth: Int = 0,
    val sourceHeight: Int = 0,
    val sourceRotationDegrees: Int = 0,
) {
    /** Length of the trimmed region before speed is applied. */
    val trimmedDurationUs: Long get() = max(0L, trimEndUs - trimStartUs)

    /** Length this clip occupies on the timeline. */
    val timelineDurationUs: Long
        get() = if (speed <= 0f) trimmedDurationUs else (trimmedDurationUs / speed).roundToLong()

    /** Display size after the source's own rotation metadata is applied. */
    val displayWidth: Int
        get() = if (sourceRotationDegrees % 180 == 0) sourceWidth else sourceHeight

    val displayHeight: Int
        get() = if (sourceRotationDegrees % 180 == 0) sourceHeight else sourceWidth

    fun withTrim(startUs: Long, endUs: Long): Clip {
        val safeStart = startUs.coerceIn(0L, max(0L, sourceDurationUs - MIN_CLIP_US))
        val safeEnd = endUs.coerceIn(safeStart + MIN_CLIP_US, sourceDurationUs)
        return copy(trimStartUs = safeStart, trimEndUs = safeEnd)
    }

    companion object {
        const val DEFAULT_IMAGE_DURATION_US = 3_000_000L
        /** Nothing shorter than this is useful, and it keeps trim maths safe. */
        const val MIN_CLIP_US = 100_000L
    }
}

data class AudioClip(
    val id: String = UUID.randomUUID().toString(),
    val uri: String,
    val title: String = "",
    val sourceDurationUs: Long,
    val startOnTimelineUs: Long = 0L,
    val trimStartUs: Long = 0L,
    val trimEndUs: Long = sourceDurationUs,
    val volume: Float = 1f,
    val fadeInUs: Long = 0L,
    val fadeOutUs: Long = 0L,
    val loop: Boolean = false,
) {
    val timelineDurationUs: Long get() = max(0L, trimEndUs - trimStartUs)
    val endOnTimelineUs: Long get() = startOnTimelineUs + timelineDurationUs
}

data class Project(
    val id: String = UUID.randomUUID().toString(),
    val name: String = "Untitled",
    val aspect: AspectRatio = AspectRatio.DEFAULT,
    val clips: List<Clip> = emptyList(),
    val audio: List<AudioClip> = emptyList(),
    val backgroundColor: Int = 0xFF000000.toInt(),
    val createdAt: Long = System.currentTimeMillis(),
) {
    val durationUs: Long get() = clips.sumOf { it.timelineDurationUs }

    val isEmpty: Boolean get() = clips.isEmpty() && audio.isEmpty()

    /** Timeline start of [index], in microseconds. */
    fun startOf(index: Int): Long {
        var acc = 0L
        for (i in 0 until index.coerceAtMost(clips.size)) acc += clips[i].timelineDurationUs
        return acc
    }

    /** Index of the clip covering [positionUs], or -1 past the end. */
    fun clipIndexAt(positionUs: Long): Int {
        var acc = 0L
        clips.forEachIndexed { index, clip ->
            val end = acc + clip.timelineDurationUs
            if (positionUs < end) return index
            acc = end
        }
        return -1
    }

    fun updateClip(id: String, transform: (Clip) -> Clip): Project =
        copy(clips = clips.map { if (it.id == id) transform(it) else it })

    fun updateAudio(id: String, transform: (AudioClip) -> AudioClip): Project =
        copy(audio = audio.map { if (it.id == id) transform(it) else it })

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
     * Splits the clip covering [positionUs] at that point. A split at the very
     * start or end of a clip is a no-op rather than creating a zero-length one.
     */
    fun splitAt(positionUs: Long): Project {
        val index = clipIndexAt(positionUs)
        if (index < 0) return this
        val clip = clips[index]
        val offsetOnTimeline = positionUs - startOf(index)
        val offsetInSource = (offsetOnTimeline * clip.speed).roundToLong()
        val cut = clip.trimStartUs + offsetInSource

        if (cut - clip.trimStartUs < Clip.MIN_CLIP_US) return this
        if (clip.trimEndUs - cut < Clip.MIN_CLIP_US) return this

        val head = clip.copy(trimEndUs = cut, fadeOutUs = 0L)
        val tail = clip.copy(
            id = UUID.randomUUID().toString(),
            trimStartUs = cut,
            fadeInUs = 0L,
            transitionUs = 0L,
        )
        return copy(
            clips = clips.toMutableList().apply {
                set(index, head)
                add(index + 1, tail)
            },
        )
    }
}
