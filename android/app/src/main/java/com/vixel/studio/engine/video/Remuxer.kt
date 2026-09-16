package com.vixel.studio.engine.video

import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMuxer
import android.util.Log
import java.io.File
import java.nio.ByteBuffer

private const val TAG = "VixelRemux"

/**
 * Copies already-encoded tracks into one container.
 *
 * Video and audio are produced in separate passes — a muxer needs every track
 * added before it starts, and the encoders only publish their real output
 * format partway through. Joining afterwards keeps each pass simple and costs
 * only a sample copy, with no re-encode and no quality loss.
 */
object Remuxer {

    private const val MAX_SAMPLE_SIZE = 1 shl 21

    fun combine(video: File, audio: File?, output: File): Boolean {
        if (!video.exists()) return false

        var muxer: MediaMuxer? = null
        val extractors = mutableListOf<MediaExtractor>()

        try {
            muxer = MediaMuxer(output.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)

            val videoTrack = addTrack(muxer, video, "video/", extractors) ?: return false
            val audioTrack = if (audio != null && audio.exists()) {
                addTrack(muxer, audio, "audio/", extractors)
            } else {
                null
            }

            muxer.start()

            copy(videoTrack, muxer)
            audioTrack?.let { copy(it, muxer) }

            muxer.stop()
            return true
        } catch (t: Throwable) {
            Log.e(TAG, "remux failed", t)
            runCatching { output.delete() }
            return false
        } finally {
            extractors.forEach { runCatching { it.release() } }
            runCatching { muxer?.release() }
        }
    }

    private class TrackCopy(
        val extractor: MediaExtractor,
        val outputIndex: Int,
    )

    private fun addTrack(
        muxer: MediaMuxer,
        file: File,
        mimePrefix: String,
        extractors: MutableList<MediaExtractor>,
    ): TrackCopy? {
        val extractor = MediaExtractor()
        extractors += extractor
        extractor.setDataSource(file.absolutePath)

        for (i in 0 until extractor.trackCount) {
            val format = extractor.getTrackFormat(i)
            if (format.getString(MediaFormat.KEY_MIME).orEmpty().startsWith(mimePrefix)) {
                extractor.selectTrack(i)
                return TrackCopy(extractor, muxer.addTrack(format))
            }
        }
        Log.w(TAG, "no $mimePrefix track in ${file.name}")
        return null
    }

    private fun copy(track: TrackCopy, muxer: MediaMuxer) {
        val buffer = ByteBuffer.allocate(MAX_SAMPLE_SIZE)
        val info = MediaCodec.BufferInfo()

        while (true) {
            buffer.clear()
            val size = track.extractor.readSampleData(buffer, 0)
            if (size < 0) break

            info.offset = 0
            info.size = size
            info.presentationTimeUs = track.extractor.sampleTime
            info.flags = sampleFlagsToBufferFlags(track.extractor.sampleFlags)

            muxer.writeSampleData(track.outputIndex, buffer, info)
            track.extractor.advance()
        }
    }

    private fun sampleFlagsToBufferFlags(sampleFlags: Int): Int {
        var flags = 0
        if (sampleFlags and MediaExtractor.SAMPLE_FLAG_SYNC != 0) {
            flags = flags or MediaCodec.BUFFER_FLAG_KEY_FRAME
        }
        return flags
    }
}
