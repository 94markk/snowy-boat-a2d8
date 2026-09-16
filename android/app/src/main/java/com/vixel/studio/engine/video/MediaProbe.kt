package com.vixel.studio.engine.video

import android.content.Context
import android.media.MediaMetadataRetriever
import android.net.Uri
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

    fun clipFor(context: Context, uri: Uri): Clip? {
        val info = probe(context, uri) ?: return null
        if (info.kind == MediaKind.AUDIO) return null
        return Clip(
            uri = uri.toString(),
            kind = info.kind,
            sourceDurationUs = info.durationUs.coerceAtLeast(Clip.MIN_CLIP_US),
            trimEndUs = info.durationUs.coerceAtLeast(Clip.MIN_CLIP_US),
            sourceWidth = info.width,
            sourceHeight = info.height,
            sourceRotationDegrees = info.rotationDegrees,
        )
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
