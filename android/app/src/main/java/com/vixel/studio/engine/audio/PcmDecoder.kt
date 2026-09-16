package com.vixel.studio.engine.audio

import android.content.Context
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.net.Uri
import android.util.Log
import java.io.BufferedOutputStream
import java.io.File
import java.io.FileOutputStream
import java.io.OutputStream
import kotlin.math.max
import kotlin.math.roundToInt

private const val TAG = "VixelPcm"

/**
 * Decodes an audio track to raw PCM on disk.
 *
 * Everything downstream works in one canonical format — 44.1 kHz, stereo,
 * signed 16-bit little-endian — so the mixer never has to reason about a
 * source's own rate or channel count. Going to disk rather than memory keeps a
 * long timeline from being bounded by heap.
 *
 * Decoding and rate conversion are two separate passes. Resampling across
 * decoder buffer boundaries needs continuous phase, and doing it inline means
 * carrying state between calls; a second pass over a contiguous file gets the
 * same result with none of that.
 */
object PcmDecoder {

    const val SAMPLE_RATE = 44_100
    const val CHANNELS = 2
    const val BYTES_PER_SAMPLE = 2
    const val BYTES_PER_FRAME = CHANNELS * BYTES_PER_SAMPLE

    private const val TIMEOUT_US = 10_000L

    fun framesToUs(frames: Long): Long = frames * 1_000_000L / SAMPLE_RATE

    fun usToFrames(us: Long): Long = us * SAMPLE_RATE / 1_000_000L

    /**
     * Writes the region [startUs, endUs) of [uri]'s audio into [output].
     *
     * @param speed above 1 shortens the result. Pitch moves with it, the way a
     *   tape speeds up — which is how a speed control behaves in most mobile
     *   editors when pitch correction is off.
     * @return stereo frames written; 0 when the file has no audio track.
     */
    fun decode(
        context: Context,
        uri: Uri,
        startUs: Long,
        endUs: Long,
        output: File,
        speed: Float = 1f,
    ): Long {
        val scratch = File(output.parentFile, "${output.name}.src")
        try {
            val sourceRate = decodeToStereo(context, uri, startUs, endUs, scratch)
            if (sourceRate <= 0) return 0L

            val ratio = (SAMPLE_RATE.toDouble() / sourceRate) / max(0.05f, speed).toDouble()
            return resampleFile(scratch, output, ratio)
        } finally {
            scratch.delete()
        }
    }

    /**
     * Pass 1 — decode to stereo 16-bit at the source's own sample rate.
     * @return the source sample rate, or -1 if nothing was decoded.
     */
    private fun decodeToStereo(
        context: Context,
        uri: Uri,
        startUs: Long,
        endUs: Long,
        output: File,
    ): Int {
        val extractor = MediaExtractor()
        var decoder: MediaCodec? = null
        var sourceRate = -1

        try {
            extractor.setDataSource(context, uri, null)
            val trackIndex = (0 until extractor.trackCount).firstOrNull { index ->
                extractor.getTrackFormat(index)
                    .getString(MediaFormat.KEY_MIME)
                    .orEmpty()
                    .startsWith("audio/")
            } ?: return -1

            extractor.selectTrack(trackIndex)
            val inputFormat = extractor.getTrackFormat(trackIndex)
            sourceRate = inputFormat.intOr(MediaFormat.KEY_SAMPLE_RATE, SAMPLE_RATE)
            var channels = inputFormat.intOr(MediaFormat.KEY_CHANNEL_COUNT, 2)

            decoder = MediaCodec.createDecoderByType(
                inputFormat.getString(MediaFormat.KEY_MIME) ?: return -1,
            )
            decoder.configure(inputFormat, null, null, 0)
            decoder.start()
            extractor.seekTo(startUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)

            BufferedOutputStream(FileOutputStream(output), 1 shl 16).use { sink ->
                val bufferInfo = MediaCodec.BufferInfo()
                var inputDone = false
                var outputDone = false

                while (!outputDone) {
                    if (!inputDone) {
                        val inputIndex = decoder.dequeueInputBuffer(TIMEOUT_US)
                        if (inputIndex >= 0) {
                            val buffer = decoder.getInputBuffer(inputIndex)
                            val size = if (buffer == null) -1 else extractor.readSampleData(buffer, 0)
                            val sampleTime = extractor.sampleTime
                            if (size < 0 || (sampleTime >= 0 && sampleTime > endUs)) {
                                decoder.queueInputBuffer(
                                    inputIndex, 0, 0, 0L, MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                                )
                                inputDone = true
                            } else {
                                decoder.queueInputBuffer(inputIndex, 0, size, sampleTime, 0)
                                extractor.advance()
                            }
                        }
                    }

                    val outputIndex = decoder.dequeueOutputBuffer(bufferInfo, TIMEOUT_US)
                    if (outputIndex == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED) {
                        val actual = decoder.outputFormat
                        sourceRate = actual.intOr(MediaFormat.KEY_SAMPLE_RATE, sourceRate)
                        channels = actual.intOr(MediaFormat.KEY_CHANNEL_COUNT, channels)
                    } else if (outputIndex >= 0) {
                        val eos = (bufferInfo.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM) != 0
                        if (bufferInfo.size > 0 && bufferInfo.presentationTimeUs >= startUs) {
                            val buffer = decoder.getOutputBuffer(outputIndex)
                            if (buffer != null) {
                                buffer.position(bufferInfo.offset)
                                buffer.limit(bufferInfo.offset + bufferInfo.size)
                                writeAsStereo(buffer, channels, sink)
                            }
                        }
                        decoder.releaseOutputBuffer(outputIndex, false)
                        if (eos || bufferInfo.presentationTimeUs > endUs) outputDone = true
                    }
                }
            }
        } catch (t: Throwable) {
            Log.e(TAG, "audio decode failed for $uri", t)
            return -1
        } finally {
            runCatching { decoder?.stop() }
            runCatching { decoder?.release() }
            runCatching { extractor.release() }
        }
        return sourceRate
    }

    /** Downmixes or duplicates whatever the decoder produced into stereo. */
    private fun writeAsStereo(
        buffer: java.nio.ByteBuffer,
        channels: Int,
        sink: OutputStream,
    ) {
        val shorts = ShortArray(buffer.remaining() / 2)
        buffer.asShortBuffer().get(shorts)
        if (channels <= 0) return

        val frames = shorts.size / channels
        val out = ByteArray(frames * BYTES_PER_FRAME)
        var o = 0
        for (frame in 0 until frames) {
            val base = frame * channels
            val left: Int
            val right: Int
            when {
                channels == 1 -> {
                    left = shorts[base].toInt(); right = left
                }
                channels == 2 -> {
                    left = shorts[base].toInt(); right = shorts[base + 1].toInt()
                }
                else -> {
                    // Fold surround down to the front pair.
                    left = shorts[base].toInt()
                    right = shorts[base + 1].toInt()
                }
            }
            out[o++] = (left and 0xFF).toByte()
            out[o++] = ((left shr 8) and 0xFF).toByte()
            out[o++] = (right and 0xFF).toByte()
            out[o++] = ((right shr 8) and 0xFF).toByte()
        }
        sink.write(out)
    }

    /**
     * Pass 2 - linear resample a contiguous stereo file by [ratio] output
     * frames per input frame. Phase runs continuously across the whole file,
     * so there are no seams.
     *
     * Works a block at a time and re-reads one frame at each block boundary,
     * so the interpolation window is always satisfied without per-frame reads
     * or allocations.
     */
    private fun resampleFile(source: File, output: File, ratio: Double): Long {
        if (!source.exists() || source.length() < BYTES_PER_FRAME * 2) return 0L

        if (kotlin.math.abs(ratio - 1.0) < 1e-9) {
            source.copyTo(output, overwrite = true)
            return output.length() / BYTES_PER_FRAME
        }

        val step = 1.0 / ratio
        val blockFrames = 1 shl 14
        val raw = ByteArray(blockFrames * BYTES_PER_FRAME)
        val frames = ShortArray(blockFrames * CHANNELS)
        val outBuffer = ByteArray(1 shl 16)

        var written = 0L
        var position = 0.0
        var blockStart = 0L

        java.io.RandomAccessFile(source, "r").use { raf ->
            BufferedOutputStream(FileOutputStream(output), 1 shl 16).use { sink ->
                var outIndex = 0
                while (true) {
                    raf.seek(blockStart * BYTES_PER_FRAME)
                    val read = readFully(raf, raw)
                    val n = read / BYTES_PER_FRAME
                    if (n < 2) break

                    var i = 0
                    var b = 0
                    while (i < n * CHANNELS) {
                        frames[i] = ((raw[b].toInt() and 0xFF) or (raw[b + 1].toInt() shl 8)).toShort()
                        i++
                        b += 2
                    }

                    // Emit every output frame whose interpolation window lies
                    // inside this block.
                    val lastUsable = blockStart + n - 2
                    while (position <= lastUsable) {
                        val i0 = position.toLong()
                        val frac = (position - i0).toFloat()
                        val local = ((i0 - blockStart) * CHANNELS).toInt()

                        for (channel in 0 until CHANNELS) {
                            val a = frames[local + channel].toFloat()
                            val bb = frames[local + CHANNELS + channel].toFloat()
                            val value = (a + (bb - a) * frac).roundToInt().coerceIn(-32768, 32767)
                            outBuffer[outIndex++] = (value and 0xFF).toByte()
                            outBuffer[outIndex++] = ((value shr 8) and 0xFF).toByte()
                        }
                        written++
                        position += step

                        if (outIndex >= outBuffer.size - BYTES_PER_FRAME) {
                            sink.write(outBuffer, 0, outIndex)
                            outIndex = 0
                        }
                    }

                    if (read < raw.size) break          // reached end of file
                    blockStart += (n - 1).toLong()      // overlap one frame
                }
                if (outIndex > 0) sink.write(outBuffer, 0, outIndex)
            }
        }
        return written
    }

    /** Reads until [into] is full or the file ends. Returns bytes read. */
    private fun readFully(raf: java.io.RandomAccessFile, into: ByteArray): Int {
        var total = 0
        while (total < into.size) {
            val n = raf.read(into, total, into.size - total)
            if (n < 0) break
            total += n
        }
        return total
    }

    private fun MediaFormat.intOr(key: String, fallback: Int): Int =
        if (containsKey(key)) getInteger(key) else fallback
}
