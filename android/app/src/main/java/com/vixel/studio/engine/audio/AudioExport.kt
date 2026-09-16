package com.vixel.studio.engine.audio

import android.content.ContentValues
import android.content.Context
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMuxer
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import android.util.Log
import java.io.BufferedOutputStream
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.io.RandomAccessFile
import java.nio.ByteBuffer
import java.nio.ByteOrder

private const val TAG = "VixelAudioExport"

enum class AudioFormat(val label: String, val extension: String, val mimeType: String) {
    M4A("M4A (fast, no re-encode)", "m4a", "audio/mp4"),
    WAV("WAV (uncompressed)", "wav", "audio/wav"),
}

/** Pulls the audio out of a video, or re-encodes any source to WAV. */
object AudioExport {

    const val ALBUM = "Vixel Studio"

    fun outputDir(context: Context): File =
        File(context.getExternalFilesDir(null) ?: context.filesDir, "audio").apply { mkdirs() }

    fun newFile(context: Context, format: AudioFormat): File =
        File(outputDir(context), "vixel_${System.currentTimeMillis()}.${format.extension}")

    /**
     * Copies the compressed audio track straight into an MP4 container.
     *
     * Nothing is decoded or re-encoded, so this is near-instant and bit-exact.
     * It only works when the source track is already MP4-muxable (AAC), which
     * covers essentially every phone-shot video.
     */
    fun extractToM4a(context: Context, uri: Uri, output: File): Boolean {
        val extractor = MediaExtractor()
        var muxer: MediaMuxer? = null
        var started = false

        try {
            extractor.setDataSource(context, uri, null)
            var trackIndex = -1
            var format: MediaFormat? = null
            for (i in 0 until extractor.trackCount) {
                val f = extractor.getTrackFormat(i)
                if (f.getString(MediaFormat.KEY_MIME).orEmpty().startsWith("audio/")) {
                    trackIndex = i
                    format = f
                    break
                }
            }
            if (trackIndex < 0 || format == null) {
                Log.w(TAG, "no audio track in $uri")
                return false
            }

            extractor.selectTrack(trackIndex)
            muxer = MediaMuxer(output.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
            val outTrack = muxer.addTrack(format)
            muxer.start()
            started = true

            val maxSize = if (format.containsKey(MediaFormat.KEY_MAX_INPUT_SIZE)) {
                format.getInteger(MediaFormat.KEY_MAX_INPUT_SIZE)
            } else {
                1 shl 18
            }
            val buffer = ByteBuffer.allocate(maxSize.coerceAtLeast(1 shl 16))
            val info = MediaCodec.BufferInfo()

            while (true) {
                buffer.clear()
                val size = extractor.readSampleData(buffer, 0)
                if (size < 0) break
                info.offset = 0
                info.size = size
                info.presentationTimeUs = extractor.sampleTime
                info.flags = if (extractor.sampleFlags and MediaExtractor.SAMPLE_FLAG_SYNC != 0) {
                    MediaCodec.BUFFER_FLAG_KEY_FRAME
                } else {
                    0
                }
                muxer.writeSampleData(outTrack, buffer, info)
                extractor.advance()
            }
            return true
        } catch (t: Throwable) {
            Log.e(TAG, "m4a extraction failed", t)
            runCatching { output.delete() }
            return false
        } finally {
            if (started) runCatching { muxer?.stop() }
            runCatching { muxer?.release() }
            runCatching { extractor.release() }
        }
    }

    /**
     * Decodes any audio source to a 44.1 kHz stereo 16-bit WAV.
     *
     * Unlike [extractToM4a] this always works, including for containers whose
     * codec cannot be re-muxed into MP4.
     */
    fun exportWav(
        context: Context,
        uri: Uri,
        output: File,
        startUs: Long = 0L,
        endUs: Long = Long.MAX_VALUE,
    ): Boolean {
        val pcm = File(output.parentFile, "${output.name}.pcm")
        try {
            val frames = PcmDecoder.decode(context, uri, startUs, endUs, pcm)
            if (frames <= 0) return false
            writeWav(pcm, output)
            return true
        } catch (t: Throwable) {
            Log.e(TAG, "wav export failed", t)
            runCatching { output.delete() }
            return false
        } finally {
            pcm.delete()
        }
    }

    /** Wraps raw PCM in a canonical 44-byte RIFF/WAVE header. */
    fun writeWav(pcm: File, output: File) {
        val dataSize = pcm.length().toInt()
        val byteRate = PcmDecoder.SAMPLE_RATE * PcmDecoder.BYTES_PER_FRAME

        BufferedOutputStream(FileOutputStream(output), 1 shl 16).use { sink ->
            val header = ByteBuffer.allocate(44).order(ByteOrder.LITTLE_ENDIAN)
            header.put("RIFF".toByteArray(Charsets.US_ASCII))
            header.putInt(36 + dataSize)
            header.put("WAVE".toByteArray(Charsets.US_ASCII))
            header.put("fmt ".toByteArray(Charsets.US_ASCII))
            header.putInt(16)                                   // PCM chunk size
            header.putShort(1)                                  // format: PCM
            header.putShort(PcmDecoder.CHANNELS.toShort())
            header.putInt(PcmDecoder.SAMPLE_RATE)
            header.putInt(byteRate)
            header.putShort(PcmDecoder.BYTES_PER_FRAME.toShort())
            header.putShort((PcmDecoder.BYTES_PER_SAMPLE * 8).toShort())
            header.put("data".toByteArray(Charsets.US_ASCII))
            header.putInt(dataSize)
            sink.write(header.array())

            RandomAccessFile(pcm, "r").use { input ->
                val chunk = ByteArray(1 shl 16)
                while (true) {
                    val read = input.read(chunk)
                    if (read <= 0) break
                    sink.write(chunk, 0, read)
                }
            }
        }
    }

    /** Publishes a finished audio file into the shared Music collection. */
    fun publishToGallery(
        context: Context,
        file: File,
        format: AudioFormat,
        displayName: String = file.name,
    ): Uri {
        val values = ContentValues().apply {
            put(MediaStore.MediaColumns.DISPLAY_NAME, displayName)
            put(MediaStore.MediaColumns.MIME_TYPE, format.mimeType)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                put(MediaStore.MediaColumns.RELATIVE_PATH, "${Environment.DIRECTORY_MUSIC}/$ALBUM")
                put(MediaStore.MediaColumns.IS_PENDING, 1)
            }
        }

        val resolver = context.contentResolver
        val collection = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            MediaStore.Audio.Media.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY)
        } else {
            MediaStore.Audio.Media.EXTERNAL_CONTENT_URI
        }

        val uri = resolver.insert(collection, values)
            ?: throw IOException("MediaStore rejected the insert")

        try {
            resolver.openOutputStream(uri)?.use { out ->
                file.inputStream().use { input -> input.copyTo(out) }
            } ?: throw IOException("Could not open an output stream")
        } catch (t: Throwable) {
            resolver.delete(uri, null, null)
            throw t
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            values.clear()
            values.put(MediaStore.MediaColumns.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
        }
        return uri
    }
}
