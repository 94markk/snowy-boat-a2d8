package com.delicat.studio.engine

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.util.LruCache
import com.delicat.studio.model.MediaKind
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.util.Collections
import java.util.concurrent.ConcurrentHashMap
import kotlin.math.max
import kotlin.math.roundToInt

/**
 * Decoded frames, kept around and handed out by key.
 *
 * The filmstrip asks for dozens of these while the user drags, and the
 * preview asks for one every time a transition needs the clip behind it. Both
 * are cheap to satisfy from memory and expensive to satisfy from a file, so
 * the rules here are about not doing the expensive thing twice.
 *
 * Extraction is serialised through a mutex. That is not caution about thread
 * safety alone: each [MediaMetadataRetriever] holds a decoder, and letting a
 * screenful of thumbnails open one apiece is how a previous version of this
 * app exhausted native memory and got itself killed. One at a time is also
 * fast enough, because opening the file is the expensive part and the pool
 * below means it usually does not happen at all.
 */
class FrameCache(
    private val context: Context,
    maxBytes: Int = DEFAULT_BUDGET,
    private val dispatcher: CoroutineDispatcher = singleThreadIo(),
) {

    private val bitmaps = object : LruCache<String, Bitmap>(maxBytes) {
        override fun sizeOf(key: String, value: Bitmap): Int = value.byteCount
    }

    private val gate = Mutex()
    private val open = LinkedHashMap<String, MediaMetadataRetriever>()

    /** Keys that failed, so a broken file is not reopened on every rebuild. */
    private val refused: MutableSet<String> =
        Collections.newSetFromMap(ConcurrentHashMap<String, Boolean>())

    /** Already decoded, or null. Safe to call while composing. */
    fun peek(key: String): Bitmap? = bitmaps.get(key)

    fun keyFor(uri: String, timeUs: Long, edge: Int): String = "$uri@$timeUs#$edge"

    /**
     * A frame at [timeUs], scaled so its longest edge is about [edge] pixels.
     *
     * Returns whatever is cached without touching a decoder, and otherwise
     * decodes once. Repeated calls for the same key while one is in flight
     * queue on the mutex and the later ones then find the cached result, so a
     * burst of identical requests costs one decode.
     */
    suspend fun frame(uri: Uri, kind: MediaKind, timeUs: Long, edge: Int): Bitmap? {
        val key = keyFor(uri.toString(), timeUs, edge)
        bitmaps.get(key)?.let { return it }
        if (key in refused) return null

        return withContext(dispatcher) {
            gate.withLock {
                bitmaps.get(key)?.let { return@withLock it }

                val bitmap = runCatching {
                    if (kind == MediaKind.IMAGE) still(uri, edge) else videoFrame(uri, timeUs, edge)
                }.getOrNull()

                if (bitmap == null) {
                    refused += key
                } else {
                    bitmaps.put(key, bitmap)
                }
                bitmap
            }
        }
    }

    fun clear() {
        bitmaps.evictAll()
        refused.clear()
        synchronized(open) {
            open.values.forEach { runCatching { it.release() } }
            open.clear()
        }
    }

    // ---- decoding ---------------------------------------------------------

    private fun videoFrame(uri: Uri, timeUs: Long, edge: Int): Bitmap? {
        val retriever = retrieverFor(uri) ?: return null
        // CLOSEST_SYNC rather than CLOSEST: a filmstrip wants the nearest
        // keyframe quickly, and asking for an exact frame makes the decoder
        // walk forward from that keyframe anyway, one row of thumbnails at a
        // time.
        val frame = retriever.getFrameAtTime(
            max(0L, timeUs),
            MediaMetadataRetriever.OPTION_CLOSEST_SYNC,
        ) ?: retriever.getFrameAtTime(max(0L, timeUs), MediaMetadataRetriever.OPTION_CLOSEST)
        ?: return null

        return scaleToEdge(frame, edge)
    }

    private fun still(uri: Uri, edge: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, bounds)
        }
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        // Sub-sampled during decode rather than scaled after it, so a
        // forty-megapixel photo never exists at full size in memory.
        var sample = 1
        val longest = max(bounds.outWidth, bounds.outHeight)
        while (longest / (sample * 2) >= edge) sample *= 2

        val options = BitmapFactory.Options().apply {
            inSampleSize = sample
            inPreferredConfig = Bitmap.Config.ARGB_8888
        }
        val decoded = context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, options)
        } ?: return null

        return scaleToEdge(decoded, edge)
    }

    private fun scaleToEdge(source: Bitmap, edge: Int): Bitmap {
        val longest = max(source.width, source.height)
        if (longest <= edge || longest == 0) return source
        val factor = edge.toFloat() / longest
        val scaled = Bitmap.createScaledBitmap(
            source,
            max(1, (source.width * factor).roundToInt()),
            max(1, (source.height * factor).roundToInt()),
            true,
        )
        if (scaled !== source) source.recycle()
        return scaled
    }

    /**
     * Retrievers are pooled because `setDataSource` is the slow part: it
     * parses the container and starts a decoder, which dwarfs the cost of
     * pulling a frame once that is done.
     */
    private fun retrieverFor(uri: Uri): MediaMetadataRetriever? = synchronized(open) {
        val key = uri.toString()
        open.remove(key)?.let {
            open[key] = it
            return it
        }
        val created = MediaMetadataRetriever()
        val ok = runCatching { created.setDataSource(context, uri) }.isSuccess
        if (!ok) {
            runCatching { created.release() }
            return null
        }
        open[key] = created
        while (open.size > MAX_OPEN) {
            val oldest = open.keys.first()
            open.remove(oldest)?.let { runCatching { it.release() } }
        }
        created
    }

    companion object {
        /** Enough for a long filmstrip without competing with the decoder. */
        val DEFAULT_BUDGET: Int
            get() = (Runtime.getRuntime().maxMemory() / 8).coerceIn(
                8L * 1024 * 1024,
                48L * 1024 * 1024,
            ).toInt()

        private const val MAX_OPEN = 3
    }
}

/**
 * One thread for every decode in the app.
 *
 * Frame extraction is bound by the hardware decoder rather than by the CPU,
 * so widening this buys no throughput and costs a decoder instance per thread.
 */
@OptIn(ExperimentalCoroutinesApi::class)
private fun singleThreadIo(): CoroutineDispatcher = Dispatchers.IO.limitedParallelism(1)
