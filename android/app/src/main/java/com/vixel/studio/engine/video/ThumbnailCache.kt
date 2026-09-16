package com.vixel.studio.engine.video

import android.content.Context
import android.graphics.Bitmap
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.util.LruCache
import com.vixel.studio.core.io.ImageIo
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/**
 * Timeline filmstrip frames.
 *
 * A filmstrip asks for the same frame over and over as the timeline scrolls,
 * and every miss costs a decode, so results are cached by (uri, time) rounded
 * to a coarse grid. Rounding is what makes the cache work at all: without it a
 * scroll requests a slightly different timestamp each frame and never hits.
 *
 * Frames are small — they are drawn a few dp high — so a generous entry count
 * still costs little. The cache is sized in bytes rather than entries so an
 * unusually large decode cannot push the app towards its heap limit.
 */
object ThumbnailCache {

    /** Frames are requested on this grid, so nearby scroll positions share one. */
    const val STEP_US = 1_000_000L

    private const val CACHE_BYTES = 12 * 1024 * 1024

    private val cache = object : LruCache<String, Bitmap>(CACHE_BYTES) {
        override fun sizeOf(key: String, value: Bitmap): Int = value.byteCount
    }

    /**
     * Retrievers are not thread-safe and are expensive to open, so one lock
     * serialises decoding. The filmstrip only needs a few frames per scroll and
     * they arrive asynchronously, so serialising them is not felt.
     */
    private val gate = Mutex()

    private fun keyOf(uri: String, timeUs: Long) = "$uri@$timeUs"

    /** Cached frame, if one has already been decoded. Safe on any thread. */
    fun peek(uri: String, timeUs: Long): Bitmap? =
        cache.get(keyOf(uri, snap(timeUs)))

    fun snap(timeUs: Long): Long = (timeUs / STEP_US) * STEP_US

    /**
     * Frame at [timeUs] for [uri], decoding it if needed.
     *
     * Returns null when the media cannot produce a frame; callers draw a plain
     * block in that case rather than leaving a hole in the strip.
     */
    suspend fun frame(
        context: Context,
        uri: String,
        timeUs: Long,
        isImage: Boolean,
        targetHeightPx: Int,
    ): Bitmap? {
        val snapped = snap(timeUs)
        val key = keyOf(uri, snapped)
        cache.get(key)?.let { return it }

        val bitmap = gate.withLock {
            // Another caller may have decoded this while we waited for the lock.
            cache.get(key) ?: withContext(Dispatchers.IO) {
                decode(context, uri, snapped, isImage, targetHeightPx)
            }?.also { cache.put(key, it) }
        }
        return bitmap
    }

    private fun decode(
        context: Context,
        uri: String,
        timeUs: Long,
        isImage: Boolean,
        targetHeightPx: Int,
    ): Bitmap? = try {
        if (isImage) {
            ImageIo.decode(context, Uri.parse(uri), maxEdge = targetHeightPx * 4)
                ?.let { scaleToHeight(it, targetHeightPx) }
        } else {
            videoFrame(context, uri, timeUs, targetHeightPx)
        }
    } catch (t: Throwable) {
        null
    }

    private fun videoFrame(
        context: Context,
        uri: String,
        timeUs: Long,
        targetHeightPx: Int,
    ): Bitmap? {
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(context, Uri.parse(uri))
            // OPTION_CLOSEST_SYNC seeks to a keyframe rather than decoding
            // forward to an exact time. For a strip of thumbnails that is the
            // right trade: it is far cheaper, and a frame or two of drift is
            // invisible at this size.
            val frame = retriever.getFrameAtTime(
                timeUs,
                MediaMetadataRetriever.OPTION_CLOSEST_SYNC,
            )
            frame?.let { scaleToHeight(it, targetHeightPx) }
        } catch (t: Throwable) {
            null
        } finally {
            runCatching { retriever.release() }
        }
    }

    private fun scaleToHeight(source: Bitmap, targetHeightPx: Int): Bitmap {
        if (targetHeightPx <= 0 || source.height <= targetHeightPx) return source
        val ratio = targetHeightPx.toFloat() / source.height
        val width = (source.width * ratio).toInt().coerceAtLeast(1)
        val scaled = Bitmap.createScaledBitmap(source, width, targetHeightPx, true)
        if (scaled != source) source.recycle()
        return scaled
    }

    fun clear() = cache.evictAll()
}
