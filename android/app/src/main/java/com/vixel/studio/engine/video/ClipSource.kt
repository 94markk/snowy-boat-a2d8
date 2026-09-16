package com.vixel.studio.engine.video

import android.content.Context
import android.graphics.Bitmap
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.net.Uri
import android.util.Log
import com.vixel.studio.core.io.ImageIo
import com.vixel.studio.core.model.Clip
import com.vixel.studio.engine.gl.ColorGrader
import com.vixel.studio.engine.gl.GlUtils

private const val TAG = "VixelClipSource"

/**
 * A clip presented as "give me the frame at source time T".
 *
 * The exporter used to own a decode loop per clip and push frames out as they
 * arrived. That cannot render a transition, which needs two clips visible at
 * the same instant. Pulling frames instead lets the compositor drive the
 * timeline and ask each participating clip for its own frame.
 */
interface ClipSource {
    val textureId: Int
    val isExternal: Boolean
    val sourceWidth: Int
    val sourceHeight: Int

    fun transform(): FloatArray

    /**
     * Makes the frame at [sourceTimeUs] current.
     *
     * @return false once the source is exhausted.
     */
    fun advanceTo(sourceTimeUs: Long): Boolean

    fun release()
}

/** Decodes a video file forward, one frame at a time. */
class VideoClipSource(
    private val context: Context,
    private val clip: Clip,
) : ClipSource {

    private val extractor = MediaExtractor()
    private var decoder: MediaCodec? = null
    private var surface: DecoderSurface? = null

    private var inputDone = false
    private var outputDone = false
    private var lastRenderedUs = Long.MIN_VALUE
    private var prepared = false

    override val isExternal: Boolean = true
    override val textureId: Int get() = surface?.textureId ?: 0
    override val sourceWidth: Int get() = clip.displayWidth
    override val sourceHeight: Int get() = clip.displayHeight

    override fun transform(): FloatArray = surface?.transform() ?: ColorGrader.IDENTITY

    fun prepare(): Boolean {
        if (prepared) return true
        return try {
            extractor.setDataSource(context, Uri.parse(clip.uri), null)
            val track = (0 until extractor.trackCount).firstOrNull { index ->
                extractor.getTrackFormat(index)
                    .getString(MediaFormat.KEY_MIME).orEmpty().startsWith("video/")
            } ?: return false

            extractor.selectTrack(track)
            val format = extractor.getTrackFormat(track)

            val decoderSurface = DecoderSurface()
            surface = decoderSurface
            decoder = MediaCodec.createDecoderByType(
                format.getString(MediaFormat.KEY_MIME) ?: return false,
            ).apply {
                configure(format, decoderSurface.surface, null, 0)
                start()
            }

            extractor.seekTo(clip.trimStartUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)
            prepared = true
            true
        } catch (t: Throwable) {
            Log.e(TAG, "could not prepare ${clip.uri}", t)
            false
        }
    }

    /**
     * Decodes forward until the current frame is at or past [sourceTimeUs].
     *
     * Frames before the target are rendered and superseded rather than
     * skipped, which keeps the decoder's reference frames intact.
     */
    override fun advanceTo(sourceTimeUs: Long): Boolean {
        if (!prepared && !prepare()) return false
        val codec = decoder ?: return false
        val decoderSurface = surface ?: return false
        if (outputDone) return false

        val target = sourceTimeUs.coerceAtMost(clip.trimEndUs)
        val info = MediaCodec.BufferInfo()
        var guard = MAX_STEPS

        while (lastRenderedUs < target && guard-- > 0) {
            if (!inputDone) {
                val inputIndex = codec.dequeueInputBuffer(TIMEOUT_US)
                if (inputIndex >= 0) {
                    val buffer = codec.getInputBuffer(inputIndex)
                    val size = if (buffer == null) -1 else extractor.readSampleData(buffer, 0)
                    if (size < 0) {
                        codec.queueInputBuffer(
                            inputIndex, 0, 0, 0L, MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                        )
                        inputDone = true
                    } else {
                        codec.queueInputBuffer(inputIndex, 0, size, extractor.sampleTime, 0)
                        extractor.advance()
                    }
                }
            }

            val outputIndex = codec.dequeueOutputBuffer(info, TIMEOUT_US)
            if (outputIndex >= 0) {
                val eos = (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM) != 0
                val render = info.size > 0
                codec.releaseOutputBuffer(outputIndex, render)
                if (render && decoderSurface.awaitNewImage()) {
                    lastRenderedUs = info.presentationTimeUs
                }
                if (eos) {
                    outputDone = true
                    break
                }
            }
        }
        return lastRenderedUs != Long.MIN_VALUE
    }

    override fun release() {
        runCatching { decoder?.stop() }
        runCatching { decoder?.release() }
        runCatching { surface?.release() }
        runCatching { extractor.release() }
        decoder = null
        surface = null
        prepared = false
    }

    private companion object {
        const val TIMEOUT_US = 10_000L
        /** Stops a malformed stream from spinning the compositor forever. */
        const val MAX_STEPS = 600
    }
}

/** A still image, which is simply always ready. */
class ImageClipSource(context: Context, clip: Clip) : ClipSource {

    private var texture = 0
    private var width = 0
    private var height = 0

    init {
        val bitmap: Bitmap? = ImageIo.decode(context, Uri.parse(clip.uri), maxEdge = 2160)
        if (bitmap != null) {
            texture = GlUtils.createTextureFromBitmap(bitmap)
            width = bitmap.width
            height = bitmap.height
            bitmap.recycle()
        }
    }

    override val textureId: Int get() = texture
    override val isExternal: Boolean = false
    override val sourceWidth: Int get() = width
    override val sourceHeight: Int get() = height

    override fun transform(): FloatArray = ColorGrader.IDENTITY

    override fun advanceTo(sourceTimeUs: Long): Boolean = texture != 0

    override fun release() {
        GlUtils.deleteTexture(texture)
        texture = 0
    }
}
