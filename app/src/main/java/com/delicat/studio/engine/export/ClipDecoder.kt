package com.delicat.studio.engine.export

import android.content.Context
import android.graphics.SurfaceTexture
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.net.Uri
import android.os.Handler
import android.os.HandlerThread
import android.view.Surface
import com.delicat.studio.engine.gl.GlUtil

/**
 * Decodes one clip's video, a frame at a time, into a texture.
 *
 * Export cannot use [android.media.MediaMetadataRetriever] the way the
 * filmstrip does: that reopens and re-seeks for every frame, and a minute of
 * footage is eighteen hundred of them. This keeps one decoder running and
 * walks it forward, which is the difference between an export that takes
 * seconds and one the user gives up on.
 *
 * Every method must be called on the thread holding the export GL context,
 * because [SurfaceTexture.updateTexImage] binds to whatever context is
 * current.
 */
class ClipDecoder(
    context: Context,
    uri: Uri,
) {

    val textureId: Int = GlUtil.createTexture(GlUtil.EXTERNAL_TEXTURE)

    private val extractor = MediaExtractor()
    private val codec: MediaCodec
    private val surfaceTexture: SurfaceTexture
    private val surface: Surface
    private val bufferInfo = MediaCodec.BufferInfo()

    /**
     * Frames arrive on a thread of their own.
     *
     * The waiting thread is the one that will consume the frame, so the
     * notification cannot be delivered there — it would be signalling a
     * thread that is blocked waiting for the signal.
     */
    private val callbackThread = HandlerThread("delicat-decode").apply { start() }
    private val frameLock = Object()
    private var frameAvailable = false

    private var inputDone = false
    private var outputDone = false

    /** Presentation time of the frame currently in the texture, or -1. */
    var currentTimeUs: Long = -1L
        private set

    val width: Int
    val height: Int
    val rotationDegrees: Int

    init {
        extractor.setDataSource(context, uri, null)
        var track = -1
        var format: MediaFormat? = null
        for (i in 0 until extractor.trackCount) {
            val candidate = extractor.getTrackFormat(i)
            if (candidate.getString(MediaFormat.KEY_MIME)?.startsWith("video/") == true) {
                track = i
                format = candidate
                break
            }
        }
        requireNotNull(format) { "no video track" }
        extractor.selectTrack(track)

        width = format.getInteger(MediaFormat.KEY_WIDTH)
        height = format.getInteger(MediaFormat.KEY_HEIGHT)
        rotationDegrees = if (format.containsKey(KEY_ROTATION)) {
            format.getInteger(KEY_ROTATION)
        } else {
            0
        }

        surfaceTexture = SurfaceTexture(textureId)
        // Stated rather than left at zero, which a decoder may refuse.
        surfaceTexture.setDefaultBufferSize(width, height)
        surfaceTexture.setOnFrameAvailableListener(
            {
                synchronized(frameLock) {
                    frameAvailable = true
                    frameLock.notifyAll()
                }
            },
            Handler(callbackThread.looper),
        )
        surface = Surface(surfaceTexture)

        codec = MediaCodec.createDecoderByType(
            format.getString(MediaFormat.KEY_MIME) ?: "video/avc",
        )
        codec.configure(format, surface, null, 0)
        codec.start()
    }

    fun getTransformMatrix(out: FloatArray) = surfaceTexture.getTransformMatrix(out)

    /**
     * Restarts decoding from the sync sample at or before [timeUs].
     *
     * Only worth doing when jumping backwards or a long way forwards; walking
     * forward frame by frame is cheaper than a flush for anything nearby,
     * because a seek lands on a keyframe and the decoder then has to replay
     * everything between it and the frame actually wanted.
     */
    fun seekTo(timeUs: Long) {
        extractor.seekTo(timeUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)
        codec.flush()
        inputDone = false
        outputDone = false
        currentTimeUs = -1L
        synchronized(frameLock) { frameAvailable = false }
    }

    /**
     * Advances until the texture holds the frame covering [targetUs].
     *
     * Returns false only when the clip ends first, in which case the texture
     * keeps the last frame — which is what should be drawn, rather than black.
     */
    fun renderUpTo(targetUs: Long): Boolean {
        if (currentTimeUs in 0..Long.MAX_VALUE && currentTimeUs >= targetUs) return true

        var rendered = false
        var guard = 0
        while (currentTimeUs < targetUs && !outputDone && guard++ < MAX_STEPS) {
            feed()
            if (drain(targetUs)) rendered = true
        }
        return rendered || currentTimeUs >= 0
    }

    private fun feed() {
        if (inputDone) return
        val index = codec.dequeueInputBuffer(TIMEOUT_US)
        if (index < 0) return

        val buffer = codec.getInputBuffer(index) ?: return
        val size = extractor.readSampleData(buffer, 0)
        if (size < 0) {
            codec.queueInputBuffer(index, 0, 0, 0L, MediaCodec.BUFFER_FLAG_END_OF_STREAM)
            inputDone = true
        } else {
            codec.queueInputBuffer(index, 0, size, extractor.sampleTime, 0)
            extractor.advance()
        }
    }

    /** Returns true when a frame was put into the texture. */
    private fun drain(targetUs: Long): Boolean {
        val index = codec.dequeueOutputBuffer(bufferInfo, TIMEOUT_US)
        when {
            index == MediaCodec.INFO_TRY_AGAIN_LATER -> return false
            index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> return false
            index < 0 -> return false
        }

        if (bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) {
            outputDone = true
        }

        // Frames before the target are dropped rather than drawn: releasing
        // them without rendering skips the composition step entirely, which
        // is most of the cost of catching up after a seek.
        val wanted = bufferInfo.size > 0 && bufferInfo.presentationTimeUs >= targetUs
        val last = outputDone && bufferInfo.size > 0
        val render = wanted || last

        codec.releaseOutputBuffer(index, render)
        if (!render) return false

        awaitFrame()
        surfaceTexture.updateTexImage()
        currentTimeUs = bufferInfo.presentationTimeUs
        return true
    }

    private fun awaitFrame() {
        synchronized(frameLock) {
            val deadline = System.currentTimeMillis() + FRAME_WAIT_MS
            while (!frameAvailable) {
                val remaining = deadline - System.currentTimeMillis()
                if (remaining <= 0) return
                runCatching { frameLock.wait(remaining) }
            }
            frameAvailable = false
        }
    }

    fun release() {
        runCatching { codec.stop() }
        runCatching { codec.release() }
        runCatching { extractor.release() }
        surface.release()
        surfaceTexture.setOnFrameAvailableListener(null)
        surfaceTexture.release()
        GlUtil.deleteTexture(textureId)
        callbackThread.quitSafely()
    }

    private companion object {
        const val TIMEOUT_US = 10_000L
        const val FRAME_WAIT_MS = 2_500L

        /** Enough to cross any sane keyframe interval; a stop rather than a hang. */
        const val MAX_STEPS = 900

        const val KEY_ROTATION = "rotation-degrees"
    }
}
