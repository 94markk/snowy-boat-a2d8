package com.vixel.studio.engine.video

import android.content.Context
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.provider.OpenableColumns
import android.util.Log
import android.webkit.MimeTypeMap
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.core.model.Clip
import com.vixel.studio.core.model.MediaKind

data class MediaInfo(
    val kind: MediaKind,
    val durationUs: Long,
    val width: Int,
    val height: Int,
    val rotationDegrees: Int,
    val hasAudio: Boolean,
    val title: String,
)

object MediaProbe {

    private const val TAG = "MediaProbe"

    /**
     * Reads what the engine needs to lay a file on the timeline. Returns null
     * when the file is unreadable rather than throwing — an unopenable pick
     * should surface as a message, not a crash.
     */
    fun probe(context: Context, uri: Uri): MediaInfo? {
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(context, uri)

            val hasVideo = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_VIDEO) == "yes"
            val hasAudio = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO) == "yes"

            val durationMs = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)
                ?.toLongOrNull() ?: 0L
            val width = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)
                ?.toIntOrNull() ?: 0
            val height = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)
                ?.toIntOrNull() ?: 0
            val rotation = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION)
                ?.toIntOrNull() ?: 0
            val title = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_TITLE)
                ?: uri.lastPathSegment.orEmpty()

            val kind = when {
                hasVideo -> MediaKind.VIDEO
                hasAudio -> MediaKind.AUDIO
                else -> return null
            }

            MediaInfo(
                kind = kind,
                durationUs = durationMs * 1000L,
                width = width,
                height = height,
                rotationDegrees = ((rotation % 360) + 360) % 360,
                hasAudio = hasAudio,
                title = title,
            )
        } catch (t: Throwable) {
            null
        } finally {
            runCatching { retriever.release() }
        }
    }

    /**
     * Builds a timeline clip for anything the picker can hand back.
     *
     * Stills are checked first and never go near [probe].
     * MediaMetadataRetriever does not report HAS_VIDEO for a JPEG, so routing
     * images through it rejected every single one of them.
     *
     * Detection does not trust the declared type on its own. A provider can
     * report a null or wrong MIME, so a file that claims to be an image but
     * will not decode falls through to the video path, and a video whose
     * metadata cannot be read gets one last attempt as a still. Only a file
     * that fails as both is rejected.
     */
    fun clipFor(context: Context, uri: Uri): Clip? {
        val mime = mimeOf(context, uri)

        if (mime?.startsWith("image/") == true) {
            imageClipOrNull(context, uri)?.let { return it }
        }

        val info = probe(context, uri)
        if (info != null && info.kind != MediaKind.AUDIO) {
            // A stream can be playable while its duration header is missing or
            // zero. Falling back to a still-image length keeps the clip on the
            // timeline, trimmable, instead of discarding a usable file.
            val duration = info.durationUs
                .takeIf { it > 0L }
                ?: Clip.DEFAULT_IMAGE_DURATION_US

            return Clip(
                uri = uri.toString(),
                kind = info.kind,
                sourceDurationUs = duration.coerceAtLeast(Clip.MIN_CLIP_US),
                trimEndUs = duration.coerceAtLeast(Clip.MIN_CLIP_US),
                sourceWidth = info.width,
                sourceHeight = info.height,
                sourceRotationDegrees = info.rotationDegrees,
            )
        }

        return imageClipOrNull(context, uri).also {
            if (it == null) Log.w(TAG, "unreadable as both video and image: mime=$mime")
        }
    }

    /** Declared type of [uri], preferring the resolver over the file name. */
    private fun mimeOf(context: Context, uri: Uri): String? {
        context.contentResolver.getType(uri)?.let { return it }
        val name = queryDisplayName(context, uri) ?: uri.lastPathSegment ?: return null
        val extension = name.substringAfterLast('.', "").lowercase()
        if (extension.isEmpty()) return null
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension)
    }

    private fun queryDisplayName(context: Context, uri: Uri): String? = try {
        context.contentResolver
            .query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
            ?.use { cursor ->
                if (cursor.moveToFirst()) cursor.getString(0) else null
            }
    } catch (t: Throwable) {
        null
    }

    private fun imageClipOrNull(context: Context, uri: Uri): Clip? {
        val (width, height) = ImageIo.boundsOf(context, uri) ?: return null
        return imageClip(uri, width, height)
    }

    /** Builds a clip for a still image, which has no intrinsic duration. */
    fun imageClip(uri: Uri, width: Int, height: Int): Clip = Clip(
        uri = uri.toString(),
        kind = MediaKind.IMAGE,
        sourceDurationUs = Clip.DEFAULT_IMAGE_DURATION_US,
        trimEndUs = Clip.DEFAULT_IMAGE_DURATION_US,
        sourceWidth = width,
        sourceHeight = height,
    )
}
