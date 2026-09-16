package com.vixel.studio.engine.audio

import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaFormat
import android.media.MediaMuxer
import android.util.Log
import java.io.BufferedInputStream
import java.io.File
import java.io.FileInputStream
import kotlin.math.min

private const val TAG = "VixelAac"

/** Encodes canonical PCM to AAC-LC inside an MP4 container. */
object AacEncoder {

    private const val MIME = MediaFormat.MIMETYPE_AUDIO_AAC
    private const val TIMEOUT_US = 10_000L

    fun encode(pcm: File, output: File, bitRate: Int = 128_000): Boolean {
        if (!pcm.exists() || pcm.length() < PcmDecoder.BYTES_PER_FRAME) return false

        var encoder: MediaCodec? = null
        var muxer: MediaMuxer? = null
        var track = -1
        var started = false

        try {
            val format = MediaFormat.createAudioFormat(
                MIME, PcmDecoder.SAMPLE_RATE, PcmDecoder.CHANNELS,
            ).apply {
                setInteger(
                    MediaFormat.KEY_AAC_PROFILE,
                    MediaCodecInfo.CodecProfileLevel.AACObjectLC,
                )
                setInteger(MediaFormat.KEY_BIT_RATE, bitRate)
                setInteger(MediaFormat.KEY_MAX_INPUT_SIZE, 1 shl 16)
            }

            encoder = MediaCodec.createEncoderByType(MIME)
            encoder.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
            encoder.start()

            muxer = MediaMuxer(output.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)

            val bufferInfo = MediaCodec.BufferInfo()
            var framesSubmitted = 0L
            var inputDone = false
            var drainAttemptsLeft = 500

            BufferedInputStream(FileInputStream(pcm), 1 shl 16).use { input ->
                val chunk = ByteArray(1 shl 12)

                while (true) {
                    if (!inputDone) {
                        val index = encoder.dequeueInputBuffer(TIMEOUT_US)
                        if (index >= 0) {
                            val buffer = encoder.getInputBuffer(index)
                            if (buffer == null) {
                                inputDone = true
                            } else {
                                buffer.clear()
                                val capacity = min(chunk.size, buffer.capacity())
                                val read = input.read(chunk, 0, capacity)
                                if (read <= 0) {
                                    encoder.queueInputBuffer(
                                        index, 0, 0,
                                        PcmDecoder.framesToUs(framesSubmitted),
                                        MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                                    )
                                    inputDone = true
                                } else {
                                    buffer.put(chunk, 0, read)
                                    encoder.queueInputBuffer(
                                        index, 0, read,
                                        PcmDecoder.framesToUs(framesSubmitted), 0,
                                    )
                                    framesSubmitted += read / PcmDecoder.BYTES_PER_FRAME
                                }
                            }
                        }
                    }

                    val outIndex = encoder.dequeueOutputBuffer(bufferInfo, TIMEOUT_US)
                    if (outIndex == MediaCodec.INFO_TRY_AGAIN_LATER && inputDone) {
                        // Same guard as the video path: do not spin forever if
                        // the encoder never flags end of stream.
                        if (--drainAttemptsLeft <= 0) {
                            Log.w(TAG, "aac encoder did not report end of stream")
                            return@use
                        }
                    }
                    when {
                        outIndex == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> {
                            if (!started) {
                                track = muxer.addTrack(encoder.outputFormat)
                                muxer.start()
                                started = true
                            }
                        }
                        outIndex >= 0 -> {
                            val buffer = encoder.getOutputBuffer(outIndex)
                            val isConfig =
                                (bufferInfo.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG) != 0
                            if (buffer != null && !isConfig && bufferInfo.size > 0 && started) {
                                buffer.position(bufferInfo.offset)
                                buffer.limit(bufferInfo.offset + bufferInfo.size)
                                muxer.writeSampleData(track, buffer, bufferInfo)
                            }
                            encoder.releaseOutputBuffer(outIndex, false)
                            if ((bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM) != 0) {
                                return@use
                            }
                        }
                    }
                }
            }

            return started
        } catch (t: Throwable) {
            Log.e(TAG, "aac encode failed", t)
            return false
        } finally {
            runCatching { encoder?.stop() }
            runCatching { encoder?.release() }
            if (started) runCatching { muxer?.stop() }
            runCatching { muxer?.release() }
        }
    }
}
