package com.vixel.studio.engine.audio

import android.content.Context
import android.net.Uri
import android.util.Log
import com.vixel.studio.core.model.MediaKind
import com.vixel.studio.core.model.Project
import java.io.BufferedOutputStream
import java.io.File
import java.io.FileOutputStream
import java.io.RandomAccessFile
import kotlin.math.roundToInt
import kotlin.math.roundToLong

private const val TAG = "VixelCaptions"

/** One caption, timed against the project timeline. */
data class CaptionCue(val startUs: Long, val endUs: Long, val text: String)

sealed interface CaptionResult {
    data class Success(val cues: List<CaptionCue>) : CaptionResult
    data class Unsupported(val reason: String) : CaptionResult
    data class Failure(val message: String) : CaptionResult
}

/**
 * Turns the spoken audio of a project into timed captions, entirely on device.
 *
 * Each clip is handled separately so trims and speed changes map correctly:
 * recognition runs over the clip's own audio, and the resulting times are
 * pushed back through the clip's speed onto the timeline.
 */
object CaptionGenerator {

    /** Captions shorter than this are almost always a misfire. */
    private const val MIN_CUE_US = 400_000L

    suspend fun generate(
        context: Context,
        project: Project,
        onProgress: (Float) -> Unit = {},
    ): CaptionResult {
        if (!SpeechTranscriber.isSupported) {
            return CaptionResult.Unsupported(
                "Auto captions need Android 12 or newer, which is when the system " +
                    "recogniser gained the ability to read audio from a file.",
            )
        }
        if (!SpeechTranscriber.isAvailable(context)) {
            return CaptionResult.Unsupported(
                "No on-device speech model is installed. Add a language in " +
                    "Settings > System > Languages, then try again.",
            )
        }

        val clips = project.clips.filter { it.kind == MediaKind.VIDEO && !it.muted }
        if (clips.isEmpty()) return CaptionResult.Failure("No clip on the timeline has audio")

        val workDir = File(context.cacheDir, "captions-${System.currentTimeMillis()}")
        workDir.mkdirs()
        val cues = mutableListOf<CaptionCue>()

        try {
            clips.forEachIndexed { order, clip ->
                val index = project.clips.indexOfFirst { it.id == clip.id }
                if (index < 0) return@forEachIndexed

                val raw = File(workDir, "clip_$order.pcm")
                val frames = PcmDecoder.decode(
                    context = context,
                    uri = Uri.parse(clip.uri),
                    startUs = clip.trimStartUs,
                    endUs = clip.trimEndUs,
                    output = raw,
                )
                if (frames <= 0) {
                    raw.delete()
                    return@forEachIndexed
                }

                val mono = File(workDir, "clip_${order}_16k.pcm")
                val monoFrames = toMono16k(raw, mono)
                raw.delete()
                if (monoFrames <= 0) {
                    mono.delete()
                    return@forEachIndexed
                }

                val utterances = VoiceActivity.detect(mono, SpeechTranscriber.SAMPLE_RATE)
                val clipStartUs = project.startOf(index)
                val speed = clip.speed.coerceAtLeast(0.01f)

                utterances.forEachIndexed { u, rawUtterance ->
                    val utterance = VoiceActivity.clamp(rawUtterance, monoFrames)
                    if (utterance.lengthFrames <= 0) return@forEachIndexed

                    val text = SpeechTranscriber.transcribe(context, mono, utterance)
                    if (!text.isNullOrBlank()) {
                        // Utterance time is in clip-local audio; divide by speed
                        // to land on the timeline.
                        val localStart = framesToUs(utterance.startFrame)
                        val localEnd = framesToUs(utterance.endFrame)
                        val startUs = clipStartUs + (localStart / speed).roundToLong()
                        val endUs = clipStartUs + (localEnd / speed).roundToLong()
                        if (endUs - startUs >= MIN_CUE_US) {
                            cues += CaptionCue(startUs, endUs, text)
                        }
                    }

                    val done = (order + (u + 1).toFloat() / utterances.size) / clips.size
                    onProgress(done.coerceIn(0f, 1f))
                }

                mono.delete()
            }
        } catch (t: Throwable) {
            Log.e(TAG, "caption generation failed", t)
            return CaptionResult.Failure(t.message ?: "Could not generate captions")
        } finally {
            runCatching { workDir.deleteRecursively() }
        }

        onProgress(1f)
        return if (cues.isEmpty()) {
            CaptionResult.Failure("No speech was recognised in this project")
        } else {
            CaptionResult.Success(cues.sortedBy { it.startUs })
        }
    }

    private fun framesToUs(frames: Long): Long =
        frames * 1_000_000L / SpeechTranscriber.SAMPLE_RATE

    /**
     * Downmixes and resamples canonical 44.1 kHz stereo PCM to the 16 kHz mono
     * the recogniser expects.
     *
     * @return frames written.
     */
    fun toMono16k(source: File, output: File): Long {
        if (!source.exists() || source.length() < PcmDecoder.BYTES_PER_FRAME) return 0L

        val ratio = SpeechTranscriber.SAMPLE_RATE.toDouble() / PcmDecoder.SAMPLE_RATE
        val step = 1.0 / ratio
        val inputFrames = source.length() / PcmDecoder.BYTES_PER_FRAME

        val blockFrames = 1 shl 13
        val raw = ByteArray(blockFrames * PcmDecoder.BYTES_PER_FRAME)
        val out = ByteArray(1 shl 16)
        var written = 0L
        var position = 0.0
        var blockStart = 0L
        var outIndex = 0

        RandomAccessFile(source, "r").use { raf ->
            BufferedOutputStream(FileOutputStream(output), 1 shl 16).use { sink ->
                while (blockStart < inputFrames) {
                    raf.seek(blockStart * PcmDecoder.BYTES_PER_FRAME)
                    var read = 0
                    while (read < raw.size) {
                        val n = raf.read(raw, read, raw.size - read)
                        if (n < 0) break
                        read += n
                    }
                    val n = read / PcmDecoder.BYTES_PER_FRAME
                    if (n < 2) break

                    val lastUsable = blockStart + n - 2
                    while (position <= lastUsable) {
                        val i0 = position.toLong()
                        val frac = (position - i0).toFloat()
                        val local = ((i0 - blockStart) * PcmDecoder.BYTES_PER_FRAME).toInt()

                        val a = monoAt(raw, local)
                        val b = monoAt(raw, local + PcmDecoder.BYTES_PER_FRAME)
                        val value = (a + (b - a) * frac).roundToInt().coerceIn(-32768, 32767)

                        out[outIndex++] = (value and 0xFF).toByte()
                        out[outIndex++] = ((value shr 8) and 0xFF).toByte()
                        written++
                        position += step

                        if (outIndex >= out.size - 2) {
                            sink.write(out, 0, outIndex)
                            outIndex = 0
                        }
                    }

                    if (read < raw.size) break
                    blockStart += (n - 1).toLong()
                }
                if (outIndex > 0) sink.write(out, 0, outIndex)
            }
        }
        return written
    }

    /** Averages a stereo frame down to one channel. */
    private fun monoAt(buffer: ByteArray, offset: Int): Float {
        if (offset + 3 >= buffer.size) return 0f
        val left = ((buffer[offset].toInt() and 0xFF) or (buffer[offset + 1].toInt() shl 8)).toShort()
        val right = ((buffer[offset + 2].toInt() and 0xFF) or (buffer[offset + 3].toInt() shl 8)).toShort()
        return (left + right) / 2f
    }
}
