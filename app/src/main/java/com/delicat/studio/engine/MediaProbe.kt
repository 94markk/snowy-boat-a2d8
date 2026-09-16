package com.delicat.studio.engine

import android.content.Context
import android.graphics.BitmapFactory
import android.media.ExifInterface
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.provider.OpenableColumns
import android.webkit.MimeTypeMap
import com.delicat.studio.model.Clip
import com.delicat.studio.model.MediaKind

/** What the app needs to know about a file before it can become a clip. */
data class MediaInfo(
    val uri: Uri,
    val kind: MediaKind,
    val durationUs: Long,
    val width: Int,
    val height: Int,
    val rotationDegrees: Int,
    val hasAudio: Boolean,
    val displayName: String,
)

/**
 * Identifies an imported file and measures it.
 *
 * The previous version of this app asked [MediaMetadataRetriever] whether a
 * file had a video track and treated "no" as "unsupported". A JPEG answers no,
 * so every photo import failed silently. Type is decided here by the MIME the
 * content provider reports, which is the only thing that actually knows; the
 * measuring step is then chosen to match, and each path can fall back to the
 * other because a provider is allowed to report `application/octet-stream` and
 * some do.
 */
object MediaProbe {

    fun probe(context: Context, uri: Uri): MediaInfo? {
        val name = displayName(context, uri)
        val mime = mimeOf(context, uri, name)

        return when {
            mime?.startsWith("image/") == true ->
                asImage(context, uri, name) ?: asVideo(context, uri, name)
            mime?.startsWith("video/") == true || mime?.startsWith("audio/") == true ->
                asVideo(context, uri, name) ?: asImage(context, uri, name)
            // Unknown type: video costs more to open, so try the cheap test
            // first and only spin up a retriever if the bytes are not an image.
            else -> asImage(context, uri, name) ?: asVideo(context, uri, name)
        }
    }

    fun probeAll(context: Context, uris: List<Uri>): List<MediaInfo> =
        uris.mapNotNull { runCatching { probe(context, it) }.getOrNull() }

    // ---- type -------------------------------------------------------------

    private fun mimeOf(context: Context, uri: Uri, name: String): String? {
        context.contentResolver.getType(uri)?.takeIf { it.isNotBlank() }?.let { return it }

        val ext = MimeTypeMap.getFileExtensionFromUrl(uri.toString()).ifBlank {
            name.substringAfterLast('.', "")
        }
        if (ext.isBlank()) return null
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext.lowercase())
    }

    private fun displayName(context: Context, uri: Uri): String {
        runCatching {
            context.contentResolver.query(uri, null, null, null, null)?.use { cursor ->
                val column = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (column >= 0 && cursor.moveToFirst()) {
                    cursor.getString(column)?.takeIf { it.isNotBlank() }?.let { return it }
                }
            }
        }
        return uri.lastPathSegment?.substringAfterLast('/') ?: "Clip"
    }

    // ---- measuring --------------------------------------------------------

    /**
     * Reads the header only. `inJustDecodeBounds` means no pixels are
     * allocated, so this stays cheap enough to run on a large selection
     * without risking an out-of-memory on the import itself.
     */
    private fun asImage(context: Context, uri: Uri, name: String): MediaInfo? {
        val options = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        val decoded = runCatching {
            context.contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, options)
            }
        }.getOrNull()
        // decodeStream returns null in bounds mode by design; the width is
        // what says whether it parsed.
        if (options.outWidth <= 0 || options.outHeight <= 0) return null
        @Suppress("UNUSED_EXPRESSION") decoded

        return MediaInfo(
            uri = uri,
            kind = MediaKind.IMAGE,
            durationUs = Clip.DEFAULT_IMAGE_DURATION_US,
            width = options.outWidth,
            height = options.outHeight,
            rotationDegrees = exifRotation(context, uri),
            hasAudio = false,
            displayName = name,
        )
    }

    private fun exifRotation(context: Context, uri: Uri): Int = runCatching {
        context.contentResolver.openInputStream(uri)?.use { stream ->
            when (
                ExifInterface(stream).getAttributeInt(
                    ExifInterface.TAG_ORIENTATION,
                    ExifInterface.ORIENTATION_NORMAL,
                )
            ) {
                ExifInterface.ORIENTATION_ROTATE_90 -> 90
                ExifInterface.ORIENTATION_ROTATE_180 -> 180
                ExifInterface.ORIENTATION_ROTATE_270 -> 270
                else -> 0
            }
        } ?: 0
    }.getOrDefault(0)

    private fun asVideo(context: Context, uri: Uri, name: String): MediaInfo? {
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(context, uri)
            val hasVideo = retriever.extractMetadata(
                MediaMetadataRetriever.METADATA_KEY_HAS_VIDEO,
            ) == "yes"
            if (!hasVideo) return null

            val durationMs = retriever
                .extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)
                ?.toLongOrNull() ?: return null
            if (durationMs <= 0L) return null

            val width = retriever.intKey(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)
            val height = retriever.intKey(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)
            if (width <= 0 || height <= 0) return null

            MediaInfo(
                uri = uri,
                kind = MediaKind.VIDEO,
                durationUs = durationMs * 1_000L,
                width = width,
                height = height,
                rotationDegrees = retriever
                    .intKey(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION),
                hasAudio = retriever.extractMetadata(
                    MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO,
                ) == "yes",
                displayName = name,
            )
        } catch (_: Throwable) {
            // Anything from an unreadable Uri to a codec the device lacks.
            // A file the app cannot open is a file it will not offer to edit.
            null
        } finally {
            runCatching { retriever.release() }
        }
    }

    private fun MediaMetadataRetriever.intKey(key: Int): Int =
        extractMetadata(key)?.toIntOrNull() ?: 0
}
