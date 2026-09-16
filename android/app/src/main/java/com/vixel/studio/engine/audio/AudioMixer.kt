package com.vixel.studio.engine.audio

import java.io.BufferedOutputStream
import java.io.File
import java.io.FileOutputStream
import java.io.RandomAccessFile
import kotlin.math.max
import kotlin.math.min

/**
 * One contributor to the mix: a canonical PCM file placed at an offset on the
 * timeline, with its own level and fades.
 */
data class MixSource(
    val pcm: File,
    val startFrame: Long,
    val volume: Float = 1f,
    val fadeInFrames: Long = 0L,
    val fadeOutFrames: Long = 0L,
)

/**
 * Sums PCM sources into a single track.
 *
 * Accumulation is in Int and clamped once at the end, so two loud sources
 * overlapping distort at the ceiling instead of wrapping around — wrapping is
 * what turns a slightly hot mix into a burst of noise.
 */
object AudioMixer {

    private const val BLOCK_FRAMES = 1 shl 13

    fun mix(sources: List<MixSource>, totalFrames: Long, output: File): Boolean {
        if (totalFrames <= 0) return false

        val open = sources.mapNotNull { source ->
            if (!source.pcm.exists() || source.pcm.length() < PcmDecoder.BYTES_PER_FRAME) {
                null
            } else {
                OpenSource(source, RandomAccessFile(source.pcm, "r"))
            }
        }
        if (open.isEmpty()) return false

        val accumulator = IntArray(BLOCK_FRAMES * PcmDecoder.CHANNELS)
        val scratch = ByteArray(BLOCK_FRAMES * PcmDecoder.BYTES_PER_FRAME)
        val out = ByteArray(BLOCK_FRAMES * PcmDecoder.BYTES_PER_FRAME)

        try {
            BufferedOutputStream(FileOutputStream(output), 1 shl 16).use { sink ->
                var blockStart = 0L
                while (blockStart < totalFrames) {
                    val blockLength = min(BLOCK_FRAMES.toLong(), totalFrames - blockStart).toInt()
                    java.util.Arrays.fill(accumulator, 0, blockLength * PcmDecoder.CHANNELS, 0)

                    for (entry in open) {
                        entry.addTo(accumulator, blockStart, blockLength, scratch)
                    }

                    var o = 0
                    for (i in 0 until blockLength * PcmDecoder.CHANNELS) {
                        val value = accumulator[i].coerceIn(-32768, 32767)
                        out[o++] = (value and 0xFF).toByte()
                        out[o++] = ((value shr 8) and 0xFF).toByte()
                    }
                    sink.write(out, 0, o)
                    blockStart += blockLength
                }
            }
        } finally {
            open.forEach { runCatching { it.file.close() } }
        }
        return true
    }

    private class OpenSource(val source: MixSource, val file: RandomAccessFile) {

        private val totalFrames = file.length() / PcmDecoder.BYTES_PER_FRAME

        fun addTo(accumulator: IntArray, blockStart: Long, blockLength: Int, scratch: ByteArray) {
            val sourceStart = max(blockStart, source.startFrame)
            val sourceEnd = min(blockStart + blockLength, source.startFrame + totalFrames)
            if (sourceEnd <= sourceStart) return

            val framesToRead = (sourceEnd - sourceStart).toInt()
            val offsetInSource = sourceStart - source.startFrame
            file.seek(offsetInSource * PcmDecoder.BYTES_PER_FRAME)

            var read = 0
            val wanted = framesToRead * PcmDecoder.BYTES_PER_FRAME
            while (read < wanted) {
                val n = file.read(scratch, read, wanted - read)
                if (n < 0) break
                read += n
            }
            val framesRead = read / PcmDecoder.BYTES_PER_FRAME
            if (framesRead <= 0) return

            val writeOffset = (sourceStart - blockStart).toInt()
            for (frame in 0 until framesRead) {
                val gain = source.volume * envelope(offsetInSource + frame)
                if (gain == 0f) continue
                val base = frame * PcmDecoder.BYTES_PER_FRAME
                val target = (writeOffset + frame) * PcmDecoder.CHANNELS
                for (channel in 0 until PcmDecoder.CHANNELS) {
                    val b = base + channel * 2
                    val sample = ((scratch[b].toInt() and 0xFF) or (scratch[b + 1].toInt() shl 8)).toShort()
                    accumulator[target + channel] += (sample * gain).toInt()
                }
            }
        }

        /** Linear fade in/out within this source's own timeline. */
        private fun envelope(frameInSource: Long): Float {
            var gain = 1f
            if (source.fadeInFrames > 0 && frameInSource < source.fadeInFrames) {
                gain *= frameInSource.toFloat() / source.fadeInFrames
            }
            val fromEnd = totalFrames - frameInSource
            if (source.fadeOutFrames > 0 && fromEnd < source.fadeOutFrames) {
                gain *= (fromEnd.toFloat() / source.fadeOutFrames).coerceAtLeast(0f)
            }
            return gain.coerceIn(0f, 1f)
        }
    }
}
