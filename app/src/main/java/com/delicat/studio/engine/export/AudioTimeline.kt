package com.delicat.studio.engine.export

import android.content.Context
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.net.Uri
import java.nio.ByteOrder
import com.delicat.studio.model.MediaKind
import com.delicat.studio.model.Project
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * Builds the timeline's sound and hands it out in chunks.
 *
 * Everything is converted to one rate and channel count on the way in, so the
 * mixing below never has to think about the format a particular phone
 * recorded in. Clips are walked in order and emitted as they are finished,
 * which keeps memory flat: the only thing held back is the tail that overlaps
 * the next clip, and a transition is capped at two seconds.
 *
 * Where two clips overlap they are crossfaded rather than butt-joined. A cut
 * in sound is audible in a way a cut in picture is not — it clicks — so even a
 * transition the user set purely for the look needs its audio ramped.
 */
class AudioTimeline(private val context: Context) {

    /** Timeline sound as interleaved stereo 16-bit at [SAMPLE_RATE]. */
    fun render(project: Project, cancelled: () -> Boolean, sink: (ShortArray, Int) -> Unit) {
        var carry = ShortArray(0)
        var carryFrames = 0

        project.clips.forEachIndexed { index, clip ->
            if (cancelled()) return

            val frames = framesFor(clip.timelineDurationUs)
            val fadeInFrames = framesFor(project.overlapBefore(index))
            val fadeOutFrames = framesFor(project.overlapBefore(index + 1))
            val holdBack = min(fadeOutFrames, frames)

            val emitted = ShortArray((frames - holdBack).coerceAtLeast(0) * CHANNELS)
            val tail = ShortArray(holdBack * CHANNELS)

            val silent = clip.kind != MediaKind.VIDEO || clip.muted || clip.volume <= 0f
            if (!silent) {
                fill(clip, frames) { frame, left, right ->
                    var gain = clip.volume
                    if (fadeInFrames > 0 && frame < fadeInFrames) {
                        gain *= frame.toFloat() / fadeInFrames
                    }
                    if (holdBack > 0 && frame >= frames - holdBack) {
                        gain *= (frames - frame).toFloat() / holdBack
                    }
                    val l = (left * gain).roundToInt()
                    val r = (right * gain).roundToInt()
                    if (frame < frames - holdBack) {
                        emitted[frame * CHANNELS] = clampToShort(l)
                        emitted[frame * CHANNELS + 1] = clampToShort(r)
                    } else {
                        val at = (frame - (frames - holdBack)) * CHANNELS
                        if (at + 1 < tail.size) {
                            tail[at] = clampToShort(l)
                            tail[at + 1] = clampToShort(r)
                        }
                    }
                }
            }

            // The previous clip's held-back tail lands on the front of this
            // one, which is exactly where its own fade-in is ramping up.
            val blend = min(carryFrames, (frames - holdBack).coerceAtLeast(0))
            for (i in 0 until blend * CHANNELS) {
                emitted[i] = clampToShort(emitted[i] + carry[i])
            }
            if (carryFrames > blend) {
                // Only possible if a clip is shorter than the transition into
                // it, which the overlap clamp already prevents; mixed in at
                // the end rather than dropped, so nothing is silently lost.
                for (i in blend * CHANNELS until carryFrames * CHANNELS) {
                    val at = i - blend * CHANNELS
                    if (at < tail.size) tail[at] = clampToShort(tail[at] + carry[i])
                }
            }

            if (emitted.isNotEmpty()) sink(emitted, emitted.size)
            carry = tail
            carryFrames = holdBack
        }

        if (carryFrames > 0) sink(carry, carry.size)
    }

    /** True when any clip contributes sound, so the muxer knows to add a track. */
    fun hasAudio(project: Project): Boolean = project.clips.any { clip ->
        clip.kind == MediaKind.VIDEO && !clip.muted && clip.volume > 0f &&
            runCatching { hasAudioTrack(Uri.parse(clip.uri)) }.getOrDefault(false)
    }

    private fun hasAudioTrack(uri: Uri): Boolean {
        val extractor = MediaExtractor()
        return try {
            extractor.setDataSource(context, uri, null)
            (0 until extractor.trackCount).any { i ->
                extractor.getTrackFormat(i).getString(MediaFormat.KEY_MIME)
                    ?.startsWith("audio/") == true
            }
        } catch (_: Throwable) {
            false
        } finally {
            runCatching { extractor.release() }
        }
    }

    // ---- decoding ---------------------------------------------------------

    private fun interface Frame {
        fun onFrame(index: Int, left: Float, right: Float)
    }

    /**
     * Decodes [clip]'s audio and reports exactly [frames] output frames.
     *
     * Resampling is linear and done in the same pass as the rate and channel
     * conversion. Output frame j reads source position `j * step`, so speed,
     * a source recorded at 48kHz and a mono track all collapse into one walk
     * through the samples rather than three passes over them.
     */
    private fun fill(clip: com.delicat.studio.model.Clip, frames: Int, out: Frame) {
        val uri = Uri.parse(clip.uri)
        val extractor = MediaExtractor()
        var codec: MediaCodec? = null

        try {
            extractor.setDataSource(context, uri, null)
            var track = -1
            var format: MediaFormat? = null
            for (i in 0 until extractor.trackCount) {
                val candidate = extractor.getTrackFormat(i)
                if (candidate.getString(MediaFormat.KEY_MIME)?.startsWith("audio/") == true) {
                    track = i
                    format = candidate
                    break
                }
            }
            if (format == null) return
            extractor.selectTrack(track)
            extractor.seekTo(clip.trimStartUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)

            val sourceRate = format.getInteger(MediaFormat.KEY_SAMPLE_RATE)
            val sourceChannels = format.getInteger(MediaFormat.KEY_CHANNEL_COUNT).coerceAtLeast(1)
            val step = clip.speed.coerceAtLeast(0.01f).toDouble() * sourceRate / SAMPLE_RATE

            codec = MediaCodec.createDecoderByType(
                format.getString(MediaFormat.KEY_MIME) ?: return,
            )
            codec.configure(format, null, null, 0)
            codec.start()

            val info = MediaCodec.BufferInfo()
            var inputDone = false
            var outputDone = false

            // Source frames decoded so far, and where the next output frame
            // wants to read from. Both are counted from the trim point, so
            // trimming costs nothing at decode time.
            var decodedFrames = 0L
            var outIndex = 0
            var carryLeft = 0f
            var carryRight = 0f
            var started = false

            while (!outputDone && outIndex < frames) {
                if (!inputDone) {
                    val index = codec.dequeueInputBuffer(TIMEOUT_US)
                    if (index >= 0) {
                        val buffer = codec.getInputBuffer(index)
                        val size = if (buffer == null) -1 else extractor.readSampleData(buffer, 0)
                        if (size < 0) {
                            codec.queueInputBuffer(
                                index, 0, 0, 0L, MediaCodec.BUFFER_FLAG_END_OF_STREAM,
                            )
                            inputDone = true
                        } else {
                            codec.queueInputBuffer(index, 0, size, extractor.sampleTime, 0)
                            extractor.advance()
                        }
                    }
                }

                val index = codec.dequeueOutputBuffer(info, TIMEOUT_US)
                if (index == MediaCodec.INFO_TRY_AGAIN_LATER) continue
                if (index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED || index < 0) continue

                if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) outputDone = true

                if (info.size > 0) {
                    val buffer = codec.getOutputBuffer(index)
                    if (buffer != null) {
                        buffer.position(info.offset)
                        buffer.limit(info.offset + info.size)
                        // Raw PCM from a decoder is in the device's own byte
                        // order. A ByteBuffer defaults to big-endian, so
                        // reading shorts without saying otherwise swaps every
                        // pair of bytes and turns the audio into noise.
                        val shorts = buffer.order(ByteOrder.nativeOrder()).asShortBuffer()
                        val available = shorts.remaining() / sourceChannels

                        // A seek lands on the sync sample before the trim, so
                        // the first chunks can predate it and are dropped.
                        val chunkStartUs = info.presentationTimeUs
                        if (!started && chunkStartUs + CHUNK_SLACK_US < clip.trimStartUs) {
                            codec.releaseOutputBuffer(index, false)
                            continue
                        }
                        if (!started) {
                            started = true
                            decodedFrames = ((chunkStartUs - clip.trimStartUs) * sourceRate / 1_000_000L)
                                .coerceAtLeast(0L)
                        }

                        for (i in 0 until available) {
                            val position = decodedFrames + i
                            var wanted = outIndex * step
                            while (wanted <= position.toDouble() && outIndex < frames) {
                                val at = i * sourceChannels
                                val left = shorts.get(at) / 32768f
                                val right = if (sourceChannels > 1) {
                                    shorts.get(at + 1) / 32768f
                                } else {
                                    left
                                }
                                val blend = (wanted - (position - 1).toDouble())
                                    .coerceIn(0.0, 1.0).toFloat()
                                out.onFrame(
                                    outIndex,
                                    carryLeft + (left - carryLeft) * blend,
                                    carryRight + (right - carryRight) * blend,
                                )
                                outIndex++
                                wanted = outIndex * step
                            }
                            val at = i * sourceChannels
                            carryLeft = shorts.get(at) / 32768f
                            carryRight = if (sourceChannels > 1) shorts.get(at + 1) / 32768f else carryLeft
                        }
                        decodedFrames += available
                    }
                }
                codec.releaseOutputBuffer(index, false)
            }
        } catch (_: Throwable) {
            // A clip whose audio will not decode exports silently rather than
            // failing the whole render.
        } finally {
            runCatching { codec?.stop() }
            runCatching { codec?.release() }
            runCatching { extractor.release() }
        }
    }

    private fun framesFor(durationUs: Long): Int =
        max(0L, durationUs * SAMPLE_RATE / 1_000_000L).toInt()

    private fun clampToShort(value: Int): Short =
        value.coerceIn(Short.MIN_VALUE.toInt(), Short.MAX_VALUE.toInt()).toShort()

    companion object {
        const val SAMPLE_RATE = 44_100
        const val CHANNELS = 2
        private const val TIMEOUT_US = 10_000L

        /** A decoded chunk may start just before the trim; this tolerates that. */
        private const val CHUNK_SLACK_US = 40_000L
    }
}
