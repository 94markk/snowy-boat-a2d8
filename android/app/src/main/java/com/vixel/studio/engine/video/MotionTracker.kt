package com.vixel.studio.engine.video

import android.content.Context
import android.graphics.Bitmap
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.util.Log
import com.vixel.studio.core.model.Easing
import com.vixel.studio.core.model.Keyframe
import com.vixel.studio.core.model.KeyframeProperty
import com.vixel.studio.core.model.KeyframeTrack
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sqrt

private const val TAG = "VixelTracker"

/** Where the tracked point sat at one instant, as a fraction of the frame. */
data class TrackPoint(val timeUs: Long, val x: Float, val y: Float, val confidence: Float)

data class TrackResult(val points: List<TrackPoint>) {
    val isUsable: Boolean get() = points.size >= 2

    /**
     * Converts the path into overlay position keyframes.
     *
     * Times are rebased to [overlayStartUs] because overlay keys are measured
     * from the overlay's own start, not from the timeline.
     */
    fun toKeyframes(overlayStartUs: Long): List<KeyframeTrack> {
        if (!isUsable) return emptyList()
        return listOf(
            KeyframeTrack(
                KeyframeProperty.OFFSET_X,
                points.map {
                    Keyframe((it.timeUs - overlayStartUs).coerceAtLeast(0L), it.x, Easing.LINEAR)
                },
            ),
            KeyframeTrack(
                KeyframeProperty.OFFSET_Y,
                points.map {
                    Keyframe((it.timeUs - overlayStartUs).coerceAtLeast(0L), it.y, Easing.LINEAR)
                },
            ),
        )
    }
}

/**
 * Follows a patch of the picture across time.
 *
 * Frames are sampled through MediaMetadataRetriever rather than a second
 * decoder: tracking runs at a low rate over a short span, so the simplicity is
 * worth more than the throughput, and it keeps this off the export path
 * entirely.
 *
 * Matching is normalised cross-correlation, which is invariant to brightness
 * changes — a subject moving through shade stays matched, where a plain
 * difference metric would lose it.
 */
object MotionTracker {

    /** Frames sampled per second. Denser costs time and buys little. */
    const val SAMPLE_FPS = 10

    /** Working width; tracking does not need detail, and small is fast. */
    private const val WORK_WIDTH = 320

    /** Half-size of the patch being matched, in working pixels. */
    private const val PATCH = 18

    /** How far to look around the last position, in working pixels. */
    private const val SEARCH = 28

    /** Below this the match is treated as lost. */
    private const val MIN_CONFIDENCE = 0.55f

    /**
     * Tracks the point at ([startX], [startY]) — fractions of the frame — from
     * [startUs] to [endUs].
     *
     * @return points as offsets relative to the starting position, ready to
     *   drive an overlay that was placed on the subject.
     */
    fun track(
        context: Context,
        uri: Uri,
        startUs: Long,
        endUs: Long,
        startX: Float,
        startY: Float,
        onProgress: (Float) -> Unit = {},
    ): TrackResult {
        val retriever = MediaMetadataRetriever()
        val points = mutableListOf<TrackPoint>()

        try {
            retriever.setDataSource(context, uri)

            val stepUs = 1_000_000L / SAMPLE_FPS
            val span = (endUs - startUs).coerceAtLeast(stepUs)
            val frameCount = (span / stepUs).toInt().coerceIn(2, 600)

            var template: FloatArray? = null
            var width = 0
            var height = 0
            var lastX = 0
            var lastY = 0

            for (i in 0 until frameCount) {
                val timeUs = startUs + i * stepUs
                val bitmap = retriever.getFrameAtTime(
                    timeUs,
                    MediaMetadataRetriever.OPTION_CLOSEST_SYNC,
                ) ?: continue

                val luma = toLuma(bitmap, WORK_WIDTH)
                bitmap.recycle()
                if (luma.width < PATCH * 2 || luma.height < PATCH * 2) continue

                if (template == null) {
                    width = luma.width
                    height = luma.height
                    lastX = (startX * width).roundToInt().coerceIn(PATCH, width - PATCH - 1)
                    lastY = (startY * height).roundToInt().coerceIn(PATCH, height - PATCH - 1)
                    template = extractPatch(luma, lastX, lastY)
                    points += TrackPoint(timeUs, 0f, 0f, 1f)
                    continue
                }

                val match = bestMatch(luma, template, lastX, lastY)
                if (match.score < MIN_CONFIDENCE) {
                    // Hold the last good position rather than snapping the
                    // overlay to a bad match.
                    points += TrackPoint(
                        timeUs,
                        (lastX - startX * width) / width,
                        (lastY - startY * height) / height,
                        match.score,
                    )
                    continue
                }

                lastX = match.x
                lastY = match.y
                points += TrackPoint(
                    timeUs,
                    (lastX - startX * width) / width,
                    (lastY - startY * height) / height,
                    match.score,
                )

                // Refresh the template slowly so gradual rotation or lighting
                // shifts are absorbed without letting drift accumulate.
                val refreshed = extractPatch(luma, lastX, lastY)
                for (p in template.indices) {
                    template[p] = template[p] * 0.85f + refreshed[p] * 0.15f
                }

                onProgress((i + 1).toFloat() / frameCount)
            }
        } catch (t: Throwable) {
            Log.e(TAG, "tracking failed for $uri", t)
        } finally {
            runCatching { retriever.release() }
        }

        return TrackResult(points)
    }

    private class Luma(val data: FloatArray, val width: Int, val height: Int)

    private class Match(val x: Int, val y: Int, val score: Float)

    /** Downscales and converts to a single luminance plane. */
    private fun toLuma(bitmap: Bitmap, targetWidth: Int): Luma {
        val scale = targetWidth.toFloat() / max(1, bitmap.width)
        val w = max(8, (bitmap.width * scale).roundToInt())
        val h = max(8, (bitmap.height * scale).roundToInt())
        val scaled = Bitmap.createScaledBitmap(bitmap, w, h, true)

        val pixels = IntArray(w * h)
        scaled.getPixels(pixels, 0, w, 0, 0, w, h)
        if (scaled !== bitmap) scaled.recycle()

        val out = FloatArray(w * h)
        for (i in pixels.indices) {
            val p = pixels[i]
            out[i] = (
                0.2126f * ((p shr 16) and 0xFF) +
                    0.7152f * ((p shr 8) and 0xFF) +
                    0.0722f * (p and 0xFF)
                ) / 255f
        }
        return Luma(out, w, h)
    }

    private fun extractPatch(luma: Luma, cx: Int, cy: Int): FloatArray {
        val size = PATCH * 2 + 1
        val out = FloatArray(size * size)
        var i = 0
        for (dy in -PATCH..PATCH) {
            val y = (cy + dy).coerceIn(0, luma.height - 1)
            for (dx in -PATCH..PATCH) {
                val x = (cx + dx).coerceIn(0, luma.width - 1)
                out[i++] = luma.data[y * luma.width + x]
            }
        }
        return out
    }

    /**
     * Searches a window around the last position for the best normalised
     * cross-correlation with [template].
     */
    private fun bestMatch(luma: Luma, template: FloatArray, lastX: Int, lastY: Int): Match {
        val templateMean = template.average().toFloat()
        var templateVar = 0f
        for (v in template) {
            val d = v - templateMean
            templateVar += d * d
        }
        if (templateVar <= 1e-6f) return Match(lastX, lastY, 0f)
        val templateNorm = sqrt(templateVar)

        var bestX = lastX
        var bestY = lastY
        var bestScore = -1f

        val minX = max(PATCH, lastX - SEARCH)
        val maxX = min(luma.width - PATCH - 1, lastX + SEARCH)
        val minY = max(PATCH, lastY - SEARCH)
        val maxY = min(luma.height - PATCH - 1, lastY + SEARCH)

        // Step 2 first for speed, then refine by 1 around the winner.
        var y = minY
        while (y <= maxY) {
            var x = minX
            while (x <= maxX) {
                val score = correlate(luma, template, templateMean, templateNorm, x, y)
                if (score > bestScore) {
                    bestScore = score
                    bestX = x
                    bestY = y
                }
                x += 2
            }
            y += 2
        }

        for (ry in (bestY - 1)..(bestY + 1)) {
            for (rx in (bestX - 1)..(bestX + 1)) {
                if (rx < minX || rx > maxX || ry < minY || ry > maxY) continue
                val score = correlate(luma, template, templateMean, templateNorm, rx, ry)
                if (score > bestScore) {
                    bestScore = score
                    bestX = rx
                    bestY = ry
                }
            }
        }

        return Match(bestX, bestY, bestScore.coerceIn(0f, 1f))
    }

    private fun correlate(
        luma: Luma,
        template: FloatArray,
        templateMean: Float,
        templateNorm: Float,
        cx: Int,
        cy: Int,
    ): Float {
        val size = PATCH * 2 + 1
        var sum = 0f
        var i = 0
        for (dy in -PATCH..PATCH) {
            val y = cy + dy
            val row = y * luma.width
            for (dx in -PATCH..PATCH) {
                sum += luma.data[row + cx + dx]
                i++
            }
        }
        val mean = sum / i

        var cross = 0f
        var variance = 0f
        i = 0
        for (dy in -PATCH..PATCH) {
            val y = cy + dy
            val row = y * luma.width
            for (dx in -PATCH..PATCH) {
                val d = luma.data[row + cx + dx] - mean
                cross += d * (template[i] - templateMean)
                variance += d * d
                i++
            }
        }
        if (variance <= 1e-6f) return 0f
        return cross / (templateNorm * sqrt(variance))
    }
}
