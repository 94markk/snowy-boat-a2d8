package com.vixel.studio.engine.audio

import java.io.File
import java.io.RandomAccessFile
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sqrt

/** A stretch of speech, in frames of the PCM it was detected in. */
data class Utterance(val startFrame: Long, val endFrame: Long) {
    val lengthFrames: Long get() = max(0L, endFrame - startFrame)
}

/**
 * Splits audio into utterances by energy.
 *
 * The threshold is derived from the recording's own noise floor rather than
 * fixed, so a quiet interview and a loud street scene both segment sensibly.
 * A hangover keeps short pauses inside a sentence from cutting it in half,
 * and a hard cap splits anything that runs long at its quietest point — a
 * caption that sits on screen for fifteen seconds is not a caption.
 */
object VoiceActivity {

    private const val WINDOW_MS = 20

    /** Silence shorter than this stays inside an utterance. */
    private const val HANGOVER_MS = 320

    /** Utterances shorter than this are noise, not speech. */
    private const val MIN_SPEECH_MS = 320

    /** Longest a single caption may run before it is forced apart. */
    const val MAX_SEGMENT_MS = 4_000

    /** How far above the noise floor counts as speech. */
    private const val THRESHOLD_FACTOR = 2.2f

    /** Absolute floor, so pure digital silence never reads as speech. */
    private const val MIN_THRESHOLD = 0.006f

    fun detect(pcm: File, sampleRate: Int): List<Utterance> {
        val energies = windowEnergies(pcm, sampleRate)
        if (energies.isEmpty()) return emptyList()

        val windowFrames = (sampleRate * WINDOW_MS / 1000L).coerceAtLeast(1L)
        val threshold = thresholdFor(energies)

        val hangoverWindows = (HANGOVER_MS / WINDOW_MS)
        val minSpeechWindows = (MIN_SPEECH_MS / WINDOW_MS)
        val maxWindows = (MAX_SEGMENT_MS / WINDOW_MS)

        val out = mutableListOf<Utterance>()
        var start = -1
        var quiet = 0

        for (i in energies.indices) {
            val loud = energies[i] >= threshold
            if (loud) {
                if (start < 0) start = i
                quiet = 0
            } else if (start >= 0) {
                quiet++
                if (quiet >= hangoverWindows) {
                    val end = i - quiet + 1
                    if (end - start >= minSpeechWindows) {
                        out += splitLong(start, end, maxWindows, energies, windowFrames)
                    }
                    start = -1
                    quiet = 0
                }
            }
        }
        if (start >= 0 && energies.size - start >= minSpeechWindows) {
            out += splitLong(start, energies.size, maxWindows, energies, windowFrames)
        }
        return out
    }

    /**
     * Breaks an over-long run at its quietest interior window, repeatedly, so
     * the cut lands in a natural gap rather than mid-word.
     */
    private fun splitLong(
        startWindow: Int,
        endWindow: Int,
        maxWindows: Int,
        energies: FloatArray,
        windowFrames: Long,
    ): List<Utterance> {
        if (endWindow - startWindow <= maxWindows) {
            return listOf(
                Utterance(startWindow * windowFrames, endWindow.toLong() * windowFrames),
            )
        }

        // Look for the quietest window in the middle half of the run; cutting
        // near either edge would just produce a sliver.
        val from = startWindow + (endWindow - startWindow) / 4
        val to = endWindow - (endWindow - startWindow) / 4
        var quietest = from
        for (i in from until to) {
            if (energies[i] < energies[quietest]) quietest = i
        }

        return splitLong(startWindow, quietest, maxWindows, energies, windowFrames) +
            splitLong(quietest, endWindow, maxWindows, energies, windowFrames)
    }

    private fun thresholdFor(energies: FloatArray): Float {
        // The 20th percentile approximates the noise floor without being
        // dragged down by a handful of perfectly silent windows.
        val sorted = energies.sortedArray()
        val floor = sorted[(sorted.size * 0.2f).toInt().coerceIn(0, sorted.lastIndex)]
        return max(MIN_THRESHOLD, floor * THRESHOLD_FACTOR)
    }

    /** RMS per window over a mono 16-bit PCM file. */
    private fun windowEnergies(pcm: File, sampleRate: Int): FloatArray {
        if (!pcm.exists() || pcm.length() < 2) return FloatArray(0)
        val windowFrames = (sampleRate * WINDOW_MS / 1000).coerceAtLeast(1)
        val bytesPerWindow = windowFrames * 2
        val totalWindows = (pcm.length() / bytesPerWindow).toInt()
        if (totalWindows <= 0) return FloatArray(0)

        val out = FloatArray(totalWindows)
        val buffer = ByteArray(bytesPerWindow)

        RandomAccessFile(pcm, "r").use { raf ->
            for (w in 0 until totalWindows) {
                var read = 0
                while (read < buffer.size) {
                    val n = raf.read(buffer, read, buffer.size - read)
                    if (n < 0) break
                    read += n
                }
                if (read < 2) break

                var sum = 0.0
                var i = 0
                while (i + 1 < read) {
                    val sample = ((buffer[i].toInt() and 0xFF) or (buffer[i + 1].toInt() shl 8)).toShort()
                    val v = sample / 32768.0
                    sum += v * v
                    i += 2
                }
                out[w] = sqrt(sum / max(1, read / 2)).toFloat()
            }
        }
        return out
    }

    /** Clamps an utterance to the file so a read cannot run past the end. */
    fun clamp(utterance: Utterance, totalFrames: Long): Utterance = Utterance(
        utterance.startFrame.coerceIn(0L, totalFrames),
        min(utterance.endFrame, totalFrames),
    )
}
